<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\TeamUserManagementProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class TeamUserManagementProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyNineExactAccountBoundGetRequests(): void
    {
        $probes = TeamUserManagementProbes::load();
        self::assertCount(9, $probes);
        $expected = [
            ['whm1:version', 'whm',
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'],
            ['uapi:Team/get_team_users_with_roles_count', 'direct-uapi',
                'https://test.oblak.host:2083/execute/Team/get_team_users_with_roles_count',
                '{"status":1,"errors":null,"data":{}}'],
            ['uapi:Team/get_team_users_with_roles_count', 'bridged-uapi',
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=Team&cpanel.function=get_team_users_with_roles_count',
                '{"metadata":{"result":1},"data":{"uapi":{"status":1,"errors":null,"data":{}}}}'],
            ['uapi:TeamRoles/list_feature_descriptions', 'direct-uapi',
                'https://test.oblak.host:2083/execute/TeamRoles/list_feature_descriptions',
                '{"status":1,"errors":null,"data":[]}'],
            ['uapi:TeamRoles/list_feature_descriptions', 'bridged-uapi',
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=TeamRoles&cpanel.function=list_feature_descriptions',
                '{"metadata":{"result":1},"data":{"uapi":{"status":1,"errors":null,"data":[]}}}'],
            ['uapi:UserManager/list_users', 'direct-uapi',
                'https://test.oblak.host:2083/execute/UserManager/list_users?flat=1',
                '{"status":1,"errors":null,"data":[]}'],
            ['uapi:UserManager/list_users', 'bridged-uapi',
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=UserManager&cpanel.function=list_users&flat=1',
                '{"metadata":{"result":1},"data":{"uapi":{"status":1,"errors":null,"data":[]}}}'],
            ['uapi:PasswdStrength/get_required_strength', 'direct-uapi',
                'https://test.oblak.host:2083/execute/PasswdStrength/get_required_strength?app=webdisk',
                '{"status":1,"errors":null,"data":{}}'],
            ['uapi:PasswdStrength/get_required_strength', 'bridged-uapi',
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=PasswdStrength&cpanel.function=get_required_strength&app=webdisk',
                '{"metadata":{"result":1},"data":{"uapi":{"status":1,"errors":null,"data":{}}}}'],
        ];
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
