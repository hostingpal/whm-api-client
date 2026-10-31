<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\UserData;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/**
 * Literal contracts independently derived from the pinned WHM source, not generated specs.
 *
 * @phpstan-type Call Closure(WhmClient): mixed
 * @phpstan-type RequestCase array{string, string, Call, string, ?string}
 */
final class WhmInterfaceDataTest extends TestCase
{
    /** @param Call $call */
    #[DataProvider('requests')]
    public function testExactWireContracts(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        $expected = $operation === 'get_in_product_survey_url'
            ? ['display' => 1, 'link' => 'https://example.test/survey?token=private', 'max_dismiss' => '3',
                'new_user' => 0, 'server_type' => 'whm', 'submit_event' => 'survey_submitted', 'user_type' => 'whm']
            : ($payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($expected, $call($client->whm()));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
                . ($method === 'GET' && $parameters !== '' ? '&' . $parameters : ''),
            (string) $request->getUri(),
        );
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, RequestCase> */
    public static function requests(): iterable
    {
        yield 'retrieve metadata-only source' => ['retrieve_customizations', 'GET',
            static function (WhmClient $w): void {
                $w->customizations()->retrieveCustomizations('cpanel', 'jupiter');
            }, 'application=cpanel&theme=jupiter', null];
        yield 'delete optional path omitted' => ['delete_customizations', 'POST',
            static function (WhmClient $w): void {
                $w->customizations()->deleteCustomizations('webmail', 'jupiter');
            }, 'application=webmail&theme=jupiter', null];
        yield 'delete exact dot path preserved' => ['delete_customizations', 'POST',
            static function (WhmClient $w): void {
                $w->customizations()->deleteCustomizations('cpanel', 'jupiter', 'brand.logo.description');
            }, 'application=cpanel&theme=jupiter&path=brand.logo.description', null];
        yield 'delete empty path explicit' => ['delete_customizations', 'POST',
            static function (WhmClient $w): void {
                $w->customizations()->deleteCustomizations('cpanel', 'jupiter', '');
            }, 'application=cpanel&theme=jupiter&path=', null];
        yield 'scoped read source explicitly non-readonly' => ['get_scoped_userdata', 'POST',
            static fn (WhmClient $w): array => $w->userData()->getScopedUserdata('scope & private'),
            'scope=scope%20%26%20private', '{"theme":"dark","items_per_page":"50","empty":""}'];
        yield 'scoped set serialized JSON once' => ['set_scoped_userdata', 'POST',
            static fn (WhmClient $w): array => $w->userData()->setScopedUserdata('scope', json: '{ "key": "a&b=1+%" }'),
            'scope=scope&json=%7B%20%22key%22%3A%20%22a%26b%3D1%2B%25%22%20%7D', '{"key":"a&b=1+%"}'];
        yield 'scoped set key value' => ['set_scoped_userdata', 'POST',
            static fn (WhmClient $w): array => $w->userData()->setScopedUserdata('scope', key: 'theme', value: 'dark'),
            'scope=scope&key=theme&value=dark', '{"theme":"dark","sibling":"kept"}'];
        yield 'optional scoped arguments omitted' => ['set_scoped_userdata', 'POST',
            static fn (WhmClient $w): array => $w->userData()->setScopedUserdata('scope'), 'scope=scope', '{}'];
        yield 'all arguments are sent without invented exclusivity check' => ['set_scoped_userdata', 'POST',
            static fn (WhmClient $w): array => $w->userData()->setScopedUserdata('scope', '{}', '', '0'),
            'scope=scope&json=%7B%7D&key=&value=0', '{"zero":"0"}'];
        yield 'quoted zero stays supplied text' => ['get_scoped_userdata', 'POST',
            static fn (WhmClient $w): array => $w->userData()->getScopedUserdata('0'), 'scope=0', '{}'];
        yield 'survey enum flags normalize and other strings stay strings' => ['get_in_product_survey_url', 'POST',
            static fn (WhmClient $w): array => $w->inProductSurvey()->getInProductSurveyUrl(), '',
            '{"display":"1","link":"https://example.test/survey?token=private",'
                . '"max_dismiss":"3","new_user":"0","submit_event":"survey_submitted",'
                . '"server_type":"whm","user_type":"whm"}'];
    }

    /** @return iterable<string, array{Call, string}> */
    public static function payloadCalls(): iterable
    {
        yield 'get scoped map' => [
            static fn (WhmClient $w): array => $w->userData()->getScopedUserdata('scope'), 'map',
        ];
        yield 'set scoped map' => [
            static fn (WhmClient $w): array => $w->userData()->setScopedUserdata('scope'), 'map',
        ];
        yield 'survey object' => [
            static fn (WhmClient $w): array => $w->inProductSurvey()->getInProductSurveyUrl(), 'survey',
        ];
    }

    /** @param Call $call */
    #[DataProvider('payloadCalls')]
    public function testOptionalObjectFieldsAndExistingEmptyArrayCompatibility(Closure $call, string $kind): void
    {
        foreach (['{}', '[]'] as $payload) {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], self::envelope($payload)));
            self::assertSame([], $call($client->whm()), $kind);
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{Call, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach (self::payloadCalls() as $name => [$call, $kind]) {
            foreach ([null, 'null', 'false', '0', '"text"', '[{}]'] as $index => $payload) {
                yield $name . ' root ' . $index => [$call, $payload];
            }
            if ($kind === 'map') {
                foreach (['0', 'false', 'null', '[]', '{}', '1.5'] as $index => $value) {
                    yield $name . ' leaf ' . $index => [$call, '{"good":"yes","bad":' . $value . '}'];
                }
            } else {
                $fields = ['display', 'link', 'max_dismiss', 'new_user', 'submit_event', 'server_type', 'user_type'];
                foreach ($fields as $field) {
                    $invalid = in_array($field, ['display', 'new_user'], true)
                        ? ['2', '-1', '"2"', 'true', 'false', 'null', '[]', '{}', '1.5']
                        : ['0', 'false', 'null', '[]', '{}'];
                    foreach ($invalid as $index => $value) {
                        yield $name . ' ' . $field . ' ' . $index => [
                            $call, '{"link":"valid","' . $field . '":' . $value . '}',
                        ];
                    }
                }
            }
        }
    }

    /** @param Call $call */
    #[DataProvider('malformedPayloads')]
    public function testMalformedRootsLeavesAndStrictSiblingsFailAfterApiSuccess(Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $call($client->whm());
            self::fail('Malformed or absent documented object payload must fail.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @param Call $call */
    #[DataProvider('requests')]
    public function testApiFailureRetainsMetadataWithoutRetry(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Fixture failure","version":1}}'));
        try {
            $call($client->whm());
            self::fail('WHM API failure must throw.');
        } catch (ApiErrorException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
            self::assertSame('Fixture failure', $exception->metadata->whm['reason'] ?? null);
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function flags(): iterable
    {
        yield 'quoted zero' => ['"0"', 0];
        yield 'quoted one' => ['"1"', 1];
        yield 'native zero' => ['0', 0];
        yield 'native one' => ['1', 1];
    }

    #[DataProvider('flags')]
    public function testSurveyUsesExistingExactStringFlagNormalization(string $wire, int $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            '{"display":' . $wire . ',"max_dismiss":"0","new_user":' . $wire . '}',
        )));
        self::assertSame(
            ['display' => $expected, 'max_dismiss' => '0', 'new_user' => $expected],
            $client->whm()->inProductSurvey()->getInProductSurveyUrl(),
        );
        self::assertCount(1, $http->getRequests());
    }

    public function testScopedParametersAreSensitiveInGeneratedFacade(): void
    {
        foreach (['getScopedUserdata', 'setScopedUserdata'] as $method) {
            foreach ((new ReflectionMethod(UserData::class, $method))->getParameters() as $parameter) {
                self::assertNotEmpty($parameter->getAttributes(SensitiveParameter::class), $parameter->getName());
            }
        }
    }

    public function testBooleanValueIsRejectedBeforeDispatch(): void
    {
        [$client, $http] = self::client();
        try {
            // @phpstan-ignore argument.type (Deliberately invalid scalar boundary.)
            $client->whm()->userData()->setScopedUserdata('scope', value: false);
            self::fail('Boolean must not become a string or integer value.');
        } catch (TypeError) {
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testPrivateConfigurationInputsAndReturnedDataStayOutOfTraceArguments(): void
    {
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ([false, true] as $payloadFailure) {
                [$client, $http] = self::client();
                $response = $payloadFailure
                    ? self::envelope('{"private":"trace-fixture-secret","bad":false}')
                    : '{"metadata":{"result":0,"reason":"Fixture failure"}}';
                $http->addResponse(new Response(200, [], $response));
                try {
                    $client->whm()->userData()->setScopedUserdata(
                        'trace-fixture-secret',
                        'trace-fixture-secret',
                        'trace-fixture-secret',
                        'trace-fixture-secret',
                    );
                    self::fail('Fixture must fail.');
                } catch (ApiErrorException | PayloadMismatchException $exception) {
                    $seen = [];
                    self::assertFalse(self::hasSecret($exception->getTrace(), $seen));
                    self::assertStringNotContainsString('trace-fixture-secret', $exception->getMessage());
                    self::assertStringNotContainsString('trace-fixture-secret', $exception->getTraceAsString());
                    if ($exception instanceof PayloadMismatchException) {
                        self::assertStringNotContainsString(
                            'trace-fixture-secret',
                            print_r($exception->__debugInfo(), true),
                        );
                    }
                    self::assertCount(1, $http->getRequests());
                }
            }
        } finally {
            if ($original !== false) {
                ini_set('zend.exception_ignore_args', $original);
            }
        }
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'trace-fixture-secret');
        }
        if ($value instanceof MessageInterface && str_contains((string) $value->getBody(), 'trace-fixture-secret')) {
            return true;
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (self::hasSecret((string) $key, $seen) || self::hasSecret($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK","version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
