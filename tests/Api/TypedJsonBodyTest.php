<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\JsonList;
use HostingPal\WhmApiClient\JsonObject;
use HostingPal\WhmApiClient\Tests\Fixtures\StrictJsonCaller;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
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
            'params' => [],
            'returns' => ['type' => 'int'],
        ];
    }

    public function testOptionalDirectObjectAbsenceAndPresentEmptyObjectStayDistinct(): void
    {
        foreach ([[], ['configuration' => null], ['configuration' => []]] as $arguments) {
            $input = $arguments['configuration'] ?? null;
            [$client, $http] = self::client(true);
            $handle = $client->uapi();
            $http->addResponse(self::success(true));
            self::assertSame(7, (new ReflectionMethod($handle, 'run'))->invoke(
                $handle,
                self::spec(true, false, ['type' => 'object', 'fields' => []]),
                $arguments,
            ));
            $request = $http->getRequests()[0];
            self::assertSame($input === null ? '' : '{}', (string) $request->getBody());
            self::assertSame($input === null ? '' : 'application/json', $request->getHeaderLine('Content-Type'));
            self::assertSame('', $request->getUri()->getQuery());
            self::assertSame('/execute/Example/save', $request->getUri()->getPath());
            self::assertSame('cpanel alpha:fixture-token', $request->getHeaderLine('Authorization'));
            self::assertCount(1, $http->getRequests());
            self::assertNotNull($handle->responseMeta());
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
                    ($direct ? '{' : '{"api.version":1,') . '"changes":[{"domain":"example.test","analyzers":['
                    . '{"name":"awstats","enabled":0}]},'
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

    public function testLookalikesAndSensitiveFrames(): void
    {
        [$client, $http] = self::client(true);
        $handle = $client->uapi();
        $schema = ['type' => 'object', 'fields' => [], 'values' => ['type' => 'string']];
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


    public function testExplicitOpenObjectsRetainRoutingReservationsAndResetBeforeContentValidation(): void
    {
        $schema = ['type' => 'object', 'fields' => [], 'values' => ['type' => 'json']];
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $handle = $direct ? $client->uapi() : $client->whm();
            $run = (new ReflectionMethod($handle, 'run'))->getClosure($handle);
            $spec = self::spec($direct, true, $schema);
            $reservations = ['api.', 'api.version', 'api.future',
                ...($direct ? ['cpanel.user', 'cpanel.module', 'cpanel.function'] : [])];
            foreach ($reservations as $reserved) {
                foreach ([false, true] as $entries) {
                    $http->addResponse(self::success($direct));
                    $run($spec, ['configuration' => JsonObject::fromArray([])]);
                    self::assertNotNull($handle->responseMeta());
                    $before = count($http->getRequests());
                    $bad = $entries ? JsonObject::fromEntries([['name' => $reserved, 'value' => null]])
                        : JsonObject::fromArray([$reserved => null]);
                    self::assertNotNull($handle->responseMeta(), 'Factory content checks must wait for the attempt.');
                    try {
                        $run($spec, ['configuration' => $bad]);
                        self::fail('Reserved root member accepted.');
                    } catch (InvalidArgumentException) {
                        self::assertNull($handle->responseMeta());
                        self::assertCount($before, $http->getRequests());
                    }
                }
            }
            $http->addResponse(self::success($direct));
            $run($spec, ['configuration' => JsonObject::fromArray([
                'nested' => JsonObject::fromEntries([['name' => 'api.future', 'value' => null]]),
                'Api.version' => 0,
            ])]);
            $request = $http->getRequests()[count($http->getRequests()) - 1];
            self::assertSame(
                ($direct ? '{' : '{"api.version":1,') . '"nested":{"api.future":null},"Api.version":0}',
                (string) $request->getBody(),
            );
            foreach (
                [StrictJsonCaller::factory(JsonList::class, 'fromArray', [1 => 'private-value']),
                JsonObject::fromEntries([['name' => 'private-name', 'value' => []]]),
                StrictJsonCaller::factory(JsonObject::class, 'fromEntries', [['name' => 'private-name']])] as $bad
            ) {
                $http->addResponse(self::success($direct));
                $run($spec, ['configuration' => []]);
                $before = count($http->getRequests());
                try {
                    $run($spec, ['configuration' => ['late' => $bad]]);
                    self::fail('Invalid nested explicit content accepted.');
                } catch (InvalidArgumentException $error) {
                    self::assertNull($handle->responseMeta());
                    self::assertCount($before, $http->getRequests());
                    self::assertStringNotContainsString('private-', $error->getMessage());
                }
            }
        }
    }

    public function testOptionalOpenObjectAndBridgePoliciesRemainUnchanged(): void
    {
        $schema = ['type' => 'object', 'fields' => [], 'values' => ['type' => 'json']];
        [$client, $http] = self::client(true);
        $handle = $client->uapi();
        $run = (new ReflectionMethod($handle, 'run'))->getClosure($handle);
        foreach ([null, JsonObject::fromArray([]), []] as $value) {
            $http->addResponse(self::success(true));
            $run(self::spec(true, false, $schema), ['configuration' => $value]);
            $request = $http->getRequests()[count($http->getRequests()) - 1];
            self::assertSame($value === null ? '' : '{}', (string) $request->getBody());
        }
        [$client, $http] = self::client(false);
        $handle = $client->uapi('alpha');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":7}}}'));
        $handle->call('Example', 'read');
        self::assertNotNull($handle->responseMeta());
        try {
            (new ReflectionMethod($handle, 'run'))->invoke(
                $handle,
                self::spec(true, true, $schema),
                ['configuration' => JsonObject::fromArray([])],
            );
            self::fail('Bridged JSON accepted.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
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
