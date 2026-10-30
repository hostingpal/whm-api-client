<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\MysqlSectionEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\MysqlSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MysqlSectionEvidenceTest extends TestCase
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

    #[DataProvider('alterations')]
    public function testAssessmentRejectsAlteredPhaseEvidence(string $case, string $reason): void
    {
        $context = [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
        $report = (new MysqlSectionFixture(
            new MysqlSectionServer(),
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            'a1b2c3',
            $context,
        ))->run();
        self::assertSame('passed', $report['status']);
        $records = $report['probes'];
        $second = $records[1] ?? throw new RuntimeException('Missing synthetic record.');
        switch ($case) {
            case 'order':
                [$records[10], $records[19]] = [$records[19], $records[10]];
                break;
            case 'missing':
                array_pop($records);
                break;
            case 'duplicate':
                $records[] = $records[1];
                break;
            case 'phase':
                $second['phase'] = 'unrecognized';
                break;
            case 'operation':
                $second['operation'] = 'uapi:Mysql/delete_host';
                break;
            case 'method':
                $second['method'] = 'POST';
                break;
            case 'contract':
                $second['effective_contract_sha256'] = str_repeat('a', 64);
                break;
            case 'failed':
                $second['probe_outcome'] = 'failed';
                break;
            case 'expectation':
                $second['expectation'] = 'failed';
                break;
            case 'discrepancy':
                $second['discrepancies'][] = ['path' => '$.*', 'kind' => 'unknown_field'];
                break;
            case 'source':
                $second['source_sha256'] = str_repeat('a', 64);
                break;
            case 'revision':
                $second['revision'] = str_repeat('b', 40);
                break;
            case 'version':
                $second['server_version'] = '11.2.3';
                break;
            case 'missing_version':
                array_shift($records);
                break;
        }
        if (!in_array($case, ['order', 'missing', 'duplicate', 'missing_version'], true)) {
            $records[1] = $second;
        }
        $assessment = MysqlSectionEvidence::finish($records, $context);
        self::assertSame('failed', $assessment['assessment']['status']);
        self::assertContains($reason, $assessment['assessment']['findings']);
        self::assertSame($records, $assessment['probes'], 'Existing evidence outcomes must never be rewritten.');
    }

    /** @return iterable<array{string, string}> */
    public static function alterations(): iterable
    {
        yield ['order', 'phase_order_mismatch'];
        yield ['missing', 'missing_phase'];
        yield ['duplicate', 'duplicate_phase'];
        yield ['phase', 'unexpected_phase'];
        yield ['operation', 'phase_contract_mismatch'];
        yield ['method', 'phase_contract_mismatch'];
        yield ['contract', 'phase_contract_mismatch'];
        yield ['failed', 'unsuccessful_phase'];
        yield ['expectation', 'unsuccessful_phase'];
        yield ['discrepancy', 'unacknowledged_discrepancy'];
        yield ['source', 'invalid_provenance'];
        yield ['revision', 'invalid_provenance'];
        yield ['version', 'unusable_server_version'];
        yield ['missing_version', 'unusable_server_version'];
    }
}
