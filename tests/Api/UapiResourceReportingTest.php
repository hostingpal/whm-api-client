<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UapiResourceReportingTest extends TestCase
{
    public function testReportingSelectorsBindAccountsWithoutIo(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client([
            'host' => 'api.example.test', 'authType' => 'whm',
            'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory));
        $account = $client->uapi('exampleuser');

        self::assertSame($account->bandwidth(), $account->bandwidth());
        self::assertSame($account->resourceUsage(), $account->resourceUsage());
        self::assertSame($account->statsBar(), $account->statsBar());
        self::assertNotSame($account->bandwidth(), $client->uapi('other')->bandwidth());
        self::assertNotSame($account->resourceUsage(), $client->uapi('other')->resourceUsage());
        self::assertNotSame($account->statsBar(), $client->uapi('other')->statsBar());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('successfulPayloads')]
    public function testReportingReadsPreservePinnedValuesAndUseOneGet(
        string $route,
        string $operation,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame($expected, self::invoke($account, $operation));
        self::assertRequest($http, $route, $operation);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic reporting notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>}> */
    public static function successfulPayloads(): iterable
    {
        // Literal oracles were reviewed against the pinned source, independently of generated specs.
        $cases = [
            'enabled protocols' => ['Bandwidth/get_enabled_protocols', '["http","imap","smtp","pop3","ftp"]',
                ['http', 'imap', 'smtp', 'pop3', 'ftp']],
            'empty protocols' => ['Bandwidth/get_enabled_protocols', '[]', []],
            'retention fields' => ['Bandwidth/get_retention_periods',
                '[{"retention":2678400,"interval":"5min","future":true},{"interval":"daily","retention":0}]',
                [['interval' => '5min', 'retention' => 2678400], ['interval' => 'daily', 'retention' => 0]]],
            'optional retention fields' => ['Bandwidth/get_retention_periods', '[{}, {"retention":0}]',
                [[], ['retention' => 0]]],
            'empty retention' => ['Bandwidth/get_retention_periods', '[]', []],
            'dynamic grouping keys and opaque deepest values' => ['Bandwidth/query',
                '{"example.test":{"http":{"2026":{"bytes":0,"custom":[null,"1.25",false]}}},'
                . '"UNKNOWN":{"smtp":null,"ftp":{"2025":null}}}',
                ['example.test' => ['http' => [2026 => ['bytes' => 0, 'custom' => [null, '1.25', false]]]],
                    'UNKNOWN' => ['smtp' => null, 'ftp' => [2025 => null]]]],
            'empty report' => ['Bandwidth/query', '{}', []],
            'empty first grouping' => ['Bandwidth/query', '{"example.test":{}}', ['example.test' => []]],
            'resource fields and unlimited maximum' => ['ResourceUsage/get_usages',
                '[{"description":"Disk usage","error":null,"formatter":"format_bytes","id":"disk_usage",'
                . '"maximum":null,"url":"disk/index.html","usage":0,"future":"ignored"},'
                . '{"formatter":"vendor_formatter","maximum":0,"usage":1}]',
                [
                    ['description' => 'Disk usage', 'error' => null, 'formatter' => 'format_bytes',
                        'id' => 'disk_usage',
                    'maximum' => null, 'url' => 'disk/index.html', 'usage' => 0],
                    ['formatter' => 'vendor_formatter', 'maximum' => 0, 'usage' => 1]]],
            'nullable resource formatter' => ['ResourceUsage/get_usages',
                '[{"error":"Synthetic resource error","formatter":null,"maximum":15}]',
                [['error' => 'Synthetic resource error', 'formatter' => null, 'maximum' => 15]]],
            'optional resource fields' => ['ResourceUsage/get_usages', '[{}]', [[]]],
            'empty resources' => ['ResourceUsage/get_usages', '[]', []],
            'stats fields retain units and decimal strings' => ['StatsBar/get_stats',
                '[{"_count":"0.00","_max":"1024.00","_maxed":0,"condition":1,"count":"0 bytes",'
                . '"feature":"bandwidth","id":"bandwidthusage","is_maxed":0,"item":"Monthly Bandwidth",'
                . '"max":"1 GB","maxed_phrase":"Maximum","module":"Stats","name":"bandwidthusage",'
                . '"near_limit_phrase":"Near limit","normalized":1,"percent":0,"percent10":0,"percent20":0,'
                . '"percent5":0,"phrase":"Monthly Bandwidth","role":"WebServer","rowtype":"even",'
                . '"units":"MB","zeroisunlimited":1,"langkey":"unmodeled"}]',
                [['_count' => '0.00', '_max' => '1024.00', '_maxed' => 0, 'condition' => 1, 'count' => '0 bytes',
                    'feature' => 'bandwidth', 'id' => 'bandwidthusage', 'is_maxed' => 0, 'item' => 'Monthly Bandwidth',
                    'max' => '1 GB', 'maxed_phrase' => 'Maximum', 'module' => 'Stats', 'name' => 'bandwidthusage',
                    'near_limit_phrase' => 'Near limit', 'normalized' => 1, 'percent' => 0, 'percent10' => 0,
                    'percent20' => 0, 'percent5' => 0, 'phrase' => 'Monthly Bandwidth', 'role' => 'WebServer',
                    'rowtype' => 'even', 'units' => 'MB', 'zeroisunlimited' => 1]]],
            'complementary flags and optional statistics' => ['StatsBar/get_stats',
                '[{"_maxed":1,"condition":0,"is_maxed":1,"normalized":0,"zeroisunlimited":0},{}]',
                [['_maxed' => 1, 'condition' => 0, 'is_maxed' => 1, 'normalized' => 0, 'zeroisunlimited' => 0], []]],
            'empty statistics' => ['StatsBar/get_stats', '[]', []],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$operation, $payload, $expected]) {
                yield $route . ' ' . $name => [$route, $operation, $payload, $expected];
            }
        }
    }

    #[DataProvider('routes')]
    public function testPipeStringsAndExplicitFalseZeroAreEncodedOnce(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->bandwidth()->query(
            grouping: 'domain|protocol|year',
            domains: 'example.test|UNKNOWN',
            end: 1446664809,
            interval: '5min',
            protocols: 'http|smtp',
            start: 0,
            timezone: 'America/Chicago',
        );
        self::assertRequest(
            $http,
            $route,
            'Bandwidth/query',
            'grouping=domain%7Cprotocol%7Cyear&domains=example.test%7CUNKNOWN&end=1446664809'
            . '&interval=5min&protocols=http%7Csmtp&start=0&timezone=America%2FChicago',
        );

        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->statsBar()->getStats(
            display: 'bandwidthusage|diskusage',
            infinityImg: '/home/example/infinity.png',
            infinityLang: 'No limit',
            rowCounter: 'even',
            warningLevel: 87,
            warnings: false,
            warnOut: true,
        );
        self::assertRequest(
            $http,
            $route,
            'StatsBar/get_stats',
            'display=bandwidthusage%7Cdiskusage&infinityimg=%2Fhome%2Fexample%2Finfinity.png'
            . '&infinitylang=No%20limit&rowcounter=even&warninglevel=87&warnings=0&warnout=1',
        );
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->statsBar()->getStats(
            display: 'diskusage',
            infinityImg: null,
            infinityLang: null,
            rowCounter: null,
            warningLevel: null,
            warnings: null,
            warnOut: null,
        );
        self::assertRequest($http, $route, 'StatsBar/get_stats', 'display=diskusage');
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidLeavesRetainTheNewConfirmedOutcome(
        string $route,
        string $operation,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->resourceUsage()->getUsages();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $operation);
            self::fail('Missing or incompatible reporting payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        foreach (self::operationNames() as $operation) {
            foreach ([null, 'null', 'true', '0', '"0"'] as $payload) {
                $cases[] = [$operation, $payload];
            }
        }
        foreach (['[0]', '[null]', '[true]', '[{}]'] as $payload) {
            $cases[] = ['Bandwidth/get_enabled_protocols', $payload];
        }
        foreach (['Bandwidth/get_retention_periods', 'ResourceUsage/get_usages', 'StatsBar/get_stats'] as $operation) {
            foreach (['{"named":{}}', '[null]', '[1]', '["x"]'] as $payload) {
                $cases[] = [$operation, $payload];
            }
        }
        foreach (['{"x":null}', '{"x":1}', '{"x":{"y":1}}', '{"x":{"y":{"z":1}}}'] as $payload) {
            $cases[] = ['Bandwidth/query', $payload];
        }
        $fields = [
            'Bandwidth/get_retention_periods' => [
                'interval' => ['null', '0', 'true'], 'retention' => ['null', '"0"', '1.5', 'true'],
            ],
            'ResourceUsage/get_usages' => [
                'description' => ['null', '0'], 'error' => ['0', 'false'], 'formatter' => ['0', 'false'],
                'id' => ['null', '0'], 'maximum' => ['"0"', '1.5', 'true'],
                'url' => ['null', '0'], 'usage' => ['null', '"0"', '1.5', 'false'],
            ],
            'StatsBar/get_stats' => [],
        ];
        foreach (
            ['_count', '_max', 'count', 'feature', 'id', 'item', 'max', 'maxed_phrase', 'module', 'name',
            'near_limit_phrase', 'phrase', 'role', 'rowtype', 'units'] as $field
        ) {
            $fields['StatsBar/get_stats'][$field] = ['null', '0', 'false'];
        }
        foreach (['_maxed', 'condition', 'is_maxed', 'normalized', 'zeroisunlimited'] as $field) {
            $fields['StatsBar/get_stats'][$field] = ['null', '"0"', 'true', '2', '1.5'];
        }
        foreach (['percent', 'percent5', 'percent10', 'percent20'] as $field) {
            $fields['StatsBar/get_stats'][$field] = ['null', '"0"', 'true', '1.5'];
        }
        foreach ($fields as $operation => $leaves) {
            foreach ($leaves as $field => $values) {
                foreach ($values as $value) {
                    $cases[] = [$operation, '[{"' . $field . '":' . $value . '}]'];
                }
            }
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $index => [$operation, $payload]) {
                yield $route . ' ' . $index . ' ' . $operation => [$route, $operation, $payload];
            }
        }
    }

    #[DataProvider('operations')]
    public function testApiAndHttpFailureReplaceMetadataWithoutRetry(string $route, string $operation): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->resourceUsage()->getUsages();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $operation);
            self::fail('API failure must precede payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic reporting failure'], $error->errors());
        }
        $http->addResponse(new Response(503, [], '{}'));
        try {
            self::invoke($account, $operation);
            self::fail('HTTP failure must clear stale metadata.');
        } catch (HttpException $error) {
            self::assertNull($account->responseMeta());
            self::assertNull($error->metadata);
        }
        self::assertCount(3, $http->getRequests());
    }

    public function testBridgeOuterFailureIsNotWaived(): void
    {
        [$client, $http] = self::client('bridge');
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":0,"reason":"Synthetic outer failure"},"data":{"uapi":{"status":1,"data":[]}}}',
        ));
        $this->expectException(ApiErrorException::class);
        $client->uapi('exampleuser')->resourceUsage()->getUsages();
    }

    public function testReportingBridgeAccountsKeepTheirOwnRequestsAndMetadata(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $other = $client->uapi('other');
        foreach (self::operationNames() as $operation) {
            $payload = $operation === 'Bandwidth/query' ? '{}' : '[]';
            $http->addResponse(new Response(200, [], self::envelope('bridge', $payload)));
            self::invoke($first, $operation);
            $firstMetadata = $first->responseMeta();
            $http->addResponse(new Response(200, [], self::envelope('bridge', $payload)));
            self::invoke($other, $operation);
            self::assertRequest($http, 'bridge', $operation, null, 'other');
            self::assertSame($firstMetadata, $first->responseMeta());
            self::assertNotSame($firstMetadata, $other->responseMeta());
        }
        self::assertCount(10, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operationNames() as $operation) {
                yield $route . ' ' . $operation => [$route, $operation];
            }
        }
    }

    /** @return list<string> */
    private static function operationNames(): array
    {
        return ['Bandwidth/get_enabled_protocols', 'Bandwidth/get_retention_periods', 'Bandwidth/query',
            'ResourceUsage/get_usages', 'StatsBar/get_stats'];
    }

    /** @return array<array-key, mixed> */
    private static function invoke(UapiClient $account, string $operation): array
    {
        return match ($operation) {
            'Bandwidth/get_enabled_protocols' => $account->bandwidth()->getEnabledProtocols(),
            'Bandwidth/get_retention_periods' => $account->bandwidth()->getRetentionPeriods(),
            'Bandwidth/query' => $account->bandwidth()->query(grouping: 'domain|protocol|year'),
            'ResourceUsage/get_usages' => $account->resourceUsage()->getUsages(),
            'StatsBar/get_stats' => $account->statsBar()->getStats(display: 'bandwidthusage|diskusage'),
            default => throw new LogicException('Unknown reporting operation.'),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $operation,
        ?string $query = null,
        string $account = 'exampleuser',
    ): void {
        [$module, $function] = explode('/', $operation);
        $query ??= match ($operation) {
            'Bandwidth/query' => 'grouping=domain%7Cprotocol%7Cyear',
            'StatsBar/get_stats' => 'display=bandwidthusage%7Cdiskusage',
            default => '',
        };
        $base = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $operation;
        $uri = $base . ($query === '' ? '' : ($route === 'bridge' ? '&' : '?') . $query);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic reporting failure"]')
            . ',"warnings":["Synthetic reporting notice"]}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
