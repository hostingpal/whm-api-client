<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\AccountEnvironmentProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AccountEnvironmentProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyTwentySevenExactAccountBoundGetRequests(): void
    {
        $probes = AccountEnvironmentProbes::load();
        self::assertCount(27, $probes);
        self::assertSame('whm1:version', $probes[0]->operation);
        $cases = [
            ['ContactInformation', 'get_notification_preferences', '[]'],
            ['Contactus', 'is_enabled', '{}'],
            ['Locale', 'get_attributes', '{}'],
            ['Locale', 'list_locales', '[]'],
            ['Themes', 'list', '[]'],
            ['Notifications', 'get_notifications_count', '0'],
            ['AccountEnhancements', 'list', '[]'],
            ['EA4', 'get_php_recommendations', '[]'],
            ['EA4', 'get_recommendations', '{}'],
            ['ServerInformation', 'get_information', '[]'],
            ['Variables', 'get_server_information', '{}'],
            ['Variables', 'get_session_information', '{}'],
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
        // Exact literals keep this selected read independent from the catalog URI construction.
        $expected[] = [
            'uapi:Variables/get_user_information', 'direct-uapi',
            'https://test.oblak.host:2083/execute/Variables/get_user_information?name=domain',
            '{"status":1,"errors":null,"data":{"domain":"fixture.example"}}',
        ];
        $expected[] = [
            'uapi:Variables/get_user_information', 'bridged-uapi',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Variables&cpanel.function=get_user_information&name=domain',
            '{"metadata":{"result":1},"data":{"uapi":'
                . '{"status":1,"errors":null,"data":{"domain":"fixture.example"}}}}',
        ];
        self::assertCount(27, $expected);
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
