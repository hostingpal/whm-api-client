<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class MimeProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyThirteenExactAccountBoundGetRequests(): void
    {
        $probes = MimeProbes::load();
        self::assertCount(13, $probes);
        self::assertSame('whm1:version', $probes[0]->operation);
        $cases = [
            ['get_redirect', 'domain=npr.rs', '{}'],
            ['list_handlers', 'type=user', '[]'],
            ['list_hotlinks', '', '{}'],
            ['list_mime', 'type=user', '[]'],
            ['list_redirects', '', '[]'],
            ['redirect_info', 'domain=npr.rs&url=http%3A%2F%2Fnpr.rs', '{}'],
        ];
        $expected = [['whm1:version', 'whm', 'https://test.oblak.host:2087/json-api/version?api.version=1',
            '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}']];
        foreach ($cases as [$function, $parameters, $payload]) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $uri = $route === 'direct-uapi'
                    ? 'https://test.oblak.host:2083/execute/Mime/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=Mime&cpanel.function=' . $function;
                if ($parameters !== '') {
                    $uri .= ($route === 'direct-uapi' ? '?' : '&') . $parameters;
                }
                $response = '{"status":1,"errors":null,"data":' . $payload . '}';
                $expected[] = ['uapi:Mime/' . $function, $route, $uri,
                    $route === 'direct-uapi'
                        ? $response : '{"metadata":{"result":1},"data":{"uapi":' . $response . '}}'];
            }
        }
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
