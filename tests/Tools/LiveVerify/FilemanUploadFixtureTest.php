<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @phpstan-import-type Scenario from FilemanUploadFixture
 * @phpstan-import-type Observation from FilemanUploadFixture
 * @phpstan-import-type Context from EvidenceRunner
 */
final class FilemanUploadFixtureTest extends TestCase
{
    private const UPLOAD = 'direct:Fileman/upload_files';

    private const READ = 'direct:Fileman/get_file_content';

    private const LIST = 'direct:Fileman/list_files';

    private const DELETE = 'direct:Fileman/delete_file';

    private const FAILURE_MODES = ['throw_before', 'throw_after', 'api_before', 'api_after', 'http_before',
        'http_after', 'invalid_before', 'invalid_after'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlupload',
                'WHM_API_LIVE_DOMAIN' => 'wlupload.example.com'] as $key => $value
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

    public function testCompleteRunProvesEveryUploadAndCleansUpExactly(): void
    {
        $http = new FilemanUploadServer();
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['status'], (string) json_encode($fixture['failures']));
        self::assertSame([], $scenario['discrepancy_review']['findings']);
        self::assertSame('passed', $scenario['discrepancy_review']['status']);
        self::assertSame([], $fixture['failures']);
        self::assertSame('passed', $fixture['cleanup']);
        self::assertNull($fixture['stopped_at']);
        foreach (['account', 'token', 'directory'] as $resource) {
            $outcome = $fixture['resources'][$resource];
            self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $outcome);
        }
        self::assertSame(['proven_owned' => 6, 'deleted' => 6], $fixture['resources']['files']);
        self::assertSame('confirmed_absent', $fixture['domain_cleanup']);
        self::assertSame('confirmed_absent', $fixture['zone_cleanup']);
        self::assertSame('not_claimed', $fixture['filesystem_cleanup']);
        self::assertFalse($http->account);
        self::assertFalse($http->zone);
        self::assertSame([], $http->tokens);
        self::assertSame([], $http->directories);
        self::assertSame([
            'createacct', 'Tokens/create_full_access',
            self::UPLOAD, self::UPLOAD, self::UPLOAD, self::UPLOAD, self::UPLOAD,
            self::DELETE, self::DELETE, self::DELETE, self::DELETE, self::DELETE, self::DELETE, self::DELETE,
            'Tokens/revoke', 'removeacct',
        ], $http->mutations);
        self::assertSame(FilemanUploadEvidence::PHASES, array_column($scenario['probes'], 'phase'));
        self::assertSame(5, $http->streamed);
        self::assertSame(['cursor' => true, 'partial' => true], $scenario['borrowed_streams']);
        foreach ($scenario['byte_oracle'] as $outcome) {
            self::assertTrue($outcome['content_exact']);
            self::assertSame('exact', $outcome['from_charset_echo']);
            self::assertSame('exact', $outcome['to_charset_echo']);
            self::assertNull($outcome['first_difference']);
        }
        self::assertSame(
            ['read_bytes', 'read_cursor', 'read_path', 'read_partial', 'read_raw', 'read_empty'],
            array_keys($scenario['byte_oracle']),
        );
        self::assertSame(261, $scenario['byte_oracle']['read_bytes']['expected_bytes']);
        self::assertSame(390, $scenario['byte_oracle']['read_bytes']['expected_utf8_bytes']);
        self::assertSame(0, $scenario['byte_oracle']['read_empty']['expected_bytes']);
        self::assertSame(0, $scenario['byte_oracle']['read_empty']['returned_utf8_bytes']);
        self::assertPrivate($scenario);
    }

    public function testRowsCoverSuccessPartialAndStatusZeroBatchesThroughTheMergedRule(): void
    {
        $scenario = self::scenario(new FilemanUploadServer());
        $uploads = $scenario['uploads'];

        self::assertSame('typed', $uploads['upload_success']['surface']);
        self::assertSame(1, $uploads['upload_success']['uapi_status']);
        self::assertSame(3, $uploads['upload_success']['succeeded']);
        self::assertSame(0, $uploads['upload_success']['failed']);
        self::assertSame('present', $uploads['upload_success']['diskinfo']);
        self::assertSame(1, $uploads['upload_partial']['uapi_status']);
        self::assertSame(1, $uploads['upload_partial']['succeeded']);
        self::assertSame(1, $uploads['upload_partial']['failed']);
        self::assertSame(2, $uploads['upload_partial']['rows']);
        self::assertTrue($uploads['upload_partial']['refused_rows_exact']);
        self::assertSame(0, $uploads['upload_all_failed']['uapi_status']);
        self::assertSame(1, $uploads['upload_all_failed']['envelope_errors']);
        self::assertSame(0, $uploads['upload_all_failed']['succeeded']);
        self::assertSame(1, $uploads['upload_all_failed']['failed']);
        self::assertSame(1, $uploads['upload_all_failed']['rows']);
        self::assertTrue($uploads['upload_all_failed']['refused_rows_exact']);
        self::assertSame('generic', $uploads['upload_raw']['surface']);
        self::assertSame('typed', $uploads['upload_empty']['surface']);
        self::assertSame(1, $uploads['upload_empty']['succeeded']);
        $record = self::observation($scenario, 'upload_all_failed');
        self::assertSame('passed', $record['probe_outcome']);
        self::assertSame('passed', $record['payload_validation']);
        self::assertSame(0, $record['uapi_status']);
    }

    public function testTheLargeRequestReallyStreamsInBoundedReads(): void
    {
        $transfer = self::scenario(new FilemanUploadServer())['uploads']['upload_success']['transfer'];
        self::assertNotNull($transfer);
        self::assertGreaterThan(20000, $transfer['declared_length']);
        self::assertSame($transfer['declared_length'], $transfer['bytes_read']);
        self::assertGreaterThan(2, $transfer['read_calls']);
        self::assertLessThanOrEqual(8192, $transfer['largest_read']);
        self::assertTrue($transfer['body_exact']);
        self::assertFalse($transfer['buffered']);
        self::assertFalse($transfer['sought']);
    }

    public function testTemporaryTokenIsWritableAndShortLivedWithNoRetainedValue(): void
    {
        $http = new FilemanUploadServer();
        $before = time();
        self::scenario($http);
        $create = array_values(array_filter($http->requests, static fn (RequestInterface $request): bool =>
            str_contains((string) $request->getUri(), 'cpanel.function=create_full_access')))[0];
        $parameters = [];
        parse_str((string) $create->getBody(), $parameters);
        self::assertArrayNotHasKey('readonly', $parameters);
        self::assertSame('wlupload-upload-fixture', $parameters['name']);
        self::assertIsString($parameters['expires_at']);
        self::assertGreaterThanOrEqual($before + 900, (int) $parameters['expires_at']);
        self::assertLessThanOrEqual(time() + 900, (int) $parameters['expires_at']);
    }

    public function testNeverTouchesAnotherAccountOrARoute(): void
    {
        $http = new FilemanUploadServer();
        self::scenario($http);
        foreach ($http->requests as $request) {
            $uri = (string) $request->getUri();
            self::assertSame('test.oblak.host', $request->getUri()->getHost());
            self::assertStringNotContainsString('npr', $uri . (str_starts_with(
                $request->getHeaderLine('Content-Type'),
                'multipart/',
            ) ? '' : (string) $request->getBody()));
            if (str_contains($uri, 'upload_files')) {
                self::assertSame(2083, $request->getUri()->getPort());
                self::assertStringContainsString('overwrite=0', $uri);
            }
        }
    }

    #[DataProvider('requiredEnvironment')]
    public function testMissingOrWrongTargetConfigurationPreventsAllRequests(string $key): void
    {
        $http = new FilemanUploadServer();
        $fixture = new FilemanUploadFixture($http, $http->streaming(), 'WHMTOKEN', self::context());
        putenv($key . '=wrong');
        $scenario = $fixture->run();
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertSame('not_started', $scenario['fixture']['setup']);
        self::assertSame('not_required', $scenario['fixture']['cleanup']);
        self::assertSame(['configuration_failure'], $scenario['fixture']['failures']);
        self::assertSame([], $http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredEnvironment(): iterable
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN'] as $key) {
            yield $key => [$key];
        }
    }

    public function testInstanceIsSingleUse(): void
    {
        $http = new FilemanUploadServer();
        $fixture = new FilemanUploadFixture($http, $http->streaming(), 'WHMTOKEN', self::context());
        $fixture->run();
        $requests = count($http->requests);
        $second = $fixture->run();
        self::assertSame(['configuration_failure'], $second['fixture']['failures']);
        self::assertCount($requests, $http->requests);
    }

    #[DataProvider('preexistingIdentities')]
    public function testUnconfirmedInitialAbsenceNeverCreatesOrDeletesAnything(string $step, string $payload): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[$step] = $payload;
        $scenario = self::scenario($http);
        self::assertSame('failed', $scenario['fixture']['setup']);
        self::assertSame('not_run', $scenario['fixture']['capture']);
        self::assertSame('not_required', $scenario['fixture']['cleanup']);
        self::assertSame(['setup_failure'], $scenario['fixture']['failures']);
        self::assertSame([], $http->mutations);
        self::assertSame('not_attempted', $scenario['fixture']['resources']['account']['creation']);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{string, string}> */
    public static function preexistingIdentities(): iterable
    {
        yield 'existing account' => ['listaccts:1',
            '{"acct":[{"user":"wlupload","domain":"wlupload.example.com","email":"private@example.com"}]}'];
        yield 'existing account other domain' => ['listaccts:1',
            '{"acct":[{"user":"wlupload","domain":"private.test"}]}'];
        yield 'domain owned elsewhere' => ['listaccts:2',
            '{"acct":[{"user":"other","domain":"wlupload.example.com"}]}'];
        yield 'addon domain elsewhere' => ['get_domain_info:1',
            '{"domains":[{"user":"other","domain":"wlupload.example.com"}]}'];
        yield 'existing zone' => ['listzones:1', '{"zone":[{"domain":"wlupload.example.com"}]}'];
        yield 'object not account list' => ['listaccts:1', '{"acct":{}}'];
        yield 'null accounts' => ['listaccts:1', 'null'];
        yield 'duplicate account row' => ['listaccts:1',
            '{"acct":[{"user":"other","domain":"a.test"},{"user":"other","domain":"b.test"}]}'];
        yield 'unusable zone list' => ['listzones:1', '{"zone":{}}'];
    }

    public function testConcurrentCreationNeverAuthorizesRemovingAnotherRunsAccount(): void
    {
        $http = new FilemanUploadServer();
        $http->concurrentContactEmail = 'upload-00000000000000000000000000000000@wlupload.example.com';
        $http->failures['createacct:1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertTrue($http->account);
        self::assertTrue($http->zone);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['account']['creation']);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertSame('failed', $scenario['fixture']['cleanup']);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertArrayNotHasKey('Tokens/create_full_access', $http->counts);
        self::assertPrivate($scenario);
    }

    public function testRefusedCreationIsAbsentAndRemovesNothing(): void
    {
        $http = new FilemanUploadServer();
        $http->failures['createacct:1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertSame(['setup_failure'], $scenario['fixture']['failures']);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertSame('passed', $scenario['fixture']['cleanup']);
        self::assertSame([], $http->mutations);
    }

    /** Only an explicit refusal is a known outcome; these replies cannot rule out creation still in flight. */
    #[DataProvider('unusableCreationResponses')]
    public function testUnusableCreationResponseIsNeverReportedAsAbsent(string $failure): void
    {
        $http = new FilemanUploadServer();
        $http->failures['createacct:1'] = $failure . '_before';
        $scenario = self::scenario($http);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['account']['creation']);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertSame('failed', $scenario['fixture']['cleanup']);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertContains('account_creation_outcome_unknown', $scenario['fixture']['failures']);
        self::assertSame([], $http->mutations);
        self::assertPrivate($scenario);
    }

    #[DataProvider('unusableCreationResponses')]
    public function testUnusableCreationResponseStillRemovesTheAccountItsMarkerProves(string $failure): void
    {
        $http = new FilemanUploadServer();
        $http->failures['createacct:1'] = $failure . '_after';
        $scenario = self::scenario($http);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame('confirmed', $scenario['fixture']['resources']['account']['creation']);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertNotContains('account_creation_outcome_unknown', $scenario['fixture']['failures']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableCreationResponses(): iterable
    {
        yield 'lost response' => ['throw'];
        yield 'gateway error' => ['http'];
        yield 'unreadable body' => ['invalid'];
    }

    public function testTransientIdentityReadCannotAbandonTheAccountOrItsToken(): void
    {
        $http = new FilemanUploadServer();
        $http->failures['listaccts:4'] = 'throw_before';
        $http->failures['listaccts:5'] = 'throw_before';
        $scenario = self::scenario($http);
        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
    }

    /** Only a request that failed is read again: any reply the server returned is final, however unusable. */
    #[DataProvider('finalIdentityReplies')]
    public function testIdentityReplyThatIsNotALostRequestIsNeverReadAgain(string $mode): void
    {
        $http = new FilemanUploadServer();
        $http->failures['listaccts:4'] = $mode;
        $scenario = self::scenario($http);
        // Three setup reads, the one final cleanup read, and the separate domain check.
        self::assertSame(5, $http->counts['listaccts']);
        self::assertContains('account_identity_unconfirmed', $scenario['fixture']['failures']);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey(self::DELETE, $http->counts);
        self::assertTrue($http->account);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{string}> */
    public static function finalIdentityReplies(): iterable
    {
        yield 'result 0' => ['api_before'];
        yield 'gateway error' => ['http_before'];
        yield 'unreadable body' => ['invalid_before'];
    }

    /** Re-reads are bounded, and exhausting them stops every mutation with an accurate report. */
    public function testExhaustedIdentityReadsStopAllMutationAndReportTheRealState(): void
    {
        $http = new FilemanUploadServer();
        foreach ([4, 5, 6] as $count) {
            $http->failures['listaccts:' . $count] = 'throw_before';
        }
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];
        self::assertTrue($http->account);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey(self::DELETE, $http->counts);
        self::assertSame('unconfirmed', $fixture['resources']['account']['cleanup']);
        self::assertSame('unconfirmed', $fixture['resources']['token']['cleanup']);
        self::assertSame('unconfirmed', $fixture['resources']['directory']['cleanup']);
        self::assertSame(['proven_owned' => 6, 'deleted' => 0], $fixture['resources']['files']);
        self::assertSame('failed', $fixture['cleanup']);
        // Three setup reads, the three bounded cleanup reads, and the separate domain check.
        self::assertSame(7, $http->counts['listaccts']);
    }

    /** A returned inventory is final: only a failed request is read again. */
    public function testForeignMarkerIsNeverReadTwice(): void
    {
        $http = new FilemanUploadServer();
        $http->overrides['listaccts:4'] = '{"acct":[{"user":"wlupload","domain":"wlupload.example.com",'
            . '"email":"private-foreign@example.com"}]}';
        $scenario = self::scenario($http);
        self::assertSame(5, $http->counts['listaccts']);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['account']['cleanup']);
    }

    /** @param array<string, string> $overrides */
    #[DataProvider('emptyIdentityLists')]
    public function testAnEmptyIdentityListCannotProveAbsence(array $overrides, string $resource): void
    {
        $http = new FilemanUploadServer();
        $http->overrides = $overrides;
        $scenario = self::scenario($http);
        $outcome = $resource === 'account'
            ? $scenario['fixture']['resources']['account']['cleanup'] : $scenario['fixture']['zone_cleanup'];
        self::assertSame('unconfirmed', $outcome);
        self::assertSame('failed', $scenario['fixture']['cleanup']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function emptyIdentityLists(): iterable
    {
        yield 'accounts during cleanup' => [['listaccts:4' => '{"acct":[]}'], 'account'];
        yield 'zones after removal' => [['listzones:2' => '{"zone":[]}'], 'zone'];
    }

    public function testTokenWithReadonlyFlagOrWrongExpirySkipsCaptureButStillCleansUp(): void
    {
        foreach ([['readonly', 1], ['expires_at', 12345], ['expires_at', null]] as [$field, $value]) {
            $http = new FilemanUploadServer();
            $http->tokenFields[$field] = $value;
            $scenario = self::scenario($http);
            self::assertSame('failed', $scenario['fixture']['setup']);
            self::assertSame('not_run', $scenario['fixture']['capture']);
            self::assertSame('passed', $scenario['fixture']['cleanup']);
            self::assertArrayNotHasKey(self::UPLOAD, $http->counts);
            self::assertSame(1, $http->counts['Tokens/revoke']);
            self::assertFalse($http->account);
        }
    }

    /**
     * Every mutation and every owned read fails in every way: cleanup still runs, and nothing is ever repeated.
     */
    #[DataProvider('everyFailurePath')]
    public function testEveryFailurePathCleansUpWithoutRetrying(string $step, string $mode): void
    {
        $http = new FilemanUploadServer();
        $http->failures[$step] = $mode;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame('failed', $scenario['discrepancy_review']['status']);
        self::assertNotSame([], $fixture['failures']);
        self::assertFalse($http->account, 'The run-owned account must be removed.');
        self::assertSame([], $http->tokens);
        self::assertLessThanOrEqual(1, $http->counts['createacct']);
        self::assertLessThanOrEqual(1, $http->counts['Tokens/create_full_access'] ?? 0);
        self::assertLessThanOrEqual(1, $http->counts['Tokens/revoke'] ?? 0);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertLessThanOrEqual(5, $http->counts[self::UPLOAD] ?? 0);
        self::assertLessThanOrEqual(7, $http->counts[self::DELETE] ?? 0);
        self::assertSame(
            count(array_unique(self::deletedPaths($http))),
            count(self::deletedPaths($http)),
            'A delete was repeated.',
        );
        self::assertSame('confirmed_absent', $fixture['resources']['account']['cleanup']);
        self::assertSame('confirmed_absent', $fixture['domain_cleanup']);
        self::assertSame('confirmed_absent', $fixture['zone_cleanup']);
        self::assertSame('confirmed_absent', $fixture['resources']['token']['cleanup']);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{string, string}> */
    public static function everyFailurePath(): iterable
    {
        $steps = ['Tokens/create_full_access:1', 'version:1'];
        foreach (range(1, 5) as $count) {
            $steps[] = self::UPLOAD . ':' . $count;
        }
        foreach (range(1, 6) as $count) {
            $steps[] = self::READ . ':' . $count;
        }
        foreach (range(1, 6) as $count) {
            $steps[] = self::LIST . ':' . $count;
        }
        foreach (range(1, 7) as $count) {
            $steps[] = self::DELETE . ':' . $count;
        }
        foreach ($steps as $step) {
            foreach (self::FAILURE_MODES as $mode) {
                yield $step . ' ' . $mode => [$step, $mode];
            }
        }
    }

    /** A token revoke failure must not stop account removal; removal then takes the token with it. */
    #[DataProvider('finalMutationFailures')]
    public function testFinalMutationFailuresStayVisibleAndDoNotStopIndependentChecks(string $step, string $mode): void
    {
        $http = new FilemanUploadServer();
        $http->failures[$step] = $mode;
        $scenario = self::scenario($http);
        self::assertSame('failed', $scenario['fixture']['cleanup']);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertSame(1, $http->counts[substr($step, 0, -2)]);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertGreaterThanOrEqual(2, $http->counts['listzones']);
        self::assertGreaterThanOrEqual(2, $http->counts['get_domain_info']);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{string, string}> */
    public static function finalMutationFailures(): iterable
    {
        foreach (['Tokens/revoke:1', 'removeacct:1'] as $step) {
            foreach (self::FAILURE_MODES as $mode) {
                yield $step . ' ' . $mode => [$step, $mode];
            }
        }
    }

    /**
     * This run created the token, so an unreadable listing cannot withhold its revoke; removal may not take it.
     *
     * Reads: setup absence, setup capability, then this gate.
     */
    #[DataProvider('unreadableTokenListings')]
    public function testUnreadableTokenListingStillRevokesTheTokenThisRunCreated(string $mode): void
    {
        $http = new FilemanUploadServer();
        $http->failures['Tokens/list:3'] = $mode;
        $http->failures['removeacct:1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame([], $http->tokens);
        self::assertTrue($http->account);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['token']['cleanup']);
        self::assertSame('present', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertNotContains('token_absence_unconfirmed', $scenario['fixture']['failures']);
        self::assertContains('account_remove_failure', $scenario['fixture']['failures']);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableTokenListings(): iterable
    {
        yield 'lost request' => ['throw_before'];
        yield 'result 0' => ['api_before'];
        yield 'gateway error' => ['http_before'];
        yield 'unreadable body' => ['invalid_before'];
    }

    /** The token's own gate survives a lost ownership read too; it must not fall back to a single read. */
    public function testLostOwnershipReadAtTheTokenGateStillRevokesTheToken(): void
    {
        $http = new FilemanUploadServer();
        $http->failures['listaccts:5'] = 'throw_before';
        $http->failures['removeacct:1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame([], $http->tokens);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['token']['cleanup']);
    }

    public function testUnprovenTokenIsNeverRevoked(): void
    {
        $http = new FilemanUploadServer();
        // The create may have worked and nothing can show it: no create reply, then no listing.
        $http->failures['Tokens/create_full_access:1'] = 'throw_after';
        $http->failures['Tokens/list:2'] = 'throw_before';
        $http->failures['removeacct:1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['token']['creation']);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['token']['cleanup']);
        self::assertContains('token_absence_unconfirmed', $scenario['fixture']['failures']);
        self::assertSame('failed', $scenario['fixture']['cleanup']);
    }

    public function testTokenListingThatShowsTheTokenProvesALostCreation(): void
    {
        $http = new FilemanUploadServer();
        $http->failures['Tokens/create_full_access:1'] = 'throw_after';
        $scenario = self::scenario($http);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame('confirmed', $scenario['fixture']['resources']['token']['creation']);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['token']['cleanup']);
    }

    public function testTokenListedAsAbsentSendsNoRevoke(): void
    {
        $http = new FilemanUploadServer();
        $http->failures['Tokens/create_full_access:1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['token']['cleanup']);
    }

    /**
     * Ownership lost after the first gate stops the revoke, the listing notwithstanding.
     *
     * @param array<string, string> $failures
     */
    #[DataProvider('tokenListingsAtALostOwnershipGate')]
    public function testForeignMarkerAtTheTokenGateRevokesNothing(array $failures): void
    {
        $foreign = '{"acct":[{"user":"wlupload","domain":"wlupload.example.com",'
            . '"email":"private-foreign@example.com"}]}';
        $http = new FilemanUploadServer();
        $http->failures = $failures;
        $http->overrides['listaccts:5'] = $foreign;
        $http->overrides['listaccts:6'] = $foreign;
        $scenario = self::scenario($http);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame(['wlupload-upload-fixture'], $http->tokens);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['token']['cleanup']);
        self::assertContains('token_absence_unconfirmed', $scenario['fixture']['failures']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function tokenListingsAtALostOwnershipGate(): iterable
    {
        yield 'listing shows the token' => [[]];
        yield 'listing unreadable' => [['Tokens/list:3' => 'throw_before']];
    }

    public function testLostUploadResponseProvesNoOwnershipAndDeletesNoFile(): void
    {
        $http = new FilemanUploadServer();
        $http->failures[self::UPLOAD . ':1'] = 'throw_after';
        $scenario = self::scenario($http);
        self::assertSame('upload_success', $scenario['fixture']['stopped_at']);
        self::assertArrayNotHasKey(self::DELETE, $http->counts);
        self::assertSame(1, $http->counts[self::UPLOAD]);
        self::assertSame(['proven_owned' => 0, 'deleted' => 0], $scenario['fixture']['resources']['files']);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['directory']['creation']);
        self::assertSame('present', $scenario['fixture']['resources']['directory']['cleanup']);
        self::assertContains('directory_still_present', $scenario['fixture']['failures']);
        self::assertFalse($http->account);
    }

    public function testRejectedFirstUploadThatStoredNothingLeavesNoDirectoryToReport(): void
    {
        $http = new FilemanUploadServer();
        $http->failures[self::UPLOAD . ':1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['directory']['cleanup']);
        self::assertArrayNotHasKey(self::DELETE, $http->counts);
        self::assertNotContains('directory_still_present', $scenario['fixture']['failures']);
    }

    /** @param array<string, string> $overrides */
    #[DataProvider('unexpectedRows')]
    public function testUnexpectedSuccessRowsStopBeforeOwnershipAndLaterMutations(array $overrides): void
    {
        $http = new FilemanUploadServer();
        $http->overrides = $overrides;
        $scenario = self::scenario($http);
        self::assertSame('failed', $scenario['fixture']['capture']);
        self::assertSame(1, $http->counts[self::UPLOAD]);
        self::assertArrayNotHasKey(self::DELETE, $http->counts);
        self::assertArrayNotHasKey(self::READ, $http->counts);
        self::assertSame(['proven_owned' => 0, 'deleted' => 0], $scenario['fixture']['resources']['files']);
        self::assertFalse($http->account);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function unexpectedRows(): iterable
    {
        $step = self::UPLOAD . ':1';
        yield 'no rows' => [[$step => '{"succeeded":3,"failed":0,"warned":0,"uploads":[]}']];
        yield 'no uploads member' => [[$step => '{"succeeded":3,"failed":0,"warned":0}']];
        yield 'counts disagree' => [[$step => '{"succeeded":2,"failed":1,"warned":0,"uploads":[]}']];
        yield 'empty object' => [[$step => '{}']];
    }

    public function testRenamedStoredFileIsNotAcceptedAsTheExpectedName(): void
    {
        $http = new FilemanUploadServer();
        $http->afterEffect = static function (FilemanUploadServer $server): void {
            foreach ($server->directories as $name => $files) {
                foreach ($files as $file => $bytes) {
                    if (str_starts_with($file, 'bytes-')) {
                        unset($server->directories[$name][$file]);
                        $server->directories[$name]['renamed-' . $file] = $bytes;
                    }
                }
            }
        };
        $scenario = self::scenario($http);
        self::assertSame('inventory_success', $scenario['fixture']['stopped_at']);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['directory']['creation']);
        self::assertArrayNotHasKey(self::DELETE, $http->counts);
        self::assertFalse($http->account);
    }

    /** @return array<string, array{string}> */
    public static function corruptions(): array
    {
        return ['extra encoding layer' => ['double'], 'truncated at NUL' => ['truncated'],
            'replacement characters' => ['replacement'], 'same size, different bytes' => ['equal-size']];
    }

    #[DataProvider('corruptions')]
    public function testByteOracleFailsOnAnyTranscodingCorruptionAndWithdrawsOwnership(string $mode): void
    {
        $http = new FilemanUploadServer();
        $http->transcoding = $mode;
        $scenario = self::scenario($http);

        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertContains('expectation_failure', $scenario['fixture']['failures']);
        self::assertSame('read_raw', $scenario['fixture']['stopped_at']);
        // Every row batch already ran; the only mutation a mismatch withholds is the empty upload.
        self::assertSame(4, $http->counts[self::UPLOAD]);
        self::assertCount(4, $scenario['uploads']);
        self::assertSame(5, $http->counts[self::READ]);
        self::assertFalse($scenario['byte_oracle']['read_bytes']['content_exact']);
        self::assertNotNull($scenario['byte_oracle']['read_bytes']['first_difference']);
        self::assertLessThan(5, $scenario['fixture']['resources']['files']['proven_owned']);
        self::assertSame(
            $scenario['fixture']['resources']['files']['proven_owned'],
            $http->counts[self::DELETE] ?? 0,
        );
        self::assertContains('fixture_directory_not_empty', $scenario['fixture']['failures']);
        self::assertFalse($http->account);
        self::assertPrivate($scenario);
    }

    public function testSizeAloneNeverPassesTheOracle(): void
    {
        $http = new FilemanUploadServer();
        $http->transcoding = 'equal-size';
        $outcome = self::scenario($http)['byte_oracle']['read_path'];
        self::assertSame($outcome['expected_utf8_bytes'], $outcome['returned_utf8_bytes']);
        self::assertFalse($outcome['content_exact']);
    }

    public function testEmptyReadbackMustBeExactlyEmpty(): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::READ . ':6'] = '{"content":" ","from_char":"ISO-8859-1","to_char":"UTF-8"}';
        $scenario = self::scenario($http);
        self::assertFalse($scenario['byte_oracle']['read_empty']['content_exact']);
        self::assertSame(1, $scenario['byte_oracle']['read_empty']['returned_utf8_bytes']);
        self::assertSame('read_empty', $scenario['fixture']['stopped_at']);
        self::assertSame(['proven_owned' => 5, 'deleted' => 5], $scenario['fixture']['resources']['files']);
        self::assertSame('failed', $scenario['fixture']['status']);
    }

    public function testFailedEmptyReadbackCostsNoEarlierEvidence(): void
    {
        $http = new FilemanUploadServer();
        $http->failures[self::READ . ':6'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertSame(5, $http->counts[self::UPLOAD]);
        self::assertCount(5, $scenario['uploads']);
        self::assertSame('read_empty', $scenario['fixture']['stopped_at']);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertFalse($http->account);
    }

    /**
     * Listings report a zero-byte size as an empty string on this server, so an upload row may too.
     *
     * The empty file is alone and last, so either surprise costs only itself.
     */
    #[DataProvider('emptyRowSizes')]
    public function testUnexpectedEmptyRowSizeCostsOnlyTheEmptyFile(mixed $size, string $kind): void
    {
        $http = new FilemanUploadServer();
        $http->emptyRowSize = $size;
        $scenario = self::scenario($http);
        self::assertSame('upload_empty', $scenario['fixture']['stopped_at']);
        self::assertSame($kind, $scenario['fixture']['failures'][0]);
        self::assertSame(5, $http->counts[self::UPLOAD]);
        self::assertCount(5, array_filter($scenario['byte_oracle'], static fn (array $outcome): bool =>
            $outcome['content_exact']));
        self::assertSame(['proven_owned' => 5, 'deleted' => 5], $scenario['fixture']['resources']['files']);
        self::assertFalse($http->account);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function emptyRowSizes(): iterable
    {
        yield 'empty string fails the typed contract' => ['', 'capture_failure'];
        yield 'null passes the contract but not the row check' => [null, 'expectation_failure'];
    }

    public function testRowBatchesCompleteEvenWhenTheFirstReadbackFails(): void
    {
        $http = new FilemanUploadServer();
        $http->failures[self::READ . ':1'] = 'api_before';
        $scenario = self::scenario($http);
        self::assertSame(4, $http->counts[self::UPLOAD]);
        self::assertSame(
            ['upload_success', 'upload_partial', 'upload_all_failed', 'upload_raw'],
            array_keys($scenario['uploads']),
        );
        self::assertArrayNotHasKey('upload_empty', $scenario['uploads']);
        self::assertFalse($http->account);
    }

    /**
     * Exact content keeps ownership and the remaining phases; a differing echo still fails the run.
     *
     * @param array<string, string> $echo
     */
    #[DataProvider('charsetEchoes')]
    public function testReturnedCharsetsMustEchoTheRequestOrItsLowercaseForm(array $echo): void
    {
        $http = new FilemanUploadServer();
        $http->charsetEcho = $echo;
        $scenario = self::scenario($http);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertContains('expectation_failure', $scenario['fixture']['failures']);
        self::assertContains('other', [
            $scenario['byte_oracle']['read_bytes']['from_charset_echo'],
            $scenario['byte_oracle']['read_bytes']['to_charset_echo'],
        ]);
        self::assertTrue($scenario['byte_oracle']['read_bytes']['content_exact']);
        self::assertSame(5, $http->counts[self::UPLOAD]);
        self::assertSame(['proven_owned' => 6, 'deleted' => 6], $scenario['fixture']['resources']['files']);
        self::assertSame('passed', $scenario['fixture']['cleanup']);
        self::assertSame('failed', self::observation($scenario, 'read_bytes')['expectation']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function charsetEchoes(): iterable
    {
        yield 'detected source' => [['from_char' => 'UTF-8']];
        yield 'locale target' => [['to_char' => 'ISO-8859-1']];
        yield 'mixed case' => [['to_char' => 'Utf-8']];
        yield 'trailing space' => [['from_char' => 'ISO-8859-1 ']];
    }

    /**
     * The form observed on cPanel 11.138.0.19: only the twin fields appear, with lowercased labels.
     *
     * Charset labels are case-insensitive, so this still shows the requested conversion.
     */
    public function testLowercaseEchoInTheTwinFieldsPassesAndIsClassified(): void
    {
        $http = new FilemanUploadServer();
        $http->documentedCharsetFields = false;
        $http->charsetEcho = ['from_charset' => 'iso-8859-1', 'to_charset' => 'utf-8'];
        $scenario = self::scenario($http);
        self::assertSame('passed', $scenario['fixture']['status']);
        foreach ($scenario['byte_oracle'] as $outcome) {
            self::assertSame('lowercase', $outcome['from_charset_echo']);
            self::assertSame('lowercase', $outcome['to_charset_echo']);
            self::assertTrue($outcome['content_exact']);
        }
    }

    public function testExactContentNeverExcusesAWrongTwinField(): void
    {
        $http = new FilemanUploadServer();
        $http->documentedCharsetFields = false;
        $http->charsetEcho = ['from_charset' => 'windows-1252'];
        $scenario = self::scenario($http);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertSame('other', $scenario['byte_oracle']['read_bytes']['from_charset_echo']);
        self::assertSame('exact', $scenario['byte_oracle']['read_bytes']['to_charset_echo']);
    }

    public function testMissingCharsetFieldsFailRatherThanPass(): void
    {
        $http = new FilemanUploadServer();
        $http->reportCharsets = false;
        $scenario = self::scenario($http);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertSame('other', $scenario['byte_oracle']['read_bytes']['from_charset_echo']);
    }

    public function testSchemalessCharsetTwinIsReadOnlyWhenTheDocumentedFieldIsAbsent(): void
    {
        $http = new FilemanUploadServer();
        $http->charsetEcho = ['from_charset' => 'other-value'];
        $scenario = self::scenario($http);
        self::assertSame('exact', $scenario['byte_oracle']['read_bytes']['from_charset_echo']);
        self::assertSame('passed', $scenario['fixture']['status']);
    }

    public function testServerOverwriteDespiteDisabledOverwriteStopsAtTheUnexpectedRows(): void
    {
        $http = new FilemanUploadServer();
        $http->honorOverwrite = false;
        $scenario = self::scenario($http);
        self::assertSame('upload_partial', $scenario['fixture']['stopped_at']);
        self::assertContains('expectation_failure', $scenario['fixture']['failures']);
        self::assertSame(2, $http->counts[self::UPLOAD]);
        self::assertFalse($http->account);
    }

    public function testSilentOverwriteBehindRefusalRowsIsCaughtByTheOracleAndProvesNoOwnership(): void
    {
        $http = new FilemanUploadServer();
        $changed = false;
        $http->afterEffect = static function (FilemanUploadServer $server) use (&$changed): void {
            if (!$changed && ($server->counts[self::UPLOAD] ?? 0) === 2) {
                $changed = true;
                foreach ($server->directories as $name => $files) {
                    foreach (array_keys($files) as $file) {
                        if (str_starts_with($file, 'bytes-')) {
                            // Same length, so only the byte oracle can notice.
                            $server->directories[$name][$file] = str_repeat('x', 261);
                        }
                    }
                }
            }
        };
        $scenario = self::scenario($http);
        self::assertFalse($scenario['byte_oracle']['read_bytes']['content_exact']);
        self::assertSame(['proven_owned' => 4, 'deleted' => 4], $scenario['fixture']['resources']['files']);
        self::assertContains('expectation_failure', $scenario['fixture']['failures']);
        self::assertContains('fixture_directory_not_empty', $scenario['fixture']['failures']);
        self::assertFalse($http->account);
    }

    public function testUnexpectedDirectoryEntryBlocksTheRecursiveDirectoryDelete(): void
    {
        $http = new FilemanUploadServer();
        $injected = false;
        $http->afterEffect = static function (FilemanUploadServer $server) use (&$injected): void {
            if (!$injected && ($server->counts[self::DELETE] ?? 0) === 6) {
                $injected = true;
                foreach (array_keys($server->directories) as $name) {
                    $server->directories[$name]['.private-unexpected'] = 'private';
                }
            }
        };
        $scenario = self::scenario($http);
        self::assertContains('fixture_directory_not_empty', $scenario['fixture']['failures']);
        self::assertContains('directory_still_present', $scenario['fixture']['failures']);
        self::assertSame(6, $http->counts[self::DELETE]);
        self::assertSame('present', $scenario['fixture']['resources']['directory']['cleanup']);
        self::assertSame(['proven_owned' => 6, 'deleted' => 6], $scenario['fixture']['resources']['files']);
        self::assertSame('failed', $scenario['fixture']['cleanup']);
        self::assertFalse($http->account);
        self::assertPrivate($scenario);
    }

    /** The type filter on an earlier listing hid links from this check, so the listing now sends none. */
    public function testSymbolicLinkBlocksTheRecursiveDirectoryDelete(): void
    {
        $http = new FilemanUploadServer();
        $injected = false;
        $http->afterEffect = static function (FilemanUploadServer $server) use (&$injected): void {
            if (!$injected && ($server->counts[self::DELETE] ?? 0) === 6) {
                $injected = true;
                foreach (array_keys($server->directories) as $name) {
                    $server->symlinks[$name] = ['private-link'];
                }
            }
        };
        $scenario = self::scenario($http);
        self::assertSame(6, $http->counts[self::DELETE]);
        self::assertContains('fixture_directory_not_empty', $scenario['fixture']['failures']);
        self::assertFalse($http->account);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{string}> */
    public static function unprovenEmptiness(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'object without lists' => ['{"other":[]}'];
        yield 'object with one list' => ['{"dirs":[]}'];
    }

    #[DataProvider('unprovenEmptiness')]
    public function testEmptyLookingObjectCannotAuthorizeTheRecursiveDirectoryDelete(string $payload): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::LIST . ':5'] = $payload;
        $scenario = self::scenario($http);
        self::assertSame(6, $http->counts[self::DELETE]);
        self::assertContains('directory_emptiness_unproven', $scenario['fixture']['failures']);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertFalse($http->account);
    }

    public function testDocumentedEmptyListingFormProvesEmptiness(): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::LIST . ':5'] = '{"dirs":[],"files":[]}';
        $scenario = self::scenario($http);
        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertSame(7, $http->counts[self::DELETE]);
    }

    public function testDotAndParentRowsAreNotDirectoryContents(): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::LIST . ':5'] = '[{"file":"."},{"file":".."},{"file":"up","isparent":1}]';
        $scenario = self::scenario($http);
        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertSame(7, $http->counts[self::DELETE]);
    }

    /** A surprise on an unrelated home entry reaches the strict review without stopping the upload run. */
    public function testHomeListingShapeSurpriseIsReviewedRatherThanFatal(): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::LIST . ':1'] = '[{"file":"public_html","fullpath":"/home/wlupload/public_html"},'
            . '{"file":"other","mtime":"private-unexpected-type"}]';
        $scenario = self::scenario($http);
        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertSame('failed', $scenario['discrepancy_review']['status']);
        self::assertContains('unacknowledged_discrepancy:home_initial', $scenario['discrepancy_review']['findings']);
        self::assertPrivate($scenario);
    }

    #[DataProvider('unusableHomes')]
    public function testHomeThatIsNotTheExpectedOneCannotProveAbsence(string $payload): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::LIST . ':1'] = $payload;
        $scenario = self::scenario($http);
        self::assertSame('home_initial', $scenario['fixture']['stopped_at']);
        self::assertArrayNotHasKey(self::UPLOAD, $http->counts);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableHomes(): iterable
    {
        yield 'no entries' => ['[]'];
        yield 'known entry missing' => ['[{"file":"other"}]'];
        yield 'different home' => ['[{"file":"public_html","fullpath":"/home2/wlupload/public_html"}]'];
    }

    public function testHomeRowWithoutOptionalFullPathIsStillUsable(): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::LIST . ':1'] = '[{"file":"public_html"}]';
        self::assertSame('passed', self::scenario($http)['fixture']['status']);
    }

    public function testExtraEntryDuringCaptureStopsMutationsAtTheInventory(): void
    {
        $http = new FilemanUploadServer();
        $injected = false;
        $http->afterEffect = static function (FilemanUploadServer $server) use (&$injected): void {
            if (!$injected && ($server->counts[self::UPLOAD] ?? 0) === 3) {
                $injected = true;
                foreach (array_keys($server->directories) as $name) {
                    $server->directories[$name]['private-unexpected'] = 'private';
                }
            }
        };
        $scenario = self::scenario($http);
        self::assertSame('inventory_final', $scenario['fixture']['stopped_at']);
        self::assertSame(4, $http->counts[self::UPLOAD]);
        self::assertContains('fixture_directory_not_empty', $scenario['fixture']['failures']);
        self::assertArrayNotHasKey('read_raw', $scenario['byte_oracle']);
        self::assertFalse($http->account);
    }

    /** Status 0 follows the merged rows-aware rule: rows return normally, and these do not. */
    #[DataProvider('statusZeroWithoutRows')]
    public function testStatusZeroWithoutUsableRowsIsNotAcceptedAsARefusal(string $payload, string $kind): void
    {
        $http = new FilemanUploadServer();
        $http->overrides[self::UPLOAD . ':3'] = $payload;
        $scenario = self::scenario($http);
        $record = self::observation($scenario, 'upload_all_failed');
        self::assertSame($kind, $record['failure_kind']);
        self::assertSame('failed', $scenario['fixture']['status']);
        self::assertSame('upload_all_failed', $scenario['fixture']['stopped_at']);
        self::assertFalse($http->account);
        self::assertPrivate($scenario);
    }

    /** @return iterable<string, array{string, string}> */
    public static function statusZeroWithoutRows(): iterable
    {
        yield 'explicit null' => ['null', 'api_failure'];
        yield 'validated empty rows' => ['{"uploads":[],"succeeded":0,"failed":0,"warned":0}', 'api_failure'];
        yield 'rows missing' => ['{"succeeded":0,"failed":0,"warned":0}', 'payload_mismatch'];
        yield 'malformed row' => ['{"uploads":[{"status":"0"}]}', 'payload_mismatch'];
    }

    /** Both statuses are valid envelopes, so only the phase's expected status can detect a change. */
    #[DataProvider('unexpectedStatuses')]
    public function testEveryPhaseRequiresItsReportedEnvelopeStatusWithExactRows(
        int $count,
        int $status,
        string $phase,
    ): void {
        $http = new FilemanUploadServer();
        $http->statuses[self::UPLOAD . ':' . $count] = $status;
        $scenario = self::scenario($http);
        self::assertSame($phase, $scenario['fixture']['stopped_at']);
        self::assertContains('expectation_failure', $scenario['fixture']['failures']);
        self::assertSame($status, $scenario['uploads'][$phase]['uapi_status']);
        self::assertTrue($scenario['uploads'][$phase]['refused_rows_exact']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function unexpectedStatuses(): iterable
    {
        yield 'partial batch at status 0' => [2, 0, 'upload_partial'];
        yield 'all-failed batch at status 1' => [3, 1, 'upload_all_failed'];
    }

    public function testInexactFramingOrUnconfirmedDeliveryFailsTheUpload(): void
    {
        $http = new FilemanUploadServer();
        self::assertNotNull($http->framing);
        $http->framing['content_length_exact'] = false;
        $scenario = self::scenario($http);
        self::assertSame('upload_success', $scenario['fixture']['stopped_at']);
        self::assertContains('expectation_failure', $scenario['fixture']['failures']);
        self::assertSame(1, $http->counts[self::UPLOAD]);

        $http = new FilemanUploadServer();
        $http->framing = null;
        self::assertSame('upload_success', self::scenario($http)['fixture']['stopped_at']);
    }

    public function testDeleteFailureDoesNotStopTheRemainingDeletesOrAccountRemoval(): void
    {
        $http = new FilemanUploadServer();
        $http->failures[self::DELETE . ':2'] = 'api_before';
        $scenario = self::scenario($http);
        // Five files plus the failed one; the directory delete is never sent while a file remains.
        self::assertSame(6, $http->counts[self::DELETE]);
        self::assertSame(['proven_owned' => 6, 'deleted' => 5], $scenario['fixture']['resources']['files']);
        self::assertContains('owned_delete_failure', $scenario['fixture']['failures']);
        self::assertContains('fixture_directory_not_empty', $scenario['fixture']['failures']);
        self::assertFalse($http->account);
    }

    public function testAccountOwnershipChangingBeforeCleanupStopsAllMutationThere(): void
    {
        $http = new FilemanUploadServer();
        $http->overrides['listaccts:4'] = '{"acct":[{"user":"wlupload","domain":"wlupload.example.com",'
            . '"email":"private-foreign@example.com"}]}';
        $scenario = self::scenario($http);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey(self::DELETE, $http->counts);
        self::assertSame('unconfirmed', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertSame('failed', $scenario['fixture']['cleanup']);
    }

    public function testZoneResidueIsReportedWithoutFurtherMutation(): void
    {
        $http = new FilemanUploadServer();
        $http->retainZone = true;
        $scenario = self::scenario($http);
        self::assertSame('confirmed_absent', $scenario['fixture']['resources']['account']['cleanup']);
        self::assertSame('present', $scenario['fixture']['zone_cleanup']);
        self::assertSame('failed', $scenario['fixture']['status']);
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

    /** @return list<string> */
    private static function deletedPaths(FilemanUploadServer $http): array
    {
        $paths = [];
        foreach ($http->requests as $request) {
            if (str_ends_with($request->getUri()->getPath(), '/delete_file')) {
                $paths[] = (string) $request->getBody();
            }
        }

        return $paths;
    }

    /** @return Scenario */
    private static function scenario(FilemanUploadServer $http): array
    {
        return (new FilemanUploadFixture($http, $http->streaming(), 'WHMTOKEN', self::context()))->run();
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

    /** @param array<mixed> $scenario */
    private static function assertPrivate(array $scenario): void
    {
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        foreach (
            ['private', 'WHMTOKEN', 'PRIVATEFIXTURETOKEN', 'wlupload', 'example.com', '/home', 'bytes-', 'cursor-',
                'conflicting', 'BORROWED', '1790000000'] as $value
        ) {
            self::assertStringNotContainsString($value, $json);
        }
        self::assertStringNotContainsString(FilemanUploadRecipe::CONFLICT_BYTES, $json);
        foreach (FilemanUploadRecipe::LABELS as $label) {
            $contents = FilemanUploadRecipe::contents($label);
            if ($contents !== '') {
                self::assertStringNotContainsString(substr((string) json_encode(
                    mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1'),
                    JSON_THROW_ON_ERROR,
                ), 1, 40), $json);
            }
        }
    }
}
