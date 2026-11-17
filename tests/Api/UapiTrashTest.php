<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Trash;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Message\RequestMatcher;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Independent synthetic fixtures; no trash target, restore map or returned path is accessed. */
final class UapiTrashTest extends TestCase
{
    public function testCachedAccountAccessorAndExactNativeSignaturesWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('trash'), 'Trash accessor must exist.');
        self::assertSame($account->trash(), $account->trash());
        self::assertNotSame($account->trash(), $client->uapi('other')->trash());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        $list = new ReflectionMethod(Trash::class, 'getUsage');
        self::assertSame('array', (string) $list->getReturnType());
        self::assertCount(0, $list->getParameters());
        $remove = new ReflectionMethod(Trash::class, 'removeItem');
        self::assertSame('array', (string) $remove->getReturnType());
        self::assertCount(1, $remove->getParameters());
        $path = $remove->getParameters()[0];
        self::assertSame('path', $path->getName());
        self::assertSame('string', (string) $path->getType());
        self::assertFalse($path->isOptional());
        self::assertFalse($path->isDefaultValueAvailable());
        self::assertCount(1, $path->getAttributes(SensitiveParameter::class));
    }

    #[DataProvider('requests')]
    public function testLiteralRequestsPreserveRemotePathBytesOnce(string $route, ?string $path, string $body): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('trash'), 'Trash accessor must exist.');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $actual = $path === null ? $account->trash()->getUsage() : $account->trash()->removeItem(path: $path);
        self::assertSame([], $actual);
        self::assertCount(1, $http->getRequests());
        self::assertRequest($http, $route, $path === null ? 'usage' : 'remove', $body);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function requests(): iterable
    {
        $cases = [
            'usage' => [null, ''],
            'documented home-relative path' => ['/.trash/backup-7.14.2026_16-35-25',
                'path=%2F.trash%2Fbackup-7.14.2026_16-35-25'],
            'leading slash' => ['/', 'path=%2F'],
            'repeated slash' => ['//.trash///item', 'path=%2F%2F.trash%2F%2F%2Fitem'],
            'dot and traversal' => ['/.trash/./a/../item', 'path=%2F.trash%2F.%2Fa%2F..%2Fitem'],
            'outside traversal remains server input' => ['/.trash/../../outside', 'path=%2F.trash%2F..%2F..%2Foutside'],
            'backslash' => ['.trash\\item', 'path=.trash%5Citem'],
            'literal percent escapes' => ['/.trash/%2f%2E%2e', 'path=%2F.trash%2F%252f%252E%252e'],
            'unicode plus space' => ['/.trash/š+ item', 'path=%2F.trash%2F%C5%A1%2B%20item'],
            'empty supplied' => ['', 'path='],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => $case) {
                yield "$route $name" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('payloads')]
    public function testSourcePayloadPresenceShapesAndPrivateValues(
        string $route,
        string $method,
        ?string $json,
        bool $valid,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $json)));
        if (!$valid) {
            $this->expectException(PayloadMismatchException::class);
        }
        try {
            $actual = self::invoke($account->trash(), $method);
            self::assertSame($expected, $actual);
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
    }

    /** @return iterable<string, array{string, string, ?string, bool, mixed}> */
    public static function payloads(): iterable
    {
        $cases = [];
        // Every optional integer subset; existence is formally int, not an invented 0|1 enum.
        foreach (
            [
            ['{}', []],
            ['{"bytes":0}', ['bytes' => 0]],
            ['{"exists":0}', ['exists' => 0]],
            ['{"items":0}', ['items' => 0]],
            ['{"bytes":0,"exists":0}', ['bytes' => 0, 'exists' => 0]],
            ['{"bytes":0,"items":0}', ['bytes' => 0, 'items' => 0]],
            ['{"exists":0,"items":0}', ['exists' => 0, 'items' => 0]],
            ['{"bytes":0,"exists":0,"items":0}', ['bytes' => 0, 'exists' => 0, 'items' => 0]],
            ['{"bytes":9223372036854775807,"exists":7,"items":-1}',
                ['bytes' => PHP_INT_MAX, 'exists' => 7, 'items' => -1]],
            ['[]', []],
            ['{"bytes":4096,"exists":1,"items":3,"future":{"PRIVATE":[null,false]}}',
                ['bytes' => 4096, 'exists' => 1, 'items' => 3]],
            ] as [$json, $expected]
        ) {
            $cases[] = ['getUsage', $json, true, $expected];
        }
        // Pinned bool inputs remain accepted, now returning int flags; omission stays omission.
        foreach (
            [
            ['{}', []],
            ['[]', []],
            ['{"removed":true}', ['removed' => 1]],
            ['{"removed":false}', ['removed' => 0]],
            ['{"alreadyGone":true}', ['alreadyGone' => 1]],
            ['{"alreadyGone":false}', ['alreadyGone' => 0]],
            ['{"alreadyGone":true,"removed":true}', ['alreadyGone' => 1, 'removed' => 1]],
            ['{"alreadyGone":true,"removed":false}', ['alreadyGone' => 1, 'removed' => 0]],
            ['{"alreadyGone":false,"removed":true}', ['alreadyGone' => 0, 'removed' => 1]],
            ['{"alreadyGone":false,"removed":false}', ['alreadyGone' => 0, 'removed' => 0]],
            ['{"removed":false,"future":"PRIVATE"}', ['removed' => 0]],
            ] as [$json, $expected]
        ) {
            $cases[] = ['removeItem', $json, true, $expected];
        }
        foreach (['bytes', 'exists', 'items'] as $field) {
            foreach (
                ['null', '1.5', '1.0', 'false', 'true', '[]', '{}',
                ...($field === 'items' ? [] : ['"0"', '"4096"'])] as $wrong
            ) {
                $cases[] = ['getUsage', '{"' . $field . '":' . $wrong . '}', false, null];
            }
        }
        foreach (['removed', 'alreadyGone'] as $field) {
            foreach (['null', '-1', '2', '1.0', '1.5', '"0"', '"1"', '"true"', '[]', '{}'] as $wrong) {
                $cases[] = ['removeItem', '{"' . $field . '":' . $wrong . '}', false, null];
            }
        }
        foreach (['getUsage', 'removeItem'] as $method) {
            foreach ([null, 'null', '0', '1.5', 'false', 'true', '"PRIVATE"', '[{}]', '[null]'] as $wrong) {
                $cases[] = [$method, $wrong, false, null];
            }
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route case $index" => [$route, ...$case];
            }
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('nativeFailures')]
    public function testRequiredNativeInputFailuresBeforeEntryRetainPriorMetadata(
        string $route,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}', 1, true)));
        $account->trash()->getUsage();
        $before = $account->responseMeta();
        try {
            $account->trash()->$method(...$arguments);
            self::fail('A missing or non-string native path must fail before entry.');
        } catch (TypeError) {
            self::assertSame($before, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>}> */
    public static function nativeFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach ([[], [null], [[]], [new \stdClass()], [42], [false], [1.25]] as $index => $arguments) {
                yield "$route case $index" => [$route, 'removeItem', $arguments];
            }
        }
    }

    #[DataProvider('outcomes')]
    public function testApiFailurePrecedesValidPayloadAndMetadataReplacesOnSuccess(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $payload = $method === 'getUsage' ? '{"bytes":0,"exists":0,"items":0}'
            : '{"alreadyGone":false,"removed":false}';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 0)));
        try {
            self::invoke($account->trash(), $method);
            self::fail('Failed API status rejects even a valid object with zero or false fields.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['PRIVATE error'], $error->metadata->uapiErrors());
        }
        $before = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        self::assertSame($method === 'getUsage' ? ['bytes' => 0, 'exists' => 0, 'items' => 0]
            : ['alreadyGone' => 0, 'removed' => 0], self::invoke($account->trash(), $method));
        self::assertNotSame($before, $account->responseMeta());
        self::assertSame(['Synthetic warning'], $account->responseMeta()?->uapiWarnings());
        self::assertSame(['transformed' => 1], $account->responseMeta()->uapi['metadata'] ?? null);
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi);
        self::assertCount(2, $http->getRequests());
    }

    public function testBridgeAccountIsolationAndMetadataAreBoundToOwningHandle(): void
    {
        [$client, $http] = self::client('bridge');
        $one = $client->uapi('fixture');
        $two = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"items":1}')));
        self::assertSame(['items' => 1], $one->trash()->getUsage());
        self::assertNull($two->responseMeta());
        $before = $one->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"removed":true}')));
        self::assertSame(['removed' => 1], $two->trash()->removeItem('PRIVATE two'));
        self::assertSame($before, $one->responseMeta());
        self::assertNotSame($before, $two->responseMeta());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=other'
            . '&cpanel.module=Trash&cpanel.function=remove', (string) $request->getUri());
        self::assertSame('path=PRIVATE%20two', (string) $request->getBody());
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('failures')]
    public function testFailuresClearMetadataBeforeIoUseOneAttemptAndConcealActualLibraryFrames(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"items":1}')));
        $account->trash()->getUsage();
        $previous = $account->responseMeta();
        $clearedAtIo = false;
        $http->on(new class implements RequestMatcher {
            public function matches(RequestInterface $request): bool
            {
                return true;
            }
        }, static function () use ($account, $route, $failure, &$clearedAtIo): Response {
            $clearedAtIo = $account->responseMeta() === null;
            if ($failure === 'transport') {
                throw new class ('PRIVATE transport') extends RuntimeException implements ClientExceptionInterface {
                };
            }
            return match ($failure) {
                'http' => new Response(503, [], 'PRIVATE non-json'),
                'json' => new Response(200, [], 'PRIVATE non-json'),
                'api' => new Response(200, [], self::envelope($route, '"PRIVATE invalid payload"', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"PRIVATE outer"},'
                    . '"data":{"uapi":{"status":1,"data":"PRIVATE invalid payload"}}}'),
                'status' => new Response(200, [], $route === 'bridge'
                    ? '{"metadata":{"result":1},"data":{"uapi":{"status":"1","data":"PRIVATE"}}}'
                    : '{"result":{"status":"1","data":"PRIVATE"}}'),
                default => new Response(200, [], self::envelope($route, '"PRIVATE successful mismatch"')),
            };
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($account->trash(), $method);
            self::fail('The invocation must fail without retry.');
        } catch (ApiException $error) {
            self::assertSame(match ($failure) {
                'transport' => TransportException::class,
                'http' => HttpException::class,
                'json', 'status' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class,
                default => PayloadMismatchException::class,
            }, $error::class);
            if (in_array($failure, ['transport', 'http', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotNull($error->metadata);
                self::assertNotSame($previous, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(match ($failure) {
                    'api' => 0, 'status' => null, default => 1,
                }, $error->metadata->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' ? 1 : null),
                    $error->metadata->whmResult(),
                );
            }
            if ($failure === 'outer') {
                self::assertStringContainsString('WHM', $error->getMessage());
                self::assertSame(['PRIVATE outer'], $error->metadata?->errors());
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame('PRIVATE successful mismatch', $error->payload);
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
            }
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            $frames = 0;
            $groupFrames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($class, __NAMESPACE__ . '\\')
                ) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(
                        self::hasPrivateValue($frame['args'] ?? [], $seen),
                        $class . '::' . $frame['function'],
                    );
                    if ($method === 'removeItem' && $class === Trash::class) {
                        $groupFrames++;
                        self::assertInstanceOf(SensitiveParameterValue::class, $frame['args'][0] ?? null);
                    }
                }
            }
            self::assertGreaterThan(0, $frames);
            if ($method === 'removeItem') {
                self::assertSame(1, $groupFrames);
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertTrue($clearedAtIo);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function outcomes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['getUsage', 'removeItem'] as $method) {
                yield "$route $method" => [$route, $method];
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (self::outcomes() as $name => [$route, $method]) {
            foreach (
                ['transport', 'http', 'json', 'api', 'status', 'payload',
                ...($route === 'bridge' ? ['outer'] : [])] as $failure
            ) {
                yield "$name $failure" => [$route, $method, $failure];
            }
        }
    }

    /** @return array<mixed> */
    private static function invoke(Trash $group, string $method): array
    {
        return $method === 'getUsage' ? $group->getUsage() : $group->removeItem(path: 'PRIVATE path\\/+% š');
    }

    /** @param array<int, true> $seen */
    private static function hasPrivateValue(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'PRIVATE');
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (str_contains((string) $key, 'PRIVATE') || self::hasPrivateValue($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function assertRequest(MockClient $http, string $route, string $function, string $body): void
    {
        $post = $function === 'remove';
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=Trash&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Trash/' . $function, (string) $request->getUri());
        self::assertSame($body, (string) $request->getBody());
        self::assertSame($post ? 'application/x-www-form-urlencoded' : '', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["PRIVATE error"]')
            . ',"messages":null,"warnings":' . ($warning ? '["Synthetic warning"]' : 'null')
            . ',"metadata":' . ($warning ? '{"transformed":1}' : '{}') . '}';
        return $route === 'bridge' ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'fixture', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
