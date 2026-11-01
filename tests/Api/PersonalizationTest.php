<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use ArgumentCountError;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
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
use Throwable;
use TypeError;

final class PersonalizationTest extends TestCase
{
    public function testDirectGetterUsesOneLiteralJsonPost(): void
    {
        [$client, $http] = self::client();
        $group = $client->uapi()->personalization();
        self::assertCount(0, $http->getRequests());
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"personalization":{"theme":'
            . '{"success":1,"value":"dark"}}}}'));
        self::assertSame(
            ['personalization' => ['theme' => ['success' => 1, 'value' => 'dark']]],
            $group->get(['names' => ['theme', 'locale']]),
        );
        self::assertCount(1, $http->getRequests());
        $request = $http->getRequests()[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.test:2443/execute/Personalization/get', (string) $request->getUri());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('cpanel alpha:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('{"names":["theme","locale"]}', (string) $request->getBody());
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function queries(): iterable
    {
        yield 'explicit empty object' => [[], '{}'];
        yield 'empty list' => [['names' => []], '{"names":[]}'];
        yield 'singleton' => [['names' => ['theme']], '{"names":["theme"]}'];
        yield 'duplicates and empties' => [
            ['names' => ['', 'theme', 'theme', '']], '{"names":["","theme","theme",""]}',
        ];
        $escapedJson = <<<'JSON'
{"names":["\u96ea+%\"\\\n\u0000","https:\/\/example.test\/a?b=%2F&c=1","api.version","cpanel.user"]}
JSON;
        yield 'bytes and URL-like values' => [
            ['names' => ["雪+%\"\\\n\u{0000}", 'https://example.test/a?b=%2F&c=1', 'api.version', 'cpanel.user']],
            $escapedJson,
        ];
        yield 'no undocumented byte limit' => [
            ['names' => [str_repeat('x', 129)]], '{"names":["' . str_repeat('x', 129) . '"]}',
        ];
    }

    /** @param array<mixed> $query */
    #[DataProvider('queries')]
    public function testExactBodyDistinctionsAndBytes(array $query, string $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"status":1,"data":{}}'));
        $method = new ReflectionMethod($client->uapi()->personalization(), 'get');
        self::assertSame([], $method->invoke($client->uapi()->personalization(), $query));
        self::assertCount(1, $http->getRequests());
        self::assertSame($expected, (string) $http->getRequests()[0]->getBody());
        self::assertSame('', $http->getRequests()[0]->getUri()->getQuery());
    }

    public function testRequiredNativeArgumentAndSensitivityRetainPreEntryMetadata(): void
    {
        [$client, $http] = self::client();
        $group = $client->uapi()->personalization();
        $method = new ReflectionMethod($group, 'get');
        $parameter = $method->getParameters()[0];
        self::assertSame('query', $parameter->getName());
        self::assertSame('array', (string) $parameter->getType());
        self::assertFalse($parameter->isOptional());
        self::assertFalse($parameter->allowsNull());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        $http->addResponse(new Response(200, [], '{"status":1,"data":{}}'));
        $group->get([]);
        $metadata = $client->uapi()->responseMeta();
        self::assertNotNull($metadata);
        $caught = null;
        try {
            $method->invoke($group);
        } catch (ArgumentCountError $error) {
            $caught = $error;
        }
        self::assertInstanceOf(ArgumentCountError::class, $caught);
        self::assertSame($metadata, $client->uapi()->responseMeta());
        foreach ([null, 'private-query', 1, false, new \stdClass()] as $invalid) {
            $caught = null;
            try {
                $method->invoke($group, $invalid);
            } catch (TypeError $error) {
                $caught = $error;
            }
            self::assertInstanceOf(TypeError::class, $caught);
            self::assertSame($metadata, $client->uapi()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield 'unknown root' => [['private-key' => 'private-value']];
        yield 'integer root' => [[0 => 'private-value']];
        yield 'integer root beside names' => [['names' => [], 1 => 'private-value']];
        yield 'null names' => [['names' => null]];
        yield 'scalar names' => [['names' => 'private-value']];
        yield 'object names' => [['names' => new \stdClass()]];
        yield 'associative names' => [['names' => ['x' => 'private-value']]];
        yield 'sparse names' => [['names' => [1 => 'private-value']]];
        yield 'unordered list keys' => [['names' => [1 => 'private-value', 0 => 'private-value']]];
        yield 'negative names index' => [['names' => [-1 => 'private-value']]];
        foreach ([null, false, 1, 1.0, [], ['nested'], new \stdClass()] as $i => $value) {
            yield 'bad late item ' . $i => [['names' => ['private-value', $value]]];
        }
        yield 'invalid UTF-8 item' => [['names' => ['private-value', "\xC3\x28"]]];
        yield 'invalid UTF-8 member' => [["\xC3\x28" => 'private-value']];
        foreach (['api.version', 'api.future', 'cpanel.user', 'cpanel.module', 'cpanel.function'] as $name) {
            yield 'reserved ' . $name => [['names' => ['private-value'], $name => 'private-value']];
        }
    }

    /** @param array<mixed> $query */
    #[DataProvider('invalidQueries')]
    public function testInvalidStructureClearsMetadataBeforeIoAndHidesInput(array $query): void
    {
        [$client, $http] = self::client();
        $group = $client->uapi()->personalization();
        $http->addResponse(new Response(200, [], '{"status":1,"data":{}}'));
        $group->get([]);
        self::assertNotNull($client->uapi()->responseMeta());
        $prior = ini_set('zend.exception_ignore_args', '0');
        $caught = null;
        try {
            (new ReflectionMethod($group, 'get'))->invoke($group, $query);
        } catch (InvalidArgumentException $error) {
            $caught = $error;
        } finally {
            if ($prior !== false) {
                ini_set('zend.exception_ignore_args', $prior);
            }
        }
        self::assertInstanceOf(InvalidArgumentException::class, $caught);
        self::assertStringNotContainsString('private-value', (string) $caught);
        self::assertStringNotContainsString('private-key', (string) $caught);
        self::assertNull($caught->getPrevious());
        self::assertNull($client->uapi()->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    public function testBridgeAccessorHasNoIoAndGetterRefusesAfterMetadataReset(): void
    {
        [$client, $http] = self::client(false);
        $handle = $client->uapi('alpha');
        $group = $handle->personalization();
        self::assertCount(0, $http->getRequests());
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":[]}}}'));
        $handle->statsManager()->getConfiguration();
        self::assertNotNull($handle->responseMeta());
        $caught = null;
        try {
            $group->get(['names' => ['private-value']]);
        } catch (InvalidArgumentException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(InvalidArgumentException::class, $caught);
        self::assertNull($handle->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function successfulPayloads(): iterable
    {
        yield 'empty formal object' => ['{}', []];
        yield 'inherited empty array object compatibility' => ['[]', []];
        yield 'empty map object' => ['{"personalization":{}}', ['personalization' => []]];
        yield 'inherited empty array map compatibility' => ['{"personalization":[]}', ['personalization' => []]];
        yield 'optional rows and native flags' => [
            '{"personalization":{"missing":{"reason":"private-reason","success":0,"value":null},'
                . '"present":{"value":"private-value","success":1},"omitted":{},"empty":[]}}',
            ['personalization' => [
                'missing' => ['reason' => 'private-reason', 'success' => 0, 'value' => null],
                'present' => ['success' => 1, 'value' => 'private-value'], 'omitted' => [], 'empty' => [],
            ]],
        ];
        yield 'map keys preserve normal PHP behavior' => [
            '{"personalization":{"0":{"value":"zero"},"01":{"value":"leading"},"-2":{},"雪":{}}}',
            ['personalization' => [0 => ['value' => 'zero'], '01' => ['value' => 'leading'], -2 => [], '雪' => []]],
        ];
        yield 'unknown fixed fields project out' => [
            '{"unknown":"private-value","personalization":{"theme":{"extra":"private-value","value":"dark"}}}',
            ['personalization' => ['theme' => ['value' => 'dark']]],
        ];
        yield 'empty strings' => [
            '{"personalization":{"":{"reason":"","value":""}}}',
            ['personalization' => ['' => ['reason' => '', 'value' => '']]],
        ];
        yield 'direct-key example is not an inferred union' => ['{"theme":{"value":"dark"}}', []];
    }

    /** @param array<mixed> $expected */
    #[DataProvider('successfulPayloads')]
    public function testFormalResponseOnBareAndResultWrappedDirectEnvelopes(string $data, array $expected): void
    {
        foreach ([false, true] as $wrapped) {
            [$client, $http] = self::client();
            $json = '{"status":1,"warnings":["fixture-warning"],"data":' . $data . '}';
            $http->addResponse(new Response(200, [], $wrapped ? '{"result":' . $json . '}' : $json));
            self::assertSame($expected, $client->uapi()->personalization()->get([]));
            self::assertSame(1, $client->uapi()->responseMeta()?->uapiStatus());
            self::assertSame(['fixture-warning'], $client->uapi()->responseMeta()->uapiWarnings());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, class-string<Throwable>, bool}> */
    public static function failures(): iterable
    {
        yield 'API failure wins over rows' => [
            '{"status":0,"errors":["denied"],"data":{"personalization":{"x":{"success":1}}}}',
            ApiErrorException::class, false,
        ];
        foreach (['"1"', 'true', 'null', '2', '1.0'] as $status) {
            yield 'malformed status ' . $status => [
                '{"status":' . $status . ',"data":{}}', InvalidResponseException::class, false,
            ];
        }
        yield 'missing status' => ['{"result":{"data":{}}}', InvalidResponseException::class, false];
        yield 'missing data' => ['{"status":1}', PayloadMismatchException::class, true];
        foreach (['null', 'false', '1', '"bad"', '[{}]'] as $data) {
            yield 'invalid root ' . $data => [
                '{"status":1,"data":' . $data . '}', PayloadMismatchException::class, true,
            ];
        }
        foreach (['null', 'false', '1', '"bad"', '[{}]'] as $map) {
            yield 'invalid map ' . $map => [
                '{"status":1,"data":{"personalization":' . $map . '}}', PayloadMismatchException::class, true,
            ];
        }
        foreach (['null', 'false', '1', '"bad"', '[{}]'] as $row) {
            yield 'invalid row ' . $row => [
                '{"status":1,"data":{"personalization":{"x":' . $row . '}}}', PayloadMismatchException::class, true,
            ];
        }
        foreach (['"0"', '"1"', 'true', 'null', '2', '1.0'] as $flag) {
            yield 'invalid native flag ' . $flag => [
                '{"status":1,"data":{"personalization":{"x":{"success":' . $flag . '}}}}',
                PayloadMismatchException::class, true,
            ];
        }
        $invalidFields = ['reason' => ['null', '1', 'false', '{}', '[]'], 'value' => ['1', 'false', '{}', '[]']];
        foreach ($invalidFields as $field => $values) {
            foreach ($values as $value) {
                yield 'invalid ' . $field . ' ' . $value => [
                    '{"status":1,"data":{"personalization":{"x":{"' . $field . '":' . $value . '}}}}',
                    PayloadMismatchException::class, true,
                ];
            }
        }
    }

    /** @param class-string<Throwable> $errorClass */
    #[DataProvider('failures')]
    public function testFailuresKeepMetadataAndNeverRetry(string $json, string $errorClass, bool $confirmed): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], $json));
        $caught = null;
        try {
            $client->uapi()->personalization()->get([]);
        } catch (Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught);
        self::assertSame($errorClass, $caught::class);
        self::assertInstanceOf(ApiException::class, $caught);
        self::assertSame($client->uapi()->responseMeta(), $caught->metadata);
        self::assertNotNull($caught->metadata);
        if ($caught instanceof PayloadMismatchException) {
            self::assertSame($confirmed, $caught->outcomeConfirmed);
            self::assertSame(1, $caught->metadata->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testMismatchTraceConcealsQueryAndResponseWithExceptionArgsEnabled(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"personalization":{"private-key":'
            . '{"reason":"private-reason","value":"private-value","success":"1"}}}}'));
        $prior = ini_set('zend.exception_ignore_args', '0');
        $caught = null;
        try {
            $client->uapi()->personalization()->get(['names' => ['private-name']]);
        } catch (PayloadMismatchException $error) {
            $caught = $error;
        } finally {
            if ($prior !== false) {
                ini_set('zend.exception_ignore_args', $prior);
            }
        }
        self::assertInstanceOf(PayloadMismatchException::class, $caught);
        foreach (['private-key', 'private-reason', 'private-value', 'private-name'] as $private) {
            self::assertStringNotContainsString($private, (string) $caught);
        }
        self::assertTrue($caught->outcomeConfirmed);
        self::assertCount(1, $http->getRequests());
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
