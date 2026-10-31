<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

final class GeneratedCollectionInputsTest extends TestCase
{
    private const INDEXED = ['kind' => 'indexed', 'item_type' => 'string', 'allow_scalar' => true,
        'empty' => 'reject', 'prefix' => 'domain', 'separator' => '-', 'start' => 0];

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        foreach (['whm', 'direct', 'bridge'] as $route) {
            foreach (['GET', 'POST'] as $method) {
                yield $route . $method => [$route, $method];
            }
        }
    }

    #[DataProvider('routes')]
    public function testLiteralIndexedAndTypedRepeatRequests(string $route, string $method): void
    {
        [$handle, $http] = $this->client($route);
        $params = ['domain' => ['name' => 'domain', 'encoding' => 'collection', 'collection' => self::INDEXED],
            'remove' => ['name' => 'remove', 'encoding' => 'collection', 'collection' => [
                'kind' => 'repeat', 'item_type' => 'int', 'allow_scalar' => false, 'empty' => 'reject']]];
        $this->invoke($handle, $method, $params, ['remove' => [3, 8, 3], 'domain' => ['a%20&=', '']]);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $base = $route === 'whm' ? 'https://api.example.test:2087/json-api/probe'
            : ($route === 'bridge' ? 'https://api.example.test:2087/json-api/uapi_cpanel'
                : 'https://api.example.test:2083/execute/Email/probe');
        $routing = match ($route) {
            'whm' => 'api.version=1',
            'bridge' => 'api.version=1&cpanel.user=npr&cpanel.module=Email&cpanel.function=probe',
            default => '',
        };
        $query = $routing;
        if ($method === 'GET') {
            $query .= ($routing === '' ? '' : '&') . 'domain-0=a%2520%26%3D&domain-1=&remove=3&remove=8&remove=3';
        }
        self::assertSame($base . ($query === '' ? '' : '?' . $query), (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            $method === 'POST' ? 'domain-0=a%2520%26%3D&domain-1=&remove=3&remove=8&remove=3' : '',
            (string) $request->getBody(),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testSingletonArrayAndScalarStayDifferentOnEveryRoute(string $route, string $method): void
    {
        [$handle, $http] = $this->client($route);
        foreach ([['a'], 'a'] as $value) {
            $this->invoke($handle, $method, self::params(), ['domain' => $value]);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            $fields = $method === 'POST' ? (string) $request->getBody() : $request->getUri()->getQuery();
            self::assertStringEndsWith(is_array($value) ? 'domain-0=a' : 'domain=a', $fields);
        }
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testLateInvalidItemClearsMetadataAndSendsNoPartialRequest(string $route, string $method): void
    {
        [$handle, $http] = $this->client($route);
        $handle instanceof WhmClient ? $handle->call('probe') : $handle->call('Email', 'probe');
        self::assertNotNull($handle->responseMeta());
        try {
            $this->invoke($handle, $method, self::params(), ['domain' => ['first-secret', 'last-secret', 1]]);
            self::fail('The whole strict list must be valid before dispatch.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('routes')]
    public function testIndexedFixedCollisionInBothOrdersRejectsAndRedacts(string $route, string $method): void
    {
        foreach ([false, true] as $reverse) {
            [$handle, $http] = $this->client($route);
            $params = self::params() + ['fixed' => ['name' => 'domain-1', 'encoding' => 'scalar']];
            try {
                $this->invoke(
                    $handle,
                    $method,
                    $reverse ? array_reverse($params, true) : $params,
                    ['domain' => ['a', 'collision-secret'], 'fixed' => 'collision-secret'],
                );
                self::fail('Expanded fields cannot collide.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('domain-1', $error->getMessage());
                self::assertStringNotContainsString('collision-secret', $error->getMessage());
                self::assertNull($handle->responseMeta());
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    public function testTypedRepeatCannotOverrideBridgeReservations(): void
    {
        foreach (['api.version', 'cpanel.user', 'cpanel.module', 'cpanel.function'] as $wire) {
            [$handle, $http] = $this->client('bridge');
            try {
                $this->invoke($handle, 'POST', ['items' => ['name' => $wire, 'encoding' => 'collection',
                    'collection' => ['kind' => 'repeat', 'item_type' => 'int', 'allow_scalar' => false,
                        'empty' => 'reject']]], ['items' => [3, 8]]);
                self::fail('Routing is reserved.');
            } catch (InvalidArgumentException) {
                self::assertNull($handle->responseMeta());
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    #[DataProvider('routes')]
    public function testAcceptedOutcomePayloadMismatchDoesNotRetry(string $route, string $method): void
    {
        [$handle, $http] = $this->client($route);
        $spec = ['function' => 'probe', 'method' => $method, 'outcome' => 'flag',
            'params' => self::params(), 'returns' => ['type' => 'string']];
        if ($handle instanceof UapiClient) {
            $spec['module'] = 'Email';
        }
        try {
            (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, ['domain' => ['a']]);
            self::fail('An absent/null response does not satisfy string.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertNotNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('routes')]
    public function testApiFailureRetainsSnapshotAndMakesOneAttempt(string $route, string $method): void
    {
        [$handle, $http] = $this->client($route);
        $http->setDefaultResponse(new Response(200, [], match ($route) {
            'whm' => '{"metadata":{"result":0,"reason":"fixture failure"}}',
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":{"status":0,"errors":["fixture failure"]}}}',
            default => '{"result":{"status":0,"errors":["fixture failure"]}}',
        }));
        try {
            $this->invoke($handle, $method, self::params(), ['domain' => ['a', 'b']]);
            self::fail('A collection request does not waive API failure.');
        } catch (ApiException) {
            self::assertNotNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('routes')]
    public function testNamedMapLiteralRequestAndEmptyMapStillDispatches(string $route, string $method): void
    {
        foreach ([['A.%&=' => '', 'x_test' => 'a%20&='], [], null] as $values) {
            [$handle, $http] = $this->client($route);
            $params = self::mapParams() + ['fixed' => ['name' => 'fixed', 'encoding' => 'bool']];
            $this->invoke($handle, $method, $params, ['values' => $values, 'fixed' => false]);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            $expected = $values === null || $values === [] ? 'fixed=0'
                : 'x_A.%25%26%3D=&x_x_test=a%2520%26%3D&fixed=0';
            $fields = $method === 'POST' ? (string) $request->getBody() : $request->getUri()->getQuery();
            self::assertStringEndsWith($expected, $fields);
            self::assertCount(1, $http->getRequests());
            self::assertNotNull($handle->responseMeta());
        }
    }

    #[DataProvider('routes')]
    public function testLateInvalidMapEntryClearsMetadataAndNeverDispatches(string $route, string $method): void
    {
        $cases = [['private-key' => 'private-value', 'late-key' => null], ['private-key' => 'ok', 7 => 'bad']];
        foreach ($cases as $values) {
            [$handle, $http] = $this->client($route);
            $handle instanceof WhmClient ? $handle->call('probe') : $handle->call('Email', 'probe');
            self::assertNotNull($handle->responseMeta());
            try {
                $this->invoke($handle, $method, self::mapParams(), ['values' => $values]);
                self::fail('Invalid map must fail before I/O.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('private', $error->getMessage());
                self::assertStringNotContainsString('late-key', $error->getMessage());
                self::assertNull($handle->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    #[DataProvider('routes')]
    public function testNamedMapRoutingCollisionsFailLocally(string $route, string $method): void
    {
        if ($route === 'direct') {
            // Direct UAPI has no query routing fields; literal dotted names survive.
            [$handle, $http] = $this->client($route);
            $this->invoke($handle, $method, self::mapParams(''), ['values' => ['cpanel.user' => 'value']]);
            self::assertCount(1, $http->getRequests());
            return;
        }
        $keys = $route === 'whm' ? ['api.version']
            : ['api.version', 'cpanel.user', 'cpanel.module', 'cpanel.function'];
        foreach ($keys as $key) {
            [$handle, $http] = $this->client($route);
            try {
                $this->invoke($handle, $method, self::mapParams(''), ['values' => [$key => 'private-value']]);
                self::fail('Map cannot overwrite routing.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString($key, $error->getMessage());
                self::assertStringNotContainsString('private-value', $error->getMessage());
                self::assertNull($handle->responseMeta());
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    /** @return array<string, mixed> */
    private static function mapParams(string $prefix = 'x_'): array
    {
        return ['values' => ['name' => 'placeholder*', 'encoding' => 'collection', 'collection' => [
            'kind' => 'map', 'item_type' => 'string', 'allow_scalar' => false,
            'empty' => 'allow', 'prefix' => $prefix]]];
    }

    /** @return array<string, mixed> */
    private static function params(): array
    {
        return ['domain' => ['name' => 'domain', 'encoding' => 'collection', 'collection' => self::INDEXED]];
    }

    /** @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     */
    private function invoke(WhmClient|UapiClient $handle, string $method, array $params, array $arguments): void
    {
        $spec = ['function' => 'probe', 'method' => $method, 'outcome' => 'flag',
            'params' => $params, 'returns' => null];
        if ($handle instanceof UapiClient) {
            $spec['module'] = 'Email';
        }
        (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, $arguments);
    }

    /** @return array{WhmClient|UapiClient, MockClient} */
    private function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $http->setDefaultResponse(new Response(200, [], match ($route) {
            'whm' => '{"metadata":{"result":1}}',
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":null}}}',
            default => '{"result":{"status":1,"data":null}}',
        }));
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'direct' ? 'cpanel' : 'whm',
            'username' => $route === 'direct' ? 'npr' : 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );

        return [$route === 'whm' ? $client->whm() : $client->uapi('npr'), $http];
    }
}
