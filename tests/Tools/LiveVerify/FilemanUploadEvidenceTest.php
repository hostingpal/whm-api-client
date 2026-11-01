<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadFixture;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Observation from FilemanUploadFixture */
final class FilemanUploadEvidenceTest extends TestCase
{
    public function testACompleteOrderedRunPassesWithNoAcknowledgmentMechanism(): void
    {
        $assessment = self::assess(self::records());
        self::assertSame('passed', $assessment['status']);
        self::assertSame('fileman-upload-no-acknowledgments-v1', $assessment['policy']);
        self::assertSame([], $assessment['findings']);
    }

    public function testAnEmptyRunFailsWithEveryPhaseMissing(): void
    {
        $assessment = self::assess([]);
        self::assertSame('failed', $assessment['status']);
        self::assertContains('unusable_server_version', $assessment['findings']);
        foreach (FilemanUploadEvidence::PHASES as $phase) {
            self::assertContains('missing_phase:' . $phase, $assessment['findings']);
        }
    }

    public function testADiscrepancyCannotPassEvenWhenEveryOutcomePassed(): void
    {
        $records = self::changed('upload_success', static function (array $record): array {
            $record['discrepancies'] = [['path' => '$.*', 'kind' => 'unknown_field']];

            return $record;
        });
        self::assertSame(['unacknowledged_discrepancy:upload_success'], self::assess($records)['findings']);
    }

    public function testUnsuccessfulExpectationFailsTheRecordedPhase(): void
    {
        $records = self::changed('read_bytes', static function (array $record): array {
            $record['expectation'] = 'failed';

            return $record;
        });
        self::assertSame(['unsuccessful_phase:read_bytes'], self::assess($records)['findings']);
    }

    public function testStatusZeroIsExpectedOnlyForTheAllFailedBatch(): void
    {
        $records = self::changed('upload_all_failed', static function (array $record): array {
            $record['uapi_status'] = 1;

            return $record;
        });
        self::assertSame(['unexpected_uapi_status:upload_all_failed'], self::assess($records)['findings']);

        $records = self::changed('upload_success', static function (array $record): array {
            $record['uapi_status'] = 0;

            return $record;
        });
        self::assertSame(['unexpected_uapi_status:upload_success'], self::assess($records)['findings']);
    }

    public function testDuplicateMissingAndReorderedPhasesFail(): void
    {
        $records = self::records();
        self::assertContains(
            'duplicate_phase:version',
            self::assess([...$records, ...array_slice($records, 0, 1)])['findings'],
        );

        $rows = array_filter($records, static fn (array $record): bool => $record['phase'] !== 'inventory_success');
        self::assertSame(['missing_phase:inventory_success'], self::assess(array_values($rows))['findings']);

        self::assertSame(['phase_order_mismatch'], self::assess(array_reverse($records))['findings']);
    }

    public function testContractsAndProvenanceMustMatchThePhase(): void
    {
        $records = self::changed('upload_success', static function (array $record): array {
            $record['effective_contract_sha256'] = str_repeat('0', 64);

            return $record;
        });
        self::assertSame(['contract_mismatch:upload_success'], self::assess($records)['findings']);

        $records = self::changed('upload_success', static function (array $record): array {
            $record['variant'] = 'uapi:Fileman/upload_files#GET';

            return $record;
        });
        self::assertSame(['contract_mismatch:upload_success'], self::assess($records)['findings']);

        $records = self::changed('upload_success', static function (array $record): array {
            $record['revision'] = str_repeat('0', 40);

            return $record;
        });
        self::assertSame(['invalid_provenance:upload_success'], self::assess($records)['findings']);

        $records = self::changed('upload_success', static function (array $record): array {
            $record['source_sha256'] = str_repeat('b', 64);

            return $record;
        });
        self::assertSame(['invalid_provenance:upload_success'], self::assess($records)['findings']);
    }

    public function testVersionMustBeUsableAndSharedByEveryRecord(): void
    {
        $records = self::changed('version', static function (array $record): array {
            $record['server_version'] = 'unknown';

            return $record;
        });
        self::assertContains('unusable_server_version', self::assess($records)['findings']);

        $records = self::changed('read_empty', static function (array $record): array {
            $record['server_version'] = '11.138.0.20';

            return $record;
        });
        self::assertSame(['unusable_server_version'], self::assess($records)['findings']);
    }

    public function testAnUnknownPhaseIsReportedWithoutItsName(): void
    {
        $records = self::changed('upload_success', static function (array $record): array {
            $record['phase'] = 'private-phase';

            return $record;
        });
        self::assertContains('unexpected_phase', self::assess($records)['findings']);
        self::assertStringNotContainsString('private', (string) json_encode(self::assess($records)));
    }

    public function testDeleteIsPinnedAsGetAndSentAsPostByItsGeneratedSpec(): void
    {
        $contract = FilemanUploadEvidence::contract('delete_bytes');
        self::assertSame('uapi:Fileman/delete_file#GET', $contract['variant']);
        self::assertSame('POST', $contract['method']);
        self::assertSame(FilemanSpec::DELETE_FILE, $contract['spec']);
        self::assertSame('uapi:Fileman/upload_files#POST', FilemanUploadEvidence::contract('upload_raw')['variant']);
        self::assertSame(
            'uapi:Fileman/get_file_content#GET',
            FilemanUploadEvidence::contract('reread_bytes')['variant'],
        );
        self::assertSame('uapi:Fileman/list_files#GET', FilemanUploadEvidence::contract('home_final')['variant']);
    }

    /**
     * @param list<Observation> $records
     * @return array{policy: string, status: string, findings: list<string>}
     */
    private static function assess(array $records): array
    {
        return FilemanUploadEvidence::assess($records, FilemanUploadFixtureTest::context());
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
        foreach (FilemanUploadEvidence::PHASES as $phase) {
            $contract = FilemanUploadEvidence::contract($phase);
            $whm = $phase === 'version';
            $records[] = [
                'phase' => $phase, 'expectation' => 'passed',
                'uapi_status' => $whm ? null : ($phase === 'upload_all_failed' ? 0 : 1),
                'operation' => $contract['operation'], 'variant' => $contract['variant'],
                'route' => $whm ? 'whm' : 'direct-uapi', 'method' => $contract['method'],
                'server_version' => '11.138.0.19', 'observed_at' => '2026-11-01T00:00:00Z',
                'revision' => $context['revision'],
                'source_sha256' => $context['source_hashes'][$whm ? 'whm1' : 'uapi'],
                'map_sha256' => $context['map_sha256'], 'generator_tree' => $context['generator_tree'],
                'overlay_sha256' => $context['overlay_sha256'],
                'effective_contract_sha256' => hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR)),
                'http_status' => 200, 'api_outcome' => 'success', 'payload_validation' => 'passed',
                'probe_outcome' => 'passed', 'failure_kind' => null, 'response_paths' => [], 'payload_paths' => [],
                'discrepancies' => [],
            ];
        }

        return $records;
    }
}
