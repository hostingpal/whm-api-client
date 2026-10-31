<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Whm\Spec\NginxManagerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Fixtures\WeakNginxCaller;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TypeError;

/** Independent literals reviewed against the pinned NGINX Manager source. */
final class WhmNginxManagerTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<mixed>, string, string}> */
    public static function requests(): iterable
    {
        yield 'clear repeated' => ['clearCache', 'nginxmanager_clear_cache', [['one', 'two +&=雪']], 'POST',
            'user=one&user=two%20%2B%26%3D%E9%9B%AA'];
        yield 'system omitted merge' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system', [], 'GET', ''];
        yield 'system merge' => [
            'getSystemCacheConfig', 'nginxmanager_get_cache_config_system', [true], 'GET', 'merge=1'];
        yield 'users merge and repeated' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            [['one', 'two'], true], 'GET', 'user=one&user=two&merge=1'];
        yield 'users scalar encoded once' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            ['one +&=%/雪'], 'GET', 'user=one%20%2B%26%3D%25%2F%E9%9B%AA'];
        yield 'users empty scalar' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users',
            [''], 'GET', 'user='];
        yield 'users explicit null all users' => [
            'getUsersCacheConfig', 'nginxmanager_get_cache_config_users', [null, null], 'GET', ''];
        yield 'users omitted all users' => [
            'getUsersCacheConfig', 'nginxmanager_get_cache_config_users', [], 'GET', ''];
        yield 'rebuild repeated' => ['rebuildCacheConfig', 'nginxmanager_rebuild_cache_config',
            [['one', 'two']], 'POST', 'user=one&user=two'];
        yield 'reset scalar' => [
            'resetUsersCacheConfig', 'nginxmanager_reset_users_cache_config', ['one'], 'POST', 'user=one'];
        yield 'reset repeated' => ['resetUsersCacheConfig', 'nginxmanager_reset_users_cache_config',
            [['one', 'two']], 'POST', 'user=one&user=two'];
        yield 'disable repeated' => ['setCacheConfig', 'nginxmanager_set_cache_config',
            [false, ['one', 'two']], 'POST', 'enabled=0&user=one&user=two'];
        yield 'enable scalar' => [
            'setCacheConfig', 'nginxmanager_set_cache_config', [true, 'one'], 'POST', 'enabled=1&user=one'];
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
        yield 'system read' => ['getSystemCacheConfig', 'nginxmanager_get_cache_config_system', [true]];
        yield 'users read' => ['getUsersCacheConfig', 'nginxmanager_get_cache_config_users', ['one', true]];
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
                    'setCacheConfig' => [false, $users], default => [$users],
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


    /** @return iterable<string, array{string, string, bool}> */
    public static function writes(): iterable
    {
        yield 'clear' => ['clearCache', 'nginxmanager_clear_cache', false];
        yield 'rebuild' => ['rebuildCacheConfig', 'nginxmanager_rebuild_cache_config', false];
        yield 'reset' => ['resetUsersCacheConfig', 'nginxmanager_reset_users_cache_config', false];
        yield 'set' => ['setCacheConfig', 'nginxmanager_set_cache_config', true];
    }

    #[DataProvider('writes')]
    public function testExplicitGenericPostIsTheDeliberateAllUsersForm(
        string $method,
        string $operation,
        bool $set,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, null)));
        $client->whm()->call($operation, $set ? ['enabled' => 0] : []);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame($set ? 'enabled=0' : '', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('writes')]
    public function testMissingAndNullWriteTargetsRejectBeforeRunnerAndRetainMetadata(
        string $method,
        string $operation,
        bool $set,
    ): void {
        foreach ([false, true] as $weak) {
            foreach ([[], [null]] as $target) {
                [$client, $http] = self::client();
                $group = $client->whm()->nginxManager();
                $http->addResponse(new Response(200, [], self::envelope('nginxmanager_get_cache_config_system', '{}')));
                $group->getSystemCacheConfig();
                $previous = $client->whm()->responseMeta();
                $arguments = [...($set ? [false] : []), ...$target];
                try {
                    if ($weak) {
                        WeakNginxCaller::call($group, $method, $arguments);
                    } else {
                        $group->{$method}(...$arguments);
                    }
                    self::fail('A typed write requires an explicit nonnullable target.');
                } catch (TypeError) {
                    self::assertSame($previous, $client->whm()->responseMeta());
                }
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function unsafeWriteTargets(): iterable
    {
        foreach (['clearCache', 'rebuildCacheConfig', 'resetUsersCacheConfig', 'setCacheConfig'] as $method) {
            foreach (['', ' ', "\t\r\n", '0', ' 0 ', [''], ['one', ' '], ['one', ' 0 ']] as $index => $target) {
                yield "$method unsafe $index" => [$method, $method === 'setCacheConfig'
                    ? [false, $target] : [$target]];
            }
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('unsafeWriteTargets')]
    public function testUnsafeWriteTargetsResetMetadataWithoutDispatch(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('nginxmanager_get_cache_config_system', '{}')));
        $client->whm()->nginxManager()->getSystemCacheConfig();
        try {
            $client->whm()->nginxManager()->{$method}(...$arguments);
            self::fail('Blank and trimmed-zero write targets must reject.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('writes')]
    public function testValidWriteTargetsAreEncodedWithoutTrimming(string $method, string $operation, bool $set): void
    {
        foreach ([' one ', [' one ', 'two +&=雪']] as $target) {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], self::envelope($operation, null)));
            $arguments = $set ? [false, $target] : [$target];
            $client->whm()->nginxManager()->{$method}(...$arguments);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame(
                ($set ? 'enabled=0&' : '') . 'user=%20one%20'
                . (is_array($target) ? '&user=two%20%2B%26%3D%E9%9B%AA' : ''),
                (string) $request->getBody(),
            );
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testInternalMissingAndNullTargetsCannotBypassSelectedSpecPolicy(): void
    {
        foreach (
            [NginxManagerSpec::NGINXMANAGER_CLEAR_CACHE, NginxManagerSpec::NGINXMANAGER_REBUILD_CACHE_CONFIG,
            NginxManagerSpec::NGINXMANAGER_RESET_USERS_CACHE_CONFIG,
            NginxManagerSpec::NGINXMANAGER_SET_CACHE_CONFIG] as $spec
        ) {
            foreach ([[], ['users' => null]] as $target) {
                $dispatched = false;
                try {
                    OperationRunner::run(
                        $spec,
                        ($spec['function'] === 'nginxmanager_set_cache_config' ? ['enabled' => false] : []) + $target,
                        static function () use (&$dispatched): never {
                            $dispatched = true;
                            self::fail('An internal missing or null target must not dispatch.');
                        },
                    );
                    self::fail('The selected target policy must reject.');
                } catch (InvalidArgumentException) {
                    self::assertFalse($dispatched);
                }
            }
        }
    }

    /** @return iterable<string, array{string, mixed, bool}> */
    public static function mergeArguments(): iterable
    {
        foreach (['getSystemCacheConfig', 'getUsersCacheConfig'] as $method) {
            foreach ([true, null, false, 0, 1, '0', '1', 'true', [], [true], new \stdClass()] as $index => $value) {
                foreach ([false, true] as $weak) {
                    yield "$method merge $index weak $weak" => [$method, $value, $weak];
                }
            }
        }
    }

    #[DataProvider('mergeArguments')]
    public function testPresenceOnlyMergeInStrictAndWeakCallers(string $method, mixed $value, bool $weak): void
    {
        [$client, $http] = self::client();
        $group = $client->whm()->nginxManager();
        $http->addResponse(new Response(200, [], self::envelope('nginxmanager_get_cache_config_system', '{}')));
        $group->getSystemCacheConfig();
        $previous = $client->whm()->responseMeta();
        $arguments = ['merge' => $value];
        if ($method === 'getUsersCacheConfig') {
            $arguments['users'] = 'one';
        }
        if ($value === true || $value === null) {
            $operation = $method === 'getSystemCacheConfig'
                ? 'nginxmanager_get_cache_config_system' : 'nginxmanager_get_cache_config_users';
            $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
            $result = $weak ? WeakNginxCaller::call($group, $method, $arguments) : $group->{$method}(...$arguments);
            self::assertSame([], $result);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('api.version=1' . ($method === 'getUsersCacheConfig' ? '&user=one' : '')
                . ($value === true ? '&merge=1' : ''), $request->getUri()->getQuery());
            self::assertCount(2, $http->getRequests());
        } else {
            try {
                if ($weak) {
                    WeakNginxCaller::call($group, $method, $arguments);
                } else {
                    $group->{$method}(...$arguments);
                }
                self::fail('Only true or null may be supplied for merge.');
            } catch (TypeError) {
                self::assertSame($previous, $client->whm()->responseMeta());
            }
            self::assertCount(1, $http->getRequests());
        }
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
