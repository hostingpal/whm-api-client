<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

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

final class GeneratedDelimitedCollectionInputsTest extends TestCase
{
    private const RULES = ['kind' => 'delimited', 'item_type' => 'string', 'allow_scalar' => true,
        'empty' => 'reject', 'delimiter' => '|'];

    /** @return iterable<array{string, string}> */
    public static function routes(): iterable
    {
        foreach (['whm', 'direct', 'bridge'] as $route) {
            foreach (['GET', 'POST'] as $method) {
                yield [$route, $method];
            }
        }
    }

    #[DataProvider('routes')]
    public function testOneEncodedFieldAndNoPartialRequestAfterFailure(string $route, string $method): void
    {
        [$handle, $http] = $this->client($route);
        $params = ['value' => ['name' => 'value', 'encoding' => 'collection', 'collection' => self::RULES]];
        $this->invoke($handle, $method, $params, ['value' => ['', 'a%20&=é', '']]);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        $fields = $method === 'POST' ? (string) $request->getBody() : $request->getUri()->getQuery();
        self::assertStringEndsWith('value=%7Ca%2520%26%3D%C3%A9%7C', $fields);
        self::assertSame(1, substr_count($fields, 'value='));
        self::assertNotNull($handle->responseMeta());
        try {
            $this->invoke($handle, $method, $params, ['value' => ['first-secret', 'late|secret']]);
            self::fail('Delimiter-bearing item accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testRoutingCollisionRetainsMetadataReset(): void
    {
        foreach (['api.version', 'cpanel.user', 'cpanel.module', 'cpanel.function'] as $wire) {
            [$handle, $http] = $this->client('bridge');
            try {
                $this->invoke($handle, 'POST', ['value' => ['name' => $wire, 'encoding' => 'collection',
                    'collection' => ['allow_scalar' => false] + self::RULES]], ['value' => ['secret']]);
                self::fail('Reserved routing accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
                self::assertNull($handle->responseMeta());
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    public function testOmittedStaticOverlapClearsMetadataInBothOrders(): void
    {
        foreach ([false, true] as $reverse) {
            [$handle, $http] = $this->client('bridge');
            self::assertInstanceOf(UapiClient::class, $handle);
            $handle->call('Email', 'probe');
            self::assertNotNull($handle->responseMeta());
            $params = ['value' => ['name' => 'value-1', 'encoding' => 'collection',
                'collection' => ['allow_scalar' => false] + self::RULES],
                'indexed' => ['name' => 'list', 'encoding' => 'collection', 'collection' => [
                    'kind' => 'indexed', 'item_type' => 'string', 'allow_scalar' => false,
                    'empty' => 'reject', 'prefix' => 'value', 'separator' => '-', 'start' => 1]]];
            try {
                $this->invoke($handle, 'GET', $reverse ? array_reverse($params, true) : $params, []);
                self::fail('Omitted parameters concealed a static overlap.');
            } catch (LogicException $error) {
                self::assertStringContainsString('Overlapping', $error->getMessage());
                self::assertNull($handle->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
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
