<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmSystemPackagesProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmSystemPackagesProbesTest extends TestCase
{
    public function testCatalogExecutesOnlySevenExactWhmGetRequests(): void
    {
        $probes = WhmSystemPackagesProbes::load();
        self::assertCount(7, $probes);
        $expected = [
            ['whm1:version', 'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:list_rpms', 'https://test.oblak.host:2087/json-api/list_rpms?api.version=1'],
            ['whm1:package_manager_list_packages',
                'https://test.oblak.host:2087/json-api/package_manager_list_packages?api.version=1&state=installed'],
            ['whm1:package_manager_get_package_info',
                'https://test.oblak.host:2087/json-api/package_manager_get_package_info?api.version=1'
                . '&package=ea-apache24'],
            ['whm1:get_rpm_version_data',
                'https://test.oblak.host:2087/json-api/get_rpm_version_data?api.version=1&section=target_settings'],
            ['whm1:get_rpm_version_data',
                'https://test.oblak.host:2087/json-api/get_rpm_version_data?api.version=1&section=rpm_groups'],
            ['whm1:package_manager_resolve_actions',
                'https://test.oblak.host:2087/json-api/package_manager_resolve_actions?api.version=1'
                . '&package=ea-apache24&ns=ea'],
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
            $data = ['{"version":"11.138.0.19"}', '{"rpms":[]}', '{"packages":[]}',
                '{"packages":[]}', '{"target":"installed"}', '{"group":["rpm"]}',
                '{"install":[],"unaffected":[],"uninstall":[],"upgrade":[]}'][$index];
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
