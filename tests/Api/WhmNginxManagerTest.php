<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Independent literals reviewed against the pinned NGINX Manager source. */
final class WhmNginxManagerTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<mixed>, string, string}> */
    public static function requests(): iterable
    {
        yield 'clear repeated' => ['clearCache', 'nginxmanager_clear_cache', [['one', 'two +&=雪']], 'POST',
            'user=one&user=two%20%2B%26%3D%E9%9B%AA'];
        yield 'clear omitted all users' => ['clearCache', 'nginxmanager_clear_cache', [], 'POST', ''];
        yield 'clear null all users' => ['clearCache', 'nginxmanager_clear_cache', [null], 'POST', ''];
        yield 'system omitted merge' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system', [], 'GET', ''];
        yield 'system merge' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system', [1], 'GET', 'merge=1'];
        yield 'users merge and repeated' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            [1, ['one', 'two']], 'GET', 'merge=1&user=one&user=two'];
        yield 'users scalar encoded once' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            [null, 'one +&=%/雪'], 'GET', 'user=one%20%2B%26%3D%25%2F%E9%9B%AA'];
        yield 'users empty scalar' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            [null, ''], 'GET', 'user='];
        yield 'users omitted all users' => [
            'getUsersCacheConfig', 'nginxmanager_get_cache_config_users', [], 'GET', ''];
        yield 'rebuild repeated' => ['rebuildCacheConfig', 'nginxmanager_rebuild_cache_config',
            [['one', 'two']], 'POST', 'user=one&user=two'];
        yield 'rebuild omitted all users' => [
            'rebuildCacheConfig', 'nginxmanager_rebuild_cache_config', [], 'POST', ''];
        yield 'reset scalar' => [
            'resetUsersCacheConfig', 'nginxmanager_reset_users_cache_config', ['one'], 'POST', 'user=one'];
        yield 'reset repeated' => ['resetUsersCacheConfig', 'nginxmanager_reset_users_cache_config',
            [['one', 'two']], 'POST', 'user=one&user=two'];
        yield 'reset omitted all users' => [
            'resetUsersCacheConfig', 'nginxmanager_reset_users_cache_config', [], 'POST', ''];
        yield 'disable repeated' => ['setCacheConfig', 'nginxmanager_set_cache_config',
            [false, ['one', 'two']], 'POST', 'enabled=0&user=one&user=two'];
        yield 'enable scalar' => [
            'setCacheConfig', 'nginxmanager_set_cache_config', [true, 'one'], 'POST', 'enabled=1&user=one'];
        yield 'disable omitted all users' => [
            'setCacheConfig', 'nginxmanager_set_cache_config', [false], 'POST', 'enabled=0'];
        yield 'enable null all users' => [
            'setCacheConfig', 'nginxmanager_set_cache_config', [true, null], 'POST', 'enabled=1'];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('requests')]
    public function testExactRequestPreservesRepeatedUsersOmissionAndFalse(
        string $method,
        string $operation,
        array $arguments,
        string $verb,
        string $parameters,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $verb === 'GET' ? '{}' : null)));
        self::assertSame($verb === 'GET' ? [] : null, $client->whm()->nginxManager()->{$method}(...$arguments));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
            . ($verb === 'GET' && $parameters !== '' ? '&' . $parameters : ''), (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame($operation, $client->whm()->responseMeta()?->whm['command'] ?? null);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validPayloads(): iterable
    {
        yield 'system complete source example' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system',
            '{"default":{"enabled":1,"inactive_time":"60m","levels":62,"logging":0,'
            . '"proxy_cache_background_update":"on","proxy_cache_lock":"on","proxy_cache_min_uses":1,'
            . '"proxy_cache_revalidate":"on","proxy_cache_use_stale":"error timeout http_429 http_500 http_502 '
            . 'http_503 http_504","x_cache_header":0,"zone_size":"10m"},'
            . '"system":{"enabled":true,"inactive_time":"60m","levels":62,"logging":false,'
            . '"proxy_cache_background_update":"on","proxy_cache_lock":"on","proxy_cache_min_uses":1,'
            . '"proxy_cache_revalidate":"on","proxy_cache_use_stale":"error timeout http_429 http_500 http_502 '
            . 'http_503 http_504","x_cache_header":0,"zone_size":"10m"}}'];
        yield 'system disabled types' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system',
            '{"default":{"enabled":0},"system":{"enabled":false}}'];
        yield 'system opaque dynamic values' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system',
            '{"default":{"future.option":{"nested":[false,1.5,null]}},"system":{"future":null,"empty":""}}'];
        yield 'system empty sections' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system',
            '{"default":{},"system":[]}'];
        yield 'system omitted sections' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system', '{}'];
        yield 'system empty root array compatibility' => [
            'getSystemCacheConfig', 'nginxmanager_get_cache_config_system', '[]'];
        yield 'users complete source row and inverse flags' => [
            'getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            '{"users":[{"config":{"enabled":true,"inactive_time":"60m","levels":62,"logging":false,'
            . '"proxy_cache_background_update":"on","proxy_cache_lock":"on","proxy_cache_min_uses":1,'
            . '"proxy_cache_revalidate":"on","proxy_cache_use_stale":"error timeout http_429 http_500 http_502 '
            . 'http_503 http_504","x_cache_header":false,"zone_size":"10m"}'
            . ',"merged":0,"owner":"root","user":"cptest1"},'
            . '{"config":{"enabled":false},"merged":1,"owner":"reseller","user":"cptest2"}]}'];
        yield 'users dynamic config values' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            '{"users":[{"config":{"future.option":{"nested":[false,1.5,null]},"empty":""}}]}'];
        yield 'users optional fields and empty array objects' => [
            'getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            '{"users":[{},[],{"config":[]}]}'];
        yield 'users empty list' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users', '{"users":[]}'];
        yield 'users omitted list' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users', '{}'];
        yield 'users root array compatibility' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users', '[]'];
    }

    #[DataProvider('validPayloads')]
    public function testSourceConfigurationAndDynamicValuesRemainUnchanged(
        string $method,
        string $operation,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->nginxManager()->{$method}(),
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (
            ['getSystemCacheConfig' => 'nginxmanager_get_cache_config_system',
            'getUsersCacheConfig' => 'nginxmanager_get_cache_config_users'] as $method => $operation
        ) {
            foreach ([null, 'null', 'false', '1', '1.5', '"text"', '[{}]'] as $index => $payload) {
                yield "$method root $index" => [$method, $operation, $payload];
            }
        }
        foreach (['default', 'system'] as $section) {
            foreach (['null', 'true', '1', '"text"', '[{}]'] as $index => $value) {
                yield "$section object $index" => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system',
                    '{"' . $section . '":' . $value . '}'];
            }
            $badFlags = $section === 'default' ? ['null', 'true', '2', '"0"', '"1"', '0.0', '[]', '{}']
                : ['null', '0', '1', '"false"', '"1"', '[]', '{}'];
            foreach ($badFlags as $index => $value) {
                yield "$section enabled $index" => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system',
                    '{"' . $section . '":{"enabled":' . $value . '}}'];
            }
        }
        foreach (['null', 'false', '1', '"text"', '{}', '{"one":{}}'] as $index => $value) {
            yield "users list $index" => [
                'getUsersCacheConfig', 'nginxmanager_get_cache_config_users', '{"users":' . $value . '}'];
        }
        foreach (['null', 'false', '1', '"text"', '[{}]'] as $index => $value) {
            yield "user row $index" => [
                'getUsersCacheConfig', 'nginxmanager_get_cache_config_users', '{"users":[' . $value . ']}'];
            yield "user config $index" => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
                '{"users":[{"config":' . $value . '}]}'];
        }
        foreach (
            ['config' => ['{"enabled":null}', '{"enabled":0}', '{"enabled":1}', '{"enabled":"true"}'],
            'merged' => ['null', 'true', '2', '"0"', '0.0', '[]', '{}'],
            'owner' => ['null', 'false', '1', '[]', '{}'],
            'user' => ['null', 'false', '1', '[]', '{}']] as $field => $values
        ) {
            foreach ($values as $index => $value) {
                yield "user field $field $index" => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
                    '{"users":[{"' . $field . '":' . $value . '}]}'];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadKeepsConfirmedFreshMetadataAndNeverRetries(
        string $method,
        string $operation,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('nginxmanager_get_cache_config_system', '{}')));
        $whm->nginxManager()->getSystemCacheConfig();
        $previous = $whm->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $whm->nginxManager()->{$method}();
            self::fail('Malformed NGINX payload must fail its contract.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($operation, $error->metadata->whm['command'] ?? null);
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>}> */
    public static function operations(): iterable
    {
        yield 'clear' => ['clearCache', 'nginxmanager_clear_cache', ['one']];
        yield 'system read' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system', [1]];
        yield 'users read' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users', [1, 'one']];
        yield 'rebuild' => ['rebuildCacheConfig', 'nginxmanager_rebuild_cache_config', ['one']];
        yield 'reset' => ['resetUsersCacheConfig', 'nginxmanager_reset_users_cache_config', ['one']];
        yield 'set' => ['setCacheConfig', 'nginxmanager_set_cache_config', [false, 'one']];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiErrorsPrecedePayloadValidationWithoutRetry(
        string $method,
        string $operation,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, 'false', 0)));
        try {
            $client->whm()->nginxManager()->{$method}(...$arguments);
            self::fail('Failed WHM outcome must be reported.');
        } catch (ApiErrorException $error) {
            self::assertSame(['Synthetic failure'], $error->errors());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function invalidLists(): iterable
    {
        $methods = [
            'clearCache', 'getUsersCacheConfig', 'rebuildCacheConfig', 'resetUsersCacheConfig', 'setCacheConfig',
        ];
        $invalid = [[], ['named' => 'one'], [1 => 'one'], ['one', 7], ['one', false], [['nested']], [null]];
        foreach ($methods as $method) {
            foreach ($invalid as $index => $users) {
                yield "$method list $index" => [$method, match ($method) {
                    'getUsersCacheConfig' => [null, $users], 'setCacheConfig' => [false, $users], default => [$users],
                }];
            }
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidLists')]
    public function testInvalidListsResetMetadataBeforeRejectionWithoutDispatch(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('nginxmanager_get_cache_config_system', '{}')));
        $whm->nginxManager()->getSystemCacheConfig();
        self::assertNotNull($whm->responseMeta());
        try {
            $whm->nginxManager()->{$method}(...$arguments);
            self::fail('User lists must be nonempty sequential string lists.');
        } catch (InvalidArgumentException) {
            self::assertNull($whm->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    private static function envelope(string $operation, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"'
            . ($result === 1 ? 'OK' : 'Synthetic failure') . '","result":' . $result . ',"version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
