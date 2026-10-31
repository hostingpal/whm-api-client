<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Http;

use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tests\Support\UploadProbeStream;
use HostingPal\WhmApiClient\Tests\Support\UploadTestClient;
use HostingPal\WhmApiClient\Upload;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

final class HttpTransportStreamTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    public function testOrdinaryCallerStreamAttachesDirectlyWithoutFactoryCopyOrOwnership(): void
    {
        $stream = new UploadProbeStream('ordinary');
        $client = new UploadTestClient(static function (RequestInterface $request) use ($stream): Response {
            self::assertSame($stream, $request->getBody());
            self::assertSame('custom/type', $request->getHeaderLine('Content-Type'));
            self::assertFalse($request->hasHeader('Content-Length'));
            return new Response(201);
        });
        $streams = $this->createStub(StreamFactoryInterface::class);
        $streams->method('createStream')->willThrowException(new RuntimeException('Stream factory must not copy.'));
        $response = (new HttpTransport($client, new Psr17Factory(), $streams))->send(
            'POST',
            'https://example.test/stream',
            ['Content-Type' => 'custom/type'],
            $stream,
        );
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $stream->reads);
        self::assertSame(0, $stream->closes);
        self::assertSame(0, $stream->detaches);
    }

    public function testMultipartSameObjectAndExactFinalHeadersAndLiteralBody(): void
    {
        $stream = new UploadProbeStream("x\0\xff");
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromStream('a', $stream)],
        ], static fn (): string => 'test-boundary');
        $literal = "--test-boundary\r\nContent-Disposition: form-data; name=\"f\"; filename=\"a\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\nx\0\xff\r\n--test-boundary--\r\n";
        $client = new UploadTestClient(static function (RequestInterface $request) use ($body, $literal): Response {
            self::assertSame($body, $request->getBody());
            self::assertSame((string) strlen($literal), $request->getHeaderLine('Content-Length'));
            self::assertSame('multipart/form-data; boundary=test-boundary', $request->getHeaderLine('Content-Type'));
            self::assertFalse($request->hasHeader('Transfer-Encoding'));
            self::assertSame($literal, $request->getBody()->getContents());
            self::assertSame(strlen($literal), $request->getBody()->getSize());
            return new Response(202);
        });
        $factory = new Psr17Factory();
        $response = (new HttpTransport($client, $factory, $factory))->send('POST', 'https://example.test/upload', [
            'content-type' => 'wrong/type', 'content-length' => '1', 'transfer-encoding' => 'chunked',
        ], $body);
        self::assertSame(202, $response->getStatusCode());
        self::assertFalse($body->isReadable());
        self::assertSame(strlen($literal), $body->getSize());
        self::assertSame(1, $client->calls);
        self::assertSame(0, $stream->closes);
    }

    #[DataProvider('clientExits')]
    public function testEverySynchronousClientExitReleasesOwnedSourcesWithoutRetry(string $mode): void
    {
        $path = $this->file('private-owned-content');
        $borrowed = new UploadProbeStream('borrowed');
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromPath($path)],
            ['name' => 'g', 'value' => Upload::fromStream('g', $borrowed)],
        ]);
        self::assertCount(1, $this->handles($path));
        $error = new class ('client-failure') extends RuntimeException implements ClientExceptionInterface {
        };
        $client = new UploadTestClient(static function (RequestInterface $request) use ($mode, $error): Response {
            if ($mode === 'full') {
                $request->getBody()->getContents();
            }
            if ($mode === 'partial' || $mode === 'throw-during') {
                $request->getBody()->read(7);
            }
            if ($mode === 'throw-before' || $mode === 'throw-during') {
                throw $error;
            }
            return new Response(413);
        });
        $factory = new Psr17Factory();
        try {
            $response = (new HttpTransport($client, $factory, $factory))->send(
                'POST',
                'https://example.test/upload',
                [],
                $body,
            );
            self::assertContains($mode, ['none', 'partial', 'full']);
            self::assertSame(413, $response->getStatusCode());
            $body->assertHealthy();
        } catch (ClientExceptionInterface $caught) {
            self::assertSame($error, $caught);
            self::assertContains($mode, ['throw-before', 'throw-during']);
        }
        self::assertSame([], $this->handles($path));
        self::assertFalse($body->isReadable());
        self::assertSame(1, $client->calls);
        self::assertSame(0, $borrowed->closes);
        self::assertSame(0, $borrowed->detaches);
    }

    /** @return iterable<string, array{string}> */
    public static function clientExits(): iterable
    {
        foreach (['none', 'partial', 'full', 'throw-before', 'throw-during'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('factoryFailures')]
    public function testRequestAndHeaderFactoryFailuresReleaseOwnedSources(string $mode): void
    {
        $path = $this->file('private-owned-content');
        $body = StreamingMultipartEncoder::encode([['name' => 'f', 'value' => Upload::fromPath($path)]]);
        $error = new RuntimeException('factory failure');
        $request = $this->createStub(RequestInterface::class);
        if ($mode === 'body') {
            $request->method('withHeader')->willReturnSelf();
            $request->method('withBody')->willThrowException($error);
        } else {
            $request->method('withHeader')->willThrowException($error);
        }
        $requests = $this->createStub(RequestFactoryInterface::class);
        if ($mode === 'request') {
            $requests->method('createRequest')->willThrowException($error);
        } else {
            $requests->method('createRequest')->willReturn($request);
        }
        $client = new UploadTestClient(static fn (): Response => new Response());
        try {
            (new HttpTransport($client, $requests, new Psr17Factory()))->send(
                'POST',
                'https://example.test/upload',
                ['X-Header' => 'value'],
                $body,
            );
            self::fail('Factory failure must propagate.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame([], $this->handles($path));
        self::assertSame(0, $client->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function factoryFailures(): iterable
    {
        yield 'request factory' => ['request'];
        yield 'header factory' => ['header'];
        yield 'body factory' => ['body'];
    }

    #[DataProvider('swallowedFailures')]
    public function testSwallowedBodyFailuresCannotBeReturnedAsSuccessfulDelivery(string $mode): void
    {
        $path = $this->file('owned');
        $stream = new UploadProbeStream('test-boundary');
        if ($mode === 'source') {
            $stream->throwRead = true;
        } elseif ($mode === 'early EOF') {
            $stream = new UploadProbeStream('short');
            $stream->reportedSize = 40;
        }
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromPath($path)],
            ['name' => 'g', 'value' => Upload::fromStream('private-file', $stream)],
        ], static fn (): string => 'test-boundary');
        $client = new UploadTestClient(static function (RequestInterface $request) use ($mode): Response {
            if ($mode !== 'caught') {
                self::assertSame('', (string) $request->getBody());
            } else {
                try {
                    $request->getBody()->getContents();
                    self::fail('Collision must throw while reading.');
                } catch (AssertionFailedError $failure) {
                    throw $failure;
                } catch (RuntimeException) {
                    $request->getBody()->close();
                }
            }
            return new Response(200);
        });
        $factory = new Psr17Factory();
        try {
            (new HttpTransport($client, $factory, $factory))->send('POST', 'https://example.test/upload', [], $body);
            self::fail('Recorded body failure must override a returned response.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private', $error->getMessage());
        }
        self::assertSame(1, $client->calls);
        self::assertSame([], $this->handles($path));
        self::assertSame(0, $stream->closes);
    }

    /** @return iterable<string, array{string}> */
    public static function swallowedFailures(): iterable
    {
        yield 'PSR string cast' => ['string'];
        yield 'client catch and close' => ['caught'];
        yield 'source exception swallowed by string conversion' => ['source'];
        yield 'early EOF swallowed by string conversion' => ['early EOF'];
    }

    public function testPreviouslyRecordedFailureRejectsBeforeAnotherClientAttempt(): void
    {
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromString('f', 'test-boundary')],
        ], static fn (): string => 'test-boundary');
        self::assertSame('', (string) $body);
        $client = new UploadTestClient(static fn (): Response => new Response());
        $factory = new Psr17Factory();
        try {
            (new HttpTransport($client, $factory, $factory))->send('POST', 'https://example.test/upload', [], $body);
            self::fail('A failed body cannot start another send.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException $error) {
            self::assertNull($error->getPrevious());
        }
        self::assertSame(0, $client->calls);
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'multipart-transport-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->paths[] = $path;
        return $path;
    }

    /** @return array<int, resource> */
    private function handles(string $path): array
    {
        return array_filter(get_resources('stream'), static fn ($resource): bool =>
            (stream_get_meta_data($resource)['uri'] ?? null) === $path);
    }
}
