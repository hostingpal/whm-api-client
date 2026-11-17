<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\UserTasksSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\VersionControlSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\UserTasksEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\UserTasksFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Observation from UserTasksFixture */
final class UserTasksEvidenceTest extends TestCase
{
    /** Written out here, independently of the assessment under test. */
    private const PHASES = [
        'version', 'home', 'tasks_initial_direct', 'tasks_initial_bridged', 'source_create', 'clone_create',
        'tasks_queued_direct', 'tasks_queued_bridged', 'delete_owned', 'tasks_after_direct', 'tasks_after_bridged',
    ];

    private const PRODUCER_PHASES = ['home', 'source_create', 'clone_create'];

    public function testACompleteOrderedRunPassesBothReviewsWithoutAcknowledgments(): void
    {
        $review = self::assess(self::records());
        self::assertSame(UserTasksEvidence::PHASES, self::PHASES);
        self::assertSame('user-tasks-subject-no-acknowledgments-v2', $review['discrepancy_review']['policy']);
        self::assertSame('passed', $review['discrepancy_review']['status']);
        self::assertSame([], $review['discrepancy_review']['findings']);
        self::assertSame('user-tasks-producer-structure-v2', $review['producer_review']['policy']);
        self::assertSame('passed', $review['producer_review']['status']);
        self::assertSame([], $review['producer_review']['findings']);
    }

    /**
     * A delete the fixture rightly withheld, because no read showed the owned row, is a recorded race outcome.
     *
     * It must not read as a contract failure, so the subject review then expects no delete record at all.
     */
    public function testAWithheldDeleteIsNotAContractFailure(): void
    {
        $records = array_values(array_filter(
            self::records(),
            static fn (array $record): bool => $record['phase'] !== 'delete_owned',
        ));
        $review = UserTasksEvidence::assess($records, FilemanUploadFixtureTest::context(), false);
        self::assertSame('passed', $review['discrepancy_review']['status']);
        self::assertSame([], $review['discrepancy_review']['findings']);
        $withheld = UserTasksEvidence::assess(self::records(), FilemanUploadFixtureTest::context(), false);
        self::assertContains('unexpected_phase', $withheld['discrepancy_review']['findings']);
        $attempted = UserTasksEvidence::assess($records, FilemanUploadFixtureTest::context(), true);
        self::assertSame(['missing_phase:delete_owned'], $attempted['discrepancy_review']['findings']);
    }

    public function testAnEmptyRunFailsWithEveryPhaseMissing(): void
    {
        $review = self::assess([]);
        self::assertSame('failed', $review['discrepancy_review']['status']);
        self::assertSame('failed', $review['producer_review']['status']);
        self::assertContains('unusable_server_version', $review['discrepancy_review']['findings']);
        foreach (self::PHASES as $phase) {
            $group = in_array($phase, self::PRODUCER_PHASES, true) ? 'producer_review' : 'discrepancy_review';
            self::assertContains('missing_phase:' . $phase, $review[$group]['findings']);
        }
    }

    /** A UserTasks discrepancy fails the gating review; nothing can acknowledge it. */
    public function testASubjectDiscrepancyCannotPass(): void
    {
        $review = self::assess(self::changed('tasks_queued_bridged', static function (array $record): array {
            $record['discrepancies'] = [['path' => '$[].*', 'kind' => 'unknown_field']];

            return $record;
        }));
        self::assertSame(
            ['unacknowledged_discrepancy:tasks_queued_bridged'],
            $review['discrepancy_review']['findings'],
        );
        self::assertSame('passed', $review['producer_review']['status']);
    }

    /** A producer discrepancy is VersionControl evidence: reported and failed in its own review, never hidden. */
    public function testAProducerDiscrepancyFailsOnlyTheProducerReview(): void
    {
        $review = self::assess(self::changed('clone_create', static function (array $record): array {
            $record['discrepancies'] = [['path' => '$.tasks[].*', 'kind' => 'unknown_field']];

            return $record;
        }));
        self::assertSame('passed', $review['discrepancy_review']['status']);
        self::assertSame(['unacknowledged_discrepancy:clone_create'], $review['producer_review']['findings']);
        self::assertSame('failed', $review['producer_review']['status']);
    }

    /** A delete the server refused, after its task finished or otherwise, is no successful deletion record. */
    public function testARefusedDeleteFailsTheSubjectReview(): void
    {
        $review = self::assess(self::changed('delete_owned', static function (array $record): array {
            $record['api_outcome'] = 'failure';
            $record['probe_outcome'] = 'failed';
            $record['failure_kind'] = 'api_failure';
            $record['payload_validation'] = 'not_checked';
            $record['uapi_status'] = 0;

            return $record;
        }));
        self::assertSame(
            ['unsuccessful_phase:delete_owned', 'unexpected_uapi_status:delete_owned'],
            $review['discrepancy_review']['findings'],
        );
    }

    /** No validator runs on generic phases, so a passed claim is false however clean the rest of the record is. */
    #[DataProvider('producerPhases')]
    public function testProducerPhaseCannotClaimAValidationItNeverRan(string $phase): void
    {
        $review = self::assess(self::changed($phase, static function (array $record): array {
            $record['payload_validation'] = 'passed';

            return $record;
        }));
        self::assertSame(['unsuccessful_phase:' . $phase], $review['producer_review']['findings']);
    }

    /** @return iterable<string, array{string}> */
    public static function producerPhases(): iterable
    {
        foreach (self::PRODUCER_PHASES as $phase) {
            yield $phase => [$phase];
        }
    }

    public function testTypedPhaseMustReportPassedValidation(): void
    {
        $review = self::assess(self::changed('tasks_after_direct', static function (array $record): array {
            $record['payload_validation'] = 'not_checked';

            return $record;
        }));
        self::assertSame(['unsuccessful_phase:tasks_after_direct'], $review['discrepancy_review']['findings']);
    }

    public function testContractRouteAndProvenanceAreBoundToEachPhase(): void
    {
        $route = self::assess(self::changed('tasks_queued_bridged', static function (array $record): array {
            $record['route'] = 'direct-uapi';
            $record['source_sha256'] = FilemanUploadFixtureTest::context()['source_hashes']['uapi'];

            return $record;
        }));
        self::assertSame(['contract_mismatch:tasks_queued_bridged'], $route['discrepancy_review']['findings']);
        $spec = self::assess(self::changed('delete_owned', static function (array $record): array {
            $record['effective_contract_sha256'] = str_repeat('0', 64);

            return $record;
        }));
        self::assertSame(['contract_mismatch:delete_owned'], $spec['discrepancy_review']['findings']);
        $revision = self::assess(self::changed('source_create', static function (array $record): array {
            $record['revision'] = str_repeat('9', 40);

            return $record;
        }));
        self::assertSame(['invalid_provenance:source_create'], $revision['producer_review']['findings']);
    }

    public function testOrderDuplicatesAndUnexpectedPhasesFail(): void
    {
        $records = self::records();
        [$records[6], $records[7]] = [$records[7], $records[6]];
        self::assertContains('phase_order_mismatch', self::assess($records)['discrepancy_review']['findings']);
        $records = self::records();
        $records[] = $records[2];
        self::assertContains(
            'duplicate_phase:tasks_initial_direct',
            self::assess($records)['discrepancy_review']['findings'],
        );
        $findings = self::assess(self::changed('tasks_initial_bridged', static function (array $record): array {
            $record['phase'] = 'tasks_elsewhere';

            return $record;
        }))['discrepancy_review']['findings'];
        self::assertContains('unexpected_phase', $findings);
        self::assertContains('missing_phase:tasks_initial_bridged', $findings);
    }

    public function testEveryPhaseKeepsOneServerVersion(): void
    {
        $review = self::assess(self::changed('tasks_after_bridged', static function (array $record): array {
            $record['server_version'] = '11.138.0.20';

            return $record;
        }));
        self::assertSame(['unusable_server_version'], $review['discrepancy_review']['findings']);
    }

    public function testContractsNameThePinnedVariantAndTheSentMethod(): void
    {
        $create = UserTasksEvidence::contract('clone_create');
        self::assertSame(['uapi:VersionControl/create', 'uapi:VersionControl/create#GET', 'POST', 'direct-uapi'], [
            $create['operation'], $create['variant'], $create['method'], $create['route'],
        ]);
        self::assertSame(VersionControlSpec::CREATE, $create['spec']);
        $delete = UserTasksEvidence::contract('delete_owned');
        self::assertSame(['uapi:UserTasks/delete#GET', 'POST'], [$delete['variant'], $delete['method']]);
        self::assertSame(UserTasksSpec::DELETE, $delete['spec']);
        self::assertSame('bridged-uapi', UserTasksEvidence::contract('tasks_initial_bridged')['route']);
        self::assertSame(UserTasksSpec::RETRIEVE, UserTasksEvidence::contract('tasks_after_bridged')['spec']);
        self::assertSame('whm', UserTasksEvidence::contract('version')['route']);
    }

    /**
     * @param list<Observation> $records
     * @return array{
     *     discrepancy_review: array{policy: string, status: string, findings: list<string>},
     *     producer_review: array{policy: string, status: string, findings: list<string>}
     * }
     */
    private static function assess(array $records): array
    {
        return UserTasksEvidence::assess($records, FilemanUploadFixtureTest::context(), true);
    }

    /**
     * Return a complete run with one phase's record replaced.
     *
     * @param Closure(Observation): Observation $change
     * @return list<Observation>
     */
    private static function changed(string $phase, Closure $change): array
    {
        return array_map(
            static fn (array $record): array => $record['phase'] === $phase ? $change($record) : $record,
            self::records(),
        );
    }

    /** @return list<Observation> */
    private static function records(): array
    {
        $context = FilemanUploadFixtureTest::context();
        $records = [];
        foreach (self::PHASES as $phase) {
            $contract = UserTasksEvidence::contract($phase);
            $whm = $phase === 'version';
            $records[] = [
                'phase' => $phase, 'expectation' => 'passed', 'uapi_status' => $whm ? null : 1,
                'operation' => $contract['operation'], 'variant' => $contract['variant'],
                'route' => $contract['route'], 'method' => $contract['method'],
                'server_version' => '11.138.0.19', 'observed_at' => '2026-11-02T00:00:00Z',
                'revision' => $context['revision'],
                'source_sha256' => $context['source_hashes'][$whm ? 'whm1' : 'uapi'],
                'map_sha256' => $context['map_sha256'], 'generator_tree' => $context['generator_tree'],
                'overlay_sha256' => $context['overlay_sha256'],
                'effective_contract_sha256' => hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR)),
                'http_status' => 200, 'api_outcome' => 'success',
                'payload_validation' => in_array($phase, self::PRODUCER_PHASES, true) ? 'not_checked' : 'passed',
                'probe_outcome' => 'passed', 'failure_kind' => null, 'response_paths' => [], 'payload_paths' => [],
                'discrepancies' => [],
            ];
        }

        return $records;
    }
}
