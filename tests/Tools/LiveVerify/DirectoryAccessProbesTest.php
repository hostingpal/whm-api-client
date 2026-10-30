<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\DirectoryAccessProbes;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class DirectoryAccessProbesTest extends TestCase
{
    public function testOnlyReviewedReadsInvokeExactAccountBoundUris(): void
    {
        $probes = DirectoryAccessProbes::load();
        self::assertCount(13, $probes);
        $expected = [
            ['whm1:version', 'version', null],
            ['uapi:DirectoryIndexes/get_indexing', 'DirectoryIndexes', 'get_indexing'],
            ['uapi:DirectoryIndexes/list_directories', 'DirectoryIndexes', 'list_directories'],
            ['uapi:DirectoryPrivacy/is_directory_protected', 'DirectoryPrivacy', 'is_directory_protected'],
            ['uapi:DirectoryPrivacy/list_directories', 'DirectoryPrivacy', 'list_directories'],
            ['uapi:DirectoryPrivacy/list_users', 'DirectoryPrivacy', 'list_users'],
            ['uapi:DirectoryProtection/list_directories', 'DirectoryProtection', 'list_directories'],
        ];
        $position = 0;
        foreach ($expected as [$operation, $module, $function]) {
            foreach ($function === null ? ['whm'] : ['direct-uapi', 'bridged-uapi'] as $route) {
                $probe = $probes[$position++];
                self::assertSame($operation, $probe->operation);
                self::assertSame($route, $probe->route);
                self::assertSame('GET', $probe->spec['method']);
                self::assertNull($probe->unavailable);
                self::assertNull($probe->sourceVariant);
                $uri = match ($route) {
                    'whm' => 'https://test.oblak.host:2087/json-api/version?api.version=1',
                    'direct-uapi' => 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                        . '?dir=%2Fhome%2Fnpr%2Fpublic_html',
                    default => 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=' . $module . '&cpanel.function=' . $function
                        . '&dir=%2Fhome%2Fnpr%2Fpublic_html',
                };
                self::assertSame($uri, $probe->uri);
                $factory = new Psr17Factory();
                $http = new MockClient($factory);
                $client = new Client(
                    ['host' => 'test.oblak.host',
                    'authType' => $route === 'direct-uapi' ? 'cpanel' : 'whm',
                    'username' => $route === 'direct-uapi' ? 'npr' : 'root', 'token' => 'fixture-token'],
                    new HttpTransport($http, $factory, $factory),
                );
                self::assertSame([], $http->getRequests());
                $payload = match ($function) {
                    'get_indexing' => '"inherit"',
                    'list_users' => '[]',
                    default => '{}',
                };
                $result = '{"status":1,"errors":null,"data":' . $payload . '}';
                $body = match ($route) {
                    'whm' => '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}',
                    'direct-uapi' => $result,
                    default => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
                };
                $http->addResponse(new Response(200, [], $body));
                ($probe->invoke)($client);
                self::assertCount(1, $http->getRequests());
                $request = $http->getLastRequest();
                self::assertInstanceOf(RequestInterface::class, $request);
                self::assertSame('GET', $request->getMethod());
                self::assertSame($uri, (string) $request->getUri());
                self::assertSame('', (string) $request->getBody());
            }
        }
    }
}
