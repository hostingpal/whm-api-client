<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\UserTasks;
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

/** Independent synthetic fixtures; no queue, repository path or returned URL is accessed. */
final class UapiUserTasksTest extends TestCase
{
    public function testCachedAccountAccessorAndExactNativeSignaturesWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('userTasks'), 'UserTasks accessor must exist.');
        self::assertSame($account->userTasks(), $account->userTasks());
        self::assertNotSame($account->userTasks(), $client->uapi('other')->userTasks());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        $list = new ReflectionMethod(UserTasks::class, 'listTasks');
        self::assertSame('array', (string) $list->getReturnType());
        self::assertCount(0, $list->getParameters());
        $delete = new ReflectionMethod(UserTasks::class, 'deleteTask');
        self::assertSame('?array', (string) $delete->getReturnType());
        self::assertCount(1, $delete->getParameters());
        $id = $delete->getParameters()[0];
        self::assertSame('id', $id->getName());
        self::assertSame('string', (string) $id->getType());
        self::assertFalse($id->isOptional());
        self::assertFalse($id->isDefaultValueAvailable());
        self::assertCount(1, $id->getAttributes(SensitiveParameter::class));
    }

    #[DataProvider('requests')]
    public function testLiteralRequestsPreserveIdentifiersOnce(string $route, ?string $id, string $body): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('userTasks'), 'UserTasks accessor must exist.');
        $http->addResponse(new Response(200, [], self::envelope($route, $id === null ? '[]' : 'null')));
        $actual = $id === null ? $account->userTasks()->listTasks() : $account->userTasks()->deleteTask(id: $id);
        self::assertSame($id === null ? [] : null, $actual);
        self::assertCount(1, $http->getRequests());
        self::assertRequest($http, $route, $id === null ? 'retrieve' : 'delete', $body);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function requests(): iterable
    {
        $cases = [
            'inventory' => [null, ''],
            'source literal backslash slash' => ['00000000\\/5a9ec8dd4c345d', 'id=00000000%5C%2F5a9ec8dd4c345d'],
            'source slash' => ['00000000/h39dnn27suc13l', 'id=00000000%2Fh39dnn27suc13l'],
            'same suffix slash' => ['00000000/5a9ec8dd4c345d', 'id=00000000%2F5a9ec8dd4c345d'],
            'slash only' => ['/', 'id=%2F'],
            'backslash slash only' => ['\\/', 'id=%5C%2F'],
            'percent escapes' => ['%5c%2f%2F', 'id=%255c%252f%252F'],
            'unicode plus space percent' => ['š+ %', 'id=%C5%A1%2B%20%25'],
            'empty supplied' => ['', 'id='],
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
            $actual = self::invoke($account->userTasks(), $method);
            self::assertSame($expected, $actual);
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
    }

    /** @return iterable<string, array{string, string, ?string, bool, mixed}> */
    public static function payloads(): iterable
    {
        $inventory = '[{"subsystem":"VersionControl","id":"00000000\\\\/5a9ec8dd4c345d",'
            . '"args":{},"action":"create"},{"id":"00000000/h39dnn27suc13l",'
            . '"args":{"repository_root":"/PRIVATE/repository +% š"},"action":"create","subsystem":"VersionControl"}]';
        $cases = [
            ['listTasks', $inventory, true, [
                ['action' => 'create', 'args' => [], 'id' => '00000000\\/5a9ec8dd4c345d',
                    'subsystem' => 'VersionControl'],
                ['action' => 'create', 'args' => ['repository_root' => '/PRIVATE/repository +% š'],
                    'id' => '00000000/h39dnn27suc13l', 'subsystem' => 'VersionControl'],
            ]],
            ['listTasks', '[]', true, []],
            ['listTasks', '[{},{}]', true, [[], []]],
            ['listTasks', '[[]]', true, [[]]],
            ['listTasks', '[{"args":[]}]', true, [['args' => []]]],
            ['listTasks', '[{"args":{"repository_root":""}}]', true, [['args' => ['repository_root' => '']]]],
            ['listTasks', '[{"action":"Other","subsystem":"Future"}]', true,
                [['action' => 'Other', 'subsystem' => 'Future']]],
            ['listTasks', '[{"id":"second"},{"id":"first"},{"id":"second"}]', true,
                [['id' => 'second'], ['id' => 'first'], ['id' => 'second']]],
            ['listTasks', '[{"future":"PRIVATE","args":{"future":{"PRIVATE":[null,false]}}}]', true,
                [['args' => []]]],
            ['deleteTask', 'null', true, null],
            ['deleteTask', '{}', true, []],
            ['deleteTask', '[]', true, []],
            ['deleteTask', '{"PRIVATE":{"nested":[null,false,0,1.25,"PRIVATE",{},[]]},"flag":0}', true,
                ['PRIVATE' => ['nested' => [null, false, 0, 1.25, 'PRIVATE', [], []]], 'flag' => 0]],
        ];
        foreach (['action', 'id', 'subsystem'] as $field) {
            $cases[] = ['listTasks', '[{"' . $field . '":""}]', true, [[$field => '']]];
            $cases[] = ['listTasks', '[{"' . $field . '":"PRIVATE +% š\\\\/"}]', true,
                [[$field => 'PRIVATE +% š\\/']]];
            foreach (['null', '0', '1.5', 'false', '[]', '{}'] as $wrong) {
                $cases[] = ['listTasks', '[{"' . $field . '":' . $wrong . '}]', false, null];
            }
        }
        foreach (['null', '0', '1.5', 'false', '"text"', '[{}]'] as $wrong) {
            $cases[] = ['listTasks', '[{"args":' . $wrong . '}]', false, null];
        }
        foreach (['null', '0', '1.5', 'false', '[]', '{}'] as $wrong) {
            $cases[] = ['listTasks', '[{"args":{"repository_root":' . $wrong . '}}]', false, null];
        }
        foreach ([null, 'null', '{}', '{"id":"x"}', '"text"', '0', 'false', '1.5'] as $wrong) {
            $cases[] = ['listTasks', $wrong, false, null];
        }
        foreach (['null', '"text"', '0', 'false', '1.5', '[{}]'] as $wrong) {
            $cases[] = ['listTasks', '[' . $wrong . ']', false, null];
        }
        foreach ([null, '0', '1.5', 'false', '"text"', '[{}]', '[null]'] as $wrong) {
            $cases[] = ['deleteTask', $wrong, false, null];
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
        $http->addResponse(new Response(200, [], self::envelope($route, '[]', 1, true)));
        $account->userTasks()->listTasks();
        $before = $account->responseMeta();
        try {
            $account->userTasks()->$method(...$arguments);
            self::fail('A missing or non-string native ID must fail before entry.');
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
                yield "$route case $index" => [$route, 'deleteTask', $arguments];
            }
        }
    }

    #[DataProvider('outcomes')]
    public function testApiFailurePrecedesValidPayloadAndMetadataReplacesOnSuccess(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $payload = $method === 'listTasks' ? '[]' : 'null';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 0)));
        try {
            self::invoke($account->userTasks(), $method);
            self::fail('Failed API status rejects even a valid nullable or list payload.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['PRIVATE error'], $error->metadata->uapiErrors());
        }
        $before = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        self::assertSame($method === 'listTasks' ? [] : null, self::invoke($account->userTasks(), $method));
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
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[{"id":"PRIVATE one"}]')));
        self::assertSame([['id' => 'PRIVATE one']], $one->userTasks()->listTasks());
        self::assertNull($two->responseMeta());
        $before = $one->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'null')));
        self::assertNull($two->userTasks()->deleteTask('PRIVATE two'));
        self::assertSame($before, $one->responseMeta());
        self::assertNotSame($before, $two->responseMeta());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=other'
            . '&cpanel.module=UserTasks&cpanel.function=delete', (string) $request->getUri());
        self::assertSame('id=PRIVATE%20two', (string) $request->getBody());
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
        $http->addResponse(new Response(200, [], self::envelope($route, '[{"id":"PRIVATE previous"}]')));
        $account->userTasks()->listTasks();
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
            self::invoke($account->userTasks(), $method);
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
                    if ($method === 'deleteTask' && $class === UserTasks::class) {
                        $groupFrames++;
                        self::assertInstanceOf(SensitiveParameterValue::class, $frame['args'][0] ?? null);
                    }
                }
            }
            self::assertGreaterThan(0, $frames);
            if ($method === 'deleteTask') {
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
            foreach (['listTasks', 'deleteTask'] as $method) {
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

    /** @return array<mixed>|null */
    private static function invoke(UserTasks $group, string $method): ?array
    {
        return $method === 'listTasks' ? $group->listTasks() : $group->deleteTask(id: 'PRIVATE id\\/+% š');
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
        $post = $function === 'delete';
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=UserTasks&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/UserTasks/' . $function, (string) $request->getUri());
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
