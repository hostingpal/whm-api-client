<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\CaptureClient;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccountLifecycle;
use HostingPal\WhmApiClient\Tools\LiveVerify\ExerciseFailure;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Every assertion here runs after run() returns: the lifecycle catches Throwable around both callbacks, and PHPUnit's
 * own failures are Throwable, so an assertion inside a callback would be swallowed and could never fail a test.
 *
 * @phpstan-import-type Outcome from DisposableAccountLifecycle
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class DisposableAccountLifecycleTest extends TestCase
{
    private const MODES = ['throw_before', 'throw_after', 'api_before', 'api_after', 'http_before', 'http_after',
        'invalid_before', 'invalid_after'];

    private const FOREIGN = '{"acct":[{"user":"wdisposable","domain":"wdisposable.example.com",'
        . '"email":"private-foreign@example.com"}]}';

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
                'WHM_API_LIVE_ACCOUNT' => DisposableAccountServer::USER,
                'WHM_API_LIVE_DOMAIN' => DisposableAccountServer::DOMAIN] as $key => $value
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

    public function testCompleteRunCreatesExercisesAndRemovesEverythingExactlyOnce(): void
    {
        $http = new DisposableAccountServer();
        $outcome = self::execute($http);

        self::assertSame('passed', $outcome['status']);
        self::assertSame('passed', $outcome['setup']);
        self::assertSame('passed', $outcome['exercise']);
        self::assertSame('passed', $outcome['cleanup']);
        self::assertSame([], $outcome['failures']);
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $outcome['account']);
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $outcome['token']);
        self::assertSame('confirmed_absent', $outcome['domain_cleanup']);
        self::assertSame('confirmed_absent', $outcome['zone_cleanup']);
        self::assertSame('not_claimed', $outcome['filesystem_cleanup']);
        self::assertSame([], $outcome['filesystem_residue']);
        self::assertSame(['status' => 'not_run', 'counts' => []], $outcome['filesystem_control']);
        self::assertSame(
            ['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'],
            $http->mutations,
        );
        self::assertFalse($http->account);
        self::assertFalse($http->zone);
        self::assertFalse($http->manualDomain);
        self::assertSame([], $http->tokens);
        self::assertPrivate($outcome);
    }

    public function testExerciseReceivesTheLiveTokenWhileTheAccountExists(): void
    {
        $http = new DisposableAccountServer();
        $received = null;
        $live = false;
        self::lifecycle($http)->run(static function (string $token) use ($http, &$received, &$live): void {
            $received = $token;
            $live = $http->account && $http->tokens !== [];
        });
        self::assertSame(DisposableAccountServer::TOKEN, $received);
        self::assertTrue($live);
    }

    public function testEveryRequestMatchesTheBindingsExactGuardAllowlist(): void
    {
        $http = new DisposableAccountServer();
        $account = self::account();
        self::lifecycle($http, null, $account)->run(self::noop());

        $posts = $account->controlPosts();
        foreach ($http->requests as $request) {
            $uri = (string) $request->getUri();
            if ($request->getMethod() === 'POST') {
                self::assertArrayHasKey($uri, $posts);
                self::assertSame($posts[$uri], (string) $request->getBody());
            } else {
                self::assertContains($uri, $account->controlReads());
                self::assertSame('', (string) $request->getBody());
            }
        }
        self::assertCount(count($posts), array_filter($http->requests, static fn (RequestInterface $request): bool =>
            $request->getMethod() === 'POST'));
    }

    public function testWritableAndReadOnlyTokensAreEachConfirmedAsRequested(): void
    {
        $writable = new DisposableAccountServer();
        self::assertSame('passed', self::execute($writable)['status']);
        self::assertFalse($writable->tokenReadOnly);

        $readOnly = new DisposableAccountServer();
        $outcome = self::lifecycle($readOnly, null, self::account(true))->run(self::noop());
        self::assertSame('passed', $outcome['status']);
        self::assertTrue($readOnly->tokenReadOnly);
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('unconfirmedWritableTokens')]
    public function testUnconfirmedWritableTokenNeverReachesTheExerciseButIsStillRevoked(array $fields): void
    {
        $http = new DisposableAccountServer();
        $http->tokenFields = $fields;
        $ran = false;
        $outcome = self::lifecycle($http)->run(static function (string $token) use (&$ran): void {
            $ran = true;
        });

        self::assertFalse($ran);
        self::assertSame('failed', $outcome['setup']);
        self::assertSame('not_run', $outcome['exercise']);
        self::assertSame(['setup_failure'], $outcome['failures']);
        self::assertSame('passed', $outcome['cleanup']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unconfirmedWritableTokens(): iterable
    {
        yield 'reported read-only' => [['readonly' => 1]];
        yield 'string flag' => [['readonly' => '0']];
        yield 'wrong expiry' => [['expires_at' => 12345]];
        yield 'no expiry' => [['expires_at' => null]];
        yield 'noncanonical expiry' => [['expires_at' => ' 2000000000']];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('unconfirmedReadOnlyTokens')]
    public function testUnconfirmedReadOnlyTokenNeverReachesTheExercise(array $fields): void
    {
        $http = new DisposableAccountServer();
        $http->tokenFields = $fields;
        $ran = false;
        $outcome = self::lifecycle($http, null, self::account(true))->run(
            static function (string $token) use (&$ran): void {
                $ran = true;
            },
        );

        self::assertFalse($ran);
        self::assertSame('failed', $outcome['setup']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unconfirmedReadOnlyTokens(): iterable
    {
        yield 'writable' => [['readonly' => 0]];
        yield 'string flag' => [['readonly' => '1']];
        yield 'boolean flag' => [['readonly' => true]];
        yield 'null flag' => [['readonly' => null]];
    }

    public function testNoTokenInTheReplyIsNeverRetriedAndIsStillRevokedByItsExactName(): void
    {
        $http = new DisposableAccountServer();
        $http->overrides['Tokens/create_full_access:1'] = '{}';
        $outcome = self::execute($http);

        self::assertSame('failed', $outcome['setup']);
        self::assertSame(1, $http->counts['Tokens/create_full_access']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame('confirmed_absent', $outcome['token']['cleanup']);
    }

    /** Requests of a refused setup: one absence read each, before anything could be created. */
    #[DataProvider('unprovenAbsence')]
    public function testUnprovenInitialAbsenceCreatesAndRemovesNothing(string $step, string $payload): void
    {
        $http = new DisposableAccountServer();
        $http->overrides[$step] = $payload;
        $ran = false;
        $outcome = self::lifecycle($http)->run(static function (string $token) use (&$ran): void {
            $ran = true;
        });

        self::assertFalse($ran);
        self::assertSame('failed', $outcome['status']);
        self::assertSame('failed', $outcome['setup']);
        self::assertSame('not_run', $outcome['exercise']);
        self::assertSame('not_required', $outcome['cleanup']);
        self::assertSame(['setup_failure'], $outcome['failures']);
        self::assertSame(['creation' => 'not_attempted', 'cleanup' => 'not_required'], $outcome['account']);
        self::assertSame([], $http->mutations);
        self::assertPrivate($outcome);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unprovenAbsence(): iterable
    {
        $other = '{"user":"other","domain":"other.test"}';
        yield 'existing account with no marker' => ['listaccts:1',
            '{"acct":[{"user":"wdisposable","domain":"wdisposable.example.com"}]}'];
        yield 'existing account on another domain' => ['listaccts:1',
            '{"acct":[{"user":"wdisposable","domain":"private.test"}]}'];
        yield 'another account owns the domain' => ['listaccts:2',
            '{"acct":[{"user":"other","domain":"wdisposable.example.com"}]}'];
        yield 'addon domain on another account' => ['get_domain_info:1',
            '{"domains":[{"user":"other","domain":"wdisposable.example.com"}]}'];
        yield 'existing zone' => ['listzones:1', '{"zone":[{"domain":"wdisposable.example.com"}]}'];
        yield 'empty account list' => ['listaccts:1', '{"acct":[]}'];
        yield 'empty account list at the domain read' => ['listaccts:2', '{"acct":[]}'];
        yield 'empty zone list' => ['listzones:1', '{"zone":[]}'];
        yield 'empty domain list' => ['get_domain_info:1', '{"domains":[]}'];
        yield 'empty object for accounts' => ['listaccts:1', '{"acct":{}}'];
        yield 'empty object for zones' => ['listzones:1', '{"zone":{}}'];
        yield 'empty object for domains' => ['get_domain_info:1', '{"domains":{}}'];
        yield 'null accounts' => ['listaccts:1', 'null'];
        yield 'duplicate account' => ['listaccts:1', '{"acct":[' . $other . ',' . $other . ']}'];
        yield 'account row with no domain' => ['listaccts:1', '{"acct":[{"user":"other"}]}'];
        yield 'duplicate zone' => ['listzones:1', '{"zone":[{"domain":"o.test"},{"domain":"o.test"}]}'];
        yield 'domain row with no owner' => ['get_domain_info:1', '{"domains":[{"domain":"o.test"}]}'];
    }

    #[DataProvider('failedInitialReads')]
    public function testFailedInitialReadIsNeverAbsenceAndNeverRepeated(string $step, string $mode): void
    {
        $http = new DisposableAccountServer();
        $http->failures[$step] = $mode;
        $outcome = self::execute($http);

        self::assertSame('not_required', $outcome['cleanup']);
        self::assertSame([], $http->mutations);
        self::assertSame(1, $http->counts[substr($step, 0, (int) strrpos($step, ':'))]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failedInitialReads(): iterable
    {
        foreach (['listaccts:1', 'get_domain_info:1', 'listzones:1'] as $step) {
            foreach (['throw_before', 'api_before', 'http_before', 'invalid_before'] as $mode) {
                yield $step . ' ' . $mode => [$step, $mode];
            }
        }
    }

    public function testExplicitRefusalOfCreationIsAKnownAbsence(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['createacct:1'] = 'api_before';
        $outcome = self::execute($http);

        self::assertSame('failed', $outcome['status']);
        self::assertSame('passed', $outcome['cleanup']);
        self::assertSame(['setup_failure'], $outcome['failures']);
        self::assertSame(['creation' => 'unconfirmed', 'cleanup' => 'confirmed_absent'], $outcome['account']);
        self::assertSame([], $http->mutations);
    }

    /** Only an explicit refusal is a known outcome; these cannot rule out a creation still in flight. */
    #[DataProvider('unusableCreationReplies')]
    public function testAnyOtherCreationFailureIsNeverReportedAsAbsent(string $failure): void
    {
        $http = new DisposableAccountServer();
        $http->failures['createacct:1'] = $failure . '_before';
        $outcome = self::execute($http);

        self::assertSame('failed', $outcome['status']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame(['creation' => 'unconfirmed', 'cleanup' => 'unconfirmed'], $outcome['account']);
        self::assertContains('account_creation_outcome_unknown', $outcome['failures']);
        self::assertSame([], $http->mutations);
        self::assertSame(1, $http->counts['createacct']);
        self::assertPrivate($outcome);
    }

    /** A creation still in flight would bring its domain and zone with it, so reading them gone proves nothing. */
    #[DataProvider('unusableCreationReplies')]
    public function testUnknownCreationNeverReportsTheDomainOrZoneAbsent(string $failure): void
    {
        $http = new DisposableAccountServer();
        $http->failures['createacct:1'] = $failure . '_before';
        $outcome = self::execute($http);

        self::assertSame('unconfirmed', $outcome['domain_cleanup']);
        self::assertSame('unconfirmed', $outcome['zone_cleanup']);
        self::assertContains('domain_absence_unconfirmed', $outcome['failures']);
        self::assertContains('zone_absence_unconfirmed', $outcome['failures']);
        self::assertSame(2, $http->counts['listzones']);
    }

    /** The same reasoning holds for files, and this proof sees nothing because no creation has landed yet. */
    #[DataProvider('unusableCreationReplies')]
    public function testUnknownCreationNeverReportsTheFilesystemAbsent(string $failure): void
    {
        $http = new DisposableAccountServer();
        $http->failures['createacct:1'] = $failure . '_before';
        $proof = new RecordingFilesystemProof([self::finding('confirmed_absent')]);
        $outcome = self::lifecycle($http, $proof)->run(self::noop());

        self::assertSame('unconfirmed', $outcome['filesystem_cleanup']);
        self::assertContains('filesystem_absence_unconfirmed', $outcome['failures']);
    }

    /** Unknown is a reason to doubt absence, not to hide residue that a list actually shows. */
    public function testUnknownCreationStillReportsWhatIsVisiblyPresent(): void
    {
        $http = new DisposableAccountServer();
        $http->concurrentContactEmail = DisposableAccountServer::FOREIGN_MARKER;
        $http->failures['createacct:1'] = 'throw_before';
        $outcome = self::execute($http);

        self::assertSame('present', $outcome['domain_cleanup']);
        self::assertSame('present', $outcome['zone_cleanup']);
    }

    /** Once the marker proves the account, the outcome is known, so its own removal can be proven as usual. */
    #[DataProvider('unusableCreationReplies')]
    public function testLostCreationTheMarkerProvesCanStillBeProvenGone(string $failure): void
    {
        $http = new DisposableAccountServer();
        $http->failures['createacct:1'] = $failure . '_after';
        $outcome = self::execute($http);

        self::assertSame('confirmed_absent', $outcome['domain_cleanup']);
        self::assertSame('confirmed_absent', $outcome['zone_cleanup']);
    }

    #[DataProvider('unusableCreationReplies')]
    public function testLostCreationThatTheMarkerProvesIsStillRemoved(string $failure): void
    {
        $http = new DisposableAccountServer();
        $http->failures['createacct:1'] = $failure . '_after';
        $outcome = self::execute($http);

        self::assertSame(1, $http->counts['createacct']);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $outcome['account']);
        self::assertNotContains('account_creation_outcome_unknown', $outcome['failures']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableCreationReplies(): iterable
    {
        yield 'lost response' => ['throw'];
        yield 'gateway error' => ['http'];
        yield 'unreadable body' => ['invalid'];
    }

    #[DataProvider('foreignCreations')]
    public function testAnotherRunsAccountIsNeverAdoptedOrRemoved(string $failure): void
    {
        $http = new DisposableAccountServer();
        $http->concurrentContactEmail = DisposableAccountServer::FOREIGN_MARKER;
        $http->failures['createacct:1'] = $failure;
        $outcome = self::execute($http);

        self::assertTrue($http->account);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertArrayNotHasKey('Tokens/create_full_access', $http->counts);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame(['creation' => 'unconfirmed', 'cleanup' => 'unconfirmed'], $outcome['account']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame('failed', $outcome['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignCreations(): iterable
    {
        yield 'refused because another run won' => ['api_before'];
        yield 'lost reply' => ['throw_before'];
    }

    /** A createacct success is not ownership: the marker read after it decides, and a foreign one stops setup. */
    public function testConfirmedCreationWithAForeignMarkerStopsBeforeAnyToken(): void
    {
        $http = new DisposableAccountServer();
        $http->overrides['listaccts:3'] = self::FOREIGN;
        $http->overrides['listaccts:4'] = self::FOREIGN;
        $outcome = self::execute($http);

        self::assertSame('failed', $outcome['setup']);
        self::assertArrayNotHasKey('Tokens/create_full_access', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame('unconfirmed', $outcome['account']['cleanup']);
        self::assertContains('account_identity_unconfirmed', $outcome['failures']);
    }

    /** @param array<string, string> $overrides */
    #[DataProvider('unusableMarkers')]
    public function testAnythingButTheExactMarkerRefusesEveryCleanupMutation(array $overrides): void
    {
        $http = new DisposableAccountServer();
        $http->overrides = $overrides;
        $outcome = self::execute($http);

        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertTrue($http->account);
        self::assertSame('unconfirmed', $outcome['account']['cleanup']);
        self::assertSame('unconfirmed', $outcome['token']['cleanup']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame('failed', $outcome['status']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function unusableMarkers(): iterable
    {
        $row = '{"acct":[{"user":"wdisposable","domain":"wdisposable.example.com"';
        yield 'missing' => [['listaccts:4' => $row . '}]}']];
        yield 'null' => [['listaccts:4' => $row . ',"email":null}]}']];
        yield 'list' => [['listaccts:4' => $row . ',"email":[]}]}']];
        yield 'foreign' => [['listaccts:4' => self::FOREIGN]];
        yield 'another domain' => [['listaccts:4' => '{"acct":[{"user":"wdisposable","domain":"o.test",'
            . '"email":"private@example.com"}]}']];
        yield 'empty list' => [['listaccts:4' => '{"acct":[]}']];
    }

    /** The marker alone is not enough: this exact row keeps the real marker and changes only the primary domain. */
    public function testExactMarkerOnAnotherPrimaryDomainIsNotOwnership(): void
    {
        $http = new DisposableAccountServer();
        $http->afterEffect = static function (DisposableAccountServer $server, string $step): void {
            if ($step === 'listaccts:3') {
                $server->listedDomain = 'private-other.test';
            }
        };
        $outcome = self::execute($http);

        self::assertArrayNotHasKey('Tokens/create_full_access', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertTrue($http->account);
        self::assertSame('unconfirmed', $outcome['account']['cleanup']);
        self::assertContains('account_identity_unconfirmed', $outcome['failures']);
    }

    public function testOwnershipIsReadAgainRightBeforeRemovingTheAccount(): void
    {
        $http = new DisposableAccountServer();
        $http->overrides['listaccts:6'] = self::FOREIGN;
        $outcome = self::execute($http);

        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame('unconfirmed', $outcome['account']['cleanup']);
        self::assertSame('confirmed_absent', $outcome['token']['cleanup']);
        self::assertContains('account_identity_unconfirmed', $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
    }

    /** The first gate, the token gate, the removal gate and the read after removal each get their own bound. */
    #[DataProvider('ownershipGates')]
    public function testLostOwnershipReadIsSurvivedAtEveryGate(int $read): void
    {
        $http = new DisposableAccountServer();
        $http->failures['listaccts:' . $read] = 'throw_before';
        $http->failures['listaccts:' . ($read + 1)] = 'throw_before';
        $outcome = self::execute($http);

        self::assertSame('passed', $outcome['status']);
        self::assertFalse($http->account);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        // Eight planned reads, plus the two that were lost.
        self::assertSame(10, $http->counts['listaccts']);
    }

    /** @return iterable<string, array{int}> */
    public static function ownershipGates(): iterable
    {
        yield 'first gate' => [4];
        yield 'token gate' => [5];
        yield 'removal gate' => [6];
        yield 'after removal' => [7];
    }

    public function testExhaustedOwnershipReadsStopAllMutationAtThreeReads(): void
    {
        $http = new DisposableAccountServer();
        foreach ([4, 5, 6] as $read) {
            $http->failures['listaccts:' . $read] = 'throw_before';
        }
        $outcome = self::execute($http);

        self::assertTrue($http->account);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame('unconfirmed', $outcome['account']['cleanup']);
        self::assertSame('unconfirmed', $outcome['token']['cleanup']);
        self::assertContains('account_identity_unconfirmed', $outcome['failures']);
        // Three setup reads, the three bounded cleanup reads, and the separate domain check.
        self::assertSame(7, $http->counts['listaccts']);
    }

    /** Only a request that failed is read again: anything the server returned is final. */
    #[DataProvider('finalIdentityReplies')]
    public function testAReturnedInventoryIsNeverReadAgain(string $mode): void
    {
        $http = new DisposableAccountServer();
        $http->failures['listaccts:4'] = $mode;
        $outcome = self::execute($http);

        // Three setup reads, the one final cleanup read, and the separate domain check.
        self::assertSame(5, $http->counts['listaccts']);
        self::assertContains('account_identity_unconfirmed', $outcome['failures']);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertTrue($http->account);
        self::assertPrivate($outcome);
    }

    /** @return iterable<string, array{string}> */
    public static function finalIdentityReplies(): iterable
    {
        yield 'result 0' => ['api_before'];
        yield 'gateway error' => ['http_before'];
        yield 'unreadable body' => ['invalid_before'];
    }

    public function testForeignMarkerIsNeverReadTwice(): void
    {
        $http = new DisposableAccountServer();
        $http->overrides['listaccts:4'] = self::FOREIGN;
        self::execute($http);
        self::assertSame(5, $http->counts['listaccts']);
    }

    /** Only the ownership gate repeats: token, domain and zone reads stay single reads even when the request is lost. */
    #[DataProvider('singleReads')]
    public function testOtherAbsenceReadsAreNeverRepeated(string $step, string $outcome, string $field): void
    {
        $http = new DisposableAccountServer();
        $http->failures[$step] = 'throw_before';
        $result = self::execute($http);
        $function = substr($step, 0, (int) strrpos($step, ':'));

        $planned = ['Tokens/list' => 4, 'listzones' => 2, 'get_domain_info' => 2];
        self::assertSame($planned[$function], $http->counts[$function]);
        self::assertContains($outcome, $result['failures']);
        self::assertSame('unconfirmed', $field === 'token' ? $result['token']['cleanup'] : $result[$field]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function singleReads(): iterable
    {
        yield 'token after revoke' => ['Tokens/list:4', 'token_absence_unconfirmed', 'token'];
        yield 'domain' => ['get_domain_info:2', 'domain_absence_unconfirmed', 'domain_cleanup'];
        yield 'zone' => ['listzones:2', 'zone_absence_unconfirmed', 'zone_cleanup'];
    }

    /**
     * This run created the token, so an unreadable listing cannot withhold its revoke; removal may not take it.
     *
     * Reads: setup absence, setup capability, then this gate.
     */
    #[DataProvider('unreadableListings')]
    public function testUnreadableListingStillRevokesTheTokenThisRunCreated(string $mode): void
    {
        $http = new DisposableAccountServer();
        $http->failures['Tokens/list:3'] = $mode;
        $http->failures['removeacct:1'] = 'api_before';
        $outcome = self::execute($http);

        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame([], $http->tokens);
        self::assertTrue($http->account);
        self::assertSame('confirmed_absent', $outcome['token']['cleanup']);
        self::assertSame('present', $outcome['account']['cleanup']);
        self::assertNotContains('token_absence_unconfirmed', $outcome['failures']);
        self::assertContains('account_remove_failure', $outcome['failures']);
        self::assertContains('account_still_present', $outcome['failures']);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableListings(): iterable
    {
        yield 'lost request' => ['throw_before'];
        yield 'result 0' => ['api_before'];
        yield 'gateway error' => ['http_before'];
        yield 'unreadable body' => ['invalid_before'];
    }

    public function testATokenNoReplyOrListingCanShowIsNeverRevoked(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['Tokens/create_full_access:1'] = 'throw_after';
        $http->failures['Tokens/list:2'] = 'throw_before';
        $http->failures['removeacct:1'] = 'api_before';
        $outcome = self::execute($http);

        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame(['creation' => 'unconfirmed', 'cleanup' => 'unconfirmed'], $outcome['token']);
        self::assertContains('token_absence_unconfirmed', $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
    }

    /** A create still in flight can land after a listing that shows nothing, exactly as for the account. */
    #[DataProvider('unusableCreationReplies')]
    public function testLostTokenCreationIsNeverReportedAsAbsent(string $failure): void
    {
        $http = new DisposableAccountServer();
        $http->failures['Tokens/create_full_access:1'] = $failure . '_before';
        $outcome = self::execute($http);

        self::assertSame(['creation' => 'unconfirmed', 'cleanup' => 'unconfirmed'], $outcome['token']);
        self::assertContains('token_creation_outcome_unknown', $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame(1, $http->counts['Tokens/create_full_access']);
        // The unknown token stops nothing else: the account still goes, and with it any late token.
        self::assertSame(1, $http->counts['removeacct']);
        self::assertFalse($http->account);
        self::assertPrivate($outcome);
    }

    public function testRefusedTokenCreationIsAKnownAbsence(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['Tokens/create_full_access:1'] = 'api_before';
        $outcome = self::execute($http);

        self::assertSame(['creation' => 'unconfirmed', 'cleanup' => 'confirmed_absent'], $outcome['token']);
        self::assertNotContains('token_creation_outcome_unknown', $outcome['failures']);
        self::assertSame('passed', $outcome['cleanup']);
    }

    public function testAListingThatShowsTheTokenProvesALostCreation(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['Tokens/create_full_access:1'] = 'throw_after';
        $outcome = self::execute($http);

        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $outcome['token']);
        self::assertNotContains('token_creation_outcome_unknown', $outcome['failures']);
    }

    public function testATokenListedAsAbsentSendsNoRevoke(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['Tokens/create_full_access:1'] = 'api_before';
        $outcome = self::execute($http);

        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame('confirmed_absent', $outcome['token']['cleanup']);
    }

    /** @param array<string, string> $failures */
    #[DataProvider('listingsAtALostGate')]
    public function testLostOwnershipAtTheTokenGateRevokesNothing(array $failures): void
    {
        $http = new DisposableAccountServer();
        $http->failures = $failures;
        $http->overrides['listaccts:5'] = self::FOREIGN;
        $http->overrides['listaccts:6'] = self::FOREIGN;
        $outcome = self::execute($http);

        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame([DisposableAccountServer::TOKEN_NAME], $http->tokens);
        self::assertSame('unconfirmed', $outcome['token']['cleanup']);
        self::assertContains('token_absence_unconfirmed', $outcome['failures']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function listingsAtALostGate(): iterable
    {
        yield 'listing shows the token' => [[]];
        yield 'listing unreadable' => [['Tokens/list:3' => 'throw_before']];
    }

    /** Neither mutation repeats, whatever its reply, and the other resources and checks still happen. */
    #[DataProvider('finalMutationFailures')]
    public function testFailedFinalMutationIsVisibleUnrepeatedAndStopsNothing(string $step, string $mode): void
    {
        $http = new DisposableAccountServer();
        $http->failures[$step] = $mode;
        $outcome = self::execute($http);

        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame('failed', $outcome['status']);
        self::assertSame(1, $http->counts[substr($step, 0, (int) strrpos($step, ':'))]);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame(2, $http->counts['listzones']);
        self::assertSame(2, $http->counts['get_domain_info']);
        self::assertPrivate($outcome);
    }

    /** @return iterable<string, array{string, string}> */
    public static function finalMutationFailures(): iterable
    {
        foreach (['Tokens/revoke:1', 'removeacct:1'] as $step) {
            foreach (self::MODES as $mode) {
                yield $step . ' ' . $mode => [$step, $mode];
            }
        }
    }

    #[DataProvider('finalInventoryFailures')]
    public function testUnusableFinalInventoryIsUnconfirmedAndStopsNoOtherCheck(
        string $step,
        string $payload,
        string $label,
    ): void {
        $http = new DisposableAccountServer();
        $http->overrides[$step] = $payload;
        $outcome = self::execute($http);

        self::assertContains($label, $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame(1, $http->counts['removeacct']);
        // The zone check is separate; the two domain reads are one check, so a failed first read skips the second.
        self::assertSame(2, $http->counts['listzones']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function finalInventoryFailures(): iterable
    {
        yield 'account object' => ['listaccts:7', '{"acct":{}}', 'account_identity_unconfirmed'];
        yield 'empty accounts after removal' => ['listaccts:7', '{"acct":[]}', 'account_identity_unconfirmed'];
        yield 'main domain' => ['listaccts:8', '{"acct":[{}]}', 'domain_absence_unconfirmed'];
        yield 'empty main domains' => ['listaccts:8', '{"acct":[]}', 'domain_absence_unconfirmed'];
        yield 'manual domain' => ['get_domain_info:2', '{"domains":[{}]}', 'domain_absence_unconfirmed'];
        yield 'empty manual domains' => ['get_domain_info:2', '{"domains":[]}', 'domain_absence_unconfirmed'];
        yield 'zone' => ['listzones:2', '{"zone":null}', 'zone_absence_unconfirmed'];
        yield 'empty zones' => ['listzones:2', '{"zone":[]}', 'zone_absence_unconfirmed'];
        yield 'token' => ['Tokens/list:4', '{}', 'token_absence_unconfirmed'];
    }

    public function testZoneResidueIsReportedWithoutAnyFurtherMutation(): void
    {
        $http = new DisposableAccountServer();
        $http->retainZone = true;
        $outcome = self::execute($http);

        self::assertSame('confirmed_absent', $outcome['account']['cleanup']);
        self::assertSame('present', $outcome['zone_cleanup']);
        self::assertContains('zone_still_present', $outcome['failures']);
        self::assertSame('failed', $outcome['status']);
        self::assertSame(
            ['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'],
            $http->mutations,
        );
    }

    public function testDomainResidueIsReportedWithoutAnyFurtherMutation(): void
    {
        $http = new DisposableAccountServer();
        $http->retainDomain = true;
        $outcome = self::execute($http);

        self::assertSame('present', $outcome['domain_cleanup']);
        self::assertContains('domain_still_present', $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
    }

    public function testAccountThatSurvivesRemovalIsReportedPresent(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['removeacct:1'] = 'http_before';
        $outcome = self::execute($http);

        self::assertSame('present', $outcome['account']['cleanup']);
        self::assertContains('account_still_present', $outcome['failures']);
        self::assertContains('account_remove_failure', $outcome['failures']);
        self::assertSame(1, $http->counts['removeacct']);
    }

    public function testRevokedTokenThatStillListsIsReportedPresent(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['Tokens/revoke:1'] = 'api_before';
        $outcome = self::execute($http);

        self::assertSame('present', $outcome['token']['cleanup']);
        self::assertContains('token_revoke_failure', $outcome['failures']);
        self::assertContains('token_still_present', $outcome['failures']);
        self::assertFalse($http->account);
    }

    public function testThrowingExerciseStillCleansUpAndFailsTheRun(): void
    {
        $http = new DisposableAccountServer();
        $outcome = self::lifecycle($http)->run(static function (string $token): void {
            throw new RuntimeException('private exercise failure');
        });

        self::assertSame('failed', $outcome['status']);
        self::assertSame('passed', $outcome['setup']);
        self::assertSame('failed', $outcome['exercise']);
        self::assertSame(['exercise_failure'], $outcome['failures']);
        self::assertSame('passed', $outcome['cleanup']);
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
        self::assertPrivate($outcome);
    }

    /** A recipe rethrows after recording its detail, so the lifecycle's status is the only one there is. */
    public function testNamedExerciseFailureFailsTheRunWithItsOwnLabels(): void
    {
        $http = new DisposableAccountServer();
        $outcome = self::lifecycle($http)->run(static function (string $token): void {
            throw new ExerciseFailure(['capture_failure', 'expectation_failure']);
        });

        self::assertSame('failed', $outcome['status']);
        self::assertSame('failed', $outcome['exercise']);
        self::assertSame('passed', $outcome['setup']);
        self::assertSame(['capture_failure', 'expectation_failure'], $outcome['failures']);
        self::assertSame('passed', $outcome['cleanup']);
        self::assertFalse($http->account);
    }

    public function testOwnedCleanupRunsAfterTheOwnershipGateWhileTheTokenStillWorks(): void
    {
        $http = new DisposableAccountServer();
        $live = false;
        $reads = 0;
        $outcome = self::lifecycle($http)->run(
            self::noop(),
            static function () use ($http, &$live, &$reads): array {
                $live = $http->account && $http->tokens !== [];
                $reads = $http->counts['listaccts'];

                return [];
            },
        );

        self::assertTrue($live);
        self::assertSame(4, $reads);
        self::assertSame('passed', $outcome['status']);
    }

    public function testOwnedCleanupRunsEvenAfterTheExerciseFailed(): void
    {
        $http = new DisposableAccountServer();
        $ran = false;
        self::lifecycle($http)->run(
            static function (string $token): void {
                throw new RuntimeException('private exercise failure');
            },
            static function () use (&$ran): array {
                $ran = true;

                return [];
            },
        );

        self::assertTrue($ran);
    }

    public function testOwnedCleanupFailuresFailTheRunAndStopNothing(): void
    {
        $http = new DisposableAccountServer();
        $outcome = self::lifecycle($http)->run(
            self::noop(),
            static fn (): array => ['fixture_directory_not_empty', 'directory_still_present'],
        );

        self::assertSame('failed', $outcome['status']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame(['fixture_directory_not_empty', 'directory_still_present'], $outcome['failures']);
        self::assertFalse($http->account);
        self::assertSame(1, $http->counts['Tokens/revoke']);
    }

    public function testThrowingOwnedCleanupStopsNeitherTheTokenNorTheAccount(): void
    {
        $http = new DisposableAccountServer();
        $outcome = self::lifecycle($http)->run(
            self::noop(),
            static function (): array {
                throw new RuntimeException('private cleanup failure');
            },
        );

        self::assertSame(['owned_resource_cleanup_failure'], $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertFalse($http->account);
        self::assertPrivate($outcome);
    }

    public function testOwnedCleanupNeverRunsOnAnAccountTheMarkerDoesNotProve(): void
    {
        $http = new DisposableAccountServer();
        $http->overrides['listaccts:4'] = self::FOREIGN;
        $ran = false;
        self::lifecycle($http)->run(self::noop(), static function () use (&$ran): array {
            $ran = true;

            return [];
        });

        self::assertFalse($ran);
    }

    public function testOwnedCleanupNeverRunsWhenSetupNeverCreatedAnything(): void
    {
        $http = new DisposableAccountServer();
        $http->overrides['listzones:1'] = '{"zone":[{"domain":"wdisposable.example.com"}]}';
        $ran = false;
        self::lifecycle($http)->run(self::noop(), static function () use (&$ran): array {
            $ran = true;

            return [];
        });

        self::assertFalse($ran);
    }

    public function testNoFilesystemProofLeavesTheClaimOpenWithoutFailingTheRun(): void
    {
        self::assertSame('not_claimed', self::execute(new DisposableAccountServer())['filesystem_cleanup']);
    }

    public function testConfirmedFilesystemAbsenceIsReportedAfterEveryApiCheck(): void
    {
        $http = new DisposableAccountServer();
        $seen = null;
        $proof = RecordingFilesystemProof::after(
            self::finding('confirmed_absent'),
            static function () use ($http, &$seen): void {
                $seen = [count($http->requests), $http->account, $http->zone, $http->manualDomain];
            },
        );
        $outcome = self::lifecycle($http, $proof)->run(self::noop());

        self::assertSame('passed', $outcome['status']);
        self::assertSame('confirmed_absent', $outcome['filesystem_cleanup']);
        self::assertSame([], $outcome['filesystem_residue']);
        // The proof comes last: nothing was still gone-or-not, and no request ever follows it.
        self::assertSame([count($http->requests), false, false, false], $seen);
    }

    public function testControlRunsOnceAfterSetupAndBeforeTheExercise(): void
    {
        $http = new DisposableAccountServer();
        $seen = [];
        $proof = RecordingFilesystemProof::after(
            self::finding('confirmed_absent'),
            static function () use ($http, &$seen): void {
                $seen[] = [$http->account, $http->tokens !== []];
            },
        );
        $ranAfterControl = false;
        $outcome = self::lifecycle($http, $proof)->run(
            static function (string $token) use ($proof, &$ranAfterControl): void {
                $ranAfterControl = count($proof->calls) === 1;
            },
        );

        // The control saw the account and its token; the final proof saw neither.
        self::assertSame([[true, true], [false, false]], $seen);
        self::assertTrue($ranAfterControl);
        self::assertSame(
            array_fill(0, 2, [DisposableAccountServer::USER, DisposableAccountServer::DOMAIN]),
            $proof->calls,
        );
    }

    public function testControlIsRecordedInLabelsAndCountsOnly(): void
    {
        $outcome = self::lifecycle(
            new DisposableAccountServer(),
            RecordingFilesystemProof::after(self::finding('confirmed_absent')),
        )->run(self::noop());

        self::assertSame(
            ['status' => 'passed', 'counts' => RecordingFilesystemProof::PRESENT['residue']],
            $outcome['filesystem_control'],
        );
        self::assertPrivate($outcome);
    }

    /** Nothing has shown how session files are named, so a control cannot require one. */
    public function testSessionsAreNeverRequiredByTheControlButAreStillRecorded(): void
    {
        $withSession = RecordingFilesystemProof::PRESENT;
        $withSession['residue']['sessions'] = 3;
        $outcome = self::lifecycle(
            new DisposableAccountServer(),
            new RecordingFilesystemProof([$withSession, self::finding('confirmed_absent')]),
        )->run(self::noop());

        self::assertSame('passed', $outcome['filesystem_control']['status']);
        self::assertSame(3, $outcome['filesystem_control']['counts']['sessions']);
        self::assertSame('confirmed_absent', $outcome['filesystem_cleanup']);
    }

    /**
     * A proof that cannot see the account while it exists is looking elsewhere, so its later absence means nothing.
     *
     * @param Finding|null $control
     */
    #[DataProvider('failedControls')]
    public function testFailedControlMeansALaterAbsenceIsNeverTrusted(?array $control): void
    {
        $http = new DisposableAccountServer();
        $ran = false;
        $outcome = self::lifecycle(
            $http,
            new RecordingFilesystemProof([$control, self::finding('confirmed_absent')]),
        )->run(static function (string $token) use (&$ran): void {
            $ran = true;
        });

        self::assertSame('failed', $outcome['filesystem_control']['status']);
        self::assertSame('unconfirmed', $outcome['filesystem_cleanup']);
        self::assertContains('filesystem_control_failed', $outcome['failures']);
        self::assertContains('filesystem_absence_unconfirmed', $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame('failed', $outcome['status']);
        // It costs only the claim: the exercise ran, and cleanup and its API proofs stayed exact.
        self::assertTrue($ran);
        self::assertSame('passed', $outcome['exercise']);
        self::assertSame('confirmed_absent', $outcome['account']['cleanup']);
        self::assertSame('confirmed_absent', $outcome['token']['cleanup']);
        self::assertSame(
            ['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'],
            $http->mutations,
        );
        self::assertPrivate($outcome);
    }

    /** @return iterable<string, array{Finding|null}> */
    public static function failedControls(): iterable
    {
        yield 'wrong machine sees nothing' => [self::finding('confirmed_absent')];
        yield 'proof unreachable' => [self::finding('unconfirmed')];
        yield 'proof threw' => [null];
        yield 'no location required is shown' => [['outcome' => 'present', 'residue' => ['sessions' => 1]]];
        yield 'every count but no present outcome' => [
            ['outcome' => 'unconfirmed', 'residue' => RecordingFilesystemProof::PRESENT['residue']],
        ];
        foreach (array_keys(RecordingFilesystemProof::PRESENT['residue']) as $location) {
            $partial = RecordingFilesystemProof::PRESENT;
            unset($partial['residue'][$location]);
            yield 'no ' . $location => [$partial];
        }
    }

    public function testFailedControlDoesNotHideRealResidue(): void
    {
        $proof = new RecordingFilesystemProof([
            self::finding('unconfirmed'),
            ['outcome' => 'present', 'residue' => ['home' => 1]],
        ]);
        $outcome = self::lifecycle(new DisposableAccountServer(), $proof)->run(self::noop());

        self::assertSame('present', $outcome['filesystem_cleanup']);
        self::assertSame(['home' => 1], $outcome['filesystem_residue']);
        self::assertContains('filesystem_residue_present', $outcome['failures']);
    }

    /** Setup failed, so no control ran: the one final read cannot be bound to the server that was changed. */
    #[DataProvider('setupsWithoutAControl')]
    public function testNoControlMeansNoTrustedAbsence(string $step, string $mode): void
    {
        $http = new DisposableAccountServer();
        $http->failures[$step] = $mode;
        $proof = new RecordingFilesystemProof([self::finding('confirmed_absent')]);
        $outcome = self::lifecycle($http, $proof)->run(self::noop());

        self::assertSame(['status' => 'not_run', 'counts' => []], $outcome['filesystem_control']);
        self::assertCount(1, $proof->calls);
        self::assertSame('unconfirmed', $outcome['filesystem_cleanup']);
        self::assertContains('filesystem_absence_unconfirmed', $outcome['failures']);
        self::assertNotContains('filesystem_control_failed', $outcome['failures']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function setupsWithoutAControl(): iterable
    {
        yield 'creation refused' => ['createacct:1', 'api_before'];
        yield 'token refused' => ['Tokens/create_full_access:1', 'api_before'];
        yield 'token capability read lost' => ['Tokens/list:2', 'throw_before'];
    }

    /** @param Finding|null $finding */
    #[DataProvider('failedFilesystemFindings')]
    public function testFilesystemFailureFailsTheClaimWithoutAnyMutationOrRetry(
        ?array $finding,
        string $outcomeLabel,
        string $failure,
    ): void {
        $http = new DisposableAccountServer();
        $proof = RecordingFilesystemProof::after($finding);
        $before = new DisposableAccountServer();
        self::execute($before);
        $outcome = self::lifecycle($http, $proof)->run(self::noop());

        self::assertSame($outcomeLabel, $outcome['filesystem_cleanup']);
        self::assertContains($failure, $outcome['failures']);
        self::assertSame('failed', $outcome['cleanup']);
        self::assertSame('failed', $outcome['status']);
        // A filesystem result changes the report and nothing else: same requests, same effects, one check.
        self::assertSame($before->counts, $http->counts);
        self::assertSame($before->mutations, $http->mutations);
        self::assertCount(2, $proof->calls);
        // The API results stay what the API showed; only the overall claim failed.
        self::assertSame('confirmed_absent', $outcome['account']['cleanup']);
        self::assertSame('confirmed_absent', $outcome['token']['cleanup']);
        self::assertSame('confirmed_absent', $outcome['domain_cleanup']);
        self::assertSame('confirmed_absent', $outcome['zone_cleanup']);
        self::assertPrivate($outcome);
    }

    /** @return iterable<string, array{Finding|null, string, string}> */
    public static function failedFilesystemFindings(): iterable
    {
        yield 'residue' => [
            ['outcome' => 'present', 'residue' => ['sessions' => 2]], 'present', 'filesystem_residue_present',
        ];
        yield 'unreachable' => [self::finding('unconfirmed'), 'unconfirmed', 'filesystem_absence_unconfirmed'];
        yield 'proof threw' => [null, 'unconfirmed', 'filesystem_absence_unconfirmed'];
    }

    public function testFilesystemResidueCountsReachTheReport(): void
    {
        $proof = RecordingFilesystemProof::after(['outcome' => 'present', 'residue' => ['home' => 1, 'sessions' => 2]]);
        $outcome = self::lifecycle(new DisposableAccountServer(), $proof)->run(self::noop());

        self::assertSame(['home' => 1, 'sessions' => 2], $outcome['filesystem_residue']);
    }

    /** Nothing was created, so there is nothing to claim and nothing worth a connection. */
    public function testFilesystemProofNeverRunsWhenNothingWasAttempted(): void
    {
        $http = new DisposableAccountServer();
        $http->overrides['listzones:1'] = '{"zone":[{"domain":"wdisposable.example.com"}]}';
        $proof = RecordingFilesystemProof::after(self::finding('confirmed_absent'));
        $outcome = self::lifecycle($http, $proof)->run(self::noop());

        self::assertSame([], $proof->calls);
        self::assertSame('not_claimed', $outcome['filesystem_cleanup']);
    }

    /** A failed API cleanup makes the read-only proof more useful, not less: it reports what really remains. */
    public function testFilesystemProofStillRunsAfterAFailedApiCleanup(): void
    {
        $http = new DisposableAccountServer();
        $http->failures['removeacct:1'] = 'api_before';
        $proof = RecordingFilesystemProof::after(['outcome' => 'present', 'residue' => ['home' => 1]]);
        $outcome = self::lifecycle($http, $proof)->run(self::noop());

        self::assertCount(2, $proof->calls);
        self::assertSame('present', $outcome['filesystem_cleanup']);
        self::assertSame('present', $outcome['account']['cleanup']);
    }

    public function testRunIsSingleUseBeforeAnyRequest(): void
    {
        $http = new DisposableAccountServer();
        $lifecycle = self::lifecycle($http);
        $lifecycle->run(self::noop());
        $requests = count($http->requests);

        try {
            $lifecycle->run(self::noop());
            $refused = false;
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertCount($requests, $http->requests);
    }

    #[DataProvider('liveOptIns')]
    public function testMissingOptInSendsNothing(string $key): void
    {
        $http = new DisposableAccountServer();
        $lifecycle = self::lifecycle($http);
        putenv($key . '=wrong');

        try {
            $lifecycle->run(self::noop());
            $refused = false;
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertSame([], $http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function liveOptIns(): iterable
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN'] as $key) {
            yield $key => [$key];
        }
    }

    public function testLifecycleNeverPrintsOrPersistsItsMarker(): void
    {
        $lifecycle = self::lifecycle(new DisposableAccountServer());
        self::assertSame([], $lifecycle->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($lifecycle);
    }

    /** @return Outcome */
    private static function execute(DisposableAccountServer $http): array
    {
        return self::lifecycle($http)->run(self::noop());
    }

    /** @return Closure(string): void */
    private static function noop(): Closure
    {
        return static function (string $token): void {
        };
    }

    private static function lifecycle(
        DisposableAccountServer $http,
        ?FilesystemAbsenceProof $filesystem = null,
        ?DisposableAccount $account = null,
    ): DisposableAccountLifecycle {
        $capture = new CaptureClient($http);
        $factory = new Psr17Factory();
        $root = new Client([
            'host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm', 'username' => 'root',
            'token' => 'WHMTOKEN',
        ], new HttpTransport($capture, $factory, $factory));

        return new DisposableAccountLifecycle($account ?? self::account(), $capture, $root, $filesystem);
    }

    private static function account(bool $readOnlyToken = false): DisposableAccount
    {
        return DisposableAccount::fresh(
            DisposableAccountServer::USER,
            DisposableAccountServer::DOMAIN,
            DisposableAccountServer::TOKEN_NAME,
            900,
            $readOnlyToken,
        );
    }

    /**
     * @param 'confirmed_absent'|'present'|'unconfirmed' $outcome
     * @return Finding
     */
    private static function finding(string $outcome): array
    {
        return ['outcome' => $outcome, 'residue' => []];
    }

    /** @param array<mixed> $outcome */
    private static function assertPrivate(array $outcome): void
    {
        $json = json_encode($outcome, JSON_THROW_ON_ERROR);
        $private = ['private', 'WHMTOKEN', 'PRIVATEDISPOSABLETOKEN', 'wdisposable', 'example.com', 'fixture-'];
        foreach ($private as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }
}
