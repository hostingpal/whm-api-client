<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmMailManagementProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmMailManagementProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyEightExactWhmGetRequests(): void
    {
        $probes = WhmMailManagementProbes::load();
        self::assertCount(8, $probes);
        $expected = [
            ['whm1:version',
                'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:get_default_dmarc_record',
                'https://test.oblak.host:2087/json-api/get_default_dmarc_record?api.version=1'],
            ['whm1:is_sni_supported',
                'https://test.oblak.host:2087/json-api/is_sni_supported?api.version=1'],
            ['whm1:list_blocked_incoming_email_countries',
                'https://test.oblak.host:2087/json-api/list_blocked_incoming_email_countries?api.version=1'],
            ['whm1:list_blocked_incoming_email_domains',
                'https://test.oblak.host:2087/json-api/list_blocked_incoming_email_domains?api.version=1'],
            ['whm1:list_pops_for',
                'https://test.oblak.host:2087/json-api/list_pops_for?api.version=1&user=npr'],
            ['whm1:mail_sni_status',
                'https://test.oblak.host:2087/json-api/mail_sni_status?api.version=1&domain=npr.rs'],
            ['whm1:get_user_email_forward_destination',
                'https://test.oblak.host:2087/json-api/get_user_email_forward_destination?api.version=1&user=npr'],
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
                '{"payload":{"record":"v=DMARC1; p=none;"}}',
                '{"sni":1}',
                '{"countries":[]}',
                '{"domains":[]}',
                '{"pops":[]}',
                '{"enabled":1}',
                '{"forward_to":[]}',
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
