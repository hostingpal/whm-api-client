<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RepeatedList;
use HostingPal\WhmApiClient\Api\SuffixNumberedList;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Http\StreamingMultipartBody;
use HostingPal\WhmApiClient\Response\CallResult;
use HostingPal\WhmApiClient\Tests\Support\UploadProbeStream;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class RawMultipartCallTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function routes(): iterable
    {
        yield 'WHM' => [false];
        yield 'direct UAPI' => [true];
    }

    #[DataProvider('routes')]
    public function testExactStreamedRequestPreservesBinaryOrderRepeatedNamesAndQuery(bool $direct): void
    {
        $request = null;
        $bytes = '';
        $sends = 0;
        $stream = new UploadProbeStream('prefixREST');
        $stream->seek(6);
        $client = self::client($direct, static function (RequestInterface $sent) use (
            &$request,
            &$bytes,
            &$sends,
            $stream,
            $direct,
        ): ResponseInterface {
            ++$sends;
            self::assertSame(0, $stream->reads);
            self::assertInstanceOf(StreamingMultipartBody::class, $sent->getBody());
            $request = $sent;
            while (!$sent->getBody()->eof()) {
                $bytes .= $sent->getBody()->read(17);
            }
            self::assertSame(strlen($bytes), $sent->getBody()->getSize());
            return self::success($direct);
        });
        $parts = [
            ['name' => 'same', 'value' => '/looks/like/a/path &+%'],
            ['name' => 'same', 'value' => Upload::fromString('ž space.bin', "\0\xff0", 'image/png')],
            ['name' => 'same', 'value' => ''],
            ['name' => 'file-0', 'value' => Upload::fromString('empty', '')],
            ['name' => 'borrowed', 'value' => Upload::fromStream('rest', $stream)],
            ['name' => 'literal.0-1', 'value' => 'raw'],
        ];
        $result = self::call($client, $direct, $parts, [
            'space &/%' => 'a +%/', 'repeat' => new RepeatedList(['a', 'b']),
            'domain' => new NumberedList(['first', 'second']), 'action*' => new SuffixNumberedList(['one', 'two']),
        ]);
        self::assertSame(['ok' => true], $result->data);
        self::assertSame($result->metadata, ($direct ? $client->uapi() : $client->whm())->responseMeta());
        self::assertSame(1, $sends);
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2443/'
            . ($direct ? 'execute/Example/operation?' : 'json-api/Example/operation?api.version=1&')
            . 'space%20%26%2F%25=a%20%2B%25%2F&repeat=a&repeat=b&domain=first&domain-1=second&action1=one&action2=two',
            (string) $request->getUri(),
        );
        self::assertSame(
            $direct ? 'cpanel alpha:fixture-token' : 'whm root:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertFalse($request->hasHeader('Transfer-Encoding'));
        self::assertSame((string) strlen($bytes), $request->getHeaderLine('Content-Length'));
        $type = $request->getHeaderLine('Content-Type');
        self::assertMatchesRegularExpression('/\Amultipart\/form-data; boundary=whm-api-[a-f0-9]{48}\z/', $type);
        $boundary = substr($type, strlen('multipart/form-data; boundary='));
        self::assertSame(
            '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"same\"\r\n\r\n/looks/like/a/path &+%\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"same\"; filename=\"ž space.bin\"\r\n"
            . "Content-Type: image/png\r\n\r\n\0\xff0\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"same\"\r\n\r\n\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file-0\"; filename=\"empty\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"borrowed\"; filename=\"rest\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\nREST\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"literal.0-1\"\r\n\r\nraw\r\n"
            . '--' . $boundary . "--\r\n",
            $bytes,
        );
        self::assertSame(10, $stream->tell());
        self::assertSame(1, $stream->seeks);
        self::assertSame(0, $stream->closes);
        self::assertSame(0, $stream->detaches);
        self::assertFalse($request->getBody()->isReadable());
        if (!$direct) {
            self::assertNull($client->uapi('sibling')->responseMeta());
        } else {
            self::assertSame($client->uapi(), $client->uapi('alpha'));
            $other = self::client(true, static fn (): ResponseInterface => self::success(true));
            self::assertNull($other->uapi()->responseMeta());
        }
    }

    #[DataProvider('routes')]
    public function testEmptyPartsAndOmittedNullQueryKeepLiteralLookalikes(bool $direct): void
    {
        $client = self::client($direct, static function (RequestInterface $request) use ($direct): ResponseInterface {
            $boundary = substr($request->getHeaderLine('Content-Type'), strlen('multipart/form-data; boundary='));
            self::assertSame('--' . $boundary . "--\r\n", $request->getBody()->getContents());
            self::assertSame(
                ($direct ? '' : 'api.version=1&') . 'API.version=a&apiVersion=b&api=c',
                $request->getUri()->getQuery(),
            );
            return self::success($direct);
        });
        self::call($client, $direct, [], ['omitted' => null, 'API.version' => 'a', 'apiVersion' => 'b', 'api' => 'c']);
        $client = self::client($direct, static function (RequestInterface $request) use ($direct): ResponseInterface {
            self::assertStringContainsString('name="same"', $request->getBody()->getContents());
            self::assertSame($direct ? '' : 'api.version=1', $request->getUri()->getQuery());
            return self::success($direct);
        });
        self::call($client, $direct, [['name' => 'same', 'value' => 'body']], ['same' => null]);
    }

    /** @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>}> */
    public static function invalidInputs(): iterable
    {
        foreach ([null, 0, 1.5, true, new \stdClass(), ['x']] as $i => $value) {
            yield 'value ' . $i => [[['name' => 'secret-part-name', 'value' => $value]], []];
        }
        foreach ([new NumberedList(['a']), new RepeatedList(['a']), new SuffixNumberedList(['a'])] as $i => $value) {
            yield 'part marker ' . $i => [[['name' => 'secret-part-name', 'value' => $value]], []];
        }
        foreach (['', 'bad*', 'bad[]', 'bad name', "bad\r\n", 'bad"'] as $name) {
            yield 'name ' . bin2hex($name) => [[['name' => $name, 'value' => 'secret-value']], []];
        }
        yield 'sparse' => [[2 => ['name' => 'a', 'value' => 'b']], []];
        yield 'map' => [['name' => 'a', 'value' => 'b'], []];
        yield 'missing value' => [[['name' => 'a']], []];
        yield 'extra field' => [[['name' => 'a', 'value' => 'b', 'extra' => 'secret-value']], []];
        yield 'non-array row' => [['secret-value'], []];
        yield 'numeric query key' => [[], [0 => 'secret-value']];
        yield 'numeric omitted query key' => [[], [0 => null]];
        yield 'bad query type' => [[], ['secret-query-name' => new \stdClass()]];
        yield 'bad late query list' => [[], ['secret-query-name' => ['a', 2]]];
        yield 'reverse query suffix collision' => [[], ['action1' => 'b', 'action*' => new SuffixNumberedList(['a'])]];
        yield 'query suffix collision' => [[], ['action*' => new SuffixNumberedList(['a']), 'action1' => 'b']];
        yield 'query collision' => [[['name' => 'same', 'value' => 'body']], ['same' => 'query']];
        yield 'expanded collision' => [
        [['name' => 'domain-1', 'value' => 'body']],
            ['domain' => new NumberedList(['one', 'two'])]];
        yield 'suffix collision' => [
        [['name' => 'action1', 'value' => 'body']],
            ['action*' => new SuffixNumberedList(['one'])]];
        foreach (['api.', 'api.version', 'api.other'] as $name) {
            yield $name . ' part' => [[['name' => $name, 'value' => 'body']], []];
            yield $name . ' null' => [[], [$name => null]];
            yield $name . ' query' => [[], [$name => 'query']];
            yield $name . ' expanded' => [[], [$name => new NumberedList(['one', 'two'])]];
        }
    }

    /**
     * @param array<array-key, mixed> $tail
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('invalidInputs')]
    public function testLateInvalidInputClearsMetadataAndDoesNoSourceIo(array $tail, array $query): void
    {
        foreach ([false, true] as $direct) {
            $sends = 0;
            $client = self::client($direct, static function () use (&$sends, $direct): ResponseInterface {
                ++$sends;
                return self::success($direct);
            });
            self::call($client, $direct, []);
            $handle = $direct ? $client->uapi() : $client->whm();
            self::assertNotNull($handle->responseMeta());
            $stream = new UploadProbeStream('unconsumed-secret');
            $parts = [
                ['name' => 'first', 'value' => Upload::fromPath('/nonexistent/private-path')],
                ['name' => 'borrowed', 'value' => Upload::fromStream('secret-file', $stream)],
            ];
            // Preserve malformed outer keys rather than normalizing them.
            $parts = array_is_list($tail) ? [...$parts, ...$tail] : $tail;
            $error = self::caught(static fn () => self::call($client, $direct, $parts, $query));
            self::assertInstanceOf(InvalidArgumentException::class, $error);
            self::assertNull($error->getPrevious());
            self::assertNull($handle->responseMeta());
            self::assertSame(1, $sends);
            self::assertSame(0, $stream->reads);
            self::assertSame(0, $stream->metadataCalls);
            self::assertSame(0, $stream->tell());
        }
    }

    #[DataProvider('routes')]
    public function testLiteralPartsAndLegacyQueryRepeatsKeepTheirWireNames(bool $direct): void
    {
        $client = self::client($direct, static function (RequestInterface $request) use ($direct): ResponseInterface {
            self::assertSame(
                ($direct ? '' : 'api.version=1&') . 'domain=a&domain-1=b&domain-1=c',
                $request->getUri()->getQuery(),
            );
            $bytes = $request->getBody()->getContents();
            foreach (['API.version', 'apiVersion', 'api', 'cpanel.other', 'literal-1'] as $name) {
                self::assertStringContainsString('name="' . $name . '"', $bytes);
            }
            return self::success($direct);
        });
        self::call($client, $direct, [
            ['name' => 'API.version', 'value' => 'a'], ['name' => 'apiVersion', 'value' => 'b'],
            ['name' => 'api', 'value' => 'c'], ['name' => 'cpanel.other', 'value' => 'd'],
            ['name' => 'literal-1', 'value' => 'e'],
        ], ['domain' => new NumberedList(['a', 'b']), 'domain-1' => 'c']);
    }

    public function testResourceValuesAreNotImplicitUploadDescriptors(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        try {
            $client = self::client(true, static function (): ResponseInterface {
                self::fail('Raw resources must not dispatch.');
            });
            $error = self::caught(static fn () => self::call(
                $client,
                true,
                [['name' => 'file', 'value' => $resource]],
            ));
            self::assertInstanceOf(InvalidArgumentException::class, $error);
            self::assertSame(0, ftell($resource));
        } finally {
            fclose($resource);
        }
    }

    public function testDirectCpanelRoutingNamesAreReservedButWhmAcceptsThem(): void
    {
        foreach (['cpanel.user', 'cpanel.module', 'cpanel.function'] as $name) {
            $client = self::client(true, static function (): ResponseInterface {
                self::fail('Reserved routing must not dispatch.');
            });
            foreach ([null, 'override', new RepeatedList(['one', 'two'])] as $value) {
                self::assertInstanceOf(
                    InvalidArgumentException::class,
                    self::caught(static fn () => self::call($client, true, [], [$name => $value])),
                );
            }
            self::assertInstanceOf(
                InvalidArgumentException::class,
                self::caught(static fn () => self::call($client, true, [['name' => $name, 'value' => 'override']])),
            );
        }
        $client = self::client(false, static function (RequestInterface $request): ResponseInterface {
            self::assertSame('api.version=1&cpanel.user=query', $request->getUri()->getQuery());
            self::assertStringContainsString('name="cpanel.module"', $request->getBody()->getContents());
            return self::success(false);
        });
        self::call($client, false, [['name' => 'cpanel.module', 'value' => 'text']], ['cpanel.user' => 'query']);
    }

    public function testBridgeAndInvalidRoutesRejectBeforeSourceInspection(): void
    {
        $sends = 0;
        $client = self::client(false, static function () use (&$sends): ResponseInterface {
            ++$sends;
            return new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":null}}}');
        });
        $bridge = $client->uapi('alpha');
        $bridge->call('Example', 'operation');
        $stream = new UploadProbeStream('bytes');
        $parts = [['name' => 'file', 'value' => Upload::fromStream('file', $stream)]];
        self::assertInstanceOf(
            InvalidArgumentException::class,
            self::caught(static fn () => $bridge->callMultipart('Example', 'operation', $parts)),
        );
        self::assertNull($bridge->responseMeta());
        self::assertSame(1, $sends);
        foreach ([false, true] as $direct) {
            $client = self::client($direct, static function (): ResponseInterface {
                self::fail('Invalid routes must not dispatch.');
            });
            self::assertInstanceOf(InvalidArgumentException::class, self::caught(static fn () => $direct
                ? $client->uapi()->callMultipart('bad/module', 'operation', $parts)
                : $client->whm()->callMultipart('bad-operation', $parts)));
        }
        self::assertSame(0, $stream->metadataCalls);
        self::assertSame(0, $stream->reads);
    }

    public function testUnknownMismatchOverflowAndDuplicateBorrowedSourcesRejectBeforeHttp(): void
    {
        foreach (['unknown', 'mismatch', 'overflow', 'duplicate'] as $case) {
            $stream = new UploadProbeStream('bytes');
            $stream->reportedSize = $case === 'unknown' || $case === 'overflow' ? null : 5;
            $upload = Upload::fromStream('file', $stream, size: match ($case) {
                'overflow' => PHP_INT_MAX,
                'mismatch' => 4,
                default => null,
            });
            $parts = [['name' => 'file', 'value' => $upload]];
            if ($case === 'duplicate') {
                $parts[] = ['name' => 'other', 'value' => $upload];
            }
            $client = self::client(true, static function (): ResponseInterface {
                self::fail('Invalid sources must not dispatch.');
            });
            $error = self::caught(static fn () => self::call($client, true, $parts));
            self::assertTrue($error instanceof RuntimeException || $error instanceof InvalidArgumentException);
            self::assertNull($error->getPrevious());
            self::assertSame(0, $stream->reads);
            self::assertSame(0, $stream->tell());
            self::assertSame(0, $stream->closes);
        }
    }

    /** @return iterable<string, array{string, class-string<Throwable>|null, int|null}> */
    public static function outcomes(): iterable
    {
        yield 'success' => ['{"result":{"status":1,"data":{"ok":true}}}', null, 1];
        yield 'API error' => ['{"result":{"status":0,"errors":["no"],"data":{"reason":"file"}}}',
            ApiErrorException::class, 0];
        yield 'malformed' => ['not-json', InvalidResponseException::class, null];
    }

    /** @param class-string<Throwable>|null $exceptionClass */
    #[DataProvider('outcomes')]
    public function testCommonEnvelopeLifecycleAfterStreaming(
        string $json,
        ?string $exceptionClass,
        ?int $status,
    ): void {
        $sends = 0;
        $client = self::client(true, static function (RequestInterface $request) use (
            &$sends,
            $json,
        ): ResponseInterface {
            ++$sends;
            $request->getBody()->getContents();
            return new Response(200, [], $json);
        });
        $error = self::caught(static fn () => self::call($client, true, []));
        if ($exceptionClass === null) {
            self::assertNull($error);
        } else {
            self::assertInstanceOf($exceptionClass, $error);
        }
        self::assertSame($status, $client->uapi()->responseMeta()?->uapiStatus());
        self::assertSame(1, $sends);
    }

    public function testUncheckedRetainsRawFailurePayloadAndHttpStillFails(): void
    {
        $client = self::client(true, static fn (): ResponseInterface => new Response(
            200,
            [],
            '{"result":{"status":0,"data":{"uploads":[{"reason":"exists"}]}}}',
        ));
        $result = self::call($client, true, [], [], OutcomePolicy::Unchecked);
        self::assertSame(['uploads' => [['reason' => 'exists']]], $result->data);
        self::assertSame(0, $client->uapi()->responseMeta()?->uapiStatus());
        $client = self::client(true, static fn (): ResponseInterface => new Response(500, [], '{}'));
        self::assertInstanceOf(
            HttpException::class,
            self::caught(static fn () => self::call($client, true, [], [], OutcomePolicy::Unchecked)),
        );
        self::assertNull($client->uapi()->responseMeta());
    }

    public function testWhmFlagAndUncheckedUseTheExistingOutcomeContract(): void
    {
        $sends = 0;
        $client = self::client(false, static function (RequestInterface $request) use (&$sends): ResponseInterface {
            ++$sends;
            $request->getBody()->getContents();
            return new Response(200, [], '{"metadata":{"result":0,"reason":"no"},"data":{"reason":"file"}}');
        });
        self::assertInstanceOf(ApiErrorException::class, self::caught(static fn () => self::call($client, false, [])));
        self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
        $result = self::call($client, false, [], [], OutcomePolicy::Unchecked);
        self::assertSame(['reason' => 'file'], $result->data);
        self::assertSame(0, $client->whm()->responseMeta()->whmResult());
        self::assertSame(2, $sends);
    }

    public function testPartialConsumptionAndNetworkFailureNeverCloseOrRewindBorrowedSources(): void
    {
        foreach ([false, true] as $fail) {
            $stream = new UploadProbeStream(str_repeat('x', 30000));
            $body = null;
            $sends = 0;
            $client = self::client(true, static function (RequestInterface $request) use (
                &$body,
                &$sends,
                $fail,
            ): ResponseInterface {
                ++$sends;
                $body = $request->getBody();
                $body->read(8192);
                if ($fail) {
                    throw new class ('network') extends RuntimeException implements NetworkExceptionInterface {
                        public function getRequest(): RequestInterface
                        {
                            throw new \LogicException('Request not retained by fixture.');
                        }
                    };
                }
                return self::success(true);
            });
            $error = self::caught(static fn () => self::call(
                $client,
                true,
                [['name' => 'file', 'value' => Upload::fromStream('file', $stream)]],
            ));
            if ($fail) {
                self::assertInstanceOf(TransportException::class, $error);
            } else {
                self::assertNull($error);
            }
            self::assertSame(1, $sends);
            self::assertGreaterThan(0, $stream->tell());
            self::assertLessThan(30000, $stream->tell());
            self::assertSame(0, $stream->seeks);
            self::assertSame(0, $stream->closes);
            self::assertSame(0, $stream->detaches);
            self::assertInstanceOf(StreamingMultipartBody::class, $body);
            self::assertFalse($body->isReadable());
        }
    }

    public function testOwnedPathHandlesAreReleasedAcrossEverySynchronousExit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'multipart-call-');
        self::assertIsString($path);
        file_put_contents($path, str_repeat('x', 30000));
        try {
            foreach (['success', 'partial', 'network', 'api', 'http', 'envelope', 'preparation', 'overflow'] as $mode) {
                $sends = 0;
                $client = self::client(true, static function (RequestInterface $request) use (
                    $path,
                    $mode,
                    &$sends,
                ): ResponseInterface {
                    ++$sends;
                    self::assertSame(1, self::openPathCount($path));
                    if ($mode === 'partial' || $mode === 'network') {
                        $request->getBody()->read(8192);
                    } else {
                        $request->getBody()->getContents();
                    }
                    if ($mode === 'network') {
                        throw new class ('network') extends RuntimeException implements NetworkExceptionInterface {
                            public function getRequest(): RequestInterface
                            {
                                throw new \LogicException('Request not retained by fixture.');
                            }
                        };
                    }
                    return match ($mode) {
                        'http' => new Response(500),
                        'envelope' => new Response(200, [], 'not-json'),
                        'api' => new Response(200, [], '{"result":{"status":0,"data":null}}'),
                        default => self::success(true),
                    };
                });
                $parts = [['name' => 'owned', 'value' => Upload::fromPath($path)]];
                $borrowed = new UploadProbeStream('bytes');
                if ($mode === 'preparation' || $mode === 'overflow') {
                    $borrowed->reportedSize = null;
                    $parts[] = ['name' => 'later', 'value' => Upload::fromStream(
                        'file',
                        $borrowed,
                        size: $mode === 'overflow' ? PHP_INT_MAX : null,
                    )];
                }
                $error = self::caught(static fn () => self::call($client, true, $parts));
                if ($mode === 'success' || $mode === 'partial') {
                    self::assertNull($error);
                } else {
                    self::assertInstanceOf(match ($mode) {
                        'network' => TransportException::class,
                        'http' => HttpException::class,
                        'api' => ApiErrorException::class,
                        'envelope' => InvalidResponseException::class,
                        default => RuntimeException::class,
                    }, $error);
                }
                self::assertSame($mode === 'preparation' || $mode === 'overflow' ? 0 : 1, $sends);
                self::assertSame(0, self::openPathCount($path));
                self::assertSame(0, $borrowed->reads);
                self::assertSame(0, $borrowed->tell());
                self::assertSame(0, $borrowed->closes);
            }
        } finally {
            unlink($path);
        }
    }

    public function testRecordedReadFailureCannotBecomeAcceptedEnvelopeOrRetry(): void
    {
        foreach (['early-eof', 'throw', 'swallowed'] as $mode) {
            $stream = new UploadProbeStream('bytes');
            if ($mode === 'early-eof') {
                $stream->reportedSize = 20;
            } else {
                $stream->throwRead = true;
            }
            $sends = 0;
            $body = null;
            $client = self::client(true, static function (RequestInterface $request) use (
                $mode,
                &$sends,
                &$body,
            ): ResponseInterface {
                ++$sends;
                $body = $request->getBody();
                if ($mode === 'swallowed') {
                    self::assertInstanceOf(
                        RuntimeException::class,
                        self::caught(static fn () => $body->getContents()),
                    );
                } else {
                    $body->getContents();
                }
                return self::success(true);
            });
            $error = self::caught(static fn () => self::call(
                $client,
                true,
                [['name' => 'file', 'value' => Upload::fromStream('file', $stream)]],
            ));
            self::assertInstanceOf(RuntimeException::class, $error);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private-stream-content-secret', $error->getMessage());
            self::assertSame(1, $sends);
            self::assertNull($client->uapi()->responseMeta());
            self::assertSame(0, $stream->closes);
            self::assertSame(0, $stream->detaches);
            self::assertSame(0, $stream->seeks);
            self::assertInstanceOf(StreamingMultipartBody::class, $body);
            self::assertFalse($body->isReadable());
        }
    }

    private static function openPathCount(string $path): int
    {
        $count = 0;
        foreach (get_resources('stream') as $resource) {
            if ((stream_get_meta_data($resource)['uri'] ?? null) === $path) {
                ++$count;
            }
        }
        return $count;
    }

    public function testLocalDynamicNamesAndValuesAreAbsentFromEnabledArgumentLibraryTraces(): void
    {
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $client = self::client(true, static fn (): ResponseInterface => self::success(true));
            foreach (
                [
                [[['name' => 'private-part-name!', 'value' => 'private-body-value']], []],
                [[], ['private-query-name' => new \stdClass()]],
                ] as [$parts, $query]
            ) {
                $error = self::caught(static fn () => self::call($client, true, $parts, $query));
                self::assertInstanceOf(InvalidArgumentException::class, $error);
                self::assertNull($error->getPrevious());
                $diagnostic = $error->getMessage();
                foreach ($error->getTrace() as $frame) {
                    $class = $frame['class'] ?? '';
                    if (
                        str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                        && !str_contains($class, '\\Tests\\')
                    ) {
                        $diagnostic .= print_r($frame['args'] ?? [], true);
                    }
                }
                foreach (['private-part-name', 'private-body-value', 'private-query-name'] as $secret) {
                    self::assertStringNotContainsString($secret, $diagnostic);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $old === false ? '1' : $old);
        }
    }

    private static function success(bool $direct): ResponseInterface
    {
        return new Response(200, [], $direct ? '{"result":{"status":1,"data":{"ok":true}}}'
            : '{"metadata":{"result":1},"data":{"ok":true}}');
    }

    /** @param Closure(RequestInterface): ResponseInterface $send */
    private static function client(bool $direct, Closure $send): Client
    {
        $http = new class ($send) implements ClientInterface {
            /** @param Closure(RequestInterface): ResponseInterface $send */
            public function __construct(private Closure $send)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->send)($request);
            }
        };
        $factory = new Psr17Factory();
        return new Client(
            ['host' => 'api.example.test', 'port' => 2443, 'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'alpha' : 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
    }

    /**
     * @param array<array-key, mixed> $parts
     * @param array<array-key, mixed> $query
     */
    private static function call(
        Client $client,
        bool $direct,
        array $parts,
        array $query = [],
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): CallResult {
        // Reflection intentionally exercises the public runtime boundary with invalid inputs.
        $handle = $direct ? $client->uapi() : $client->whm();
        $result = (new ReflectionMethod($handle, 'callMultipart'))->invokeArgs($handle, $direct
            ? ['Example', 'operation', $parts, $query, $outcome]
            : ['Example/operation', $parts, $query, $outcome]);
        self::assertInstanceOf(CallResult::class, $result);
        return $result;
    }

    /** @param Closure(): mixed $call */
    private static function caught(Closure $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $error) {
            if ($error instanceof AssertionFailedError) {
                throw $error;
            }
            return $error;
        }
        return null;
    }
}
