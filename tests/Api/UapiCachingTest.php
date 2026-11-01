<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\CacheBuster;
use HostingPal\WhmApiClient\Api\Uapi\NginxCaching;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Independent literal HTTP fixtures for account browser-version and NGINX caching.
 *
 * @phpstan-type CacheCall Closure(UapiClient): mixed
 */
final class UapiCachingTest extends TestCase
{
    #[DataProvider('routes')]
    public function testAccessorsCachePerAccountWithoutIoOrMetadataReset(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $other = $route === 'bridge' ? $client->uapi('other') : self::client($route)[0]->uapi('exampleuser');
        self::assertInstanceOf(CacheBuster::class, $account->cacheBuster());
        self::assertInstanceOf(NginxCaching::class, $account->nginxCaching());
        self::assertSame($account->cacheBuster(), $account->cacheBuster());
        self::assertSame($account->nginxCaching(), $account->nginxCaching());
        self::assertNotSame($account->cacheBuster(), $other->cacheBuster());
        self::assertNotSame($account->nginxCaching(), $other->nginxCaching());
        self::assertSame([], $http->getRequests());
        self::assertNull($account->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->cacheBuster()->read();
        $previous = $account->responseMeta();
        $account->cacheBuster();
        $account->nginxCaching();
        self::assertSame($previous, $account->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @param CacheCall $call */
    #[DataProvider('operationRoutes')]
    public function testParameterlessCallsSendExactlyOneCorrectlyRoutedRequest(
        string $route,
        string $module,
        string $function,
        string $method,
        Closure $call,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $payload = $module === 'CacheBuster' ? '{"cache_id":27819453}' : '{"opaque":[false,0,null]}';
        $expected = $module === 'CacheBuster' ? ['cache_id' => 27819453] : ['opaque' => [false, 0, null]];
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $call($account));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                    . '&cpanel.module=' . $module . '&cpanel.function=' . $function
                : 'https://api.example.test:2083/execute/' . $module . '/' . $function,
            (string) $request->getUri(),
        );
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @param array<string, int> $expected */
    #[DataProvider('cacheIds')]
    public function testCacheIdsKeepOptionalPresenceAndStrictIntegerValues(
        string $route,
        string $method,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $group = $client->uapi('exampleuser')->cacheBuster();
        self::assertSame($expected, $method === 'read' ? $group->read() : $group->update());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<string, int>}> */
    public static function cacheIds(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['read', 'update'] as $method) {
                foreach (
                    [
                    'optional object' => ['{}', []],
                    'legacy empty array' => ['[]', []],
                    'populated' => ['{"cache_id":27819453}', ['cache_id' => 27819453]],
                    'zero without invented range' => ['{"cache_id":0}', ['cache_id' => 0]],
                    'negative without invented range' => ['{"cache_id":-1}', ['cache_id' => -1]],
                    'large without invented range' => ['{"cache_id":100000000}', ['cache_id' => 100000000]],
                    ] as $name => [$payload, $expected]
                ) {
                    yield $route . ' ' . $method . ' ' . $name => [$route, $method, $payload, $expected];
                }
            }
        }
    }

    /** @param CacheCall $call */
    #[DataProvider('invalidPayloads')]
    public function testInvalidOrAbsentPayloadRetainsConfirmedOutcomeAndFreshMetadata(
        string $route,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->cacheBuster()->read();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account);
            self::fail('Invalid or absent payload must throw.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(json_decode($payload ?? 'null', true, 512, JSON_THROW_ON_ERROR), $error->payload);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, CacheCall, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operationRoutes() as $name => [$route, $module, , , $call]) {
            $values = $module === 'CacheBuster'
                ? [null, 'null', 'false', '0', '1.5', '"id"', '[{}]', '{"cache_id":"27819453"}',
                    '{"cache_id":1.5}', '{"cache_id":true}', '{"cache_id":false}',
                    '{"cache_id":null}', '{"cache_id":[]}',
                    '{"cache_id":{}}']
                : [null];
            foreach ($values as $index => $payload) {
                yield $name . ' ' . $index => [$route, $call, $payload];
            }
        }
    }

    /** @param CacheCall $call */
    #[DataProvider('opaquePayloads')]
    public function testNginxPreservesEveryPresentJsonValue(string $route, Closure $call, string $payload): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $call($client->uapi('exampleuser')));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, CacheCall, string}> */
    public static function opaquePayloads(): iterable
    {
        foreach (self::operationRoutes() as $name => [$route, $module, , , $call]) {
            if ($module !== 'NginxCaching') {
                continue;
            }
            foreach (
                ['null', 'false', 'true', '0', '-19', '1.25', '""', '"unconstrained"', '[]', '{}',
                '[{"nested":[null,false,0,"0",1.5]},[]]',
                '{"unknown":{"values":[null,false,0,"0",1.5],"empty":{}},"0":"numeric key","01":[]}'] as $payload
            ) {
                yield $name . ' ' . $payload => [$route, $call, $payload];
            }
        }
    }

    /** @param CacheCall $call */
    #[DataProvider('operationRoutes')]
    public function testApiFailureWinsBeforePayloadValidationWithoutRetry(
        string $route,
        string $module,
        string $function,
        string $method,
        Closure $call,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->cacheBuster()->read();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            $call($account);
            self::fail('API failure must throw before checking missing data.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param CacheCall $call */
    #[DataProvider('operations')]
    public function testOuterWhmFailureWinsOverInnerSuccess(
        string $module,
        string $function,
        string $method,
        Closure $call,
    ): void {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Outer denial"},'
            . '"data":{"uapi":{"status":1,"data":null}}}'));
        try {
            $call($account);
            self::fail('Outer failure must win over inner success.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['Outer denial'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param CacheCall $call */
    #[DataProvider('operationRoutes')]
    public function testHttpAndTransportFailuresClearMetadataAndNeverRetry(
        string $route,
        string $module,
        string $function,
        string $method,
        Closure $call,
    ): void {
        foreach (['http', 'transport'] as $failure) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('exampleuser');
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->cacheBuster()->read();
            self::assertNotNull($account->responseMeta());
            if ($failure === 'http') {
                $http->addResponse(new Response(503, [], 'unavailable'));
            } else {
                $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
                });
            }
            try {
                $call($account);
                self::fail('Failure must throw.');
            } catch (HttpException | TransportException $error) {
                self::assertSame($failure === 'transport', $error instanceof TransportException);
                self::assertNull($error->metadata);
                self::assertNull($account->responseMeta());
            }
            self::assertCount(2, $http->getRequests());
        }
    }

    public function testPublicMethodsAreParameterlessWithNativeReturns(): void
    {
        foreach (
            [
                CacheBuster::class => ['read', 'update'],
                NginxCaching::class => ['clearCache', 'disableCache', 'enableCache', 'resetCacheConfig'],
            ] as $class => $names
        ) {
            foreach ($names as $name) {
                $method = new ReflectionMethod($class, $name);
                self::assertTrue($method->isPublic());
                self::assertSame([], $method->getParameters());
                self::assertSame($class === CacheBuster::class ? 'array' : 'mixed', (string) $method->getReturnType());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string, string, string, CacheCall}> */
    public static function operations(): iterable
    {
        yield 'read browser version' => ['CacheBuster', 'read', 'GET',
            static fn (UapiClient $a): array => $a->cacheBuster()->read()];
        yield 'update browser version' => ['CacheBuster', 'update', 'POST',
            static fn (UapiClient $a): array => $a->cacheBuster()->update()];
        yield 'clear NGINX contents' => ['NginxCaching', 'clear_cache', 'POST',
            static fn (UapiClient $a): mixed => $a->nginxCaching()->clearCache()];
        yield 'disable NGINX caching' => ['NginxCaching', 'disable_cache', 'POST',
            static fn (UapiClient $a): mixed => $a->nginxCaching()->disableCache()];
        yield 'enable NGINX caching' => ['NginxCaching', 'enable_cache', 'POST',
            static fn (UapiClient $a): mixed => $a->nginxCaching()->enableCache()];
        yield 'reset NGINX config' => ['NginxCaching', 'reset_cache_config', 'POST',
            static fn (UapiClient $a): mixed => $a->nginxCaching()->resetCacheConfig()];
    }

    /** @return iterable<string, array{string, string, string, string, CacheCall}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => $case) {
                yield $route . ' ' . $name => [$route, ...$case];
            }
        }
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic cache failure"]') . '}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
