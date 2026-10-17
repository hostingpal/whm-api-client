<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\MysqlFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/** @phpstan-import-type Record from EvidenceRunner */
final class MysqlFixtureTest extends TestCase
{
    private string|false $live;

    private string|false $mutations;

    protected function setUp(): void
    {
        $this->live = getenv('WHM_API_LIVE');
        $this->mutations = getenv('WHM_API_LIVE_MUTATIONS');
        putenv('WHM_API_LIVE=1');
        putenv('WHM_API_LIVE_MUTATIONS=1');
    }

    protected function tearDown(): void
    {
        putenv($this->live === false ? 'WHM_API_LIVE' : 'WHM_API_LIVE=' . $this->live);
        putenv($this->mutations === false ? 'WHM_API_LIVE_MUTATIONS' : 'WHM_API_LIVE_MUTATIONS=' . $this->mutations);
    }

    public function testFixtureObservesBothRoutesOnceAndRemovesOnlyItsFourResources(): void
    {
        $http = new MysqlFixtureServer();
        $http->databases['existing_database'] = [];
        $http->users['existing_user'] = [];
        $fixture = new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3');
        $report = $fixture->run(self::probes(), self::capture($http));

        self::assertSame('passed', $report['mysql_fixture']['status']);
        self::assertSame('passed', $report['mysql_fixture']['cleanup']);
        self::assertSame([], $report['mysql_fixture']['failures']);
        self::assertCount(5, $report['probes']);
        self::assertSame(['existing_database' => []], $http->databases);
        self::assertSame(['existing_user' => []], $http->users);
        self::assertSame(['npr_wva1b2c3v', 'npr_wva1b2c3u', 'npr_wva1b2c3e', 'npr_wva1b2c3d'], $http->deleted);
        self::assertSame(1, $http->counts['bridge:list_databases']);
        self::assertSame(1, $http->counts['bridge:list_users']);
        self::assertSame(6, $http->counts['list_databases']);
        self::assertSame(6, $http->counts['list_users']);
        $databases = $report['probes'][1];
        self::assertSame(['int'], $databases['payload_paths']['$[].disk_usage']);
        self::assertSame(['empty-list', 'list'], $databases['payload_paths']['$[].users']);
        self::assertSame(['string'], $databases['payload_paths']['$[].users[]']);
        self::assertSame(['empty-list', 'list'], $report['probes'][3]['payload_paths']['$[].databases']);
        foreach ($report['mysql_fixture']['resources'] as $resource) {
            self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $resource);
        }
        self::assertPrivate($report, $http);
    }

    #[DataProvider('invalidSuffixes')]
    public function testRejectsUnboundNamesWithoutRequests(string $suffix): void
    {
        $http = new MysqlFixtureServer();
        $this->expectException(LogicException::class);
        try {
            new MysqlFixture($http, 'private-cpanel-token', $suffix);
        } finally {
            self::assertSame([], $http->counts);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSuffixes(): iterable
    {
        yield 'uppercase' => ['ABCDEF'];
        yield 'short' => ['abcde'];
        yield 'long' => ['abcdef0'];
        yield 'separator' => ['abc/ef'];
    }

    #[DataProvider('optIns')]
    public function testMissingOptInReturnsFailureBeforeAnyRequest(string $variable): void
    {
        $http = new MysqlFixtureServer();
        $fixture = new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3');
        putenv($variable . '=0');
        $report = $fixture->run(self::probes(), self::capture($http));
        self::assertSame('failed', $report['mysql_fixture']['status']);
        self::assertSame('not_run', $report['mysql_fixture']['capture']);
        self::assertSame([], $http->counts);
    }

    /** @return iterable<string, array{string}> */
    public static function optIns(): iterable
    {
        yield 'live' => ['WHM_API_LIVE'];
        yield 'mutations' => ['WHM_API_LIVE_MUTATIONS'];
    }

    #[DataProvider('unusableInventories')]
    public function testUnconfirmedInitialAbsenceNeverCreatesOrDeletes(string $payload): void
    {
        $http = new MysqlFixtureServer();
        $http->overrides['list_databases:1'] = $payload;
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame('failed', $report['mysql_fixture']['status']);
        self::assertSame([], $http->mutations);
        self::assertSame('not_run', $report['mysql_fixture']['capture']);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableInventories(): iterable
    {
        yield 'object is not empty list' => ['{}'];
        yield 'missing identity' => ['[{}]'];
        yield 'null identity' => ['[{"database":null}]'];
        yield 'duplicate identity' => ['[{"database":"same"},{"database":"same"}]'];
        yield 'empty identity' => ['[{"database":""}]'];
        yield 'collision' => ['[{"database":"npr_wva1b2c3d"}]'];
    }

    #[DataProvider('creationFailures')]
    public function testCreationFailuresReconcileBeforeCleanupWithoutRetrying(string $step, string $mode): void
    {
        $http = new MysqlFixtureServer();
        $http->failures[$step] = $mode;
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame('failed', $report['mysql_fixture']['status']);
        self::assertSame('failed', $report['mysql_fixture']['setup']);
        self::assertSame('not_run', $report['mysql_fixture']['capture']);
        self::assertSame([], $http->databases);
        self::assertSame([], $http->users);
        self::assertSame(1, count(array_filter($http->steps, static fn (string $value): bool => $value === $step)));
        self::assertPrivate($report, $http);
    }

    /** @return iterable<string, array{string, string}> */
    public static function creationFailures(): iterable
    {
        foreach (
            ['create_database:1', 'create_database:2', 'create_user:1', 'create_user:2',
            'set_privileges_on_database:1'] as $step
        ) {
            foreach (['throw_before', 'throw_after', 'api_before', 'api_after'] as $mode) {
                yield $step . ' ' . $mode => [$step, $mode];
            }
        }
    }

    public function testUnknownCreationIsNotDeletedWhenReconciliationCannotIdentifyIt(): void
    {
        $http = new MysqlFixtureServer();
        $http->failures['create_database:1'] = 'throw_after';
        $http->overrides['list_databases:2'] = '[{}]';
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame([], $http->deleted);
        self::assertArrayHasKey('npr_wva1b2c3d', $http->databases);
        self::assertSame('unconfirmed', $report['mysql_fixture']['resources']['linked_database']['cleanup']);
        self::assertSame('failed', $report['mysql_fixture']['cleanup']);
    }

    public function testCleanupUsesIdentityEvenWhenOtherInventoryFieldsAreMalformed(): void
    {
        $http = new MysqlFixtureServer();
        $http->failures['create_database:1'] = 'throw_after';
        $http->overrides['list_databases:2'] = '[{"database":"npr_wva1b2c3d","disk_usage":"private-bad"}]';
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame(['npr_wva1b2c3d'], $http->deleted);
        self::assertSame('confirmed_absent', $report['mysql_fixture']['resources']['linked_database']['cleanup']);
        self::assertSame('failed', $report['mysql_fixture']['status']);
    }

    #[DataProvider('deleteFailures')]
    public function testDeleteFailureDoesNotRetryOrPreventOtherResourcesBeingCleaned(
        string $mode,
        string $expected,
    ): void {
        $http = new MysqlFixtureServer();
        $http->failures['delete_user:1'] = $mode;
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame('failed', $report['mysql_fixture']['status']);
        self::assertSame('failed', $report['mysql_fixture']['cleanup']);
        self::assertSame($expected, $report['mysql_fixture']['resources']['unlinked_user']['cleanup']);
        self::assertSame([], $http->databases);
        self::assertArrayNotHasKey('npr_wva1b2c3u', $http->users);
        self::assertSame(2, $http->counts['delete_user']);
        self::assertContains('cleanup_request_failed', $report['mysql_fixture']['failures']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function deleteFailures(): iterable
    {
        yield 'transport before deletion' => ['throw_before', 'present'];
        yield 'transport after deletion' => ['throw_after', 'confirmed_absent'];
        yield 'API before deletion' => ['api_before', 'present'];
        yield 'API after deletion' => ['api_after', 'confirmed_absent'];
    }

    public function testCaptureFailureStillCleansEveryResourceAndReturnsOnlyFixedDiagnostics(): void
    {
        $http = new MysqlFixtureServer();
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))->run(
            self::probes(),
            static function (array $probes): array {
                throw new RuntimeException('private raw upstream message');
            },
        );
        self::assertSame('failed', $report['mysql_fixture']['capture']);
        self::assertSame('passed', $report['mysql_fixture']['cleanup']);
        self::assertSame([], $http->databases);
        self::assertSame([], $http->users);
        self::assertPrivate($report, $http);
    }

    #[DataProvider('unexpectedFixtures')]
    public function testFixtureAssertionsPreserveSuccessfulApiPayloadAndRawStructure(string $payload): void
    {
        $http = new MysqlFixtureServer();
        $http->overrides['list_databases:2'] = $payload;
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        $record = $report['probes'][1];
        self::assertSame('success', $record['api_outcome']);
        self::assertSame('passed', $record['payload_validation']);
        self::assertSame('failed', $record['probe_outcome']);
        self::assertSame('fixture_expectation_failed', $record['failure_kind']);
        self::assertSame('passed', $report['probes'][2]['probe_outcome']);
        self::assertSame('failed', $report['mysql_fixture']['expectations']);
        self::assertNotSame([], $record['payload_paths']);
        self::assertSame([], $http->databases);
        self::assertSame([], $http->users);
    }

    /** @return iterable<string, array{string}> */
    public static function unexpectedFixtures(): iterable
    {
        yield 'empty' => ['[]'];
        yield 'missing field' => ['[{"database":"npr_wva1b2c3d","users":["npr_wva1b2c3u"]},'
            . '{"database":"npr_wva1b2c3e","disk_usage":0,"users":[]}]'];
        yield 'link missing' => ['[{"database":"npr_wva1b2c3d","disk_usage":0,"users":[]},'
            . '{"database":"npr_wva1b2c3e","disk_usage":0,"users":[]}]'];
        yield 'unlinked not empty' => ['[{"database":"npr_wva1b2c3d","disk_usage":0,"users":["npr_wva1b2c3u"]},'
            . '{"database":"npr_wva1b2c3e","disk_usage":0,"users":["other"]}]'];
    }

    public function testExistingPayloadMismatchIsPreservedDuringFixtureValidation(): void
    {
        $http = new MysqlFixtureServer();
        $http->overrides['list_databases:2'] = '[{"disk_usage":"private-wrong"}]';
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame('payload_mismatch', $report['probes'][1]['failure_kind']);
        self::assertSame('success', $report['probes'][1]['api_outcome']);
        self::assertSame('failed', $report['probes'][1]['payload_validation']);
        self::assertSame(['string'], $report['probes'][1]['payload_paths']['$[].disk_usage']);
        self::assertSame([], $http->databases);
    }

    public function testUnsupportedRecipePreventsFixtureMutationsBeforeCapture(): void
    {
        $http = new MysqlFixtureServer();
        $probes = self::probes();
        $first = $probes[0];
        $probes[0] = new Probe(
            $first->operation,
            $first->route,
            $first->spec,
            $first->uri,
            $first->invoke,
            'unsupported',
        );
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run($probes, self::capture($http));
        self::assertSame('failed', $report['mysql_fixture']['status']);
        self::assertSame([], $http->counts);
    }

    public function testFullCatalogPassesThroughWithoutReplacingOtherProbeCallbacks(): void
    {
        $http = new MysqlFixtureServer();
        $original = ProbeCatalog::load(dirname(__DIR__, 3));
        $capture = self::capture($http);
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))->run(
            $original,
            static function (array $wrapped) use ($original, $capture): array {
                self::assertCount(43, $wrapped);
                foreach ($original as $index => $probe) {
                    if (!str_starts_with($probe->operation, 'uapi:Mysql/')) {
                        self::assertSame($probe, $wrapped[$index]);
                    }
                }

                return $capture(array_values(array_filter($wrapped, static fn (Probe $probe): bool =>
                    $probe->operation === 'whm1:version' || str_starts_with($probe->operation, 'uapi:Mysql/'))));
            },
        );
        self::assertSame('passed', $report['mysql_fixture']['status']);
    }

    public function testUnknownCleanupDoesNotBlockOtherConfirmedResources(): void
    {
        $http = new MysqlFixtureServer();
        $http->overrides['list_users:3'] = '[{}]';
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame('unconfirmed', $report['mysql_fixture']['resources']['unlinked_user']['cleanup']);
        self::assertSame(['npr_wva1b2c3v' => []], $http->users);
        self::assertSame([], $http->databases);
        self::assertSame('failed', $report['mysql_fixture']['status']);
    }

    public function testApiFailureKeepsItsOutcomeAndStillCleansFixtures(): void
    {
        $http = new MysqlFixtureServer();
        $http->failures['list_databases:2'] = 'api_before';
        $report = (new MysqlFixture($http, 'private-cpanel-token', 'a1b2c3'))
            ->run(self::probes(), self::capture($http));
        self::assertSame('failure', $report['probes'][1]['api_outcome']);
        self::assertSame('not_checked', $report['probes'][1]['payload_validation']);
        self::assertSame('api_failure', $report['probes'][1]['failure_kind']);
        self::assertSame('passed', $report['mysql_fixture']['cleanup']);
        self::assertSame([], $http->databases);
        self::assertSame([], $http->users);
    }

    /** @return list<Probe> */
    private static function probes(): array
    {
        return array_values(array_filter(ProbeCatalog::load(dirname(__DIR__, 3)), static fn (Probe $probe): bool =>
            $probe->operation === 'whm1:version' || str_starts_with($probe->operation, 'uapi:Mysql/')));
    }

    /** @return Closure(list<Probe>): list<Record> */
    private static function capture(ClientInterface $http): Closure
    {
        $runner = new EvidenceRunner($http, [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]);

        return static function (array $probes) use ($runner): array {
            self::assertTrue(array_is_list($probes));
            $checked = [];
            foreach ($probes as $probe) {
                self::assertInstanceOf(Probe::class, $probe);
                $checked[] = $probe;
            }

            return $runner->run(
                $checked,
                '1',
                ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
            );
        };
    }

    /** @param array<mixed> $report */
    private static function assertPrivate(array $report, MysqlFixtureServer $http): void
    {
        $serialized = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private', 'npr_wva1b2c3', 'existing_database', 'existing_user', ...$http->passwords] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }
}
