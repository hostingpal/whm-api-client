<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RequestMethod;
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
        } catch (LogicException $error) {
            self::assertSame(LogicException::class, $error::class);
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function reservations(): iterable
    {
        foreach (['GET', 'POST'] as $method) {
            foreach (['whm', 'direct', 'bridge'] as $route) {
                foreach (
                    ['api.', 'api.version', 'api.filter.enable', 'api.sort.enable',
                    'api.chunk.enable', 'api.columns.enable'] as $name
                ) {
                    yield $route . $name . $method => [$route, $method, $name];
                }
                if ($route !== 'whm') {
                    foreach (['cpanel.user', 'cpanel.module', 'cpanel.function'] as $name) {
                        yield $route . $name . $method => [$route, $method, $name];
                    }
                }
            }
        }
    }

    #[DataProvider('reservations')]
    public function testGeneratedReservedFieldsFailBeforeFiltering(string $route, string $method, string $name): void
    {
        $spec = ['function' => 'probe', 'method' => $method, 'outcome' => 'flag', 'returns' => null,
            'params' => ['target' => ['name' => $name, 'encoding' => 'repeat']]];
        if ($route !== 'whm') {
            $spec['module'] = 'Email';
        }
        try {
            SpecChecker::check($spec);
            self::fail('Reserved declarations must fail the offline lint.');
        } catch (LogicException $error) {
            self::assertSame(LogicException::class, $error::class);
        }
        foreach (
            [
            [], ['target' => null], ['target' => ['same-secret']],
            ['target' => ['same-secret', 'same-secret']]] as $arguments
        ) {
            [$handle, $http] = $this->client($route);
            $handle instanceof WhmClient ? $handle->call('probe') : $handle->call('Email', 'probe');
            try {
                $this->invoke($handle, $method, $spec['params'], $arguments);
                self::fail('Reserved declarations are malformed even when omitted or singleton.');
            } catch (LogicException $error) {
                self::assertSame(LogicException::class, $error::class);
                self::assertStringNotContainsString('same-secret', $error->getMessage());
                self::assertNull($handle->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    #[DataProvider('reservations')]
    public function testRuntimeReservationsDefendExpandedFields(string $route, string $method, string $name): void
    {
        [$handle, $http] = $this->client($route);
        if ($route === 'direct' && str_starts_with($name, 'cpanel.')) {
            $this->expectNotToPerformAssertions();
            return; // Shared-spec lint rejects these; direct runtime ownership stays route-aware.
        }
        $fields = new OrderedFields();
        $fields->append('future collection', $name, 'private');
        $spec = ['function' => 'probe', 'module' => 'Email', 'method' => $method, 'outcome' => 'flag'];
        try {
            (new ReflectionMethod($handle, 'dispatch'))->getClosure($handle)($spec, $fields);
            self::fail('Expanded API controls must fail before request encoding.');
        } catch (InvalidArgumentException $error) {
            self::assertSame(InvalidArgumentException::class, $error::class);
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testDirectRuntimeAllowsExactBridgeControls(): void
    {
        [$handle, $http] = $this->client('direct');
        $fields = new OrderedFields();
        $fields->append('fixture', 'cpanel.user', 'literal');
        (new ReflectionMethod($handle, 'dispatch'))->getClosure($handle)(
            ['function' => 'probe', 'module' => 'Email', 'method' => 'POST', 'outcome' => 'flag'],
            $fields,
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('cpanel.user=literal', (string) $request->getBody());
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
        } catch (LogicException $error) {
            self::assertSame(LogicException::class, $error::class);
            self::assertStringNotContainsString('private-key-secret', $error->getMessage());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedNames(): iterable
    {
        foreach (['whm', 'direct', 'bridge'] as $route) {
            foreach (['api', 'Api.x', 'xapi.foo', 'apiX', 'cpanel.userX'] as $name) {
                yield $route . $name => [$route, $name];
            }
        }
        yield 'WHM bridge control is ordinary data' => ['whm', 'cpanel.user'];
    }

    #[DataProvider('allowedNames')]
    public function testUnreservedLookalikesStayLiteral(string $route, string $name): void
    {
        [$handle, $http] = $this->client($route);
        $this->invoke(
            $handle,
            'POST',
            ['value' => ['name' => $name, 'encoding' => 'scalar']],
            ['value' => 'literal%20'],
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($name . '=literal%2520', (string) $request->getBody());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{array<string, array{name: string, encoding: string}>}> */
    public static function staticOverlaps(): iterable
    {
        foreach (
            [
            ['user', 'numbered', 'user-1', 'scalar'],
            ['user', 'numbered', 'user-99', 'repeat'],
            ['user', 'numbered', 'user-1', 'numbered'],
            ['user', 'numbered', 'user', 'repeat'],
            ['user*', 'suffix-numbered', 'user12', 'numbered'],
            ['user12*', 'suffix-numbered', 'user123', 'numbered'],
            ['user*', 'suffix-numbered', 'user1*', 'suffix-numbered'],
            ['user', 'scalar', 'user', 'scalar']] as [$first, $encoding, $second, $otherEncoding]
        ) {
            $params = ['first' => ['name' => $first, 'encoding' => $encoding],
                'second' => ['name' => $second, 'encoding' => $otherEncoding]];
            yield $first . $encoding . $second . $otherEncoding => [$params];
            yield 'reverse ' . $first . $encoding . $second . $otherEncoding => [array_reverse($params, true)];
        }
    }

    /** @param array<string, array{name: string, encoding: string}> $params */
    #[DataProvider('staticOverlaps')]
    public function testStaticCollisionsFailBeforeArgumentsCanHideThem(array $params): void
    {
        $spec = ['function' => 'probe', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null];
        try {
            SpecChecker::check($spec);
            self::fail('Static collisions must fail the offline lint.');
        } catch (LogicException $error) {
            self::assertSame(LogicException::class, $error::class);
        }
        foreach (['whm', 'direct', 'bridge'] as $route) {
            foreach ([[], ['first' => null, 'second' => null], ['first' => ['one'], 'second' => 'one']] as $arguments) {
                [$handle, $http] = $this->client($route);
                $handle instanceof WhmClient ? $handle->call('probe') : $handle->call('Email', 'probe');
                try {
                    $this->invoke($handle, 'POST', $params, $arguments);
                    self::fail('Omission and first values cannot hide malformed specs.');
                } catch (LogicException $error) {
                    self::assertSame(LogicException::class, $error::class);
                    self::assertNull($handle->responseMeta());
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    public function testNumberedSafeSuffixLookalikesPassLint(): void
    {
        foreach (['user-0', 'user-01', 'user-1x', 'user1*', 'user*'] as $name) {
            SpecChecker::check(['function' => 'probe', 'method' => 'POST', 'outcome' => 'flag', 'returns' => null,
                'params' => ['users' => ['name' => 'user', 'encoding' => 'numbered'],
                    'other' => ['name' => $name, 'encoding' => str_ends_with($name, '*')
                        ? 'suffix-numbered' : 'numbered']]]);
        }
        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('routesAndMethods')]
    public function testGenericControlFilteringRetainsItsExactLegacyBoundary(
        string $route,
        string $method,
        bool $reverse,
    ): void {
        [$handle, $http] = $this->client($route);
        $params = ['api.version' => 9, 'api.filter.enable' => 'yes', 'cpanel.user' => 'literal'];
        $params = $reverse ? array_reverse($params, true) : $params;
        $requestMethod = RequestMethod::from($method);
        $handle instanceof WhmClient ? $handle->call('probe', $params, $requestMethod)
            : $handle->call('Email', 'probe', $params, $requestMethod);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $fields = $method === 'POST' ? (string) $request->getBody() : $request->getUri()->getQuery();
        self::assertStringContainsString('api.filter.enable=yes', $fields);
        self::assertSame($route !== 'bridge', str_contains($fields, 'cpanel.user=literal'));
        self::assertSame($route === 'direct', str_contains($fields, 'api.version=9'));
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
