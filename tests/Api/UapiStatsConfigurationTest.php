<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use ArgumentCountError;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;
use TypeError;

/** Independent request/response fixtures for the pinned StatsManager JSON operation. */
final class UapiStatsConfigurationTest extends TestCase
{
    public function testDirectCompleteBodyUsesOneJsonPostAndNativeBooleanMapping(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
        $body = ['changes' => [
            ['domain' => 'example.test', 'analyzers' => [
                ['name' => 'awstats', 'enabled' => true], ['name' => 'analog', 'enabled' => false],
            ]],
            ['domain' => "special+%/\"\\\n.test", 'analyzers' => [
                ['name' => 'cpanel.user', 'enabled' => false], ['name' => 'api.version', 'enabled' => true],
            ]],
            ['domain' => 'empty.test', 'analyzers' => []],
        ]];
        self::assertSame([], $client->uapi()->statsManager()->saveConfiguration($body));
        self::assertCount(1, $http->getRequests());
        $request = $http->getRequests()[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2443/execute/StatsManager/save_configuration',
            (string) $request->getUri(),
        );
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('cpanel alpha:fixture-token', $request->getHeaderLine('Authorization'));
        $specialJson = <<<'JSON'
special+%\/\"\\\n.test
JSON;
        self::assertSame(
            '{"changes":[{"domain":"example.test","analyzers":[{"name":"awstats","enabled":1},'
            . '{"name":"analog","enabled":0}]},{"domain":"' . $specialJson . '","analyzers":['
            . '{"name":"cpanel.user","enabled":0},{"name":"api.version","enabled":1}]},'
            . '{"domain":"empty.test","analyzers":[]}]}',
            (string) $request->getBody(),
        );
        self::assertSame(1, $client->uapi()->responseMeta()?->uapiStatus());
    }

    public function testRequiredNativeArgumentAndSensitiveFrameContract(): void
    {
        [$client, $http] = self::client();
        $group = $client->uapi()->statsManager();
        $method = new ReflectionMethod($group, 'saveConfiguration');
        $parameter = $method->getParameters()[0];
        self::assertSame('configuration', $parameter->getName());
        self::assertFalse($parameter->isOptional());
        self::assertFalse($parameter->allowsNull());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        try {
            $method->invoke($group);
            self::fail('Missing configuration accepted.');
        } catch (ArgumentCountError) {
            self::assertCount(0, $http->getRequests());
        }
        try {
            $method->invoke($group, null);
            self::fail('Null configuration accepted.');
        } catch (TypeError) {
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty body' => [[]];
        yield 'empty changes' => [['changes' => []]];
        yield 'null changes' => [['changes' => null]];
        yield 'scalar changes' => [['changes' => 'bad']];
        yield 'keyed changes' => [['changes' => ['domain' => []]]];
        yield 'sparse changes' => [['changes' => [1 => ['domain' => 'x', 'analyzers' => []]]]];
        yield 'empty row' => [['changes' => [[]]]];
        yield 'missing domain' => [['changes' => [['analyzers' => []]]]];
        yield 'missing analyzers' => [['changes' => [['domain' => 'x']]]];
        yield 'null domain' => [['changes' => [['domain' => null, 'analyzers' => []]]]];
        yield 'integer domain' => [['changes' => [['domain' => 1, 'analyzers' => []]]]];
        yield 'null analyzers' => [['changes' => [['domain' => 'x', 'analyzers' => null]]]];
        yield 'keyed analyzers' => [['changes' => [['domain' => 'x', 'analyzers' => ['a' => []]]]]];
        yield 'sparse analyzers' => [['changes' => [['domain' => 'x', 'analyzers' => [1 => []]]]]];
        yield 'empty analyzer row' => [['changes' => [['domain' => 'x', 'analyzers' => [[]]]]]];
        yield 'missing name' => [self::analyzer(['enabled' => true])];
        yield 'missing enabled' => [self::analyzer(['name' => 'awstats'])];
        foreach ([null, 1, 0, '1', '0', 1.0, [], new \stdClass()] as $value) {
            yield 'nonbool enabled ' . get_debug_type($value) . ':' . serialize($value) => [
                self::analyzer(['name' => 'awstats', 'enabled' => $value]),
            ];
        }
        yield 'null name' => [self::analyzer(['name' => null, 'enabled' => true])];
        yield 'bool name' => [self::analyzer(['name' => false, 'enabled' => true])];
        yield 'unknown analyzer' => [self::analyzer([
            'name' => 'x', 'enabled' => true, 'secret-key' => 'secret-value',
        ])];
        yield 'integer analyzer key' => [self::analyzer(['name' => 'x', 'enabled' => true, 0 => 'secret-value'])];
        yield 'unknown root' => [self::complete() + ['secret-key' => 'secret-value']];
        yield 'integer root key' => [self::complete() + [0 => 'secret-value']];
        yield 'reserved api root' => [self::complete() + ['api.future' => null]];
        yield 'reserved account root' => [self::complete() + ['cpanel.user' => null]];
        yield 'unknown change' => [['changes' => [['domain' => 'x', 'analyzers' => [], 'extra' => true]]]];
        yield 'integer change key' => [['changes' => [['domain' => 'x', 'analyzers' => [], 0 => true]]]];
        yield 'late invalid change' => [['changes' => [
            ['domain' => 'secret-value', 'analyzers' => [['name' => 'awstats', 'enabled' => true]]],
            ['domain' => 'late.test', 'analyzers' => [['name' => 'x', 'enabled' => '1']]],
        ]]];
    }

    /** @param array<mixed> $configuration */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidBodiesRejectBeforeIoAndResetStaleMetadata(array $configuration): void
    {
        [$client, $http] = self::client();
        $handle = $client->uapi();
        $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
        $handle->statsManager()->getConfiguration();
        self::assertNotNull($handle->responseMeta());
        $prior = ini_set('zend.exception_ignore_args', '0');
        try {
            (new ReflectionMethod($handle->statsManager(), 'saveConfiguration'))->invoke(
                $handle->statsManager(),
                $configuration,
            );
            self::fail('Invalid configuration accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret-value', (string) $error);
            self::assertStringNotContainsString('secret-key', (string) $error);
            self::assertNull($error->getPrevious());
        } finally {
            if ($prior !== false) {
                ini_set('zend.exception_ignore_args', $prior);
            }
        }
        self::assertCount(1, $http->getRequests());
        self::assertNull($handle->responseMeta());
    }

    public function testBridgeRefusesLocallyAfterClearingMetadata(): void
    {
        [$client, $http] = self::client(false);
        $handle = $client->uapi('alpha');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":[]}}}'));
        $handle->statsManager()->getConfiguration();
        self::assertNotNull($handle->responseMeta());
        try {
            $handle->statsManager()->saveConfiguration(self::complete());
            self::fail('Bridge JSON accepted.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $http->getRequests());
            self::assertNull($handle->responseMeta());
        }
    }

    public function testSuccessWarningsAndSkippedEntriesStayInMetadata(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"status":1,"warnings":["not_owned","not_available","unrecognized"],'
            . '"data":[{"domain":"returned.test","analyzers":[{"name":"awstats",'
            . '"available":1,"enabled":0,"locked":1}]}]}'));
        self::assertSame([
            [
                'analyzers' => [['available' => 1, 'enabled' => 0, 'locked' => 1, 'name' => 'awstats']],
                'domain' => 'returned.test',
            ],
        ], $client->uapi()->statsManager()->saveConfiguration(self::complete()));
        self::assertSame(
            ['not_owned', 'not_available', 'unrecognized'],
            $client->uapi()->responseMeta()?->uapiWarnings(),
        );
        self::assertSame(1, $client->uapi()->responseMeta()->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<mixed>|null, class-string<\Throwable>|null}> */
    public static function outcomes(): iterable
    {
        yield 'empty list' => ['{"status":1,"data":[]}', [], null];
        yield 'optional fields' => [
            '{"status":1,"data":[{}, {"analyzers":[]}, {"analyzers":[{},'
                . '{"name":"other","available":0,"enabled":1,"locked":0}]}]}',
            [
                [],
                ['analyzers' => []],
                ['analyzers' => [
                    [],
                    ['available' => 0, 'enabled' => 1, 'locked' => 0, 'name' => 'other'],
                ]],
            ],
            null,
        ];
        yield 'api failure' => ['{"status":0,"errors":["denied"],"data":[]}', null, ApiErrorException::class];
        yield 'null payload' => ['{"status":1,"data":null}', null, PayloadMismatchException::class];
        yield 'absent payload' => ['{"status":1}', null, PayloadMismatchException::class];
        foreach (['available', 'enabled', 'locked'] as $flag) {
            yield 'string ' . $flag => ['{"status":1,"data":[{"analyzers":[{"' . $flag . '":"1"}]}]}',
                null, PayloadMismatchException::class];
            yield 'null ' . $flag => ['{"status":1,"data":[{"analyzers":[{"' . $flag . '":null}]}]}',
                null, PayloadMismatchException::class];
        }
    }

    /**
     * @param array<mixed>|null $expected
     * @param class-string<\Throwable>|null $errorClass
     */
    #[DataProvider('outcomes')]
    public function testPinnedResponseOptionalFieldsAndOneAttemptFailures(
        string $json,
        ?array $expected,
        ?string $errorClass,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], $json));
        try {
            self::assertSame($expected, $client->uapi()->statsManager()->saveConfiguration(self::complete()));
            self::assertNull($errorClass);
        } catch (ApiErrorException | PayloadMismatchException $error) {
            self::assertSame($errorClass, $error::class);
            if ($error instanceof PayloadMismatchException) {
                self::assertTrue($error->outcomeConfirmed);
            }
        }
        self::assertCount(1, $http->getRequests());
        self::assertNotNull($client->uapi()->responseMeta());
    }

    /** @return array{changes: non-empty-list<array{domain: string, analyzers: list<array{name: string, enabled: bool}>}>} */
    private static function complete(): array
    {
        return ['changes' => [['domain' => 'example.test', 'analyzers' => [['name' => 'awstats', 'enabled' => true]]]]];
    }

    /**
     * @param array<mixed> $row
     * @return array<mixed>
     */
    private static function analyzer(array $row): array
    {
        return ['changes' => [['domain' => 'x', 'analyzers' => [$row]]]];
    }

    /** @return array{Client, MockClient} */
    private static function client(bool $direct = true): array
    {
        $http = new MockClient();
        $factory = new Psr17Factory();
        return [new Client([
            'host' => 'api.example.test', 'port' => 2443, 'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'alpha' : 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
