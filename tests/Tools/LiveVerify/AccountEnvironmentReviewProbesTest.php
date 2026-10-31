<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\AccountEnvironmentReviewProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AccountEnvironmentReviewProbesTest extends TestCase
{
    public function testCatalogBindsEveryPinnedUserFieldExceptTheTokenOnBothRoutes(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../docs/api/sources/2026-10-13/cpanel.openapi.json.gz');
        self::assertIsString($source);
        $json = gzdecode($source);
        self::assertIsString($json);
        $node = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach (
            ['paths', '/Variables/get_user_information', 'get', 'responses', '200', 'content',
                'application/json', 'schema', 'properties', 'result', 'properties', 'data', 'properties'] as $key
        ) {
            self::assertIsArray($node);
            $node = $node[$key];
        }
        self::assertIsArray($node);
        $fields = [];
        foreach (array_keys($node) as $field) {
            self::assertIsString($field);
            $fields[] = $field;
        }
        self::assertCount(68, $fields);
        $fields = array_values(array_diff($fields, ['pushbullet_access_token']));
        sort($fields);
        self::assertCount(67, $fields);
        $query = implode('&', array_map(static fn (string $name): string => 'name=' . $name, $fields));
        $probes = AccountEnvironmentReviewProbes::load();
        self::assertCount(3, $probes);
        foreach ($probes as $index => $probe) {
            $route = ['whm', 'direct-uapi', 'bridged-uapi'][$index];
            $uri = match ($route) {
                'whm' => 'https://test.oblak.host:2087/json-api/version?api.version=1',
                'direct-uapi' => 'https://test.oblak.host:2083/execute/Variables/get_user_information?' . $query,
                default => 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=Variables&cpanel.function=get_user_information&' . $query,
            };
            self::assertSame($route, $probe->route);
            self::assertSame($uri, $probe->uri);
            self::assertSame('GET', $probe->spec['method']);
            self::assertStringNotContainsString('pushbullet', $uri);
            self::assertNull($probe->unavailable);
            self::assertSame($route === 'whm' ? null : 'documented-fields-except-token', $probe->argumentVariant);
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $payload = '{"domain":"fixture.example","uid":"1001","feature":{"fixture":"1"},'
                . '"contact_email":null,"contact_email_2":null}';
            $uapi = '{"status":1,"errors":null,"data":' . $payload . '}';
            $response = match ($route) {
                'whm' => '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}',
                'direct-uapi' => $uapi,
                default => '{"metadata":{"result":1},"data":{"uapi":' . $uapi . '}}',
            };
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
            self::assertSame('', (string) $request->getBody());
            foreach (
                [
                    new Request('POST', $uri),
                    new Request('GET', $uri, [], 'unexpected body'),
                    new Request('GET', $uri . '&name=pushbullet_access_token'),
                    new Request('GET', str_replace('test.oblak.host', 'other.example', $uri)),
                    new Request(
                        'GET',
                        $route === 'whm' ? $uri . '&name=domain' : str_replace('name=domain&', '', $uri),
                    ),
                ] as $forbidden
            ) {
                try {
                    (new RequestGuard($http, $uri))->sendRequest($forbidden);
                    self::fail('Changed catalog request must be refused before I/O.');
                } catch (LogicException) {
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }
}
