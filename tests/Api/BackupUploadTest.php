<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\BackupSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
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

final class BackupUploadTest extends TestCase
{
    public function testGeneratedMethodsExist(): void
    {
        $client = self::client(static fn (): ResponseInterface => new Response(200));
        foreach (['restoreDatabases', 'restoreEmailFilters', 'restoreEmailForwarders', 'restoreFiles'] as $method) {
            self::assertTrue(method_exists($client->uapi()->backup(), $method));
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function methods(): iterable
    {
        yield 'databases' => ['restoreDatabases', 'restore_databases', 'file'];
        yield 'filters' => ['restoreEmailFilters', 'restore_email_filters', 'backup'];
        yield 'forwarders' => ['restoreEmailForwarders', 'restore_email_forwarders', 'backup'];
        yield 'files' => ['restoreFiles', 'restore_files', 'backup'];
    }

    #[DataProvider('methods')]
    public function testExactScalarSingletonAndMultipleWireBodies(string $method, string $function, string $part): void
    {
        $requests = [];
        $bodies = [];
        $client = self::client(static function (RequestInterface $request) use (
            &$requests,
            &$bodies,
        ): ResponseInterface {
            $requests[] = $request;
            $bodies[] = $request->getBody()->getContents();
            return new Response(200, [], '{"result":{"status":1,"messages":["done"],"data":'
                . '{"log_id":"id","log_path":"/sensitive/log"}}}');
        });
        $upload = Upload::fromString('same<>;.gz', "\0\xff");
        $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
        foreach ([$upload, [$upload], [$upload, Upload::fromString('same<>;.gz', '')]] as $value) {
            $args = [$value];
            if ($method === 'restoreFiles') {
                $args['directory'] = '../literal +%/';
            }
            $args['timeout'] = 0;
            $args['verbose'] = false;
            self::assertSame(
                ['log_id' => 'id', 'log_path' => '/sensitive/log'],
                $reflection->invokeArgs($client->uapi()->backup(), $args),
            );
        }
        self::assertSame(['status' => 1, 'messages' => ['done']], $client->uapi()->responseMeta()?->uapi);
        self::assertCount(3, $requests);
        foreach ($requests as $i => $request) {
            self::assertSame('POST', $request->getMethod());
            self::assertSame(
                'https://api.example.test:2083/execute/Backup/' . $function . '?'
                    . ($method === 'restoreFiles' ? 'directory=..%2Fliteral%20%2B%25%2F&' : '') . 'timeout=0&verbose=0',
                (string) $request->getUri(),
            );
            self::assertSame('cpanel alpha:fixture-token', $request->getHeaderLine('Authorization'));
            self::assertFalse($request->hasHeader('Transfer-Encoding'));
            self::assertSame((string) strlen($bodies[$i]), $request->getHeaderLine('Content-Length'));
            $boundary = substr($request->getHeaderLine('Content-Type'), strlen('multipart/form-data; boundary='));
            $name = $i === 0 ? $part : $part . '-1';
            $fixture = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name
                . "\"; filename=\"same<>;.gz\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n\0\xff\r\n";
            if ($i === 2) {
                $fixture .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $part
                    . "-2\"; filename=\"same<>;.gz\"\r\n"
                    . "Content-Type: application/octet-stream\r\n\r\n\r\n";
            }
            $fixture .= '--' . $boundary . "--\r\n";
            self::assertSame($fixture, $bodies[$i]);
            self::assertFalse($request->getBody()->isReadable());
        }
    }


    #[DataProvider('methods')]
    public function testRestoreLogsStayTypedWhileMessagesRemainEnvelopeData(
        string $method,
        string $function,
        string $part,
    ): void {
        foreach (['["unmodeled"]', '[1]', 'null'] as $unexpectedMessages) {
            $sends = 0;
            $client = self::client(static function (RequestInterface $request) use (
                &$sends,
                $unexpectedMessages,
            ): ResponseInterface {
                ++$sends;
                $request->getBody()->getContents();
                return new Response(200, [], '{"result":{"status":1,"errors":null,"warnings":null,'
                    . '"messages":["line one\\nline two","<b>restored</b>"],'
                    . '"data":{"log_id":"restore-123","log_path":"/home/fixture/.cpanel/logs/restore-123",'
                    . '"messages":' . $unexpectedMessages . ',"other":{"opaque":[1,null]}}}}');
            });
            $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
            self::assertSame(
                ['log_id' => 'restore-123', 'log_path' => '/home/fixture/.cpanel/logs/restore-123'],
                $reflection->invoke($client->uapi()->backup(), Upload::fromString('archive.gz', 'bytes')),
            );
            self::assertSame([
                'status' => 1, 'errors' => null, 'warnings' => null,
                'messages' => ["line one\nline two", '<b>restored</b>'],
            ], $client->uapi()->responseMeta()?->uapi);
            self::assertSame(1, $sends);
        }
    }

    #[DataProvider('methods')]
    public function testFailedRestoreKeepsFlagErrorsAndEnvelopeWithoutReturningUnvalidatedLogs(
        string $method,
        string $function,
        string $part,
    ): void {
        $sends = 0;
        $client = self::client(static function (RequestInterface $request) use (&$sends): ResponseInterface {
            ++$sends;
            $request->getBody()->getContents();
            return new Response(200, [], '{"result":{"status":0,"errors":["archive rejected"],'
                . '"messages":["log available"],"data":{"log_id":"failed-123","log_path":"/private/log"}}}');
        });
        $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
        $error = self::caught(static fn () => $reflection->invoke(
            $client->uapi()->backup(),
            Upload::fromString('archive.gz', 'bytes'),
        ));
        self::assertSame(\HostingPal\WhmApiClient\Exception\ApiErrorException::class, $error::class);
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame(['archive rejected'], $error->errors());
        self::assertSame($client->uapi()->responseMeta(), $error->metadata);
        self::assertSame([
            'status' => 0, 'errors' => ['archive rejected'], 'messages' => ['log available'],
        ], $error->metadata?->uapi);
        self::assertSame(1, $sends);
    }

    #[DataProvider('methods')]
    public function testOmittedOptionsAndNegativeTimeoutAreNotLocallyReinterpreted(
        string $method,
        string $function,
        string $part,
    ): void {
        $queries = [];
        $client = self::client(static function (RequestInterface $request) use (&$queries): ResponseInterface {
            $queries[] = $request->getUri()->getQuery();
            return new Response(200, [], '{"result":{"status":1,"data":{}}}');
        });
        $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
        self::assertSame([], $reflection->invoke($client->uapi()->backup(), Upload::fromString('empty', '')));
        self::assertSame([], $reflection->invokeArgs($client->uapi()->backup(), [
            Upload::fromString('empty', ''), 'timeout' => -1, 'verbose' => true,
        ]));
        self::assertSame(['', 'timeout=-1&verbose=1'], $queries);
        self::assertSame(1, $client->uapi()->responseMeta()?->uapiStatus());
    }

    #[DataProvider('methods')]
    public function testAllStructuralFailuresPrecedeSourcePreparation(
        string $method,
        string $function,
        string $part,
    ): void {
        $sends = 0;
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return new Response(200, [], '{"result":{"status":1,"data":{}}}');
        });
        foreach (
            ['empty', 'sparse', 'late item', 'mixed mode', 'bad spec', 'both list', 'both scalar',
                'query collision', 'reserved null', 'missing'] as $mode
        ) {
            $client->uapi()->call('Backup', 'fixture');
            $source = new UploadProbeStream('private-bytes');
            $value = [Upload::fromStream('private-name', $source), Upload::fromPath('/missing/private-path')];
            $spec = constant(BackupSpec::class . '::' . strtoupper($function));
            self::assertIsArray($spec);
            self::assertIsArray($spec['multipart_upload_family']);
            self::assertIsArray($spec['params']);
            $php = $part === 'file' ? 'files' : 'backups';
            $args = [$php => $value];
            $type = InvalidArgumentException::class;
            switch ($mode) {
                case 'empty':
                    $args[$php] = [];
                    break;
                case 'sparse':
                    $args[$php] = [1 => $value[0]];
                    break;
                case 'late item':
                    $args[$php][] = '/private/guess';
                    break;
                case 'mixed mode':
                    $args['backup'] = '/private/server';
                    $type = LogicException::class;
                    break;
                case 'bad spec':
                    $spec['multipart_upload_family']['routes'] = 'bridge';
                    $type = LogicException::class;
                    break;
                case 'both list':
                    $spec['multipart_uploads'] = [];
                    $type = LogicException::class;
                    break;
                case 'both scalar':
                    $spec['multipart_upload'] = [];
                    $type = LogicException::class;
                    break;
                case 'query collision':
                case 'reserved null':
                    $spec['params']['omitted'] = [
                        'name' => $mode === 'query collision' ? $part . '-1' : 'api.other', 'encoding' => 'scalar',
                    ];
                    $args['omitted'] = null;
                    $type = LogicException::class;
                    break;
                case 'missing':
                    $args = [];
                    break;
            }
            $run = new ReflectionMethod($client->uapi(), 'run');
            self::assertInstanceOf($type, self::caught(static fn () => $run->invoke($client->uapi(), $spec, $args)));
            self::assertNull($client->uapi()->responseMeta());
            self::assertSame(0, $source->reads);
            self::assertSame(0, $source->metadataCalls);
        }
        self::assertSame(10, $sends);
    }

    #[DataProvider('methods')]
    public function testBridgeRejectsBeforeMetadataOrReads(string $method, string $function, string $part): void
    {
        $sends = 0;
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{}}}}');
        }, true);
        $account = $client->uapi('alpha');
        $account->call('Backup', 'fixture');
        $source = new UploadProbeStream('private-bytes');
        $reflection = new ReflectionMethod($account->backup(), $method);
        self::assertInstanceOf(InvalidArgumentException::class, self::caught(static fn () => $reflection->invoke(
            $account->backup(),
            [Upload::fromStream('private', $source), Upload::fromPath('/missing/private')],
        )));
        self::assertNull($account->responseMeta());
        self::assertSame(1, $sends);
        self::assertSame(0, $source->metadataCalls);
        self::assertSame(0, $source->reads);
    }

    #[DataProvider('methods')]
    public function testStrictFlagPayloadAndFailuresKeepMetadataAndNeverRetry(
        string $method,
        string $function,
        string $part,
    ): void {
        foreach (
            ['{}', '{"log_id":"id"}', '{"log_path":"path"}',
                'null', '[]', '1', '{"log_id":1}', '{"log_id":null}', '{"log_path":null}', '{"log_path":1}',
                'missing', 'api', 'http', 'transport'] as $data
        ) {
            $sends = 0;
            $source = new UploadProbeStream('prefixREST');
            $source->seek(6);
            $request = null;
            $client = self::client(static function (RequestInterface $sent) use (
                &$sends,
                &$request,
                $data,
            ): ResponseInterface {
                ++$sends;
                $request = $sent;
                self::assertStringContainsString("\r\nREST\r\n", $sent->getBody()->getContents());
                if ($data === 'transport') {
                    throw new class ('private-transport') extends RuntimeException implements ClientExceptionInterface {
                    };
                }
                if ($data === 'http') {
                    return new Response(503);
                }
                return new Response(200, [], $data === 'api'
                    ? '{"result":{"status":0,"errors":["fixture"],"data":{}}}'
                    : '{"result":{"status":1' . ($data === 'missing' ? '' : ',"data":' . $data) . '}}');
            });
            $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
            $call = static fn () => $reflection->invoke(
                $client->uapi()->backup(),
                Upload::fromStream('private-name', $source),
            );
            if (
                in_array($data, [
                '{}', '{"log_id":"id"}', '{"log_path":"path"}',
                ], true)
            ) {
                self::assertSame(json_decode($data, true, 512, JSON_THROW_ON_ERROR), $call());
            } else {
                $error = self::caught($call);
                self::assertInstanceOf(match ($data) {
                    'api' => \HostingPal\WhmApiClient\Exception\ApiErrorException::class,
                    'http' => HttpException::class,
                    'transport' => TransportException::class,
                    default => PayloadMismatchException::class,
                }, $error);
                if ($error instanceof PayloadMismatchException) {
                    self::assertTrue($error->outcomeConfirmed);
                    self::assertSame($client->uapi()->responseMeta(), $error->metadata);
                    self::assertSame(
                        $data === 'missing' ? null : json_decode($data, true, 512, JSON_THROW_ON_ERROR),
                        $error->payload,
                    );
                }
            }
            self::assertSame(1, $sends);
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertFalse($request->getBody()->isReadable());
            self::assertSame(1, $source->seeks);
            self::assertSame(0, $source->closes);
            self::assertSame(0, $source->detaches);
        }
    }

    #[DataProvider('methods')]
    public function testUnknownRemainderAndPreparationTraceKeepValuesSensitive(
        string $method,
        string $function,
        string $part,
    ): void {
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $sends = 0;
            $client = self::client(static function () use (&$sends): ResponseInterface {
                ++$sends;
                return new Response(200, [], '{"result":{"status":1,"data":{}}}');
            });
            $client->uapi()->call('Backup', 'fixture');
            $source = new UploadProbeStream('private-bytes');
            $source->seek(6);
            $source->reportedSize = null;
            $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
            $args = [[Upload::fromStream('private-name', $source)], 'timeout' => 7, 'verbose' => true];
            if ($method === 'restoreFiles') {
                $args['directory'] = 'private-directory';
            }
            $error = self::caught(static fn () => $reflection->invokeArgs(
                $client->uapi()->backup(),
                $args,
            ));
            self::assertSame(InvalidArgumentException::class, $error::class);
            self::assertSame('API multipart sources could not be prepared.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertNull($client->uapi()->responseMeta());
            self::assertSame(1, $sends);
            self::assertSame(0, $source->reads);
            self::assertSame(1, $source->seeks);
            self::assertSame(6, $source->tell());
            self::assertSame(0, $source->closes);
            self::assertSame(0, $source->detaches);
            foreach ($reflection->getParameters() as $parameter) {
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
            }
            $seen = false;
            $diagnostic = $error->getMessage();
            foreach ($error->getTrace() as $frame) {
                if ($frame['function'] === $method) {
                    $seen = true;
                    self::assertCount($method === 'restoreFiles' ? 4 : 3, $frame['args'] ?? []);
                    foreach ($frame['args'] ?? [] as $argument) {
                        self::assertInstanceOf(SensitiveParameterValue::class, $argument);
                    }
                }
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, 'HostingPal\\WhmApiClient\\') && !str_contains($class, '\\Tests\\')) {
                    $diagnostic .= print_r($frame['args'] ?? [], true);
                }
            }
            self::assertTrue($seen);
            self::assertStringNotContainsString('private-', $diagnostic);
        } finally {
            ini_set('zend.exception_ignore_args', $old === false ? '1' : $old);
        }
    }

    #[DataProvider('methods')]
    public function testNativeTypeErrorKeepsSnapshot(string $method, string $function, string $part): void
    {
        $client = self::client(static fn (): ResponseInterface => new Response(
            200,
            [],
            '{"result":{"status":1,"data":{}}}',
        ));
        $client->uapi()->call('Backup', 'fixture');
        $snapshot = $client->uapi()->responseMeta();
        $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
        self::assertFalse($reflection->getParameters()[0]->isOptional());
        foreach (['/private/path', null, 1] as $bad) {
            self::assertInstanceOf(\TypeError::class, self::caught(static fn () => $reflection->invoke(
                $client->uapi()->backup(),
                $bad,
            )));
            self::assertSame($snapshot, $client->uapi()->responseMeta());
        }
    }

    #[DataProvider('methods')]
    public function testOwnedAndBorrowedSourcesAreReleasedAfterPayloadHttpAndTransportFailures(
        string $method,
        string $function,
        string $part,
    ): void {
        $path = tempnam(sys_get_temp_dir(), 'backup-owned-');
        self::assertIsString($path);
        file_put_contents($path, 'owned-bytes');
        try {
            foreach (['success', 'payload', 'api', 'http', 'transport', 'early transport', 'stream'] as $mode) {
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
                    if ($mode !== 'early transport') {
                        $sent->getBody()->getContents();
                    }
                    if (str_contains($mode, 'transport')) {
                        throw new class (
                            'private-client-message',
                        ) extends RuntimeException implements ClientExceptionInterface {
                        };
                    }
                    return $mode === 'http' ? new Response(503) : new Response(
                        200,
                        [],
                        $mode === 'success' ? '{"result":{"status":1,"data":{}}}'
                        : ($mode === 'api' ? '{"result":{"status":0}}' : '{"result":{"status":1}}'),
                    );
                });
                $reflection = new ReflectionMethod($client->uapi()->backup(), $method);
                $call = static fn () => $reflection->invoke($client->uapi()->backup(), [
                    Upload::fromPath($path), Upload::fromStream('borrowed', $source),
                ]);
                if ($mode === 'success') {
                    self::assertSame([], $call());
                } else {
                    $error = self::caught($call);
                    self::assertInstanceOf(match ($mode) {
                        'http' => HttpException::class,
                        'transport', 'early transport' => TransportException::class,
                        'api' => \HostingPal\WhmApiClient\Exception\ApiErrorException::class,
                        'stream' => ApiException::class,
                        default => PayloadMismatchException::class,
                    }, $error);
                }
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
