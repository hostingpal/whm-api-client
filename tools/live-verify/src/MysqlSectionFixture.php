<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Bounded, single-use lifecycle for 21 reviewed MySQL operations through both account-bound routes.
 *
 * Successful API creation confirms ownership even if payload validation fails. Failed or uncertain
 * creation never adopts a newly appearing resource. An unsuccessful rename suspends ownership of
 * both identities, retaining them for manual review instead of guessing. After a successful rename,
 * only the new identity is deleted: an unexpectedly remaining old identity is retained and fails cleanup.
 * Independent cleanup runs after each route failure, never retries, and checks original-response inventory identities.
 * Password result failures must be absent or exactly []; no failure entry is accepted as success.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type Route from MysqlSectionRecipe
 * @phpstan-type Observation array{
 *     phase: string, expectation: 'passed'|'failed'|'not_checked',
 *     operation: string, variant: string, route: string, method: string|null, server_version: string|null,
 *     observed_at: string, revision: string, source_sha256: string, map_sha256: string, generator_tree: string,
 *     overlay_sha256: string, effective_contract_sha256: string, http_status: int|null,
 *     api_outcome: string, payload_validation: string, probe_outcome: string, failure_kind: string|null,
 *     response_paths: array<string, list<string>>, payload_paths: array<string, list<string>>,
 *     discrepancies: list<array{path: string, kind: string}>
 * }
 * @phpstan-type ResourceState 'not_started'|'owned'|'renamed'|'cleaned'|'uncertain'
 * @phpstan-type Resources array{database: ResourceState, user: ResourceState}
 * @phpstan-type Report array{
 *     recipe: string, status: 'passed'|'failed', probes: list<Observation>,
 *     resources: array<Route, Resources>, failures: list<string>,
 *     unobserved: array<string, string>, limitations: list<string>,
 *     assessment: array{status: 'passed'|'failed', findings: list<string>}
 * }
 */
final class MysqlSectionFixture
{
    private readonly MysqlSectionRecipe $recipe;

    private readonly MysqlSectionGuard $guard;

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
        $this->recipe = new MysqlSectionRecipe($suffix, 'Ws!9' . bin2hex(random_bytes(20)));
        $this->guard = new MysqlSectionGuard($http, $tokens, $this->recipe);
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
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'npr'
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
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            try {
                $this->lifecycle($route);
            } catch (Throwable) {
                $this->failures[] = $route . ':lifecycle_failure';
            } finally {
                $this->cleanup($route);
            }
        }

        return $this->report();
    }

    /** @param Route $route */
    private function lifecycle(string $route): void
    {
        $names = $this->recipe->names($route);
        $databases = $this->inventory('initial_databases', $route, 'database');
        $users = $this->inventory('initial_users', $route, 'user');
        $this->expect(
            !in_array($names['database'], $databases, true) && !in_array($names['renamed_database'], $databases, true)
                && !in_array($names['user'], $users, true) && !in_array($names['renamed_user'], $users, true),
        );
        foreach (['get_host_notes', 'get_restrictions', 'get_server_information', 'locate_server'] as $phase) {
            $this->require($phase, $route);
        }
        foreach (['database', 'user'] as $kind) {
            $this->resourceState($route, $kind, 'uncertain');
            $this->invoke('create_' . $kind, $route);
            if ($this->last()['api_outcome'] === 'success') {
                $this->resourceState($route, $kind, 'owned');
            }
            $this->successful();
        }
        $this->require('assign_select', $route);
        $this->expect($this->require('read_select', $route) === ['SELECT']);
        $databases = $this->require('linked_databases', $route);
        $this->expect(self::linked($databases, 'database', $names['database'], 'users', $names['user']));
        $users = $this->require('linked_users', $route);
        $this->expect(self::linked($users, 'user', $names['user'], 'databases', $names['database']));
        $password = $this->require('set_password', $route);
        $this->expect(
            is_array($password) && (!array_key_exists('failures', $password) || $password['failures'] === []),
        );
        foreach (['check_database', 'repair_database', 'list_routines'] as $phase) {
            $this->expect($this->require($phase, $route) === []);
        }
        $this->require('dump_database_schema', $route);
        $this->require('revoke_access', $route);
        $this->expect($this->require('read_revoked', $route) === []);
        foreach (['database', 'user'] as $kind) {
            $this->resourceState($route, $kind, 'uncertain');
            $this->invoke('rename_' . $kind, $route);
            if ($this->last()['api_outcome'] === 'success') {
                $this->resourceState($route, $kind, 'renamed');
            }
            $this->successful();
            $identities = $this->inventory('renamed_' . ($kind === 'database' ? 'databases' : 'users'), $route, $kind);
            $this->expect(!in_array($names[$kind], $identities, true)
                && in_array($names['renamed_' . $kind], $identities, true));
        }
        // Deliberately account-wide on the explicitly designated test account; one planned call per route.
        $this->require('update_privileges', $route);
    }

    /** @param Route $route */
    private function cleanup(string $route): void
    {
        foreach (['user', 'database'] as $kind) {
            $state = $this->resources[$route][$kind];
            if ($state !== 'owned' && $state !== 'renamed') {
                continue;
            }
            try {
                $this->require(($state === 'owned' ? 'cleanup_original_' : 'cleanup_') . $kind, $route);
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
                $absent = !in_array($names[$kind], $identities, true)
                    && !in_array($names['renamed_' . $kind], $identities, true);
                $this->expect($absent);
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
     * Original JSON identities must be complete and unique; optional normalized fields alone cannot prove absence.
     *
     * @param Route $route
     * @param 'database'|'user' $kind
     * @return list<string>
     */
    private function inventory(string $phase, string $route, string $kind): array
    {
        $this->require($phase, $route);
        $raw = $this->capture->rawBody();
        $document = $raw === null ? null : json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $envelope = $route === 'direct-uapi'
            ? ($document instanceof stdClass && property_exists($document, 'result') ? $document->result : $document)
            : ($document instanceof stdClass && ($document->data ?? null) instanceof stdClass
                ? ($document->data->uapi ?? null) : null);
        $rows = $envelope instanceof stdClass ? ($envelope->data ?? null) : null;
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->expect(false);
            throw new RuntimeException('Section inventory unavailable.');
        }
        $identities = [];
        foreach ($rows as $row) {
            $identity = $row instanceof stdClass ? ($row->{$kind} ?? null) : null;
            if (!is_string($identity) || $identity === '' || in_array($identity, $identities, true)) {
                $this->expect(false);
                throw new RuntimeException('Section inventory unavailable.');
            }
            $identities[] = $identity;
        }

        return $identities;
    }

    /** Inspect only the exact owned pair; arbitrary other rows are never persisted or changed. */
    private static function linked(
        #[SensitiveParameter] mixed $rows,
        string $field,
        #[SensitiveParameter] string $identity,
        string $nested,
        #[SensitiveParameter] string $other,
    ): bool {
        if (!is_array($rows)) {
            return false;
        }
        $matches = array_values(array_filter($rows, static fn (mixed $row): bool =>
            is_array($row) && ($row[$field] ?? null) === $identity));

        return count($matches) === 1 && ($matches[0][$nested] ?? null) === [$other];
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
        $finished = MysqlSectionEvidence::finish($this->records, $this->context);
        $cleaned = true;
        foreach ($this->resources as $resources) {
            $cleaned = $cleaned && $resources['database'] === 'cleaned' && $resources['user'] === 'cleaned';
        }

        return [
            'recipe' => 'mysql-npr-complete-section-v1',
            'status' => $this->failures === [] && $cleaned && $finished['assessment']['status'] === 'passed'
                ? 'passed' : 'failed',
            ...$finished, 'resources' => $this->resources, 'failures' => array_values(array_unique($this->failures)),
            'unobserved' => [
                'uapi:Mysql/add_host' => 'authorized_host_absence_not_established',
                'uapi:Mysql/add_host_note' => 'authorized_host_absence_not_established',
                'uapi:Mysql/delete_host' => 'authorized_host_absence_not_established',
                'uapi:Mysql/setup_db_and_user' => 'partial_failure_generated_identity_ownership_unavailable',
            ],
            'limitations' => [
                'empty_database_check_and_repair_do_not_observe_table_results',
                'empty_routine_lists_do_not_observe_routine_items',
                'schema_dump_values_are_not_persisted',
                'privilege_readback_does_not_establish_sql_enforcement',
                'password_result_does_not_establish_sql_authentication',
                'update_privileges_is_account_wide_on_the_designated_test_account',
                'unconfirmed_resources_require_manual_review_and_are_not_adopted',
            ],
        ];
    }
}
