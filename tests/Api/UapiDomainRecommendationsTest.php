<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\DomainRecommendations;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
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
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/**
 * Independently authored literal fixtures for pinned DomainRecommendations contracts.
 *
 * @phpstan-type DomainCall Closure(DomainRecommendations): mixed
 */
final class UapiDomainRecommendationsTest extends TestCase
{
    public function testCachedAccessorUsesRealClientAndRunner(): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('npr');
        $group = $account->domainRecommendations();
        self::assertSame($group, $account->domainRecommendations());
        self::assertCount(0, $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('direct', '{"is_enabled":0}')));
        self::assertSame(['is_enabled' => 0], $group->isEnabled());
        self::assertCount(1, $http->getRequests());
    }

    /** @return array<string, array{string, DomainCall, string, string}> */
    private static function operations(): array
    {
        return [
            'domain_availability' => ['GET', static fn (DomainRecommendations $c): mixed =>
                $c->domainAvailability(domain: 'MiXeD.雪+%.test', useCache: false),
                'domain=MiXeD.%E9%9B%AA%2B%25.test&use_cache=0', self::availability()],
            'domain_suggestions' => ['GET', static fn (DomainRecommendations $c): mixed =>
                $c->domainSuggestions(searchField: 'A +&=%雪', page: 0, pageSize: -1, useCache: true),
                'search_field=A%20%2B%26%3D%25%E9%9B%AA&page=0&page_size=-1&use_cache=1', self::suggestions()],
            'get_store_config' => ['GET', static fn (DomainRecommendations $c): mixed => $c->getStoreConfig(), '',
                '{"store_type":"WHMCS"}'],
            'is_enabled' => ['GET', static fn (DomainRecommendations $c): mixed => $c->isEnabled(), '',
                '{"is_enabled":0}'],
            'purchase_domain' => ['POST', static fn (DomainRecommendations $c): mixed =>
                $c->purchaseDomain(domain: 'MiXeD.雪+%.test', period: 0),
                'domain=MiXeD.%E9%9B%AA%2B%25.test&period=0', '{"purchase_url":"https://never-fetch.test/+?x=%2F"}'],
            'supported_tlds' => ['GET',
                static fn (DomainRecommendations $c): mixed => $c->supportedTlds(useCache: false),
                'use_cache=0', '{"tlds":["com","雪","MiXeD",""]}'],
        ];
    }

    private static function price(): string
    {
        return '{"code":"future-currency","display_value":" 9007199254740993.00000001 USD ",'
            . '"prefix":" ","suffix":" USD ","value":"9007199254740993.00000001"}';
    }

    private static function period(): string
    {
        return '{"period":0,"register":' . self::price() . ',"renew":null,"transfer":{}}';
    }

    private static function availability(): string
    {
        return '{"domain_name":"MiXeD.雪.test","idn_domain_name":"xn--opaque.test","idn_sld":"雪",'
            . '"sld":"MiXeD","tld":"test","status":"future","group":null,"is_domain_available":true,'
            . '"is_premium":false,"is_tld_available":false,"premium_cost_pricing":{"opaque":{"a":[1,null,true]}},'
            . '"pricing":[' . self::period() . ',{}],"shortest_period":' . self::period() . '}';
    }

    private static function suggestions(): string
    {
        return '{"meta":{"page":0,"page_size":-1,"total_items":2,"total_pages":1,"future":{"x":[true,null]}},'
            . '"suggestions":{"MiXeD.雪.test":{"pricing":[' . self::period() . '],"is_premium":0,'
            . '"premium_cost_pricing":{"register":{"term+01":' . self::price() . ',"future":{}}}},'
            . '"other.test":{"pricing":[],"is_premium":1,"premium_cost_pricing":null}}}';
    }

    /** @return iterable<string, array{string, string, string, DomainCall, string, string}> */
    public static function successes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::operations() as $function => [$method, $call, $wire, $payload]) {
                yield "$route $function populated" => [$route, $function, $method, $call, $wire, $payload];
                yield "$route $function empty" => [$route, $function, $method, $call, $wire, '{}'];
            }
            foreach ([null, false, true] as $i => $cache) {
                $wire = $cache === null ? '' : 'use_cache=' . ($cache ? '1' : '0');
                yield "$route availability cache $i" => [$route, 'domain_availability', 'GET',
                    static fn (DomainRecommendations $c): mixed => $c->domainAvailability('', $cache),
                    'domain=' . ($wire === '' ? '' : '&' . $wire), 'null'];
                yield "$route suggestions cache $i" => [$route, 'domain_suggestions', 'GET',
                    static fn (DomainRecommendations $c): mixed => $c->domainSuggestions('', useCache: $cache),
                    'search_field=' . ($wire === '' ? '' : '&' . $wire), '{"meta":{},"suggestions":{}}'];
                yield "$route tlds cache $i" => [$route, 'supported_tlds', 'GET',
                    static fn (DomainRecommendations $c): mixed => $c->supportedTlds($cache), $wire, '{"tlds":[]}'];
            }
            yield "$route store open string" => [$route, 'get_store_config', 'GET',
                static fn (DomainRecommendations $c): mixed => $c->getStoreConfig(), '', '{"store_type":"future"}'];
            yield "$route enabled" => [$route, 'is_enabled', 'GET',
                static fn (DomainRecommendations $c): mixed => $c->isEnabled(), '', '{"is_enabled":1}'];
            yield "$route purchase empty and negative" => [$route, 'purchase_domain', 'POST',
                static fn (DomainRecommendations $c): mixed => $c->purchaseDomain('', -7),
                'domain=&period=-7', '{"purchase_url":""}'];
            yield "$route suggestions supplied zero" => [$route, 'domain_suggestions', 'GET',
                static fn (DomainRecommendations $c): mixed => $c->domainSuggestions('', pageSize: 0),
                'search_field=&page_size=0', '{"suggestions":{}}'];
            foreach (
                ['{"group":"","premium_cost_pricing":null,"pricing":[],"shortest_period":{}}',
                '{"pricing":[{"register":null,"renew":{},"transfer":{"value":""}}]}'] as $i => $payload
            ) {
                yield "$route optional prices $i" => [$route, 'domain_availability', 'GET',
                    static fn (DomainRecommendations $c): mixed => $c->domainAvailability('x'), 'domain=x', $payload];
            }
            foreach (['{}', '{"register":{}}', '{"register":{"1":{}}}'] as $i => $premium) {
                yield "$route premium independent $i" => [$route, 'domain_suggestions', 'GET',
                    static fn (DomainRecommendations $c): mixed => $c->domainSuggestions('x'), 'search_field=x',
                    '{"suggestions":{"x":{"pricing":[],"is_premium":0,"premium_cost_pricing":' . $premium . '}}}'];
            }
        }
    }

    /** @param DomainCall $call */
    #[DataProvider('successes')]
    public function testSingleRequestMatchesLiteralWireAndPayload(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        self::assertSame(
            self::ordered(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)),
            self::ordered($call($account->domainRecommendations())),
        );
        self::assertCount(1, $http->getRequests());
        self::assertRequest($http, $route, $function, $method, $wire);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame(['Fixture warning'], $account->responseMeta()->uapiWarnings());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function mismatches(): iterable
    {
        $cases = [
            'domain_availability' => [null, '[{}]', '"bad"', '{"is_domain_available":1}', '{"is_premium":"0"}',
                '{"is_tld_available":0}', '{"is_premium":null}', '{"group":0}', '{"domain_name":false}',
                '{"idn_domain_name":null}', '{"idn_sld":1}', '{"sld":[]}', '{"status":0}', '{"tld":true}',
                '{"premium_cost_pricing":[1]}', '{"pricing":{}}', '{"pricing":[null]}',
                '{"pricing":[{"period":"1"}]}', '{"pricing":[{"register":{"value":1.2}}]}',
                '{"pricing":[{"renew":{"code":null}}]}', '{"pricing":[{"transfer":{"suffix":1}}]}',
                '{"shortest_period":null}', '{"shortest_period":{"period":1.0}}',
                '{"shortest_period":{"register":{"value":2}}}'],
            'domain_suggestions' => [null, 'null', '[{}]', '{"meta":null}', '{"meta":{"page":"1"}}',
                '{"meta":{"page_size":false}}', '{"meta":{"total_items":1.0}}', '{"meta":{"total_pages":null}}',
                '{"suggestions":[{}]}', '{"suggestions":{"x":null}}', '{"suggestions":{"x":{}}}'],
            'get_store_config' => [null, 'null', '[{}]', '{"store_type":null}', '{"store_type":1}'],
            'is_enabled' => [null, 'null', '[{}]', '{"is_enabled":false}', '{"is_enabled":"0"}',
                '{"is_enabled":2}', '{"is_enabled":null}', '{"is_enabled":1.0}'],
            'purchase_domain' => [null, 'null', '[{}]', '{"purchase_url":null}', '{"purchase_url":1}'],
            'supported_tlds' => [null, 'null', '[{}]', '{"tlds":null}', '{"tlds":{}}', '{"tlds":[1]}'],
        ];
        $row = ['pricing' => [], 'is_premium' => 0, 'premium_cost_pricing' => null];
        foreach (array_keys($row) as $field) {
            $missing = $row;
            unset($missing[$field]);
            $cases['domain_suggestions'][] = json_encode(['suggestions' => ['x' => $missing]], JSON_THROW_ON_ERROR);
        }
        foreach (
            [
            '{"pricing":null,"is_premium":0,"premium_cost_pricing":null}',
            '{"pricing":[],"is_premium":true,"premium_cost_pricing":null}',
            '{"pricing":[],"is_premium":"1","premium_cost_pricing":null}',
            '{"pricing":[],"is_premium":2,"premium_cost_pricing":null}',
            '{"pricing":[],"is_premium":0,"premium_cost_pricing":[1]}',
            '{"pricing":[{"period":"1"}],"is_premium":0,"premium_cost_pricing":null}',
            '{"pricing":[{"register":{"value":1}}],"is_premium":0,"premium_cost_pricing":null}',
            '{"pricing":[],"is_premium":0,"premium_cost_pricing":{"register":null}}',
            '{"pricing":[],"is_premium":0,"premium_cost_pricing":{"register":{"x":null}}}',
            '{"pricing":[],"is_premium":0,"premium_cost_pricing":{"register":{"x":{"value":2}}}}',
            '{"pricing":[],"is_premium":0,"premium_cost_pricing":{"register":{"x":{"prefix":false}}}}',
            ] as $invalid
        ) {
            $cases['domain_suggestions'][] = '{"suggestions":{"x":' . $invalid . '}}';
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $function => $payloads) {
                foreach ($payloads as $i => $payload) {
                    yield "$route $function $i" => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('mismatches')]
    public function testInvalidKnownFieldsAndMissingPayloadFail(string $route, string $function, ?string $payload): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        try {
            self::operations()[$function][1]($account->domainRecommendations());
            self::fail('Wrong known type or absent payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testUnknownFieldsRespectOpenMetadataClosedStoreAndOpaqueAvailability(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            foreach (
                [
                ['get_store_config', '{"store_type":"WHMCS","token":"secret","url":"https://never-fetch",'
                    . '"future":true}', ['store_type' => 'WHMCS']],
                ['domain_suggestions', '{"meta":{"future":{"a":true}},"extra":7}',
                    ['meta' => ['future' => ['a' => true]]]],
                ['domain_suggestions', '{"suggestions":{"x":{"pricing":[],"is_premium":1,'
                    . '"premium_cost_pricing":{"extra":7,"register":{"term":{"value":"1.00","extra":7}}},'
                    . '"extra":7}}}', ['suggestions' => ['x' => ['pricing' => [], 'is_premium' => 1,
                        'premium_cost_pricing' => ['register' => ['term' => ['value' => '1.00']]]]]]],
                ['domain_availability', '{"premium_cost_pricing":{"opaque":[1,true,null]},"future":7}',
                    ['premium_cost_pricing' => ['opaque' => [1, true, null]]]],
                ] as [$function, $payload, $expected]
            ) {
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                self::assertSame(
                    self::ordered($expected),
                    self::ordered(self::operations()[$function][1]($client->uapi('npr')->domainRecommendations())),
                );
            }
            self::assertCount(4, $http->getRequests());
        }
    }

    public function testNativeSignatures(): void
    {
        $signatures = [
            'domainAvailability' => ['domain' => 'string', 'useCache' => '?bool'],
            'domainSuggestions' => ['searchField' => 'string', 'page' => '?int', 'pageSize' => '?int',
                'useCache' => '?bool'],
            'getStoreConfig' => [], 'isEnabled' => [], 'purchaseDomain' => ['domain' => 'string', 'period' => 'int'],
            'supportedTlds' => ['useCache' => '?bool'],
        ];
        self::assertCount(6, array_filter(
            (new \ReflectionClass(DomainRecommendations::class))->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->isPublic() && $method->getName() !== '__construct',
        ));
        foreach ($signatures as $method => $parameters) {
            $reflection = new ReflectionMethod(DomainRecommendations::class, $method);
            self::assertSame(
                $method === 'domainAvailability' ? '?array' : 'array',
                (string) $reflection->getReturnType(),
            );
            self::assertSame(array_keys($parameters), array_map(
                static fn ($p): string => $p->getName(),
                $reflection->getParameters(),
            ));
            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameters[$parameter->getName()] ?? null;
                self::assertNotNull($type);
                self::assertSame($type, (string) $parameter->getType());
                self::assertSame(str_starts_with($type, '?'), $parameter->isOptional());
                if ($parameter->isOptional()) {
                    self::assertNull($parameter->getDefaultValue());
                }
            }
        }
    }

    public function testNativeFailuresRetainPriorMetadataAndPerformNoIo(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $group = $account->domainRecommendations();
            $group->isEnabled();
            $meta = $account->responseMeta();
            foreach (
                [
                ['domainAvailability', []], ['domainAvailability', [1]], ['domainAvailability', ['x', 0]],
                ['domainSuggestions', []], ['domainSuggestions', ['x', '1']], ['domainSuggestions', ['x', 1, false]],
                ['domainSuggestions', ['x', 1, 1, 1]], ['purchaseDomain', ['x']], ['purchaseDomain', ['x', '1']],
                ['supportedTlds', [0]]] as [$method, $arguments]
            ) {
                try {
                    self::invoke($group, $method, $arguments);
                    self::fail('Native type failure must precede entry.');
                } catch (TypeError) {
                    self::assertSame($meta, $account->responseMeta());
                }
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (array_keys(self::operations()) as $function) {
                foreach (['api', 'http', 'json', 'transport', 'payload'] as $failure) {
                    yield "$route $function $failure" => [$route, $function, $failure];
                }
                if ($route === 'bridge') {
                    yield "$route $function outer" => [$route, $function, 'outer'];
                }
            }
        }
    }

    #[DataProvider('failures')]
    public function testFailureOrderingAndMetadata(string $route, string $function, string $failure): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->domainRecommendations()->isEnabled();
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $body = match ($failure) {
                'api' => self::envelope($route, '{"status":"running"}', 0),
                'http' => self::envelope($route, '"bad"', 0),
                'json' => 'not JSON',
                'outer' => '{"metadata":{"result":0,"reason":"Synthetic failure"},'
                    . '"data":{"uapi":{"status":1,"data":{}}}}',
                default => self::envelope($route, '"bad"'),
            };
            $http->addResponse(new Response($failure === 'http' ? 503 : 200, [], $body));
        }
        try {
            self::operations()[$function][1]($account->domainRecommendations());
            self::fail('Failure must throw before success is returned.');
        } catch (ApiException $error) {
            self::assertInstanceOf(match ($failure) {
                'api', 'outer' => ApiErrorException::class,
                'payload' => PayloadMismatchException::class,
                'http' => HttpException::class,
                'json' => InvalidResponseException::class,
                default => TransportException::class,
            }, $error);
            if (in_array($failure, ['transport', 'json'], true)) {
                self::assertNull($account->responseMeta());
            } elseif (in_array($failure, ['api', 'http'], true)) {
                self::assertSame(0, $account->responseMeta()?->uapiStatus());
            } elseif ($failure === 'outer') {
                self::assertSame(0, $account->responseMeta()?->whmResult());
            } else {
                self::assertSame(1, $account->responseMeta()?->uapiStatus());
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param array<int|string, mixed> $arguments */
    private static function invoke(
        DomainRecommendations $group,
        string $method,
        #[SensitiveParameter] array $arguments,
    ): mixed {
        return $group->$method(...$arguments);
    }

    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::ordered(...), $value);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function privateFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                ['purchaseDomain'] as $method
            ) {
                foreach (['api', 'http', 'json', 'payload', 'transport'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    #[DataProvider('privateFailures')]
    public function testActualRunnerRedactsPrivateInputsAndResponseSnapshots(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $secret = 'https://private-checkout.test/cart?token=marker';
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $http->addResponse(match ($failure) {
                'api' => new Response(200, [], self::envelope($route, '"' . $secret . '"', 0)),
                'http' => new Response(503, [], $secret),
                'json' => new Response(200, [], $secret),
                default => new Response(200, [], self::envelope($route, '"' . $secret . '"')),
            });
        }
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::privatePurchase($client->uapi('npr')->domainRecommendations());
            self::fail('Synthetic failure must throw.');
        } catch (ApiException $error) {
            self::assertStringNotContainsString($secret, $error->getMessage());
            self::assertStringNotContainsString($secret, print_r($error, true));
            if ($error instanceof PayloadMismatchException) {
                self::assertSame($secret, $error->payload);
            }
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
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

    private static function privatePurchase(DomainRecommendations $group): mixed
    {
        return $group->purchaseDomain('fixture.test', 1);
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
        string $wire,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainRecommendations&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/DomainRecommendations/' . $function;
        if ($method === 'GET' && $wire !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $wire;
        }
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        if ($method === 'POST') {
            self::assertStringNotContainsString('domain=', (string) $request->getUri());
            self::assertStringNotContainsString('period=', (string) $request->getUri());
        }
    }

    private static function envelope(string $route, #[SensitiveParameter] ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Fixture warning"],"messages":null}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : '{"result":' . $result . '}';
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
