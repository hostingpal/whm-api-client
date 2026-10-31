<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Ccs;
use HostingPal\WhmApiClient\Api\Uapi\Spec\CcsSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use TypeError;

/**
 * Independent CCS source fixtures; CPDAVD's live contracts do not apply to this plugin.
 *
 * @phpstan-type CcsCall Closure(Ccs): mixed
 */
final class UapiCcsTest extends TestCase
{
    #[DataProvider('routes')]
    public function testAccessorCachesOnePluginGroupPerAccountWithoutIo(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $other = $route === 'bridge' ? $client->uapi('other') : self::client($route)[0]->uapi('npr');
        self::assertInstanceOf(Ccs::class, $account->ccs());
        self::assertSame($account->ccs(), $account->ccs());
        self::assertNotSame($account->ccs(), $other->ccs());
        self::assertCount(0, $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->ccs()->listDelegates();
        $previous = $account->responseMeta();
        $account->ccs();
        self::assertSame($previous, $account->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @param CcsCall $call */
    #[DataProvider('successfulCalls')]
    public function testLiteralRequestsPreserveAddressesPayloadAndMetadata(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $call($account->ccs()));
        self::assertRequest($http, $route, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Fixture warning'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, CcsCall, string, ?string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::operationCalls() as $function => $call) {
                $read = str_starts_with($function, 'list_');
                yield "$route $function" => [
                    $route, $function, $read ? 'GET' : 'POST', $call,
                    $read ? '' : 'delegatee=Guest%2B%2526%26%E9%9B%AA%40Example.test'
                        . '&delegator=Owner%2B%25%26%C4%87%40Example.test'
                        . ($function === 'remove_delegate' ? '' : '&readonly=0'),
                    $read ? ($function === 'list_users' ? '{}' : '[]') : null,
                    $read ? [] : null,
                ];
            }
            foreach (['addDelegate' => 'add_delegate', 'updateDelegate' => 'update_delegate'] as $method => $function) {
                $cases = [
                    'omitted' => [static function (Ccs $c) use ($method): mixed {
                        $c->$method('', '');
                        return null;
                    }, ''],
                    'null' => [static function (Ccs $c) use ($method): mixed {
                        $c->$method('', '', null);
                        return null;
                    }, ''],
                    'false' => [static function (Ccs $c) use ($method): mixed {
                        $c->$method('', '', false);
                        return null;
                    }, '&readonly=0'],
                    'true' => [static function (Ccs $c) use ($method): mixed {
                        $c->$method('', '', true);
                        return null;
                    }, '&readonly=1'],
                ];
                foreach ($cases as $name => [$call, $suffix]) {
                    yield "$route $function $name" => [
                        $route, $function, 'POST', $call, 'delegatee=&delegator=' . $suffix, null, null,
                    ];
                }
            }
            $delegations = [
                ['null', null], ['[]', []], ['[{},[]]', [[], []]],
                ['[{"delegatee":"Guest@example.test","delegator":"Owner@example.test","read_only":0},'
                    . '{"read_only":1},{"delegatee":"","future":true}]',
                    [
            ['delegatee' => 'Guest@example.test', 'delegator' => 'Owner@example.test', 'read_only' => 0],
                        ['read_only' => 1], ['delegatee' => '']]],
            ];
            foreach ($delegations as $i => [$payload, $expected]) {
                yield "$route delegates $i" => [
                    $route, 'list_delegates', 'GET', static fn (Ccs $c): mixed => $c->listDelegates(),
                    '', $payload, $expected,
                ];
            }
            $users = [
                ['[]', []],
                ['{"Guest@example.test":"31b2c4d6-1234-4567-890a-bcdef0123456",'
                    . '"Owner+tag@example.test":"8c7d6e5f-4321-4abc-9876-0123456789ab"}',
                    ['Guest@example.test' => '31b2c4d6-1234-4567-890a-bcdef0123456',
                        'Owner+tag@example.test' => '8c7d6e5f-4321-4abc-9876-0123456789ab']],
                ['{"0":"zero","12":false,"012":null,"other":1.5,"nested":{"a":[1,true,null,{}]},'
                    . '"integer":7,"emptyObject":{},"emptyList":[]}',
                    [0 => 'zero', 12 => false, '012' => null, 'other' => 1.5,
                        'nested' => ['a' => [1, true, null, []]], 'integer' => 7,
                        'emptyObject' => [], 'emptyList' => []]],
            ];
            foreach ($users as $i => [$payload, $expected]) {
                yield "$route opaque users $i" => [
                    $route, 'list_users', 'GET', static fn (Ccs $c): mixed => $c->listUsers(),
                    '', $payload, $expected,
                ];
            }
        }
    }

    /** @param CcsCall $call */
    #[DataProvider('invalidPayloads')]
    public function testMalformedOrAbsentReadPayloadRetainsFreshConfirmedMetadata(
        string $route,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->ccs()->listDelegates();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account->ccs());
            self::fail('Malformed or absent read data must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, CcsCall, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (['list_delegates', 'list_users'] as $function) {
                $cases = [null, 'true', '0', '0.5', '"text"'];
                if ($function === 'list_users') {
                    array_push($cases, 'null', '[{}]', '[1]', '[null]');
                } else {
                    array_push($cases, '{}', '{"0":{}}', '[null]', '[false]', '[1]', '["text"]');
                    foreach (['delegatee', 'delegator'] as $field) {
                        foreach (['null', '1', 'true', '[]', '{}'] as $bad) {
                            $cases[] = '[{"' . $field . '":' . $bad . '}]';
                        }
                    }
                    foreach (['null', '"0"', '"1"', 'false', 'true', '2', '-1', '0.5', '[]', '{}'] as $bad) {
                        $cases[] = '[{"read_only":' . $bad . '}]';
                    }
                    $cases[] = '[{"read_only":0},{"delegator":null}]';
                }
                foreach ($cases as $i => $payload) {
                    yield "$route $function $i" => [$route, self::operationCalls()[$function], $payload];
                }
            }
        }
    }

    /** @param CcsCall $call */
    #[DataProvider('invalidArguments')]
    public function testNativeSignatureRejectionPrecedesMetadataResetAndIo(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->ccs()->listDelegates();
        $previous = $account->responseMeta();
        try {
            $call($account->ccs());
            self::fail('Wrong native types must fail.');
        } catch (TypeError) {
            self::assertSame($previous, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, CcsCall}> */
    public static function invalidArguments(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach ([null, 1, false, []] as $i => $bad) {
                $cases = [
                    'addDelegate delegatee' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid native inputs exercise rejection before metadata reset.
                        // @phpstan-ignore argument.type
                        $c->addDelegate($bad, '');
                        return null;
                    },
                    'addDelegate delegator' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid native inputs exercise rejection before metadata reset.
                        // @phpstan-ignore argument.type
                        $c->addDelegate('', $bad);
                        return null;
                    },
                    'removeDelegate delegatee' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid native inputs exercise rejection before metadata reset.
                        // @phpstan-ignore argument.type
                        $c->removeDelegate($bad, '');
                        return null;
                    },
                    'removeDelegate delegator' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid native inputs exercise rejection before metadata reset.
                        // @phpstan-ignore argument.type
                        $c->removeDelegate('', $bad);
                        return null;
                    },
                    'updateDelegate delegatee' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid native inputs exercise rejection before metadata reset.
                        // @phpstan-ignore argument.type
                        $c->updateDelegate($bad, '');
                        return null;
                    },
                    'updateDelegate delegator' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid native inputs exercise rejection before metadata reset.
                        // @phpstan-ignore argument.type
                        $c->updateDelegate('', $bad);
                        return null;
                    },
                ];
                foreach ($cases as $name => $call) {
                    yield "$route $name $i" => [$route, $call];
                }
            }
            foreach ([0, 1, '0', '1', 'false', 0.0, []] as $i => $bad) {
                $cases = [
                    'addDelegate readonly' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid integer/string flags must not coerce to booleans.
                        // @phpstan-ignore argument.type
                        $c->addDelegate('', '', $bad);
                        return null;
                    },
                    'updateDelegate readonly' => static function (Ccs $c) use ($bad): mixed {
                        // Intentionally invalid integer/string flags must not coerce to booleans.
                        // @phpstan-ignore argument.type
                        $c->updateDelegate('', '', $bad);
                        return null;
                    },
                ];
                foreach ($cases as $name => $call) {
                    yield "$route $name $i" => [$route, $call];
                }
            }
        }
    }

    /** @param CcsCall $call */
    #[DataProvider('operationRoutes')]
    public function testApiFailurePrecedesPayloadAndReplacesMetadata(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->ccs()->listDelegates();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
        try {
            $call($account->ccs());
            self::fail('Status zero must raise before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param CcsCall $call */
    #[DataProvider('bridgeOperations')]
    public function testOuterBridgeFailureWinsOverUapiSuccess(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->ccs()->listDelegates();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Outer failure"},'
            . '"data":{"uapi":{"status":1,"data":null}}}'));
        try {
            $call($account->ccs());
            self::fail('Outer WHM failure must win.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Outer failure'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param CcsCall $call */
    #[DataProvider('operationRoutes')]
    public function testHttpAndTransportFailureClearMetadataWithoutRetry(string $route, Closure $call): void
    {
        foreach (['http', 'transport'] as $failure) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
            $account->ccs()->listDelegates();
            self::assertNotNull($account->responseMeta());
            if ($failure === 'http') {
                $http->addResponse(new Response(503, [], 'unavailable'));
            } else {
                $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
                });
            }
            try {
                $call($account->ccs());
                self::fail('A failed request must throw.');
            } catch (ApiException $error) {
                self::assertSame($failure === 'transport', $error instanceof TransportException);
                self::assertNull($error->metadata);
                self::assertNull($account->responseMeta());
            }
            self::assertCount(2, $http->getRequests());
        }
    }

    public function testPublicSignaturesPreserveNamesOrderAndOptionalBoolDefaults(): void
    {
        $signatures = [
            'addDelegate' => ['void', ['delegatee' => 'string', 'delegator' => 'string', 'readonly' => '?bool']],
            'listDelegates' => ['?array', []],
            'listUsers' => ['array', []],
            'removeDelegate' => ['void', ['delegatee' => 'string', 'delegator' => 'string']],
            'updateDelegate' => ['void', ['delegatee' => 'string', 'delegator' => 'string', 'readonly' => '?bool']],
        ];
        foreach ($signatures as $name => [$returnType, $parameters]) {
            $method = new ReflectionMethod(Ccs::class, $name);
            self::assertSame($returnType, (string) $method->getReturnType());
            self::assertSame(array_keys($parameters), array_map(
                static fn ($p): string => $p->getName(),
                $method->getParameters(),
            ));
            foreach ($method->getParameters() as $parameter) {
                self::assertSame($parameters[$parameter->getName()] ?? null, (string) $parameter->getType());
                self::assertSame($parameter->getName() === 'readonly', $parameter->isDefaultValueAvailable());
                self::assertSame([], $parameter->getAttributes());
                if ($parameter->isDefaultValueAvailable()) {
                    self::assertNull($parameter->getDefaultValue());
                }
            }
        }
    }

    public function testLiteralSpecsPinExistingLoweringWithoutCpdavdCorrections(): void
    {
        self::assertSame([
            'function' => 'add_delegate', 'module' => 'CCS', 'method' => 'POST', 'outcome' => 'flag',
            'params' => [
                'delegatee' => ['name' => 'delegatee', 'encoding' => 'scalar'],
                'delegator' => ['name' => 'delegator', 'encoding' => 'scalar'],
                'readonly' => ['name' => 'readonly', 'encoding' => 'bool'],
            ],
            'returns' => null,
        ], CcsSpec::ADD_DELEGATE);
        self::assertSame([
            'function' => 'list_delegates', 'module' => 'CCS', 'method' => 'GET', 'outcome' => 'flag',
            'params' => [],
            'returns' => ['type' => 'list', 'nullable' => true, 'items' => [
                'type' => 'object', 'fields' => [
                    'delegatee' => ['type' => 'string'], 'delegator' => ['type' => 'string'],
                    'read_only' => ['type' => 'flag'],
                ],
            ]],
        ], CcsSpec::LIST_DELEGATES);
        self::assertSame([
            'function' => 'list_users', 'module' => 'CCS', 'method' => 'GET', 'outcome' => 'flag',
            'params' => [], 'returns' => ['type' => 'map', 'values' => ['type' => 'mixed']],
        ], CcsSpec::LIST_USERS);
        self::assertSame([
            'function' => 'remove_delegate', 'module' => 'CCS', 'method' => 'POST', 'outcome' => 'flag',
            'params' => [
                'delegatee' => ['name' => 'delegatee', 'encoding' => 'scalar'],
                'delegator' => ['name' => 'delegator', 'encoding' => 'scalar'],
            ],
            'returns' => null,
        ], CcsSpec::REMOVE_DELEGATE);
        self::assertSame([
            'function' => 'update_delegate', 'module' => 'CCS', 'method' => 'POST', 'outcome' => 'flag',
            'params' => [
                'delegatee' => ['name' => 'delegatee', 'encoding' => 'scalar'],
                'delegator' => ['name' => 'delegator', 'encoding' => 'scalar'],
                'readonly' => ['name' => 'readonly', 'encoding' => 'bool'],
            ],
            'returns' => null,
        ], CcsSpec::UPDATE_DELEGATE);
    }

    /** @return array<string, CcsCall> */
    private static function operationCalls(): array
    {
        return [
            'add_delegate' => static function (Ccs $c): mixed {
                $c->addDelegate(
                    delegatee: 'Guest+%26&雪@Example.test',
                    delegator: 'Owner+%&ć@Example.test',
                    readonly: false,
                );
                return null;
            },
            'list_delegates' => static fn (Ccs $c): mixed => $c->listDelegates(),
            'list_users' => static fn (Ccs $c): mixed => $c->listUsers(),
            'remove_delegate' => static function (Ccs $c): mixed {
                $c->removeDelegate(
                    delegatee: 'Guest+%26&雪@Example.test',
                    delegator: 'Owner+%&ć@Example.test',
                );
                return null;
            },
            'update_delegate' => static function (Ccs $c): mixed {
                $c->updateDelegate(
                    delegatee: 'Guest+%26&雪@Example.test',
                    delegator: 'Owner+%&ć@Example.test',
                    readonly: false,
                );
                return null;
            },
        ];
    }

    /** @return iterable<string, array{string, CcsCall}> */
    public static function operationRoutes(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::operationCalls() as $function => $call) {
                yield "$route $function" => [$route, $call];
            }
        }
    }

    /** @return iterable<string, array{string, CcsCall}> */
    public static function bridgeOperations(): iterable
    {
        foreach (self::operationCalls() as $function => $call) {
            yield $function => ['bridge', $call];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=CCS&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/CCS/' . $function;
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Fixture warning"],"messages":null,"metadata":{}}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
