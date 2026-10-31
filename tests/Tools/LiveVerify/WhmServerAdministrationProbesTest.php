<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmServerAdministrationProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmServerAdministrationProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyEightExactWhmGetRequests(): void
    {
        $probes = WhmServerAdministrationProbes::load();
        self::assertCount(8, $probes);
        $expected = [
            ['whm1:version',
                'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:get_all_contact_importances',
                'https://test.oblak.host:2087/json-api/get_all_contact_importances?api.version=1'],
            ['whm1:get_available_profiles',
                'https://test.oblak.host:2087/json-api/get_available_profiles?api.version=1'],
            ['whm1:get_current_profile',
                'https://test.oblak.host:2087/json-api/get_current_profile?api.version=1'],
            ['whm1:getminimumpasswordstrengths',
                'https://test.oblak.host:2087/json-api/getminimumpasswordstrengths?api.version=1'],
            ['whm1:installed_versions',
                'https://test.oblak.host:2087/json-api/installed_versions?api.version=1'],
            ['whm1:servicestatus',
                'https://test.oblak.host:2087/json-api/servicestatus?api.version=1'],
            ['whm1:wp_dashboard_get_hostname',
                'https://test.oblak.host:2087/json-api/wp_dashboard_get_hostname?api.version=1'],
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
