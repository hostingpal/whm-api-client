<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\CallerJson;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Fixtures\StrictJsonCaller;
use HostingPal\WhmApiClient\Tests\Fixtures\WeakJsonCaller;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use Stringable;
use TypeError;

/** Actual generated native signatures, invoked from ordinary weak and strict calling files. */
final class TypedJsonCallerModeTest extends TestCase
{
    public function testWeakScalarBindingPrecedesStrictSerialization(): void
    {
        [$handle, $http, $group] = self::fixture();
        foreach (
            [
            ['sendFlag', 'false', 'flag', '1'],
            ['sendBool', 'false', 'bool', 'true'],
            ['sendInt', '7', 'int', '7'],
            ['sendString', 17, 'string', '"17"'],
            ] as [$method, $input, $function, $wire]
        ) {
            $http->addResponse(self::success());
            self::assertSame(7, WeakJsonCaller::call($group, $method, $input));
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($wire, (string) $request->getBody());
            self::assertSame('/execute/CallerJson/' . $function, $request->getUri()->getPath());
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
            self::assertSame('cpanel alpha:fixture-token', $request->getHeaderLine('Authorization'));
        }
        self::assertCount(4, $http->getRequests());
        self::assertNotNull($handle->responseMeta());
    }

    public function testStringableConversionRunsBeforeEntryOnlyForWeakCaller(): void
    {
        [$handle, $http, $group] = self::fixture();
        $http->addResponse(self::success());
        $group->sendInt(1);
        $before = $handle->responseMeta();
        self::assertNotNull($before);
        $input = new class ($handle) implements Stringable {
            public int $calls = 0;
            public bool $sawPriorMetadata = false;

            public function __construct(private readonly UapiClient $handle)
            {
            }

            public function __toString(): string
            {
                $this->calls++;
                $this->sawPriorMetadata = $this->handle->responseMeta() !== null;
                return 'converted';
            }
        };
        try {
            StrictJsonCaller::call($group, 'sendString', $input);
            self::fail('Strict caller accepted a Stringable root.');
        } catch (TypeError) {
            self::assertSame(0, $input->calls);
            self::assertSame($before, $handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
        $http->addResponse(self::success());
        self::assertSame(7, WeakJsonCaller::call($group, 'sendString', $input));
        self::assertSame(1, $input->calls);
        self::assertTrue($input->sawPriorMetadata);
        self::assertCount(2, $http->getRequests());
        self::assertSame('"converted"', (string) $http->getRequests()[1]->getBody());
    }

    public function testStrictCallerRejectsScalarCoercionsBeforeMetadataReset(): void
    {
        [$handle, $http, $group] = self::fixture();
        $http->addResponse(self::success());
        $group->sendInt(1);
        $before = $handle->responseMeta();
        self::assertNotNull($before);
        foreach (
            [['sendFlag', 'false'], ['sendBool', 'false'], ['sendInt', '7'], ['sendString', 17]] as [$method, $input]
        ) {
            try {
                StrictJsonCaller::call($group, $method, $input);
                self::fail('Strict caller accepted a mismatched native root.');
            } catch (TypeError) {
                self::assertSame($before, $handle->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
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
