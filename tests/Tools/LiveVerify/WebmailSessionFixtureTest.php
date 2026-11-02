<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use HostingPal\WhmApiClient\Tools\LiveVerify\SessionFileCleanup;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @phpstan-import-type Scenario from WebmailSessionFixture
 * @phpstan-import-type Observation from WebmailSessionFixture
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class WebmailSessionFixtureTest extends TestCase
{
    private const SELF = 'create_webmail_session_for_self';

    private const MAIL_USER = 'create_webmail_session_for_mail_user';

    private const CHECK = 'create_webmail_session_for_mail_user_check_password';

    private const CREATOR_PHASES = ['self_direct', 'mail_user_direct', 'check_password_direct', 'self_bridged',
        'mail_user_bridged', 'check_password_bridged'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlsession',
                'WHM_API_LIVE_DOMAIN' => 'wlsession.example.com'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testCompleteRunCreatesEverySessionOnBothRoutesAndKeepsOnlyStructure(): void
    {
        $http = new WebmailSessionServer();
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['status'], (string) json_encode($fixture['failures']));
        self::assertSame('webmail-sessions-disposable-v1', $scenario['scenario']);
        self::assertSame(
            ['policy' => 'webmail-sessions-no-acknowledgments-v1', 'status' => 'passed', 'findings' => []],
            $scenario['discrepancy_review'],
        );
        self::assertSame([], $fixture['failures']);
        self::assertSame('passed', $fixture['capture']);
        self::assertSame('passed', $fixture['expectations']);
        self::assertNull($fixture['stopped_at']);
        self::assertSame(WebmailSessionRecipe::PHASES, array_column($scenario['probes'], 'phase'));
        self::assertSame([
            'createacct', 'Tokens/create_full_access', 'direct:Email/add_pop',
            'direct:Session/' . self::SELF, 'direct:Session/' . self::MAIL_USER, 'direct:Session/' . self::CHECK,
            'Session/' . self::SELF, 'Session/' . self::MAIL_USER, 'Session/' . self::CHECK,
            'Tokens/revoke', 'removeacct',
        ], $http->mutations);
        self::assertCount(6, $http->sessions);
        foreach (self::CREATOR_PHASES as $phase) {
            self::assertSame(
                ['uapi_status' => 1, 'hostname' => 'null', 'session' => 'string', 'token' => 'string'],
                $scenario['credentials'][$phase],
                $phase,
            );
        }
        self::assertSame(self::CREATOR_PHASES, array_keys($scenario['credentials']));
        $empty = ['rows' => 0, 'current_rows' => 0, 'possessed_rows' => 0, 'tfa_verified_rows' => 0,
            'supplied_address_rows' => 0, 'empty_address_rows' => 0];
        $populated = ['rows' => 6, 'current_rows' => 0, 'possessed_rows' => 0, 'tfa_verified_rows' => 0,
            'supplied_address_rows' => 6, 'empty_address_rows' => 0];
        self::assertSame([
            'by_phase' => [
                'sessions_initial_direct' => $empty, 'sessions_initial_bridged' => $empty,
                'sessions_final_direct' => $populated, 'sessions_final_bridged' => $populated,
            ],
            'appeared' => ['direct-uapi' => true, 'bridged-uapi' => true],
        ], $scenario['active_sessions']);
        self::assertSame(
            ['creation' => 'confirmed', 'cleanup' => 'absent_with_account'],
            $fixture['resources']['mailbox'],
        );
        self::assertSame(['created' => 6], $fixture['resources']['sessions']);
        foreach (['account', 'token'] as $resource) {
            $outcome = $fixture['resources'][$resource];
            self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $outcome);
        }
        self::assertSame('not_run', $scenario['session_files']['status']);
        self::assertSame('filesystem_proof_not_enabled', $scenario['session_files']['reason']);
        self::assertContains('api_absence_only_no_filesystem_or_session_file_claim', $scenario['limitations']);
        self::assertPrivate($scenario, [self::sentMailbox($http->requests), self::sentPassword($http->requests)]);
    }

    /** Every field a clean lifecycle reports reaches this report unchanged, so a new one cannot be left out. */
    public function testReportCarriesEveryLifecycleField(): void
    {
        $fixture = self::scenario(new WebmailSessionServer())['fixture'];
        foreach (CleanLifecycleRun::reportFields() as $field => $value) {
            if ($field === 'resources') {
                foreach ($value as $resource => $outcome) {
                    self::assertSame($outcome, $fixture['resources'][$resource], $resource);
                }
                continue;
            }
            self::assertSame($value, $fixture[$field], $field);
        }
    }

    /** The fixture, its guard, recipe, lifecycle and scenario never keep the marker, the password or a credential. */
    public function testNoPublicObjectHoldsTheMarkerThePasswordOrAReturnedCredential(): void
    {
        $http = new WebmailSessionServer();
        $fixture = new WebmailSessionFixture($http, 'WHMTOKEN', self::context());
        $scenario = $fixture->run();
        $secrets = [self::sentMarker($http->requests), self::sentPassword($http->requests), 'PRIVATEWEBMAILSESSION',
            '/cpsessPRIVATE'];
        foreach ($secrets as $secret) {
            foreach ([$secret, rawurlencode($secret)] as $form) {
                MarkerExposure::assertNeverExposed($form, $scenario);
                MarkerExposure::assertNeverExposed($form, $fixture, $http);
            }
        }
        // The account token lives in the guard and clients that use it, but never reaches the report.
        MarkerExposure::assertNeverExposed(WebmailSessionServer::TOKEN, $scenario);
    }

    /** Only the two API ports are reached; no request carries a returned session or token anywhere. */
    public function testReturnedCredentialsAreNeverUsedOrFetched(): void
    {
        $http = new WebmailSessionServer();
        $http->hostname = 'private-webmail.example.net';
        self::scenario($http);
        foreach ($http->requests as $request) {
            $uri = (string) $request->getUri();
            self::assertMatchesRegularExpression(
                '#\Ahttps://test\.oblak\.host:(?:2083/execute/|2087/json-api/)#',
                $uri,
            );
            foreach (['PRIVATEWEBMAILSESSION', 'cpsess', 'private-webmail'] as $returned) {
                self::assertStringNotContainsString($returned, $uri);
                self::assertStringNotContainsString($returned, (string) $request->getBody());
                self::assertStringNotContainsString($returned, implode("\n", array_merge(...array_values(
                    $request->getHeaders(),
                ))));
            }
        }
    }

    public function testEveryCreatorCarriesTheDocumentationAddressAndTheOwnedMailbox(): void
    {
        $http = new WebmailSessionServer();
        self::scenario($http);
        $mailbox = array_key_first($http->mailboxes) ?? self::sentMailbox($http->requests);
        foreach ($http->requests as $request) {
            if (!str_contains((string) $request->getUri(), 'create_webmail_session_for_')) {
                continue;
            }
            parse_str((string) $request->getBody(), $fields);
            self::assertSame('192.0.2.10', $fields['remote_address'] ?? null);
            if (!str_contains((string) $request->getUri(), self::SELF)) {
                self::assertSame($mailbox, $fields['login'] ?? null);
                self::assertSame('wlsession.example.com', $fields['domain'] ?? null);
            }
        }
    }

    public function testTheMailboxIsCreatedWithWelcomeMailDisabledAndAFreshName(): void
    {
        $http = new WebmailSessionServer();
        self::scenario($http);
        $mailbox = self::sentMailbox($http->requests);
        self::assertMatchesRegularExpression('/\Abox[0-9a-f]{16}\z/', $mailbox);
        self::assertSame(1, $http->counts['direct:Email/add_pop']);
    }

    #[DataProvider('hostnames')]
    public function testHostnameIsRecordedAsAKindNeverAValue(mixed $hostname, string $kind): void
    {
        $http = new WebmailSessionServer();
        $http->hostname = $hostname;
        $scenario = self::scenario($http);
        foreach (self::CREATOR_PHASES as $phase) {
            self::assertSame($kind, $scenario['credentials'][$phase]['hostname']);
        }
        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertStringNotContainsString('private-webmail', (string) json_encode($scenario));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function hostnames(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'string' => ['private-webmail.example.net', 'string'];
        yield 'absent' => [WebmailSessionServer::ABSENT, 'absent'];
    }

    public function testAFailedBridgedCreatorIsRecordedAndTheOthersStillRun(): void
    {
        $http = new WebmailSessionServer();
        $http->failures['Session/' . self::SELF . ':1'] = 'api_before';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame('failed', $fixture['capture']);
        self::assertNull($fixture['stopped_at']);
        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertSame(WebmailSessionRecipe::PHASES, array_column($scenario['probes'], 'phase'));
        self::assertSame(1, $http->counts['Session/' . self::MAIL_USER]);
        self::assertSame(1, $http->counts['Session/' . self::CHECK]);
        self::assertSame(1, $http->counts['Session/' . self::SELF]);
        self::assertSame(
            ['uapi_status' => 0, 'hostname' => 'absent', 'session' => 'absent', 'token' => 'absent'],
            $scenario['credentials']['self_bridged'],
        );
        self::assertSame(['created' => 5], $fixture['resources']['sessions']);
        $record = self::observation($scenario, 'self_bridged');
        self::assertSame('api_failure', $record['failure_kind']);
        self::assertSame(
            ['unsuccessful_phase:self_bridged', 'unexpected_uapi_status:self_bridged'],
            $scenario['discrepancy_review']['findings'],
        );
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
    }

    public function testALostCreatorResponseIsNeverRetried(): void
    {
        $http = new WebmailSessionServer();
        $http->failures['direct:Session/' . self::CHECK . ':1'] = 'throw_after';
        $scenario = self::scenario($http);
        self::assertSame(1, $http->counts['direct:Session/' . self::CHECK]);
        self::assertSame('request_failure', self::observation($scenario, 'check_password_direct')['failure_kind']);
        self::assertSame(['created' => 5], $scenario['fixture']['resources']['sessions']);
        self::assertSame('failed', $scenario['fixture']['status']);
    }

    #[DataProvider('mailboxFailures')]
    public function testAFailedMailboxCreationStopsBeforeAnyCreator(string $mode): void
    {
        $http = new WebmailSessionServer();
        $http->failures['direct:Email/add_pop:1'] = $mode;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('create_mailbox', $fixture['stopped_at']);
        self::assertSame('failed', $fixture['capture']);
        self::assertContains('capture_failure', $fixture['failures']);
        self::assertSame([], $http->sessions);
        foreach (array_keys($http->counts) as $function) {
            self::assertStringNotContainsString('Session/create', $function);
        }
        // Only the initial inventories ran; nothing follows a mailbox whose state is not the expected one.
        self::assertSame(1, $http->counts['direct:ActiveSessions/list_active_sessions']);
        self::assertSame(1, $http->counts['ActiveSessions/list_active_sessions']);
        self::assertSame(
            ['creation' => 'unconfirmed', 'cleanup' => 'absent_with_account'],
            $fixture['resources']['mailbox'],
        );
        self::assertSame(1, $http->counts['direct:Email/add_pop']);
        self::assertSame(['created' => 0], $fixture['resources']['sessions']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string}> */
    public static function mailboxFailures(): iterable
    {
        yield 'explicit refusal' => ['api_before'];
        yield 'lost response' => ['throw_after'];
        yield 'gateway error' => ['http_after'];
    }

    public function testAnUnexpectedMailboxResultStopsCaptureAsAFailedExpectation(): void
    {
        $http = new WebmailSessionServer();
        $http->overrides['direct:Email/add_pop:1'] = '"private+other.example.com"';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('create_mailbox', $fixture['stopped_at']);
        self::assertSame('failed', $fixture['expectations']);
        self::assertSame(['capture_failure', 'expectation_failure'], $fixture['failures']);
        self::assertSame('failed', self::observation($scenario, 'create_mailbox')['expectation']);
        self::assertSame(
            ['creation' => 'confirmed', 'cleanup' => 'absent_with_account'],
            $fixture['resources']['mailbox'],
        );
        self::assertSame([], $http->sessions);
    }

    public function testAnEmptyReturnedCredentialFailsItsExpectationWhileCaptureContinues(): void
    {
        $http = new WebmailSessionServer();
        $http->overrides['direct:Session/' . self::MAIL_USER . ':1'] = '{"hostname":null,"session":"","token":"x"}';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['capture']);
        self::assertSame('failed', $fixture['expectations']);
        self::assertSame(['expectation_failure'], $fixture['failures']);
        self::assertSame('empty_string', $scenario['credentials']['mail_user_direct']['session']);
        self::assertSame('failed', self::observation($scenario, 'mail_user_direct')['expectation']);
        self::assertSame(6, count($http->sessions));
        self::assertContains('unsuccessful_phase:mail_user_direct', $scenario['discrepancy_review']['findings']);
    }

    /** An inventory that stays empty after creation is recorded as such, never as a failure or as no sessions. */
    public function testAnInventoryThatShowsNoNewSessionIsRecordedNotFailed(): void
    {
        $http = new WebmailSessionServer();
        $http->listSessions = false;
        $scenario = self::scenario($http);

        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertSame(['direct-uapi' => false, 'bridged-uapi' => false], $scenario['active_sessions']['appeared']);
        $final = $scenario['active_sessions']['by_phase']['sessions_final_direct'];
        self::assertNotNull($final);
        self::assertSame(0, $final['rows']);
        self::assertContains(
            'empty_session_inventory_cannot_separate_no_sessions_from_unreadable_store',
            $scenario['limitations'],
        );
    }

    public function testAnUnknownSessionRowFieldReachesTheStrictReview(): void
    {
        $http = new WebmailSessionServer();
        $http->overrides['ActiveSessions/list_active_sessions:2'] = '[{"ip_address":"192.0.2.10","is_current":0,'
            . '"private_extra":1}]';
        $scenario = self::scenario($http);

        self::assertContains(
            'unacknowledged_discrepancy:sessions_final_bridged',
            $scenario['discrepancy_review']['findings'],
        );
        $final = $scenario['active_sessions']['by_phase']['sessions_final_bridged'];
        self::assertNotNull($final);
        self::assertSame(1, $final['rows']);
        self::assertStringNotContainsString('private_extra', (string) json_encode($scenario));
    }

    public function testMissingOptInFailsBeforeAnyRequest(): void
    {
        putenv('WHM_API_LIVE_MUTATIONS=0');
        $http = new WebmailSessionServer();
        $scenario = self::scenario($http);

        self::assertSame([], $http->requests);
        self::assertSame(['configuration_failure'], $scenario['fixture']['failures']);
        self::assertSame('not_run', $scenario['session_files']['status']);
    }

    /** Session files are read and removed only after the account is gone, then the read-only proof runs again. */
    public function testSessionFilesAreRemovedAfterAccountRemovalAndTheProofRerun(): void
    {
        $http = new WebmailSessionServer();
        $seen = [];
        $cleanup = new RecordingSessionFileCleanup(observe: static function (string $step) use ($http, &$seen): void {
            $seen[] = [$step, $http->account, $http->tokens];
        });
        $proof = new RecordingFilesystemProof([
            RecordingFilesystemProof::PRESENT,
            ['outcome' => 'present', 'residue' => ['sessions' => 4]],
            ['outcome' => 'confirmed_absent', 'residue' => []],
        ]);
        $scenario = self::scenario($http, $proof, $cleanup);
        $fixture = $scenario['fixture'];

        self::assertSame([['observe', false, []], ['remove', false, []]], $seen);
        $mailbox = self::sentMailbox($http->requests);
        self::assertSame([
        ['observe', 'wlsession', 'wlsession.example.com', $mailbox],
            ['remove', 'wlsession', 'wlsession.example.com', $mailbox]], $cleanup->calls);
        self::assertCount(3, $proof->calls);
        // The lifecycle's own truth stands: account removal left session files behind.
        self::assertSame('failed', $fixture['status']);
        self::assertSame(['filesystem_residue_present'], $fixture['failures']);
        self::assertSame('present', $fixture['filesystem_cleanup']);
        self::assertSame(['sessions' => 4], $fixture['filesystem_residue']);
        self::assertSame([
            'status' => 'removed', 'reason' => null, 'observed' => RecordingSessionFileCleanup::OBSERVED,
            'removal' => RecordingSessionFileCleanup::REMOVED,
            'reproof' => ['outcome' => 'confirmed_absent', 'residue' => []],
        ], $scenario['session_files']);
        self::assertContains(
            'filesystem_proof_after_session_file_removal_covers_fixed_locations_at_one_moment',
            $scenario['limitations'],
        );
        self::assertSame('passed', $scenario['discrepancy_review']['status']);
    }

    /** @param list<array<mixed>|null> $findings */
    #[DataProvider('refusedSessionCleanups')]
    public function testSessionFilesAreNeverTouchedWithoutEveryGate(
        array $findings,
        ?string $removeacct,
        string $reason,
    ): void {
        $http = new WebmailSessionServer();
        if ($removeacct !== null) {
            $http->failures['removeacct:1'] = $removeacct;
        }
        $cleanup = new RecordingSessionFileCleanup();
        /** @var list<Finding|null> $findings */
        $scenario = self::scenario($http, new RecordingFilesystemProof($findings), $cleanup);

        self::assertSame([], $cleanup->calls);
        self::assertSame(
            ['status' => 'not_run', 'reason' => $reason, 'observed' => null, 'removal' => null, 'reproof' => null],
            $scenario['session_files'],
        );
    }

    /** @return iterable<string, array{list<array<mixed>|null>, string|null, string}> */
    public static function refusedSessionCleanups(): iterable
    {
        $residue = ['outcome' => 'present', 'residue' => ['sessions' => 4]];
        yield 'session names before the exercise' => [[['outcome' => 'present', 'residue' => [
            ...RecordingFilesystemProof::PRESENT['residue'], 'sessions' => 1,
        ]], $residue], null, 'session_names_before_exercise'];
        yield 'failed control' => [
        [['outcome' => 'unconfirmed', 'residue' => []], $residue], null,
            'filesystem_control_not_passed'];
        yield 'account still present' => [
        [RecordingFilesystemProof::PRESENT, $residue], 'api_before',
            'account_removal_unproven'];
        yield 'account removal outcome lost' => [
        [RecordingFilesystemProof::PRESENT, $residue], 'throw_before',
            'account_removal_unproven'];
    }

    public function testNoCleanupWithoutTheFilesystemProof(): void
    {
        $cleanup = new RecordingSessionFileCleanup();
        $scenario = self::scenario(new WebmailSessionServer(), null, $cleanup);
        self::assertSame([], $cleanup->calls);
        self::assertSame('filesystem_proof_not_enabled', $scenario['session_files']['reason']);
    }

    /**
     * @param array<string, int>|null $observed
     * @param array<string, int>|null $removed
     * @param array{outcome: string, residue: array<string, int>}|null $reproof
     * @param list<string> $steps
     */
    #[DataProvider('sessionCleanupOutcomes')]
    public function testSessionCleanupOutcomesAreReportedExactly(
        ?array $observed,
        ?array $removed,
        ?array $reproof,
        string $status,
        ?string $reason,
        array $steps,
    ): void {
        /** @var array{inventory: int, wide: int, own: int, mailbox_encoded: int, mailbox_literal: int,
         *     anchored: int, unexplained: int, token_suffix: int, directories: int}|null $observed */
        /** @var array{before: int, deleted: int, after: int, wide_after: int}|null $removed */
        $cleanup = new RecordingSessionFileCleanup($observed, $removed);
        $findings = [RecordingFilesystemProof::PRESENT, ['outcome' => 'present', 'residue' => ['sessions' => 4]]];
        if ($reproof !== null) {
            $findings[] = $reproof;
        }
        /** @var list<Finding> $findings */
        $scenario = self::scenario(new WebmailSessionServer(), new RecordingFilesystemProof($findings), $cleanup);
        $files = $scenario['session_files'];

        self::assertSame($status, $files['status']);
        self::assertSame($reason, $files['reason']);
        self::assertSame($steps, array_column($cleanup->calls, 0));
        self::assertSame($observed, $files['observed']);
        self::assertSame($steps === ['observe', 'remove'] ? $removed : null, $files['removal']);
    }

    /**
     * @return iterable<string, array{
     *     array<string, int>|null, array<string, int>|null, array{outcome: string, residue: array<string, int>}|null,
     *     string, string|null, list<string>
     * }>
     */
    public static function sessionCleanupOutcomes(): iterable
    {
        $observed = RecordingSessionFileCleanup::OBSERVED;
        $removed = RecordingSessionFileCleanup::REMOVED;
        $absent = ['outcome' => 'confirmed_absent', 'residue' => []];
        $none = ['inventory' => 40, 'wide' => 0, 'own' => 0, 'mailbox_encoded' => 0, 'mailbox_literal' => 0,
            'anchored' => 0, 'unexplained' => 0, 'token_suffix' => 0, 'directories' => 0];
        yield 'nothing to remove' => [$none, $removed, null, 'not_required', null, ['observe']];
        yield 'only unexplained names' => [
        [...$none, 'wide' => 2, 'unexplained' => 2], $removed, null,
            'residue_left', 'no_anchored_files', ['observe']];
        yield 'anchored symlink only' => [
        [...$none, 'wide' => 1, 'anchored' => 1], $removed, null, 'residue_left',
            'no_anchored_files', ['observe']];
        yield 'unreadable observation' => [null, $removed, null, 'unconfirmed', 'observation_unreadable', ['observe']];
        yield 'unreadable removal' => [$observed, null, $absent, 'unconfirmed', 'removal_unreadable',
            ['observe', 'remove']];
        yield 'anchored files remain' => [$observed, [...$removed, 'deleted' => 3, 'after' => 1], $absent,
            'residue_left', 'anchored_files_remain', ['observe', 'remove']];
        yield 'unexplained names remain' => [
        [...$observed, 'wide' => 5, 'unexplained' => 1],
            [...$removed, 'wide_after' => 1], ['outcome' => 'present', 'residue' => ['sessions' => 1]],
            'residue_left', 'unexplained_names_remain', ['observe', 'remove']];
        yield 'reproof not absent' => [$observed, $removed, ['outcome' => 'unconfirmed', 'residue' => []],
            'unconfirmed', 'reproof_not_absent', ['observe', 'remove']];
    }

    public function testACleanupThatThrowsIsUnreadableNeverAbsent(): void
    {
        $cleanup = new RecordingSessionFileCleanup(throw: true);
        $proof = RecordingFilesystemProof::after(['outcome' => 'present', 'residue' => ['sessions' => 4]]);
        $scenario = self::scenario(new WebmailSessionServer(), $proof, $cleanup);
        self::assertSame('unconfirmed', $scenario['session_files']['status']);
        self::assertSame('observation_unreadable', $scenario['session_files']['reason']);
        self::assertSame(['observe'], array_column($cleanup->calls, 0));
    }

    /**
     * @param Scenario $scenario
     * @return Observation
     */
    private static function observation(array $scenario, string $phase): array
    {
        foreach ($scenario['probes'] as $record) {
            if ($record['phase'] === $phase) {
                return $record;
            }
        }
        self::fail('Missing observation.');
    }

    /** @param list<RequestInterface> $requests */
    private static function sentMarker(array $requests): string
    {
        foreach ($requests as $request) {
            if (str_contains((string) $request->getUri(), '/createacct?')) {
                parse_str((string) $request->getBody(), $fields);
                $marker = $fields['contactemail'] ?? null;
                self::assertIsString($marker);

                return $marker;
            }
        }
        self::fail('The fixture sent no createacct request.');
    }

    /** @param list<RequestInterface> $requests */
    private static function sentPassword(array $requests): string
    {
        return self::addPopField($requests, 'password');
    }

    /** @param list<RequestInterface> $requests */
    private static function sentMailbox(array $requests): string
    {
        return self::addPopField($requests, 'email');
    }

    /** @param list<RequestInterface> $requests */
    private static function addPopField(array $requests, string $field): string
    {
        foreach ($requests as $request) {
            if (str_ends_with((string) $request->getUri(), '/execute/Email/add_pop')) {
                parse_str((string) $request->getBody(), $fields);
                $value = $fields[$field] ?? null;
                self::assertIsString($value);

                return $value;
            }
        }
        self::fail('The fixture sent no add_pop request.');
    }

    /** @return Scenario */
    private static function scenario(
        WebmailSessionServer $http,
        ?FilesystemAbsenceProof $filesystem = null,
        ?SessionFileCleanup $sessionFiles = null,
    ): array {
        return (new WebmailSessionFixture($http, 'WHMTOKEN', self::context(), $filesystem, $sessionFiles))->run();
    }

    /** @return Context */
    public static function context(): array
    {
        return [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
    }

    /**
     * @param array<mixed> $scenario
     * @param list<string> $secrets
     */
    private static function assertPrivate(array $scenario, array $secrets): void
    {
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        foreach (
            ['private', 'PRIVATE', 'WHMTOKEN', 'wlsession', 'example.com', '/home', 'Ws!9', 'cpsess', '192.0.2',
                '1790000000', ...$secrets] as $value
        ) {
            self::assertStringNotContainsString($value, $json, $value);
        }
    }
}
