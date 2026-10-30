<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainManagementProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DomainManagementProbesTest extends TestCase
{
    public function testCatalogHasExactlyTheReviewedCallsAndEveryCallbackSatisfiesItsLiteralGuard(): void
    {
        $probes = DomainManagementProbes::load();
        $expected = [['whm1:version', 'whm', 'https://test.oblak.host:2087/json-api/version?api.version=1']];
        $reads = [
            ['AddonDomain', 'listaddondomains', 'return_https_redirect_status=0'],
            ['Domain', 'is_temporary_domain', 'domain=npr.rs'],
            ['Domain', 'temporary_domain_is_disabled', ''],
            ['DomainLookup', 'countbasedomains', ''],
            ['DomainLookup', 'getbasedomains', 'return_temporary_domain=0'],
            ['DomainLookup', 'getdocroot', 'domain=npr.rs'],
            ['DomainLookup', 'getdocroots', ''],
            ['Park', 'listparkeddomains', 'return_https_redirect_status=0'],
            ['SubDomain', 'getreservedsubdomains', ''],
            ['SubDomain', 'listsubdomains', 'return_https_redirect_status=0'],
            ['SubDomain', 'validregex', ''],
            ['WebVhosts', 'list_domains', ''],
            ['WebVhosts', 'list_ssl_capable_domains', 'hide_temporary_domains=0'],
        ];
        foreach ($reads as [$module, $function, $query]) {
            $operation = 'uapi:' . $module . '/' . $function;
            $expected[] = [$operation, 'direct-uapi',
                'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                . ($query === '' ? '' : '?' . $query)];
            $expected[] = [$operation, 'bridged-uapi',
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr&cpanel.module='
                . $module . '&cpanel.function=' . $function . ($query === '' ? '' : '&' . $query)];
        }
        self::assertCount(27, $probes);
        foreach ($expected as $index => [$operation, $route, $uri]) {
            $probe = $probes[$index];
            self::assertSame($operation, $probe->operation);
            self::assertSame($route, $probe->route);
            self::assertSame($uri, $probe->uri);
            self::assertNull($probe->unavailable);
            self::assertSame('GET', $probe->spec['method']);
            self::assertSame($operation . '#GET', $probe->sourceVariant);
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $payload = match ($operation) {
                'whm1:version' => '{"version":"11.138.0.19"}',
                'uapi:Domain/is_temporary_domain' => '0',
                'uapi:Domain/temporary_domain_is_disabled' => '{"is_disabled":0}',
                default => '[]',
            };
            $result = '{"status":1,"errors":null,"data":' . $payload . '}';
            $envelope = match ($route) {
                'whm' => '{"metadata":{"result":1},"data":' . $payload . '}',
                'bridged-uapi' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
                default => '{"result":' . $result . '}',
            };
            $http->addResponse(new Response(200, [], $envelope));
            $client = new Client([
                'host' => 'test.oblak.host',
                'authType' => $route === 'direct-uapi' ? 'cpanel' : 'whm',
                'username' => $route === 'direct-uapi' ? 'npr' : 'root',
                'token' => 'fixture-token',
            ], new HttpTransport(new RequestGuard($http, $uri), $factory, $factory));
            ($probe->invoke)($client);
            self::assertCount(1, $http->getRequests());
        }
    }
}
