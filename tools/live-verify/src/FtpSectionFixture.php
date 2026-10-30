<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * One-shot lifecycle for 16 FTP functions through both account-bound routes on npr/npr.rs.
 *
 * Confirmed API creation grants ownership even when its payload fails validation. Failed or uncertain
 * creation never adopts an account that appears afterward. Cleanup deletes only that owned identity,
 * exactly once, preserving its directory; original-response inventory must confirm absence afterward.
 * Both home-directory arguments resolve to the existing account home. This verifies call execution
 * and readback, not changed-directory isolation. Password results do not establish FTP authentication.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Route from FtpSectionRecipe
 * @phpstan-type Observation array{
 *     phase: string, expectation: 'passed'|'failed'|'not_checked',
 *     operation: string, variant: string, route: string, method: string|null, server_version: string|null,
 *     observed_at: string, revision: string, source_sha256: string, map_sha256: string, generator_tree: string,
 *     overlay_sha256: string, effective_contract_sha256: string, http_status: int|null,
 *     api_outcome: string, payload_validation: string, probe_outcome: string, failure_kind: string|null,
 *     response_paths: array<string, list<string>>, payload_paths: array<string, list<string>>,
 *     discrepancies: list<array{path: string, kind: string}>
 * }
 * @phpstan-type State 'not_started'|'owned'|'cleaned'|'uncertain'
 * @phpstan-type Report array{
 *     recipe: string, status: 'passed'|'failed', probes: list<Observation>, resources: array<Route, State>,
 *     failures: list<string>, unobserved: array<string, string>, limitations: list<string>,
 *     assessment: array{status: 'passed'|'failed', findings: list<string>}
 * }
 */
final class FtpSectionFixture
{
    private readonly FtpSectionRecipe $recipe;

    private readonly FtpSectionGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    /** @var array<'whm'|Route, Client> */
    private readonly array $clients;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<Route, State> */
    private array $resources = ['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'];

    /** @var list<string> */
    private array $failures = [];

    private bool $used = false;

    /**
     * Construct without I/O; live callers must obtain committed context through RepositoryContext first.
     *
     * @param array{whm: string, cpanel: string} $tokens
     * @param array<Route, string> $suffixes
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] array $tokens,
        #[SensitiveParameter] array $suffixes,
        private readonly array $context,
    ) {
        $this->recipe = new FtpSectionRecipe($suffixes, 'Ftp!9' . bin2hex(random_bytes(20)));
        $this->guard = new FtpSectionGuard($http, $tokens, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context);
        $factory = new Psr17Factory();
        $transport = new HttpTransport($this->capture, $factory, $factory);
        $direct = new Client(['host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
            'username' => 'npr', 'token' => $tokens['cpanel']], $transport);
        $bridge = new Client(['host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $tokens['whm']], $transport);
        $this->clients = ['whm' => $bridge, 'direct-uapi' => $direct, 'bridged-uapi' => $bridge];
    }

    /**
     * Capture original responses once, returning sanitized structural evidence including failures.
     *
     * Each route has independent cleanup and no operation is retried. Raw rows and identities remain
     * in memory solely to check fixture ownership, home and quota; no raw value or upstream error is returned.
     *
     * @return Report
     */
    public function run(): array
    {
        if ($this->used || !FtpSectionGuard::authorized()) {
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
                $this->exercise($route);
            } catch (Throwable) {
                $this->failures[] = $route . ':exercise_failure';
            } finally {
                $this->cleanup($route);
            }
        }

        return $this->report();
    }

    /** @param Route $route */
    private function exercise(string $route): void
    {
        $user = $this->recipe->user($route);
        $rows = $this->inventory('initial_accounts', $route);
        $this->expect(!isset($rows[$user]) && !isset($rows[$user . '@npr.rs']));
        foreach (['daemon', 'server', 'anonymous', 'anonymous_incoming', 'port', 'welcome', 'sessions'] as $phase) {
            $this->require($phase, $route);
        }
        $this->resources[$route] = 'uncertain';
        $this->invoke('create_account', $route);
        if ($this->last()['api_outcome'] === 'success') {
            $this->resources[$route] = 'owned';
        }
        $this->successful();
        $rows = $this->inventory('created_accounts', $route);
        $row = $this->ownedRow($rows, $user);
        $home = self::directory($row->homedir ?? null);
        $this->expect($home !== null && ($row->type ?? null) === 'sub');
        $this->require('exists', $route);
        $this->expect($this->require('initial_quota', $route) === 'unlimited');
        $this->require('change_password', $route);
        $this->require('set_home', $route);
        $rows = $this->inventory('home_accounts', $route);
        $row = $this->ownedRow($rows, $user);
        $this->expect(self::directory($row->homedir ?? null) === $home && ($row->type ?? null) === 'sub');
        $this->require('set_quota', $route);
        $this->expect($this->require('finite_quota', $route) === '17');
        $rows = $this->require('disk_accounts', $route);
        $matches = is_array($rows) ? array_values(array_filter($rows, static fn (mixed $row): bool =>
            is_array($row) && in_array($row['login'] ?? null, [$user, $user . '@npr.rs'], true))) : [];
        $this->expect(count($matches) === 1 && self::directory($matches[0]['dir'] ?? null) === $home
            && ($matches[0]['accttype'] ?? null) === 'sub' && ($matches[0]['_diskquota'] ?? null) === '17.00'
            && ($matches[0]['diskquota'] ?? null) === '17.00');
    }

    /** @param Route $route */
    private function cleanup(string $route): void
    {
        if ($this->resources[$route] === 'owned') {
            try {
                $this->require('cleanup_account', $route);
            } catch (Throwable) {
                $this->failures[] = $route . ':cleanup_request_failure';
            }
        }
        try {
            $rows = $this->inventory('final_accounts', $route);
            if ($this->resources[$route] !== 'not_started') {
                $user = $this->recipe->user($route);
                $this->expect(!isset($rows[$user]) && !isset($rows[$user . '@npr.rs']));
                $this->resources[$route] = 'cleaned';
            }
        } catch (Throwable) {
            if ($this->resources[$route] !== 'not_started') {
                $this->resources[$route] = 'uncertain';
            }
            $this->failures[] = $route . ':cleanup_unconfirmed';
        }
    }

    /** @param 'whm'|Route $route */
    private function invoke(string $phase, string $route): mixed
    {
        $probe = $this->recipe->step($phase, $route)['probe'];
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
        $this->records[] = [...$record, 'phase' => $phase,
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];

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
            throw new RuntimeException('FTP section observation failed.');
        }
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $index = array_key_last($this->records);
            if ($index !== null) {
                $this->records[$index]['expectation'] = 'failed';
            }
            throw new RuntimeException('FTP section expectation failed.');
        }
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('FTP section observation unavailable.');
        }

        return $this->records[$index];
    }

    /**
     * Require every raw inventory row to have a unique nonempty identity before using absence as evidence.
     *
     * @param Route $route
     * @return array<string, stdClass>
     */
    private function inventory(string $phase, string $route): array
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
            throw new RuntimeException('FTP section inventory unavailable.');
        }
        $identities = [];
        foreach ($rows as $row) {
            $user = $row instanceof stdClass ? ($row->user ?? null) : null;
            if (!is_string($user) || $user === '' || isset($identities[$user])) {
                $this->expect(false);
                throw new RuntimeException('FTP section inventory unavailable.');
            }
            $identities[$user] = $row;
        }

        return $identities;
    }

    /** Resolve only the two exact spellings of the owned identity and reject ambiguous rows.
     * @param array<string, stdClass> $rows
     */
    private function ownedRow(#[SensitiveParameter] array $rows, #[SensitiveParameter] string $user): stdClass
    {
        $matches = array_intersect_key($rows, array_flip([$user, $user . '@npr.rs']));
        $this->expect(count($matches) === 1);
        $row = reset($matches);
        if (!$row instanceof stdClass) {
            throw new RuntimeException('FTP owned inventory unavailable.');
        }

        return $row;
    }

    /** Lexically normalize equivalent absolute home paths without resolving or following filesystem links. */
    private static function directory(#[SensitiveParameter] mixed $directory): ?string
    {
        if (!is_string($directory) || !str_starts_with($directory, '/')) {
            return null;
        }
        $segments = explode('/', $directory);
        if (in_array('..', $segments, true)) {
            return null;
        }

        return '/' . implode('/', array_filter($segments, static fn (string $part): bool =>
            $part !== '' && $part !== '.'));
    }

    /** @return Report */
    private function report(): array
    {
        $finished = FtpSectionEvidence::finish($this->records, $this->context);
        $cleaned = $this->resources === ['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'];

        return ['recipe' => 'ftp-npr-complete-section-v1',
            'status' => $this->failures === [] && $cleaned && $finished['assessment']['status'] === 'passed'
                ? 'passed' : 'failed', ...$finished, 'resources' => $this->resources,
            'failures' => array_values(array_unique($this->failures)),
            'unobserved' => ['uapi:Ftp/kill_session' => 'no_owned_ftp_session',
                'uapi:Ftp/set_anonymous_ftp' => 'account_setting_not_changed',
                'uapi:Ftp/set_anonymous_ftp_incoming' => 'account_setting_not_changed',
                'uapi:Ftp/set_welcome_message' => 'account_setting_not_changed'],
            'limitations' => ['empty_session_lists_do_not_observe_session_fields',
                'home_directory_execution_does_not_establish_changed_directory_isolation',
                'password_result_does_not_establish_ftp_authentication',
                'quota_readback_does_not_establish_transfer_enforcement',
                'directory_contents_are_never_deleted',
                'unconfirmed_accounts_require_manual_review_and_are_not_adopted']];
    }
    /** Keep passwords, raw captures and resource identities out of ordinary diagnostic dumps.
     * @return array{}
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Fixture state contains credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('FTP section state cannot be serialized.');
    }
}
