<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Support\UploadProbeStream;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
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
use TypeError;

final class WhmConfigUploadTest extends TestCase
{
    public function testExactLiteralTextBinaryAndEmptyPartsUseOneWhmAttempt(): void
    {
        $requests = [];
        $bodies = [];
        $client = self::client(static function (RequestInterface $request) use (
            &$requests,
            &$bodies,
        ): ResponseInterface {
            $requests[] = $request;
            $bodies[] = $request->getBody()->getContents();
            return self::response();
        });
        foreach (['Basic' => "\0\xff", 'Main' => '', 'main +%' => 'literal'] as $module => $bytes) {
            $method = new ReflectionMethod($client->whm()->server(), 'restoreConfigFromUpload');
            self::assertNull($method->invoke(
                $client->whm()->server(),
                $module,
                Upload::fromString('config<>;.bin', $bytes),
            ));
        }
        self::assertCount(3, $requests);
        foreach ($requests as $index => $request) {
            self::assertSame('POST', $request->getMethod());
            self::assertSame(
                'https://api.example.test:2087/json-api/restore_config_from_upload?api.version=1',
                (string) $request->getUri(),
            );
            self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
            self::assertFalse($request->hasHeader('Transfer-Encoding'));
            self::assertSame((string) strlen($bodies[$index]), $request->getHeaderLine('Content-Length'));
            $boundary = substr($request->getHeaderLine('Content-Type'), strlen('multipart/form-data; boundary='));
            $module = ['Basic', 'Main', 'main +%'][$index];
            $bytes = ["\0\xff", '', 'literal'][$index];
            $fixture = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"module\"\r\n\r\n"
                . $module . "\r\n--" . $boundary
                . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"config<>;.bin\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n";
            self::assertSame($fixture, $bodies[$index]);
            self::assertFalse($request->getBody()->isReadable());
        }
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
    }

    public function testBorrowedCursorAndOwnedSourcesCloseOnlyOwnedHandles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'whm-config-owned-');
        self::assertIsString($path);
        file_put_contents($path, 'owned-bytes');
        try {
            foreach (
                [
                ['success', true], ['api', true], ['transport', true], ['early transport', true],
                ['success', false], ['api', false], ['transport', false], ['early transport', false],
                ['stream', false]] as [$mode, $owned]
            ) {
                $sends = 0;
                $source = new UploadProbeStream('prefixREST');
                $source->seek(6);
                $source->throwRead = $mode === 'stream';
                $body = '';
                $client = self::client(static function (RequestInterface $request) use (
                    &$sends,
                    &$body,
                    $mode,
                    $path,
                    $owned,
                ): ResponseInterface {
                    ++$sends;
                    if ($owned) {
                        self::assertSame(1, self::openPathCount($path));
                    }
                    if ($mode !== 'early transport') {
                        $body = $request->getBody()->getContents();
                    }
                    if (str_contains($mode, 'transport')) {
                        throw new class (
                            'private-transport',
                        ) extends RuntimeException implements ClientExceptionInterface {
                        };
                    }
                    return self::response($mode === 'api' ? 0 : 1);
                });
                $upload = $owned ? Upload::fromPath($path) : Upload::fromStream('borrowed', $source);
                if ($mode === 'success') {
                    $client->whm()->server()->restoreConfigFromUpload('Main', $upload);
                    self::assertStringContainsString($owned ? "\r\nowned-bytes\r\n" : "\r\nREST\r\n", $body);
                } else {
                    $error = self::caught(static fn () => $client->whm()->server()->restoreConfigFromUpload(
                        'Main',
                        $upload,
                    ));
                    self::assertInstanceOf(
                        str_contains($mode, 'transport') ? TransportException::class : ApiException::class,
                        $error,
                    );
                    if ($mode === 'api') {
                        self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
                        self::assertStringContainsString($owned ? "\r\nowned-bytes\r\n" : "\r\nREST\r\n", $body);
                    }
                }
                self::assertSame(1, $sends);
                self::assertSame(0, self::openPathCount($path));
                self::assertSame(0, $source->closes);
                self::assertSame(0, $source->detaches);
                self::assertSame(1, $source->seeks);
                if ($mode === 'early transport') {
                    self::assertSame(0, $source->reads);
                }
            }
        } finally {
            unlink($path);
        }
    }

    public function testStructuralFailuresClearMetadataBeforeSourcePreparation(): void
    {
        $sends = 0;
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return self::response();
        });
        $whm = $client->whm();
        $run = new ReflectionMethod($whm, 'run');
        $modes = ['missing text', 'wrong text', 'wrong file', 'extra argument', 'collision', 'both descriptors'];
        foreach ($modes as $mode) {
            $whm->call('fixture');
            self::assertNotNull($whm->responseMeta());
            $source = new UploadProbeStream('private-bytes');
            $args = ['module' => 'private-module', 'file' => Upload::fromStream('private-file', $source)];
            $spec = ServerSpec::RESTORE_CONFIG_FROM_UPLOAD;
            switch ($mode) {
                case 'missing text':
                    unset($args['module']);
                    break;
                case 'wrong text':
                    $args['module'] = 1;
                    break;
                case 'wrong file':
                    $args['file'] = '/private-path';
                    break;
                case 'extra argument':
                    $args['extra'] = 'private-extra';
                    break;
                case 'collision':
                    $spec['multipart_upload']['text'] = 'file';
                    break;
                case 'both descriptors':
                    $spec['multipart_uploads'] = [];
                    break;
            }
            $error = self::caught(static fn () => $run->invoke($whm, $spec, $args));
            self::assertInstanceOf(in_array($mode, ['collision', 'both descriptors', 'extra argument'], true)
                ? LogicException::class : InvalidArgumentException::class, $error);
            self::assertNull($whm->responseMeta());
            self::assertSame(0, $source->metadataCalls);
            self::assertSame(0, $source->reads);
        }
        self::assertSame(6, $sends);
    }

    public function testNativeUploadTypeRejectionPrecedesLifecycle(): void
    {
        $sends = 0;
        $client = self::client(static function () use (&$sends): ResponseInterface {
            ++$sends;
            return self::response();
        });
        $whm = $client->whm();
        $whm->call('fixture');
        $snapshot = $whm->responseMeta();
        $method = new ReflectionMethod($whm->server(), 'restoreConfigFromUpload');
        self::assertSame('void', (string) $method->getReturnType());
        $parameters = $method->getParameters();
        self::assertSame('string', (string) $parameters[0]->getType());
        self::assertSame(Upload::class, (string) $parameters[1]->getType());
        foreach ($parameters as $parameter) {
            self::assertFalse($parameter->isOptional());
            self::assertFalse($parameter->allowsNull());
        }
        foreach (['/private-path', [], [Upload::fromString('one', '')]] as $invalid) {
            $error = self::caught(static fn () => $method->invoke($whm->server(), 'Main', $invalid));
            self::assertInstanceOf(TypeError::class, $error);
            self::assertSame($snapshot, $whm->responseMeta());
        }
        self::assertSame(1, $sends);
    }

    public function testEnabledArgumentTraceHidesBothValuesAfterProductionCatch(): void
    {
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $sends = 0;
            $client = self::client(static function () use (&$sends): ResponseInterface {
                ++$sends;
                return self::response();
            });
            $client->whm()->call('fixture');
            $method = new ReflectionMethod($client->whm()->server(), 'restoreConfigFromUpload');
            $error = self::caught(static fn () => $method->invoke(
                $client->whm()->server(),
                'private-module',
                Upload::fromPath('/missing/private-path'),
            ));
            // Inherited PR86 preparation mapping is RuntimeException until the external fix integrates.
            self::assertInstanceOf(RuntimeException::class, $error);
            self::assertNull($error->getPrevious());
            self::assertNull($client->whm()->responseMeta());
            self::assertSame(1, $sends);
            $method = new ReflectionMethod($client->whm()->server(), 'restoreConfigFromUpload');
            foreach ($method->getParameters() as $parameter) {
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
            }
            $seen = false;
            $diagnostic = $error->getMessage();
            foreach ($error->getTrace() as $frame) {
                if ($frame['function'] === 'restoreConfigFromUpload') {
                    $seen = true;
                    self::assertCount(2, $frame['args'] ?? []);
                    foreach ($frame['args'] ?? [] as $argument) {
                        self::assertInstanceOf(SensitiveParameterValue::class, $argument);
                    }
                }
                if (
                    str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')
                    && !str_contains($frame['class'], '\\Tests\\')
                ) {
                    $diagnostic .= print_r($frame['args'] ?? [], true);
                }
            }
            self::assertTrue($seen);
            self::assertStringNotContainsString('private-', $diagnostic);
        } finally {
            ini_set('zend.exception_ignore_args', $old === false ? '1' : $old);
        }
    }

    public function testMalformedDescriptorsRejectWithoutDispatch(): void
    {
        foreach (['php', 'source', 'text', 'extra', 'method', 'body', 'module', 'params'] as $key) {
            $spec = ServerSpec::RESTORE_CONFIG_FROM_UPLOAD;
            if (in_array($key, ['method', 'body', 'module', 'params'], true)) {
                $spec[$key] = 'invalid';
            } else {
                $spec['multipart_upload'][$key] = 'invalid!';
            }
            self::assertInstanceOf(LogicException::class, self::caught(static fn () => SpecChecker::check($spec)));
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

    private static function response(int $result = 1): ResponseInterface
    {
        return new Response(200, [], '{"metadata":{"result":' . $result . ',"reason":"fixture","version":1}}');
    }

    /** @param Closure(RequestInterface): ResponseInterface $send */
    private static function client(Closure $send): Client
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
            ['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
    }
}
