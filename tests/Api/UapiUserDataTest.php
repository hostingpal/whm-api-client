<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\UserData;
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

/** Independent synthetic fixtures; no settings, returned path or URL is accessed. */
final class UapiUserDataTest extends TestCase
{
    public function testCachedAccountAccessorAndExactNativeSignaturesWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('userData'), 'UserData accessor must exist.');
        self::assertSame($account->userData(), $account->userData());
        self::assertNotSame($account->userData(), $client->uapi('other')->userData());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        foreach (
            [
            'getScopedUserdata' => ['scope'],
            'setScopedUserdata' => ['scope', 'json', 'key', 'value'],
            ] as $name => $names
        ) {
            $method = new ReflectionMethod(UserData::class, $name);
            self::assertSame('array', (string) $method->getReturnType());
            self::assertCount(count($names), $method->getParameters());
            foreach ($method->getParameters() as $index => $parameter) {
                self::assertSame($names[$index], $parameter->getName());
                self::assertSame($index === 0 ? 'string' : '?string', (string) $parameter->getType());
                self::assertSame($index !== 0, $parameter->isOptional());
                self::assertSame($index !== 0, $parameter->isDefaultValueAvailable());
                if ($index !== 0) {
                    self::assertNull($parameter->getDefaultValue());
                }
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
            }
        }
    }

    /** @param array<string, string|null> $arguments */
    #[DataProvider('requests')]
    public function testLiteralRequestsPreserveRawScalarFieldsOnce(
        string $route,
        string $method,
        array $arguments,
        string $body,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('userData'), 'UserData accessor must exist.');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::assertSame([], $account->userData()->$method(...$arguments));
        self::assertCount(1, $http->getRequests());
        self::assertRequest(
            $http,
            $route,
            $method === 'getScopedUserdata' ? 'get_scoped_userdata' : 'set_scoped_userdata',
            $body,
        );
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, string, array<string, string|null>, string}> */
    public static function requests(): iterable
    {
        $cases = [
            'get' => ['getScopedUserdata', ['scope' => 'prefs'], 'scope=prefs'],
            'get empty' => ['getScopedUserdata', ['scope' => ''], 'scope='],
            'get private bytes' => ['getScopedUserdata', ['scope' => ' %+.š/'], 'scope=%20%25%2B.%C5%A1%2F'],
            'scope only' => ['setScopedUserdata', ['scope' => 'prefs'], 'scope=prefs'],
            'json only' => ['setScopedUserdata', ['scope' => 'prefs', 'json' => '{"theme":"dark"}'],
                'scope=prefs&json=%7B%22theme%22%3A%22dark%22%7D'],
            'key only' => ['setScopedUserdata', ['scope' => 'prefs', 'key' => 'theme'], 'scope=prefs&key=theme'],
            'value only' => ['setScopedUserdata', ['scope' => 'prefs', 'value' => 'dark'], 'scope=prefs&value=dark'],
            'key value' => ['setScopedUserdata', ['scope' => 'prefs', 'key' => 'theme', 'value' => 'dark'],
                'scope=prefs&key=theme&value=dark'],
            'json key conflict' => ['setScopedUserdata', ['scope' => 'prefs', 'json' => 'raw', 'key' => 'theme'],
                'scope=prefs&json=raw&key=theme'],
            'json value conflict' => ['setScopedUserdata', ['scope' => 'prefs', 'json' => 'raw', 'value' => 'dark'],
                'scope=prefs&json=raw&value=dark'],
            'all conflict caller order' => ['setScopedUserdata',
                ['value' => 'dark', 'key' => 'theme', 'json' => 'raw', 'scope' => 'prefs'],
                'scope=prefs&json=raw&key=theme&value=dark'],
            'all explicit null omit' => ['setScopedUserdata',
                ['scope' => 'prefs', 'json' => null, 'key' => null, 'value' => null],
                'scope=prefs'],
            'null between values' => ['setScopedUserdata',
                ['scope' => 'prefs', 'json' => 'raw', 'key' => null, 'value' => ''],
                'scope=prefs&json=raw&value='],
            'all supplied empty' => ['setScopedUserdata', ['scope' => '', 'json' => '', 'key' => '', 'value' => ''],
                'scope=&json=&key=&value='],
            'raw whitespace duplicate keys' => ['setScopedUserdata',
                ['scope' => 'prefs', 'json' => " {\n\"a\":1, \"a\":2} \t"],
                'scope=prefs&json=%20%7B%0A%22a%22%3A1%2C%20%22a%22%3A2%7D%20%09'],
            'raw malformed percent plus unicode' => ['setScopedUserdata',
                ['scope' => 'prefs', 'json' => '{%2F+š broken'],
                'scope=prefs&json=%7B%252F%2B%C5%A1%20broken'],
            'raw scalar' => ['setScopedUserdata', ['scope' => 'prefs', 'json' => 'null'], 'scope=prefs&json=null'],
            'raw list' => ['setScopedUserdata', ['scope' => 'prefs', 'json' => '[1,"0"]'],
                'scope=prefs&json=%5B1%2C%220%22%5D'],
            'key value punctuation' => ['setScopedUserdata',
                ['scope' => 'prefs', 'key' => 'api.version +%', 'value' => 'š/ %2f+'],
                'scope=prefs&key=api.version%20%2B%25&value=%C5%A1%2F%20%252f%2B'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => $case) {
                yield "$route $name" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('payloads')]
    public function testRequiredDynamicStringMapPreservesKeysAndRejectsOtherTypes(
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
            self::assertSame($expected, self::invoke($account->userData(), $method));
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
    }

    /** @return iterable<string, array{string, string, ?string, bool, mixed}> */
    public static function payloads(): iterable
    {
        $cases = [
            ['{}', true, []],
            ['[]', true, []],
            ['{"theme":"dark","items_per_page":"50","show_help_tour":"0"}', true,
                ['theme' => 'dark', 'items_per_page' => '50', 'show_help_tour' => '0']],
            ['{"":""," ":" ","š":"ž","dotted.key":"0","api.version":"50","cpanel.user":"PRIVATE",'
                . '"__proto__":"raw","0":"zero","-1":"minus","01":"leading","+1":"plus","1.0":"decimal",'
                . '"url":"https://private.example.test/path","path":"/PRIVATE/settings"}', true,
                ['' => '', ' ' => ' ', 'š' => 'ž', 'dotted.key' => '0', 'api.version' => '50',
                    'cpanel.user' => 'PRIVATE',
                    '__proto__' => 'raw', 0 => 'zero', -1 => 'minus', '01' => 'leading',
                    '+1' => 'plus', '1.0' => 'decimal',
                    'url' => 'https://private.example.test/path', 'path' => '/PRIVATE/settings']],
            ['{"0":"zero","1":"one"}', true, [0 => 'zero', 1 => 'one']],
        ];
        foreach ([null, 'null', '0', '1.5', 'true', 'false', '"PRIVATE"', '["0"]', '[{}]', '[null]'] as $wrong) {
            $cases[] = [$wrong, false, null];
        }
        foreach (
            ['0', '50', '-1', '1.0', '1.5', 'true', 'false', 'null', '[]', '{}',
            '["value"]', '{"key":"value"}'] as $wrong
        ) {
            $cases[] = ['{"valid":"0","PRIVATE":' . $wrong . '}', false, null];
        }
        foreach (self::outcomes() as $name => [$route, $method]) {
            foreach ($cases as $index => $case) {
                yield "$name case $index" => [$route, $method, ...$case];
            }
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('nativeFailures')]
    public function testNativeArgumentFailuresBeforeEntryRetainEarlierMetadata(
        string $route,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}', 1, true)));
        $account->userData()->getScopedUserdata('prefs');
        $before = $account->responseMeta();
        try {
            $account->userData()->$method(...$arguments);
            self::fail('Missing or non-string native arguments must fail before entry.');
        } catch (TypeError) {
            self::assertSame($before, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>}> */
    public static function nativeFailures(): iterable
    {
        foreach (self::outcomes() as $name => [$route, $method]) {
            foreach ([[], [null], [[]], [new \stdClass()], [42], [false], [1.25]] as $index => $arguments) {
                yield "$name scope $index" => [$route, $method, $arguments];
            }
            if ($method === 'setScopedUserdata') {
                foreach ([[], new \stdClass(), 42, false, 1.25] as $index => $wrong) {
                    yield "$name json $index" => [$route, $method, ['prefs', $wrong]];
                    yield "$name key $index" => [$route, $method, ['prefs', null, $wrong]];
                    yield "$name value $index" => [$route, $method, ['prefs', null, null, $wrong]];
                }
            }
        }
    }

    #[DataProvider('outcomes')]
    public function testApiStatusPrecedesValidPayloadAndReplacesMetadataSnapshots(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"flag":"0","empty":""}', 0)));
        try {
            self::invoke($account->userData(), $method);
            self::fail('Failed API status rejects even a valid string map.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['PRIVATE error'], $error->metadata->uapiErrors());
        }
        $before = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, '{"flag":"0","empty":""}', 1, true)));
        self::assertSame(['flag' => '0', 'empty' => ''], self::invoke($account->userData(), $method));
        self::assertNotSame($before, $account->responseMeta());
        self::assertSame(0, $before?->uapiStatus());
        self::assertSame(['PRIVATE warning'], $account->responseMeta()?->uapiWarnings());
        self::assertSame(
            ['transformed' => 1, 'private' => 'PRIVATE metadata'],
            $account->responseMeta()->uapi['metadata'] ?? null,
        );
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi);
        self::assertCount(2, $http->getRequests());
    }

    public function testBridgeAccountsKeepRequestsAndMetadataBoundToOwningHandle(): void
    {
        [$client, $http] = self::client('bridge');
        $one = $client->uapi('fixture');
        $two = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"first":"0"}')));
        self::assertSame(['first' => '0'], $one->userData()->getScopedUserdata('prefs'));
        self::assertNull($two->responseMeta());
        $before = $one->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"second":"50"}')));
        self::assertSame(['second' => '50'], $two->userData()->setScopedUserdata('PRIVATE two', null, 'theme', 'dark'));
        self::assertSame($before, $one->responseMeta());
        self::assertNotSame($before, $two->responseMeta());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=other'
            . '&cpanel.module=UserData&cpanel.function=set_scoped_userdata', (string) $request->getUri());
        self::assertSame('scope=PRIVATE%20two&key=theme&value=dark', (string) $request->getBody());
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('failures')]
    public function testFailurePrecedenceOneAttemptMetadataResetAtIoAndActualLibraryTracePrivacy(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->userData()->getScopedUserdata('prefs');
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
                'api' => new Response(200, [], self::envelope($route, '{"PRIVATE":null}', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"PRIVATE outer"},'
                    . '"data":{"uapi":{"status":1,"data":{"PRIVATE":null}}}}'),
                'status' => new Response(200, [], $route === 'bridge'
                    ? '{"metadata":{"result":1},"data":{"uapi":{"status":"1","data":{"PRIVATE":null}}}}'
                    : '{"result":{"status":"1","data":{"PRIVATE":null}}}'),
                default => new Response(
                    200,
                    [],
                    self::envelope($route, '{"PRIVATE successful mismatch":null}', 1, true),
                ),
            };
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($account->userData(), $method);
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
                self::assertSame(['PRIVATE successful mismatch' => null], $error->payload);
                self::assertSame(['PRIVATE warning'], $error->metadata?->uapiWarnings());
                self::assertSame(
                    ['transformed' => 1, 'private' => 'PRIVATE metadata'],
                    $error->metadata?->uapi['metadata'] ?? null,
                );
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
                    if ($class === UserData::class) {
                        $groupFrames++;
                        self::assertCount($method === 'getScopedUserdata' ? 1 : 4, $frame['args'] ?? []);
                        foreach ($frame['args'] ?? [] as $argument) {
                            self::assertInstanceOf(SensitiveParameterValue::class, $argument);
                        }
                    }
                }
            }
            self::assertGreaterThan(0, $frames);
            self::assertSame(1, $groupFrames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertTrue($clearedAtIo);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function outcomes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['getScopedUserdata', 'setScopedUserdata'] as $method) {
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

    /** @return array<array-key, string> */
    private static function invoke(UserData $group, string $method): array
    {
        return $method === 'getScopedUserdata' ? $group->getScopedUserdata(scope: 'PRIVATE scope')
            : $group->setScopedUserdata(
                scope: 'PRIVATE scope',
                json: 'PRIVATE json',
                key: 'PRIVATE key',
                value: 'PRIVATE value',
            );
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
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=UserData&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/UserData/' . $function, (string) $request->getUri());
        self::assertSame($body, (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["PRIVATE error"]')
            . ',"messages":null,"warnings":' . ($warning ? '["PRIVATE warning"]' : 'null')
            . ',"metadata":' . ($warning ? '{"transformed":1,"private":"PRIVATE metadata"}' : '{}') . '}';
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
