<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\ResourceStatisticsReviewProbes;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Context from \HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner */
final class ResourceStatisticsReviewProbesTest extends TestCase
{
    public function testReviewExecutesTwentySevenExactGetsIncludingEveryRequestedArgumentVariant(): void
    {
        $http = self::responses();
        $probes = self::probes();
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            $probes,
            'resource-statistics-review-v1',
            '1',
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
        );
        self::assertSame('passed', $report['status']);
        self::assertCount(27, $report['probes']);
        self::assertCount(27, $http->getRequests());
        $expected = self::expectedRequests();
        foreach ($http->getRequests() as $index => $request) {
            self::assertSame($expected[$index][0], $report['probes'][$index]['operation']);
            self::assertSame($expected[$index][1], (string) $request->getUri());
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
            self::assertSame(
                $request->getUri()->getPort() === 2083 ? 'cpanel npr:private-cpanel' : 'whm root:private-whm',
                $request->getHeaderLine('Authorization'),
            );
        }
        foreach (
            [5 => 'grouping-domain', 7 => 'grouping-domain-protocol',
                9 => 'grouping-domain-protocol-year'] as $index => $label
        ) {
            self::assertSame($label, $report['probes'][$index]['argument_variant'] ?? null);
            self::assertSame($label, $report['probes'][$index + 1]['argument_variant'] ?? null);
            self::assertSame('uapi:Bandwidth/query#GET', $report['probes'][$index]['variant']);
        }
        self::assertSame(['int'], $report['probes'][5]['payload_paths']['$.*']);
        self::assertSame(['float'], $report['probes'][7]['payload_paths']['$.*.*']);
        self::assertSame(['int'], $report['probes'][9]['payload_paths']['$.*.*.*']);
        self::assertSame(['missing', 'null', 'string'], $report['probes'][13]['payload_paths']['$[].count']);
        self::assertSame(['list', 'string'], $report['probes'][13]['payload_paths']['$[].role']);
        self::assertSame(['missing', 'numeric-string'], $report['probes'][13]['payload_paths']['$[].percent']);
        self::assertSame(['string'], $report['probes'][17]['payload_paths']['$[].entry']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (
            ['private-domain', 'private-protocol', 'private-date', 'private-log-content',
                'private-whm', 'private-cpanel', 'private-stat-value', '/private/report.html', 'npr.rs'] as $value
        ) {
            self::assertStringNotContainsString($value, $json);
        }
    }

    public function testEveryCatalogGuardRejectsChangedTargetMethodAndBodyBeforeIo(): void
    {
        foreach (self::probes() as $probe) {
            $http = new Client();
            $guard = new RequestGuard($http, $probe->uri);
            $requests = [
                new Request('POST', $probe->uri),
                new Request('GET', $probe->uri, [], 'body'),
                new Request('GET', str_replace('test.oblak.host', 'other.example', $probe->uri)),
                new Request('GET', $probe->uri . '&cpanel.user=other'),
                new Request('GET', $probe->uri . '&domain=other.example'),
            ];
            if (str_contains($probe->uri, 'grouping=')) {
                $requests[] = new Request('GET', str_replace('1790812800', '1', $probe->uri));
                $requests[] = new Request('GET', str_replace('grouping=domain', 'grouping=protocol', $probe->uri));
            }
            foreach ($requests as $request) {
                try {
                    $guard->sendRequest($request);
                    self::fail('Changed request escaped the reviewed GET recipe.');
                } catch (LogicException) {
                    self::assertSame([], $http->getRequests());
                }
            }
        }
    }

    #[DataProvider('failedResponses')]
    public function testFailuresRemainStrictWithoutRetriesOrRawLogAndUnknownFieldPersistence(
        int $index,
        string $payload,
        bool $apiFailure,
        string $failure,
    ): void {
        $http = self::responses($index, $payload, $apiFailure);
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            self::probes(),
            'resource-statistics-review-v1',
            '1',
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
        );
        self::assertSame('failed', $report['status']);
        self::assertCount(27, $http->getRequests());
        self::assertCount(27, $report['probes']);
        $record = $report['probes'][$index];
        if ($failure === 'unknown_field') {
            self::assertSame('passed', $record['probe_outcome']);
            self::assertSame([['path' => '$[].*', 'kind' => 'unknown_field']], $record['discrepancies']);
        } else {
            self::assertSame($failure, $record['failure_kind']);
            self::assertSame('failed', $record['probe_outcome']);
        }
        self::assertArrayHasKey('$', $record['response_paths']);
        self::assertArrayHasKey('$', $record['payload_paths']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (
            ['private-upstream-error', 'private-log-content', 'private-dynamic-key', 'private-stat-value'] as $value
        ) {
            self::assertStringNotContainsString($value, $json);
        }
    }

    /** @return iterable<string, array{int, string, bool, string}> */
    public static function failedResponses(): iterable
    {
        yield 'direct log error' => [17, 'null', true, 'api_failure'];
        yield 'bridge log error' => [18, 'null', true, 'api_failure'];
        yield 'unknown log field' => [17,
            '[{"date":123,"entry":"private-log-content","private-dynamic-key":"private-stat-value"}]',
            false, 'unknown_field'];
        yield 'malformed bandwidth leaf' => [5, '{"private-domain":true}', false, 'payload_mismatch'];
    }

    public function testEntrypointHelpAndMissingOptInStayOffline(): void
    {
        $output = sys_get_temp_dir() . '/resource-review-' . bin2hex(random_bytes(8)) . '.json';
        foreach ([['--help'], ['--output=' . $output]] as $arguments) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 3) . '/tools/live-verify/resource-statistics-review.php', ...$arguments],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['WHM_API_LIVE' => '', 'WHM_API_LIVE_ACCOUNT' => '', 'WHM_API_LIVE_DOMAIN' => '',
                    'OBLAK_TEST_WHM_KEY' => '', 'OBLAK_TEST_NPR_KEY' => ''],
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($arguments === ['--help']) {
                self::assertSame(0, $exit);
                self::assertIsString($stdout);
                self::assertStringContainsString('WHM_API_LIVE_ACCOUNT=npr', $stdout);
                self::assertStringContainsString('WHM_API_LIVE_DOMAIN=npr.rs', $stdout);
                self::assertSame('', $stderr);
            } else {
                self::assertSame(1, $exit);
                self::assertSame('', $stdout);
                self::assertIsString($stderr);
                self::assertStringContainsString('configuration_failure', $stderr);
            }
            self::assertFileDoesNotExist($output);
        }
    }

    /** @return list<Probe> */
    private static function probes(): array
    {
        self::assertTrue(class_exists(ResourceStatisticsReviewProbes::class), 'The varied review catalog is missing.');

        return ResourceStatisticsReviewProbes::load();
    }

    /** @return list<array{string, string}> */
    private static function expectedRequests(): array
    {
        // Literal wire expectations come from the reviewed request recipe, independently of the catalog.
        $expected = [['whm1:version', 'https://test.oblak.host:2087/json-api/version?api.version=1']];
        $queries = [
            ['Bandwidth', 'get_enabled_protocols', ''],
            ['Bandwidth', 'get_retention_periods', ''],
            ['Bandwidth', 'query', 'grouping=domain&domains=npr.rs&end=1793404800'
                . '&interval=daily&protocols=http&start=1790812800&timezone=UTC'],
            ['Bandwidth', 'query', 'grouping=domain%7Cprotocol&domains=npr.rs&end=1793404800'
                . '&interval=daily&protocols=http&start=1790812800&timezone=UTC'],
            ['Bandwidth', 'query', 'grouping=domain%7Cprotocol%7Cyear&domains=npr.rs&end=1793404800'
                . '&interval=daily&protocols=http&start=1790812800&timezone=UTC'],
            ['ResourceUsage', 'get_usages', ''],
            ['StatsBar', 'get_stats', 'display=bandwidthusage%7Capacheversion%7Ccpanelversion%7Chostingpackage'
                . '%7Chostname%7Ckernelversion%7Cmachinetype%7Cmysqlversion%7Coperatingsystem%7Cperlversion'
                . '%7Csharedip%7Cshorthostname%7Ctheme%7Cparkeddomains&warnings=0&warnout=0'],
            ['Stats', 'get_bandwidth', 'timezone=UTC'],
            ['Stats', 'get_site_errors', 'domain=npr.rs&log=suexec&maxlines=1'],
            ['Stats', 'get_stats_daily', 'domain=npr.rs'],
            ['Stats', 'list_sites', 'engine=analog&traffic=http'],
            ['Stats', 'list_stats_by_domain', 'domain=npr.rs&engine=analog&ssl=0'],
            ['StatsManager', 'get_configuration', ''],
        ];
        foreach ($queries as [$module, $function, $query]) {
            $expected[] = ['uapi:' . $module . '/' . $function,
                'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                    . ($query === '' ? '' : '?' . $query)];
            $expected[] = ['uapi:' . $module . '/' . $function,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=' . $module . '&cpanel.function=' . $function
                    . ($query === '' ? '' : '&' . $query)];
        }

        return $expected;
    }

    private static function responses(?int $override = null, string $payload = '', bool $apiFailure = false): Client
    {
        $http = new Client();
        $data = [
            '{"version":"11.138.0.19"}',
            '["http"]', '["http"]', '[]', '[]',
            '{"private-domain":17}', '{"private-domain":17}',
            '{"private-domain":{"private-protocol":12.5}}', '{"private-domain":{"private-protocol":12.5}}',
            '{"private-domain":{"private-protocol":{"private-date":31}}}',
            '{"private-domain":{"private-protocol":{"private-date":31}}}',
            '[]', '[]', self::statistics(), self::statistics(), '[]', '[]',
            '[{"date":123,"entry":"private-log-content"}]', '[{"date":123,"entry":"private-log-content"}]',
            '{}', '{}',
            '[{"domain":"private-domain","path":"/private/report.html","ssl":0,"all_domains":0}]',
            '[{"domain":"private-domain","path":"/private/report.html","ssl":0,"all_domains":0}]',
            '[]', '[]', '[]', '[]',
        ];
        if ($override !== null) {
            $data[$override] = $payload;
        }
        foreach ($data as $index => $value) {
            if ($index === 0) {
                $body = '{"metadata":{"result":1},"data":' . $value . '}';
            } else {
                $result = ($apiFailure && $index === $override
                    ? '{"status":0,"errors":["private-upstream-error"],"data":'
                    : '{"status":1,"errors":null,"data":') . $value . '}';
                $body = $index % 2 === 0 ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : $result;
            }
            $http->addResponse(new Response(200, [], $body));
        }

        return $http;
    }

    private static function statistics(): string
    {
        return '[{"id":"bandwidthusage","_count":"12","count":"private-stat-value","percent":"12",'
            . '"role":"WebServer"},{"id":"apacheversion","count":null,"role":"WebServer"},'
            . '{"id":"parkeddomains","_count":"0","role":["WebServer","DNS"]}]';
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => str_repeat('a', 40), 'source_hashes' => ['whm1' => str_repeat('b', 64),
            'uapi' => str_repeat('c', 64)], 'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40), 'overlay_sha256' => str_repeat('f', 64)];
    }
}
