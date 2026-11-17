<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\CallerJson;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\JsonList;
use HostingPal\WhmApiClient\JsonObject;
use HostingPal\WhmApiClient\Tests\Fixtures\StrictJsonCaller;
use HostingPal\WhmApiClient\Tests\Fixtures\WeakJsonCaller;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Stringable;
use TypeError;

/** Actual generated native signatures, invoked from ordinary weak and strict calling files. */
final class TypedJsonCallerModeTest extends TestCase
{
    public function testInvalidNativeArrayArgumentsFailBeforeEntryInEitherCallerMode(): void
    {
        foreach ([StrictJsonCaller::class, WeakJsonCaller::class] as $caller) {
            [$handle, $http, $group] = self::fixture();
            $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
            $handle->statsManager()->getConfiguration();
            $before = $handle->responseMeta();
            self::assertNotNull($before);
            $stringable = new class implements Stringable {
                public int $calls = 0;

                public function __toString(): string
                {
                    $this->calls++;
                    return 'converted';
                }
            };
            foreach ([null, 'false', 7, false, $stringable] as $input) {
                $caught = null;
                try {
                    $caller::call($group, 'sendObject', $input);
                } catch (TypeError $error) {
                    $caught = $error;
                }
                self::assertNotNull($caught);
                self::assertSame(TypeError::class, $caught::class);
                self::assertSame($before, $handle->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
            self::assertSame(0, $stringable->calls);
        }
    }

    public function testStatsNestedFlagsStayStrictInEitherCallerMode(): void
    {
        foreach ([StrictJsonCaller::class, WeakJsonCaller::class] as $caller) {
            [$handle, $http] = self::fixture();
            foreach ([1, 'false'] as $invalid) {
                $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
                $handle->statsManager()->getConfiguration();
                self::assertNotNull($handle->responseMeta());
                $before = count($http->getRequests());
                try {
                    $caller::call($handle->statsManager(), 'saveConfiguration', ['changes' => [
                        ['domain' => 'example.test', 'analyzers' => [['name' => 'awstats', 'enabled' => $invalid]]],
                    ]]);
                    self::fail('Nested scalar was coerced.');
                } catch (InvalidArgumentException) {
                    self::assertCount($before, $http->getRequests());
                    self::assertNull($handle->responseMeta());
                }
            }
        }
    }

    public function testAcceptedNumericLookalikeNamesProduceValidDescriptorAndExactWireNames(): void
    {
        [$handle, $http, $group] = self::fixture();
        $input = [
            '' => 'v', '00' => 'v', '01' => 'v', '+1' => 'v', '-0' => 'v', '-01' => 'v',
            '1.0' => 'v', '1e1' => 'v', ' 1' => 'v', '1 ' => 'v',
            '9223372036854775808' => 'v', '-9223372036854775809' => 'v',
        ];
        $http->addResponse(self::success());
        self::assertSame(7, $group->sendObject($input));
        self::assertSame(
            '{"":"v","00":"v","01":"v","+1":"v","-0":"v","-01":"v",'
            . '"1.0":"v","1e1":"v"," 1":"v","1 ":"v",'
            . '"9223372036854775808":"v","-9223372036854775809":"v"}',
            (string) $http->getRequests()[0]->getBody(),
        );
        self::assertNotNull($handle->responseMeta());
        try {
            WeakJsonCaller::call($group, 'sendObject', $input + [0 => 'v']);
            self::fail('Integer caller key was accepted.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $http->getRequests());
            self::assertNull($handle->responseMeta());
        }
    }


    public function testExplicitOpenGeneratedBodyAndFieldPreserveKindsInBothCallerModes(): void
    {
        foreach ([StrictJsonCaller::class, WeakJsonCaller::class] as $caller) {
            [$handle, $http, $group] = self::fixture();
            foreach (['sendOpen', 'sendField'] as $method) {
                $http->addResponse(self::success());
                $input = JsonObject::fromEntries([
                    ['name' => 'known', 'value' => 7],
                    ['name' => '0', 'value' => JsonList::fromArray([
                        JsonObject::fromArray([]), null, false, 1.0,
                    ])],
                ]);
                self::assertSame(7, $caller::call($group, $method, $input));
                $request = $http->getRequests()[count($http->getRequests()) - 1];
                $expected = '{"known":7,"0":[{},null,false,1.0]}';
                self::assertSame($method === 'sendOpen' ? $expected
                    : 'configuration=' . rawurlencode($expected), (string) $request->getBody());
                self::assertSame(
                    $method === 'sendOpen' ? 'application/json' : 'application/x-www-form-urlencoded',
                    $request->getHeaderLine('Content-Type'),
                );
                self::assertNotNull($handle->responseMeta());
                foreach (
                    [JsonObject::fromArray([]),
                    JsonObject::fromArray(['known' => '7']),
                    JsonObject::fromArray(['known' => 7, 'private-name' => []]),
                    JsonObject::fromEntries([
                    ['name' => 'known', 'value' => 7],
                        ['name' => 'private-name', 'value' => 1], ['name' => 'private-name', 'value' => 2]]),
                    JsonList::fromArray([])] as $bad
                ) {
                    $before = count($http->getRequests());
                    try {
                        $caller::call($group, $method, $bad);
                        self::fail('Invalid explicit JSON accepted.');
                    } catch (TypeError) {
                        self::assertInstanceOf(JsonList::class, $bad);
                    } catch (InvalidArgumentException $error) {
                        self::assertNull($handle->responseMeta());
                        self::assertStringNotContainsString('private-', $error->getMessage());
                    }
                    self::assertCount($before, $http->getRequests());
                }
            }
        }
    }


    public function testOpenNativeUnionsAndFactoriesCannotCoerceOrInvokeStringableInEitherMode(): void
    {
        foreach ([StrictJsonCaller::class, WeakJsonCaller::class] as $caller) {
            [$handle, $http, $group] = self::fixture();
            $http->addResponse(self::success());
            $caller::call($group, 'sendOpen', ['known' => 7]);
            $before = $handle->responseMeta();
            self::assertNotNull($before);
            $stringable = new class implements Stringable {
                public int $calls = 0;

                public function __toString(): string
                {
                    $this->calls++;
                    return 'private-value';
                }
            };
            foreach (['sendOpen', 'sendField'] as $method) {
                foreach ([null, '{}', 7, false, $stringable, JsonList::fromArray([])] as $bad) {
                    $caught = null;
                    try {
                        $caller::call($group, $method, $bad);
                    } catch (TypeError $error) {
                        $caught = $error;
                    }
                    self::assertNotNull($caught);
                    self::assertSame($before, $handle->responseMeta());
                    self::assertCount(1, $http->getRequests());
                }
            }
            foreach (
                [
                [JsonObject::class, 'fromArray'], [JsonObject::class, 'fromEntries'],
                [JsonList::class, 'fromArray']] as [$class, $method]
            ) {
                $caught = null;
                try {
                    $caller::factory($class, $method, $stringable);
                } catch (TypeError $error) {
                    $caught = $error;
                }
                self::assertNotNull($caught);
                self::assertSame($before, $handle->responseMeta());
            }
            self::assertSame(0, $stringable->calls);
        }
    }

    /** @return array{UapiClient, MockClient, CallerJson} */
    private static function fixture(): array
    {
        require_once __DIR__ . '/../Fixtures/TypedJson/Uapi/Spec/CallerJsonSpec.php';
        require_once __DIR__ . '/../Fixtures/TypedJson/Uapi/CallerJson.php';

        $http = new MockClient();
        $factory = new Psr17Factory();
        $client = new Client([
            'host' => 'api.example.test', 'port' => 2443, 'authType' => 'cpanel',
            'username' => 'alpha', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory));
        $handle = $client->uapi();
        // Reuse the exact bound runner from a generated accessor. Only its closure
        // is inspected: every test call enters the fixture's public native method.
        $runner = (new ReflectionProperty($handle->statsManager(), 'run'))->getValue($handle->statsManager());
        self::assertInstanceOf(Closure::class, $runner);
        return [$handle, $http, new CallerJson($runner)];
    }

    private static function success(): Response
    {
        return new Response(200, [], '{"status":1,"data":7}');
    }
}
