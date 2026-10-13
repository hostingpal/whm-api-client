<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Support\LiveCurlClient;
use HostingPal\WhmApiClient\Tests\Support\LiveTestEnvironment;
use HostingPal\WhmApiClient\WhmClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Approval-gated mutating lifecycle on one throwaway account of the test server.
 *
 * This suite is NOT part of `composer test:live` (group live). It creates, inspects,
 * suspends, unsuspends, re-passwords, re-packages, modifies, and finally removes
 * exactly the account named by WHM_API_LIVE_ACCOUNT, and runs only with
 * WHM_API_LIVE=1, WHM_API_LIVE_MUTATIONS=1, WHM_API_LIVE_ACCOUNT, and
 * WHM_API_LIVE_DOMAIN set. The transport double refuses every other mutation.
 * Run it only after the owner approves the target account and server snapshot:
 * `WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=... WHM_API_LIVE_DOMAIN=...
 * vendor/bin/phpunit --testsuite live --group live-mutating`.
 */
#[Group('live-mutating')]
final class AccountLifecycleLiveTest extends TestCase
{
    private WhmClient $whm;

    private string $account;

    private string $domain;

    protected function setUp(): void
    {
        try {
            $tokens = LiveTestEnvironment::fromEnvironment();
            ['account' => $this->account, 'domain' => $this->domain] = LiveTestEnvironment::mutationsFromEnvironment();
        } catch (RuntimeException $error) {
            self::fail($error->getMessage());
        }

        $factory = new Psr17Factory();
        $transport = new HttpTransport(new LiveCurlClient(null, $this->account), $factory, $factory);
        $client = self::safeOperation(static fn (): Client => new Client([
            'host' => 'test.oblak.host',
            'port' => 2087,
            'authType' => 'whm',
            'username' => 'root',
            'token' => $tokens['whm'],
        ], $transport));
        $this->whm = $client->whm();
    }

    public function testAccountLifecycle(): void
    {
        $accounts = $this->whm->accounts();
        $packages = self::safeOperation(static fn (): array => $accounts->packages('creatable'));
        $plan = $packages[0]['name'] ?? null;
        self::assertTrue(is_string($plan), 'A creatable package is required for the lifecycle.');

        $created = false;
        try {
            $result = self::safeOperation(fn (): array => $accounts->create($this->account, [
                'domain' => $this->domain,
                'password' => self::password(),
                'plan' => $plan,
                'contactemail' => 'lifecycle@' . $this->domain,
            ]));
            $created = true;
            foreach ($result as $value) {
                self::assertTrue($value !== '', 'Creation fields must be nonempty strings.');
            }
            $this->assertSummary(0);

            self::safeOperation(fn () => $accounts->suspend($this->account, 'whm-api-client lifecycle test', false));
            $this->assertSummary(1);

            self::safeOperation(fn () => $accounts->unsuspend($this->account));
            $this->assertSummary(0);

            $updated = self::safeOperation(fn (): array => $accounts->changePassword($this->account, self::password()));
            foreach ($updated['app'] ?? [] as $application) {
                self::assertTrue($application !== '', 'Updated applications must be named.');
            }

            self::safeOperation(fn () => $accounts->changePackage($this->account, $plan));

            $modified = self::safeOperation(fn (): array => $accounts->modify($this->account, [
                'contactemail' => 'modified@' . $this->domain,
            ]));
            self::assertTrue(
                ($modified['user'] ?? $this->account) === $this->account,
                'Modification must echo the account.',
            );
            $summary = self::safeOperation(fn (): array => $accounts->summary($this->account));
            self::assertTrue(($summary['email'] ?? null) === 'modified@' . $this->domain, 'Contact email must update.');
        } finally {
            if ($created) {
                self::safeOperation(fn () => $accounts->remove($this->account, false));
            }
        }
    }

    private function assertSummary(int $suspended): void
    {
        $summary = self::safeOperation(fn (): array => $this->whm->accounts()->summary($this->account));

        self::assertTrue(($summary['user'] ?? null) === $this->account, 'The summary must describe the account.');
        self::assertTrue(($summary['suspended'] ?? null) === $suspended, 'The suspension state must match.');
    }

    /** Generate a throwaway password that is never logged. */
    private static function password(): string
    {
        return 'Lc' . bin2hex(random_bytes(12)) . '!9';
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
            $status = $metadata?->httpStatus;
            $result = $metadata?->whmResult();
            self::fail(sprintf(
                'Live operation failed (%s; HTTP %s; WHM result %s).',
                $error::class,
                $status === null ? 'none' : (string) $status,
                $result === null ? 'none' : (string) $result,
            ));
        }
    }
}
