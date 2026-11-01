<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\WebProsMcp;
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
use SensitiveParameterValue;

/** Synthetic offline fixtures: returned authentication URLs and configuration stay inert. */
final class UapiWebProsMcpTest extends TestCase
{
    public function testSelectorIsLazyCachedAccountIsolatedAndMethodsAreParameterless(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('webProsMcp'));
        self::assertInstanceOf(WebProsMcp::class, $account->webProsMcp());
        self::assertSame($account->webProsMcp(), $account->webProsMcp());
        self::assertNotSame($account->webProsMcp(), $client->uapi('other')->webProsMcp());
        foreach (['getConnectionConfig', 'unlinkWebProsAccount'] as $name) {
            $method = new ReflectionMethod(WebProsMcp::class, $name);
            self::assertCount(0, $method->getParameters());
            self::assertSame('array', (string) $method->getReturnType());
        }
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    #[DataProvider('operations')]
    public function testEachOperationSendsOneEmptyFormPostWithLiteralRoutingAndFreshResults(
        string $route,
        string $method,
        string $function,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"is_linked":0}')));
        $http->addResponse(new Response(200, [], self::envelope($route, '{"is_linked":2}')));
        self::assertSame(['is_linked' => 0], $account->webProsMcp()->$method());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        $snapshot = $account->responseMeta();
        $account->webProsMcp();
        self::assertSame($snapshot, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=WebProsMCP&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/WebProsMCP/' . $function, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        // Formal integers have no enum. This does not claim that a server returns 2.
        self::assertSame(['is_linked' => 2], $account->webProsMcp()->$method());
        self::assertNotSame($snapshot, $account->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            yield "$route config" => [$route, 'getConnectionConfig', 'get_connection_config'];
            yield "$route unlink" => [$route, 'unlinkWebProsAccount', 'unlink_webpros_account'];
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('payloads')]
    public function testOptionalKnownFieldsAndNestedOauthCasingPreserveInertPrivateBytes(
        string $method,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', $payload, 1, true)));
        $account = $client->uapi();
        self::assertSame($expected, $account->webProsMcp()->$method());
        self::assertSame(['PRIVATE warning'], $account->responseMeta()?->uapiWarnings());
        self::assertSame(['private' => 'PRIVATE metadata'], $account->responseMeta()?->uapi['metadata'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function payloads(): iterable
    {
        yield 'full config and unknown filtering at every level' => ['getConnectionConfig',
            '{"is_linked":1,"link_url":"/openid_connect/webpros?x=%2F+š&y=PRIVATE",'
                . '"environment":"overridden","unknown":"PRIVATE",'
                . '"mcp_config":{"name":"PRIVATE š","type":"future","url":"javascript:inert() %2F+š",'
                . '"unknown":1,"oauth":{"clientId":"PRIVATE +%2F š","callbackPort":64474,'
                . '"client_id":"discard","callback_port":1,"unknown":true}}}',
            ['environment' => 'overridden', 'is_linked' => 1,
                'link_url' => '/openid_connect/webpros?x=%2F+š&y=PRIVATE',
                'mcp_config' => ['name' => 'PRIVATE š',
                    'oauth' => ['callbackPort' => 64474, 'clientId' => 'PRIVATE +%2F š'],
                    'type' => 'future', 'url' => 'javascript:inert() %2F+š']]];
        yield 'all empty strings and nonflag port without range guard' => ['getConnectionConfig',
            '{"link_url":"","environment":"","mcp_config":{"name":"","type":"","url":"",'
                . '"oauth":{"clientId":"","callbackPort":-1}}}',
            ['environment' => '', 'link_url' => '', 'mcp_config' => ['name' => '',
                'oauth' => ['callbackPort' => -1, 'clientId' => ''], 'type' => '', 'url' => '']]];
        yield 'minimal config omission' => ['getConnectionConfig', '{"is_linked":0}', ['is_linked' => 0]];
        yield 'present empty config object' => ['getConnectionConfig', '{"mcp_config":{}}', ['mcp_config' => []]];
        yield 'present empty oauth object' => ['getConnectionConfig', '{"mcp_config":{"oauth":{}}}',
            ['mcp_config' => ['oauth' => []]]];
        yield 'nested empty array compatibility' => ['getConnectionConfig', '{"mcp_config":{"oauth":[]}}',
            ['mcp_config' => ['oauth' => []]]];
        yield 'config empty array compatibility' => ['getConnectionConfig', '{"mcp_config":[]}', ['mcp_config' => []]];
        yield 'unlink filtering' => ['unlinkWebProsAccount', '{"is_linked":0,"unknown":1}', ['is_linked' => 0]];
        foreach (['getConnectionConfig', 'unlinkWebProsAccount'] as $method) {
            yield "$method empty object" => [$method, '{}', []];
            yield "$method empty array compatibility" => [$method, '[]', []];
        }
    }

    public function testBridgePreservesNestedConfigurationAndMismatchSnapshotAfterLaterSuccess(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope(
            'bridge',
            '{"mcp_config":{"oauth":{"clientId":"PRIVATE mismatch","callbackPort":"invalid"}}}',
            1,
            true,
        )));
        $http->addResponse(new Response(200, [], self::envelope(
            'bridge',
            '{"environment":"production","mcp_config":{"oauth":{"clientId":"PRIVATE next","callbackPort":0}}}',
        )));
        try {
            $account->webProsMcp()->getConnectionConfig();
            self::fail('Expected callbackPort string mismatch.');
        } catch (PayloadMismatchException $error) {
            $snapshot = $error->metadata;
            self::assertSame($snapshot, $account->responseMeta());
            self::assertSame(['mcp_config' => ['oauth' => [
                'clientId' => 'PRIVATE mismatch', 'callbackPort' => 'invalid',
            ]]], $error->payload);
            self::assertSame(['environment' => 'production', 'mcp_config' => [
                'oauth' => ['callbackPort' => 0, 'clientId' => 'PRIVATE next'],
            ]], $account->webProsMcp()->getConnectionConfig());
            self::assertNotSame($snapshot, $account->responseMeta());
            self::assertSame(['PRIVATE warning'], $error->metadata?->uapiWarnings());
            self::assertSame(['private' => 'PRIVATE metadata'], $error->metadata?->uapi['metadata'] ?? null);
        }
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('mismatches')]
    public function testRequiredRootsAndOptionalNonnullableFieldsRejectWrongRepresentations(
        string $method,
        ?string $payload,
    ): void {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', $payload)));
        try {
            $account->webProsMcp()->$method();
            self::fail('Expected strict payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function mismatches(): iterable
    {
        foreach (['getConnectionConfig', 'unlinkWebProsAccount'] as $method) {
            foreach ([null, 'null', '"object"', '1', 'true', '[{}]'] as $index => $wrong) {
                yield "$method root $index" => [$method, $wrong];
            }
            foreach (['null', 'true', '"0"', '0.0'] as $wrong) {
                yield "$method is_linked $wrong" => [$method, '{"is_linked":' . $wrong . '}'];
            }
        }
        $fields = [
            'link_url' => ['{"link_url":', '}'], 'environment' => ['{"environment":', '}'],
            'name' => ['{"mcp_config":{"name":', '}}'], 'type' => ['{"mcp_config":{"type":', '}}'],
            'url' => ['{"mcp_config":{"url":', '}}'],
            'clientId' => ['{"mcp_config":{"oauth":{"clientId":', '}}}'],
        ];
        foreach ($fields as $name => [$prefix, $suffix]) {
            foreach (['null', 'true', '1', '[]'] as $wrong) {
                yield "$name $wrong" => ['getConnectionConfig', $prefix . $wrong . $suffix];
            }
        }
        foreach (['null', 'false', '"64474"', '64474.0'] as $wrong) {
            yield "callbackPort $wrong" => ['getConnectionConfig',
                '{"mcp_config":{"oauth":{"callbackPort":' . $wrong . '}}}'];
        }
        foreach (['null', 'true', '"object"', '[{}]'] as $wrong) {
            yield "mcp_config $wrong" => ['getConnectionConfig', '{"mcp_config":' . $wrong . '}'];
            yield "oauth $wrong" => ['getConnectionConfig', '{"mcp_config":{"oauth":' . $wrong . '}}'];
        }
    }

    #[DataProvider('failures')]
    public function testFailurePrecedenceResetsMetadataKeepsSnapshotsAndNeverRetries(
        string $route,
        string $failure,
        string $method,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->webProsMcp()->getConnectionConfig();
        $before = $account->responseMeta();
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
                'api' => new Response(200, [], self::envelope($route, 'null', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"PRIVATE outer"},'
                    . '"data":{"uapi":{"status":1,"data":{"is_linked":1}}}}'),
                'outer-status' => new Response(200, [], '{"metadata":{"result":"1"},'
                    . '"data":{"uapi":{"status":1,"data":null}}}'),
                'status' => new Response(200, [], $route === 'bridge'
                    ? '{"metadata":{"result":1},"data":{"uapi":{"status":"1","data":null}}}'
                    : '{"result":{"status":"1","data":null}}'),
                default => new Response(200, [], self::envelope($route, '{"is_linked":"PRIVATE"}', 1, true)),
            };
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $account->webProsMcp()->$method();
            self::fail('The failing invocation must not retry.');
        } catch (ApiException $error) {
            self::assertSame(match ($failure) {
                'transport' => TransportException::class, 'http' => HttpException::class,
                'json', 'status', 'outer-status' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class, default => PayloadMismatchException::class,
            }, $error::class);
            if (in_array($failure, ['transport', 'http', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotSame($before, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(match ($failure) {
                    'api' => 0, 'status' => null, default => 1,
                }, $error->metadata?->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' && $failure !== 'outer-status' ? 1 : null),
                    $error->metadata?->whmResult(),
                );
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame(['is_linked' => 'PRIVATE'], $error->payload);
                self::assertSame(['PRIVATE warning'], $error->metadata?->uapiWarnings());
                self::assertSame(['private' => 'PRIVATE metadata'], $error->metadata?->uapi['metadata'] ?? null);
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
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
                        self::hasPrivateValue($frame['args'] ?? [], $seen),
                        $class . '::' . $frame['function'],
                    );
                }
            }
            self::assertGreaterThan(0, $frames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertTrue($clearedAtIo);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                ['transport', 'http', 'json', 'api', 'status', 'payload',
                ...($route === 'bridge' ? ['outer', 'outer-status'] : [])] as $failure
            ) {
                foreach (['getConnectionConfig', 'unlinkWebProsAccount'] as $method) {
                    yield "$route $failure $method" => [$route, $failure, $method];
                }
            }
        }
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

    private static function envelope(string $route, ?string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["PRIVATE error"]')
            . ',"warnings":' . ($warning ? '["PRIVATE warning"]' : 'null')
            . ',"metadata":' . ($warning ? '{"private":"PRIVATE metadata"}' : '{}') . '}';
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
