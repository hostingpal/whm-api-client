<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\MysqlMutationEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Record from EvidenceRunner */
final class MysqlMutationEvidenceTest extends TestCase
{
    public function testFinishBindsOnlyMissingVersionsAndPreservesOriginalEvidenceAndOrder(): void
    {
        $records = array_reverse(self::records());
        $records[0] = array_replace($records[0], ['server_version' => '11.138.0.15']);
        $before = $records;
        $baseline = [self::baseline()];
        $result = MysqlMutationEvidence::finish($records, $baseline);

        self::assertSame([
            'policy' => 'mysql-mutations-no-acknowledgments-v1',
            'status' => 'passed', 'acknowledged' => [], 'unexpected' => [],
        ], $result['discrepancy_review']);
        self::assertCount(10, $result['probes']);
        self::assertSame(array_fill(0, 10, '11.138.0.15'), array_column($result['probes'], 'server_version'));
        foreach ($result['probes'] as $index => $record) {
            $record['server_version'] = $before[$index]['server_version'];
            self::assertSame($before[$index], $record);
        }
        self::assertSame($before, $records);
        self::assertSame([self::baseline()], $baseline);
    }

    public function testEmptyCaptureReportsAllTenMissingIdentitiesWithoutBorrowingBaselineSuccess(): void
    {
        $result = MysqlMutationEvidence::finish([], [self::baseline()]);
        self::assertSame([], $result['probes']);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        self::assertSame([], $result['discrepancy_review']['acknowledged']);
        self::assertSame([
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'direct-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'bridged-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/create_user',
                'variant' => 'uapi:Mysql/create_user#GET',
                'route' => 'direct-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/create_user',
                'variant' => 'uapi:Mysql/create_user#GET',
                'route' => 'bridged-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/set_privileges_on_database',
                'variant' => 'uapi:Mysql/set_privileges_on_database#GET',
                'route' => 'direct-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/set_privileges_on_database',
                'variant' => 'uapi:Mysql/set_privileges_on_database#GET',
                'route' => 'bridged-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/delete_user',
                'variant' => 'uapi:Mysql/delete_user#GET',
                'route' => 'direct-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/delete_user',
                'variant' => 'uapi:Mysql/delete_user#GET',
                'route' => 'bridged-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/delete_database',
                'variant' => 'uapi:Mysql/delete_database#GET',
                'route' => 'direct-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/delete_database',
                'variant' => 'uapi:Mysql/delete_database#GET',
                'route' => 'bridged-uapi',
                'reason' => 'missing_probe',
            ],
        ], $result['discrepancy_review']['unexpected']);
    }

    public function testMissingAndDuplicateObservationsCannotCancelEachOther(): void
    {
        $records = self::records();
        $records[0] = $records[1];
        $result = MysqlMutationEvidence::finish($records, [self::baseline()]);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        self::assertSame([
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'direct-uapi',
                'reason' => 'missing_probe',
            ],
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'bridged-uapi',
                'reason' => 'duplicate_probe',
            ],
        ], $result['discrepancy_review']['unexpected']);
        self::assertCount(10, $result['probes']);
    }

    /** @param array{operation?: string, variant?: string, route?: string} $change */
    #[DataProvider('unexpectedIdentities')]
    public function testOnlyReviewedSourceVariantAndRoutesAreAccepted(array $change): void
    {
        $records = self::records();
        $records[0] = array_replace($records[0], $change);
        $result = MysqlMutationEvidence::finish($records, [self::baseline()]);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        self::assertSame(
            ['missing_probe', 'unexpected_probe'],
            array_column($result['discrepancy_review']['unexpected'], 'reason'),
        );
        self::assertSame($records[0]['variant'], $result['probes'][0]['variant']);
    }

    /** @return iterable<string, array{array{operation?: string, variant?: string, route?: string}}> */
    public static function unexpectedIdentities(): iterable
    {
        yield 'actual POST is not the pinned variant' => [['variant' => 'uapi:Mysql/create_database#POST']];
        yield 'unreviewed function' => [['operation' => 'uapi:Mysql/rename_database']];
        yield 'wrong route' => [['route' => 'whm']];
    }

    /** @param array{method?: string|null, http_status?: int|null, api_outcome?: string, payload_validation?: string, probe_outcome?: string, failure_kind?: string|null} $change */
    #[DataProvider('unsuccessfulObservations')]
    public function testFailuresKeepTheirOriginalOutcomeAndCannotPassTheAssessment(array $change): void
    {
        $records = self::records();
        $records[0] = array_replace($records[0], $change);
        $result = MysqlMutationEvidence::finish($records, [self::baseline()]);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        self::assertSame([
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'direct-uapi',
                'reason' => 'unsuccessful_probe',
            ],
        ], $result['discrepancy_review']['unexpected']);
        $returned = $result['probes'][0];
        $returned['server_version'] = null;
        self::assertSame($records[0], $returned);
    }

    /** @return iterable<string, array{array{method?: string|null, http_status?: int|null, api_outcome?: string, payload_validation?: string, probe_outcome?: string, failure_kind?: string|null}}> */
    public static function unsuccessfulObservations(): iterable
    {
        yield 'GET mutation' => [['method' => 'GET']];
        yield 'method unavailable' => [['method' => null]];
        yield 'HTTP unavailable' => [['http_status' => null]];
        yield 'HTTP informational' => [['http_status' => 199]];
        yield 'redirect' => [['http_status' => 302]];
        yield 'HTTP failure' => [['http_status' => 500]];
        yield 'API failure' => [[
            'api_outcome' => 'failure', 'probe_outcome' => 'failed', 'failure_kind' => 'api_failure',
        ]];
        yield 'API unavailable' => [['api_outcome' => 'unavailable']];
        yield 'payload unchecked' => [['payload_validation' => 'not_checked']];
        yield 'successful mutation with bad payload' => [[
            'api_outcome' => 'success', 'payload_validation' => 'failed', 'probe_outcome' => 'failed',
            'failure_kind' => 'payload_mismatch',
        ]];
        yield 'skipped probe' => [['probe_outcome' => 'skipped']];
        yield 'retained failure' => [['failure_kind' => 'request_failure']];
    }

    public function testUnknownFieldsRemainVisibleWithoutAcknowledgments(): void
    {
        $records = self::records();
        $records[1] = array_replace($records[1], [
            'payload_paths' => ['$' => ['null'], '$.*' => ['int']],
            'discrepancies' => [['path' => '$.*', 'kind' => 'unknown_field']],
        ]);
        $result = MysqlMutationEvidence::finish($records, [self::baseline()]);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        self::assertSame([], $result['discrepancy_review']['acknowledged']);
        self::assertSame([
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'bridged-uapi',
                'reason' => 'unacknowledged_discrepancy',
            ],
        ], $result['discrepancy_review']['unexpected']);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $result['probes'][1]['discrepancies']);
        self::assertSame(['int'], $result['probes'][1]['payload_paths']['$.*']);
        self::assertSame('passed', $result['probes'][1]['probe_outcome']);
    }

    /** @param list<Record> $baseline */
    #[DataProvider('unusableBaselines')]
    public function testOnlyOneSuccessfulUsableBaselineVersionCanBeBound(array $baseline): void
    {
        $records = self::records();
        $result = MysqlMutationEvidence::finish($records, $baseline);
        self::assertSame($records, $result['probes']);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        self::assertSame(
            array_fill(0, 10, 'unusable_server_version'),
            array_column($result['discrepancy_review']['unexpected'], 'reason'),
        );
    }

    /** @return iterable<string, array{list<Record>}> */
    public static function unusableBaselines(): iterable
    {
        yield 'missing' => [[]];
        yield 'duplicate' => [[self::baseline(), self::baseline()]];
        foreach (
            [
            ['variant' => 'whm1:version#POST'], ['route' => 'bridged-uapi'], ['method' => 'POST'],
            ['server_version' => null], ['server_version' => 'unreviewed-upstream-message'],
            ['server_version' => '11.138.0.15 '], ['http_status' => 302], ['api_outcome' => 'failure'],
            ['payload_validation' => 'failed'], ['probe_outcome' => 'failed'], ['failure_kind' => 'payload_mismatch'],
            ['discrepancies' => [['path' => '$.*', 'kind' => 'unknown_field']]], ['revision' => ''],
            ] as $index => $change
        ) {
            yield 'unusable version record ' . $index => [[array_replace(self::baseline(), $change)]];
        }
    }

    public function testConflictingRecordedVersionIsPreservedAndRejected(): void
    {
        $records = self::records();
        $records[0] = array_replace($records[0], ['server_version' => '11.138.0.16']);
        $result = MysqlMutationEvidence::finish($records, [self::baseline()]);
        self::assertSame('11.138.0.16', $result['probes'][0]['server_version']);
        self::assertSame([
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'direct-uapi',
                'reason' => 'unusable_server_version',
            ],
        ], $result['discrepancy_review']['unexpected']);
    }

    /** @param array{revision?: string, source_sha256?: string, map_sha256?: string, generator_tree?: string, overlay_sha256?: string, effective_contract_sha256?: string} $change */
    #[DataProvider('invalidProvenance')]
    public function testMissingOrConflictingProvenanceCannotPass(array $change): void
    {
        $records = self::records();
        $records[0] = array_replace($records[0], $change);
        $result = MysqlMutationEvidence::finish($records, [self::baseline()]);
        self::assertSame([
            [
                'operation' => 'uapi:Mysql/create_database',
                'variant' => 'uapi:Mysql/create_database#GET',
                'route' => 'direct-uapi',
                'reason' => 'invalid_provenance',
            ],
        ], $result['discrepancy_review']['unexpected']);
    }

    /** @return iterable<string, array{array{revision?: string, source_sha256?: string, map_sha256?: string, generator_tree?: string, overlay_sha256?: string, effective_contract_sha256?: string}}> */
    public static function invalidProvenance(): iterable
    {
        yield 'revision missing' => [['revision' => '']];
        yield 'source missing' => [['source_sha256' => '']];
        yield 'map malformed' => [['map_sha256' => 'not-a-hash']];
        yield 'generator wrong length' => [['generator_tree' => str_repeat('a', 39)]];
        yield 'overlay uppercase' => [['overlay_sha256' => str_repeat('A', 64)]];
        yield 'contract missing' => [['effective_contract_sha256' => '']];
        yield 'different revision' => [['revision' => str_repeat('f', 40)]];
        yield 'different map' => [['map_sha256' => str_repeat('f', 64)]];
        yield 'different generator' => [['generator_tree' => str_repeat('f', 40)]];
        yield 'different overlay' => [['overlay_sha256' => str_repeat('f', 64)]];
    }

    public function testUnrelatedBaselineFailuresDoNotReplaceTheIndependentMutationAssessment(): void
    {
        $unrelated = self::records()[0];
        $unrelated['operation'] = 'uapi:Quota/get_quota_info';
        $unrelated['discrepancies'] = [['path' => '$.*', 'kind' => 'unknown_field']];
        $result = MysqlMutationEvidence::finish(self::records(), [$unrelated, self::baseline()]);
        self::assertSame('passed', $result['discrepancy_review']['status']);
        self::assertSame([], $result['discrepancy_review']['acknowledged']);
    }

    /** @return list<Record> */
    private static function records(): array
    {
        $records = [];
        foreach (
            [
            'uapi:Mysql/create_database', 'uapi:Mysql/create_user', 'uapi:Mysql/set_privileges_on_database',
            'uapi:Mysql/delete_user', 'uapi:Mysql/delete_database',
            ] as $operation
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $records[] = [
                    'operation' => $operation, 'variant' => $operation . '#GET', 'route' => $route,
                    'method' => 'POST', 'server_version' => null, 'observed_at' => '2026-10-17T10:00:00Z',
                    'revision' => str_repeat('a', 40), 'source_sha256' => str_repeat('b', 64),
                    'map_sha256' => str_repeat('c', 64), 'generator_tree' => str_repeat('d', 40),
                    'overlay_sha256' => str_repeat('e', 64), 'effective_contract_sha256' => str_repeat('f', 64),
                    'http_status' => 200, 'api_outcome' => 'success', 'payload_validation' => 'passed',
                    'probe_outcome' => 'passed', 'failure_kind' => null,
                    'response_paths' => ['$' => ['object'], '$.data' => ['null']],
                    'payload_paths' => ['$' => ['null']], 'discrepancies' => [],
                ];
            }
        }

        return $records;
    }

    /** @return Record */
    private static function baseline(): array
    {
        $record = self::records()[0];
        $record['operation'] = 'whm1:version';
        $record['variant'] = 'whm1:version#GET';
        $record['route'] = 'whm';
        $record['method'] = 'GET';
        $record['server_version'] = '11.138.0.15';
        $record['source_sha256'] = str_repeat('1', 64);
        $record['payload_paths'] = ['$' => ['object'], '$.version' => ['string']];

        return $record;
    }
}
