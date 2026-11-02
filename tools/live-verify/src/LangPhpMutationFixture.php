<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\LangPhp;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Prove the three LangPHP setters on one freshly created disposable account, once, then remove the account.
 *
 * DisposableAccountLifecycle owns identity, ownership, the short-lived writable token, account cleanup and the run's
 * status, and states their rules. This fixture holds the LangPHP phases: it reads the original state through both
 * routes, then changes basic directives, INI content and the vhost PHP version through the generated client on direct
 * and WHM-bridged UAPI, reading each change back through its matching getter on the same route.
 *
 * Preflight must finish, and both routes must agree, before any mutation. The three groups that follow touch separate
 * state or replace it whole, so a group stops at its first unexpected state while the later groups still run: no
 * mutation in a group follows an unexpected outcome in that group, and nothing is ever retried. Two content writes send
 * the pinned enum's `host` type, which the reviewed handler refuses before writing; their expected outcome is an
 * explicit API refusal, and the readback that follows must show the content unchanged. Everything the run changed lives
 * inside the account, so account removal is its only cleanup.
 *
 * Readback through the API proves what the API stores and reports. It proves nothing about PHP's runtime behavior.
 *
 * In the report, capture says whether every planned observation finished, and expectations whether they held, as in
 * the Fileman upload fixture. `stopped_at` names, per group, the phase whose outcome stopped it.
 *
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Route from LangPhpMutationRecipe
 * @phpstan-import-type ContentLabel from LangPhpMutationRecipe
 * @phpstan-import-type ContentClass from LangPhpMutationRecipe
 * @phpstan-import-type Assessment from LangPhpMutationEvidence
 * @phpstan-import-type CleanupOutcome from DisposableAccountLifecycle
 * @phpstan-import-type ResourceOutcome from DisposableAccountLifecycle
 * @phpstan-import-type TokenOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemControl from DisposableAccountLifecycle
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
 * @phpstan-type Shape 'object'|'list'|'other'
 * @phpstan-type OriginalState array{
 *     installed_versions: int, installed_routes_agree: bool, version_alternatives: int,
 *     vhost_rows: array<string, int>, vhost_row_found: bool, vhosts_root: array<string, Shape>,
 *     home_content: array<string, 'empty'|'nonempty'|'absent'>, content_routes_agree: bool,
 *     directives_found: bool, directive_routes_agree: bool, directive_targets_distinct: bool
 * }
 * @phpstan-type DirectiveChange array{encoding: 'scalar'|'numbered', fields: int, readback_exact: bool|null}
 * @phpstan-type ContentRoundTrip array{
 *     class: ContentClass, server_comment_lines: int, server_blank_lines: int,
 *     directive_crosscheck: bool|null, earlier_directives_replaced: bool|null
 * }
 * @phpstan-type HostAttempt array{
 *     outcome: 'refused'|'accepted'|'unknown', envelope_errors: int, content_unchanged: bool|null
 * }
 * @phpstan-type VersionChange array{
 *     result_vhosts_exact: bool, readback_root: Shape|null, readback_matches: bool|null
 * }
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started', capture: 'passed'|'failed'|'not_run',
 *     expectations: 'passed'|'failed'|'not_run', cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     stopped_at: array{preflight: string|null, directives: string|null, content: string|null, versions: string|null},
 *     resources: array{account: ResourceOutcome, token: TokenOutcome},
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>,
 *     filesystem_control: FilesystemControl
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'langphp-mutations-disposable-v1', target_role: 'disposable-test-account',
 *     probes: list<Observation>, original_state: OriginalState,
 *     directive_changes: array<string, DirectiveChange>, content_round_trips: array<string, ContentRoundTrip>,
 *     content_type_host: array<string, HostAttempt>, version_changes: array<string, VersionChange>,
 *     discrepancy_review: Assessment, fixture: Report, limitations: list<string>
 * }
 */
final class LangPhpMutationFixture
{
    private const HOST = 'test.oblak.host';

    /** Long enough for this run and short enough that a lost token dies quickly; cleanup still revokes it. */
    private const TOKEN_LIFETIME = 900;

    /** @var array<Route, string> */
    private const ROUTES = ['direct-uapi' => 'direct', 'bridged-uapi' => 'bridged'];

    private readonly LangPhpMutationRecipe $recipe;

    private readonly LangPhpMutationGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    private readonly Client $root;

    private readonly DisposableAccountLifecycle $lifecycle;

    /** @var Report */
    private array $report;

    private ?Client $direct = null;

    /** @var list<Observation> */
    private array $records = [];

    /** @var OriginalState */
    private array $original;

    /** @var array<string, DirectiveChange> */
    private array $directiveChanges = [];

    /** @var array<string, ContentRoundTrip> */
    private array $contentRoundTrips = [];

    /** @var array<string, HostAttempt> */
    private array $hostAttempts = [];

    /** @var array<string, VersionChange> */
    private array $versionChanges = [];

    /** @var list<string> Installed packages, in memory only. */
    private array $installed = [];

    /** The vhost's original version, in memory only. */
    private ?string $vhostVersion = null;

    /** @var array<string, string|null> Original directive values, in memory only. */
    private array $directiveValues = [];

    /** The content the last confirmed home write left, as the getter reported it; in memory only. */
    private ?string $lastContent = null;

    private bool $expectationFailed = false;

    private bool $observationFailed = false;

    private ?string $stopPhase = null;

    /** @var array{preflight: string|null, directives: string|null, content: string|null, versions: string|null} */
    private array $stoppedAt = ['preflight' => null, 'directives' => null, 'content' => null, 'versions' => null];

    /**
     * Bind the fixed disposable account and a token expiration, without I/O.
     *
     * The client must support form POST, verify TLS and refuse redirects and retries. The guard re-checks both live
     * opt-ins and the exact account and domain on every request. Live callers obtain committed context through
     * RepositoryContext first. Without a filesystem proof, nothing is claimed about the server's files.
     *
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] string $whmToken,
        private readonly array $context,
        ?FilesystemAbsenceProof $filesystem = null,
    ) {
        $this->recipe = new LangPhpMutationRecipe(bin2hex(random_bytes(16)), DisposableAccount::fresh(
            LangPhpMutationRecipe::ACCOUNT,
            LangPhpMutationRecipe::DOMAIN,
            LangPhpMutationRecipe::TOKEN_NAME,
            self::TOKEN_LIFETIME,
            readOnlyToken: false,
        ));
        $this->guard = new LangPhpMutationGuard($http, $whmToken, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context, LangPhpMutationRecipe::ACCOUNT);
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
        $this->original = [
            'installed_versions' => 0, 'installed_routes_agree' => false, 'version_alternatives' => 0,
            'vhost_rows' => [], 'vhost_row_found' => false, 'vhosts_root' => [],
            'home_content' => [], 'content_routes_agree' => false,
            'directives_found' => false, 'directive_routes_agree' => false, 'directive_targets_distinct' => false,
        ];
    }

    /**
     * Set up, capture, clean up, and return sanitized evidence including every failure.
     *
     * This instance is single-use. Exception messages, raw payloads, returned credentials, INI content, versions,
     * names and paths never enter the result; fixture success and the strict discrepancy assessment stay separate.
     *
     * @return Scenario
     */
    public function run(): array
    {
        $this->report = self::report();
        try {
            $outcome = $this->lifecycle->run(function (#[SensitiveParameter] string $token): void {
                $this->capture($token);
            });
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
     * Bind the token, run every phase, and tell the lifecycle what failed.
     *
     * @throws ExerciseFailure After recording, for a failed observation or any failed expectation.
     */
    private function capture(#[SensitiveParameter] string $token): void
    {
        $this->report['capture'] = 'failed';
        try {
            $this->guard->bindToken($token);
            $factory = new Psr17Factory();
            $this->direct = new Client([
                'host' => self::HOST, 'port' => 2083, 'authType' => 'cpanel',
                'username' => LangPhpMutationRecipe::ACCOUNT, 'token' => $token,
            ], new HttpTransport($this->capture, $factory, $factory));
            $this->exercise();
        } catch (Throwable) {
            $this->stoppedAt['preflight'] = $this->stopPhase ?? $this->lastPhase();
        }
        $complete = array_filter($this->stoppedAt, static fn (?string $phase): bool => $phase !== null) === []
            && array_column($this->records, 'phase') === array_keys(LangPhpMutationEvidence::PLAN);
        if ($complete) {
            $this->report['capture'] = 'passed';
        }
        $failures = [];
        if ($this->observationFailed || (!$complete && !$this->expectationFailed)) {
            $failures[] = 'capture_failure';
        }
        if ($this->expectationFailed) {
            $this->report['expectations'] = 'failed';
            $failures[] = 'expectation_failure';
        } elseif ($complete) {
            $this->report['expectations'] = 'passed';
        }
        if ($failures !== []) {
            throw new ExerciseFailure($failures);
        }
    }

    /** Preflight stops everything; each later group stops only itself. */
    private function exercise(): void
    {
        $this->succeed('version', static fn (#[SensitiveParameter] Client $client): mixed =>
            $client->whm()->server()->version());
        $this->preflight();
        $this->group('directives', fn () => $this->directives());
        $this->group('content', fn () => $this->content());
        $this->group('versions', fn () => $this->versions());
    }

    /** @param 'directives'|'content'|'versions' $group */
    private function group(string $group, Closure $steps): void
    {
        try {
            $steps();
        } catch (Throwable) {
            $this->stoppedAt[$group] = $this->stopPhase ?? $this->lastPhase();
            $this->stopPhase = null;
        }
    }

    /** Read the original state through both routes and require them to agree before anything changes. */
    private function preflight(): void
    {
        $installed = [];
        foreach (self::ROUTES as $route => $short) {
            $result = $this->succeed('installed_' . $short, static fn (#[SensitiveParameter] Client $client): mixed =>
                self::lang($client, $route)->phpGetInstalledVersions());
            $installed[$route] = is_array($result) ? $result : null;
        }
        $this->installed = array_values(array_filter(
            $installed['direct-uapi'] ?? [],
            static fn (mixed $version): bool => is_string($version),
        ));
        $this->original['installed_versions'] = count($this->installed);
        $this->original['installed_routes_agree'] = $installed['direct-uapi'] === $installed['bridged-uapi']
            && $this->installed !== [];
        $this->expect($this->original['installed_routes_agree']);

        $versions = [];
        foreach (self::ROUTES as $route => $short) {
            $result = $this->succeed('vhosts_' . $short, static fn (#[SensitiveParameter] Client $client): mixed =>
                self::lang($client, $route)->phpGetVhostVersions());
            $this->original['vhosts_root'][$route] = $this->rootShape();
            $rows = is_array($result) && array_is_list($result) ? $result : [];
            $this->original['vhost_rows'][$route] = count($rows);
            $versions[$route] = self::vhostVersion($rows);
        }
        $this->vhostVersion = $versions['direct-uapi'];
        $this->original['vhost_row_found'] = $this->vhostVersion !== null
            && $versions['bridged-uapi'] === $this->vhostVersion;
        $this->original['version_alternatives'] = count($this->alternatives());
        $this->expect($this->original['vhost_row_found']);

        $values = [];
        foreach (self::ROUTES as $route => $short) {
            $values[$route] = $this->readDirectives('directives_' . $short, $route);
        }
        $this->directiveValues = $values['direct-uapi'];
        $this->original['directives_found'] = !in_array(
            null,
            array_merge(array_values($values['direct-uapi']), array_values($values['bridged-uapi'])),
            true,
        );
        $this->original['directive_routes_agree'] = $values['direct-uapi'] === $values['bridged-uapi'];
        $this->original['directive_targets_distinct'] = $this->original['directives_found']
            && !in_array($this->directiveValues['max_execution_time'], ['37', '41'], true)
            && $this->directiveValues['max_input_time'] !== '43';
        $this->expect($this->original['directives_found'] && $this->original['directive_routes_agree']);

        $contents = [];
        foreach (self::ROUTES as $route => $short) {
            $result = $this->succeed('content_' . $short, static fn (#[SensitiveParameter] Client $client): mixed =>
                self::lang($client, $route)->phpIniGetUserContent(type: 'home'));
            $content = is_array($result) ? ($result['content'] ?? null) : null;
            $contents[$route] = is_string($content) ? $content : null;
            $this->original['home_content'][$route] = match (true) {
                !is_string($content) => 'absent',
                $content === '' => 'empty',
                default => 'nonempty',
            };
        }
        $this->original['content_routes_agree'] = $contents['direct-uapi'] === $contents['bridged-uapi'];
        $this->expect($this->original['content_routes_agree']);
    }

    /** Write a scalar directive directly and a numbered pair through the bridge, reading each back. */
    private function directives(): void
    {
        $this->precondition('directives_bridged', $this->original['directive_targets_distinct']);
        $expected = $this->directiveValues;
        foreach (self::ROUTES as $route => $short) {
            $argument = LangPhpMutationRecipe::directiveArgument($route);
            $this->directiveChanges[$route] = [
                'encoding' => is_array($argument) ? 'numbered' : 'scalar',
                'fields' => count(LangPhpMutationRecipe::DIRECTIVES[$route]), 'readback_exact' => null,
            ];
            $this->mutate('set_directives_' . $short, static fn (#[SensitiveParameter] Client $client): mixed =>
                self::lang($client, $route)->phpIniSetUserBasicDirectives(directives: $argument, type: 'home'));
            $expected = [...$expected, ...LangPhpMutationRecipe::DIRECTIVES[$route]];
            $exact = $this->readDirectives('read_directives_' . $short, $route) === $expected;
            $this->directiveChanges[$route]['readback_exact'] = $exact;
            $this->expect($exact);
        }
    }

    /** Replace the content through each route and read it back, then send the pinned enum's `host` type twice. */
    private function content(): void
    {
        $original = $this->directiveValues['max_input_vars'] ?? null;
        $this->precondition(
            'directives_bridged',
            $original !== null && !in_array($original, LangPhpMutationRecipe::CONTENT_INPUT_VARS, true),
        );
        foreach (self::ROUTES as $route => $short) {
            /** @var ContentLabel $label */
            $label = $short;
            $text = $this->recipe->contentText($label);
            $finding = $this->recipe->classifyContent($label, null);
            $checks = ['directive_crosscheck' => null, 'earlier_directives_replaced' => null];
            $this->contentRoundTrips[$route] = [...$finding, ...$checks];
            $this->mutate('set_content_' . $short, static fn (#[SensitiveParameter] Client $client): mixed =>
                self::lang($client, $route)->phpIniSetUserContent(content: $text, type: 'home'));
            $returned = $this->readContent('read_content_' . $short, $route);
            $finding = $this->recipe->classifyContent($label, $returned);
            $this->contentRoundTrips[$route] = [...$finding, ...$checks];
            $this->expect(!in_array($finding['class'], ['other', 'absent'], true));
            $this->lastContent = $returned;

            $values = $this->readDirectives('read_content_directives_' . $short, $route);
            $crosscheck = $values['max_input_vars'] === LangPhpMutationRecipe::CONTENT_INPUT_VARS[$label];
            $replaced = $this->original['directive_targets_distinct']
                ? !in_array($values['max_execution_time'], ['37', '41'], true) && $values['max_input_time'] !== '43'
                : null;
            $checks = ['directive_crosscheck' => $crosscheck, 'earlier_directives_replaced' => $replaced];
            $this->contentRoundTrips[$route] = [...$finding, ...$checks];
            $this->expect($crosscheck && $replaced !== false);
        }
        foreach (self::ROUTES as $route => $short) {
            $this->host($route, $short);
        }
    }

    /**
     * Send `host` once on a route, then require the home content to read back unchanged.
     *
     * Only an explicit API refusal is a refusal; a lost or unreadable reply leaves the outcome unknown and stops the
     * group. An accepted write is unexpected: the content is still read, so the report shows what it did, and then the
     * group stops at the write.
     *
     * @param Route $route
     */
    private function host(string $route, string $short): void
    {
        /** @var ContentLabel $label */
        $label = 'host-' . $short;
        $phase = 'set_content_host_' . $short;
        $text = $this->recipe->contentText($label);
        $this->hostAttempts[$route] = ['outcome' => 'unknown', 'envelope_errors' => 0, 'content_unchanged' => null];
        $this->guard->authorize($phase);
        try {
            [$record, $errors] = $this->observe($phase, static fn (#[SensitiveParameter] Client $client): mixed =>
                self::lang($client, $route)->phpIniSetUserContent(content: $text, type: 'host'))[1];
        } finally {
            $this->guard->clearPending();
        }
        $accepted = $record['probe_outcome'] === 'passed';
        $refused = $record['failure_kind'] === 'api_failure' && $record['uapi_status'] === 0;
        if (!$accepted && !$refused) {
            $this->observationFailed = true;
            throw new RuntimeException('LangPHP host attempt outcome is unknown.');
        }
        $outcome = $accepted ? 'accepted' : 'refused';
        $this->hostAttempts[$route] = [
            'outcome' => $outcome, 'envelope_errors' => $errors, 'content_unchanged' => null,
        ];
        $this->setExpectation($phase, $accepted ? 'failed' : 'passed');
        if ($accepted) {
            $this->expectationFailed = true;
            $this->stopPhase = $phase;
        }
        $unchanged = $this->readContent('read_content_host_' . $short, $route) === $this->lastContent;
        $this->hostAttempts[$route] = [
            'outcome' => $outcome, 'envelope_errors' => $errors, 'content_unchanged' => $unchanged,
        ];
        if ($accepted) {
            throw new RuntimeException('LangPHP host type was accepted.');
        }
        $this->expect($unchanged);
    }

    /** Move the main domain to two other installed versions in turn, reading each through the vhost selector. */
    private function versions(): void
    {
        $alternatives = $this->alternatives();
        $this->precondition('vhosts_bridged', count($alternatives) >= 2);
        $targets = ['direct-uapi' => $alternatives[0], 'bridged-uapi' => $alternatives[1]];
        foreach (self::ROUTES as $route => $short) {
            $version = $targets[$route];
            $this->versionChanges[$route] = [
                'result_vhosts_exact' => false, 'readback_root' => null, 'readback_matches' => null,
            ];
            $result = $this->mutate(
                'set_version_' . $short,
                static fn (#[SensitiveParameter] Client $client): mixed => self::lang($client, $route)
                    ->phpSetVhostVersions(version: $version, vhosts: LangPhpMutationRecipe::DOMAIN),
                $version,
            );
            $exact = is_array($result) && ($result['vhosts'] ?? null) === [LangPhpMutationRecipe::DOMAIN];
            $this->versionChanges[$route] = [
                'result_vhosts_exact' => $exact, 'readback_root' => null, 'readback_matches' => null,
            ];
            $this->expect($exact);

            $read = $this->succeed('read_version_' . $short, static fn (#[SensitiveParameter] Client $client): mixed =>
                self::lang($client, $route)->phpGetVhostVersions(vhost: LangPhpMutationRecipe::DOMAIN));
            $shape = $this->rootShape();
            $row = match ($shape) {
                'object' => $read,
                'list' => is_array($read) ? self::row($read) : null,
                default => null,
            };
            $matches = is_array($row) && ($row['vhost'] ?? null) === LangPhpMutationRecipe::DOMAIN
                && ($row['version'] ?? null) === $version;
            $this->versionChanges[$route] = [
                'result_vhosts_exact' => $exact, 'readback_root' => $shape, 'readback_matches' => $matches,
            ];
            $this->expect($matches);
        }
    }

    /**
     * Installed packages other than the vhost's original version, in a fixed order; only safe names qualify.
     *
     * @return list<string>
     */
    private function alternatives(): array
    {
        $alternatives = array_values(array_filter(
            $this->installed,
            fn (string $version): bool => $version !== $this->vhostVersion
                && preg_match(LangPhpMutationRecipe::VERSION_PATTERN, $version) === 1,
        ));
        sort($alternatives, SORT_STRING);

        return array_values(array_unique($alternatives));
    }

    /**
     * Read the watched directives; a key that is missing, repeated or not a string reads as null.
     *
     * @param Route $route
     * @return array<string, string|null>
     */
    private function readDirectives(string $phase, string $route): array
    {
        $result = $this->succeed($phase, static fn (#[SensitiveParameter] Client $client): mixed =>
            self::lang($client, $route)->phpIniGetUserBasicDirectives(type: 'home'));
        $rows = is_array($result) && is_array($result['directives'] ?? null) ? $result['directives'] : [];
        $values = [];
        foreach (LangPhpMutationRecipe::DIRECTIVE_KEYS as $key) {
            $matching = array_values(array_filter(
                $rows,
                static fn (mixed $row): bool => is_array($row) && ($row['key'] ?? null) === $key,
            ));
            $value = count($matching) === 1 ? ($matching[0]['value'] ?? null) : null;
            $values[$key] = is_string($value) ? $value : null;
        }

        return $values;
    }

    /** @param Route $route */
    private function readContent(string $phase, string $route): ?string
    {
        $result = $this->succeed($phase, static fn (#[SensitiveParameter] Client $client): mixed =>
            self::lang($client, $route)->phpIniGetUserContent(type: 'home'));
        $content = is_array($result) ? ($result['content'] ?? null) : null;

        return is_string($content) ? $content : null;
    }

    /** @param list<mixed> $rows */
    private static function vhostVersion(array $rows): ?string
    {
        $row = self::row($rows);
        $version = $row['version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * The one row for this recipe's domain, or null.
     *
     * @param array<mixed> $rows
     * @return array<mixed>|null
     */
    private static function row(array $rows): ?array
    {
        $matching = array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row) && ($row['vhost'] ?? null) === LangPhpMutationRecipe::DOMAIN,
        ));

        return count($matching) === 1 ? $matching[0] : null;
    }

    /**
     * The payload root of the latest record, as the same raw response showed it.
     *
     * @return 'object'|'list'|'other'
     */
    private function rootShape(): string
    {
        return match ($this->last()['payload_paths']['$'][0] ?? null) {
            'object', 'empty-object' => 'object',
            'list', 'empty-list' => 'list',
            default => 'other',
        };
    }

    /** @param Route $route */
    private static function lang(Client $client, string $route): LangPhp
    {
        return ($route === 'direct-uapi' ? $client->uapi() : $client->uapi(LangPhpMutationRecipe::ACCOUNT))->langPhp();
    }

    /**
     * Send one authorized mutation once and require a successful observation.
     *
     * @param Closure(Client): mixed $send
     */
    private function mutate(string $phase, #[SensitiveParameter] Closure $send, ?string $version = null): mixed
    {
        $this->guard->authorize($phase, $version);
        try {
            return $this->succeed($phase, $send);
        } finally {
            $this->guard->clearPending();
        }
    }

    /**
     * Capture one call through the shared runner, keeping its result in memory for expectations.
     *
     * The runner clears prior capture and never retries; the record carries only structural evidence.
     *
     * @param Closure(Client): mixed $invoke
     * @return array{0: mixed, 1: array{0: Observation, 1: int}}
     */
    private function observe(string $phase, #[SensitiveParameter] Closure $invoke): array
    {
        [$route, $function, $argument] = LangPhpMutationEvidence::PLAN[$phase];
        $contract = LangPhpMutationEvidence::contract($phase);
        $client = $route === 'direct-uapi'
            ? ($this->direct ?? throw new LogicException('LangPHP direct client unavailable.')) : $this->root;
        $result = null;
        $uri = match (true) {
            $route === 'whm' => 'https://test.oblak.host:2087/json-api/version?api.version=1',
            isset(LangPhpMutationRecipe::MUTATIONS[$phase]) => LangPhpMutationRecipe::mutationUri($function, $route),
            default => LangPhpMutationRecipe::readUri(
                $function,
                $route, match ($argument) {
                'home-scope' => 'type=home',
                'selected-vhost' => 'vhost=' . LangPhpMutationRecipe::DOMAIN,
                default => '',
                },
            ),
        };
        $record = $this->runner->capture(new Probe(
            $contract['operation'],
            $route,
            $contract['spec'],
            $uri,
            static function (#[SensitiveParameter] Client $client) use ($invoke, &$result): mixed {
                return $result = $invoke($client);
            },
            null,
            $contract['variant'],
            $argument,
        ), $this->capture, $client);
        $metadata = $route === 'whm' ? null : ($route === 'direct-uapi'
            ? $client->uapi() : $client->uapi(LangPhpMutationRecipe::ACCOUNT))->responseMeta();
        $observation = [...$record, 'phase' => $phase, 'uapi_status' => $metadata?->uapiStatus(),
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];
        $this->records[] = $observation;

        return [$result, [$observation, $metadata === null ? 0 : count($metadata->uapiErrors())]];
    }

    /** @param Closure(Client): mixed $invoke */
    private function succeed(string $phase, #[SensitiveParameter] Closure $invoke): mixed
    {
        [$result] = $this->observe($phase, $invoke);
        if ($this->last()['probe_outcome'] !== 'passed') {
            $this->observationFailed = true;
            throw new RuntimeException('LangPHP observation failed.');
        }

        return $result;
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $this->setExpectation($this->lastPhase() ?? '', 'failed');
            $this->expectationFailed = true;
            throw new RuntimeException('LangPHP expectation failed.');
        }
    }

    /** Stop a group before its first mutation when the original state cannot show its change; blame that read. */
    private function precondition(string $phase, bool $condition): void
    {
        if (!$condition) {
            $this->setExpectation($phase, 'failed');
            $this->expectationFailed = true;
            $this->stopPhase = $phase;
            throw new RuntimeException('LangPHP group precondition failed.');
        }
    }

    /** @param 'passed'|'failed' $expectation */
    private function setExpectation(string $phase, string $expectation): void
    {
        foreach ($this->records as $index => $record) {
            if ($record['phase'] === $phase) {
                $this->records[$index]['expectation'] = $expectation;
            }
        }
    }

    private function lastPhase(): ?string
    {
        $index = array_key_last($this->records);

        return $index === null ? null : $this->records[$index]['phase'];
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('LangPHP observation unavailable.');
        }

        return $this->records[$index];
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

        return [
            'status' => 'failed', 'setup' => 'not_started', 'capture' => 'not_run',
            'expectations' => 'not_run', 'cleanup' => 'not_required', 'failures' => [],
            'stopped_at' => ['preflight' => null, 'directives' => null, 'content' => null, 'versions' => null],
            'resources' => ['account' => $resource, 'token' => $resource],
            'domain_cleanup' => 'not_required', 'zone_cleanup' => 'not_required',
            'filesystem_cleanup' => 'not_claimed', 'filesystem_residue' => [],
            'filesystem_control' => ['status' => 'not_run', 'counts' => []],
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
        $this->report['stopped_at'] = $this->stoppedAt;

        return [
            'scenario' => 'langphp-mutations-disposable-v1',
            'target_role' => 'disposable-test-account',
            'probes' => $records,
            'original_state' => $this->original,
            'directive_changes' => $this->directiveChanges,
            'content_round_trips' => $this->contentRoundTrips,
            'content_type_host' => $this->hostAttempts,
            'version_changes' => $this->versionChanges,
            'discrepancy_review' => LangPhpMutationEvidence::assess($records, $this->context),
            'fixture' => $this->report,
            'limitations' => [
                'two_routes_on_one_server_version_only',
                'home_ini_scope_only_no_vhost_ini_round_trip',
                'api_readback_only_no_php_runtime_behavior',
                'main_domain_version_change_only_no_fpm_or_impacted_domain_variants',
                'account_removal_is_the_only_cleanup_of_ini_and_version_changes',
                $this->report['filesystem_cleanup'] === 'confirmed_absent'
                    ? 'filesystem_proof_covers_fixed_locations_at_one_moment'
                    : 'api_absence_only_no_filesystem_cleanup_claim',
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
        throw new LogicException('LangPHP mutation fixture state cannot be serialized.');
    }
}
