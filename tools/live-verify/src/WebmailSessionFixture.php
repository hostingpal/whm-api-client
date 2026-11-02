<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use JsonException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Create Webmail session credentials with all three Session creators on both routes, once, on one disposable account.
 *
 * DisposableAccountLifecycle owns identity, ownership, the short-lived writable token, account cleanup and the run's
 * status, and states their rules. This fixture creates one mailbox on its own account, with welcome mail disabled,
 * then calls the self, mail-user and password-checking creators through direct UAPI and the WHM bridge, each with the
 * RFC 5737 documentation address, and reads the active-session inventory on both routes before and after. Returned
 * credentials are never used: nothing logs in, posts to Webmail or fetches a URL, and the guard admits no such
 * request. Only the kind of each returned field and the counts of session rows leave memory.
 *
 * Capture stops only where later phases could not be interpreted: at a failed version read, or at a mailbox that is not
 * proven created as expected. A failed creator is recorded and the others still run, since each is independent and
 * none is retried. In the report, `capture` says whether every planned observation finished and succeeded, and
 * `expectations` whether they held.
 *
 * Removing an account leaves its mailbox's Webmail session files behind. With the proof enabled, the fixture offers the
 * session-file cleanup to the lifecycle as its removed-resource callback, so it runs only when the lifecycle calls it:
 * at most once, after the marker proved the account and the account then read absent, and before the lifecycle's own
 * final filesystem proof, which therefore shows what the cleanup left. The cleanup is a root filesystem write over
 * SSH, bounded to this account's anchored regular files. It also needs the lifecycle's control to have passed and to
 * have seen no session name before the exercise, which ControlRecordingProof remembers, and a readable observation.
 * Its failure labels fail the lifecycle's `cleanup`, so a clean run that removes every file it left passes.
 *
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Assessment from WebmailSessionEvidence
 * @phpstan-import-type ResourceOutcome from DisposableAccountLifecycle
 * @phpstan-import-type TokenOutcome from DisposableAccountLifecycle
 * @phpstan-import-type CleanupOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemControl from DisposableAccountLifecycle
 * @phpstan-import-type Observation from SessionFileCleanup as FileObservation
 * @phpstan-import-type Removal from SessionFileCleanup
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
 * @phpstan-type Kind 'absent'|'null'|'empty_string'|'string'|'other'
 * @phpstan-type Credential array{uapi_status: int|null, hostname: Kind, session: Kind, token: Kind}
 * @phpstan-type SessionRows array{
 *     rows: int, current_rows: int, possessed_rows: int, tfa_verified_rows: int, supplied_address_rows: int,
 *     empty_address_rows: int
 * }
 * @phpstan-type ActiveSessions array{
 *     by_phase: array<string, SessionRows|null>, appeared: array{'direct-uapi': bool|null, 'bridged-uapi': bool|null}
 * }
 * @phpstan-type SessionFiles array{
 *     status: 'not_run'|'not_required'|'removed'|'residue_left'|'unconfirmed', reason: string|null,
 *     observed: FileObservation|null, removal: Removal|null
 * }
 * @phpstan-type MailboxOutcome array{
 *     creation: 'not_attempted'|'confirmed'|'unconfirmed', cleanup: 'not_required'|'absent_with_account'|'unconfirmed'
 * }
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started', capture: 'passed'|'failed'|'not_run',
 *     expectations: 'passed'|'failed'|'not_run', cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     stopped_at: string|null,
 *     resources: array{
 *         account: ResourceOutcome, token: TokenOutcome, mailbox: MailboxOutcome, sessions: array{created: int}
 *     },
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>,
 *     filesystem_control: FilesystemControl
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'webmail-sessions-disposable-v3', target_role: 'disposable-test-account', probes: list<Observation>,
 *     credentials: array<string, Credential>, active_sessions: ActiveSessions, session_files: SessionFiles,
 *     discrepancy_review: Assessment, fixture: Report, limitations: list<string>
 * }
 */
final class WebmailSessionFixture
{
    private const HOST = 'test.oblak.host';

    /** Long enough for this run and short enough that a lost token dies quickly; cleanup still revokes it. */
    private const TOKEN_LIFETIME = 900;

    private readonly WebmailSessionRecipe $recipe;

    private readonly WebmailSessionGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    private readonly Client $root;

    private readonly DisposableAccountLifecycle $lifecycle;

    /** @var Report */
    private array $report;

    /** @var SessionFiles */
    private array $sessionFiles;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<string, Credential> */
    private array $credentials = [];

    /** @var array<string, SessionRows|null> */
    private array $inventories = [];

    private bool $expectationFailed = false;

    private ?string $current = null;

    /** The lifecycle's proof, remembering its control for the session-file cleanup; null without the opt-in. */
    private readonly ?ControlRecordingProof $proof;

    /**
     * Bind the fixed disposable account, a fresh mailbox name and password, and a token expiration, without I/O.
     *
     * The client must support form POST, verify TLS and refuse redirects and retries. Live callers must obtain
     * committed context through RepositoryContext first. Without a filesystem proof, nothing is claimed about the
     * server's files and the session-file cleanup is never offered to the lifecycle.
     *
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] string $whmToken,
        private readonly array $context,
        ?FilesystemAbsenceProof $filesystem = null,
        private readonly ?SessionFileCleanup $sessionFileCleanup = null,
    ) {
        $this->recipe = new WebmailSessionRecipe(
            bin2hex(random_bytes(16)),
            'Ws!9' . bin2hex(random_bytes(20)),
            DisposableAccount::fresh(
                WebmailSessionRecipe::ACCOUNT,
                WebmailSessionRecipe::DOMAIN,
                WebmailSessionRecipe::TOKEN_NAME,
                self::TOKEN_LIFETIME,
                readOnlyToken: false,
            ),
        );
        $this->guard = new WebmailSessionGuard($http, $whmToken, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context, WebmailSessionRecipe::ACCOUNT);
        $factory = new Psr17Factory();
        $this->root = new Client([
            'host' => self::HOST, 'port' => 2087, 'authType' => 'whm', 'username' => 'root', 'token' => $whmToken,
        ], new HttpTransport($this->capture, $factory, $factory));
        $this->proof = $filesystem === null ? null : new ControlRecordingProof($filesystem);
        $this->lifecycle = new DisposableAccountLifecycle(
            $this->recipe->account,
            $this->capture,
            $this->root,
            $this->proof,
        );
        $this->report = self::report();
        $this->sessionFiles = self::sessionFiles('not_run', 'run_not_started');
    }

    /**
     * Set up, capture, clean up, and return the evidence; the lifecycle calls the session-file cleanup, if offered.
     *
     * This instance is single-use. Exception messages, raw payloads, returned credentials, the mailbox name, its
     * password and addresses never enter the result; fixture success and the strict discrepancy assessment stay
     * separate.
     *
     * @return Scenario
     */
    public function run(): array
    {
        $offered = $this->proof !== null && $this->sessionFileCleanup !== null;
        $this->sessionFiles = self::sessionFiles('not_run', $offered ? 'not_called' : 'filesystem_proof_not_enabled');
        try {
            $outcome = $this->lifecycle->run(
                function (#[SensitiveParameter] string $token): void {
                    $this->capture($token);
                },
                null,
                $offered ? fn (): array => $this->removeSessionFiles() : null,
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
        if ($this->report['resources']['mailbox']['creation'] !== 'not_attempted') {
            $this->report['resources']['mailbox']['cleanup'] = $outcome['account']['cleanup'] === 'confirmed_absent'
                ? 'absent_with_account' : 'unconfirmed';
        }

        return $this->scenario();
    }

    /**
     * Bind the token and run every phase, then tell the lifecycle what failed.
     *
     * @throws ExerciseFailure After recording, for a failed capture or any failed expectation, even in a finished one.
     */
    private function capture(#[SensitiveParameter] string $token): void
    {
        $finished = false;
        try {
            $this->guard->bindToken($token);
            $factory = new Psr17Factory();
            $this->exercise(new Client([
                'host' => self::HOST, 'port' => 2083, 'authType' => 'cpanel',
                'username' => WebmailSessionRecipe::ACCOUNT, 'token' => $token,
            ], new HttpTransport($this->capture, $factory, $factory)));
            $finished = true;
        } catch (Throwable) {
            $this->report['stopped_at'] = $this->current;
        }
        $failures = [];
        $this->report['capture'] = $finished && !$this->observationFailed() ? 'passed' : 'failed';
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

    private function exercise(Client $direct): void
    {
        $this->observe('version', $this->root);
        $this->passed();
        $this->inventory('sessions_initial_direct', $direct);
        $this->inventory('sessions_initial_bridged', $this->root);
        $this->report['resources']['mailbox']['creation'] = 'unconfirmed';
        $created = $this->observe('create_mailbox', $direct);
        if ($this->last()['api_outcome'] === 'success') {
            $this->report['resources']['mailbox']['creation'] = 'confirmed';
        }
        $this->passed();
        $this->expect($created === $this->recipe->mailbox . '+' . WebmailSessionRecipe::DOMAIN);
        unset($created);
        foreach (['direct' => $direct, 'bridged' => $this->root] as $route => $client) {
            foreach (array_keys(WebmailSessionRecipe::CREATORS) as $creator) {
                $this->credential($creator . '_' . $route, $client);
            }
        }
        $this->inventory('sessions_final_direct', $direct);
        $this->inventory('sessions_final_bridged', $this->root);
    }

    /**
     * Create one session credential and keep only the kind of each returned field; the values are never kept or used.
     *
     * A failure is recorded and capture continues, as every creator is independent. A credential whose session or
     * token is not a nonempty string fails its expectation.
     */
    private function credential(string $phase, Client $client): void
    {
        $this->observe($phase, $client);
        $record = $this->last();
        $data = $this->rawData($record['route']);
        $this->credentials[$phase] = [
            'uapi_status' => $record['uapi_status'],
            'hostname' => self::kind($data, 'hostname'),
            'session' => self::kind($data, 'session'),
            'token' => self::kind($data, 'token'),
        ];
        unset($data);
        if ($record['api_outcome'] === 'success') {
            ++$this->report['resources']['sessions']['created'];
        }
        if (
            $record['probe_outcome'] === 'passed'
            && ($this->credentials[$phase]['session'] !== 'string' || $this->credentials[$phase]['token'] !== 'string')
        ) {
            $this->markFailed();
        }
    }

    /** Read the session inventory and keep only row counts; an unsuccessful read leaves its summary null. */
    private function inventory(string $phase, Client $client): void
    {
        $this->observe($phase, $client);
        $record = $this->last();
        $this->inventories[$phase] = $record['api_outcome'] === 'success'
            ? self::rows($this->rawData($record['route'])) : null;
    }

    /**
     * Count session rows by fixed criteria; no address, theme, time or other value leaves this method.
     *
     * @return SessionRows
     */
    private static function rows(#[SensitiveParameter] mixed $data): array
    {
        $rows = is_array($data) ? array_values(array_filter($data, static fn (mixed $row): bool =>
            $row instanceof stdClass)) : [];
        $count = static fn (Closure $test): int => count(array_filter($rows, $test));

        return [
            'rows' => is_array($data) ? count($data) : 0,
            'current_rows' => $count(static fn (stdClass $row): bool => ($row->is_current ?? null) === 1),
            'possessed_rows' => $count(static fn (stdClass $row): bool => ($row->is_possessed ?? null) === 1),
            'tfa_verified_rows' => $count(static fn (stdClass $row): bool => ($row->tfa_verified ?? null) === 1),
            'supplied_address_rows' => $count(static fn (stdClass $row): bool =>
                ($row->ip_address ?? null) === WebmailSessionRecipe::REMOTE_ADDRESS),
            'empty_address_rows' => $count(static fn (stdClass $row): bool => ($row->ip_address ?? null) === ''),
        ];
    }

    /** @return Kind */
    private static function kind(#[SensitiveParameter] mixed $data, string $field): string
    {
        if (!$data instanceof stdClass || !property_exists($data, $field)) {
            return 'absent';
        }
        $value = $data->{$field};

        return match (true) {
            $value === null => 'null',
            $value === '' => 'empty_string',
            is_string($value) => 'string',
            default => 'other',
        };
    }

    /**
     * The original response's UAPI data, with JSON object and list distinctions kept; null when unreadable or absent.
     *
     * Reading the captured bytes rather than the typed result lets a payload the client refused still be classified.
     */
    private function rawData(string $route): mixed
    {
        $raw = $this->capture->rawBody();
        if ($raw === null) {
            return null;
        }
        try {
            $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!$document instanceof stdClass) {
            return null;
        }
        $envelope = $route === 'direct-uapi'
            ? (property_exists($document, 'result') ? $document->result : $document)
            : (($document->data ?? null) instanceof stdClass ? ($document->data->uapi ?? null) : null);

        return $envelope instanceof stdClass ? ($envelope->data ?? null) : null;
    }

    /**
     * The lifecycle's removed-resource callback: observe and remove this account's session files once.
     *
     * The lifecycle calls it at most once, only after the marker proved the account this run's and the account then
     * read absent, and before its own final filesystem proof. It still refuses unless the control passed and saw no
     * session name before the exercise, as such a name could predate this run. Only anchored regular files are
     * removed; every other anchored entry and unexplained name stays and is reported. Nothing here repeats, and an
     * exception from the cleanup reads as an unreadable answer.
     *
     * @return list<string> Failure labels, which fail the lifecycle's `cleanup`.
     */
    private function removeSessionFiles(): array
    {
        $cleanup = $this->sessionFileCleanup;
        $proof = $this->proof;
        if ($cleanup === null || $proof === null) {
            return [];
        }
        $reason = match (true) {
            !$proof->controlPassed() => 'filesystem_control_not_passed',
            $proof->controlSawSessions() => 'session_names_before_exercise',
            default => null,
        };
        if ($reason !== null) {
            $this->sessionFiles = self::sessionFiles('not_run', $reason);

            return ['session_file_removal_refused'];
        }
        $user = WebmailSessionRecipe::ACCOUNT;
        $domain = WebmailSessionRecipe::DOMAIN;
        try {
            $observed = $cleanup->observe($user, $domain, $this->recipe->mailbox);
        } catch (Throwable) {
            $observed = null;
        }
        if ($observed === null) {
            $this->sessionFiles = self::sessionFiles('unconfirmed', 'observation_unreadable');

            return ['session_files_unconfirmed'];
        }
        if (self::anchoredFiles($observed) === 0) {
            $clean = $observed['wide'] === 0 && self::otherNames($observed) === 0;
            $this->sessionFiles = $clean ? self::sessionFiles('not_required', null, $observed)
                : self::sessionFiles('residue_left', 'no_anchored_regular_files', $observed);

            return $clean ? [] : ['session_files_remain'];
        }
        try {
            $removal = $cleanup->remove($user, $domain, $this->recipe->mailbox);
        } catch (Throwable) {
            $removal = null;
        }
        [$status, $reason, $labels] = match (true) {
            $removal === null => ['unconfirmed', 'removal_unreadable', ['session_files_unconfirmed']],
            self::anchoredFiles($removal['after']) !== 0
                || $removal['deleted'] !== self::anchoredFiles($removal['before'])
                => ['residue_left', 'anchored_files_remain', ['session_files_remain']],
            $removal['after']['wide'] !== 0 || self::otherNames($removal['after']) !== 0
                => ['residue_left', 'other_names_remain', ['session_files_remain']],
            default => ['removed', null, []],
        };
        $this->sessionFiles = self::sessionFiles($status, $reason, $observed, $removal);

        return $labels;
    }

    /**
     * The anchored regular files: the only entries the removal deletes.
     *
     * @param FileObservation $counts
     */
    private static function anchoredFiles(array $counts): int
    {
        return $counts['own'] + $counts['mailbox_encoded'] + $counts['mailbox_literal'];
    }

    /**
     * Anchored entries that are not regular files, and unexplained names: reported, never deleted.
     *
     * @param FileObservation $counts
     */
    private static function otherNames(array $counts): int
    {
        return $counts['anchored_other'] + $counts['unexplained'];
    }

    /**
     * @param 'not_run'|'not_required'|'removed'|'residue_left'|'unconfirmed' $status
     * @param FileObservation|null $observed
     * @param Removal|null $removal
     * @return SessionFiles
     */
    private static function sessionFiles(
        string $status,
        ?string $reason,
        ?array $observed = null,
        ?array $removal = null,
    ): array {
        return ['status' => $status, 'reason' => $reason, 'observed' => $observed, 'removal' => $removal];
    }

    /**
     * The one typed call of a phase; argument values stay inside the closure and never reach the record.
     *
     * @return Closure(Client): mixed
     */
    private function invoker(string $phase): Closure
    {
        $recipe = $this->recipe;
        $account = WebmailSessionRecipe::route($phase) === 'bridged-uapi' ? WebmailSessionRecipe::ACCOUNT : null;
        $creator = WebmailSessionRecipe::creator($phase);
        $domain = WebmailSessionRecipe::DOMAIN;
        $address = WebmailSessionRecipe::REMOTE_ADDRESS;

        return match (true) {
            $phase === 'version' => static fn (Client $client): mixed => $client->whm()->server()->version(),
            str_starts_with($phase, 'sessions_') => static fn (Client $client): mixed =>
                $client->uapi($account)->activeSessions()->listActiveSessions(),
            $phase === 'create_mailbox' => static fn (Client $client): mixed => $client->uapi()->email()->addPop(
                email: $recipe->mailbox,
                password: $recipe->password(),
                domain: $domain,
                quota: WebmailSessionRecipe::MAILBOX_QUOTA,
                sendWelcomeEmail: false,
            ),
            $creator === 'self' => static fn (Client $client): mixed =>
                $client->uapi($account)->session()->createWebmailSessionForSelf(remoteAddress: $address),
            $creator === 'mail_user' => static fn (Client $client): mixed =>
                $client->uapi($account)->session()->createWebmailSessionForMailUser(
                    domain: $domain,
                    login: $recipe->mailbox,
                    remoteAddress: $address,
                ),
            $creator === 'check_password' => static fn (Client $client): mixed =>
                $client->uapi($account)->session()->createWebmailSessionForMailUserCheckPassword(
                    domain: $domain,
                    login: $recipe->mailbox,
                    password: $recipe->password(),
                    remoteAddress: $address,
                ),
            default => throw new LogicException('Unknown Webmail session phase.'),
        };
    }

    /**
     * Capture one call through the shared runner, returning its typed result for expectations only.
     *
     * The phase's one probe keeps the result in memory; the runner clears prior capture and never retries, and the
     * record carries only structural evidence.
     */
    private function observe(string $phase, Client $client): mixed
    {
        $this->current = $phase;
        $contract = WebmailSessionEvidence::contract($phase);
        $route = WebmailSessionRecipe::route($phase);
        $creator = WebmailSessionRecipe::creator($phase);
        $invoke = $this->invoker($phase);
        $result = null;
        $record = $this->runner->capture(new Probe(
            $contract['operation'],
            $route,
            $contract['spec'],
            $this->recipe->request($phase)['uri'],
            static function (#[SensitiveParameter] Client $client) use ($invoke, &$result): mixed {
                return $result = $invoke($client);
            },
            null,
            $contract['variant'],
            match (true) {
                $creator !== null => 'documentation-address',
                $phase === 'create_mailbox' => 'welcome-mail-disabled',
                default => null,
            },
        ), $this->capture, $client);
        $metadata = match ($route) {
            'direct-uapi' => $client->uapi()->responseMeta(),
            'bridged-uapi' => $client->uapi(WebmailSessionRecipe::ACCOUNT)->responseMeta(),
            default => null,
        };
        $this->records[] = [
            'phase' => $phase,
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked',
            'uapi_status' => $metadata?->uapiStatus(),
            ...$record,
        ];

        return $result;
    }

    private function passed(): void
    {
        if ($this->last()['probe_outcome'] !== 'passed') {
            throw new RuntimeException('Webmail session observation failed.');
        }
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $this->markFailed();
            throw new RuntimeException('Webmail session expectation failed.');
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

    private function observationFailed(): bool
    {
        return in_array('failed', array_column($this->records, 'probe_outcome'), true);
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('Webmail session observation unavailable.');
        }

        return $this->records[$index];
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

        return [
            'status' => 'failed', 'setup' => 'not_started', 'capture' => 'not_run', 'expectations' => 'not_run',
            'cleanup' => 'not_required', 'failures' => [], 'stopped_at' => null,
            'resources' => ['account' => $resource, 'token' => $resource, 'mailbox' => $resource,
                'sessions' => ['created' => 0]],
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
        $appeared = [];
        foreach (['direct' => 'direct-uapi', 'bridged' => 'bridged-uapi'] as $suffix => $route) {
            $initial = $this->inventories['sessions_initial_' . $suffix] ?? null;
            $final = $this->inventories['sessions_final_' . $suffix] ?? null;
            $appeared[$route] = $initial === null || $final === null ? null : $final['rows'] > $initial['rows'];
        }
        $finalRows = [];
        foreach (['sessions_final_direct', 'sessions_final_bridged'] as $phase) {
            $summary = $this->inventories[$phase] ?? null;
            $finalRows[] = $summary === null ? null : $summary['rows'];
        }
        return [
            'scenario' => 'webmail-sessions-disposable-v3',
            'target_role' => 'disposable-test-account',
            'probes' => $records,
            'credentials' => $this->credentials,
            'active_sessions' => [
                'by_phase' => $this->inventories,
                'appeared' => ['direct-uapi' => $appeared['direct-uapi'], 'bridged-uapi' => $appeared['bridged-uapi']],
            ],
            'session_files' => $this->sessionFiles,
            'discrepancy_review' => WebmailSessionEvidence::assess($records, $this->context),
            'fixture' => $this->report,
            'limitations' => array_values(array_filter([
                'one_disposable_account_on_one_server_version',
                'returned_credentials_never_used_no_login_session_activity_or_webmail_post',
                'explicit_documentation_address_on_every_creator_omission_not_observed',
                'locale_omitted_account_default_only',
                'no_wrong_password_suspended_mailbox_or_role_restriction_case',
                in_array(0, $finalRows, true) || in_array(null, $finalRows, true)
                    ? 'empty_session_inventory_cannot_separate_no_sessions_from_unreadable_store' : null,
                $this->report['filesystem_cleanup'] === 'confirmed_absent'
                    ? 'filesystem_proof_covers_fixed_locations_at_one_moment'
                    : 'api_absence_only_no_filesystem_or_session_file_claim',
                $this->sessionFiles['removal'] !== null
                    ? 'session_file_removal_is_a_bounded_root_filesystem_write_before_the_final_proof' : null,
            ])),
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
        throw new LogicException('Webmail session fixture state cannot be serialized.');
    }
}
