<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Tests\Support\LiveCurlClient;
use HostingPal\WhmApiClient\Tests\Support\LiveTestEnvironment;
use HostingPal\WhmApiClient\UapiClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Explicit live verification of implemented methods on the approved test server.
 *
 * Each test sends one read-only request. Prerequisites fail rather than skip, and
 * failures report only safe predicates, exception classes, and numeric statuses.
 */
#[Group('live')]
final class ReadOnlyOperationsTest extends TestCase
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

    public function testWhmVersion(): void
    {
        $whm = $this->whm->whm();
        $version = self::safeOperation(static fn (): string => $whm->server()->version());

        self::assertTrue($version !== '', 'WHM version must be nonempty.');
        self::assertWhmMetadata($whm->responseMeta());
        self::assertTrue($whm->responseMeta()?->uapi === null, 'WHM must not contain UAPI metadata.');
    }

    public function testWhmDomainInventory(): void
    {
        $whm = $this->whm->whm();
        $inventory = self::safeOperation(static fn (): array => $whm->accounts()->getDomainInfo());
        $domains = $inventory['domains'] ?? [];

        self::assertTrue(count($domains) > 0, 'WHM must return a usable domain inventory.');
        $knownAccountFound = false;
        foreach ($domains as $domain) {
            $knownAccountFound = $knownAccountFound
                || (($domain['domain'] ?? null) === 'npr.rs' && ($domain['user'] ?? null) === 'npr');
        }

        self::assertTrue($knownAccountFound, 'WHM inventory must include the known test account domain.');
        self::assertWhmMetadata($whm->responseMeta());
        self::assertTrue($whm->responseMeta()?->uapi === null, 'WHM must not contain UAPI metadata.');
    }

    public function testBridgedAccountDomains(): void
    {
        $account = $this->whm->uapi('npr');
        $domains = self::safeOperation(static fn (): array => $account->domainInfo()->listDomains());

        self::assertTrue(
            ($domains['main_domain'] ?? null) === 'npr.rs',
            'Bridged domains must match the known test account.',
        );
        self::assertUapiMetadata($account, true);
        self::assertTrue($this->whm->whm()->responseMeta() === null, 'Bridge metadata must stay on its UAPI handle.');
    }

    public function testDirectAccountDomains(): void
    {
        $account = $this->cpanel->uapi();
        $domains = self::safeOperation(static fn (): array => $account->domainInfo()->listDomains());

        self::assertTrue(
            ($domains['main_domain'] ?? null) === 'npr.rs',
            'Direct domains must match the known test account.',
        );
        self::assertUapiMetadata($account, false);
    }

    public function testBridgedPhpVersions(): void
    {
        $account = $this->whm->uapi('npr');
        $versions = self::safeOperation(static fn (): array => $account->langPhp()->phpGetInstalledVersions());

        self::assertVersions($versions);
        self::assertUapiMetadata($account, true);
        self::assertTrue($this->whm->whm()->responseMeta() === null, 'Bridge metadata must stay on its UAPI handle.');
    }

    public function testDirectPhpVersions(): void
    {
        $account = $this->cpanel->uapi();
        $versions = self::safeOperation(static fn (): array => $account->langPhp()->phpGetInstalledVersions());

        self::assertVersions($versions);
        self::assertUapiMetadata($account, false);
    }

    private static function assertWhmMetadata(?ResponseMetadata $metadata): void
    {
        self::assertTrue($metadata?->httpStatus === 200, 'Expected HTTP 200.');
        self::assertTrue(($metadata?->whm['result'] ?? null) === 1, 'Expected successful WHM result.');
    }

    private static function assertUapiMetadata(UapiClient $account, bool $bridged): void
    {
        $metadata = $account->responseMeta();
        self::assertTrue($account->username() === 'npr', 'UAPI must retain the known test account.');
        self::assertTrue($account->isBridged() === $bridged, 'UAPI must use the expected authentication route.');
        self::assertTrue($metadata?->httpStatus === 200, 'Expected HTTP 200.');
        self::assertTrue(($metadata?->uapi['status'] ?? null) === 1, 'Expected successful UAPI status.');
        if ($bridged) {
            self::assertWhmMetadata($metadata);
        } else {
            self::assertTrue($metadata->whm === null, 'Direct UAPI must not contain WHM metadata.');
        }
    }

    /** @param list<string> $versions */
    private static function assertVersions(array $versions): void
    {
        self::assertTrue($versions !== [], 'The test server must expose available PHP packages.');
        foreach ($versions as $version) {
            self::assertTrue($version !== '', 'PHP package names must be nonempty strings.');
        }
    }

    /**
     * Prevent upstream exceptions and metadata payloads from entering PHPUnit output.
     *
     * @template TResult
     * @param Closure(): TResult $operation
     * @return TResult
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
                self::safeStatus($metadata?->whm['result'] ?? null),
                self::safeStatus($metadata?->uapi['status'] ?? null),
            ));
        }
    }

    private static function safeStatus(mixed $status): string
    {
        return is_int($status) ? (string) $status : 'unavailable';
    }
}
