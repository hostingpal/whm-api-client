<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\DynamicDns;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Literal contract fixtures from the pinned DynamicDNS source, independent of generated specs.
 *
 * @phpstan-type DynamicDnsCall Closure(DynamicDns): mixed
 * @phpstan-type Operation array{string, string, DynamicDnsCall, string, ?string, mixed}
 */
final class UapiDynamicDnsTest extends TestCase
{
    public function testSelectorsAreLazyAndKeepAccountTargetsAndMetadataIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        self::assertSame($first->dynamicDns(), $first->dynamicDns());
        self::assertNotSame($first->dynamicDns(), $second->dynamicDns());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'list', '[]')));
        self::assertSame([], $first->dynamicDns()->listDomains());
        $metadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        $first->dynamicDns();
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'list', '[]')));
        $second->dynamicDns()->listDomains();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNotSame($metadata, $second->responseMeta());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=other'
                . '&cpanel.module=DynamicDNS&cpanel.function=list',
            (string) $request->getUri(),
        );
        self::assertCount(2, $http->getRequests());
    }

    /** @param DynamicDnsCall $call */
    #[DataProvider('successfulCalls')]
    public function testOperationsPreservePayloadSemanticsAndUseExactAccountBoundRequests(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        self::assertSame($expected, $call($account->dynamicDns()));
        self::assertRequest($http, $route, $function, $method, $parameters);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic DDNS warning'], $metadata->uapiWarnings());
        self::assertSame(['Synthetic DDNS notice'], $metadata->uapiMessages());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        // Returned capability identifiers are payloads; the client never follows an update URL.
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, DynamicDnsCall, string, ?string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ([...self::operations(), ...self::variants()] as $name => $operation) {
                yield $route . ' ' . $name => [$route, ...$operation];
            }
        }
    }

    /** @return iterable<string, Operation> */
    public static function operations(): iterable
    {
        yield 'create' => [
            'create', 'POST', static fn (DynamicDns $dns): mixed =>
                $dns->create(domain: 'home.example.test', description: "Home +&=%/雪\nnetwork"),
            'domain=home.example.test&description=Home%20%2B%26%3D%25%2F%E9%9B%AA%0Anetwork',
            '{"created_time":1700000000,"id":"new-capability-id","extra":true}',
            ['created_time' => 1700000000, 'id' => 'new-capability-id'],
        ];
        yield 'delete' => [
            'delete', 'POST', static fn (DynamicDns $dns): mixed => $dns->delete(id: 'id +&=%/雪'),
            'id=id%20%2B%26%3D%25%2F%E9%9B%AA', '{"deleted":1,"extra":true}', ['deleted' => 1],
        ];
        yield 'list domains' => [
            'list', 'GET', static fn (DynamicDns $dns): mixed => $dns->listDomains(), '',
            '[{"created_time":1700000000,"description":"Home network","domain":"home.example.test",'
                . '"id":"update-capability-id","last_run_times":[1700000020,1700000010],'
                . '"last_update_time":1700000010,"extra":true},'
                . '{"created_time":0,"description":"","last_run_times":[],"last_update_time":null},{}]',
            [
                ['created_time' => 1700000000, 'description' => 'Home network', 'domain' => 'home.example.test',
                    'id' => 'update-capability-id', 'last_run_times' => [1700000020, 1700000010],
                    'last_update_time' => 1700000010],
                ['created_time' => 0, 'description' => '', 'last_run_times' => [], 'last_update_time' => null], [],
            ],
        ];
        yield 'recreate' => [
            'recreate', 'POST', static fn (DynamicDns $dns): mixed => $dns->recreate(id: 'old +&=%/雪'),
            'id=old%20%2B%26%3D%25%2F%E9%9B%AA', '{"id":"replacement-capability-id","extra":true}',
            ['id' => 'replacement-capability-id'],
        ];
        yield 'set description' => [
            'set_description', 'POST', static function (DynamicDns $dns): mixed {
                $dns->setDescription(id: 'id +&=%/雪', description: "New +&=%/雪\nnetwork");
                return null;
            },
            'id=id%20%2B%26%3D%25%2F%E9%9B%AA&description=New%20%2B%26%3D%25%2F%E9%9B%AA%0Anetwork',
            null, null,
        ];
    }

    /** @return iterable<string, Operation> */
    public static function variants(): iterable
    {
        yield 'create with omitted description' => [
            'create', 'POST', static fn (DynamicDns $dns): mixed => $dns->create('home.example.test'),
            'domain=home.example.test', '{"created_time":0}', ['created_time' => 0],
        ];
        yield 'create with null description omits it' => [
            'create', 'POST', static fn (DynamicDns $dns): mixed => $dns->create('home.example.test', null),
            'domain=home.example.test', '{}', [],
        ];
        yield 'create with explicit empty description' => [
            'create', 'POST', static fn (DynamicDns $dns): mixed => $dns->create('home.example.test', ''),
            'domain=home.example.test&description=', '{"id":""}', ['id' => ''],
        ];
        yield 'delete absent domain succeeds with numeric zero' => [
            'delete', 'POST', static fn (DynamicDns $dns): mixed => $dns->delete('absent'),
            'id=absent', '{"deleted":0}', ['deleted' => 0],
        ];
        yield 'delete optional field omitted' => [
            'delete', 'POST', static fn (DynamicDns $dns): mixed => $dns->delete('id'), 'id=id', '{}', [],
        ];
        yield 'recreate optional field omitted' => [
            'recreate', 'POST', static fn (DynamicDns $dns): mixed => $dns->recreate('id'), 'id=id', '{}', [],
        ];
        yield 'empty domain inventory' => [
            'list', 'GET', static fn (DynamicDns $dns): mixed => $dns->listDomains(), '', '[]', [],
        ];
        yield 'zero update time stays distinct from null and omitted' => [
            'list', 'GET', static fn (DynamicDns $dns): mixed => $dns->listDomains(), '',
            '[{"last_update_time":0,"last_run_times":[0]}]',
            [['last_run_times' => [0], 'last_update_time' => 0]],
        ];
        yield 'set empty description with explicit null data' => [
            'set_description', 'POST', static function (DynamicDns $dns): mixed {
                $dns->setDescription('id', '');
                return null;
            }, 'id=id&description=', 'null', null,
        ];
    }

    /** @param DynamicDnsCall $call */
    #[DataProvider('invalidPayloads')]
    public function testMalformedAndMissingPayloadsRetainConfirmedOutcomesAndAreNotRetried(
        string $route,
        string $function,
        Closure $call,
        ?string $valid,
        ?string $invalid,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $valid)));
        $call($account->dynamicDns());
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $invalid)));
        try {
            $call($account->dynamicDns());
            self::fail('Malformed or missing DDNS data must fail validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $invalid === null ? null : json_decode($invalid, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, DynamicDnsCall, ?string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $malformed = [
            'create' => ['[{}]', '{"created_time":"0"}', '{"created_time":0.5}', '{"created_time":null}',
                '{"created_time":true}', '{"id":null}', '{"id":123}'],
            'delete' => ['[{}]', '{"deleted":"0"}', '{"deleted":true}', '{"deleted":null}',
                '{"deleted":1.0}', '{"deleted":1.5}', '{"deleted":2}', '{"deleted":-1}'],
            'recreate' => ['[{}]', '{"id":null}', '{"id":0}'],
            'list' => ['{}', '[null]', '[false]', '[[{}]]', '[{"created_time":"0"}]',
                '[{"created_time":null}]', '[{"description":null}]', '[{"domain":false}]',
                '[{"id":0}]', '[{"last_run_times":null}]', '[{"last_run_times":{}}]',
                '[{"last_run_times":["0"]}]', '[{"last_run_times":[0,null]}]',
                '[{"last_run_times":[0.5]}]', '[{"last_update_time":"0"}]',
                '[{"last_update_time":false}]', '[{"last_update_time":0.5}]'],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [$function, , $call, , $valid]) {
                if ($function === 'set_description') {
                    continue;
                }
                foreach ([null, 'null', 'false', '"wrong"', '1', ...$malformed[$function]] as $index => $invalid) {
                    yield $route . ' ' . $name . ' ' . $index => [$route, $function, $call, $valid, $invalid];
                }
            }
        }
    }

    /** @param DynamicDnsCall $call */
    #[DataProvider('failureCalls')]
    public function testApiAndHttpFailuresReplaceMetadataAndAreNotRetried(
        string $route,
        string $function,
        Closure $call,
        ?string $valid,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $valid)));
        $call($account->dynamicDns());
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, null, 0)));
        try {
            $call($account->dynamicDns());
            self::fail('DDNS API failure must throw even for the void mutation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Synthetic DDNS failure'], $error->errors());
        }
        $http->addResponse(new Response(503, [], 'Synthetic unavailable service'));
        try {
            $call($account->dynamicDns());
            self::fail('HTTP failure must clear metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertCount(3, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, DynamicDnsCall, ?string}> */
    public static function failureCalls(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [$function, , $call, , $payload]) {
                yield $route . ' ' . $name => [$route, $function, $call, $payload];
            }
        }
    }

    /** @param DynamicDnsCall $call */
    #[DataProvider('bridgeFailures')]
    public function testOuterWhmFailureCannotBeOverriddenBySuccessfulUapi(
        string $function,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope('bridge', $function, $payload, 1, 0)));
        try {
            $call($account->dynamicDns());
            self::fail('An outer WHM failure must be checked before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, DynamicDnsCall, ?string}> */
    public static function bridgeFailures(): iterable
    {
        foreach (self::operations() as $name => [$function, , $call, , $payload]) {
            yield $name => [$function, $call, $payload];
        }
    }

    #[DataProvider('idFailures')]
    public function testCapabilityIdArgumentsAreSensitiveInEveryLibraryFailureFrame(
        string $route,
        string $method,
        string $failure,
    ): void {
        $secret = 'private-ddns-capability-marker';
        [$client, $http] = self::client($route);
        $dns = $client->uapi('exampleuser')->dynamicDns();
        $id = new ReflectionParameter([$dns, $method], 'id');
        self::assertCount(1, $id->getAttributes(SensitiveParameter::class));
        $function = $method === 'setDescription' ? 'set_description' : $method;
        $http->addResponse(match ($failure) {
            'api' => new Response(200, [], self::envelope($route, $function, null, 0)),
            'http' => new Response(500, [], $secret),
            'json' => new Response(200, [], $secret),
            default => new Response(200, [], self::envelope($route, $function, 'false')),
        });
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            match ($method) {
                'delete' => $dns->delete($secret),
                'recreate' => $dns->recreate($secret),
                default => $dns->setDescription($secret, 'Synthetic description'),
            };
            self::fail('The synthetic failure must throw.');
        } catch (ApiException $error) {
            foreach ($error->getTrace() as $frame) {
                if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    continue;
                }
                $seen = [];
                self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
            }
            self::assertStringNotContainsString($secret, $error->getMessage());
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertRequest(
            $http,
            $route,
            $function,
            'POST',
            'id=' . $secret . ($method === 'setDescription' ? '&description=Synthetic%20description' : ''),
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function idFailures(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['delete', 'recreate', 'setDescription'] as $method) {
                foreach (['api', 'http', 'json', 'payload'] as $failure) {
                    if ($method !== 'setDescription' || $failure !== 'payload') {
                        yield "$route $method $failure" => [$route, $method, $failure];
                    }
                }
            }
        }
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, string $secret, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $secret);
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
                if (str_contains((string) $key, $secret) || self::hasSecret($item, $secret, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=DynamicDNS&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/DynamicDNS/' . $function;
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(
        string $route,
        string $function,
        ?string $payload,
        int $status = 1,
        int $whmResult = 1,
    ): string {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic DDNS failure"]')
            . ',"warnings":["Synthetic DDNS warning"],"messages":["Synthetic DDNS notice"]}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":' . $whmResult . '},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"DynamicDNS","func":"' . $function . '","result":' . $result . '}',
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
