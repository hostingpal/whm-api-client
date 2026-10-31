<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\BackupRestorationProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class BackupRestorationProbesTest extends TestCase
{
    public function testCatalogExecutesOnlyElevenExactWhmGetRequests(): void
    {
        $probes = BackupRestorationProbes::load();
        self::assertCount(11, $probes);
        $expected = [
            ['whm1:version',
                'https://test.oblak.host:2087/json-api/version?api.version=1'],
            ['whm1:backup_config_get',
                'https://test.oblak.host:2087/json-api/backup_config_get?api.version=1'],
            ['whm1:get_users_and_domains_with_backup_metadata',
                'https://test.oblak.host:2087/json-api/get_users_and_domains_with_backup_metadata?api.version=1'],
            ['whm1:get_users_with_backup_metadata',
                'https://test.oblak.host:2087/json-api/get_users_with_backup_metadata?api.version=1'],
            ['whm1:list_cparchive_files',
                'https://test.oblak.host:2087/json-api/list_cparchive_files?api.version=1'],
            ['whm1:restore_modules_summary',
                'https://test.oblak.host:2087/json-api/restore_modules_summary?api.version=1'],
            ['whm1:restore_queue_is_active',
                'https://test.oblak.host:2087/json-api/restore_queue_is_active?api.version=1'],
            ['whm1:restore_queue_list_active',
                'https://test.oblak.host:2087/json-api/restore_queue_list_active?api.version=1'],
            ['whm1:restore_queue_list_completed',
                'https://test.oblak.host:2087/json-api/restore_queue_list_completed?api.version=1'],
            ['whm1:restore_queue_list_pending',
                'https://test.oblak.host:2087/json-api/restore_queue_list_pending?api.version=1'],
            ['whm1:restore_queue_state',
                'https://test.oblak.host:2087/json-api/restore_queue_state?api.version=1'],
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
