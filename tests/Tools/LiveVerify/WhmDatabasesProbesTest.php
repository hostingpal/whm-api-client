<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmDatabasesProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmDatabasesProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyNineExactWhmGetRequests(): void
    {
        $probes = WhmDatabasesProbes::load();
        self::assertCount(9, $probes);
        $expected = [
            ['whm1:version',
                'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:current_mysql_version',
                'https://test.oblak.host:2087/json-api/current_mysql_version?api.version=1'],
            ['whm1:get_database_optimizations',
                'https://test.oblak.host:2087/json-api/get_database_optimizations?api.version=1'],
            ['whm1:installable_mysql_versions',
                'https://test.oblak.host:2087/json-api/installable_mysql_versions?api.version=1'],
            ['whm1:latest_available_mysql_version',
                'https://test.oblak.host:2087/json-api/latest_available_mysql_version?api.version=1'],
            ['whm1:list_database_users',
                'https://test.oblak.host:2087/json-api/list_database_users?api.version=1'],
            ['whm1:list_databases',
                'https://test.oblak.host:2087/json-api/list_databases?api.version=1'],
            ['whm1:list_mysql_databases_and_users',
                'https://test.oblak.host:2087/json-api/list_mysql_databases_and_users?api.version=1&user=npr'],
            ['whm1:remote_mysql_monitor_profile_activation',
                'https://test.oblak.host:2087/json-api/remote_mysql_monitor_profile_activation?api.version=1'],
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
