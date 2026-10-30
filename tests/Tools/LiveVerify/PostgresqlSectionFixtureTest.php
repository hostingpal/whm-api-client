<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\PostgresqlSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresqlSectionFixtureTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $settings = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'];
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

    public function testBothRoutesCaptureAllFourteenFunctionsAndCleanAllOwnedNames(): void
    {
        $server = new PostgresqlSectionServer();
        $report = $this->fixture($server)->run();
        self::assertSame('passed', $report['status']);
        self::assertSame('passed', $report['assessment']['status']);
        self::assertSame([], $server->databases);
        self::assertSame([], $server->users);
        self::assertCount(4, $server->deleted);
        $expectedPhases = ['initial_databases', 'initial_users', 'get_restrictions',
            'ready_databases', 'ready_users', 'create_database', 'create_user', 'grant',
            'linked_databases', 'linked_users', 'rename_database', 'renamed_databases',
            'rename_user', 'renamed_users', 'set_password', 'rename_user_no_password', 'set_final_password',
            'final_named_users', 'bounded_databases', 'bounded_users', 'update_privileges',
            'revoke', 'revoked_databases', 'revoked_users', 'cleanup_user', 'cleanup_database',
            'final_databases', 'final_users'];
        self::assertCount(57, $report['probes']);
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            $operations = [];
            $phases = [];
            foreach ($report['probes'] as $record) {
                if ($record['route'] === $route) {
                    $operations[$record['operation']] = true;
                    $phases[] = $record['phase'];
                }
            }
            self::assertCount(14, $operations);
            self::assertSame($expectedPhases, $phases);
        }
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['npr_wp', 'private', 'test.oblak.host'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        foreach ($server->requests as $index => $request) {
            if (str_contains((string) $request->getBody(), 'password=')) {
                parse_str((string) $request->getBody(), $body);
                self::assertIsString($body['password']);
                self::assertStringNotContainsString($body['password'], $json);
                parse_str($request->getUri()->getQuery(), $query);
                self::assertArrayNotHasKey('password', $query);
            }
            if (str_contains((string) $request->getUri(), 'rename_user_no_password')) {
                $next = $server->requests[$index + 1];
                self::assertStringContainsString('set_password', (string) $next->getUri());
                self::assertSame('POST', $next->getMethod());
            }
        }
        self::assertSame(
            ['version', 'initial_databases', 'initial_users', 'get_restrictions',
            'initial_databases', 'initial_users', 'get_restrictions'],
            array_column(array_slice($report['probes'], 0, 7), 'phase'),
        );
    }

    #[DataProvider('failedPreflights')]
    public function testEveryFailedPreflightIsPersistedAndBlocksAllMutations(string $key): void
    {
        $server = new PostgresqlSectionServer();
        $server->failures[$key] = 'before';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertCount(7, $report['probes']);
        self::assertContains('preflight_failure', $report['failures']);
        self::assertSame(['database' => 'not_started', 'user' => 'not_started'], $report['resources']['direct-uapi']);
        self::assertSame(['database' => 'not_started', 'user' => 'not_started'], $report['resources']['bridged-uapi']);
        foreach ($server->requests as $request) {
            self::assertSame('GET', $request->getMethod());
        }
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<array{string}> */
    public static function failedPreflights(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['list_databases', 'list_users', 'get_restrictions'] as $function) {
                yield [$function . '/' . $route . '/1'];
            }
        }
    }

    public function testNonemptyInventoryStopsBeforeAccountWideMutation(): void
    {
        $server = new PostgresqlSectionServer();
        $server->databases['private-existing'] = [];
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['private-existing' => []], $server->databases);
        self::assertSame([], $server->deleted);
        self::assertCount(7, $server->requests);
        foreach ($server->requests as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function testUncertainCreationNeverAdoptsAppearingResourcesOrRunsSecondRouteMutations(): void
    {
        $server = new PostgresqlSectionServer();
        $server->failures['create_database/direct/1'] = 'lost';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertArrayHasKey('npr_wpa1b2c3d', $server->databases);
        self::assertSame([], $server->deleted);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['database']);
        self::assertSame('not_started', $report['resources']['bridged-uapi']['database']);
    }

    public function testConfirmedCreationWithPayloadMismatchStillCleansTheOwnedResource(): void
    {
        $server = new PostgresqlSectionServer();
        $server->overrides['create_user/direct/1'] = '"private mismatch"';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertSame([], $server->users);
        self::assertContains('npr_wpa1b2c3u', $server->deleted);
        $record = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'create_user' && $record['route'] === 'direct-uapi'))[0];
        self::assertSame('success', $record['api_outcome']);
        self::assertSame('failed', $record['payload_validation']);
    }

    #[DataProvider('renames')]
    public function testLostRenameRetainsAllUncertainIdentitiesWithoutRetry(string $function, string $identity): void
    {
        $server = new PostgresqlSectionServer();
        $server->failures[$function . '/direct/1'] = 'lost';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertNotContains($identity, $server->deleted);
        self::assertTrue(isset($server->databases[$identity]) || in_array($identity, $server->users, true));
        $attempts = array_filter($server->requests, static fn ($request): bool =>
            $request->getUri()->getPort() === 2083 && str_ends_with($request->getUri()->getPath(), '/' . $function));
        self::assertCount(1, $attempts);
    }

    /** @return iterable<array{string, string}> */
    public static function renames(): iterable
    {
        yield ['rename_database', 'npr_wpa1b2c3e'];
        yield ['rename_user', 'npr_wpa1b2c3v'];
        yield ['rename_user_no_password', 'npr_wpa1b2c3z'];
    }

    public function testConfirmedPasswordlessRenameStillSetsPasswordAfterPayloadMismatch(): void
    {
        $server = new PostgresqlSectionServer();
        $server->overrides['rename_user_no_password/direct/1'] = '"private mismatch"';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertSame([], $server->users);
        $requests = array_values(array_filter($server->requests, static fn ($request): bool =>
            $request->getUri()->getPort() === 2083));
        foreach ($requests as $index => $request) {
            if (str_ends_with($request->getUri()->getPath(), '/rename_user_no_password')) {
                self::assertStringEndsWith('/set_password', $requests[$index + 1]->getUri()->getPath());
            }
        }
    }

    public function testCleanupFailureDoesNotPreventOtherOwnedResourceCleanup(): void
    {
        $server = new PostgresqlSectionServer();
        $server->failures['delete_user/direct/1'] = 'before';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertSame(['npr_wpa1b2c3z'], $server->users);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['user']);
        self::assertSame('cleaned', $report['resources']['direct-uapi']['database']);
    }

    public function testUnknownFieldsStayMaskedAndFailStrictAssessment(): void
    {
        $server = new PostgresqlSectionServer();
        $server->overrides['get_restrictions/direct/1'] = '{"prefix":"npr_","private-dynamic-name":"private-value"}';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertContains('unacknowledged_discrepancy', $report['assessment']['findings']);
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('incorrectInventories')]
    public function testUntrustworthyOrIncorrectInventoriesFailSafe(string $key, string $payload): void
    {
        $server = new PostgresqlSectionServer();
        $server->overrides[$key] = $payload;
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertNotContains('private-competitor', $server->deleted);
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<array{string, string}> */
    public static function incorrectInventories(): iterable
    {
        yield ['list_databases/direct/1', '[{}]'];
        yield ['list_users/direct/1', '["private-competitor","private-competitor"]'];
        yield ['list_databases/direct/3', '[{"database":"npr_wpa1b2c3d","users":[]}]'];
        yield ['list_users/direct/3', '[]'];
        yield ['list_databases/direct/5', '[{"database":"private-competitor","users":[]}]'];
    }

    public function testFreshForeignInventoryPreventsAccountWidePrivilegeUpdate(): void
    {
        $server = new PostgresqlSectionServer();
        $server->overrides['list_databases/direct/5'] = '[{"database":"private-competitor","users":[]}]';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->databases);
        self::assertSame([], $server->users);
        $updates = array_filter($server->requests, static fn ($request): bool =>
            $request->getUri()->getPort() === 2083
                && str_ends_with($request->getUri()->getPath(), '/update_privileges'));
        self::assertSame([], $updates);
        self::assertNotContains('private-competitor', $server->deleted);
    }

    public function testAbsentUncertainCreationIsNeverDeclaredCleanOrFollowedByAnotherLifecycle(): void
    {
        $server = new PostgresqlSectionServer();
        $server->failures['create_database/direct/1'] = 'before';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertNotContains('npr_wpa1b2c3d', $server->deleted);
        self::assertCount(0, $server->deleted);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['database']);
        self::assertSame(['database' => 'not_started', 'user' => 'not_started'], $report['resources']['bridged-uapi']);
    }

    #[DataProvider('delayedMutations')]
    public function testAbsentInventoryDoesNotResolveMutationThatCompletesAfterTimeout(string $function): void
    {
        $server = new PostgresqlSectionServer();
        $server->failures[$function . '/direct/1'] = 'delayed';
        $report = $this->fixture($server)->run();
        self::assertSame([], $server->databases, 'The completion has not happened at the final inventory.');
        self::assertSame('failed', $report['status']);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['database']);
        self::assertSame('not_started', $report['resources']['bridged-uapi']['database']);
        $server->completeDelayed();
        self::assertNotEmpty($server->databases, 'The remote operation can still materialize after inventory.');
        foreach (array_keys($server->databases) as $identity) {
            self::assertNotContains($identity, $server->deleted);
        }
    }

    /** @return iterable<array{string}> */
    public static function delayedMutations(): iterable
    {
        yield ['create_database'];
        yield ['rename_database'];
    }

    public function testWrongDomainAndReusingAnInstanceSendNoAdditionalRequests(): void
    {
        $server = new PostgresqlSectionServer();
        putenv('WHM_API_LIVE_DOMAIN=other');
        self::assertSame('failed', $this->fixture($server)->run()['status']);
        self::assertSame([], $server->requests);
        putenv('WHM_API_LIVE_DOMAIN=npr.rs');
        $fixture = $this->fixture($server);
        self::assertSame('passed', $fixture->run()['status']);
        $count = count($server->requests);
        self::assertSame('failed', $fixture->run()['status']);
        self::assertCount($count, $server->requests);
    }

    private function fixture(PostgresqlSectionServer $server): PostgresqlSectionFixture
    {
        return new PostgresqlSectionFixture($server, ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'], 'a1b2c3', [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]);
    }
}
