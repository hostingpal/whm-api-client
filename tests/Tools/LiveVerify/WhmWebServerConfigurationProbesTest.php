<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmWebServerConfigurationProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmWebServerConfigurationProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyNineExactWhmGetRequests(): void
    {
        $probes = WhmWebServerConfigurationProbes::load();
        self::assertCount(9, $probes);
        $expected = [
            ['whm1:version',
                'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:ea4_get_additional_pkg_prefixes',
                'https://test.oblak.host:2087/json-api/ea4_get_additional_pkg_prefixes?api.version=1'],
            ['whm1:ea4_get_currently_installed_packages',
                'https://test.oblak.host:2087/json-api/ea4_get_currently_installed_packages?api.version=1'],
            ['whm1:ea4_list_profiles',
                'https://test.oblak.host:2087/json-api/ea4_list_profiles?api.version=1'],
            ['whm1:ea4_metainfo',
                'https://test.oblak.host:2087/json-api/ea4_metainfo?api.version=1'],
            ['whm1:ea4_recommendations',
                'https://test.oblak.host:2087/json-api/ea4_recommendations?api.version=1'],
            ['whm1:eol_php_sites',
                'https://test.oblak.host:2087/json-api/eol_php_sites?api.version=1'],
            ['whm1:get_fpm_count_and_utilization',
                'https://test.oblak.host:2087/json-api/get_fpm_count_and_utilization?api.version=1'],
            ['whm1:php_get_vhost_versions',
                'https://test.oblak.host:2087/json-api/php_get_vhost_versions?api.version=1'],
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
            $data = [
                '{"version":"11.138.0.19"}',
                '{"additional_pkg_prefixes":[]}',
                '{"packages":[]}',
                '{}',
                '{}',
                '{}',
                '{"eol_php_sites":0}',
                '{}',
                '{"versions":[]}',
            ][$index];
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
