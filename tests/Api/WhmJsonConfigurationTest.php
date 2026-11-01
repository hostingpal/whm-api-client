<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use ArgumentCountError;
use HostingPal\WhmApiClient\Api\Whm\Databases;
use HostingPal\WhmApiClient\Api\Whm\WebServer;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use JsonSerializable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use TypeError;

/** Literal offline fixtures independent of generated schemas and encoders. */
final class WhmJsonConfigurationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function signatures(): iterable
    {
        yield 'read' => ['webServer', 'phpFpmConfigGet'];
        yield 'PHP-FPM write' => ['webServer', 'phpFpmConfigSet'];
        yield 'SQL write' => ['databases', 'updateSqlConfig'];
    }

    #[DataProvider('signatures')]
    public function testRequiredSensitiveArrayMethodsAndLazyGroups(string $selector, string $name): void
    {
        [$client, $http] = self::client();
        $group = self::group($client, $selector);
        self::assertSame($group, self::group($client, $selector));
        self::assertTrue(method_exists($group, $name), 'Missing typed JSON method ' . $name);
        $method = new ReflectionMethod($group, $name);
        self::assertCount(1, $method->getParameters());
        self::assertSame(1, $method->getNumberOfRequiredParameters());
        $parameter = $method->getParameters()[0];
        self::assertSame('configuration', $parameter->getName());
        self::assertSame('array', (string) $parameter->getType());
        self::assertFalse($parameter->allowsNull());
        self::assertFalse($parameter->isDefaultValueAvailable());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        self::assertSame($name === 'phpFpmConfigGet' ? 'array' : 'void', (string) $method->getReturnType());
        foreach ([[], [null], ['scalar']] as $arguments) {
            try {
                $method->invokeArgs($group, $arguments);
                self::fail('Missing/null/scalar argument accepted.');
            } catch (ArgumentCountError | TypeError) {
                self::assertCount(0, $http->getRequests());
            }
        }
        self::assertNull($client->whm()->responseMeta());
        self::assertCount(0, $http->getRequests());
    }

    public function testNativeSignatureFailureDoesNotEnterMetadataLifecycle(): void
    {
        foreach (self::methods() as [$selector, $name, $operation, $body]) {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], self::success($name)));
            self::invoke($client, $selector, $name, $body);
            $snapshot = $client->whm()->responseMeta();
            $group = self::group($client, $selector);
            $method = new ReflectionMethod($group, $name);
            foreach ([[], [null], [false], [1], ['scalar']] as $arguments) {
                try {
                    $method->invokeArgs($group, $arguments);
                    self::fail('Native invalid argument accepted.');
                } catch (ArgumentCountError | TypeError) {
                    self::assertSame($snapshot, $client->whm()->responseMeta());
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, string, array<mixed>, string}> */
    public static function requests(): iterable
    {
        yield 'required present empty object' => ['webServer', 'phpFpmConfigGet', 'php_fpm_config_get', [], '{}'];
        yield 'explicit blank system selector' => [
            'webServer', 'phpFpmConfigGet', 'php_fpm_config_get', ['domain' => ''], '{"domain":""}',
        ];
        yield 'selector bytes' => [
            'webServer', 'phpFpmConfigGet', 'php_fpm_config_get', ['domain' => "dž+%/\"\\\n.test"],
            '{"domain":"d\u017e+%\/\"\\\\\n.test"}',
        ];
        yield 'empty config no defaults inserted' => [
            'webServer', 'phpFpmConfigSet', 'php_fpm_config_set', ['config' => []], '{"config":[]}',
        ];
        yield 'set preserves order duplicates zero and native booleans' => [
            'webServer', 'phpFpmConfigSet', 'php_fpm_config_set', [
                'validate_only' => false, 'domain' => '', 'config' => [
                    ['value' => '', 'trinary_admin_value' => 0, 'base_flag_name' => 'duplicate'],
                    ['base_flag_name' => 'duplicate', 'trinary_admin_value' => 1, 'value' => '0'],
                    ['base_flag_name' => 'future', 'trinary_admin_value' => 2, 'value' => 'E_ALL & ~E_NOTICE'],
                ],
            ],
            '{"validate_only":false,"domain":"","config":[{"value":"","trinary_admin_value":0,'
                . '"base_flag_name":"duplicate"},{"base_flag_name":"duplicate","trinary_admin_value":1,"value":"0"},'
                . '{"base_flag_name":"future","trinary_admin_value":2,"value":"E_ALL & ~E_NOTICE"}]}',
        ];
        yield 'validation true remains bool and enum stays open' => [
            'webServer', 'phpFpmConfigSet', 'php_fpm_config_set', [
                'config' => [['base_flag_name' => 'future', 'trinary_admin_value' => 8, 'value' => 'x']],
                'validate_only' => true,
            ], '{"config":[{"base_flag_name":"future","trinary_admin_value":8,"value":"x"}],"validate_only":true}',
        ];
        yield 'set selector bytes and validation false' => [
            'webServer', 'phpFpmConfigSet', 'php_fpm_config_set',
            ['domain' => "dž+%/\n.test", 'config' => [], 'validate_only' => false],
            '{"domain":"d\u017e+%\/\n.test","config":[],"validate_only":false}',
        ];
        yield 'SQL string bytes' => [
            'databases', 'updateSqlConfig', 'update_sql_config',
            ['data' => [['name' => 'api.version', 'value' => "ž+%/\n0"]]],
            '{"data":[{"name":"api.version","value":"\u017e+%\/\n0"}]}',
        ];
        yield 'SQL empty data' => ['databases', 'updateSqlConfig', 'update_sql_config', ['data' => []], '{"data":[]}'];
        yield 'SQL empty row object' => [
            'databases', 'updateSqlConfig', 'update_sql_config', ['data' => [[]]], '{"data":[{}]}',
        ];
        yield 'SQL optional fields and duplicate rows' => [
            'databases', 'updateSqlConfig', 'update_sql_config', ['data' => [
                ['value' => '0', 'remove' => false, 'section' => '', 'name' => 'x+%'],
                ['remove' => true], ['name' => 'same'], ['name' => 'same'],
            ]], '{"data":[{"value":"0","remove":false,"section":"","name":"x+%"},'
                . '{"remove":true},{"name":"same"},{"name":"same"}]}',
        ];
    }

    /** @param array<mixed> $configuration */
    #[DataProvider('requests')]
    public function testExactSingleJsonPost(
        string $selector,
        string $method,
        string $operation,
        array $configuration,
        string $body,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::success($method)));
        self::assertSame(
            $method === 'phpFpmConfigGet' ? [] : null,
            self::invoke($client, $selector, $method, $configuration),
        );
        self::assertCount(1, $http->getRequests());
        $request = $http->getRequests()[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2443/json-api/' . $operation . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('api.version=1', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame($body, (string) $request->getBody());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
    }

    /** @return iterable<string, array{string, string, array<mixed>}> */
    public static function invalidBodies(): iterable
    {
        foreach (self::methods() as [$selector, $method, $operation, $valid]) {
            foreach (
                ['api.', 'api.version', 'api.filter', 'api.sort', 'api.paginate', 'api.columns', 'api.future'] as $key
            ) {
                yield $method . ' reserved ' . $key => [$selector, $method, $valid + [$key => null]];
            }
            yield $method . ' private unknown root' => [$selector, $method, $valid + ['secret-key' => 'secret-value']];
            yield $method . ' numeric root' => [$selector, $method, $valid + [0 => 'secret-value']];
        }
        foreach ([null, 0, false, 1.0, [], new \stdClass(), "\xB1"] as $value) {
            foreach (['phpFpmConfigGet', 'phpFpmConfigSet'] as $method) {
                yield $method . ' invalid domain ' . get_debug_type($value) => [
                    'webServer', $method,
                    ['domain' => $value] + ($method === 'phpFpmConfigSet' ? ['config' => []] : []),
                ];
            }
        }
        foreach (['config', 'data'] as $collection) {
            $selector = $collection === 'config' ? 'webServer' : 'databases';
            $method = $collection === 'config' ? 'phpFpmConfigSet' : 'updateSqlConfig';
            yield $method . ' missing collection' => [$selector, $method, []];
            foreach ([null, 'bad', false, 0, new \stdClass(), [1 => []], ['bad' => []]] as $value) {
                yield $method . ' bad list ' . get_debug_type($value) . ':' . serialize($value) => [
                    $selector, $method, [$collection => $value],
                ];
            }
            $invalidRows = [
                null, false, 0, 'bad', new \stdClass(), ['secret-key' => 'secret-value'], [0 => 'secret-value'],
            ];
            foreach ($invalidRows as $row) {
                yield $method . ' invalid row ' . get_debug_type($row) . ':' . serialize($row) => [
                    $selector, $method, [$collection => [$row]],
                ];
            }
        }
        $row = ['base_flag_name' => 'secret-value', 'trinary_admin_value' => 0, 'value' => 'secret-value'];
        foreach (array_keys($row) as $key) {
            $missing = $row;
            unset($missing[$key]);
            yield 'set missing ' . $key => ['webServer', 'phpFpmConfigSet', ['config' => [$missing]]];
            $values = $key === 'trinary_admin_value' ? [null, '0', '1', false, 0.0, [], new \stdClass()] : [
                null, 0, false, 1.0, [], new \stdClass(), "\xB1",
            ];
            foreach ($values as $value) {
                yield 'set invalid ' . $key . ' ' . get_debug_type($value) . ':' . serialize($value) => [
                    'webServer', 'phpFpmConfigSet', ['config' => [array_replace($row, [$key => $value])]],
                ];
            }
        }
        foreach ([null, 0, 1, 'false', 0.0, [], new \stdClass()] as $value) {
            yield 'set invalid native bool ' . get_debug_type($value) . ':' . serialize($value) => [
                'webServer', 'phpFpmConfigSet', ['config' => [], 'validate_only' => $value],
            ];
        }
        yield 'set invalid last row' => ['webServer', 'phpFpmConfigSet', ['config' => [
            $row, $row + ['secret-key' => 'secret-value'],
        ]]];
        foreach (['name', 'remove', 'section', 'value'] as $key) {
            foreach ([null, 0, 1.5, [], new \stdClass(), $key === 'remove' ? 'false' : false] as $value) {
                yield 'SQL invalid ' . $key . ' ' . get_debug_type($value) => [
                    'databases', 'updateSqlConfig', ['data' => [[$key => $value]]],
                ];
            }
            if ($key !== 'remove') {
                yield 'SQL invalid UTF8 ' . $key => ['databases', 'updateSqlConfig', ['data' => [[$key => "\xB1"]]]];
            }
        }
        yield 'SQL invalid last row' => ['databases', 'updateSqlConfig', ['data' => [
            ['name' => 'secret-value'], ['secret-key' => 'secret-value'],
        ]]];
    }

    /** @param array<mixed> $configuration */
    #[DataProvider('invalidBodies')]
    public function testEnteredArrayErrorsClearMetadataWithoutIoOrPrivateTrace(
        string $selector,
        string $method,
        array $configuration,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::success('phpFpmConfigGet')));
        $client->whm()->webServer()->phpFpmConfigGet([]);
        $snapshot = $client->whm()->responseMeta();
        self::assertNotNull($snapshot);
        $prior = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($client, $selector, $method, $configuration);
            self::fail('Invalid configuration accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret-key', (string) $error);
            self::assertStringNotContainsString('secret-value', (string) $error);
            self::assertNull($error->getPrevious());
        } finally {
            if ($prior !== false) {
                ini_set('zend.exception_ignore_args', $prior);
            }
        }
        self::assertCount(1, $http->getRequests());
        self::assertNull($client->whm()->responseMeta());
        self::assertSame(1, $snapshot->whmResult());
    }

    public function testObjectsResourcesAndJsonSerializableNeverBecomeBodies(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $object = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('must never execute');
            }
        };
        try {
            foreach ([new \stdClass(), $resource, $object] as $value) {
                foreach (
                    [
                    ['webServer', 'phpFpmConfigGet', ['domain' => $value]],
                    ['webServer', 'phpFpmConfigSet', ['config' => [$value]]],
                    ['databases', 'updateSqlConfig', ['data' => [['name' => $value]]]],
                    ] as [$selector, $method, $body]
                ) {
                    [$client, $http] = self::client();
                    $http->addResponse(new Response(200, [], self::success('phpFpmConfigGet')));
                    $client->whm()->webServer()->phpFpmConfigGet([]);
                    try {
                        self::invoke($client, $selector, $method, $body);
                        self::fail('Unsupported PHP value accepted.');
                    } catch (InvalidArgumentException) {
                        self::assertCount(1, $http->getRequests());
                        self::assertNull($client->whm()->responseMeta());
                    }
                }
            }
        } finally {
            fclose($resource);
        }
    }

    /** @return iterable<string, array{string, array<mixed>|null, class-string<ApiException>|null}> */
    public static function readResponses(): iterable
    {
        yield 'object' => ['{}', [], null];
        yield 'empty array object compatibility' => ['[]', [], null];
        yield 'optional empty config' => ['{"config":[]}', ['config' => []], null];
        yield 'optional rows and strict leaves' => [
            '{"future":7,"config":[{}, {"base_flag_name":"","future":1},'
                . '{"trinary_admin_value":0},{"trinary_admin_value":1},{"trinary_admin_value":2},'
                . '{"value":"0"},{"value":""}]}',
            ['config' => [
                [], ['base_flag_name' => ''], ['trinary_admin_value' => 0], ['trinary_admin_value' => 1],
                ['trinary_admin_value' => 2], ['value' => '0'], ['value' => ''],
            ]], null,
        ];
        foreach (
            ['null', '7', 'false', '"bad"', '[{}]', '{"config":null}', '{"config":{}}', '{"config":[null]}'] as $json
        ) {
            yield 'invalid root or config ' . $json => [$json, null, PayloadMismatchException::class];
        }
        foreach (['base_flag_name', 'trinary_admin_value', 'value'] as $key) {
            foreach (['null', 'true', '0.0', '{}', '[]', $key === 'trinary_admin_value' ? '"1"' : '1'] as $json) {
                yield 'invalid leaf ' . $key . ' ' . $json => [
                    '{"config":[{"' . $key . '":' . $json . '}]}', null, PayloadMismatchException::class,
                ];
            }
        }
    }

    /**
     * @param array<mixed>|null $expected
     * @param class-string<ApiException>|null $errorClass
     */
    #[DataProvider('readResponses')]
    public function testReadResponsesPreserveOptionalFieldsAndStrictTypes(
        string $payload,
        ?array $expected,
        ?string $errorClass,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":' . $payload . '}'));
        try {
            self::assertSame($expected, $client->whm()->webServer()->phpFpmConfigGet([]));
            self::assertNull($errorClass);
        } catch (PayloadMismatchException $error) {
            self::assertSame($errorClass, $error::class);
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($error->metadata, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
    }

    public function testVoidMutationsRelyOnOutcomeAndIgnoreUnspecifiedData(): void
    {
        foreach (['', ',"data":null', ',"data":{"future":"opaque"}', ',"data":false', ',"data":[]'] as $suffix) {
            foreach (array_slice(self::methods(), 1) as [$selector, $method, $operation, $body]) {
                [$client, $http] = self::client();
                $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"OK","warning":"inspect"}'
                    . $suffix . '}'));
                self::assertNull(self::invoke($client, $selector, $method, $body));
                self::assertSame('inspect', $client->whm()->responseMeta()?->whm['warning'] ?? null);
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string, array{int, string, class-string<ApiException>, bool}> */
    public static function failures(): iterable
    {
        yield 'HTTP with usable metadata' => [500, '{"metadata":{"result":1}}', HttpException::class, true];
        yield 'bad JSON' => [200, 'broken', InvalidResponseException::class, false];
        yield 'invalid envelope' => [200, '{}', InvalidResponseException::class, false];
        yield 'API result zero beats invalid data' => [
            200, '{"metadata":{"result":0,"reason":"private upstream"},"data":false}', ApiErrorException::class, true,
        ];
    }

    /** @param class-string<ApiException> $errorClass */
    #[DataProvider('failures')]
    public function testFailuresAfterSuccessReplaceMetadataAndNeverRetry(
        int $status,
        string $json,
        string $errorClass,
        bool $hasMetadata,
    ): void {
        foreach (self::methods() as [$selector, $method, $operation, $body]) {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], self::success($method)));
            self::invoke($client, $selector, $method, $body);
            $snapshot = $client->whm()->responseMeta();
            $http->addResponse(new Response($status, [], $json));
            try {
                self::invoke($client, $selector, $method, $body);
                self::fail('Failure accepted.');
            } catch (ApiException $error) {
                self::assertSame($errorClass, $error::class);
                self::assertSame($error->metadata, $client->whm()->responseMeta());
                self::assertNotSame($snapshot, $client->whm()->responseMeta());
                self::assertSame($hasMetadata, $error->metadata !== null);
            }
            self::assertCount(2, $http->getRequests());
            self::assertSame(1, $snapshot?->whmResult());
        }
    }

    public function testMissingReadPayloadMismatchAfterSuccessRetainsConfirmedSnapshot(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::success('phpFpmConfigGet')));
        $client->whm()->webServer()->phpFpmConfigGet([]);
        $snapshot = $client->whm()->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"second"}}'));
        try {
            $client->whm()->webServer()->phpFpmConfigGet([]);
            self::fail('Missing read payload accepted.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($error->metadata, $client->whm()->responseMeta());
            self::assertNotSame($snapshot, $error->metadata);
            self::assertNull($error->payload);
        }
        self::assertCount(2, $http->getRequests());
        self::assertSame(1, $snapshot?->whmResult());
    }

    public function testTransportFailureAfterSuccessClearsMetadataWithoutRetry(): void
    {
        foreach (self::methods() as [$selector, $method, $operation, $body]) {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], self::success($method)));
            self::invoke($client, $selector, $method, $body);
            $snapshot = $client->whm()->responseMeta();
            $http->addException(new class extends RuntimeException implements NetworkExceptionInterface {
                public function getRequest(): RequestInterface
                {
                    throw new RuntimeException('no inspection needed');
                }
            });
            try {
                self::invoke($client, $selector, $method, $body);
                self::fail('Transport failure accepted.');
            } catch (TransportException $error) {
                self::assertNull($error->metadata);
                self::assertNull($error->getPrevious());
                self::assertNull($client->whm()->responseMeta());
            }
            self::assertCount(2, $http->getRequests());
            self::assertSame(1, $snapshot?->whmResult());
        }
    }

    /** @return list<array{string, string, string, array<mixed>}> */
    private static function methods(): array
    {
        return [
            ['webServer', 'phpFpmConfigGet', 'php_fpm_config_get', []],
            ['webServer', 'phpFpmConfigSet', 'php_fpm_config_set', ['config' => []]],
            ['databases', 'updateSqlConfig', 'update_sql_config', ['data' => []]],
        ];
    }

    private static function group(Client $client, string $selector): WebServer|Databases
    {
        return match ($selector) {
            'webServer' => $client->whm()->webServer(),
            'databases' => $client->whm()->databases(),
            default => throw new InvalidArgumentException('Unknown test selector.'),
        };
    }

    /** @param array<mixed> $configuration */
    private static function invoke(
        Client $client,
        string $selector,
        string $method,
        #[SensitiveParameter] array $configuration,
    ): mixed {
        $group = self::group($client, $selector);
        return (new ReflectionMethod($group, $method))->invoke($group, $configuration);
    }

    private static function success(string $method): string
    {
        return $method === 'phpFpmConfigGet' ? '{"metadata":{"result":1},"data":{}}' : '{"metadata":{"result":1}}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $http = new MockClient();
        $factory = new Psr17Factory();
        return [new Client([
            'host' => 'api.example.test', 'port' => 2443, 'authType' => 'whm',
            'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
