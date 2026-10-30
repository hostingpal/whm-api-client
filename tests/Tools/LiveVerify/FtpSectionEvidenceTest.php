<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\FtpSectionEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\FtpSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Observation from FtpSectionFixture */
final class FtpSectionEvidenceTest extends TestCase
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

    #[DataProvider('invalidRecords')]
    public function testRejectsMissingDuplicateReorderedFailedOrUnattributableObservations(string $fault): void
    {
        $records = FtpSectionFixtureTest::fixture(new FtpSectionServer())->run()['probes'];
        self::assertCount(41, $records);
        self::assertArrayHasKey(0, $records);
        self::assertArrayHasKey(1, $records);
        self::assertArrayHasKey(2, $records);
        self::assertArrayHasKey(9, $records);
        switch ($fault) {
            case 'missing':
                array_pop($records);
                break;
            case 'duplicate':
                $records[] = $records[1];
                break;
            case 'order':
                [$records[1], $records[2]] = [$records[2], $records[1]];
                break;
            default:
                $records[1] = self::corrupt($records[1], $fault);
        }
        $result = FtpSectionEvidence::finish($records, FtpSectionFixtureTest::context());
        self::assertSame('failed', $result['assessment']['status']);
        self::assertNotEmpty($result['assessment']['findings']);
        if ($fault === 'discrepancy') {
            self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $result['probes'][1]['discrepancies']);
        }
    }

    /** @param Observation $record
     * @return Observation
     */
    private static function corrupt(array $record, string $fault): array
    {
        switch ($fault) {
            case 'discrepancy':
                $record['discrepancies'][] = ['path' => '$.*', 'kind' => 'unknown_field'];
                break;
            case 'version':
                $record['server_version'] = 'private arbitrary text';
                break;
            case 'phase':
                $record['phase'] = 'unreviewed';
                break;
            case 'semantic':
                $record['expectation'] = 'failed';
                break;
            case 'outcome':
                $record['api_outcome'] = 'failure';
                break;
            case 'payload':
                $record['payload_validation'] = 'failed';
                break;
            case 'method':
                $record['method'] = 'POST';
                break;
            case 'contract':
                $record['effective_contract_sha256'] = str_repeat('0', 64);
                break;
            case 'revision':
                $record['revision'] = str_repeat('0', 40);
                break;
            case 'source':
                $record['source_sha256'] = str_repeat('0', 64);
                break;
            case 'status':
                $record['http_status'] = 500;
                break;
        }

        return $record;
    }

    /** @return iterable<array{string}> */
    public static function invalidRecords(): iterable
    {
        foreach (
            ['missing', 'duplicate', 'order', 'discrepancy', 'version', 'phase', 'semantic',
            'outcome', 'payload', 'method', 'contract', 'revision', 'source', 'status'] as $fault
        ) {
            yield [$fault];
        }
    }
}
