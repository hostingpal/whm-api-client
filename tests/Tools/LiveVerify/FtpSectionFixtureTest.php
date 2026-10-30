<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\FtpSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Context from EvidenceRunner */
final class FtpSectionFixtureTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'] as $key => $value
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

    public function testRunsBothRoutesAndDeletesOnlyOwnedAccountsWithoutDirectories(): void
    {
        $http = new FtpSectionServer();
        $fixture = self::fixture($http);
        $report = $fixture->run();
        self::assertSame('passed', $report['status'], json_encode($report['failures'], JSON_THROW_ON_ERROR));
        self::assertSame([], $report['assessment']['findings']);
        self::assertSame([], $http->accounts);
        self::assertSame(['wftp0123456789abcdef', 'wftpfedcba9876543210'], $http->deleted);
        self::assertCount(41, $report['probes']);
        $mutations = array_values(array_filter($http->requests, static fn (array $request): bool =>
            $request['method'] === 'POST'));
        self::assertSame(['add_ftp', 'passwd', 'set_homedir', 'set_quota', 'delete_ftp',
            'add_ftp', 'passwd', 'set_homedir', 'set_quota', 'delete_ftp'], array_column($mutations, 'function'));
        self::assertSame(['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'], $report['resources']);
        $count = count($http->requests);
        self::assertSame('failed', $fixture->run()['status']);
        self::assertCount($count, $http->requests);
        self::assertPrivate($report);
    }

    #[DataProvider('failures')]
    public function testFailurePathsRetainEvidenceAndDoNotAdoptOrRetry(string $fault, string $state, int $deletes): void
    {
        $http = new FtpSectionServer($fault);
        $report = self::fixture($http)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame($state, $report['resources']['direct-uapi']);
        self::assertCount($deletes, array_filter($http->deleted, static fn (string $user): bool =>
            $user === 'wftp0123456789abcdef'));
        self::assertPrivate($report);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function failures(): iterable
    {
        yield 'ambiguous identity' => ['ambiguous_identity', 'cleaned', 1];
        yield 'creation race' => ['create_race', 'uncertain', 0];
        yield 'lost creation response' => ['create_transport', 'uncertain', 0];
        yield 'confirmed outcome malformed payload' => ['create_payload', 'cleaned', 1];
        yield 'delete is not retried' => ['delete_failure', 'uncertain', 1];
        yield 'incomplete inventory is not absence' => ['malformed_inventory', 'not_started', 0];
        yield 'quota contract mismatch' => ['quota_integer', 'cleaned', 1];
        yield 'quota semantic mismatch' => ['wrong_quota', 'cleaned', 1];
        yield 'unknown raw keys are discrepancies' => ['unknown_field', 'cleaned', 1];
    }

    #[DataProvider('requiredEnvironment')]
    public function testPrerequisitesPreventAllRequests(string $key): void
    {
        $http = new FtpSectionServer();
        putenv($key . '=wrong');
        self::assertSame('failed', self::fixture($http)->run()['status']);
        self::assertSame([], $http->requests);
    }

    /** @return iterable<array{string}> */
    public static function requiredEnvironment(): iterable
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN'] as $key) {
            yield [$key];
        }
    }

    public function testMatchesFullLoginIdentityAndNormalizesEquivalentHomePaths(): void
    {
        foreach (['full_identity', 'normalized_home'] as $variant) {
            $report = self::fixture(new FtpSectionServer($variant))->run();
            self::assertSame('passed', $report['status'], $variant);
        }
    }

    public function testFixtureDebuggingAndSerializationCannotExposeCredentialsOrRows(): void
    {
        $fixture = self::fixture(new FtpSectionServer());
        ob_start();
        var_dump($fixture);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('private', $debug);
        self::assertStringNotContainsString('0123456789abcdef', $debug);
        $this->expectException(\LogicException::class);
        serialize($fixture);
    }

    public static function fixture(FtpSectionServer $http): FtpSectionFixture
    {
        return new FtpSectionFixture(
            $http,
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            ['direct-uapi' => '0123456789abcdef', 'bridged-uapi' => 'fedcba9876543210'],
            self::context(),
        );
    }

    /** @return Context */
    public static function context(): array
    {
        return ['revision' => str_repeat('a', 40), 'source_hashes' => ['whm1' => str_repeat('b', 64),
            'uapi' => str_repeat('c', 64)], 'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40), 'overlay_sha256' => str_repeat('f', 64)];
    }

    /** @param array<mixed> $report */
    private static function assertPrivate(array $report): void
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private', '0123456789abcdef', 'fedcba9876543210', '/home/npr', 'wftp'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }
}
