<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

final class GeneratedParameterCollisionsTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function routesAndMethods(): iterable
    {
        foreach (['whm', 'bridge', 'direct'] as $route) {
            foreach (['GET', 'POST'] as $method) {
                foreach ([false, true] as $reverse) {
                    yield $route . $method . (int) $reverse => [$route, $method, $reverse];
                }
            }
        }
    }

    #[DataProvider('routesAndMethods')]
    public function testNumberedExpansionCannotCollideWithAnotherOrigin(
        string $route,
        string $method,
        bool $reverse,
    ): void {
        [$handle, $http] = $this->client($route);
        $params = ['users' => ['name' => 'user', 'encoding' => 'numbered'],
            'other' => ['name' => 'user-1', 'encoding' => 'scalar']];
        $handle instanceof WhmClient ? $handle->call('probe') : $handle->call('Email', 'probe');
        self::assertNotNull($handle->responseMeta());
        try {
            $this->invoke(
                $handle,
                $method,
                $reverse ? array_reverse($params, true) : $params,
                ['users' => ['first-secret', 'equal-secret'], 'other' => 'equal-secret'],
            );
            self::fail('Expanded names from separate arguments must not overlap.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testDuplicateDeclaredNamesCannotBeOverwrittenByArgumentEncoder(): void
    {
        $this->expectException(LogicException::class);
        ArgumentEncoder::encode(['first' => ['name' => 'same', 'encoding' => 'scalar'],
            'second' => ['name' => 'same', 'encoding' => 'scalar']], ['first' => 'equal', 'second' => 'equal']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function reservations(): iterable
    {
        foreach (['GET', 'POST'] as $method) {
            yield 'whm ' . $method => ['whm', $method, 'api.version'];
            foreach (['api.version', 'cpanel.user', 'cpanel.module', 'cpanel.function'] as $name) {
                yield $name . $method => ['bridge', $method, $name];
            }
        }
    }

    #[DataProvider('reservations')]
    public function testGeneratedReservedFieldsFailBeforeFiltering(string $route, string $method, string $name): void
    {
        [$handle, $http] = $this->client($route);
        $handle instanceof WhmClient ? $handle->call('probe') : $handle->call('Email', 'probe');
        try {
            $this->invoke(
                $handle,
                $method,
                ['target' => ['name' => $name, 'encoding' => 'repeat']],
                ['target' => ['same-secret', 'same-secret']],
            );
            self::fail('Generated fields must not be silently discarded by routing.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('same-secret', $error->getMessage());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('routesAndMethods')]
    public function testLiteralLegacyWireBytesAndIntentionalRepeatsRemainOrdered(
        string $route,
        string $method,
        bool $reverse,
    ): void {
        [$handle, $http] = $this->client($route);
        $arguments = ['users' => ['a%20', ''], 'tags' => ['same', 'same'], 'values' => [0, '007'], 'flag' => false];
        $this->invoke($handle, $method, [
            'users' => ['name' => 'user', 'encoding' => 'numbered'],
            'tags' => ['name' => 'tag', 'encoding' => 'repeat'],
            'values' => ['name' => 'val*', 'encoding' => 'suffix-numbered-scalar'],
            'flag' => ['name' => 'enabled', 'encoding' => 'bool'],
        ], $reverse ? array_reverse($arguments, true) : $arguments);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $routing = match ($route) {
            'whm' => 'api.version=1',
            'bridge' => 'api.version=1&cpanel.user=npr&cpanel.module=Email&cpanel.function=probe',
            default => '',
        };
        $fields = 'user=a%2520&user-1=&tag=same&tag=same&val1=0&val2=007&enabled=0';
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            $method === 'POST' ? $routing : ($routing === '' ? '' : $routing . '&') . $fields,
            $request->getUri()->getQuery(),
        );
        self::assertSame($method === 'POST' ? $fields : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routesAndMethods')]
    public function testLateInvalidListEntrySendsNoPartialRequest(string $route, string $method, bool $reverse): void
    {
        [$handle, $http] = $this->client($route);
        try {
            $params = ['fixed' => ['name' => 'fixed', 'encoding' => 'scalar'],
                'users' => ['name' => 'user', 'encoding' => 'numbered']];
            $this->invoke(
                $handle,
                $method,
                $reverse ? array_reverse($params, true) : $params,
                ['fixed' => 'already-encoded', 'users' => ['valid', 'also-valid', null]],
            );
            self::fail('A whole collection must be validated before I/O.');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testMultipartGeneratedReservationFailsBeforeFiltering(): void
    {
        [$handle, $http] = $this->client('bridge');
        self::assertInstanceOf(UapiClient::class, $handle);
        $handle->call('SSL', 'probe');
        $spec = ['module' => 'SSL', 'function' => 'probe', 'method' => 'POST', 'body' => 'multipart',
            'outcome' => 'flag', 'returns' => null,
            'params' => ['key' => ['name' => 'cpanel.user', 'encoding' => 'scalar']]];
        try {
            (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, ['key' => 'private-key-secret']);
            self::fail('Multipart fields cannot override bridge routing.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('private-key-secret', $error->getMessage());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testDirectGeneratedFieldsKeepDirectRoutingSemantics(): void
    {
        [$handle, $http] = $this->client('direct');
        $this->invoke(
            $handle,
            'GET',
            ['target' => ['name' => 'cpanel.user', 'encoding' => 'scalar']],
            ['target' => 'literal%20'],
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2083/execute/Email/probe?cpanel.user=literal%2520',
            (string) $request->getUri(),
        );
    }

    public function testGenericNumberedOverlapRetainsItsLegacyWireBytes(): void
    {
        [$handle, $http] = $this->client('whm');
        self::assertInstanceOf(WhmClient::class, $handle);
        $handle->call('probe', ['user' => new NumberedList(['a', 'b']),
            'user-1' => 'c', 'api.version' => 9]);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('https://api.example.test:2087/json-api/probe?api.version=1', (string) $request->getUri());
        self::assertSame('user=a&user-1=b&user-1=c', (string) $request->getBody());
    }

    /** @param array<string, array{name: string, encoding: string}> $params
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
