<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TypedJsonBodyTest extends TestCase
{
    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private static function spec(bool $direct, bool $required, array $schema): array
    {
        return [
            'function' => 'save', ...($direct ? ['module' => 'Example'] : []),
            'method' => 'POST', 'body' => 'json', 'outcome' => 'flag',
            'json_body' => [
                'php' => 'configuration', 'required' => $required, 'routes' => 'direct', 'schema' => $schema,
            ],
            'params' => ['query' => ['name' => 'q', 'encoding' => 'scalar']],
            'returns' => ['type' => 'int'],
        ];
    }

    public function testLiteralPresenceKindsRoutesAndQuery(): void
    {
        foreach ([false, true] as $direct) {
            foreach (
                [
                [false, ['type' => 'object', 'fields' => []], null, ''],
                [false, ['type' => 'object', 'fields' => []], [], '{}'],
                [false, ['type' => 'list', 'items' => ['type' => 'string']], [], '[]'],
                [true, ['type' => 'object', 'fields' => [], 'nullable' => true], null, 'null'],
                [true, ['type' => 'bool'], false, 'false'],
                [true, ['type' => 'int'], 0, '0'],
                [true, ['type' => 'string'], '', '""'],
                [true, ['type' => 'object', 'fields' => [
                    'a' => ['type' => 'string', 'nullable' => true], 'b' => ['type' => 'string'],
                ]], ['a' => null], '{"a":null}'],
                ] as [$required, $schema, $input, $wire]
            ) {
                [$client, $http] = self::client($direct);
                $handle = $direct ? $client->uapi() : $client->whm();
                $http->addResponse(self::success($direct));
                self::assertSame(7, (new ReflectionMethod($handle, 'run'))->invoke(
                    $handle,
                    self::spec($direct, $required, $schema),
                    ['configuration' => $input, 'query' => 'a +%/'],
                ));
                self::assertCount(1, $http->getRequests());
                $request = $http->getRequests()[0];
                self::assertSame($wire, (string) $request->getBody());
                self::assertSame($wire === '' ? '' : 'application/json', $request->getHeaderLine('Content-Type'));
                self::assertSame(
                    $direct ? 'cpanel alpha:fixture-token' : 'whm root:fixture-token',
                    $request->getHeaderLine('Authorization'),
                );
                self::assertSame($direct ? '/execute/Example/save' : '/json-api/save', $request->getUri()->getPath());
                self::assertSame(($direct ? '' : 'api.version=1&') . 'q=a%20%2B%25%2F', $request->getUri()->getQuery());
                self::assertNotNull($handle->responseMeta());
            }
        }
    }

    public function testCompleteConfigurationAndOneAttemptOutcome(): void
    {
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $handle = $direct ? $client->uapi() : $client->whm();
            $spec = self::spec($direct, true, ['type' => 'object', 'required' => ['changes'], 'fields' => [
                'changes' => ['type' => 'list', 'nonempty' => true, 'items' => [
                    'type' => 'object', 'required' => ['domain', 'analyzers'], 'fields' => [
                        'domain' => ['type' => 'string'],
                        'analyzers' => ['type' => 'list', 'items' => [
                            'type' => 'object', 'required' => ['name', 'enabled'],
                            'fields' => ['name' => ['type' => 'string'], 'enabled' => ['type' => 'flag']],
                        ]],
                    ],
                ]],
            ]]);
            $input = ['changes' => [
                ['domain' => 'example.test', 'analyzers' => [['name' => 'awstats', 'enabled' => false]]],
                ['domain' => 'other.test', 'analyzers' => []],
            ]];
            foreach (['success', 'api error', 'payload error'] as $outcome) {
                $response = $outcome === 'success' ? self::success($direct) : new Response(200, [], $direct
                    ? ($outcome === 'api error'
                        ? '{"status":0,"errors":["denied"],"data":7}' : '{"status":1,"data":"bad"}')
                    : ($outcome === 'api error'
                        ? '{"metadata":{"result":0,"reason":"denied"},"data":7}'
                        : '{"metadata":{"result":1},"data":"bad"}'));
                $http->addResponse($response);
                $before = count($http->getRequests());
                try {
                    (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, ['configuration' => $input]);
                    self::assertSame('success', $outcome);
                } catch (ApiErrorException | PayloadMismatchException $error) {
                    self::assertSame(
                        $outcome === 'api error' ? ApiErrorException::class : PayloadMismatchException::class,
                        $error::class,
                    );
                    if ($error instanceof PayloadMismatchException) {
                        self::assertTrue($error->outcomeConfirmed);
                    }
                }
                self::assertCount($before + 1, $http->getRequests());
                self::assertSame(
                    '{"changes":[{"domain":"example.test","analyzers":[{"name":"awstats","enabled":0}]},'
                    . '{"domain":"other.test","analyzers":[]}]}',
                    (string) $http->getRequests()[$before]->getBody(),
                );
                self::assertNotNull($handle->responseMeta());
            }
            foreach (
                [
                    null, [], ['changes' => []], ['changes' => [[]]], ['changes' => [['domain' => 'x']]],
                    ['changes' => [['domain' => 'x', 'analyzers' => [[]]]]],
                    ['changes' => [['domain' => 'x', 'analyzers' => [['name' => 'x']]]]],
                    ['changes' => [['domain' => 'x', 'analyzers' => [['enabled' => false]]]]],
                    ['changes' => [['domain' => 'x', 'analyzers' => [['name' => 'x', 'enabled' => 1]]]]],
                    $input + ['secret-dynamic-key' => 'secret-value'],
                ] as $invalid
            ) {
                $before = count($http->getRequests());
                try {
                    (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, ['configuration' => $invalid]);
                    self::fail('Invalid body was accepted.');
                } catch (InvalidArgumentException $error) {
                    self::assertStringNotContainsString('secret-', (string) $error);
                    self::assertNull($error->getPrevious());
                }
                self::assertCount($before, $http->getRequests());
                self::assertNull($handle->responseMeta());
            }
        }
    }

    public function testReservedNamesSchemaErrorsAndBridgeFailBeforeIO(): void
    {
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $handle = $direct ? $client->uapi() : $client->whm();
            $schema = ['type' => 'object', 'fields' => [], 'values' => ['type' => 'string', 'nullable' => true]];
            foreach (
                ['api.', 'api.future', 'api.columns_a',
                    ...($direct ? ['cpanel.user', 'cpanel.module', 'cpanel.function'] : [])] as $name
            ) {
                foreach ([true, false] as $body) {
                    $spec = self::spec($direct, true, $schema);
                    $spec['params'] = $body ? [] : ['query' => ['name' => $name, 'encoding' => 'scalar']];
                    try {
                        (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, [
                            'configuration' => $body ? [$name => null] : [],
                            ...($body ? [] : ['query' => null]),
                        ]);
                        self::fail('Reserved routing input was accepted.');
                    } catch (InvalidArgumentException) {
                        self::assertCount(0, $http->getRequests());
                    }
                }
            }
            foreach (
                [
                ['type' => 'int', 'accept' => ['numeric-string']],
                    ['type' => 'object', 'fields' => [], 'required' => ['missing']]] as $bad
            ) {
                try {
                    (new ReflectionMethod($handle, 'run'))->invoke(
                        $handle,
                        self::spec($direct, true, $bad),
                        ['configuration' => []],
                    );
                    self::fail('Malformed trusted body spec was accepted.');
                } catch (LogicException) {
                    self::assertCount(0, $http->getRequests());
                    self::assertNull($handle->responseMeta());
                }
            }
        }
        [$client, $http] = self::client(false);
        $handle = $client->uapi('alpha');
        $this->expectException(InvalidArgumentException::class);
        try {
            (new ReflectionMethod($handle, 'run'))->invoke(
                $handle,
                self::spec(true, false, ['type' => 'object', 'fields' => []]),
                ['configuration' => null],
            );
        } finally {
            self::assertCount(0, $http->getRequests());
            self::assertNull($handle->responseMeta());
        }
    }

    public function testRequiredBodyIsEnforcedAndLateErrorsClearMetadata(): void
    {
        [$client, $http] = self::client(true);
        $handle = $client->uapi();
        $spec = self::spec(true, true, ['type' => 'object', 'fields' => [
            'first' => ['type' => 'string'], 'last' => ['type' => 'int'],
        ]]);
        foreach ([[], ['configuration' => ['first' => 'secret-value', 'last' => '7']]] as $arguments) {
            $http->addResponse(self::success(true));
            (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, ['configuration' => []]);
            self::assertNotNull($handle->responseMeta());
            $count = count($http->getRequests());
            try {
                (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, $arguments);
                self::fail('Missing or late invalid body was accepted.');
            } catch (InvalidArgumentException) {
                self::assertNull($handle->responseMeta());
                self::assertCount($count, $http->getRequests());
            }
        }
    }

    public function testExpandedQueryGuardLookalikesAndSensitiveFrames(): void
    {
        [$client, $http] = self::client(true);
        $handle = $client->uapi();
        $schema = ['type' => 'object', 'fields' => [], 'values' => ['type' => 'string']];
        $spec = self::spec(true, true, $schema);
        $spec['params'] = ['query' => ['name' => 'dynamic', 'encoding' => 'collection', 'collection' => [
            'kind' => 'map', 'prefix' => '', 'item_type' => 'string', 'allow_scalar' => false, 'empty' => 'allow',
        ]]];
        try {
            (new ReflectionMethod($handle, 'run'))->invoke(
                $handle,
                $spec,
                ['configuration' => [], 'query' => ['api.future' => 'x']],
            );
            self::fail('Expanded JSON query bypassed reserved names.');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $http->getRequests());
        }
        $spec = self::spec(true, true, $schema);
        $http->addResponse(self::success(true));
        $input = ['Api.version' => 'a', 'API.version' => 'b', 'api_version' => 'c', 'xapi.version' => 'd'];
        (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, ['configuration' => $input]);
        self::assertSame(
            '{"Api.version":"a","API.version":"b","api_version":"c","xapi.version":"d"}',
            (string) $http->getRequests()[0]->getBody(),
        );
        $run = (new ReflectionMethod($handle, 'run'))->getClosure($handle);
        $prior = ini_set('zend.exception_ignore_args', '0');
        try {
            $run(self::spec(true, true, ['type' => 'object', 'fields' => []]), [
                'configuration' => ['secret-dynamic-key' => 'secret-json-value'],
            ]);
            self::fail('Unknown body member was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret-dynamic-key', (string) $error);
            self::assertStringNotContainsString('secret-json-value', (string) $error);
            self::assertNull($error->getPrevious());
            self::assertNull($handle->responseMeta());
        } finally {
            if ($prior !== false) {
                ini_set('zend.exception_ignore_args', $prior);
            }
        }
    }

    private static function success(bool $direct): Response
    {
        return new Response(200, [], $direct ? '{"status":1,"data":7}' : '{"metadata":{"result":1},"data":7}');
    }

    /** @return array{Client, MockClient} */
    private static function client(bool $direct): array
    {
        $http = new MockClient();
        $factory = new Psr17Factory();
        return [new Client([
            'host' => 'api.example.test', 'port' => 2443, 'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'alpha' : 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
