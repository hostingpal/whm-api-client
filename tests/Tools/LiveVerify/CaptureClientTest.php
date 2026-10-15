<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\CaptureClient;
use Http\Mock\Client;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class CaptureClientTest extends TestCase
{
    public function testCapturesOnlyWhenConsumerStringifiesAndPreservesResponse(): void
    {
        $body = Stream::create('{"data":{"load":"0.50","unknown":"private"}}');
        $body->read(3);
        $underlying = new Client();
        $underlying->addResponse(new Response(202, ['X-Example' => ['one', 'two']], $body, '1.0', 'Custom'));
        $capture = new CaptureClient($underlying);

        $response = $capture->sendRequest(new Request('GET', 'https://example.test/'));

        self::assertNull($capture->rawBody());
        self::assertSame(3, $body->tell());
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['one', 'two'], $response->getHeader('X-Example'));
        self::assertSame('Custom', $response->getReasonPhrase());
        self::assertSame('1.0', $response->getProtocolVersion());
        self::assertSame('{"data":{"load":"0.50","unknown":"private"}}', (string) $response->getBody());
        self::assertSame('{"data":{"load":"0.50","unknown":"private"}}', $capture->rawBody());
        self::assertSame(202, $capture->httpStatus());
        self::assertSame('GET', $capture->method());
        self::assertCount(1, $underlying->getRequests());
    }

    public function testOldResponseCannotReplaceLatestCapture(): void
    {
        $underlying = new Client();
        $underlying->addResponse(new Response(200, [], 'old'));
        $underlying->addResponse(new Response(201, [], 'current'));
        $capture = new CaptureClient($underlying);
        $old = $capture->sendRequest(new Request('GET', 'https://example.test/first'));
        $current = $capture->sendRequest(new Request('GET', 'https://example.test/second'));

        self::assertSame('current', (string) $current->getBody());
        self::assertSame('old', (string) $old->getBody());
        self::assertSame('current', $capture->rawBody());
        self::assertSame(201, $capture->httpStatus());
    }

    public function testResetsCaptureAndPreservesTransportException(): void
    {
        $underlying = new Client();
        $underlying->addResponse(new Response(200, [], 'first'));
        $exception = new class ('private transport detail') extends RuntimeException implements
            ClientExceptionInterface
        {
        };
        $capture = new CaptureClient($underlying);
        $response = $capture->sendRequest(new Request('GET', 'https://example.test/'));
        self::assertSame('first', (string) $response->getBody());
        $underlying->addException($exception);

        try {
            $capture->sendRequest(new Request('POST', 'https://example.test/'));
            self::fail('Transport failure must propagate.');
        } catch (ClientExceptionInterface $actual) {
            self::assertSame($exception, $actual);
        }

        self::assertNull($capture->rawBody());
        self::assertNull($capture->httpStatus());
        self::assertSame('POST', $capture->method());
        self::assertCount(2, $underlying->getRequests());
    }
}
