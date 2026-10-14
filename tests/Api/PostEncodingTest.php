<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Support\WhmQueryClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PostEncodingTest extends TestCase
{
    private const ENVELOPE = '{"metadata":{"command":"get_users_features_settings","reason":"OK","result":1,'
        . '"version":1},"data":{"features":[]}}';

    public function testBodyUsesTheSameEncoderAsQueriesAndKeepsRoutingFieldsInTheUrl(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::ENVELOPE));

        $client->post([
            'user' => new NumberedList(['npr', 'second user']),
            'feature' => ['autossl', 'a&b'],
            'api.version' => 7,
            'limit' => 10,
        ]);

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/get_users_features_settings?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame(
            'user=npr&user-1=second%20user&feature=autossl&feature=a%26b&api.version=7&limit=10',
            (string) $request->getBody(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->responseMeta()?->whmResult());
    }

    public function testEmptyBodyIsSentAsEmptyFormContent(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::ENVELOPE));

        $client->post([]);

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
    }

    public function testInvalidBodyListsFailLocallyAfterClearingMetadata(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::ENVELOPE));
        $client->post(['user' => 'npr']);
        self::assertNotNull($client->responseMeta());

        try {
            $client->post(['feature' => []]);
            self::fail('Empty lists must be rejected before I/O.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return array{WhmQueryClient, MockClient} */
    private function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new WhmQueryClient(new HttpTransport($http, $factory, $factory)), $http];
    }
}
