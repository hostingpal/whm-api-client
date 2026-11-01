<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\CallResult;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Tests\Support\LiveCurlClient;
use HostingPal\WhmApiClient\Tests\Support\LiveTestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Read-only live verification of the account inventory and the POST request path.
 *
 * Every request is read-only: account listing, searches that match and that match
 * nothing, one account summary, a summary of a missing account, the package list,
 * and the same read-only operations over POST on all three routes, which is the
 * runtime evidence for form-encoded execution. Failures report only safe
 * predicates, exception classes, and numeric statuses.
 */
#[Group('live')]
final class AccountsLiveTest extends TestCase
{
    /** A username that no account on the test server uses. */
    private const MISSING_ACCOUNT = 'nosuchacct9dy';

    private Client $whm;

    private Client $cpanel;

    protected function setUp(): void
    {
        try {
            $tokens = LiveTestEnvironment::fromEnvironment();
        } catch (RuntimeException $error) {
            self::fail($error->getMessage());
        }

        $factory = new Psr17Factory();
        $transport = new HttpTransport(new LiveCurlClient(), $factory, $factory);
        $this->whm = self::safeOperation(static fn (): Client => new Client([
            'host' => 'test.oblak.host',
            'port' => 2087,
            'authType' => 'whm',
            'username' => 'root',
            'token' => $tokens['whm'],
        ], $transport));
        $this->cpanel = self::safeOperation(static fn (): Client => new Client([
            'host' => 'test.oblak.host',
            'port' => 2083,
            'authType' => 'cpanel',
            'username' => 'npr',
            'token' => $tokens['cpanel'],
        ], $transport));
    }

    public function testAccountListContainsTheKnownAccount(): void
    {
        $whm = $this->whm->whm();
        $accounts = self::safeOperation(static fn (): array => $whm->accounts()->listAccounts());

        self::assertTrue(count($accounts) > 0, 'The server must list at least one account.');
        $known = false;
        foreach ($accounts as $account) {
            $known = $known || (($account['user'] ?? null) === 'npr' && ($account['domain'] ?? null) === 'npr.rs');
        }

        self::assertTrue($known, 'The account list must include the known test account.');
        self::assertWhmMetadata($whm->responseMeta(), 'listaccts');
    }

    public function testFilteredAccountListReturnsOnlyTheKnownAccount(): void
    {
        $whm = $this->whm->whm();
        $accounts = self::safeOperation(static fn (): array => $whm->accounts()->listAccounts(
            search: 'npr',
            searchtype: 'user',
            searchmethod: 'exact',
        ));

        self::assertTrue(count($accounts) === 1, 'An exact user search must return exactly one record.');
        self::assertTrue(($accounts[0]['user'] ?? null) === 'npr', 'The filtered record must be the known account.');
        self::assertWhmMetadata($whm->responseMeta(), 'listaccts');
    }

    public function testMatchingSearchWithWantReturnsOnlyTheRequestedField(): void
    {
        $whm = $this->whm->whm();
        $accounts = self::safeOperation(static fn (): array => $whm->accounts()->listAccounts(
            search: 'npr',
            searchtype: 'user',
            searchmethod: 'exact',
            want: 'user',
        ));

        self::assertTrue($accounts === [['user' => 'npr']], 'want must narrow the matching record to the user field.');
        self::assertWhmMetadata($whm->responseMeta(), 'listaccts');
    }

    /** WHM omits data and reports success here; the search filter is a name that no account uses. */
    public function testRegexSearchWithoutMatchesReturnsAnEmptyList(): void
    {
        $whm = $this->whm->whm();
        foreach ([null, 'user'] as $want) {
            $accounts = self::safeOperation(static fn (): array => $whm->accounts()->listAccounts(
                search: '^' . self::MISSING_ACCOUNT . '$',
                searchtype: 'user',
                searchmethod: 'regex',
                want: $want,
            ));

            self::assertTrue($accounts === [], 'A search without matches must return an empty list.');
            self::assertWhmMetadata($whm->responseMeta(), 'listaccts');
        }
    }

    /** WHM reports result 0 for this exact user lookup, so it stays an API error. */
    public function testExactUserSearchWithoutMatchesStaysAnApiError(): void
    {
        $whm = $this->whm->whm();
        self::assertApiError(
            static fn (): array => $whm->accounts()->listAccounts(
                search: self::MISSING_ACCOUNT,
                searchtype: 'user',
                searchmethod: 'exact',
            ),
            'No accounts found.',
        );
    }

    public function testAccountSummaryOfAMissingAccountStaysAnApiError(): void
    {
        $whm = $this->whm->whm();
        self::assertApiError(
            static fn (): array => $whm->accounts()->accountSummary(user: self::MISSING_ACCOUNT),
            'Account does not exist.',
        );
    }

    public function testAccountSummaryDescribesTheKnownAccount(): void
    {
        $whm = $this->whm->whm();
        $summaries = self::safeOperation(static fn (): array => $whm->accounts()->accountSummary(user: 'npr'));

        self::assertTrue(count($summaries) === 1, 'A summary by user must return exactly one record.');
        $summary = $summaries[0];
        self::assertTrue(($summary['user'] ?? null) === 'npr', 'The summary must describe the known account.');
        self::assertTrue(($summary['domain'] ?? null) === 'npr.rs', 'The summary must report the known domain.');
        self::assertTrue(is_string($summary['plan'] ?? null), 'The summary must report the account plan.');
        self::assertWhmMetadata($whm->responseMeta(), 'accountsummary');
    }

    public function testPackageListIncludesTheKnownAccountPlan(): void
    {
        $whm = $this->whm->whm();
        $plan = self::safeOperation(
            static fn (): ?string => $whm->accounts()->accountSummary(user: 'npr')[0]['plan'] ?? null,
        );
        $packages = self::safeOperation(static fn (): array => $whm->hostingPlans()->listPackages());

        self::assertTrue(count($packages) > 0, 'The server must list at least one package.');
        $names = [];
        foreach ($packages as $package) {
            self::assertTrue(is_string($package['name'] ?? null), 'Every package must carry a name.');
            $names[] = $package['name'];
        }

        self::assertTrue(in_array($plan, $names, true), 'The package list must include the known account plan.');
        self::assertWhmMetadata($whm->responseMeta(), 'listpkgs');
    }

    public function testWhmAcceptsFormEncodedPostForReadOnlyFunctions(): void
    {
        $whm = $this->whm->whm();
        $get = self::safeOperation(static fn (): CallResult => $whm->call('version', [], RequestMethod::Get));
        // call() defaults to POST.
        $post = self::safeOperation(static fn (): CallResult => $whm->call('version'));

        $data = $get->data;
        self::assertTrue(is_array($data) && is_string($data['version'] ?? null), 'GET version must return a string.');
        self::assertTrue($get->data === $post->data, 'POST version must return the same payload as GET.');
        self::assertWhmMetadata($post->metadata, 'version');

        $filtered = self::safeOperation(static fn (): CallResult => $whm->call('listaccts', [
            'search' => 'npr',
            'searchmethod' => 'exact',
            'searchtype' => 'user',
        ], RequestMethod::Post));

        $data = $filtered->data;
        $records = is_array($data) ? ($data['acct'] ?? null) : null;
        self::assertTrue(is_array($records) && count($records) === 1, 'POST listaccts must apply body parameters.');
        $record = $records[0] ?? null;
        self::assertTrue(is_array($record) && ($record['user'] ?? null) === 'npr', 'POST filter must select npr.');
        self::assertWhmMetadata($filtered->metadata, 'listaccts');
    }

    public function testDirectUapiAcceptsPost(): void
    {
        $account = $this->cpanel->uapi();
        $get = self::safeOperation(static fn (): CallResult => $account->call(
            'DomainInfo',
            'primary_domain',
            [],
            RequestMethod::Get,
        ));
        // call() defaults to POST.
        $post = self::safeOperation(static fn (): CallResult => $account->call('DomainInfo', 'primary_domain'));

        self::assertTrue($get->hasData && $get->data !== null, 'GET must return a payload.');
        self::assertTrue($get->data === $post->data, 'POST must return the same payload as GET.');
        self::assertTrue($post->metadata->uapiStatus() === 1, 'Expected successful UAPI status.');
        self::assertTrue($post->metadata->whm === null, 'Direct UAPI must not carry WHM metadata.');
        self::assertTrue($account->isBridged() === false, 'Direct credentials must not bridge.');
    }

    public function testBridgedUapiAcceptsPost(): void
    {
        $account = $this->whm->uapi('npr');
        $get = self::safeOperation(static fn (): CallResult => $account->call(
            'DomainInfo',
            'primary_domain',
            [],
            RequestMethod::Get,
        ));
        // call() defaults to POST.
        $post = self::safeOperation(static fn (): CallResult => $account->call('DomainInfo', 'primary_domain'));

        self::assertTrue($get->hasData && $get->data !== null, 'GET must return a payload.');
        self::assertTrue($get->data === $post->data, 'POST must return the same payload as GET.');
        self::assertTrue($post->metadata->uapiStatus() === 1, 'Expected successful UAPI status.');
        self::assertWhmMetadata($post->metadata, 'uapi_cpanel');
        self::assertTrue($this->whm->whm()->responseMeta() === null, 'Bridge metadata must stay on its UAPI handle.');
    }

    private static function assertWhmMetadata(?ResponseMetadata $metadata, string $command): void
    {
        self::assertNotNull($metadata, 'Expected recorded WHM metadata.');
        self::assertTrue($metadata->httpStatus === 200, 'Expected HTTP 200.');
        self::assertTrue($metadata->whmResult() === 1, 'Expected successful WHM result.');
        self::assertTrue(($metadata->whm['command'] ?? null) === $command, 'Expected the WHM command echo.');
    }

    /**
     * Require a server-reported failure with one known reason.
     *
     * @param Closure(): mixed $operation
     */
    private static function assertApiError(Closure $operation, string $reason): void
    {
        try {
            $operation();
        } catch (ApiErrorException $error) {
            self::assertTrue($error->errors() === [$reason], 'Expected the reviewed WHM failure reason.');
            self::assertTrue($error->metadata?->httpStatus === 200, 'Expected HTTP 200.');
            self::assertTrue($error->metadata->whmResult() === 0, 'Expected failed WHM result.');

            return;
        } catch (Throwable $error) {
            self::fail(sprintf('Expected an API error, got %s.', $error::class));
        }

        self::fail('Expected a server-reported failure.');
    }

    /**
     * Execute a live step and fail with safe diagnostics only.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private static function safeOperation(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $error) {
            $metadata = $error instanceof ApiException ? $error->metadata : null;
            self::fail(sprintf(
                'Live operation failed (%s; HTTP %s; WHM result %s; UAPI status %s).',
                $error::class,
                self::safeStatus($metadata?->httpStatus),
                self::safeStatus($metadata?->whmResult()),
                self::safeStatus($metadata?->uapiStatus()),
            ));
        }
    }

    private static function safeStatus(?int $status): string
    {
        return $status === null ? 'none' : (string) $status;
    }
}
