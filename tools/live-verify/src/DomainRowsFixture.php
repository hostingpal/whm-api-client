<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
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
 * Read every DomainInfo record kind and the multi-value DNS reads on one fresh disposable account, then remove it.
 *
 * DisposableAccountLifecycle owns identity, ownership, the short-lived writable token, account cleanup and the run's
 * status, and states their rules. This fixture adds three domains inside that account and reads them. Before anything
 * is added it reads every extra name absent server-wide and reads the account's marker again; then it raises this
 * account's own addon, alias and subdomain limits and creates an addon domain, an alias of the main domain and a
 * subdomain, each once, through the account's own token. A creation counts only once the server's inventory lists it
 * for this account. Account removal takes them all, so after removal it reads that every extra domain and zone this
 * run made is gone, through the lifecycle's removed-resource proof.
 *
 * The reads mutate nothing, so every planned read runs even after one fails, and each records its own outcome. The DNS
 * reads send the main domain and the addon, two distinct owned domains, in both orders. Where a result names its
 * domain, the report records whether that sample came back in request order; no general order is claimed from it.
 *
 * Results carry fixed labels, counts and structural evidence only. Names, values, tokens and upstream text never
 * leave memory. An optional inspector sees each original body in memory, for the private name inspection that
 * precedes typing a field; it must print names and types only and keep nothing.
 *
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Assessment from DomainRowsEvidence
 * @phpstan-import-type CleanupOutcome from DisposableAccountLifecycle
 * @phpstan-import-type ResourceOutcome from DisposableAccountLifecycle
 * @phpstan-import-type TokenOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemControl from DisposableAccountLifecycle
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 * @phpstan-type Observation array{
 *     phase: string, expectation: 'passed'|'failed'|'not_checked', uapi_status: int|null,
 *     operation: string, variant: string, argument_variant?: string, route: string, method: string|null,
 *     server_version: string|null, observed_at: string, revision: string, source_sha256: string,
 *     map_sha256: string, generator_tree: string, overlay_sha256: string, effective_contract_sha256: string,
 *     http_status: int|null, api_outcome: string, payload_validation: string, probe_outcome: string,
 *     failure_kind: string|null, response_paths: array<string, list<string>>,
 *     payload_paths: array<string, list<string>>, discrepancies: list<array{path: string, kind: string}>,
 *     payload_alternatives?: list<Alternative>
 * }
 * @phpstan-type DnsResult array{
 *     requested: int, returned: int|null, identities: 'request_set'|'mismatch'|'not_observable',
 *     order: 'request'|'reverse'|'other'|'not_observable'
 * }
 * @phpstan-type Kinds array{main: bool, addon: bool, parked: bool, sub: bool, addon_subdomain: bool}
 * @phpstan-type ExtraFilesystem array{
 *     control: FilesystemControl, cleanup: FilesystemOutcome, residue: array<string, int>
 * }
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started', capture: 'passed'|'failed'|'not_run',
 *     expectations: 'passed'|'failed'|'not_run', cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     stopped_at: string|null,
 *     resources: array<string, ResourceOutcome|TokenOutcome>,
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>,
 *     filesystem_control: FilesystemControl,
 *     extra_filesystem: array{addon_domain: ExtraFilesystem, alias_domain: ExtraFilesystem}
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'domain-rows-disposable-v1', target_role: 'disposable-test-account', probes: list<Observation>,
 *     dns_results: array<string, DnsResult>,
 *     dns_order_samples: array<string, array{request: int, reverse: int, other: int, not_observable: int}>,
 *     domain_kinds: array<string, Kinds>, single_domain: array<string, string>,
 *     discrepancy_review: Assessment, fixture: Report, limitations: list<string>
 * }
 */
final class DomainRowsFixture
{
    private const HOST = 'test.oblak.host';

    /** Long enough for this run and short enough that a lost token dies quickly; cleanup still revokes it. */
    private const TOKEN_LIFETIME = 900;

    /** What each creation may make, by report label; it counts once the inventory lists it for this account. */
    private const CREATES = [
        'create_addon' => ['addon_domain', 'addon_subdomain', 'addon_zone'],
        'park_alias' => ['alias_domain', 'alias_zone'],
        'create_subdomain' => ['subdomain'],
    ];

    /** The creations the reads depend on; the addon's implicit subdomain and the zones are only recorded. */
    private const REQUIRED = ['addon_domain', 'alias_domain', 'subdomain'];

    /** The extra domains with a zone of their own, which the optional filesystem proof can also check. */
    private const FILESYSTEM_DOMAINS = [
        'addon_domain' => DomainRowsRecipe::ADDON,
        'alias_domain' => DomainRowsRecipe::ALIAS,
    ];

    private readonly DomainRowsRecipe $recipe;

    private readonly DomainRowsGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    private readonly Client $root;

    private readonly DisposableAccountLifecycle $lifecycle;

    private ?Client $direct = null;

    /** @var Report */
    private array $report;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<string, DnsResult> */
    private array $dns = [];

    /** @var array<string, Kinds> */
    private array $kinds = [];

    /** @var array<string, string> */
    private array $singles = [];

    /** @var array<string, true> Labels whose creation outcome no later read can settle. */
    private array $unknown = [];

    private bool $expectationFailed = false;

    private ?string $stopLabel = null;

    /**
     * Bind the fixed disposable account and a token expiration, without I/O.
     *
     * The client must support form POST, verify TLS and refuse redirects and retries. The guard re-checks both live
     * opt-ins and the exact account and domain on every request. Live callers must obtain committed context through
     * RepositoryContext first. Without a filesystem proof, nothing is claimed about the server's files.
     *
     * @param Context $context
     * @param (Closure(string, Probe, ?string): void)|null $inspector Sees each original body in memory only.
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] string $whmToken,
        private readonly array $context,
        private readonly ?FilesystemAbsenceProof $filesystem = null,
        #[SensitiveParameter] private readonly ?Closure $inspector = null,
    ) {
        $this->recipe = new DomainRowsRecipe(DisposableAccount::fresh(
            DomainRowsRecipe::ACCOUNT,
            DomainRowsRecipe::DOMAIN,
            DomainRowsRecipe::TOKEN_NAME,
            self::TOKEN_LIFETIME,
            readOnlyToken: false,
        ));
        $this->guard = new DomainRowsGuard($http, $whmToken, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context, DomainRowsRecipe::ACCOUNT);
        $factory = new Psr17Factory();
        $this->root = new Client([
            'host' => self::HOST, 'port' => 2087, 'authType' => 'whm', 'username' => 'root', 'token' => $whmToken,
        ], new HttpTransport($this->capture, $factory, $factory));
        $this->lifecycle = new DisposableAccountLifecycle(
            $this->recipe->account,
            $this->capture,
            $this->root,
            $filesystem,
        );
    }

    /**
     * Set up, capture, clean up, and return sanitized evidence including every failure. Single-use.
     *
     * @return Scenario
     */
    public function run(): array
    {
        $this->report = self::report();
        try {
            $outcome = $this->lifecycle->run(
                function (#[SensitiveParameter] string $token): void {
                    $this->capture($token);
                },
                null,
                fn (): array => $this->proveRemoved(),
            );
        } catch (LogicException) {
            $this->report['failures'][] = 'configuration_failure';

            return $this->scenario();
        }
        $lifecycle = DisposableAccountLifecycle::reportFields($outcome);
        $this->report = [
            ...$this->report,
            ...$lifecycle,
            'resources' => [...$this->report['resources'], ...$lifecycle['resources']],
        ];

        return $this->scenario();
    }

    /**
     * Bind the token, add the domains, run every read, and tell the lifecycle what failed.
     *
     * @throws ExerciseFailure After recording, for a stopped or failed capture or any failed expectation.
     */
    private function capture(#[SensitiveParameter] string $token): void
    {
        $failures = [];
        $this->report['capture'] = 'failed';
        try {
            $this->guard->bindToken($token);
            $factory = new Psr17Factory();
            $direct = $this->direct = new Client([
                'host' => self::HOST, 'port' => 2083, 'authType' => 'cpanel',
                'username' => DomainRowsRecipe::ACCOUNT, 'token' => $token,
            ], new HttpTransport($this->capture, $factory, $factory));
            $this->setUp($direct);
            foreach ([...DomainRowsRecipe::DNS_PHASES, ...DomainRowsRecipe::DOMAIN_INFO_PHASES] as $phase) {
                $this->read($phase, $direct);
            }
            if (!in_array('not_checked', array_column($this->records, 'expectation'), true)) {
                $this->report['capture'] = 'passed';
            }
        } catch (Throwable) {
            $last = array_key_last($this->records);
            $this->report['stopped_at'] = $this->stopLabel
                ?? ($last === null ? null : $this->records[$last]['phase']);
        }
        if ($this->stopLabel !== null) {
            $failures[] = $this->stopLabel;
        }
        if ($this->report['capture'] === 'failed') {
            $failures[] = 'capture_failure';
        }
        if ($this->expectationFailed) {
            $this->report['expectations'] = 'failed';
            $failures[] = 'expectation_failure';
        } elseif ($this->report['capture'] === 'passed') {
            $this->report['expectations'] = 'passed';
        }
        if ($failures !== []) {
            throw new ExerciseFailure($failures);
        }
    }

    /** Add the three domains once each, gated by fresh absence and ownership, and confirm what the server lists. */
    private function setUp(Client $direct): void
    {
        $this->succeed('version', $this->root);
        try {
            $domains = $this->domainRows();
            $zones = $this->zoneRows();
        } catch (Throwable $failure) {
            $this->stop('extra_identity_inventory_unusable', $failure);
        }
        foreach ([...DomainRowsRecipe::DOMAINS, ...DomainRowsRecipe::ZONES] as $name) {
            if (isset($domains[$name]) || in_array($name, $zones, true)) {
                $this->stop('extra_identity_already_present');
            }
        }
        $this->requireOwnership();
        if ($this->observe('limits', $this->root)['api_outcome'] !== 'success') {
            $this->stop('setup_phase_failure');
        }
        foreach (self::CREATES as $phase => $labels) {
            $this->create($phase, $labels, $direct);
        }
        $this->observeFilesystemControls();
    }

    /**
     * Send one creation once, then confirm what it made from the server's inventory before anything else is sent.
     *
     * Only the server's own answer is a known outcome, so after a lost one the labels stay unconfirmed for good. A
     * name counts as made only once the inventory lists it for this account, a zone once it is listed.
     *
     * @param list<string> $labels
     */
    private function create(string $phase, array $labels, Client $direct): void
    {
        foreach ($labels as $label) {
            $this->report['resources'][$label]['creation'] = 'unconfirmed';
        }
        $record = $this->observe($phase, $direct);
        if (!in_array($record['api_outcome'], ['success', 'failure'], true)) {
            foreach ($labels as $label) {
                $this->unknown[$label] = true;
            }
        }
        // A payload that breaks the contract after the server accepted the call stays a failed observation, but the
        // creation happened, so the inventory decides; only a refused or lost one stops here.
        if ($record['api_outcome'] !== 'success') {
            $this->stop('extra_identity_creation_failure');
        }
        try {
            $domains = $this->domainRows();
            $zones = $this->zoneRows();
        } catch (Throwable $failure) {
            $this->stop('extra_identity_inventory_unusable', $failure);
        }
        foreach ($labels as $label) {
            $listed = isset(DomainRowsRecipe::ZONES[$label])
                ? in_array(DomainRowsRecipe::ZONES[$label], $zones, true)
                : ($domains[DomainRowsRecipe::DOMAINS[$label]] ?? null) === DomainRowsRecipe::ACCOUNT;
            if ($listed) {
                $this->report['resources'][$label]['creation'] = 'confirmed';
            } elseif (in_array($label, self::REQUIRED, true)) {
                $this->markFailed();
                $this->stop('extra_identity_unconfirmed');
            }
        }
    }

    /** Read the account's marker again right before this run's first own mutation; nothing proceeds without it. */
    private function requireOwnership(): void
    {
        try {
            $this->root->whm()->call('listaccts', ['want' => 'user,domain,email'], RequestMethod::Get);
            $rows = self::payload($this->capture->rawBody())->acct ?? null;
            $domains = self::identityRows($rows, 'user', 'domain');
        } catch (Throwable $failure) {
            $this->stop('ownership_unconfirmed', $failure);
        }
        $email = null;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($row instanceof stdClass && ($row->user ?? null) === DomainRowsRecipe::ACCOUNT) {
                $email = $row->email ?? null;
            }
        }
        if (
            ($domains[DomainRowsRecipe::ACCOUNT] ?? null) !== DomainRowsRecipe::DOMAIN
            || !$this->recipe->account->isContactMarker($email)
        ) {
            $this->stop('ownership_unconfirmed');
        }
    }

    /** Run one read once and judge what it shows; a failed read records its outcome and never stops the others. */
    private function read(string $phase, Client $direct): void
    {
        $result = null;
        $record = $this->observe($phase, $direct, $result);
        if (in_array($phase, DomainRowsRecipe::expectedRefusals(), true)) {
            $this->singles[$phase] = $record['failure_kind'] === 'api_failure' ? 'api_refusal' : 'not_refused';
            $this->judge($record['failure_kind'] === 'api_failure');

            return;
        }
        if ($record['probe_outcome'] !== 'passed') {
            if (str_contains($phase, '_single_')) {
                $this->singles[$phase] = 'not_observed';
            }

            return;
        }
        match (true) {
            in_array($phase, DomainRowsRecipe::DNS_PHASES, true) => $this->judgeDns($phase, $result),
            str_contains($phase, '_single_') => $this->judgeSingle($phase, $result),
            default => $this->judgeKinds($phase, $result),
        };
    }

    /** Count per-domain results and, where a result names its domain, record this one sample's order. */
    private function judgeDns(string $phase, #[SensitiveParameter] mixed $result): void
    {
        $requested = DomainRowsRecipe::requestedDomains($phase);
        $operation = explode('_', $phase)[1];
        $rows = match (true) {
            $operation === 'ds' => is_array($result) ? ($result['domains'] ?? null) : null,
            $operation === 'authority' && DomainRowsRecipe::route($phase) === 'whm'
                => is_array($result) ? ($result['records'] ?? null) : null,
            default => $result,
        };
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->dns[$phase] = ['requested' => 2, 'returned' => null, 'identities' => 'not_observable',
                'order' => 'not_observable'];
            $this->judge(false);

            return;
        }
        $identities = 'not_observable';
        $order = 'not_observable';
        if (in_array($operation, ['authority', 'ds'], true)) {
            $names = array_map(
                static fn (mixed $row): mixed => is_array($row) ? ($row['domain'] ?? null) : null,
                $rows,
            );
            $sorted = $names;
            sort($sorted);
            $expected = $requested;
            sort($expected);
            $identities = $sorted === $expected ? 'request_set' : 'mismatch';
            $order = match (true) {
                $identities === 'mismatch' => 'other',
                $names === $requested => 'request',
                default => 'reverse',
            };
        }
        $this->dns[$phase] = ['requested' => 2, 'returned' => count($rows), 'identities' => $identities,
            'order' => $order];
        $this->judge(count($rows) === 2 && $identities !== 'mismatch');
    }

    /**
     * Record which owned kinds an inventory read lists; main, addon and subdomain must always appear.
     *
     * The alias must appear too, except in domains_data format=list: on cPanel 11.138.0.19 the private inspection
     * found no alias row there, and the alias shows only as a name in the hash form. Where it does appear it is
     * recorded. The subdomain an addon is parked on is recorded and never required.
     */
    private function judgeKinds(string $phase, #[SensitiveParameter] mixed $result): void
    {
        $payload = is_array($result) ? $result : [];
        if (str_ends_with($phase, '_list_domains')) {
            $kinds = [
                'main' => ($payload['main_domain'] ?? null) === DomainRowsRecipe::DOMAIN,
                'addon' => self::names($payload['addon_domains'] ?? null, DomainRowsRecipe::ADDON),
                'parked' => self::names($payload['parked_domains'] ?? null, DomainRowsRecipe::ALIAS),
                'sub' => self::names($payload['sub_domains'] ?? null, DomainRowsRecipe::SUBDOMAIN),
                'addon_subdomain' => self::names($payload['sub_domains'] ?? null, DomainRowsRecipe::ADDON_SUBDOMAIN),
            ];
        } elseif (array_is_list($payload)) {
            $kinds = [
                'main' => self::rows($payload, DomainRowsRecipe::DOMAIN),
                'addon' => self::rows($payload, DomainRowsRecipe::ADDON),
                'parked' => self::rows($payload, DomainRowsRecipe::ALIAS),
                'sub' => self::rows($payload, DomainRowsRecipe::SUBDOMAIN),
                'addon_subdomain' => self::rows($payload, DomainRowsRecipe::ADDON_SUBDOMAIN),
            ];
        } else {
            $main = $payload['main_domain'] ?? null;
            $kinds = [
                'main' => is_array($main) && (array_is_list($main) ? self::rows($main, DomainRowsRecipe::DOMAIN)
                    : ($main['domain'] ?? null) === DomainRowsRecipe::DOMAIN),
                'addon' => self::rows($payload['addon_domains'] ?? null, DomainRowsRecipe::ADDON),
                'parked' => self::names($payload['parked_domains'] ?? null, DomainRowsRecipe::ALIAS)
                    || self::rows($payload['parked_domains'] ?? null, DomainRowsRecipe::ALIAS),
                'sub' => self::rows($payload['sub_domains'] ?? null, DomainRowsRecipe::SUBDOMAIN),
                'addon_subdomain' => self::rows($payload['sub_domains'] ?? null, DomainRowsRecipe::ADDON_SUBDOMAIN),
            ];
        }
        $this->kinds[$phase] = $kinds;
        $this->judge(
            $kinds['main'] && $kinds['addon'] && $kinds['sub']
            && ($kinds['parked'] || str_ends_with($phase, '_domains_list')),
        );
    }

    /** Record which of this run's names a single-domain read returned; it must be one of them. */
    private function judgeSingle(string $phase, #[SensitiveParameter] mixed $result): void
    {
        $domain = is_array($result) ? ($result['domain'] ?? null) : null;
        $requested = match (substr($phase, strpos($phase, '_single_') + 8)) {
            'main' => DomainRowsRecipe::DOMAIN,
            'addon' => DomainRowsRecipe::ADDON,
            'alias' => DomainRowsRecipe::ALIAS,
            default => DomainRowsRecipe::SUBDOMAIN,
        };
        $this->singles[$phase] = match ($domain) {
            $requested => 'requested_domain',
            DomainRowsRecipe::ADDON_SUBDOMAIN => 'addon_subdomain',
            DomainRowsRecipe::DOMAIN => 'main_domain',
            default => 'other',
        };
        $this->judge($this->singles[$phase] !== 'other');
    }

    /** True when a list of plain names holds the name. */
    private static function names(#[SensitiveParameter] mixed $list, string $name): bool
    {
        return is_array($list) && in_array($name, $list, true);
    }

    /** True when a list of rows holds one whose domain is the name. */
    private static function rows(#[SensitiveParameter] mixed $list, string $name): bool
    {
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $row) {
            if (is_array($row) && ($row['domain'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /** Take the optional filesystem control for each extra domain with its own zone, while it exists. */
    private function observeFilesystemControls(): void
    {
        if ($this->filesystem === null) {
            return;
        }
        foreach (self::FILESYSTEM_DOMAINS as $label => $domain) {
            $finding = $this->readFilesystem($domain);
            $missing = array_diff(FilesystemAbsenceProof::ACCOUNT_LOCATIONS, array_keys($finding['residue']));
            $this->report['extra_filesystem'][$label]['control'] = [
                'status' => $finding['outcome'] === 'present' && $missing === [] ? 'passed' : 'failed',
                'counts' => $finding['residue'],
            ];
        }
    }

    /**
     * After account removal, read that every extra domain and zone this run attempted is gone; mutates nothing.
     *
     * @return list<string> Failure labels for the lifecycle's report.
     */
    private function proveRemoved(): array
    {
        $attempted = array_keys(array_filter(
            $this->report['resources'],
            static fn (array $outcome): bool => $outcome['creation'] !== 'not_attempted',
        ));
        $attempted = array_values(array_intersect(
            $attempted,
            [...array_keys(DomainRowsRecipe::DOMAINS), ...array_keys(DomainRowsRecipe::ZONES)],
        ));
        if ($attempted === []) {
            return [];
        }
        try {
            $domains = $this->domainRows();
        } catch (Throwable) {
            $domains = null;
        }
        try {
            $zones = $this->zoneRows();
        } catch (Throwable) {
            $zones = null;
        }
        $failures = [];
        foreach ($attempted as $label) {
            $zone = isset(DomainRowsRecipe::ZONES[$label]);
            $present = $zone
                ? ($zones === null ? null : in_array(DomainRowsRecipe::ZONES[$label], $zones, true))
                : ($domains === null ? null : isset($domains[DomainRowsRecipe::DOMAINS[$label]]));
            if ($present === true) {
                $this->report['resources'][$label]['cleanup'] = 'present';
                $failures[] = $label . '_still_present';
            } elseif ($present === null || isset($this->unknown[$label])) {
                $this->report['resources'][$label]['cleanup'] = 'unconfirmed';
                $failures[] = $label . '_absence_unconfirmed';
            } else {
                $this->report['resources'][$label]['cleanup'] = 'confirmed_absent';
            }
        }

        return [...$failures, ...$this->proveFilesystemAbsence()];
    }

    /**
     * Run the final filesystem check for each extra domain whose control ran; absence counts only after a passed one.
     *
     * @return list<string>
     */
    private function proveFilesystemAbsence(): array
    {
        if ($this->filesystem === null) {
            return [];
        }
        $failures = [];
        foreach (self::FILESYSTEM_DOMAINS as $label => $domain) {
            $control = $this->report['extra_filesystem'][$label]['control']['status'];
            if ($control === 'not_run') {
                continue;
            }
            $finding = $this->readFilesystem($domain);
            if ($control === 'failed') {
                $failures[] = 'extra_filesystem_control_failed';
                if ($finding['outcome'] === 'confirmed_absent') {
                    $finding = ['outcome' => 'unconfirmed', 'residue' => []];
                }
            }
            $this->report['extra_filesystem'][$label]['cleanup'] = $finding['outcome'];
            $this->report['extra_filesystem'][$label]['residue'] = $finding['residue'];
            if ($finding['outcome'] === 'present') {
                $failures[] = 'extra_filesystem_residue_present';
            } elseif ($finding['outcome'] === 'unconfirmed') {
                $failures[] = 'extra_filesystem_absence_unconfirmed';
            }
        }

        return array_values(array_unique($failures));
    }

    /** @return Finding */
    private function readFilesystem(string $domain): array
    {
        try {
            return $this->filesystem?->check(DomainRowsRecipe::ACCOUNT, $domain)
                ?? ['outcome' => 'unconfirmed', 'residue' => []];
        } catch (Throwable) {
            return ['outcome' => 'unconfirmed', 'residue' => []];
        }
    }

    /**
     * Read every manually configured domain on the server with its owner; empty or unusable proves nothing.
     *
     * @return array<string, string>
     * @phpstan-impure
     */
    private function domainRows(): array
    {
        $this->root->whm()->call('get_domain_info', [], RequestMethod::Get);

        return self::identityRows(self::payload($this->capture->rawBody())->domains ?? null, 'domain', 'user');
    }

    /**
     * Read every zone on the server; empty or unusable proves nothing.
     *
     * @return list<string>
     * @phpstan-impure
     */
    private function zoneRows(): array
    {
        $this->root->whm()->call('listzones', [], RequestMethod::Get);

        return array_keys(self::identityRows(self::payload($this->capture->rawBody())->zone ?? null, 'domain'));
    }

    /** The WHM payload of the response just captured, keeping JSON object and list distinct. */
    private static function payload(#[SensitiveParameter] ?string $raw): ?stdClass
    {
        if ($raw === null) {
            throw new RuntimeException('Domain rows identity inventory was not captured.');
        }
        $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $payload = $document instanceof stdClass ? ($document->data ?? null) : null;

        return $payload instanceof stdClass ? $payload : null;
    }

    /**
     * Validate every row of a server-wide inventory; a nonlist, an empty list or an unusable row proves nothing.
     *
     * @return non-empty-array<string, string> Each identity with its related value, or an empty string without one.
     */
    private static function identityRows(
        #[SensitiveParameter] mixed $rows,
        string $identity,
        ?string $related = null,
    ): array {
        if (!is_array($rows) || !array_is_list($rows) || $rows === []) {
            throw new RuntimeException('Domain rows identity inventory cannot prove anything.');
        }
        $identities = [];
        foreach ($rows as $row) {
            $name = $row instanceof stdClass ? ($row->{$identity} ?? null) : null;
            $value = $row instanceof stdClass && $related !== null ? ($row->{$related} ?? null) : '';
            if (
                !is_string($name) || $name === '' || isset($identities[$name]) || !is_string($value)
                || ($related !== null && $value === '')
            ) {
                throw new RuntimeException('Domain rows identity inventory is unusable.');
            }
            $identities[$name] = $value;
        }

        return $identities;
    }

    /**
     * Capture one phase through the shared runner, keeping its result in memory for the judgment that follows.
     *
     * The runner clears prior capture and never retries; the record carries only structural evidence. A phase whose
     * documented outcome is an API refusal passes its expectation only when it was exactly that.
     *
     * @return Observation
     */
    private function observe(string $phase, Client $direct, mixed &$result = null): array
    {
        $probe = DomainRowsRecipe::probe($phase);
        $invoke = $probe->invoke;
        $wrapped = new Probe(
            $probe->operation,
            $probe->route,
            $probe->spec,
            $probe->uri,
            static function (#[SensitiveParameter] Client $client) use ($invoke, &$result): mixed {
                return $result = $invoke($client);
            },
            null,
            $probe->sourceVariant,
            $probe->argumentVariant,
        );
        $client = $probe->route === 'direct-uapi' ? $direct : $this->root;
        $record = $this->runner->capture($wrapped, $this->capture, $client);
        if ($this->inspector !== null) {
            try {
                ($this->inspector)($phase, $probe, $this->capture->rawBody());
            } catch (Throwable) {
                // Inspection is advisory and never changes a run.
            }
        }
        $handle = match ($probe->route) {
            'direct-uapi' => $direct->uapi(),
            'bridged-uapi' => $this->root->uapi(DomainRowsRecipe::ACCOUNT),
            default => null,
        };
        $refusal = in_array($phase, DomainRowsRecipe::expectedRefusals(), true)
            && $record['failure_kind'] === 'api_failure';
        $observation = [...$record, 'phase' => $phase, 'uapi_status' => $handle?->responseMeta()?->uapiStatus(),
            'expectation' => $record['probe_outcome'] === 'passed' || $refusal ? 'passed' : 'not_checked'];
        $this->records[] = $observation;

        return $observation;
    }

    private function succeed(string $phase, Client $client): void
    {
        if ($this->observe($phase, $client)['probe_outcome'] !== 'passed') {
            $this->stop('setup_phase_failure');
        }
    }

    /** Fail the latest record's expectation, without stopping the reads that follow. */
    private function judge(bool $condition): void
    {
        if (!$condition) {
            $this->markFailed();
        }
    }

    private function markFailed(): void
    {
        $this->expectationFailed = true;
        $index = array_key_last($this->records);
        if ($index !== null) {
            $this->records[$index]['expectation'] = 'failed';
        }
    }

    /** Stop capture under a fixed label; nothing after this point is sent. */
    private function stop(string $label, ?Throwable $previous = null): never
    {
        $this->stopLabel ??= $label;

        throw new RuntimeException('Domain rows capture stopped.', 0, $previous);
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];
        $filesystem = ['control' => ['status' => 'not_run', 'counts' => []], 'cleanup' => 'not_claimed',
            'residue' => []];

        return [
            'status' => 'failed', 'setup' => 'not_started', 'capture' => 'not_run',
            'expectations' => 'not_run', 'cleanup' => 'not_required', 'failures' => [], 'stopped_at' => null,
            'resources' => [
                'account' => $resource, 'token' => $resource,
                'addon_domain' => $resource, 'addon_subdomain' => $resource, 'alias_domain' => $resource,
                'subdomain' => $resource, 'addon_zone' => $resource, 'alias_zone' => $resource,
            ],
            'domain_cleanup' => 'not_required', 'zone_cleanup' => 'not_required',
            'filesystem_cleanup' => 'not_claimed', 'filesystem_residue' => [],
            'filesystem_control' => ['status' => 'not_run', 'counts' => []],
            'extra_filesystem' => ['addon_domain' => $filesystem, 'alias_domain' => $filesystem],
        ];
    }

    /**
     * Assemble the sanitized result, attributing every observation to the run's one server version.
     *
     * @return Scenario
     */
    private function scenario(): array
    {
        $versions = array_column(array_filter($this->records, static fn (array $record): bool =>
            $record['phase'] === 'version'), 'server_version');
        $records = $this->records;
        foreach ($records as &$record) {
            $record['server_version'] ??= is_string($versions[0] ?? null) ? $versions[0] : null;
        }
        unset($record);
        $samples = [];
        foreach ($this->dns as $phase => $result) {
            $key = DomainRowsEvidence::contract($phase)['operation'] . '@' . DomainRowsRecipe::route($phase);
            $samples[$key] ??= ['request' => 0, 'reverse' => 0, 'other' => 0, 'not_observable' => 0];
            ++$samples[$key][$result['order']];
        }

        return [
            'scenario' => 'domain-rows-disposable-v1',
            'target_role' => 'disposable-test-account',
            'probes' => $records,
            'dns_results' => $this->dns,
            'dns_order_samples' => $samples,
            'domain_kinds' => $this->kinds,
            'single_domain' => $this->singles,
            'discrepancy_review' => DomainRowsEvidence::assess($records, $this->context),
            'fixture' => $this->report,
            'limitations' => [
                'one_server_version_and_one_disposable_account',
                'dns_order_is_recorded_per_sample_and_never_generalized',
                'ensure_results_name_no_domain_so_their_order_is_not_observable',
                'no_dnssec_keys_so_ds_records_are_unpopulated',
                'https_redirect_status_not_requested',
                'export_zone_files_excluded',
                $this->report['filesystem_cleanup'] === 'confirmed_absent'
                && array_unique(array_column($this->report['extra_filesystem'], 'cleanup')) === ['confirmed_absent']
                    ? 'filesystem_proof_covers_fixed_locations_at_one_moment'
                    : 'api_absence_only_no_filesystem_or_home_directory_cleanup_claim',
            ],
        ];
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Fixture state contains credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Domain rows fixture state cannot be serialized.');
    }
}
