<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\FiniteQuotaEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\FiniteQuotaFixture;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** @phpstan-import-type Result from FiniteQuotaEvidence */
final class FiniteQuotaFixtureTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'wqfinite', 'WHM_API_LIVE_DOMAIN' => 'wqfinite.example.com'] as $key => $value
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

    public function testCreatesFiniteAccountAndReadonlyTokenThenVerifiesIndependentCleanup(): void
    {
        $http = new FiniteQuotaServer();
        $before = time();
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());

        self::assertSame('passed', $report['fixture']['status']);
        self::assertSame('passed', $report['fixture']['cleanup']);
        self::assertSame('confirmed_absent', $report['fixture']['domain_cleanup']);
        self::assertSame('confirmed_absent', $report['fixture']['zone_cleanup']);
        self::assertSame([], $report['fixture']['failures']);
        self::assertFalse($http->account);
        self::assertFalse($http->zone);
        self::assertSame([], $http->tokens);
        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http->mutations);
        self::assertSame(1, $http->counts['direct:Quota/get_quota_info']);
        self::assertSame(1, $http->counts['Tokens/create_full_access']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame(1, $http->counts['removeacct']);
        foreach ($report['fixture']['resources'] as $resource) {
            self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $resource);
        }
        $create = array_values(array_filter($http->requests, static fn (RequestInterface $request): bool =>
            str_contains((string) $request->getUri(), 'cpanel.function=create_full_access')))[0];
        $parameters = [];
        parse_str((string) $create->getBody(), $parameters);
        self::assertSame('1', $parameters['readonly']);
        self::assertIsString($parameters['expires_at']);
        self::assertGreaterThanOrEqual($before + 3600, (int) $parameters['expires_at']);
        self::assertLessThanOrEqual(time() + 3600, (int) $parameters['expires_at']);
        self::assertPrivate($report);
    }

    public function testDiscrepanciesDoNotFalsifySuccessfulFixtureAndCleanupOutcomes(): void
    {
        $report = (new FiniteQuotaFixture(new FiniteQuotaServer(), 'private-whm-token'))->run(self::capture());
        self::assertSame('passed', $report['fixture']['status']);
        self::assertSame('failed', $report['discrepancy_review']['status']);
    }

    #[DataProvider('requiredEnvironment')]
    public function testMissingOrWrongTargetConfigurationPreventsAllRequests(string $key): void
    {
        $http = new FiniteQuotaServer();
        $fixture = new FiniteQuotaFixture($http, 'private-whm-token');
        putenv($key . '=wrong');
        $report = $fixture->run(self::capture());
        self::assertSame('failed', $report['fixture']['status']);
        self::assertSame('not_started', $report['fixture']['setup']);
        self::assertSame('not_required', $report['fixture']['cleanup']);
        self::assertSame([], $http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredEnvironment(): iterable
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('preflightFailures')]
    public function testUnconfirmedInitialAbsenceNeverCreatesOrDeletesAnything(string $step, string $payload): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides[$step] = $payload;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['setup']);
        self::assertSame('not_run', $report['fixture']['capture']);
        self::assertSame([], $http->mutations);
    }

    /** @return iterable<string, array{string, string}> */
    public static function preflightFailures(): iterable
    {
        yield 'existing account' => ['listaccts:1',
            '{"acct":[{"user":"wqfinite","domain":"wqfinite.example.com"}]}'];
        yield 'domain owned elsewhere' => ['listaccts:2',
            '{"acct":[{"user":"other","domain":"wqfinite.example.com"}]}'];
        yield 'existing zone' => ['listzones:1', '{"zone":[{"domain":"wqfinite.example.com"}]}'];
        yield 'addon domain elsewhere' => ['get_domain_info:1',
            '{"domains":[{"user":"other","domain":"wqfinite.example.com"}]}'];
        yield 'manual domains object' => ['get_domain_info:1', '{"domains":{}}'];
        yield 'manual domains missing owner' => ['get_domain_info:1', '{"domains":[{"domain":"other.test"}]}'];
        yield 'manual domains duplicate' => ['get_domain_info:1',
            '{"domains":[{"domain":"other.test","user":"other"},{"domain":"other.test","user":"other"}]}'];
        yield 'object not account list' => ['listaccts:1', '{"acct":{}}'];
        yield 'null account payload' => ['listaccts:1', 'null'];
        yield 'null account list' => ['listaccts:1', '{"acct":null}'];
        yield 'duplicate unrelated account' => ['listaccts:1',
            '{"acct":[{"user":"other","domain":"other.test"},{"user":"other","domain":"also.test"}]}'];
        yield 'missing account domain' => ['listaccts:1', '{"acct":[{"user":"wqfinite"}]}'];
        yield 'unrelated missing domain' => ['listaccts:1', '{"acct":[{"user":"other"}]}'];
        yield 'object not zone list' => ['listzones:1', '{"zone":{}}'];
        yield 'missing zone identity' => ['listzones:1', '{"zone":[{}]}'];
        yield 'duplicate zone identity' => ['listzones:1',
            '{"zone":[{"domain":"other.test"},{"domain":"other.test"}]}'];
    }

    #[DataProvider('inventoryRequestFailures')]
    public function testFailedFullAccountInventoryIsNeverTreatedAsAbsence(string $mode): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['listaccts:1'] = $mode;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['setup']);
        self::assertSame('not_required', $report['fixture']['cleanup']);
        self::assertSame([], $http->mutations);
        self::assertCount(1, $http->requests);
        self::assertPrivate($report);
    }

    /** @return iterable<string, array{string}> */
    public static function inventoryRequestFailures(): iterable
    {
        yield 'API error' => ['api_before'];
        yield 'transport failure' => ['throw_before'];
    }

    #[DataProvider('creationFailures')]
    public function testFailedCreationReconcilesItsEffectsWithoutRetrying(string $step, string $mode): void
    {
        $http = new FiniteQuotaServer();
        $http->failures[$step] = $mode;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['setup']);
        self::assertSame('not_run', $report['fixture']['capture']);
        self::assertFalse($http->account);
        self::assertFalse($http->zone);
        self::assertSame([], $http->tokens);
        self::assertSame(1, $http->counts[substr($step, 0, -2)]);
        self::assertPrivate($report);
    }

    /** @return iterable<string, array{string, string}> */
    public static function creationFailures(): iterable
    {
        foreach (['createacct:1', 'Tokens/create_full_access:1'] as $step) {
            foreach (['throw_before', 'throw_after', 'api_before', 'api_after'] as $mode) {
                yield $step . ' ' . $mode => [$step, $mode];
            }
        }
    }

    public function testUncertainAccountOwnershipDoesNotPermitDeletion(): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['createacct:1'] = 'throw_after';
        $http->overrides['listaccts:3'] = '{"acct":[{"user":"wqfinite","domain":"other.test"}]}';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertTrue($http->account);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('present', $report['fixture']['domain_cleanup']);
        self::assertSame('present', $report['fixture']['zone_cleanup']);
    }

    public function testMissingReturnedTokenIsNotRecreatedAndIsCleanedByItsExactName(): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides['Tokens/create_full_access:1'] = '{}';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['setup']);
        self::assertSame(1, $http->counts['Tokens/create_full_access']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertFalse($http->account);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['token']['cleanup']);
    }

    public function testUnusableTokenInventoryCannotAuthorizeRevocationButAccountCleanupContinues(): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['Tokens/create_full_access:1'] = 'throw_after';
        $http->overrides['Tokens/list:2'] = '[{}]';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertFalse($http->account);
        self::assertSame('unconfirmed', $report['fixture']['resources']['token']['cleanup']);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('failed', $report['fixture']['cleanup']);
    }

    #[DataProvider('unconfirmedTokenCapabilities')]
    public function testUnconfirmedReadonlyExpirySkipsCaptureButIdentityCleanupStillWorks(
        string $field,
        mixed $value,
    ): void {
        $http = new FiniteQuotaServer();
        $http->tokenFields[$field] = $value;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['setup']);
        self::assertSame('not_run', $report['fixture']['capture']);
        self::assertSame('passed', $report['fixture']['cleanup']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame(1, $http->counts['Tokens/create_full_access']);
        self::assertArrayNotHasKey('direct:Quota/get_quota_info', $http->counts);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unconfirmedTokenCapabilities(): iterable
    {
        yield 'writable' => ['readonly', 0];
        yield 'string flag' => ['readonly', '1'];
        yield 'null flag' => ['readonly', null];
        yield 'boolean flag' => ['readonly', true];
        yield 'never expires' => ['expires_at', null];
        yield 'wrong expiration' => ['expires_at', 12345];
        yield 'string expiration' => ['expires_at', '12345'];
    }

    public function testExactDecimalExpiryStringAllowsCaptureAndConfirmedCleanup(): void
    {
        $http = new FiniteQuotaServer();
        $http->expirationWireValue = static fn (int $expected): string => (string) $expected;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('passed', $report['fixture']['status']);
        self::assertSame('passed', $report['fixture']['capture']);
        self::assertSame('passed', $report['fixture']['cleanup']);
        self::assertSame(1, $http->counts['direct:Quota/get_quota_info']);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['token']['cleanup']);
        self::assertPrivate($report);
    }

    /** @param Closure(int): mixed $wireValue */
    #[DataProvider('noncanonicalExpiryValues')]
    public function testEquivalentNumericExpiryFormatsRemainRejected(Closure $wireValue): void
    {
        $http = new FiniteQuotaServer();
        $http->expirationWireValue = $wireValue;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['setup']);
        self::assertSame('not_run', $report['fixture']['capture']);
        self::assertSame('passed', $report['fixture']['cleanup']);
        self::assertArrayNotHasKey('direct:Quota/get_quota_info', $http->counts);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{Closure(int): mixed}> */
    public static function noncanonicalExpiryValues(): iterable
    {
        yield 'leading zero' => [static fn (int $expected): string => '0' . $expected];
        yield 'leading whitespace' => [static fn (int $expected): string => ' ' . $expected];
        yield 'trailing whitespace' => [static fn (int $expected): string => $expected . ' '];
        yield 'exponent' => [static fn (int $expected): string => $expected . 'e0'];
        yield 'decimal string' => [static fn (int $expected): string => $expected . '.0'];
        yield 'float' => [static fn (int $expected): float => (float) $expected];
        yield 'different decimal string' => [static fn (int $expected): string => (string) ($expected + 1)];
    }

    public function testUnconfirmedAccountAtCleanupCannotReportTokenCleanupUnneeded(): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides['listaccts:4'] = '{"acct":[{}]}';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('unconfirmed', $report['fixture']['resources']['token']['cleanup']);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('failed', $report['fixture']['cleanup']);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
    }

    public function testConcurrentCreationOfTheSameFixedTargetDoesNotAuthorizeRemovingAnotherRunsAccount(): void
    {
        $http = new FiniteQuotaServer();
        $http->concurrentContactEmail = 'finite-00000000000000000000000000000000@wqfinite.example.com';
        $http->failures['createacct:1'] = 'api_before';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertTrue($http->account);
        self::assertTrue($http->zone);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['creation']);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['cleanup']);
        self::assertArrayNotHasKey('Tokens/create_full_access', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertPrivate($report);
    }

    #[DataProvider('unconfirmedOwnership')]
    public function testMissingMalformedOrForeignOwnershipMarkerRefusesAllCleanupMutations(string $fields): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides['listaccts:4'] = '{"acct":[{"user":"wqfinite",'
            . '"domain":"wqfinite.example.com"' . $fields . '}]}';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertTrue($http->account);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('unconfirmed', $report['fixture']['resources']['token']['cleanup']);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertArrayNotHasKey('removeacct', $http->counts);
    }

    /** @return iterable<string, array{string}> */
    public static function unconfirmedOwnership(): iterable
    {
        yield 'missing' => [''];
        yield 'null' => [',"email":null'];
        yield 'list' => [',"email":[]'];
        yield 'foreign' => [',"email":"private-foreign@example.com"'];
    }

    public function testOwnershipIsRecheckedBeforeRemovingTheAccountAfterTokenCleanup(): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides['listaccts:6'] = '{"acct":[{"user":"wqfinite",'
            . '"domain":"wqfinite.example.com","email":"private-foreign@example.com"}]}';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['token']['cleanup']);
        self::assertSame('failed', $report['fixture']['cleanup']);
    }

    #[DataProvider('finalInventoryFailures')]
    public function testUnusableFinalInventoryCannotClaimAbsenceOrStopOtherChecks(string $step, string $payload): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides[$step] = $payload;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['cleanup']);
        self::assertSame('failed', $report['fixture']['status']);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame(8, $http->counts['listaccts']);
        self::assertSame(2, $http->counts['listzones']);
        self::assertFalse($http->account);
        self::assertPrivate($report);
    }

    /** @return iterable<string, array{string, string}> */
    public static function finalInventoryFailures(): iterable
    {
        yield 'account' => ['listaccts:7', '{"acct":{}}'];
        yield 'main domain' => ['listaccts:8', '{"acct":[{}]}'];
        yield 'manual domain' => ['get_domain_info:2', '{"domains":[{}]}'];
        yield 'zone' => ['listzones:2', '{"zone":null}'];
        yield 'token' => ['Tokens/list:4', '{}'];
    }

    #[DataProvider('cleanupFailures')]
    public function testCleanupFailuresRemainVisibleAndDoNotStopIndependentChecks(string $step, string $mode): void
    {
        $http = new FiniteQuotaServer();
        $http->failures[$step] = $mode;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['cleanup']);
        self::assertSame('failed', $report['fixture']['status']);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame(8, $http->counts['listaccts']);
        self::assertSame(2, $http->counts['listzones']);
        self::assertSame(2, $http->counts['get_domain_info']);
        self::assertSame(1, $http->counts[substr($step, 0, -2)]);
        if (str_starts_with($step, 'Tokens/')) {
            self::assertFalse($http->account);
        }
        self::assertPrivate($report);
    }

    /** @return iterable<string, array{string, string}> */
    public static function cleanupFailures(): iterable
    {
        foreach (['Tokens/revoke:1', 'removeacct:1'] as $step) {
            foreach (['throw_before', 'throw_after', 'api_before', 'api_after'] as $mode) {
                yield $step . ' ' . $mode => [$step, $mode];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unusableCreationResponses(): iterable
    {
        yield 'lost response' => ['throw'];
        yield 'gateway error' => ['http'];
        yield 'unreadable body' => ['invalid'];
    }

    /** Only an explicit refusal is a known outcome; these replies cannot rule out creation still in flight. */
    #[DataProvider('unusableCreationResponses')]
    public function testUnusableCreationResponseIsNeverReportedAsAbsent(string $failure): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['createacct:1'] = $failure . '_before';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['creation']);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('failed', $report['fixture']['cleanup']);
        self::assertSame('failed', $report['fixture']['status']);
        self::assertContains('account_creation_outcome_unknown', $report['fixture']['failures']);
        self::assertSame([], $http->mutations);
        self::assertPrivate($report);
    }

    #[DataProvider('unusableCreationResponses')]
    public function testUnusableCreationResponseStillRemovesTheAccountItsMarkerProves(string $failure): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['createacct:1'] = $failure . '_after';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame('confirmed', $report['fixture']['resources']['account']['creation']);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['account']['cleanup']);
        self::assertNotContains('account_creation_outcome_unknown', $report['fixture']['failures']);
        self::assertFalse($http->account);
    }

    public function testRefusedCreationIsAbsentAndRemovesNothing(): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['createacct:1'] = 'api_before';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame(['setup_failure'], $report['fixture']['failures']);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('passed', $report['fixture']['cleanup']);
        self::assertSame([], $http->mutations);
    }

    /**
     * @param array<string, string> $overrides
     * @param 'account'|'zone_cleanup' $field
     */
    #[DataProvider('emptyIdentityLists')]
    public function testAnEmptyIdentityListCannotProveAbsence(array $overrides, string $field): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides = $overrides;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        $outcome = $field === 'account'
            ? $report['fixture']['resources']['account']['cleanup'] : $report['fixture'][$field];
        self::assertSame('unconfirmed', $outcome);
        self::assertSame('failed', $report['fixture']['cleanup']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function emptyIdentityLists(): iterable
    {
        yield 'accounts during cleanup' => [['listaccts:4' => '{"acct":[]}'], 'account'];
        yield 'zones after removal' => [['listzones:2' => '{"zone":[]}'], 'zone_cleanup'];
    }

    #[DataProvider('emptyInitialLists')]
    public function testAnEmptyInitialInventoryCreatesNothing(string $step, string $payload): void
    {
        $http = new FiniteQuotaServer();
        $http->overrides[$step] = $payload;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('failed', $report['fixture']['setup']);
        self::assertSame([], $http->mutations);
    }

    /** @return iterable<string, array{string, string}> */
    public static function emptyInitialLists(): iterable
    {
        yield 'accounts' => ['listaccts:1', '{"acct":[]}'];
        yield 'zones' => ['listzones:1', '{"zone":[]}'];
        yield 'manual domains' => ['get_domain_info:1', '{"domains":[]}'];
    }

    /** This run created the token, so an unreadable listing cannot withhold its revoke; removal may not take it. */
    #[DataProvider('unreadableTokenListings')]
    public function testUnreadableTokenListingStillRevokesTheTokenThisRunCreated(string $mode): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['Tokens/list:3'] = $mode;
        $http->failures['removeacct:1'] = 'api_before';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame(1, $http->counts['Tokens/revoke']);
        self::assertSame([], $http->tokens);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['token']['cleanup']);
        self::assertSame('present', $report['fixture']['resources']['account']['cleanup']);
        self::assertNotContains('token_absence_unconfirmed', $report['fixture']['failures']);
        self::assertContains('account_remove_failure', $report['fixture']['failures']);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableTokenListings(): iterable
    {
        yield 'lost request' => ['throw_before'];
        yield 'result 0' => ['api_before'];
        yield 'gateway error' => ['http_before'];
        yield 'unreadable body' => ['invalid_before'];
    }

    public function testTransientOwnershipReadCannotAbandonTheAccountOrItsToken(): void
    {
        $http = new FiniteQuotaServer();
        $http->failures['listaccts:4'] = 'throw_before';
        $http->failures['listaccts:5'] = 'throw_before';
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('passed', $report['fixture']['status']);
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
        self::assertSame(1, $http->counts['removeacct']);
    }

    public function testExhaustedOwnershipReadsStopAllMutation(): void
    {
        $http = new FiniteQuotaServer();
        foreach ([4, 5, 6] as $count) {
            $http->failures['listaccts:' . $count] = 'throw_before';
        }
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertTrue($http->account);
        self::assertArrayNotHasKey('removeacct', $http->counts);
        self::assertArrayNotHasKey('Tokens/revoke', $http->counts);
        self::assertSame('unconfirmed', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('failed', $report['fixture']['cleanup']);
        // Three setup reads, the three bounded cleanup reads, and the separate domain check.
        self::assertSame(7, $http->counts['listaccts']);
    }

    public function testFilesystemCleanupStaysUnclaimedWithoutTheProof(): void
    {
        $report = (new FiniteQuotaFixture(new FiniteQuotaServer(), 'private-whm-token'))->run(self::capture());
        self::assertSame('not_claimed', $report['fixture']['filesystem_cleanup']);
        self::assertSame([], $report['fixture']['filesystem_residue']);
    }

    public function testFilesystemProofRunsOnceOnTheExactTargetAfterCleanup(): void
    {
        $http = new FiniteQuotaServer();
        $proof = new RecordingFilesystemProof(['outcome' => 'confirmed_absent', 'residue' => []]);
        $report = (new FiniteQuotaFixture($http, 'private-whm-token', $proof))->run(self::capture());
        self::assertSame('passed', $report['fixture']['status']);
        self::assertSame('confirmed_absent', $report['fixture']['filesystem_cleanup']);
        self::assertSame([['wqfinite', 'wqfinite.example.com']], $proof->calls);
    }

    public function testUnreachableFilesystemProofFailsTheClaimWithoutAnyMutation(): void
    {
        $http = new FiniteQuotaServer();
        $proof = new RecordingFilesystemProof(['outcome' => 'unconfirmed', 'residue' => []]);
        $report = (new FiniteQuotaFixture($http, 'private-whm-token', $proof))->run(self::capture());
        self::assertSame('unconfirmed', $report['fixture']['filesystem_cleanup']);
        self::assertSame('failed', $report['fixture']['status']);
        self::assertSame('failed', $report['fixture']['cleanup']);
        self::assertContains('filesystem_absence_unconfirmed', $report['fixture']['failures']);
        self::assertSame('confirmed_absent', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http->mutations);
    }

    public function testFilesystemResidueIsReportedByLabelAndCount(): void
    {
        $proof = new RecordingFilesystemProof(['outcome' => 'present', 'residue' => ['sessions' => 2]]);
        $report = (new FiniteQuotaFixture(new FiniteQuotaServer(), 'private-whm-token', $proof))->run(self::capture());
        self::assertSame('present', $report['fixture']['filesystem_cleanup']);
        self::assertSame(['sessions' => 2], $report['fixture']['filesystem_residue']);
        self::assertContains('filesystem_residue_present', $report['fixture']['failures']);
        self::assertPrivate($report);
    }

    public function testReportNamesTheCurrentScenarioVersion(): void
    {
        $report = (new FiniteQuotaFixture(new FiniteQuotaServer(), 'private-whm-token'))->run(self::capture());
        self::assertSame('finite-quota-disposable-v2', $report['scenario']);
    }

    public function testInstanceIsSingleUse(): void
    {
        $http = new FiniteQuotaServer();
        $fixture = new FiniteQuotaFixture($http, 'private-whm-token');
        $fixture->run(self::capture());
        $requests = count($http->requests);
        $second = $fixture->run(self::capture());
        self::assertSame(['configuration_failure'], $second['fixture']['failures']);
        self::assertCount($requests, $http->requests);
    }

    public function testZoneResidueIsReportedAfterTheAccountIsGoneWithoutFurtherMutations(): void
    {
        $http = new FiniteQuotaServer();
        $http->retainZone = true;
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture());
        self::assertSame('confirmed_absent', $report['fixture']['resources']['account']['cleanup']);
        self::assertSame('present', $report['fixture']['zone_cleanup']);
        self::assertSame('failed', $report['fixture']['status']);
        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http->mutations);
    }

    public function testCaptureFailureStillRevokesTokenAndRemovesTheAccount(): void
    {
        $http = new FiniteQuotaServer();
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(
            static function (ClientInterface $guarded, string $token): array {
                throw new RuntimeException('private callback failure');
            },
        );
        self::assertSame('failed', $report['fixture']['capture']);
        self::assertSame('passed', $report['fixture']['cleanup']);
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
        self::assertPrivate($report);
    }

    public function testFailedFiniteExpectationsDoNotDiscardEvidenceAndStillCleanUp(): void
    {
        $http = new FiniteQuotaServer();
        $report = (new FiniteQuotaFixture($http, 'private-whm-token'))->run(self::capture('failed'));
        self::assertSame('failed', $report['fixture']['expectations']);
        self::assertSame('passed', $report['fixture']['capture']);
        self::assertSame('passed', $report['fixture']['cleanup']);
        self::assertSame('binary-mib', $report['unit_relationships']['direct-uapi']);
        self::assertSame('failed', $report['fixture']['status']);
    }

    /**
     * @param 'passed'|'failed' $expectations
     * @return Closure(ClientInterface, string): Result
     */
    private static function capture(string $expectations = 'passed'): Closure
    {
        return static function (ClientInterface $guarded, string $token) use ($expectations): array {
            self::assertSame('private-fixture-token', $token);
            $guarded->sendRequest(new Request('GET', 'https://test.oblak.host:2083/execute/Quota/get_quota_info', [
                'Authorization' => 'cpanel wqfinite:' . $token, 'Accept' => 'application/json',
            ]));

            return [
                'probes' => [], 'expectations' => $expectations,
                'unit_relationships' => ['direct-uapi' => 'binary-mib', 'bridged-uapi' => 'binary-mib'],
                'discrepancy_review' => FiniteQuotaEvidence::assess([]),
            ];
        };
    }

    /** @param array<mixed> $report */
    private static function assertPrivate(array $report): void
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private', 'wqfinite', 'example.com', '1790000000'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }
}
