<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\ServiceProxy;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Literal source-authored oracles; all addresses and responses are synthetic, with no live acceptance claim. */
final class UapiServiceProxyTest extends TestCase
{
    public function testServiceProxyAccessorIsAvailable(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertSame($account->serviceProxy(), $account->serviceProxy());
        self::assertNotSame($account->serviceProxy(), $client->uapi('other')->serviceProxy());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        foreach (['general', 'serviceGroupBackend'] as $name) {
            self::assertCount(1, (new ReflectionParameter([ServiceProxy::class, 'setServiceProxyBackends'], $name))
                ->getAttributes(SensitiveParameter::class));
        }
        foreach (['backends', 'general'] as $name) {
            self::assertCount(1, (new ReflectionParameter([ServiceProxy::class, 'setBackends'], $name))
                ->getAttributes(SensitiveParameter::class));
        }
        self::assertCount(0, (new ReflectionParameter([ServiceProxy::class, 'setServiceProxyBackends'], 'serviceGroup'))
            ->getAttributes(SensitiveParameter::class));
        $parameters = (new ReflectionMethod(ServiceProxy::class, 'setServiceProxyBackends'))->getParameters();
        self::assertSame(['general', 'serviceGroup', 'serviceGroupBackend'], array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            $parameters,
        ));
        foreach ($parameters as $parameter) {
            self::assertTrue($parameter->isDefaultValueAvailable());
            self::assertNull($parameter->getDefaultValue());
        }
    }

    /** @param Closure(ServiceProxy): mixed $call */
    #[DataProvider('requests')]
    public function testExactRequestBytesAndRouting(
        string $route,
        Closure $call,
        string $function,
        string $body,
    ): void {
        [$client, $http] = self::client($route);
        $payload = $function === 'get_service_proxy_backends' ? '[]' : 'null';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('fixture');
        self::assertSame($function === 'get_service_proxy_backends' ? [] : null, $call($account->serviceProxy()));
        self::assertRequest($http, $route, $function, $body);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, Closure(ServiceProxy): mixed, string, string}> */
    public static function requests(): iterable
    {
        $set = 'set_service_proxy_backends';
        $cases = [
            'inventory' => [static fn (ServiceProxy $g): array => $g->getServiceProxyBackends(),
                'get_service_proxy_backends', ''],
            'unset all' => [static fn (ServiceProxy $g): ?array => $g->unsetAllServiceProxyBackends(),
                'unset_all_service_proxy_backends', ''],
            'canonical omitted' => [static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends(), $set, ''],
            'canonical null' => [static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends(
                serviceGroupBackend: null,
                serviceGroup: null,
                general: null,
            ), $set, ''],
            'general only' => [
                static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends(general: 'mx.example.test'),
                $set, 'general=mx.example.test'],
            'empty general only' => [static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends(general: ''),
                $set, 'general='],
            'all empty' => [static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends('', '', ''),
                $set, 'general=&service_group=&service_group_backend='],
            'canonical named order and exact bytes' => [
                static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends(
                    serviceGroupBackend: 'mx+%&= /雪',
                    serviceGroup: 'mAiL',
                    general: 'gen+% /雪',
                ), $set, 'general=gen%2B%25%20%2F%E9%9B%AA&service_group=mAiL'
                    . '&service_group_backend=mx%2B%25%26%3D%20%2F%E9%9B%AA'],
            'canonical group only forwards source condition' => [
                static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends(serviceGroup: 'Other'),
                $set, 'service_group=Other'],
            'canonical backend only forwards source condition' => [
                static fn (ServiceProxy $g): ?array => $g->setServiceProxyBackends(serviceGroupBackend: '127.0.0.1'),
                $set, 'service_group_backend=127.0.0.1'],
            'one row' => [static fn (ServiceProxy $g): ?array => $g->setBackends([
                ['serviceGroup' => 'Mail', 'backend' => 'mx1'],
            ]), $set, 'service_group=Mail&service_group_backend=mx1'],
            'two duplicate groups' => [static fn (ServiceProxy $g): ?array => $g->setBackends([
                ['serviceGroup' => 'Mail', 'backend' => 'mx1'], ['serviceGroup' => 'Mail', 'backend' => 'mx2'],
            ]), $set, 'service_group=Mail&service_group_backend=mx1&service_group-1=Mail&service_group_backend-1=mx2'],
            'three rows preserve declared field order and general last' => [
                static fn (ServiceProxy $g): ?array => $g->setBackends(general: 'g+%', backends: [
                    ['backend' => 'mx+% /雪', 'serviceGroup' => 'mAiL'],
                    ['serviceGroup' => '', 'backend' => ''], ['backend' => 'mx3', 'serviceGroup' => 'Other'],
                ]), $set, 'service_group=mAiL&service_group_backend=mx%2B%25%20%2F%E9%9B%AA'
                    . '&service_group-1=&service_group_backend-1=&service_group-2=Other'
                    . '&service_group_backend-2=mx3&general=g%2B%25'],
            'explicit alias null general' => [static fn (ServiceProxy $g): ?array => $g->setBackends([
                ['serviceGroup' => 'Mail', 'backend' => 'mx1'],
            ], null), $set, 'service_group=Mail&service_group_backend=mx1'],
            'empty alias general' => [static fn (ServiceProxy $g): ?array => $g->setBackends([
                ['serviceGroup' => 'Mail', 'backend' => 'mx1'],
            ], ''), $set, 'service_group=Mail&service_group_backend=mx1&general='],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => [$call, $function, $body]) {
                yield "$route $name" => [$route, $call, $function, $body];
            }
        }
    }

    #[DataProvider('payloads')]
    public function testSourceResponseShapesRemainStrictAndPreservePresence(
        string $route,
        string $method,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($client->uapi('fixture')->serviceProxy(), $method));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function payloads(): iterable
    {
        $reads = [
            ['[]', []], ['[{}]', [[]]],
            ['[{"service_group":null,"backend":"mx1"},{"backend":""},{"service_group":"Mail"},{}]',
                [['backend' => 'mx1', 'service_group' => null], ['backend' => ''], ['service_group' => 'Mail'], []]],
            ['[{"backend":"mx2","service_group":"Other"},{"backend":"mx1","service_group":"Mail"}]',
                [['backend' => 'mx2', 'service_group' => 'Other'], ['backend' => 'mx1', 'service_group' => 'Mail']]],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::methods() as $method) {
                $cases = $method === 'getServiceProxyBackends' ? $reads : [
                    ['null', null], ['{}', []],
                    // The object validator accepts empty arrays; decoding {} still produces a distinct JSON object.
                    ['[]', []], ['{"opaque":{"zero":0,"none":null,"empty":[]},"future":"synthetic"}',
                        ['opaque' => ['zero' => 0, 'none' => null, 'empty' => []], 'future' => 'synthetic']],
                ];
                foreach ($cases as $index => [$payload, $expected]) {
                    yield "$route $method $index" => [$route, $method, $payload, $expected];
                }
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidOrAbsentPayloadFailsAfterAcceptedOutcome(
        string $route,
        string $method,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account->serviceProxy(), $method);
            self::fail('Invalid or missing payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::methods() as $method) {
                $cases = [null, 'true', '0', '1.5', '"backend"', '[null]', '[{}]'];
                if ($method === 'getServiceProxyBackends') {
                    $cases = [null, 'null', '{}', 'true', '0', '1.5', '"backend"', '[null]', '[0]',
                        '[{"backend":null}]', '[{"backend":1}]', '[{"backend":false}]', '[{"backend":[]}]',
                        '[{"service_group":1}]', '[{"service_group":false}]', '[{"service_group":[]}]'];
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $method $index" => [$route, $method, $payload];
                }
            }
        }
    }

    #[DataProvider('invalidRecords')]
    public function testInvalidCollectionClearsMetadataAndNeverDispatches(string $route, mixed $rows): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->serviceProxy()->getServiceProxyBackends();
        self::assertNotNull($account->responseMeta());
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            (new ReflectionMethod(ServiceProxy::class, 'setBackends'))->invoke($account->serviceProxy(), $rows);
            self::fail('Malformed collection must fail locally.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($account->responseMeta());
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($class, __NAMESPACE__ . '\\')
                ) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(
                        self::hasSecret($frame['args'] ?? [], $seen),
                        $frame['class'] . '::' . $frame['function'],
                    );
                }
            }
            self::assertGreaterThan(0, $frames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidRecords(): iterable
    {
        $good = ['serviceGroup' => 'Mail', 'backend' => 'PRIVATE'];
        $cases = [
        [], [1 => $good], [0 => $good, 2 => $good], ['PRIVATE' => $good],
            [$good, null], [$good, false], [$good, 'PRIVATE'], [$good, []], [$good, ['PRIVATE']],
            [$good, ['serviceGroup' => 'Mail']], [$good, ['backend' => 'PRIVATE']],
            [$good, $good + ['PRIVATE' => 'PRIVATE']], [$good, [0 => 'PRIVATE'] + $good]];
        foreach (['serviceGroup', 'backend'] as $field) {
            foreach ([null, false, true, 0, 1, 1.5, [], ['PRIVATE'], new \stdClass()] as $value) {
                $row = $good;
                $row[$field] = $value;
                $cases[] = [$good, $row];
            }
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $rows) {
                yield "$route $index" => [$route, $rows];
            }
        }
    }

    #[DataProvider('routes')]
    public function testNativeTypeErrorsRetainMetadataBeforeMethodEntry(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->serviceProxy()->getServiceProxyBackends();
        $before = $account->responseMeta();
        foreach (['setBackends', 'setServiceProxyBackends'] as $method) {
            $invalid = $method === 'setBackends' ? [null, '', false, 0, 1.5, new \stdClass()] : [[], new \stdClass()];
            foreach ($invalid as $value) {
                try {
                    (new ReflectionMethod(ServiceProxy::class, $method))->invoke($account->serviceProxy(), $value);
                    self::fail('Native argument type mismatch must fail before method entry.');
                } catch (TypeError) {
                    self::assertSame($before, $account->responseMeta());
                }
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    #[DataProvider('outcomes')]
    public function testApiFailureRejectsValidPayloadAndSuccessWarningsRemainMetadata(
        string $route,
        string $method,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $payload = $method === 'getServiceProxyBackends' ? '[]' : '{"opaque":true}';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 0)));
        try {
            self::invoke($account->serviceProxy(), $method);
            self::fail('Status zero is failure even with a valid payload.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        self::assertSame(
            $method === 'getServiceProxyBackends' ? [] : ['opaque' => true],
            self::invoke($account->serviceProxy(), $method),
        );
        $meta = $account->responseMeta();
        self::assertSame(['Synthetic rebuild failure'], $meta?->uapiWarnings());
        self::assertSame(['rebuild_failed' => true], $meta->uapi['metadata'] ?? null);
        self::assertArrayNotHasKey('data', $meta->uapi);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function outcomes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::methods() as $method) {
                yield "$route $method" => [$route, $method];
            }
        }
    }

    #[DataProvider('failures')]
    public function testRunnerFailuresResetMetadataPreservePrecedenceAndHideSensitiveFrames(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->serviceProxy()->getServiceProxyBackends();
        $previous = $account->responseMeta();
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $http->addResponse(match ($failure) {
                'http' => new Response(503, [], 'not json'),
                'json' => new Response(200, [], 'not json'),
                'api' => new Response(200, [], self::envelope($route, 'null', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic outer failure"},'
                    . '"data":{"uapi":{"status":1,"data":null}}}'),
                default => new Response(200, [], self::envelope($route, '"PRIVATE"')),
            });
        }
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($account->serviceProxy(), $method);
            self::fail('Failure must throw without a retry or fallback.');
        } catch (ApiException $error) {
            $expected = match ($failure) {
                'transport' => TransportException::class,
                'http' => HttpException::class,
                'json' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class,
                default => PayloadMismatchException::class,
            };
            self::assertInstanceOf($expected, $error);
            if (in_array($failure, ['transport', 'http', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotSame($previous, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame($failure === 'api' ? 0 : 1, $error->metadata?->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' ? 1 : null),
                    $error->metadata->whmResult(),
                );
            }
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($class, __NAMESPACE__ . '\\')
                ) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(
                        self::hasSecret($frame['args'] ?? [], $seen),
                        $frame['class'] . '::' . $frame['function'],
                    );
                }
            }
            self::assertGreaterThan(0, $frames);
            if ($error instanceof PayloadMismatchException) {
                self::assertSame('PRIVATE', $error->payload);
                self::assertTrue($error->outcomeConfirmed);
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(2, $http->getRequests());
        self::assertRequest(
            $http,
            $route,
            match ($method) {
                'getServiceProxyBackends' => 'get_service_proxy_backends',
                'unsetAllServiceProxyBackends' => 'unset_all_service_proxy_backends',
                default => 'set_service_proxy_backends',
            },
            match ($method) {
                'setServiceProxyBackends' => 'general=PRIVATE&service_group=Mail&service_group_backend=PRIVATE',
                'setBackends' => 'service_group=Mail&service_group_backend=PRIVATE&general=PRIVATE',
                default => '',
            },
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::methods() as $method) {
                $failures = ['transport', 'http', 'json', 'api', 'payload', ...($route === 'bridge' ? ['outer'] : [])];
                foreach ($failures as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, array &$seen): bool
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
                if (str_contains((string) $key, 'PRIVATE') || self::hasSecret($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function methods(): array
    {
        return ['getServiceProxyBackends', 'setServiceProxyBackends', 'unsetAllServiceProxyBackends', 'setBackends'];
    }

    private static function invoke(ServiceProxy $group, string $method): mixed
    {
        return match ($method) {
            'getServiceProxyBackends' => $group->getServiceProxyBackends(),
            'setServiceProxyBackends' => $group->setServiceProxyBackends('PRIVATE', 'Mail', 'PRIVATE'),
            'unsetAllServiceProxyBackends' => $group->unsetAllServiceProxyBackends(),
            default => $group->setBackends([['serviceGroup' => 'Mail', 'backend' => 'PRIVATE']], 'PRIVATE'),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $function, string $body): void
    {
        $post = $function !== 'get_service_proxy_backends';
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=ServiceProxy&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/ServiceProxy/' . $function, (string) $request->getUri());
        self::assertSame($post ? $body : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame($post ? 'application/x-www-form-urlencoded' : '', $request->getHeaderLine('Content-Type'));
    }

    private static function envelope(string $route, ?string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"messages":null,"warnings":' . ($warning ? '["Synthetic rebuild failure"]' : 'null')
            . ',"metadata":' . ($warning ? '{"rebuild_failed":true}' : '{}') . '}';
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
