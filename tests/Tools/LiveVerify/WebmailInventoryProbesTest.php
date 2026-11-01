<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailInventoryProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** @phpstan-import-type Context from EvidenceRunner */
final class WebmailInventoryProbesTest extends TestCase
{
    private const SESSION_ROW = '{"ip_address":"192.0.2.10","is_current":1,"is_possessed":0,'
        . '"last_active":1700000000,"login_theme":"jupiter","tfa_verified":0}';

    private const APP_ROW = '{"displayname":"Synthetic Mail","icon":"/synthetic/icon.svg","id":"synthetic",'
        . '"url":"https://synthetic.example/cpsessPRIVATETOKEN/mail"}';

    public function testCatalogExecutesOnlyFiveExactAccountBoundGetRequests(): void
    {
        $probes = WebmailInventoryProbes::load();
        self::assertCount(5, $probes);
        $cases = [
            ['ActiveSessions', 'list_active_sessions', '[' . self::SESSION_ROW . ']'],
            ['WebmailApps', 'list_webmail_apps', '[' . self::APP_ROW . ']'],
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
            self::assertSame('GET', $request->getMethod());
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

    public function testCatalogNeverReachesTheSessionCreators(): void
    {
        foreach (WebmailInventoryProbes::load() as $probe) {
            self::assertStringNotContainsString('/Session/', $probe->uri);
            self::assertStringNotContainsString('cpanel.module=Session&', $probe->uri);
            self::assertStringNotContainsString('uapi:Session/', $probe->operation);
            self::assertStringNotContainsString('create_', $probe->uri);
        }
    }

    public function testPopulatedRowsPassWithoutPersistingTokenBearingValues(): void
    {
        $http = self::mock([self::SESSION_ROW], [self::APP_ROW]);
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            WebmailInventoryProbes::load(),
            'webmail-inventory-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );

        self::assertSame('passed', $report['status']);
        self::assertCount(5, $http->getRequests());
        self::assertSame(['string'], $report['probes'][3]['payload_paths']['$[].url']);
        self::assertSame(['int'], $report['probes'][1]['payload_paths']['$[].is_current']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        $privateValues = ['cpsessPRIVATETOKEN', 'synthetic.example', '192.0.2.10', 'private-whm-token',
            'private-cpanel-token', 'Synthetic Mail', 'jupiter'];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testEmptySessionListPassesButObservesNoRowFields(): void
    {
        $report = (new ReadOnlySectionRunner(self::mock([], [self::APP_ROW]), self::context()))->run(
            WebmailInventoryProbes::load(),
            'webmail-inventory-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );

        self::assertSame('passed', $report['status']);
        foreach ([1, 2] as $index) {
            self::assertSame(['$' => ['empty-list']], $report['probes'][$index]['payload_paths']);
        }
    }

    /**
     * Direct and bridged responses for both modules, after the WHM version response.
     *
     * @param list<string> $sessions
     * @param list<string> $apps
     */
    private static function mock(array $sessions, array $apps): MockClient
    {
        $http = new MockClient();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        foreach ([$sessions, $apps] as $rows) {
            $response = '{"status":1,"errors":null,"data":[' . implode(',', $rows) . ']}';
            $http->addResponse(new Response(200, [], $response));
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":' . $response . '}}'));
        }

        return $http;
    }

    /** @return Context */
    private static function context(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'revision' => str_repeat('b', 40),
            'source_hashes' => ['whm1' => $hash, 'uapi' => $hash],
            'map_sha256' => $hash,
            'generator_tree' => str_repeat('c', 40),
            'overlay_sha256' => $hash,
        ];
    }
}
