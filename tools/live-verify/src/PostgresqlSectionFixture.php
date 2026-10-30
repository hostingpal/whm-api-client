<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Bounded, single-use lifecycle for 14 reviewed PostgreSQL operations through both account-bound routes.
 *
 * Successful API creation confirms ownership even if payload validation fails. Failed or uncertain
 * creation never adopts a newly appearing resource. An unsuccessful rename suspends ownership of
 * both identities, retaining them for manual review instead of guessing. After a successful rename,
 * only the new identity is deleted: an unexpectedly remaining old identity is retained and fails cleanup.
 * Cleanup checks original-response identities and never retries. Uncertain ownership remains unresolved
 * after absence observations because the remote mutation may still complete; further lifecycles stop.
 * All six preflight reads finish before mutations; failed preflight evidence never claims cleanup.
 * Empty inventories bound account-wide privilege synchronization to run-owned resources.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type Route from PostgresqlSectionRecipe
 * @phpstan-type Observation array{
 *     phase: string, expectation: 'passed'|'failed'|'not_checked',
 *     operation: string, variant: string, route: string, method: string|null, server_version: string|null,
 *     observed_at: string, revision: string, source_sha256: string, map_sha256: string, generator_tree: string,
 *     overlay_sha256: string, effective_contract_sha256: string, http_status: int|null,
 *     api_outcome: string, payload_validation: string, probe_outcome: string, failure_kind: string|null,
 *     response_paths: array<string, list<string>>, payload_paths: array<string, list<string>>,
 *     discrepancies: list<array{path: string, kind: string}>
 * }
 * @phpstan-type ResourceState 'not_started'|'owned'|'renamed'|'final_named'|'cleaned'|'uncertain'
 * @phpstan-type Resources array{database: ResourceState, user: ResourceState}
 * @phpstan-type Report array{
 *     recipe: string, status: 'passed'|'failed', probes: list<Observation>,
 *     resources: array<Route, Resources>, failures: list<string>,
 *     limitations: list<string>,
 *     assessment: array{status: 'passed'|'failed', findings: list<string>}
 * }
 */
final class PostgresqlSectionFixture
{
    private readonly PostgresqlSectionRecipe $recipe;

    private readonly PostgresqlSectionGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    /** @var array<'whm'|Route, Client> */
    private readonly array $clients;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<Route, Resources> */
    private array $resources = [
        'direct-uapi' => ['database' => 'not_started', 'user' => 'not_started'],
        'bridged-uapi' => ['database' => 'not_started', 'user' => 'not_started'],
    ];

    /** @var list<string> */
    private array $failures = [];

    private bool $used = false;

    /**
     * Bind only the fixed npr account on the fixed test server; construction sends no requests.
     *
     * @param array{whm: string, cpanel: string} $tokens
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] array $tokens,
        #[SensitiveParameter] string $suffix,
        private readonly array $context,
    ) {
        $this->recipe = new PostgresqlSectionRecipe($suffix, 'Ws!9' . bin2hex(random_bytes(20)));
        $this->guard = new PostgresqlSectionGuard($http, $tokens, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $factory = new Psr17Factory();
        $transport = new HttpTransport($this->capture, $factory, $factory);
        $direct = new Client([
            'host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
            'username' => 'npr', 'token' => $tokens['cpanel'],
        ], $transport);
        $bridge = new Client([
            'host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $tokens['whm'],
        ], $transport);
        $this->clients = ['whm' => $bridge, 'direct-uapi' => $direct, 'bridged-uapi' => $bridge];
        $this->runner = new EvidenceRunner($this->guard, $context);
    }

    /**
     * Capture each planned original response once and return sanitized evidence even after failure.
     *
     * Account designation and both opt-ins are required before the version request. A usable version
     * is required before mutations. The caller must obtain context from RepositoryContext::load()
     * before construction; this constructor also supports independently authored offline doubles.
     * No raw response, resource identity, password, hostname, SQL dump or upstream message is returned.
     *
     * @return Report
     */
    public function run(): array
    {
        if (
            $this->used || getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'npr' || getenv('WHM_API_LIVE_DOMAIN') !== 'npr.rs'
        ) {
            $this->failures[] = 'configuration_failure';

            return $this->report();
        }
        $this->used = true;
        try {
            $this->require('version', 'whm');
        } catch (Throwable) {
            $this->failures[] = 'version_failure';

            return $this->report();
        }
        if (!$this->preflight()) {
            $this->failures[] = 'preflight_failure';

            return $this->report();
        }
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            try {
                $this->lifecycle($route);
            } catch (Throwable) {
                $this->failures[] = $route . ':lifecycle_failure';
            } finally {
                $this->cleanup($route);
            }
            if (in_array('uncertain', $this->resources[$route], true)) {
                $this->failures[] = 'unresolved_mutation';
                break;
            }
        }

        return $this->report();
    }

    /** Establish both routes and empty account inventories without stopping on the first unavailable read. */
    private function preflight(): bool
    {
        $passed = true;
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach (
                [
                    'initial_databases' => 'database', 'initial_users' => 'user', 'get_restrictions' => null,
                ] as $phase => $kind
            ) {
                try {
                    if ($kind !== null) {
                        $this->expect($this->inventory($phase, $route, $kind) === []);
                    } else {
                        $restrictions = $this->require($phase, $route);
                        $this->expect(is_array($restrictions));
                        if (is_array($restrictions)) {
                            $names = $this->recipe->names($route);
                            foreach (
                                [
                                    'max_database_name_length' => 'database', 'max_username_length' => 'user',
                                ] as $limit => $key
                            ) {
                                if (isset($restrictions[$limit])) {
                                    $this->expect(is_int($restrictions[$limit])
                                        && $restrictions[$limit] >= strlen($names[$key]));
                                }
                            }
                            $prefix = $restrictions['prefix'] ?? '';
                            $this->expect($prefix === '' || $prefix === 'npr_');
                        }
                    }
                    $passed = $passed && $this->last()['discrepancies'] === [];
                } catch (Throwable) {
                    $passed = false;
                }
            }
        }

        return $passed;
    }

    /** @param Route $route */
    private function lifecycle(string $route): void
    {
        $names = $this->recipe->names($route);
        // Recheck after the other route's cleanup; uncertain leftovers never enter account-wide updates.
        $this->expect($this->inventory('ready_databases', $route, 'database') === []);
        $this->expect($this->inventory('ready_users', $route, 'user') === []);
        foreach (['database', 'user'] as $kind) {
            $this->resourceState($route, $kind, 'uncertain');
            $this->invoke('create_' . $kind, $route);
            if ($this->last()['api_outcome'] === 'success') {
                $this->resourceState($route, $kind, 'owned');
            }
            $this->successful();
        }
        $this->require('grant', $route);
        $this->expect(self::linked($this->require('linked_databases', $route), $names['database'], [$names['user']]));
        $this->expect($this->inventory('linked_users', $route, 'user') === [$names['user']]);
        $this->rename('rename_database', $route, 'database', 'renamed');
        $this->expect($this->inventory('renamed_databases', $route, 'database') === [$names['renamed_database']]);
        $this->rename('rename_user', $route, 'user', 'renamed');
        $this->expect($this->inventory('renamed_users', $route, 'user') === [$names['renamed_user']]);
        $this->require('set_password', $route);
        $this->resourceState($route, 'user', 'uncertain');
        $this->invoke('rename_user_no_password', $route);
        $renamed = $this->last();
        if ($renamed['api_outcome'] === 'success') {
            $this->resourceState($route, 'user', 'final_named');
            // A confirmed rename must be followed by password restoration even if its payload mismatched.
            $this->require('set_final_password', $route);
        }
        if ($renamed['probe_outcome'] !== 'passed') {
            throw new RuntimeException('Section rename observation failed.');
        }
        $this->expect($this->inventory('final_named_users', $route, 'user') === [$names['final_user']]);
        $this->expect($this->inventory('bounded_databases', $route, 'database') === [$names['renamed_database']]);
        $this->expect($this->inventory('bounded_users', $route, 'user') === [$names['final_user']]);
        $this->require('update_privileges', $route);
        $this->require('revoke', $route);
        $this->expect(self::linked($this->require('revoked_databases', $route), $names['renamed_database'], []));
        $this->expect($this->inventory('revoked_users', $route, 'user') === [$names['final_user']]);
    }

    /**
     * Suspend ownership before dispatch; only a confirmed API outcome transfers it to the destination.
     *
     * @param Route $route
     * @param 'database'|'user' $kind
     * @param 'renamed'|'final_named' $state
     */
    private function rename(string $phase, string $route, string $kind, string $state): void
    {
        $this->resourceState($route, $kind, 'uncertain');
        $this->invoke($phase, $route);
        if ($this->last()['api_outcome'] === 'success') {
            $this->resourceState($route, $kind, $state);
        }
        $this->successful();
    }

    /** @param Route $route */
    private function cleanup(string $route): void
    {
        if ($this->resources[$route] === ['database' => 'not_started', 'user' => 'not_started']) {
            return;
        }
        foreach (['user', 'database'] as $kind) {
            $state = $this->resources[$route][$kind];
            if (!in_array($state, ['owned', 'renamed', 'final_named'], true)) {
                continue;
            }
            $phase = match (true) {
                $state === 'owned' => 'cleanup_original_' . $kind,
                $kind === 'user' && $state === 'renamed' => 'cleanup_renamed_user',
                default => 'cleanup_' . $kind,
            };
            try {
                $this->require($phase, $route);
            } catch (Throwable) {
                $this->failures[] = $route . ':cleanup_request_failure';
            }
        }
        $names = $this->recipe->names($route);
        foreach (['database' => 'databases', 'user' => 'users'] as $kind => $plural) {
            try {
                $identities = $this->inventory('final_' . $plural, $route, $kind);
                if ($this->resources[$route][$kind] === 'not_started') {
                    continue;
                }
                $candidates = [$names[$kind], $names['renamed_' . $kind]];
                if ($kind === 'user') {
                    $candidates[] = $names['final_user'];
                }
                $this->expect(array_intersect($candidates, $identities) === []);
                if ($this->resources[$route][$kind] === 'uncertain') {
                    // An in-flight create or rename can materialize after this inventory response.
                    $this->failures[] = $route . ':cleanup_unconfirmed';
                    continue;
                }
                $this->resourceState($route, $kind, 'cleaned');
            } catch (Throwable) {
                if ($this->resources[$route][$kind] !== 'not_started') {
                    $this->resourceState($route, $kind, 'uncertain');
                }
                $this->failures[] = $route . ':cleanup_unconfirmed';
            }
        }
    }

    /**
     * Retain raw capture outcomes; semantic expectations have a separate field and cannot rewrite API failures.
     *
     * @param 'whm'|Route $route
     */
    private function invoke(string $phase, string $route): mixed
    {
        $step = $this->recipe->step($phase, $route);
        $probe = $step['probe'];
        $result = null;
        $wrapped = new Probe(
            $probe->operation,
            $probe->route,
            $probe->spec,
            $probe->uri,
            static function (#[SensitiveParameter] Client $client) use ($probe, &$result): mixed {
                return $result = ($probe->invoke)($client);
            },
        );
        $this->guard->authorize($phase, $route);
        try {
            $record = $this->runner->capture($wrapped, $this->capture, $this->clients[$route]);
        } finally {
            $this->guard->clearPending();
        }
        $this->records[] = [
            ...$record, 'phase' => $phase,
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked',
        ];

        return $result;
    }

    /** @param 'whm'|Route $route */
    private function require(string $phase, string $route): mixed
    {
        $result = $this->invoke($phase, $route);
        $this->successful();

        return $result;
    }

    private function successful(): void
    {
        if ($this->last()['probe_outcome'] !== 'passed') {
            throw new RuntimeException('Section observation failed.');
        }
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $index = array_key_last($this->records);
            if ($index !== null) {
                $this->records[$index]['expectation'] = 'failed';
            }
            throw new RuntimeException('Section expectation failed.');
        }
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('Section observation unavailable.');
        }

        return $this->records[$index];
    }

    /**
     * Validated list results must still contain complete, nonempty, unique identities to prove present absence.
     *
     * @param Route $route
     * @param 'database'|'user' $kind
     * @return list<string>
     */
    private function inventory(string $phase, string $route, string $kind): array
    {
        $rows = $this->require($phase, $route);
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->expect(false);
            throw new RuntimeException('Section inventory unavailable.');
        }
        $identities = [];
        foreach ($rows as $row) {
            $identity = $kind === 'user' ? $row : (is_array($row) ? ($row['database'] ?? null) : null);
            if (!is_string($identity) || $identity === '' || in_array($identity, $identities, true)) {
                $this->expect(false);
                throw new RuntimeException('Section inventory unavailable.');
            }
            $identities[] = $identity;
        }

        return $identities;
    }

    /**
     * Require the owned database as the sole row, with the exact expected assignment list.
     *
     * @param list<string> $users
     */
    private static function linked(
        #[SensitiveParameter] mixed $rows,
        #[SensitiveParameter] string $database,
        #[SensitiveParameter] array $users,
    ): bool {
        return is_array($rows) && array_is_list($rows) && count($rows) === 1
            && is_array($rows[0]) && ($rows[0]['database'] ?? null) === $database
            && ($rows[0]['users'] ?? null) === $users;
    }

    /** @param Route $route
     * @param 'database'|'user' $kind
     * @param ResourceState $state
     */
    private function resourceState(string $route, string $kind, string $state): void
    {
        $current = $this->resources[$route];
        if ($kind === 'database') {
            $current['database'] = $state;
        } else {
            $current['user'] = $state;
        }
        $this->resources[$route] = $current;
    }

    /** @return Report */
    private function report(): array
    {
        $finished = PostgresqlSectionEvidence::finish($this->records, $this->context);
        $cleaned = true;
        foreach ($this->resources as $resources) {
            $cleaned = $cleaned && $resources['database'] === 'cleaned' && $resources['user'] === 'cleaned';
        }

        return [
            'recipe' => 'postgresql-npr-complete-section-v1',
            'status' => $this->failures === [] && $cleaned && $finished['assessment']['status'] === 'passed'
                ? 'passed' : 'failed',
            ...$finished, 'resources' => $this->resources, 'failures' => array_values(array_unique($this->failures)),
            'limitations' => [
                'privilege_readback_does_not_establish_sql_enforcement',
                'password_result_does_not_establish_sql_authentication',
                'update_privileges_is_account_wide_and_requires_only_owned_resources',
                'update_privileges_does_not_establish_new_table_grant_synchronization',
                'unconfirmed_resources_require_manual_review_and_are_not_adopted',
                'empty_preflight_lists_do_not_observe_item_fields',
                'preflight_failure_is_failed_evidence_not_successful_skip',
            ],
        ];
    }
}
