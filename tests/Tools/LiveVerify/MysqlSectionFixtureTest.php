<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\MysqlSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MysqlSectionFixtureTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $settings = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'npr'];
        foreach ($settings as $key => $value) {
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

    public function testBothRoutesCaptureTwentyOneOperationsAndCleanOnlyOwnedResources(): void
    {
        $server = new MysqlSectionServer();
        $server->databases['npr_existing'] = [];
        $server->users['npr_existing'] = [];
        $report = $this->fixture($server)->run();
        self::assertSame('passed', $report['status']);
        self::assertSame('passed', $report['assessment']['status']);
        self::assertSame(['npr_existing' => []], $server->databases);
        self::assertSame(['npr_existing' => []], $server->users);
        self::assertCount(4, $server->deleted);
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            $operations = [];
            foreach ($report['probes'] as $record) {
                if ($record['route'] === $route) {
                    $operations[$record['operation']] = true;
                }
            }
            self::assertCount(21, $operations);
        }
        self::assertCount(4, $report['unobserved']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['npr_ws', 'npr_existing', 'private', 'SELECT', 'test.oblak.host'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        foreach ($server->requests as $request) {
            if (str_contains((string) $request->getBody(), 'password=')) {
                parse_str((string) $request->getBody(), $body);
                self::assertIsString($body['password']);
                self::assertStringNotContainsString($body['password'], $json);
            }
        }
    }

    public function testFailedCreationDoesNotAdoptOrDeleteAnAppearingCompetitor(): void
    {
        $server = new MysqlSectionServer();
        $server->failures['create_database/direct/1'] = 'after';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertArrayHasKey('npr_wsa1b2c3d', $server->databases);
        self::assertNotContains('npr_wsa1b2c3d', $server->deleted);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['database']);
        self::assertCount(2, $server->deleted, 'The independent bridge lifecycle still cleans its own resources.');
    }

    public function testAcceptedOutcomeWithBadCreationPayloadStillOwnsAndCleansTheResource(): void
    {
        $server = new MysqlSectionServer();
        $server->overrides['create_database/direct/1'] = '"private mismatch"';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertContains('npr_wsa1b2c3d', $server->deleted);
        $records = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'create_database' && $record['route'] === 'direct-uapi'));
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('failed', $records[0]['payload_validation']);
    }

    #[DataProvider('badPasswordResults')]
    public function testPasswordFailureEntriesAreNeverMistakenForSuccess(string $payload): void
    {
        $server = new MysqlSectionServer();
        $server->overrides['set_password/direct/1'] = $payload;
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertSame([], $server->users);
        $records = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'set_password' && $record['route'] === 'direct-uapi'));
        self::assertSame('passed', $records[0]['probe_outcome']);
        self::assertSame('failed', $records[0]['expectation']);
    }

    /** @return iterable<array{string}> */
    public static function badPasswordResults(): iterable
    {
        yield ['{"failures":[{}]}'];
        yield ['{"failures":[{"host":"private-host","error":"private error"}]}'];
    }

    public function testFailedRenameDoesNotDeleteEitherUncertainIdentity(): void
    {
        $server = new MysqlSectionServer();
        $server->failures['rename_database/direct/1'] = 'after';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertArrayHasKey('npr_wsa1b2c3e', $server->databases);
        self::assertNotContains('npr_wsa1b2c3e', $server->deleted);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['database']);
    }

    public function testPreexistingIdentityPreventsMutationForThatRoute(): void
    {
        $server = new MysqlSectionServer();
        $server->databases['npr_wsa1b2c3e'] = [];
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['npr_wsa1b2c3e' => []], $server->databases);
        foreach ($server->requests as $request) {
            self::assertFalse($request->getMethod() === 'POST' && $request->getUri()->getPort() === 2083);
        }
    }

    public function testMissingDesignationAndReusingAnInstanceSendNothingFurther(): void
    {
        $server = new MysqlSectionServer();
        $fixture = $this->fixture($server);
        putenv('WHM_API_LIVE_ACCOUNT=other');
        self::assertSame('failed', $fixture->run()['status']);
        self::assertSame([], $server->requests);
        putenv('WHM_API_LIVE_ACCOUNT=npr');
        $fixture = $this->fixture($server);
        self::assertSame('passed', $fixture->run()['status']);
        $count = count($server->requests);
        self::assertSame('failed', $fixture->run()['status']);
        self::assertCount($count, $server->requests);
    }

    #[DataProvider('incorrectReadbacks')]
    public function testIndependentValueOraclesRejectIncorrectReadbacks(string $step, string $payload): void
    {
        $server = new MysqlSectionServer();
        $server->overrides[$step] = $payload;
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertSame([], $server->users);
        self::assertContains('unsuccessful_phase', $report['assessment']['findings']);
    }

    /** @return iterable<array{string, string}> */
    public static function incorrectReadbacks(): iterable
    {
        yield ['get_privileges_on_database/direct/1', '[]'];
        yield ['get_privileges_on_database/direct/2', '["SELECT"]'];
        yield ['list_databases/direct/1', '[{}]'];
        yield ['list_users/direct/1', '[{"user":"private-existing"},{"user":"private-existing"}]'];
    }

    public function testCleanupFailureDoesNotPreventOtherOwnedResourceOrRouteCleanup(): void
    {
        $server = new MysqlSectionServer();
        $server->failures['delete_user/direct/1'] = 'before';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertSame(['npr_wsa1b2c3v' => []], $server->users);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['user']);
        self::assertSame('cleaned', $report['resources']['direct-uapi']['database']);
        self::assertSame(['database' => 'cleaned', 'user' => 'cleaned'], $report['resources']['bridged-uapi']);
        $deletions = array_filter($server->requests, static fn ($request): bool =>
            $request->getUri()->getPort() === 2083 && str_ends_with($request->getUri()->getPath(), '/delete_user'));
        self::assertCount(1, $deletions, 'Cleanup mutations are never retried.');
    }

    private function fixture(MysqlSectionServer $server): MysqlSectionFixture
    {
        return new MysqlSectionFixture($server, ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'], 'a1b2c3', [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]);
    }
}
