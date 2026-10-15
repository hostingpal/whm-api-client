<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Pin how generated operations separate applied-but-unexpected payloads from failures.
 *
 * Fixtures use reserved example.test names and RFC 5737 addresses only.
 */
final class PayloadMismatchTest extends TestCase
{
    public function testAppliedMutationWithAnUnexpectedPayloadIsAConfirmedMismatch(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"createacct","reason":"Account Creation Ok",'
            . '"result":1,"version":1},"data":{"ip":3221225985,"nameserver":"ns1.example.test"}}'));

        try {
            $client->whm()->accounts()->createAccount('example');
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame(['ip' => 3221225985, 'nameserver' => 'ns1.example.test'], $exception->payload);
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('POST', $request->getMethod());
        }
    }

    public function testFailedMutationRemainsAnApiError(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"createacct",'
            . '"reason":"Sorry, that username is taken.","result":0,"version":1},"data":null}'));

        $this->expectException(ApiErrorException::class);

        $client->whm()->accounts()->createAccount('example');
    }

    public function testUnusableEnvelopeIsNotAPayloadMismatch(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":'));

        try {
            $client->whm()->accounts()->createAccount('example');
            self::fail('Expected an invalid response.');
        } catch (InvalidResponseException $exception) {
            self::assertNotInstanceOf(PayloadMismatchException::class, $exception);
        }
    }

    public function testBridgedUapiMismatchIsConfirmedByTheInnerStatus(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"OK"},'
            . '"data":{"uapi":{"status":1,"errors":null,"messages":null,"warnings":null,"data":[1]}}}'));

        try {
            $client->uapi('example')->dns()->lookup('www.example.test');
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame([1], $exception->payload);
            self::assertSame($client->uapi('example')->responseMeta(), $exception->metadata);
        }
    }

    /** @return array{Client, MockClient} */
    private function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [
            new Client(
                ['host' => 'whm.example.test', 'authType' => 'whm', 'token' => 'fixture-token'],
                new HttpTransport($http, $factory, $factory),
            ),
            $http,
        ];
    }
}
