<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Config\ClientConfigFactory;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class QueryEncodingTest extends TestCase
{
    public function testRepeatedParametersRetainNamesOrderAndRfc3986Escaping(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"domains":[]}}'));
        $client = new class (new HttpTransport($http, $factory, $factory)) extends AbstractApiClient {
            public function __construct(HttpTransport $transport)
            {
                parent::__construct(ClientConfigFactory::fromArray([
                    'host' => 'api.example.test',
                    'authType' => 'cpanel',
                    'username' => 'npr',
                    'token' => 'fixture-token',
                ]), $transport);
            }

            public function impacted(): ApiResponse
            {
                return $this->executeGet('/execute/LangPHP/php_get_impacted_domains', [
                    'domain' => ['first.example', 'space & plus+'],
                    'system_default' => 0,
                ], ResponseRoute::DirectUapi);
            }
        };

        $client->impacted();
        $requests = $http->getRequests();
        self::assertCount(1, $requests);
        self::assertSame(
            'domain=first.example&domain=space%20%26%20plus%2B&system_default=0',
            $requests[0]->getUri()->getQuery(),
        );
        self::assertSame(1, $client->responseMeta()?->uapi['status'] ?? null);
    }
}
