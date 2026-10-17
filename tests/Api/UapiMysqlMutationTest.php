<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Mysql;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-type Mutation Closure(Mysql): (array<array-key, mixed>|null) */
final class UapiMysqlMutationTest extends TestCase
{
    /**
     * @param Mutation $call
     * @param array<array-key, mixed>|null $expected
     */
    #[DataProvider('successfulMutations')]
    public function testMysqlWritesUseExactAccountBoundFormPostsAndPreserveNullableOpaqueObjects(
        string $route,
        string $function,
        Closure $call,
        string $body,
        string $payload,
        ?array $expected,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, $payload)));

        self::assertSame($expected, $call($account->mysql()));

        self::assertRequest($http, $route, $function, $body);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic MySQL notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, Mutation, string, string, array<array-key, mixed>|null}> */
    public static function successfulMutations(): iterable
    {
        // The pinned data object has no declared properties or additionalProperties restriction.
        $payloads = [
            ['null', null],
            ['{}', []],
            ['[]', []],
            ['{"opaque":{"numbers":[0,1],"none":null},"0":"literal"}',
                ['opaque' => ['numbers' => [0, 1], 'none' => null], 0 => 'literal']],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::mutations() as $name => [$function, $call, $body]) {
                foreach ($payloads as [$payload, $expected]) {
                    yield $route . ' ' . $name . ' ' . $payload => [
                        $route, $function, $call, $body, $payload, $expected,
                    ];
                }
            }
        }
    }

    /**
     * Literal input/output cases are independently authored from the pinned operation contracts.
     * They catch wrong wire names, form encoding, lost omission and accidentally query-encoded secrets.
     *
     * @return iterable<string, array{string, Mutation, string}>
     */
    public static function mutations(): iterable
    {
        yield 'create database with omitted prefix' => [
            'create_database', static fn (Mysql $mysql): ?array => $mysql->createDatabase(name: 'exampleuser_db'),
            'name=exampleuser_db',
        ];
        yield 'create database with explicit prefix' => [
            'create_database', static fn (Mysql $mysql): ?array =>
                $mysql->createDatabase(name: 'exampleuser_db', prefixSize: 8),
            'name=exampleuser_db&prefix-size=8',
        ];
        yield 'create user with omitted prefix and encoded password' => [
            'create_user', static fn (Mysql $mysql): ?array =>
                $mysql->createUser(name: 'exampleuser_writer', password: 'p a+&=%/雪'),
            'name=exampleuser_writer&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
        ];
        yield 'create user with explicit prefix' => [
            'create_user', static fn (Mysql $mysql): ?array =>
                $mysql->createUser(name: 'exampleuser_writer', password: 'fixture-password', prefixSize: 16),
            'name=exampleuser_writer&password=fixture-password&prefix-size=16',
        ];
        yield 'replace privileges with comma-separated values' => [
            'set_privileges_on_database', static fn (Mysql $mysql): ?array => $mysql->setPrivilegesOnDatabase(
                database: 'exampleuser_db',
                user: 'exampleuser_writer',
                privileges: 'SELECT,CREATE VIEW',
            ),
            'database=exampleuser_db&user=exampleuser_writer&privileges=SELECT%2CCREATE%20VIEW',
        ];
        yield 'omit privileges' => [
            'set_privileges_on_database', static fn (Mysql $mysql): ?array =>
                $mysql->setPrivilegesOnDatabase(database: 'exampleuser_db', user: 'exampleuser_writer'),
            'database=exampleuser_db&user=exampleuser_writer',
        ];
        yield 'explicit empty privileges are not omitted' => [
            'set_privileges_on_database', static fn (Mysql $mysql): ?array =>
                $mysql->setPrivilegesOnDatabase(database: 'exampleuser_db', user: 'exampleuser_writer', privileges: ''),
            'database=exampleuser_db&user=exampleuser_writer&privileges=',
        ];
        yield 'delete user' => [
            'delete_user', static fn (Mysql $mysql): ?array => $mysql->deleteUser(name: 'exampleuser_writer'),
            'name=exampleuser_writer',
        ];
        yield 'delete database' => [
            'delete_database', static fn (Mysql $mysql): ?array => $mysql->deleteDatabase(name: 'exampleuser_db'),
            'name=exampleuser_db',
        ];
    }

    /** @param Mutation $call */
    #[DataProvider('invalidPayloads')]
    public function testMalformedOrMissingWritePayloadPreservesConfirmedOutcomeWithoutRetry(
        string $route,
        string $function,
        Closure $call,
        string $body,
        ?string $payload,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, 'null')));
        $call($account->mysql());
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, $payload)));

        try {
            $call($account->mysql());
            self::fail('A confirmed write with malformed or missing data must fail validation without retrying.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertRequest($http, $route, $function, $body);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, Mutation, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operations() as $name => [$route, $function, $call, $body]) {
            foreach ([null, '0', 'false', '"unexpected"', '[{}]'] as $payload) {
                yield $name . ' ' . ($payload ?? 'missing') => [$route, $function, $call, $body, $payload];
            }
        }
    }

    /** @param Mutation $call */
    #[DataProvider('operations')]
    public function testWriteApiAndHttpFailuresReplaceMetadataAndAreNotRetried(
        string $route,
        string $function,
        Closure $call,
        string $body,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, 'null')));
        $call($account->mysql());
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, null, 0)));
        try {
            $call($account->mysql());
            self::fail('A failed write outcome must fail before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic MySQL failure'], $error->errors());
        }
        $http->addResponse(new Response(503, [], 'Synthetic unavailable service'));
        try {
            $call($account->mysql());
            self::fail('An HTTP failure must clear response metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertRequest($http, $route, $function, $body);
        self::assertCount(3, $http->getRequests());
    }

    public function testBridgedWritesKeepAccountTargetsAndMetadataIsolated(): void
    {
        [$client, $http] = MysqlTestSupport::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope('bridge', 'create_database', 'null')));
        self::assertNull($first->mysql()->createDatabase('exampleuser_db'));
        $metadata = $first->responseMeta();
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope('bridge', 'delete_database', 'null')));
        self::assertNull($second->mysql()->deleteDatabase('other_db'));

        self::assertRequest($http, 'bridge', 'delete_database', 'name=other_db', 'other');
        self::assertSame($metadata, $first->responseMeta());
        self::assertNotSame($metadata, $second->responseMeta());
        self::assertNull($client->whm()->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, Mutation, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            $seen = [];
            foreach (self::mutations() as [$function, $call, $body]) {
                if (!isset($seen[$function])) {
                    yield $route . ' ' . $function => [$route, $function, $call, $body];
                    $seen[$function] = true;
                }
            }
        }
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $body,
        string $account = 'exampleuser',
    ): void {
        MysqlTestSupport::assertRequest(
            $http,
            'POST',
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                    . '&cpanel.module=Mysql&cpanel.function=' . $function
                : 'https://api.example.test:2083/execute/Mysql/' . $function,
            $body,
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            'application/x-www-form-urlencoded',
        );
    }
}
