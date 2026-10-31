<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Commerce;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;
use TypeError;

/** Independent pinned-route and reviewer-observed response regressions. */
final class WhmCommerceTest extends TestCase
{
    protected function setUp(): void
    {
        [$client] = self::client();
        self::assertContains('commerce', get_class_methods($client->whm()));
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testExactRoutesMethodsAndFormFields(
        string $function,
        string $method,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        self::assertSame(
            $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->commerce()->{$method}(...$arguments),
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method === 'getCommerce' ? 'GET' : 'POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame($wire, (string) $request->getBody());
        self::assertSame(
            $method === 'getCommerce' ? '' : 'application/x-www-form-urlencoded',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame($function, $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, ?string}> */
    public static function operations(): iterable
    {
        yield 'state populated and zero flag' => ['Sitejet/get_commerce', 'getCommerce', [], '',
            '{"is_enabled":0,"storeurl":"https://shop.test/path?token=synthetic&state=a+b"}'];
        yield 'reviewer string and null URL' => ['Sitejet/get_commerce', 'getCommerce', [], '',
            '{"is_enabled":"","storeurl":null}'];
        yield 'unrestricted string preserved' => ['Sitejet/get_commerce', 'getCommerce', [], '',
            '{"is_enabled":"future setting"}'];
        yield 'state optional fields' => ['Sitejet/get_commerce', 'getCommerce', [], '', '{}'];
        yield 'disable preserves omitted URL default' => ['Sitejet/set_commerce', 'setCommerce', [false],
            'allowCommerce=0', null];
        yield 'enable explicit URL' => ['Sitejet/set_commerce', 'setCommerce', [true, 'https://shop.test/a?x=+&y=雪'],
            'allowCommerce=1&storeurl=https%3A%2F%2Fshop.test%2Fa%3Fx%3D%2B%26y%3D%E9%9B%AA', null];
        yield 'empty URL distinct from omitted' => ['Sitejet/set_commerce', 'setCommerce', [false, ''],
            'allowCommerce=0&storeurl=', null];
        yield 'explicit null optional URL omitted' => ['Sitejet/set_commerce', 'setCommerce', [true, null],
            'allowCommerce=1', null];
        yield 'token returns both optional tokens' => ['validate_login_token', 'validateLoginToken',
            ['synthetic +&=%/雪', 'provider', 'https://return.test/path?token=synthetic&a=1'],
            'login_token=synthetic%20%2B%26%3D%25%2F%E9%9B%AA&provider=provider'
                . '&url_after_login=https%3A%2F%2Freturn.test%2Fpath%3Ftoken%3Dsynthetic%26a%3D1',
            '{"payload":[{"access_token":"synthetic-access","refresh_token":"synthetic-refresh"},{}]}'];
        yield 'empty token list' => ['validate_login_token', 'validateLoginToken', ['', '', ''],
            'login_token=&provider=&url_after_login=', '{"payload":[]}'];
        yield 'omitted token list' => ['validate_login_token', 'validateLoginToken', ['login', 'provider', 'url'],
            'login_token=login&provider=provider&url_after_login=url', '{}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiFailureDoesNotValidatePayloadOrRetry(
        string $function,
        string $method,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, 'false', 0)));
        try {
            $client->whm()->commerce()->{$method}(...$arguments);
            self::fail('Failed envelope must throw before payload handling.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach ([null, 'null', 'false', '1', '1.5', '"state"', '[1]'] as $i => $bad) {
            yield "state root $i" => ['Sitejet/get_commerce', 'getCommerce', [], $bad];
            yield "token root $i" => ['validate_login_token', 'validateLoginToken', ['login', 'provider', 'url'], $bad];
        }
        foreach (['false', 'true', '1.0', '2', '-1', 'null', '[]', '{}'] as $bad) {
            yield "state flag $bad" => ['Sitejet/get_commerce', 'getCommerce', [], '{"is_enabled":' . $bad . '}'];
        }
        foreach (['false', '1', '[]', '{}'] as $bad) {
            yield "URL $bad" => ['Sitejet/get_commerce', 'getCommerce', [], '{"storeurl":' . $bad . '}'];
            foreach (['access_token', 'refresh_token'] as $field) {
                yield "$field $bad" => ['validate_login_token', 'validateLoginToken', ['login', 'provider', 'url'],
                    '{"payload":[{"' . $field . '":' . $bad . '}]}'];
            }
        }
        foreach (['access_token', 'refresh_token'] as $field) {
            yield "$field explicit null" => ['validate_login_token', 'validateLoginToken', ['login', 'provider', 'url'],
                '{"payload":[{"' . $field . '":null}]}'];
        }
        foreach (['null', 'false', '{}', '"list"', '[false]', '[null]', '[[1]]'] as $bad) {
            yield "token list $bad" => ['validate_login_token', 'validateLoginToken', ['login', 'provider', 'url'],
                '{"payload":' . $bad . '}'];
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadRetainsConfirmedOutcomeWithoutRetry(
        string $function,
        string $method,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        try {
            $client->whm()->commerce()->{$method}(...$arguments);
            self::fail('Malformed present or absent typed payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($error->metadata, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testCachedSelectorAndSensitiveParameters(): void
    {
        [$client, $http] = self::client();
        $group = $client->whm()->commerce();
        self::assertInstanceOf(Commerce::class, $group);
        self::assertSame($group, $client->whm()->commerce());
        self::assertNull($client->whm()->responseMeta());
        self::assertSame([], $http->getRequests());
        foreach (
            ['setCommerce' => ['storeUrl'],
            'validateLoginToken' => ['loginToken', 'urlAfterLogin']] as $method => $parameters
        ) {
            foreach ($parameters as $parameter) {
                self::assertCount(1, (new ReflectionParameter([$group, $method], $parameter))
                    ->getAttributes(SensitiveParameter::class));
            }
        }
        foreach (['getCommerce' => 0, 'setCommerce' => 1, 'validateLoginToken' => 3] as $method => $count) {
            self::assertSame($count, (new ReflectionMethod($group, $method))->getNumberOfRequiredParameters());
        }
        self::assertNotContains('getEcommerce', get_class_methods($group));
        self::assertNotContains('setEcommerce', get_class_methods($group));
    }

    #[DataProvider('invalidFlagArguments')]
    public function testFlagParameterRejectsIntegerRatherThanCoercingIt(string $method, mixed $value): void
    {
        [$client, $http] = self::client();
        $this->expectException(TypeError::class);
        try {
            $client->whm()->commerce()->{$method}($value);
        } finally {
            self::assertSame([], $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFlagArguments(): iterable
    {
        foreach ([0, 1, '0', '1', 0.0, null, []] as $index => $value) {
            yield (string) $index => ['setCommerce', $value];
        }
    }

    public function testNextAttemptClearsMetadataBeforeTransportFailure(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('get_commerce', '{}')));
        $client->whm()->commerce()->getCommerce();
        $snapshot = $client->whm()->responseMeta();
        self::assertSame(1, $snapshot?->whmResult());
        $http->addResponse(new Response(503, [], 'unavailable'));
        try {
            $client->whm()->commerce()->setCommerce(false);
            self::fail('HTTP failure expected.');
        } catch (HttpException) {
            self::assertNull($client->whm()->responseMeta());
            self::assertSame(1, $snapshot->whmResult());
        }
        self::assertCount(2, $http->getRequests());
    }

    private static function envelope(string $function, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $function . '","result":' . $result . ',"reason":"Synthetic"}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory)), $http];
    }
}
