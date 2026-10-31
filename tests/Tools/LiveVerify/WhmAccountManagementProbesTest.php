<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmAccountManagementProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmAccountManagementProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyNineExactWhmGetRequests(): void
    {
        $probes = WhmAccountManagementProbes::load();
        self::assertCount(9, $probes);
        $expected = [
            ['whm1:version', 'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:get_current_users_count',
                'https://test.oblak.host:2087/json-api/get_current_users_count?api.version=1'],
            ['whm1:get_disk_usage', 'https://test.oblak.host:2087/json-api/get_disk_usage?api.version=1'],
            ['whm1:get_homedir_roots', 'https://test.oblak.host:2087/json-api/get_homedir_roots?api.version=1'],
            ['whm1:get_maximum_users', 'https://test.oblak.host:2087/json-api/get_maximum_users?api.version=1'],
            ['whm1:list_account_enhancements',
                'https://test.oblak.host:2087/json-api/list_account_enhancements?api.version=1'],
            ['whm1:list_users', 'https://test.oblak.host:2087/json-api/list_users?api.version=1'],
            ['whm1:myprivs', 'https://test.oblak.host:2087/json-api/myprivs?api.version=1'],
            ['whm1:quota_enabled', 'https://test.oblak.host:2087/json-api/quota_enabled?api.version=1'],
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
