<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Email;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Tests\Fixtures\EmailSectionCases;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UapiEmailSectionTest extends TestCase
{
    /** @return iterable<string, array{string, string, Closure(Email): mixed, string, string, ?string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (EmailSectionCases::operations() as $name => $case) {
                yield $route . ' ' . $name => [$route, ...$case];
            }
        }
    }

    #[DataProvider('operations')]
    public function testLiteralOperationRequestAndDocumentedPayload(
        string $route,
        string $function,
        Closure $call,
        string $method,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $email = $account->email();
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        $expected = $payload === null ? null : json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $call($email));

        $base = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Email&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Email/' . $function;
        $uri = $base . ($method === 'GET' && $parameters !== '' ? ($route === 'bridge' ? '&' : '?') . $parameters : '');
        MysqlTestSupport::assertRequest(
            $http,
            $method,
            $uri,
            $method === 'POST' ? $parameters : '',
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $method === 'POST' ? 'application/x-www-form-urlencoded' : null,
        );
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertSame($email, $account->email());
    }

    /** @return iterable<string, array{string, Closure(Email): mixed}> */
    public static function payloadOperations(): iterable
    {
        foreach (EmailSectionCases::operations() as $name => [, $call, , , $payload]) {
            if ($payload !== null) {
                yield $name => [$name, $call];
            }
        }
    }

    #[DataProvider('payloadOperations')]
    /** @param Closure(Email): mixed $call */
    public function testAbsentPayloadIsNotTreatedAsExplicitNull(string $function, Closure $call): void
    {
        [$client, $http] = MysqlTestSupport::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', null)));
        try {
            $call($client->uapi()->email());
            self::fail($function . ' accepted missing data');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($client->uapi()->responseMeta(), $error->metadata);
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, Closure(Email): mixed, string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (EmailSectionCases::operations() as $name => [, $call, , , $payload]) {
            if ($payload !== null) {
                yield $name => [$name, $call, $payload === 'null' ? '[1]' : 'false'];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    /** @param Closure(Email): mixed $call */
    public function testInvalidPayloadFailsAfterTheConfirmedOutcome(
        string $function,
        Closure $call,
        string $invalid,
    ): void {
        [$client, $http] = MysqlTestSupport::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', $invalid)));
        try {
            $call($client->uapi()->email());
            self::fail($function . ' accepted invalid payload');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($client->uapi()->responseMeta(), $error->metadata);
            self::assertCount(1, $http->getRequests());
        }
    }

    private static function envelope(string $route, ?string $payload): string
    {
        $result = '{"status":1,"errors":null,"messages":null,"warnings":null,"metadata":{}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Email","result":' . $result . '}',
        };
    }
}
