<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\Accounts;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
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
 * The account must be absent before creation. If creation raises (the server may
 * have created the account before a payload or transport failure), the test checks
 * again and removes the account when it now exists, so no account is left behind.
 * Run it only after the owner approves the target account and server snapshot:
 * `WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=... WHM_API_LIVE_DOMAIN=...
 * vendor/bin/phpunit --testsuite live --group live-mutating`.
 *
 * @phpstan-import-type AccountSummaryResult from Accounts
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
        $plans = $this->whm->hostingPlans();
        $packages = self::safeOperation(static fn (): array => $plans->listPackages('creatable'));
        $plan = $packages[0]['name'] ?? null;
        self::assertTrue(is_string($plan), 'A creatable package is required for the lifecycle.');

        self::assertFalse(
            self::safeOperation(fn (): bool => $this->accountExists()),
            'The lifecycle account must not exist before the test.',
        );

        $created = false;
        try {
            try {
                $result = $accounts->createAccount(
                    $this->account,
                    contactemail: 'lifecycle@' . $this->domain,
                    domain: $this->domain,
                    password: self::password(),
                    plan: $plan,
                );
            } catch (Throwable $error) {
                // The server can create the account and still fail the response (payload or
                // transport); the account was absent before, so an existing one is ours.
                // The creation failure is reported even if this check fails as well.
                try {
                    $created = $this->accountExists();
                } finally {
                    self::failSafely($error);
                }
            }
            $created = true;
            foreach ($result ?? [] as $value) {
                self::assertTrue($value !== '', 'Creation fields must be nonempty strings.');
            }
            $this->assertSummary(0);

            self::safeOperation(fn () => $accounts->suspendAccount(
                $this->account,
                reason: 'whm-api-client lifecycle test',
                disallowun: false,
            ));
            $this->assertSummary(1);

            self::safeOperation(fn () => $accounts->unsuspendAccount($this->account));
            $this->assertSummary(0);

            $updated = self::safeOperation(
                fn (): ?array => $accounts->changePassword($this->account, self::password()),
            );
            foreach ($updated['app'] ?? [] as $application) {
                self::assertTrue($application !== '', 'Updated applications must be named.');
            }

            self::safeOperation(fn () => $accounts->changePackage($this->account, $plan));

            $modified = self::safeOperation(fn (): ?array => $accounts->modifyAccount(
                $this->account,
                contactemail: 'modified@' . $this->domain,
            ));
            self::assertTrue(
                ($modified['user'] ?? $this->account) === $this->account,
                'Modification must echo the account.',
            );
            $summary = $this->summary();
            self::assertTrue(($summary['email'] ?? null) === 'modified@' . $this->domain, 'Contact email must update.');
        } finally {
            if ($created) {
                self::safeOperation(fn () => $accounts->removeAccount($this->account, false));
            }
        }
    }

    /**
     * Report whether the lifecycle account exists, without failing the test.
     *
     * Only a successful summary with a record for the account counts as existing. A
     * failed outcome or a list without a matching record counts as absent: how WHM
     * reports an unknown account is neither pinned nor observed, so both forms are
     * accepted. Cleanup after a failed creation therefore removes an account only on
     * positive evidence that it now exists; other failures still fail the test.
     */
    private function accountExists(): bool
    {
        try {
            $summaries = $this->whm->accounts()->accountSummary(user: $this->account);
        } catch (ApiErrorException) {
            return false;
        }

        foreach ($summaries as $summary) {
            if (($summary['user'] ?? null) === $this->account) {
                return true;
            }
        }

        return false;
    }

    private function assertSummary(int $suspended): void
    {
        $summary = $this->summary();

        self::assertTrue(($summary['user'] ?? null) === $this->account, 'The summary must describe the account.');
        self::assertTrue(($summary['suspended'] ?? null) === $suspended, 'The suspension state must match.');
    }

    /**
     * Return the single summary record of the lifecycle account.
     *
     * @return value-of<AccountSummaryResult>
     */
    private function summary(): array
    {
        $summaries = self::safeOperation(fn (): array => $this->whm->accounts()->accountSummary(user: $this->account));
        self::assertTrue(count($summaries) === 1, 'A summary by user must return exactly one record.');

        return $summaries[0];
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
            self::failSafely($error);
        }
    }

    /** Fail with the exception class, HTTP status and WHM result only. */
    private static function failSafely(Throwable $error): never
    {
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
