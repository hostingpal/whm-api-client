<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmLoginMailProtectionProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmLoginMailProtectionProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyEightExactWhmGetRequests(): void
    {
        $probes = WhmLoginMailProtectionProbes::load();
        self::assertCount(8, $probes);
        $expected = [
            ['whm1:version',
                'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:cphulk_status',
                'https://test.oblak.host:2087/json-api/cphulk_status?api.version=1'],
            ['whm1:get_countries_with_known_ip_ranges',
                'https://test.oblak.host:2087/json-api/get_countries_with_known_ip_ranges?api.version=1'],
            ['whm1:cpgreylist_status',
                'https://test.oblak.host:2087/json-api/cpgreylist_status?api.version=1'],
            ['whm1:cpgreylist_is_server_netblock_trusted',
                'https://test.oblak.host:2087/json-api/cpgreylist_is_server_netblock_trusted?api.version=1'],
            ['whm1:cpgreylist_load_common_mail_providers_config',
                'https://test.oblak.host:2087/json-api/cpgreylist_load_common_mail_providers_config?api.version=1'],
            ['whm1:load_cpgreylist_config',
                'https://test.oblak.host:2087/json-api/load_cpgreylist_config?api.version=1'],
            ['whm1:read_cpgreylist_trusted_hosts',
                'https://test.oblak.host:2087/json-api/read_cpgreylist_trusted_hosts?api.version=1'],
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
