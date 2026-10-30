<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Independent literal contracts from the pinned Stats and StatsManager sources. */
final class UapiWebStatisticsTest extends TestCase
{
    /** @param array<array-key, mixed> $expected */
    #[DataProvider('successfulPayloads')]
    public function testExactReadRequestsAndPayloads(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        self::assertRequest($http, $route, $function);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>}> */
    public static function successfulPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $function) {
                foreach (self::validCases($function) as $index => [$payload, $expected]) {
                    yield "$route $function $index" => [$route, $function, $payload, $expected];
                }
            }
        }
    }

    /** @return list<array{string, array<array-key, mixed>}> */
    private static function validCases(string $function): array
    {
        return match ($function) {
            'get_bandwidth' => [
                ['[{"bytes":74845,"domain":"example.com","month_start":1561957200,"protocol":"http"},'
                    . '{"bytes":0,"month_start":0,"protocol":"imap"}]',
                    [
            ['bytes' => 74845, 'domain' => 'example.com', 'month_start' => 1561957200, 'protocol' => 'http'],
                        ['bytes' => 0, 'month_start' => 0, 'protocol' => 'imap']]],
                ['[]', []], ['[{}, {"domain":"","protocol":"ftp","future":1}]',
                    [[], ['domain' => '', 'protocol' => 'ftp']]],
            ],
            'get_site_errors' => [
                ['[{"date":1234576704,"entry":"[core:error] synthetic fixture"}]',
                    [['date' => 1234576704, 'entry' => '[core:error] synthetic fixture']]],
                ['[]', []], ['[{}, {"date":0}, {"entry":"","future":true}]',
                    [[], ['date' => 0], ['entry' => '']]],
            ],
            'get_stats_daily' => [
                ['{"domain":"example.com","stats":{"2024-02-01":{"bandwidth":81110,"hits":6,"pages":6,'
                    . '"visits":5},"Fri Feb 02 2024 00:00:00 GMT+0000 (Coordinated Universal Time)":'
                    . '{"bandwidth":0,"hits":0,"pages":0,"visits":0}}}',
                    ['domain' => 'example.com', 'stats' => [
                        '2024-02-01' => ['bandwidth' => 81110, 'hits' => 6, 'pages' => 6, 'visits' => 5],
                        'Fri Feb 02 2024 00:00:00 GMT+0000 (Coordinated Universal Time)' =>
                            ['bandwidth' => 0, 'hits' => 0, 'pages' => 0, 'visits' => 0],
                    ]]],
                ['{}', []], ['{"stats":{}}', ['stats' => []]],
                ['{"domain":"","stats":{"2024-02-03":{},"2024-02-04":{"visits":0,"future":1}},"extra":1}',
                    ['domain' => '', 'stats' => ['2024-02-03' => [], '2024-02-04' => ['visits' => 0]]]],
            ],
            'list_sites' => [
                ['[{"all_domains":1,"domain":"example.com","path":"/tmp/user/webalizer/index.html","ssl":0}]',
                    [['all_domains' => 1, 'domain' => 'example.com', 'path' => '/tmp/user/webalizer/index.html',
                        'ssl' => 0]]],
                ['[]', []], ['[{}, {"all_domains":0,"domain":"","path":"","ssl":1,"future":0}]',
                    [[], ['all_domains' => 0, 'domain' => '', 'path' => '', 'ssl' => 1]]],
            ],
            'list_stats_by_domain' => [
                ['[{"date":1565795929,"url":"tmp/cptest/analog/8.html.com"}]',
                    [['date' => 1565795929, 'url' => 'tmp/cptest/analog/8.html.com']]],
                ['[]', []], ['[{}, {"date":0}, {"url":"","future":true}]',
                    [[], ['date' => 0], ['url' => '']]],
            ],
            default => [
                ['[{"analyzers":[{"enabled":1,"enabled_by_user":0,"name":"awstats"},'
                    . '{"enabled":0,"enabled_by_user":1,"name":"analog"}],"domain":"example.com"}]',
                    [['analyzers' => [
            ['enabled' => 1, 'enabled_by_user' => 0, 'name' => 'awstats'],
                        ['enabled' => 0, 'enabled_by_user' => 1, 'name' => 'analog']], 'domain' => 'example.com']]],
                ['[]', []], ['[{}, {"analyzers":[],"domain":""}, {"analyzers":[{}, {"name":"webalizer"}]}]',
                    [[], ['analyzers' => [], 'domain' => ''], ['analyzers' => [[], ['name' => 'webalizer']]]]],
            ],
        };
    }

    /** @param list<array{month_start?: int}> $expected */
    #[DataProvider('canonicalMonthStarts')]
    public function testMonthStartCanonicalIntegerStringsNormalizeLosslessly(
        string $route,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $account->stats()->getBandwidth(timezone: 'America/Chicago'));
        self::assertRequest($http, $route, 'get_bandwidth');
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<array{month_start?: int}>}> */
    public static function canonicalMonthStarts(): iterable
    {
        $cases = [
            'quoted timestamp' => ['[{"month_start":"1561957200"}]', [['month_start' => 1561957200]]],
            'mixed native and quoted zero' => ['[{"month_start":1561957200},{"month_start":"0"}]',
                [['month_start' => 1561957200], ['month_start' => 0]]],
            'optional omission' => ['[{}, {"month_start":"1561957200"}]', [[], ['month_start' => 1561957200]]],
            'negative integer' => ['[{"month_start":"-1"}]', [['month_start' => -1]]],
            'integer maximum' => ['[{"month_start":"' . PHP_INT_MAX . '"}]', [['month_start' => PHP_INT_MAX]]],
            'integer minimum' => ['[{"month_start":"' . PHP_INT_MIN . '"}]', [['month_start' => PHP_INT_MIN]]],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$payload, $expected]) {
                yield "$route $name" => [$route, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedOrAbsentDataRetainsConfirmedMetadata(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validCases($function)[0][0])));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Invalid statistics data must fail validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
        }
        self::assertCount(2, $http->getRequests());
        self::assertRequest($http, $route, $function);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $function) {
                $cases = [null, 'null', 'true', '1', '1.5', '"text"'];
                if ($function === 'get_stats_daily') {
                    array_push(
                        $cases,
                        '[{}]',
                        '{"domain":null}',
                        '{"domain":1}',
                        '{"domain":false}',
                        '{"domain":[]}',
                        '{"stats":null}',
                        '{"stats":true}',
                        '{"stats":"text"}',
                        '{"stats":{"2024-02-01":null}}',
                        '{"stats":{"2024-02-01":1}}',
                        '{"stats":{"2024-02-01":false}}',
                        '{"stats":{"2024-02-01":[{}]}}',
                    );
                    foreach (['bandwidth', 'hits', 'pages', 'visits'] as $field) {
                        foreach (['null', '"0"', 'true', '1.0', '[]', '{}'] as $bad) {
                            $cases[] = '{"stats":{"2024-02-01":{"' . $field . '":' . $bad . '}}}';
                        }
                    }
                } else {
                    array_push($cases, '{"row":{}}', '[null]', '[false]', '[1]', '["text"]');
                    $strings = match ($function) {
                        'get_bandwidth' => ['domain', 'protocol'],
                        'get_site_errors' => ['entry'],
                        'list_sites' => ['domain', 'path'],
                        'list_stats_by_domain' => ['url'],
                        default => ['domain'],
                    };
                    $integers = match ($function) {
                        'get_bandwidth' => ['bytes', 'month_start'],
                        'get_site_errors', 'list_stats_by_domain' => ['date'],
                        default => [],
                    };
                    foreach ($strings as $field) {
                        foreach (['null', '0', 'false', '[]', '{}'] as $bad) {
                            $cases[] = '[{"' . $field . '":' . $bad . '}]';
                        }
                    }
                    foreach ($integers as $field) {
                        $badValues = ['null', 'true', '1.0', '[]', '{}'];
                        if ($function === 'get_bandwidth' && $field === 'month_start') {
                            array_push(
                                $badValues,
                                '"1.0"',
                                '"1.5"',
                                '"01"',
                                '"+1"',
                                '"-0"',
                                '"-01"',
                                '"1e3"',
                                '" 1"',
                                '"1 "',
                                '""',
                                '"' . PHP_INT_MAX . '0"',
                                '"' . PHP_INT_MIN . '0"',
                            );
                        } else {
                            $badValues[] = '"0"';
                        }
                        foreach ($badValues as $bad) {
                            $cases[] = '[{"' . $field . '":' . $bad . '}]';
                        }
                    }
                    if ($function === 'list_sites') {
                        foreach (['all_domains', 'ssl'] as $field) {
                            foreach (['null', '"0"', '"1"', 'true', 'false', '2', '-1', '1.0', '[]'] as $bad) {
                                $cases[] = '[{"' . $field . '":' . $bad . '}]';
                            }
                        }
                    }
                    if ($function === 'get_configuration') {
                        array_push(
                            $cases,
                            '[{"analyzers":null}]',
                            '[{"analyzers":{ "a": {} }}]',
                            '[{"analyzers":[null]}]',
                            '[{"analyzers":[false]}]',
                            '[{"analyzers":[1]}]',
                            '[{"analyzers":[{"name":null}]}]',
                            '[{"analyzers":[{"name":0}]}]',
                        );
                        foreach (['enabled', 'enabled_by_user'] as $field) {
                            foreach (['null', '"0"', '"1"', 'true', 'false', '2', '-1', '1.0', '[]'] as $bad) {
                                $cases[] = '[{"analyzers":[{"' . $field . '":' . $bad . '}]}]';
                            }
                        }
                    }
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $function $index" => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailurePrecedesPayloadValidation(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($client->uapi('npr'), $function);
            self::fail('UAPI status zero must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testOuterWhmFailureIsNotHiddenByAnInnerSuccess(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic bridge failure"},'
            . '"data":{"uapi":{"status":1,"data":[]}}}'));
        try {
            $account->statsManager()->getConfiguration();
            self::fail('An outer WHM failure must remain an API error.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(['Synthetic bridge failure'], $error->errors());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $function) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    #[DataProvider('routes')]
    public function testOptionalArgumentsPreserveOmissionAndFalse(string $route): void
    {
        [$client, $http] = self::client($route);
        $stats = $client->uapi('npr')->stats();
        $calls = [
            [static fn () => $stats->getBandwidth(), 'get_bandwidth', ''],
            [static fn () => $stats->getBandwidth(timezone: ''), 'get_bandwidth', 'timezone='],
            [static fn () => $stats->getSiteErrors(domain: 'example.com'), 'get_site_errors', 'domain=example.com'],
            [static fn () => $stats->getSiteErrors(domain: 'example.com', log: 'error', maxLines: 1),
                'get_site_errors', 'domain=example.com&log=error&maxlines=1'],
            [static fn () => $stats->listSites(engine: 'analog'), 'list_sites', 'engine=analog'],
            [static fn () => $stats->listStatsByDomain(domain: 'example.com', engine: 'analog'),
                'list_stats_by_domain', 'domain=example.com&engine=analog'],
            [static fn () => $stats->listStatsByDomain(domain: 'example.com', engine: 'analog', ssl: true),
                'list_stats_by_domain', 'domain=example.com&engine=analog&ssl=1'],
            [static fn () => $stats->listStatsByDomain(domain: 'example.com', engine: 'analog', ssl: false),
                'list_stats_by_domain', 'domain=example.com&engine=analog&ssl=0'],
        ];
        foreach ($calls as [$call, $function, $parameters]) {
            $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
            self::assertSame([], $call());
            self::assertRequest($http, $route, $function, $parameters);
        }
        self::assertCount(8, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testConfigurationMetadataStaysSeparateFromDomainData(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        self::assertSame([], $account->statsManager()->getConfiguration());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame([
            'analyzers' => [['available_for_user' => 0, 'enabled_by_default' => 1, 'name' => 'analog']],
            'locked' => 1, 'transformed' => 1,
        ], $metadata->uapi['metadata'] ?? null);
        self::assertSame(['Synthetic warning'], $metadata->uapiWarnings());
        self::assertSame(['Synthetic message'], $metadata->uapiMessages());
    }

    public function testSelectorsAndMetadataAreIsolatedByAccountAndSiblingGroup(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($first->stats(), $first->stats());
        self::assertSame($first->statsManager(), $first->statsManager());
        self::assertNotSame($first->stats(), $other->stats());
        self::assertNotSame($first->statsManager(), $other->statsManager());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[]')));
        $first->stats()->getBandwidth();
        $metadata = $first->responseMeta();
        $first->statsManager();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($other->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[]')));
        $other->statsManager()->getConfiguration();
        self::assertSame($metadata, $first->responseMeta());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('api.version=1&cpanel.user=other&cpanel.module=StatsManager'
            . '&cpanel.function=get_configuration', $request->getUri()->getQuery());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return list<string> */
    private static function operations(): array
    {
        return ['get_bandwidth', 'get_site_errors', 'get_stats_daily', 'list_sites',
            'list_stats_by_domain', 'get_configuration'];
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        return match ($function) {
            'get_bandwidth' => $account->stats()->getBandwidth(timezone: 'America/Chicago'),
            'get_site_errors' => $account->stats()->getSiteErrors(
                domain: 'a +&=%/雪',
                log: 'suexec', maxLines: 250,
            ),
            'get_stats_daily' => $account->stats()->getStatsDaily(domain: 'a +&=%/雪'),
            'list_sites' => $account->stats()->listSites(engine: 'webalizer', traffic: 'ftp'),
            'list_stats_by_domain' => $account->stats()->listStatsByDomain(
                domain: 'a +&=%/雪',
                engine: 'analog', ssl: false,
            ),
            default => $account->statsManager()->getConfiguration(),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        ?string $parameters = null,
    ): void {
        $parameters ??= match ($function) {
            'get_bandwidth' => 'timezone=America%2FChicago',
            'get_site_errors' => 'domain=a%20%2B%26%3D%25%2F%E9%9B%AA&log=suexec&maxlines=250',
            'get_stats_daily' => 'domain=a%20%2B%26%3D%25%2F%E9%9B%AA',
            'list_sites' => 'engine=webalizer&traffic=ftp',
            'list_stats_by_domain' => 'domain=a%20%2B%26%3D%25%2F%E9%9B%AA&engine=analog&ssl=0',
            default => '',
        };
        $module = $function === 'get_configuration' ? 'StatsManager' : 'Stats';
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        if ($parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Synthetic warning"],"messages":["Synthetic message"],"metadata":'
            . '{"analyzers":[{"available_for_user":0,"enabled_by_default":1,"name":"analog"}],'
            . '"locked":1,"transformed":1}}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
