<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Engine\MultipartInput;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tests\Support\UploadProbeStream;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

final class FilemanUploadTest extends TestCase
{
    public function testExactRequestAndDeterministicIndependentBinaryFixture(): void
    {
        $seen = null;
        $bytes = '';
        $client = self::client(static function (RequestInterface $request) use (&$seen, &$bytes): ResponseInterface {
            $seen = $request;
            $bytes = $request->getBody()->getContents();
            return self::response(['succeeded' => 2]);
        });
        $files = [Upload::fromString('ž.bin', "\0\xff", 'image/png'), Upload::fromString('empty', '')];
        self::assertSame(['succeeded' => 2], $client->uapi()->fileman()->uploadFiles(
            $files,
            dir: 'public_html/a +%',
            getDiskInfo: false,
            overwrite: false,
            permissions: '0644',
        ));
        self::assertInstanceOf(RequestInterface::class, $seen);
        self::assertSame('POST', $seen->getMethod());
        self::assertSame('https://api.example.test:2083/execute/Fileman/upload_files'
            . '?dir=public_html%2Fa%20%2B%25&get_disk_info=0&overwrite=0&permissions=0644', (string) $seen->getUri());
        self::assertSame('cpanel alpha:fixture-token', $seen->getHeaderLine('Authorization'));
        self::assertSame((string) strlen($bytes), $seen->getHeaderLine('Content-Length'));
        self::assertFalse($seen->hasHeader('Transfer-Encoding'));
        $boundary = substr($seen->getHeaderLine('Content-Type'), strlen('multipart/form-data; boundary='));
        self::assertSame(self::fixture($boundary), $bytes);
        self::assertFalse($seen->getBody()->isReadable());

        // Exercise the emitted descriptor separately with the internal deterministic seam.
        $body = null;
        self::caught(static function () use ($files, &$body): void {
            OperationRunner::run(FilemanSpec::UPLOAD_FILES, ['files' => $files], static function (
                array $spec,
                mixed $query,
                mixed $input = null,
            ) use (&$body): never {
                $body = $input;
                throw new InvalidArgumentException('captured');
            });
        });
        self::assertInstanceOf(MultipartInput::class, $body);
        $encoded = StreamingMultipartEncoder::encode($body->parts, static fn (): string => 'independent-fixture');
        self::assertSame(self::fixture('independent-fixture'), $encoded->getContents());
        $encoded->close();
    }

    private static function fixture(string $boundary): string
    {
        return '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file-0\"; filename=\"ž.bin\"\r\n"
            . "Content-Type: image/png\r\n\r\n\0\xff\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file-1\"; filename=\"empty\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n\r\n--" . $boundary . "--\r\n";
    }

    public function testSingletonDuplicatesAndBorrowedSourcesPreservePositionsAndCursor(): void
    {
        $requests = [];
        $bodies = [];
        $client = self::client(static function (RequestInterface $request) use (
            &$requests,
            &$bodies,
        ): ResponseInterface {
            $requests[] = $request;
            $bodies[] = $request->getBody()->getContents();
            return self::response([]);
        });
        $stream = new UploadProbeStream('prefixREST');
        $stream->seek(6);
        $pipe = new UploadProbeStream('PIPE');
        $pipe->seekable = false;
        $pipe->reportedSize = null;
        $client->uapi()->fileman()->uploadFiles([Upload::fromStream('same', $stream)], permissions: '644');
        $client->uapi()->fileman()->uploadFiles([
            Upload::fromString('same', ''), Upload::fromStream('same', $pipe, size: 4),
        ]);
        self::assertCount(2, $requests);
        self::assertSame('permissions=644', $requests[0]->getUri()->getQuery());
        self::assertSame('', $requests[1]->getUri()->getQuery());
        self::assertStringContainsString('name="file-0"; filename="same"', $bodies[0]);
        self::assertStringNotContainsString('name="file-1"', $bodies[0]);
        self::assertStringContainsString("\r\nREST\r\n", $bodies[0]);
        self::assertStringContainsString('name="file-1"; filename="same"', $bodies[1]);
        self::assertStringContainsString("\r\nPIPE\r\n", $bodies[1]);
        foreach ([$stream, $pipe] as $source) {
            self::assertSame(0, $source->closes);
            self::assertSame(0, $source->detaches);
        }
        self::assertSame(1, $stream->seeks);
        self::assertSame(0, $pipe->seeks);
    }

    /** @return iterable<string, array{array<mixed>, ?UploadProbeStream}> */
    public static function badFiles(): iterable
    {
        yield 'empty' => [[], null];
        yield 'sparse' => [[1 => Upload::fromString('valid', '')], null];
        $source = new UploadProbeStream('private-source-bytes');
        yield 'late string' => [[Upload::fromStream('probe', $source),
            Upload::fromPath('/missing/private-source'), '/private/path'], $source];
        foreach (['<', '>', ';'] as $character) {
            $source = new UploadProbeStream('private-source-bytes');
            yield 'filename ' . $character => [
            [Upload::fromStream('probe', $source),
                Upload::fromPath('/missing/private-source'), Upload::fromString('private' . $character . 'name', '')],
                $source];
        }
    }

    /** @param array<mixed> $files */
    #[DataProvider('badFiles')]
    public function testInvalidListClearsMetadataWithoutOpeningEarlierSource(
        array $files,
        ?UploadProbeStream $source,
    ): void {
        $sends = 0;
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return self::response([]);
        });
        $account = $client->uapi();
        $account->call('Fileman', 'fixture');
        self::assertNotNull($account->responseMeta());
        $method = new ReflectionMethod($account->fileman(), 'uploadFiles');
        $error = self::caught(static fn () => $method->invoke($account->fileman(), $files));
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertStringNotContainsString('private', $error->getMessage());
        self::assertNull($account->responseMeta());
        self::assertSame(1, $sends);
        if ($source !== null) {
            self::assertSame(0, $source->metadataCalls);
            self::assertSame(0, $source->reads);
        }
    }

    public function testBridgeRejectsBeforeSourceMetadataAndClearsSnapshot(): void
    {
        $sends = 0;
        $source = new UploadProbeStream('private bytes');
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{}}}}');
        }, true);
        $account = $client->uapi('alpha');
        $account->call('Fileman', 'fixture');
        $error = self::caught(static fn () => $account->fileman()->uploadFiles([
            Upload::fromStream('private-name', $source), Upload::fromPath('/missing/private-path'),
        ]));
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertNull($account->responseMeta());
        self::assertSame(1, $sends);
        self::assertSame(0, $source->reads);
        self::assertSame(0, $source->metadataCalls);
    }

    /** @return iterable<string, array{int, array<string, mixed>}> */
    public static function outcomes(): iterable
    {
        yield 'all success' => [1, ['succeeded' => 1, 'failed' => 0, 'warned' => 0,
            'uploads' => [['file' => 'one', 'size' => 2, 'status' => 1, 'reason' => 'stored', 'warnings' => []]]]];
        yield 'partial success' => [1, ['succeeded' => 1, 'failed' => 1, 'warned' => 1, 'uploads' => [
            ['status' => 1, 'warnings' => ['permissions']], ['status' => 0, 'size' => null, 'reason' => 'exists'],
        ]]];
        yield 'all failed' => [0, ['succeeded' => 0, 'failed' => 1, 'uploads' => [
            ['status' => 0, 'size' => null, 'reason' => 'exists', 'warnings' => ['warning']],
        ]]];
        yield 'empty object' => [1, []];
        yield 'diskinfo' => [1, ['diskinfo' => ['file_upload_max_bytes' => '104857600.00',
            'file_upload_must_leave_bytes' => '5242880.00', 'file_upload_remain' => '0.00',
            'fileslimit' => '∞', 'filesremain' => 0, 'filesused' => '1024', 'spacelimit' => '∞',
            'spaceremain' => '2359296.00', 'spaceused' => '2359296.00', 'spaceused_humansize' => '2.5 MB',
            '1' => 'numeric key']]];
        yield 'empty diskinfo and rows' => [0, ['diskinfo' => [], 'uploads' => [[]]]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('outcomes')]
    public function testUncheckedReturnsStrictStructuredResultsForBothFlags(int $status, array $data): void
    {
        $sends = 0;
        $client = self::client(static function (RequestInterface $request) use (
            &$sends,
            $data,
            $status,
        ): ResponseInterface {
            ++$sends;
            $request->getBody()->getContents();
            return self::response($data, $status);
        });
        $actual = $client->uapi()->fileman()->uploadFiles([Upload::fromString('one', '')]);
        // Declared object fields are returned in schema order, while scalar types stay exact.
        self::assertSame(self::canonical($data), self::canonical($actual));
        self::assertSame($status, $client->uapi()->responseMeta()?->uapiStatus());
        self::assertSame(1, $sends);
    }

    /** @return iterable<string, array{string, class-string<Throwable>}> */
    public static function invalidResponses(): iterable
    {
        foreach (
            ['{}', '{"status":null,"data":{}}', '{"status":"1","data":{}}',
            '{"status":true,"data":{}}', '{"status":2,"data":{}}'] as $i => $result
        ) {
            yield 'status ' . $i => [$result, InvalidResponseException::class];
        }
        foreach (
            ['{"status":0}', '{"status":1,"data":null}', '{"status":0,"data":[]}',
            '{"status":1,"data":"bad"}', '{"status":0,"data":{"failed":"1"}}',
            '{"status":1,"data":{"uploads":[{"status":"1"}]}}',
            '{"status":1,"data":{"uploads":[{"size":1.5}]}}',
            '{"status":1,"data":{"uploads":[{"warnings":[1]}]}}',
            '{"status":1,"data":{"diskinfo":{"filesused":null}}}',
            '{"status":1,"data":{"diskinfo":{"spaceused":1}}}',
            '{"status":1,"data":{"diskinfo":{"extra":1}}}',
            '{"status":1,"data":{"diskinfo":{"1":1}}}'] as $i => $result
        ) {
            yield 'payload ' . $i => [$result, PayloadMismatchException::class];
        }
    }

    /** @param class-string<Throwable> $type */
    #[DataProvider('invalidResponses')]
    public function testInvalidOutcomeOrPayloadRetainsSnapshotWithoutRetry(string $result, string $type): void
    {
        $sends = 0;
        $source = new UploadProbeStream('bytes');
        $request = null;
        $client = self::client(static function (RequestInterface $sent) use (
            &$sends,
            &$request,
            $result,
        ): ResponseInterface {
            ++$sends;
            $request = $sent;
            $sent->getBody()->getContents();
            return new Response(200, [], '{"result":' . $result . '}');
        });
        $error = self::caught(static fn () => $client->uapi()->fileman()->uploadFiles([
            Upload::fromStream('private-name', $source),
        ]));
        self::assertInstanceOf($type, $error);
        self::assertNotNull($client->uapi()->responseMeta());
        if ($error instanceof PayloadMismatchException) {
            self::assertFalse($error->outcomeConfirmed);
            self::assertSame($client->uapi()->responseMeta(), $error->metadata);
            $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame($decoded['data'] ?? null, $error->payload);
        }
        self::assertSame(1, $sends);
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertFalse($request->getBody()->isReadable());
        self::assertSame(0, $source->closes);
        self::assertSame(0, $source->detaches);
    }

    public function testRequiredListRejectsNonArrayAndMissingArgumentWithoutIo(): void
    {
        $sends = 0;
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return self::response([]);
        });
        $run = new ReflectionMethod($client->uapi(), 'run');
        foreach ([[], ['files' => null], ['files' => Upload::fromString('one', '')]] as $args) {
            $error = self::caught(static fn () => $run->invoke($client->uapi(), FilemanSpec::UPLOAD_FILES, $args));
            self::assertInstanceOf(InvalidArgumentException::class, $error);
        }
        self::assertSame(0, $sends);
    }

    public function testMalformedUploadSpecsAndOmittedQueryReservationsFailBeforeIo(): void
    {
        foreach (['start', 'routes', 'prefix', 'php', 'extra', 'body', 'method', 'module', 'params'] as $change) {
            $spec = FilemanSpec::UPLOAD_FILES;
            if ($change === 'module') {
                unset($spec['module']);
            } elseif ($change === 'params') {
                $spec['params']['files'] = ['name' => 'ordinary', 'encoding' => 'scalar'];
            } elseif (in_array($change, ['body', 'method'], true)) {
                $spec[$change] = 'wrong';
            } else {
                $spec['multipart_uploads'][$change] = 'wrong!';
            }
            self::assertInstanceOf(LogicException::class, self::caught(static fn () => SpecChecker::check($spec)));
        }
        foreach (['file-0', 'file-100', 'api.version', 'api.custom', 'cpanel.user'] as $wire) {
            $spec = FilemanSpec::UPLOAD_FILES;
            $spec['params']['omitted'] = ['name' => $wire, 'encoding' => 'scalar'];
            self::assertInstanceOf(LogicException::class, self::caught(static fn () => SpecChecker::check($spec)));
        }
    }

    public function testUnknownMismatchAndOverflowLengthsFailWithoutReadingOrSending(): void
    {
        $sends = 0;
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return self::response([]);
        });
        foreach (['unknown', 'assertion', 'overflow'] as $mode) {
            $source = new UploadProbeStream('private-bytes');
            $source->reportedSize = $mode === 'assertion' ? 13 : null;
            $files = [Upload::fromStream(
                'private-name',
                $source,
                size: $mode === 'assertion' ? 12 : ($mode === 'overflow' ? PHP_INT_MAX : null),
            )];
            self::assertInstanceOf(
                RuntimeException::class,
                self::caught(static fn () => $client->uapi()->fileman()->uploadFiles($files)),
            );
            self::assertSame(0, $source->reads);
            self::assertSame(0, $source->closes);
            self::assertSame(0, $source->detaches);
            self::assertNull($client->uapi()->responseMeta());
        }
        self::assertSame(0, $sends);
    }

    public function testOwnedAndBorrowedSourcesAreReleasedAfterPayloadHttpAndTransportFailures(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fileman-owned-');
        self::assertIsString($path);
        file_put_contents($path, 'owned-bytes');
        try {
            foreach (['payload', 'http', 'transport', 'stream'] as $mode) {
                $sends = 0;
                $openDuringSend = 0;
                $request = null;
                $source = new UploadProbeStream('borrowed');
                $source->throwRead = $mode === 'stream';
                $client = self::client(static function (RequestInterface $sent) use (
                    $mode,
                    $path,
                    &$sends,
                    &$openDuringSend,
                    &$request,
                ): ResponseInterface {
                    ++$sends;
                    $request = $sent;
                    $openDuringSend = self::openPathCount($path);
                    $sent->getBody()->getContents();
                    if ($mode === 'transport') {
                        throw new class (
                            'private-client-message',
                        ) extends RuntimeException implements ClientExceptionInterface {
                        };
                    }
                    return $mode === 'http' ? new Response(503) : new Response(200, [], '{"result":{"status":0}}');
                });
                $error = self::caught(static fn () => $client->uapi()->fileman()->uploadFiles([
                    Upload::fromPath($path), Upload::fromStream('borrowed', $source),
                ]));
                self::assertInstanceOf(match ($mode) {
                    'http' => HttpException::class,
                    'transport' => TransportException::class,
                    'stream' => ApiException::class,
                    default => PayloadMismatchException::class,
                }, $error);
                self::assertSame(1, $sends);
                self::assertSame(1, $openDuringSend);
                self::assertSame(0, self::openPathCount($path));
                self::assertInstanceOf(RequestInterface::class, $request);
                self::assertFalse($request->getBody()->isReadable());
                self::assertSame(0, $source->closes);
                self::assertSame(0, $source->detaches);
                self::assertSame(0, $source->seeks);
            }
        } finally {
            unlink($path);
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

    public function testEnabledArgumentLibraryTracesHideFilesPathsNamesAndBytes(): void
    {
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $client = self::client(static fn (): ResponseInterface => self::response([]));
            $files = [Upload::fromPath('/private-path/file'), Upload::fromString('private-name;', 'private-bytes')];
            $error = self::caught(static fn () => $client->uapi()->fileman()->uploadFiles(
                $files,
                dir: 'private-dir-marker',
                getDiskInfo: true,
                overwrite: false,
                permissions: 'private-permissions-marker',
            ));
            self::assertInstanceOf(InvalidArgumentException::class, $error);
            $method = new ReflectionMethod($client->uapi()->fileman(), 'uploadFiles');
            foreach ($method->getParameters() as $parameter) {
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
            }
            $publicFrame = false;
            foreach ($error->getTrace() as $frame) {
                if ($frame['function'] === 'uploadFiles') {
                    $publicFrame = true;
                    self::assertCount(5, $frame['args'] ?? []);
                    foreach ($frame['args'] ?? [] as $argument) {
                        self::assertInstanceOf(SensitiveParameterValue::class, $argument);
                    }
                }
            }
            self::assertTrue($publicFrame);
            self::assertNull($error->getPrevious());
            $diagnostic = $error->getMessage();
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, 'HostingPal\\WhmApiClient\\') && !str_contains($class, '\\Tests\\')) {
                    $diagnostic .= print_r($frame['args'] ?? [], true);
                }
            }
            foreach (
                ['private-path', 'private-name', 'private-bytes',
                'private-dir-marker', 'private-permissions-marker'] as $secret
            ) {
                self::assertStringNotContainsString($secret, $diagnostic);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $old === false ? '1' : $old);
        }
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function response(array $data, int $status = 1): ResponseInterface
    {
        // Empty associative response objects must remain JSON objects, not lists.
        if (isset($data['diskinfo']) && $data['diskinfo'] === []) {
            $data['diskinfo'] = (object) [];
        }
        if (isset($data['uploads']) && $data['uploads'] === [[]]) {
            $data['uploads'] = [(object) []];
        }
        return new Response(200, [], json_encode(
            ['result' => ['status' => $status, 'data' => (object) $data]],
            JSON_THROW_ON_ERROR,
        ));
    }

    private static function caught(Closure $action): Throwable
    {
        try {
            $action();
        } catch (AssertionFailedError $error) {
            throw $error;
        } catch (Throwable $error) {
            return $error;
        }
        self::fail('Expected rejection.');
    }

    /** @param Closure(RequestInterface): ResponseInterface $send */
    private static function client(Closure $send, bool $bridge = false): Client
    {
        $factory = new Psr17Factory();
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
        return new Client(
            ['host' => 'api.example.test', 'authType' => $bridge ? 'whm' : 'cpanel',
            'username' => $bridge ? 'root' : 'alpha', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
    }
}
