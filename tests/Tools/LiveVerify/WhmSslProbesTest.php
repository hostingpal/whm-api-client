<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmSslProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmSslProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyNineExactWhmGetRequests(): void
    {
        $probes = WhmSslProbes::load();
        self::assertCount(9, $probes);
        $expected = [
            ['whm1:version', 'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:get_autossl_check_schedule',
                'https://test.oblak.host:2087/json-api/get_autossl_check_schedule?api.version=1'],
            ['whm1:get_autossl_logs_catalog',
                'https://test.oblak.host:2087/json-api/get_autossl_logs_catalog?api.version=1'],
            ['whm1:get_autossl_metadata',
                'https://test.oblak.host:2087/json-api/get_autossl_metadata?api.version=1'],
            ['whm1:get_autossl_problems_for_domain',
                'https://test.oblak.host:2087/json-api/get_autossl_problems_for_domain?api.version=1&domain=npr.rs'],
            ['whm1:get_autossl_problems_for_user',
                'https://test.oblak.host:2087/json-api/get_autossl_problems_for_user?api.version=1&username=npr'],
            ['whm1:get_autossl_user_excluded_domains',
                'https://test.oblak.host:2087/json-api/get_autossl_user_excluded_domains?api.version=1&username=npr'],
            ['whm1:get_best_ssldomain_for_service',
                'https://test.oblak.host:2087/json-api/get_best_ssldomain_for_service?api.version=1&service=whostmgr'],
            ['whm1:listcrts', 'https://test.oblak.host:2087/json-api/listcrts?api.version=1&user=npr'],
        ];
        foreach ($probes as $index => $probe) {
            [$operation, $uri] = $expected[$index];
            self::assertSame($operation, $probe->operation);
            self::assertSame('whm', $probe->route);
            self::assertSame($uri, $probe->uri);
            self::assertNull($probe->unavailable);
            self::assertSame('GET', $probe->spec['method']);
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $data = $index === 0 ? '{"version":"11.138.0.19"}' : '{}';
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":' . $data . '}'));
            $client = new Client([
                'host' => 'test.oblak.host', 'authType' => 'whm',
                'username' => 'root', 'token' => 'synthetic-token',
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
