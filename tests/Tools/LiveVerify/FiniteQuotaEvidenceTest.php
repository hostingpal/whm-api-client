<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\FiniteQuotaEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use Http\Mock\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 */
final class FiniteQuotaEvidenceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const TOTAL = '{"megabyte_limit":"64","megabytes_used":1.25,"megabytes_remain":"62.75",'
        . '"under_megabyte_limit":"1","under_inode_limit":1,"under_quota_overall":1}';
    private const LOCAL = '{"byte_limit":67108864,"bytes_used":"2097152","inode_limit":null,"inodes_used":8}';

    private string|false $live;

    protected function setUp(): void
    {
        $this->live = getenv('WHM_API_LIVE');
        putenv('WHM_API_LIVE=1');
    }

    protected function tearDown(): void
    {
        putenv($this->live === false ? 'WHM_API_LIVE' : 'WHM_API_LIVE=' . $this->live);
    }

    public function testMissingLiveOptInFailsBeforeAnyRequest(): void
    {
        putenv('WHM_API_LIVE');
        $http = self::http();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Evidence capture requires WHM_API_LIVE=1 and both test tokens.');
        try {
            (new FiniteQuotaEvidence($http, self::context()))->run(ProbeCatalog::load(self::ROOT), 'whm', 'cpanel');
        } finally {
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testSeparateFiniteBatchUsesBothAccountBoundRoutesAndOneResponsePerProbe(): void
    {
        $http = self::http();
        $catalog = ProbeCatalog::load(self::ROOT);

        $result = (new FiniteQuotaEvidence($http, self::context()))->run($catalog, 'private-whm', 'private-cpanel');

        self::assertCount(43, $catalog);
        self::assertSame('passed', $result['expectations']);
        self::assertSame(
            ['direct-uapi' => 'binary-mib', 'bridged-uapi' => 'binary-mib'],
            $result['unit_relationships'],
        );
        self::assertSame([
            'policy' => 'finite-quota-no-acknowledgments-v1',
            'status' => 'passed',
            'acknowledged' => [],
            'unexpected' => [],
        ], $result['discrepancy_review']);
        self::assertSame([
            'whm1:version', 'uapi:Quota/get_quota_info', 'uapi:Quota/get_quota_info',
            'uapi:Quota/get_local_quota_info', 'uapi:Quota/get_local_quota_info',
        ], array_column($result['probes'], 'operation'));
        $requests = $http->getRequests();
        self::assertCount(5, $requests);
        $uris = [
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            'https://test.oblak.host:2083/execute/Quota/get_quota_info',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wqfinite'
                . '&cpanel.module=Quota&cpanel.function=get_quota_info',
            'https://test.oblak.host:2083/execute/Quota/get_local_quota_info',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wqfinite'
                . '&cpanel.module=Quota&cpanel.function=get_local_quota_info',
        ];
        foreach ($requests as $index => $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame($uris[$index], (string) $request->getUri());
            self::assertSame('', (string) $request->getBody());
            self::assertSame(
                in_array($index, [1, 3], true) ? 'cpanel wqfinite:private-cpanel' : 'whm root:private-whm',
                $request->getHeaderLine('Authorization'),
            );
        }
        self::assertSame(['numeric-string'], $result['probes'][1]['payload_paths']['$.megabyte_limit']);
        self::assertSame(['numeric-string'], $result['probes'][3]['payload_paths']['$.bytes_used']);
        self::assertSame(['null'], $result['probes'][3]['payload_paths']['$.inode_limit']);
        self::assertSame(array_fill(0, 5, '11.138.0.15'), array_column($result['probes'], 'server_version'));
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['private-whm', 'private-cpanel', '67108864', '2097152', '62.75', 'private-warning'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        $baseline = new Client();
        $baseline->addResponse(new Response(200, [], self::envelope(1, self::TOTAL)));
        (new EvidenceRunner($baseline, self::context()))->run(
            [array_values(array_filter($catalog, static fn (Probe $probe): bool =>
                $probe->operation === 'uapi:Quota/get_quota_info' && $probe->route === 'direct-uapi'))[0]],
            '1',
            ['whm' => 'baseline-whm', 'cpanel' => 'baseline-cpanel'],
        );
        self::assertSame('cpanel npr:baseline-cpanel', $baseline->getRequests()[0]->getHeaderLine('Authorization'));
    }

    public function testFiniteExpectationsDoNotInventCrossRequestOrFloatingPointEqualities(): void
    {
        $total = '{"megabyte_limit":64.0,"megabytes_used":0.1,"megabytes_remain":63.89,'
            . '"under_megabyte_limit":1,"under_inode_limit":0,"under_quota_overall":0}';
        $result = (new FiniteQuotaEvidence(self::http([
            1 => $total,
            2 => self::TOTAL,
            3 => '{"byte_limit":64000000,"bytes_used":64000000}',
            4 => '{"byte_limit":60000000,"bytes_used":0,"inode_limit":null}',
        ]), self::context()))->run(ProbeCatalog::load(self::ROOT), 'whm', 'cpanel');

        self::assertSame('passed', $result['expectations']);
        self::assertSame('passed', $result['discrepancy_review']['status']);
        self::assertSame(['direct-uapi' => 'decimal-mb', 'bridged-uapi' => 'other'], $result['unit_relationships']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function failedExpectations(): iterable
    {
        yield 'unlimited total' => [1, '{"megabyte_limit":0}'];
        yield 'different configured total' => [2, str_replace('"64"', '"32"', self::TOTAL)];
        yield 'negative total usage' => [1, str_replace('1.25', '-0.1', self::TOTAL)];
        yield 'total usage reaches limit' => [2, str_replace('1.25', '64', self::TOTAL)];
        yield 'no total remaining' => [1, str_replace('"62.75"', '"0"', self::TOTAL)];
        yield 'remaining exceeds limit' => [2, str_replace('"62.75"', '"65"', self::TOTAL)];
        yield 'missing flags' => [1, '{"megabyte_limit":64,"megabytes_used":1,"megabytes_remain":63}'];
        yield 'incoherent byte flag' => [
            2, str_replace('"under_megabyte_limit":"1"', '"under_megabyte_limit":0', self::TOTAL),
        ];
        yield 'incoherent overall flag' => [
            1, str_replace('"under_quota_overall":1', '"under_quota_overall":0', self::TOTAL),
        ];
        yield 'null local limit' => [3, '{"byte_limit":null,"bytes_used":0}'];
        yield 'zero local limit' => [4, '{"byte_limit":0,"bytes_used":0}'];
        yield 'negative local limit' => [3, '{"byte_limit":-1,"bytes_used":0}'];
        yield 'missing local usage' => [4, '{"byte_limit":67108864}'];
        yield 'negative local usage' => [3, '{"byte_limit":67108864,"bytes_used":-1}'];
        yield 'local usage exceeds limit' => [4, '{"byte_limit":67108864,"bytes_used":67108865}'];
    }

    #[DataProvider('failedExpectations')]
    public function testFiniteExpectationFailuresPreserveSuccessfulOutcomeAndRawEvidence(
        int $index,
        string $payload,
    ): void {
        $result = (new FiniteQuotaEvidence(self::http([$index => $payload]), self::context()))->run(
            ProbeCatalog::load(self::ROOT),
            'whm',
            'cpanel',
        );

        self::assertSame('failed', $result['expectations']);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        $record = $result['probes'][$index];
        self::assertSame('failed', $record['probe_outcome']);
        self::assertSame('finite_quota_expectation_failed', $record['failure_kind']);
        self::assertSame('success', $record['api_outcome']);
        self::assertSame('passed', $record['payload_validation']);
        self::assertSame(200, $record['http_status']);
        self::assertNotEmpty($record['payload_paths']);
        self::assertSame([], $record['discrepancies']);
    }

    public function testRawDiscrepanciesCannotBorrowBaselineAcknowledgments(): void
    {
        $payload = substr(self::TOTAL, 0, -1) . ',"private-unknown-marker":1}';
        $result = (new FiniteQuotaEvidence(self::http([1 => $payload, 2 => $payload]), self::context()))->run(
            ProbeCatalog::load(self::ROOT),
            'whm',
            'cpanel',
        );

        self::assertSame('passed', $result['expectations']);
        self::assertSame('failed', $result['discrepancy_review']['status']);
        self::assertSame([], $result['discrepancy_review']['acknowledged']);
        foreach ([1, 2] as $index) {
            self::assertSame(
                [['path' => '$.*', 'kind' => 'unknown_field']],
                $result['probes'][$index]['discrepancies'],
            );
            self::assertSame(['int'], $result['probes'][$index]['payload_paths']['$.*']);
        }
        self::assertSame(
            ['unacknowledged_discrepancy', 'unacknowledged_discrepancy'],
            array_column($result['discrepancy_review']['unexpected'], 'reason'),
        );
        self::assertStringNotContainsString('private-unknown-marker', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testExistingPayloadFailureIsNotReclassifiedAsAnExpectationFailure(): void
    {
        $http = self::http([3 => '{"byte_limit":"private-invalid","bytes_used":0}']);
        $result = (new FiniteQuotaEvidence($http, self::context()))->run(
            ProbeCatalog::load(self::ROOT),
            'whm',
            'cpanel',
        );

        self::assertSame('failed', $result['expectations']);
        self::assertSame('payload_mismatch', $result['probes'][3]['failure_kind']);
        self::assertSame('failed', $result['probes'][3]['payload_validation']);
        self::assertSame('unavailable', $result['unit_relationships']['direct-uapi']);
        self::assertNotEmpty($result['probes'][3]['discrepancies']);
        self::assertStringNotContainsString('private-invalid', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUnavailableOrDuplicateCatalogRecipesFailBeforeIo(): void
    {
        $catalog = ProbeCatalog::load(self::ROOT);
        $selected = array_values(array_filter($catalog, static fn (Probe $probe): bool =>
            $probe->operation === 'uapi:Quota/get_quota_info' && $probe->route === 'direct-uapi'))[0];
        $unsupported = new Probe(
            $selected->operation,
            $selected->route,
            $selected->spec,
            $selected->uri,
            $selected->invoke,
            'unsupported',
        );
        $altered = new Probe($selected->operation, $selected->route, [], $selected->uri, $selected->invoke);
        foreach ([[], [...$catalog, $selected], [$catalog[0], $unsupported], [$catalog[0], $altered]] as $probes) {
            $http = new Client();
            try {
                (new FiniteQuotaEvidence($http, self::context()))->run($probes, 'whm', 'cpanel');
                self::fail('Incomplete or changed finite recipes must refuse before requests.');
            } catch (RuntimeException $error) {
                self::assertSame(
                    'Finite Quota evidence requires the five reviewed catalog recipes.',
                    $error->getMessage(),
                );
            }
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testAssessmentRequiresExactlyFiveSuccessfulGetIdentitiesAndUsableConsistentVersion(): void
    {
        $records = self::records();
        self::assertSame('passed', FiniteQuotaEvidence::assess(array_reverse($records))['status']);
        $cases = [];
        $cases['empty'] = [];
        $cases['missing'] = array_slice($records, 1);
        $cases['duplicate'] = [...$records, $records[1]];
        $cases['wrong identity'] = $records;
        $cases['wrong identity'][1]['operation'] = 'uapi:Quota/private-function';
        $fields = ['method', 'api_outcome', 'payload_validation', 'probe_outcome', 'failure_kind', 'variant'];
        foreach ($fields as $field) {
            $cases[$field] = $records;
            $cases[$field][1][$field] = 'failed';
        }
        $cases['http'] = $records;
        $cases['http'][1]['http_status'] = 302;
        $cases['null version'] = $records;
        $cases['null version'][0]['server_version'] = null;
        $cases['bad version'] = $records;
        $cases['bad version'][1]['server_version'] = 'private-upstream';
        $cases['different version'] = $records;
        $cases['different version'][2]['server_version'] = '11.138.0.16';
        foreach ($cases as $name => $case) {
            $before = $case;
            $assessment = FiniteQuotaEvidence::assess($case);
            self::assertSame('failed', $assessment['status'], $name);
            self::assertSame([], $assessment['acknowledged'], $name);
            self::assertNotEmpty($assessment['unexpected'], $name);
            self::assertSame($before, $case, $name);
        }
    }

    /** @return list<Record> */
    private static function records(): array
    {
        return (new FiniteQuotaEvidence(self::http(), self::context()))->run(
            ProbeCatalog::load(self::ROOT),
            'whm',
            'cpanel',
        )['probes'];
    }

    /** @param array<int, string> $payloads */
    private static function http(array $payloads = []): Client
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.15"}}'));
        foreach ([1 => self::TOTAL, 2 => self::TOTAL, 3 => self::LOCAL, 4 => self::LOCAL] as $index => $default) {
            $http->addResponse(new Response(200, [], self::envelope($index, $payloads[$index] ?? $default)));
        }

        return $http;
    }

    private static function envelope(int $index, string $payload): string
    {
        $inner = '{"status":1,"data":' . $payload . ',"warnings":["private-warning"]}';

        return in_array($index, [1, 3], true) ? $inner : '{"metadata":{"result":1},"data":{"uapi":' . $inner . '}}';
    }

    /** @return Context */
    private static function context(): array
    {
        return [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
    }
}
