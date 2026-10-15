<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
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
 * Every request is read-only: account listing, one account summary, the package
 * list, and the same read-only operations over POST on all three routes, which
 * is the runtime evidence for form-encoded execution. Failures report only safe
 * predicates, exception classes, and numeric statuses.
 */
#[Group('live')]
final class AccountsLiveTest extends TestCase
{
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
