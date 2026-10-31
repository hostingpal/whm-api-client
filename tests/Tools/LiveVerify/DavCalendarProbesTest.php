<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\DavCalendarProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class DavCalendarProbesTest extends TestCase
{
    public function testDiagnosticApiFailuresRemainUncheckedAndFailStrictAssessment(): void
    {
        $http = new MockClient();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        foreach (range(0, 5) as $index) {
            // Catalog order: shared direct/bridge, service direct/bridge, Horde direct/bridge.
            $service = $index === 2 || $index === 3;
            $result = $service ? '{"status":1,"data":{"enabled":1},"errors":null}'
                : '{"status":0,"data":null,"errors":null}';
            $http->addResponse(new Response(200, [], $index % 2 === 0 ? $result
                : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'));
        }
        $context = ['revision' => str_repeat('a', 40), 'source_hashes' => ['whm1' => str_repeat('b', 64),
            'uapi' => str_repeat('c', 64)], 'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40), 'overlay_sha256' => str_repeat('f', 64)];
        $report = (new ReadOnlySectionRunner($http, $context))->run(
            DavCalendarProbes::load(),
            'dav-calendar-v1',
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );
        self::assertSame('failed', $report['status']);
        self::assertCount(7, $http->getRequests());
        foreach ([1, 2, 5, 6] as $index) {
            self::assertSame('failure', $report['probes'][$index]['api_outcome']);
            self::assertSame('not_checked', $report['probes'][$index]['payload_validation']);
            self::assertSame('failed', $report['probes'][$index]['probe_outcome']);
            self::assertSame('api_failure', $report['probes'][$index]['failure_kind']);
            self::assertSame([], $report['probes'][$index]['discrepancies']);
        }
        foreach ([0, 3, 4] as $index) {
            self::assertSame('passed', $report['probes'][$index]['probe_outcome']);
        }
    }

    /** Pinned-success synthetic fixtures verify routing, not current server availability. */
    public function testCatalogExecutesOnlySevenExactAccountBoundGetRequests(): void
    {
        $probes = DavCalendarProbes::load();
        self::assertCount(7, $probes);
        $cases = [
            ['DAV', 'has_shared_global_addressbook', '{"shared":0}'],
            ['DAV', 'is_dav_service_enabled', '{"enabled":1}'],
            ['DAV', 'is_horde_enabled', '{"enabled":0}'],
        ];
        $expected = [['whm1:version', 'whm', 'https://test.oblak.host:2087/json-api/version?api.version=1',
            '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}']];
        foreach ($cases as [$module, $function, $payload]) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $uri = $route === 'direct-uapi'
                    ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
                $response = '{"status":1,"errors":null,"data":' . $payload . '}';
                $expected[] = ['uapi:' . $module . '/' . $function, $route, $uri,
                    $route === 'direct-uapi'
                        ? $response : '{"metadata":{"result":1},"data":{"uapi":' . $response . '}}'];
            }
        }
        self::assertCount(7, $expected);
        foreach ($probes as $index => $probe) {
            [$operation, $route, $uri, $response] = $expected[$index];
            self::assertSame($operation, $probe->operation);
            self::assertSame($route, $probe->route);
            self::assertSame($uri, $probe->uri);
            self::assertNull($probe->unavailable);
            self::assertSame('GET', $probe->spec['method']);
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $http->addResponse(new Response(200, [], $response));
            $client = new Client([
                'host' => 'test.oblak.host',
                'authType' => $route === 'direct-uapi' ? 'cpanel' : 'whm',
                'username' => $route === 'direct-uapi' ? 'npr' : 'root',
                'token' => 'synthetic-token',
            ], new HttpTransport(new RequestGuard($http, $uri), $factory, $factory));
            ($probe->invoke)($client);
            self::assertCount(1, $http->getRequests());
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($uri, (string) $request->getUri());
            self::assertSame('', (string) $request->getBody());
            foreach (['POST', 'DELETE'] as $method) {
                try {
                    (new RequestGuard($http, $uri))->sendRequest(new Request($method, $uri));
                    self::fail('Catalog requests must stay read-only.');
                } catch (LogicException) {
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }
}
