<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use HostingPal\WhmApiClient\Tools\LiveVerify\UserTasksEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\UserTasksFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @phpstan-import-type Scenario from UserTasksFixture
 * @phpstan-import-type Observation from UserTasksFixture
 */
final class UserTasksFixtureTest extends TestCase
{
    private const CREATE = 'direct:VersionControl/create';

    private const DELETE = 'direct:UserTasks/delete';

    private const CONTROL_MUTATIONS = ['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wltasks',
                'WHM_API_LIVE_DOMAIN' => 'wltasks.example.com'] as $key => $value
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

    public function testCompleteRunObservesTheOwnedTaskThroughBothRoutesAndDeletesOnlyIt(): void
    {
        $http = new UserTasksServer();
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['status'], (string) json_encode($fixture['failures']));
        self::assertSame('user-tasks-disposable-v1', $scenario['scenario']);
        self::assertSame('version-control-local-path-clone', $scenario['producer']);
        self::assertSame('passed', $scenario['discrepancy_review']['status']);
        self::assertSame([], $scenario['discrepancy_review']['findings']);
        self::assertSame([], $fixture['failures']);
        self::assertSame([], $fixture['expectation_findings']);
        self::assertSame(['passed', 'passed', 'passed', null], [
            $fixture['capture'], $fixture['expectations'], $fixture['cleanup'], $fixture['stopped_at'],
        ]);
        self::assertSame(UserTasksEvidence::PHASES, array_column($scenario['probes'], 'phase'));
        self::assertSame([
            'createacct', 'Tokens/create_full_access', self::CREATE, self::CREATE, self::DELETE,
            'Tokens/revoke', 'removeacct',
        ], $http->mutations);
        self::assertSame([
            'tasks_initial_direct' => self::read('direct', 0, 0, 0),
            'tasks_initial_bridged' => self::read('bridged', 0, 0, 0),
            'tasks_queued_direct' => self::read('direct', 1, 1, 0),
            'tasks_queued_bridged' => self::read('bridged', 1, 1, 0),
            'tasks_after_direct' => self::read('direct', 0, 0, 0),
            'tasks_after_bridged' => self::read('bridged', 0, 0, 0),
        ], $scenario['queue']);
        self::assertSame([
            'initial_queue_empty' => true, 'producer_rows' => 1, 'producer_owned_rows' => 1,
            'owned_task' => 'proven', 'id_source' => 'producer_response', 'id_is_queue_element' => true,
        ], $scenario['ownership']);
        self::assertSame([
            'attempted' => true, 'outcome' => 'deleted', 'data' => 'null', 'owned_row_in_latest_read' => true,
        ], $scenario['deletion']);
        foreach (['account', 'token'] as $resource) {
            self::assertSame(
                ['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'],
                $fixture['resources'][$resource],
            );
        }
        self::assertSame(
            ['creation' => 'confirmed', 'cleanup' => 'absent_with_account'],
            $fixture['resources']['repositories'],
        );
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $fixture['resources']['task']);
        self::assertFalse($http->account);
        self::assertSame([], $http->queue);
        self::assertPrivate($scenario);
    }

    /** Every wire type of a real queue row reaches the evidence as structure, with its value left behind. */
    public function testQueuedRowWireTypesAreRecordedAsStructureOnly(): void
    {
        $scenario = self::scenario(new UserTasksServer());
        foreach (['tasks_queued_direct', 'tasks_queued_bridged'] as $phase) {
            $paths = self::probe($scenario, $phase)['payload_paths'];
            self::assertSame(['list'], $paths['$']);
            foreach (['$[].action', '$[].id', '$[].sse_url', '$[].subsystem'] as $path) {
                self::assertSame(['string'], $paths[$path], $phase . $path);
            }
            // The queue stores exclusive as text, so the stored 0 is read back as a string of digits.
            self::assertSame(['numeric-string'], $paths['$[].exclusive']);
            self::assertSame(['object'], $paths['$[].args']);
            self::assertSame(['string'], $paths['$[].args.repository_root']);
            self::assertSame(['string'], $paths['$[].args.log_file']);
            self::assertSame([], self::probe($scenario, $phase)['discrepancies']);
        }
        self::assertSame(['null'], self::probe($scenario, 'delete_owned')['payload_paths']['$']);
    }

    /** The real create rows carry the queue's exclusive key, which VersionControl's contract does not declare. */
    public function testProducerDiscrepancyIsReportedWithoutGatingTheUserTaskReview(): void
    {
        $scenario = self::scenario(new UserTasksServer());
        self::assertSame(['unacknowledged_discrepancy:clone_create'], $scenario['producer_review']['findings']);
        self::assertSame(
            [['path' => '$.tasks[].*', 'kind' => 'unknown_field']],
            self::probe($scenario, 'clone_create')['discrepancies'],
        );
        self::assertSame('passed', $scenario['fixture']['status']);
        $http = new UserTasksServer();
        $http->producerRowsCarryExclusive = false;
        self::assertSame('passed', self::scenario($http)['producer_review']['status']);
    }

    /** A task that finished before the bridged read is recorded as such; the delete then finds nothing to remove. */
    public function testTaskFinishingBeforeTheBridgedReadIsRecordedHonestly(): void
    {
        $http = new UserTasksServer();
        $http->finishAfterReads = 2;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame('passed', $fixture['capture']);
        self::assertSame('failed', $fixture['expectations']);
        self::assertSame(['expectation_failure'], $fixture['failures']);
        self::assertSame(
            ['owned_row_absent:tasks_queued_bridged', 'owned_delete_not_confirmed'],
            $fixture['expectation_findings'],
        );
        self::assertSame(self::read('direct', 1, 1, 0), $scenario['queue']['tasks_queued_direct']);
        self::assertSame(self::read('bridged', 0, 0, 0), $scenario['queue']['tasks_queued_bridged']);
        self::assertSame([
            'attempted' => true, 'outcome' => 'api_failure', 'data' => 'null', 'owned_row_in_latest_read' => false,
        ], $scenario['deletion']);
        self::assertContains('unsuccessful_phase:delete_owned', $scenario['discrepancy_review']['findings']);
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $fixture['resources']['task']);
        self::assertSame('passed', $fixture['cleanup']);
        self::assertSame(1, count(array_keys($http->mutations, self::DELETE, true)));
    }

    /** Neither the producer response nor any read showed the task, so there is nothing to delete and nothing is. */
    public function testTaskThatNoReadShowedIsNeverDeleted(): void
    {
        $http = new UserTasksServer();
        $http->finishAfterReads = 0;
        $scenario = self::scenario($http);

        self::assertSame([
            'owned_row_absent:tasks_queued_direct', 'owned_row_absent:tasks_queued_bridged', 'owned_task_not_proven',
        ], $scenario['fixture']['expectation_findings']);
        self::assertNotContains(self::DELETE, $http->mutations);
        self::assertSame(
            ['attempted' => false, 'outcome' => 'not_attempted', 'data' => null, 'owned_row_in_latest_read' => false],
            $scenario['deletion'],
        );
        self::assertSame('not_proven', $scenario['ownership']['owned_task']);
        self::assertNull($scenario['ownership']['id_source']);
        self::assertSame(0, $scenario['ownership']['producer_owned_rows']);
        self::assertSame(['missing_phase:delete_owned'], $scenario['discrepancy_review']['findings']);
    }

    /** A queue the run did not fill is never touched: no producer runs, and nothing is deleted. */
    public function testNonEmptyInitialQueueStopsBeforeAnyMutation(): void
    {
        $http = new UserTasksServer();
        $http->afterEffect = static function (UserTasksServer $server, string $step): void {
            if ($step === 'Tokens/create_full_access:1') {
                $server->queueForeign(UserTasksServer::HOME . '/foreign');
            }
        };
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(self::CONTROL_MUTATIONS, $http->mutations);
        self::assertSame('tasks_initial_direct', $fixture['stopped_at']);
        self::assertSame(['initial_queue_not_empty:tasks_initial_direct'], $fixture['expectation_findings']);
        self::assertSame(['expectation_failure'], $fixture['failures']);
        self::assertSame('failed', $fixture['capture']);
        self::assertSame('passed', $fixture['cleanup']);
        self::assertFalse($scenario['ownership']['initial_queue_empty']);
        self::assertSame(['creation' => 'not_attempted', 'cleanup' => 'not_required'], $fixture['resources']['task']);
    }

    /** A row another producer queued after the run's is reported and left alone; only the owned one is deleted. */
    public function testForeignRowIsReportedAndNeverDeleted(): void
    {
        $http = new UserTasksServer();
        $http->afterEffect = static function (UserTasksServer $server, string $step): void {
            if ($step === 'direct:VersionControl/create:2') {
                $server->queueForeign(UserTasksServer::HOME . '/foreign');
            }
        };
        $scenario = self::scenario($http);

        self::assertSame([
            'foreign_row_present:tasks_queued_direct', 'foreign_row_present:tasks_queued_bridged',
            'foreign_row_present:tasks_after_direct', 'foreign_row_present:tasks_after_bridged',
        ], $scenario['fixture']['expectation_findings']);
        self::assertSame(self::read('direct', 2, 1, 1), $scenario['queue']['tasks_queued_direct']);
        self::assertSame(self::read('bridged', 1, 0, 1), $scenario['queue']['tasks_after_bridged']);
        self::assertSame('deleted', $scenario['deletion']['outcome']);
        $deletes = array_values(array_filter($http->requests, static fn (RequestInterface $request): bool =>
            str_ends_with($request->getUri()->getPath(), '/UserTasks/delete')));
        self::assertCount(1, $deletes);
        self::assertSame('id=00000000%2F5a9ec8dd4c3401', (string) $deletes[0]->getBody());
    }

    /** Ownership comes from the original JSON, so a typed read that fails validation still proves nothing alone. */
    public function testTypedReadMismatchAfterTheProducerKeepsTheOwnedDeleteAndFailsCapture(): void
    {
        $http = new UserTasksServer();
        $http->rowFields = ['exclusive' => null];
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('payload_mismatch', self::probe($scenario, 'tasks_queued_direct')['failure_kind']);
        self::assertSame('payload_mismatch', self::probe($scenario, 'tasks_queued_bridged')['failure_kind']);
        self::assertSame('deleted', $scenario['deletion']['outcome']);
        self::assertSame('producer_response', $scenario['ownership']['id_source']);
        self::assertSame('failed', $fixture['capture']);
        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertNull($fixture['stopped_at']);
        self::assertSame([
            'unsuccessful_phase:tasks_queued_direct', 'unacknowledged_discrepancy:tasks_queued_direct',
            'unsuccessful_phase:tasks_queued_bridged', 'unacknowledged_discrepancy:tasks_queued_bridged',
        ], $scenario['discrepancy_review']['findings']);
    }

    public function testHomeMismatchStopsBeforeAnyRecipeMutation(): void
    {
        $http = new UserTasksServer();
        $http->home = '/home2/wltasks';
        $scenario = self::scenario($http);

        self::assertSame(self::CONTROL_MUTATIONS, $http->mutations);
        self::assertSame('home', $scenario['fixture']['stopped_at']);
        self::assertSame(['home_mismatch:home'], $scenario['fixture']['expectation_findings']);
        self::assertSame(
            ['creation' => 'not_attempted', 'cleanup' => 'not_required'],
            $scenario['fixture']['resources']['repositories'],
        );
    }

    #[DataProvider('failureModes')]
    public function testSourceFailureStopsBeforeTheProducer(string $mode): void
    {
        $http = new UserTasksServer();
        $http->failures = [self::CREATE . ':1' => $mode];
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('source_create', $fixture['stopped_at']);
        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertSame(
            str_ends_with($mode, '_before') ? 0 : 1,
            count(array_keys($http->mutations, self::CREATE, true)),
        );
        self::assertSame(['creation' => 'not_attempted', 'cleanup' => 'not_required'], $fixture['resources']['task']);
        self::assertSame('unconfirmed', $fixture['resources']['repositories']['creation']);
        self::assertSame('absent_with_account', $fixture['resources']['repositories']['cleanup']);
        self::assertSame('passed', $fixture['cleanup']);
    }

    #[DataProvider('failureModes')]
    public function testProducerFailureStopsWithoutAnyDelete(string $mode): void
    {
        $http = new UserTasksServer();
        $http->failures = [self::CREATE . ':2' => $mode];
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('clone_create', $fixture['stopped_at']);
        self::assertNotContains(self::DELETE, $http->mutations);
        self::assertSame('unconfirmed', $fixture['resources']['task']['creation']);
        self::assertSame('absent_with_account', $fixture['resources']['task']['cleanup']);
        self::assertSame('passed', $fixture['cleanup']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string}> */
    public static function failureModes(): iterable
    {
        foreach (['throw_before', 'throw_after', 'api_before', 'http_after', 'invalid_after'] as $mode) {
            yield $mode => [$mode];
        }
    }

    /** A lost delete reply is one attempt: never retried, and the reads that follow still say what remains. */
    public function testLostDeleteReplyIsNeverRetried(): void
    {
        $http = new UserTasksServer();
        $http->failures = [self::DELETE . ':1' => 'throw_after'];
        $scenario = self::scenario($http);

        self::assertSame(1, count(array_keys($http->mutations, self::DELETE, true)));
        self::assertSame('request_failure', $scenario['deletion']['outcome']);
        self::assertSame(
            ['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'],
            $scenario['fixture']['resources']['task'],
        );
        self::assertContains('owned_delete_not_confirmed', $scenario['fixture']['expectation_findings']);
        self::assertSame(['capture_failure', 'expectation_failure'], $scenario['fixture']['failures']);
    }

    /** Every field a clean lifecycle reports reaches this report unchanged, so a new one cannot be left out. */
    public function testReportCarriesEveryLifecycleField(): void
    {
        $fixture = self::scenario(new UserTasksServer())['fixture'];
        foreach (CleanLifecycleRun::reportFields() as $field => $value) {
            if ($field === 'resources') {
                foreach ($value as $resource => $outcome) {
                    self::assertSame($outcome, $fixture['resources'][$resource], $resource);
                }
                continue;
            }
            self::assertSame($value, $fixture[$field], $field);
        }
    }

    public function testFilesystemProofOutcomeReachesTheReportAndTheLimitations(): void
    {
        $proof = RecordingFilesystemProof::after(['outcome' => 'confirmed_absent', 'residue' => []]);
        $scenario = self::scenario(new UserTasksServer(), $proof);
        self::assertSame('confirmed_absent', $scenario['fixture']['filesystem_cleanup']);
        self::assertSame('passed', $scenario['fixture']['filesystem_control']['status']);
        self::assertSame([['wltasks', 'wltasks.example.com'], ['wltasks', 'wltasks.example.com']], $proof->calls);
        self::assertContains('filesystem_proof_covers_fixed_locations_at_one_moment', $scenario['limitations']);
        $without = self::scenario(new UserTasksServer());
        self::assertContains(
            'api_absence_only_no_filesystem_or_home_directory_cleanup_claim',
            $without['limitations'],
        );
    }

    /** The fixture, its guard, recipe, lifecycle and scenario never keep the marker; only the server double saw it. */
    public function testNoPublicObjectHoldsTheMarker(): void
    {
        $http = new UserTasksServer();
        $fixture = new UserTasksFixture($http, 'WHMTOKEN', FilemanUploadFixtureTest::context());
        $scenario = $fixture->run();
        $marker = null;
        foreach ($http->requests as $request) {
            if (str_contains((string) $request->getUri(), '/createacct?')) {
                parse_str((string) $request->getBody(), $fields);
                $marker = $fields['contactemail'] ?? null;
            }
        }
        self::assertIsString($marker);
        foreach ([$marker, rawurlencode($marker)] as $form) {
            MarkerExposure::assertNeverExposed($form, $scenario);
            MarkerExposure::assertNeverExposed($form, $fixture, $http);
        }
    }

    public function testMissingOptInSendsNothing(): void
    {
        putenv('WHM_API_LIVE_MUTATIONS=0');
        $http = new UserTasksServer();
        $scenario = self::scenario($http);
        self::assertSame([], $http->requests);
        self::assertSame(['configuration_failure'], $scenario['fixture']['failures']);
        self::assertSame('failed', $scenario['fixture']['status']);
    }

    /** @return array{route: string, observed: bool, list: bool, rows: int, owned_rows: int, foreign_rows: int} */
    private static function read(string $route, int $rows, int $owned, int $foreign): array
    {
        return ['route' => $route, 'observed' => true, 'list' => true, 'rows' => $rows, 'owned_rows' => $owned,
            'foreign_rows' => $foreign];
    }

    /**
     * @param Scenario $scenario
     * @return Observation
     */
    private static function probe(array $scenario, string $phase): array
    {
        foreach ($scenario['probes'] as $record) {
            if ($record['phase'] === $phase) {
                return $record;
            }
        }
        self::fail('Phase ' . $phase . ' was not recorded.');
    }

    /** @return Scenario */
    private static function scenario(UserTasksServer $http, ?FilesystemAbsenceProof $filesystem = null): array
    {
        return (new UserTasksFixture($http, 'WHMTOKEN', FilemanUploadFixtureTest::context(), $filesystem))->run();
    }

    /** @param array<mixed> $scenario */
    private static function assertPrivate(array $scenario): void
    {
        $json = json_encode($scenario, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (
            ['private', 'WHMTOKEN', UserTasksServer::TOKEN, 'wltasks', 'example.com', '/home', '5a9ec8dd',
                '00000000', '/sse/', 'vc_', 'origin', '1790000000'] as $value
        ) {
            self::assertStringNotContainsString($value, $json);
        }
    }
}
