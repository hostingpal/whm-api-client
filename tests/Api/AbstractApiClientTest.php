<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Config\ClientConfigFactory;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class AbstractApiClientTest extends TestCase
{
    public function testRequestLifecycleClearsMetadataAndKeepsRetainedSnapshots(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $transport = new HttpTransport($http, $factory, $factory);
        $client = new class ($transport) extends AbstractApiClient {
            public function __construct(HttpTransport $transport)
            {
                parent::__construct(ClientConfigFactory::fromArray([
                    'host' => 'whm.example.test',
                    'authType' => 'whm',
                    'token' => 'fixture-token',
                ]), $transport);
            }

            public function startAttempt(): void
            {
                $this->beginRequest();
            }

            public function receiveMetadata(ResponseMetadata $metadata): void
            {
                $this->recordResponseMeta($metadata);
            }
        };

        $other = clone $client;
        self::assertNull($client->responseMeta());
        $first = new ResponseMetadata(200, ['result' => 1]);
        $client->receiveMetadata($first);
        self::assertSame($first, $client->responseMeta());

        $client->startAttempt();
        self::assertNull($client->responseMeta());
        self::assertSame(['result' => 1], $first->whm);

        $failure = new ResponseMetadata(200, ['result' => 0, 'reason' => 'failed']);
        $client->receiveMetadata($failure);
        self::assertSame($failure, $client->responseMeta());
        self::assertSame(['result' => 1], $first->whm);
        self::assertNull($other->responseMeta());
        self::assertSame([], $http->getRequests());
    }
}
