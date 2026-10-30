<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Mysql;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-type Call Closure(Mysql): mixed */
final class UapiMysqlSectionTest extends TestCase
{
    /** @param Call $call */
    #[DataProvider('successfulCalls')]
    public function testSectionUsesExactRequestsAndPreservesDocumentedPayloads(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, $payload)));
        self::assertSame($expected, $call($account->mysql()));
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Mysql&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Mysql/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        MysqlTestSupport::assertRequest(
            $http,
            $method,
            $uri,
            $method === 'POST' ? $parameters : '',
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $method === 'POST' ? 'application/x-www-form-urlencoded' : null,
        );
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame(['Synthetic MySQL notice'], $account->responseMeta()->uapiWarnings());
    }

    /** @return iterable<string, array{string, string, string, Call, string, string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::calls() as $name => [$function, $method, $call, $parameters, $payload, $expected]) {
                yield $route . ' ' . $name => [$route, $function, $method, $call, $parameters, $payload, $expected];
            }
        }
    }

    /**
     * Literal expectations from the pinned contracts, independent of the generator and specs.
     *
     * @return iterable<string, array{string, string, Call, string, string, mixed}>
     */
    public static function calls(): iterable
    {
        $opaque = [
            'add host' => ['add_host', static fn (Mysql $m): ?array => $m->addHost('192.0.2.%'), 'host=192.0.2.%25'],
            'host note' => ['add_host_note', static fn (Mysql $m): ?array => $m->addHostNote(
                'db.example.test',
                'a & b',
            ),
                'host=db.example.test&note=a%20%26%20b'],
            'delete host' => ['delete_host', static fn (Mysql $m): ?array => $m->deleteHost('192.0.2.0/24'),
                'host=192.0.2.0%2F24'],
            'rename database' => ['rename_database',
                static fn (Mysql $m): ?array => $m->renameDatabase('exampleuser_old', 'exampleuser_new'),
                'oldname=exampleuser_old&newname=exampleuser_new'],
            'rename user' => ['rename_user', static fn (Mysql $m): ?array => $m->renameUser(
                'exampleuser_old',
                'exampleuser_new',
            ),
                'oldname=exampleuser_old&newname=exampleuser_new'],
            'revoke' => ['revoke_access_to_database',
                static fn (Mysql $m): ?array => $m->revokeAccessToDatabase('exampleuser_db', 'exampleuser_user'),
                'database=exampleuser_db&user=exampleuser_user'],
            'update privileges' => ['update_privileges', static fn (Mysql $m): ?array => $m->updatePrivileges(), ''],
        ];
        foreach ($opaque as $name => [$function, $call, $parameters]) {
            foreach (
                [
                ['null', null], ['{}', []], ['{"opaque":[0,null]}', ['opaque' => [0,
                null]]]] as [$payload, $expected]
            ) {
                yield $name . ' ' . $payload => [$function, 'POST', $call, $parameters, $payload, $expected];
            }
        }
        foreach (['check_database' => 'checkDatabase', 'repair_database' => 'repairDatabase'] as $function => $method) {
            foreach (
                [
                ['[]', []], ['[{}, {"table":"exampleuser_db.t","msg_type":"note","msg_text":"message"}]',
                [
                [], ['msg_text' => 'message', 'msg_type' => 'note',
                    'table' => 'exampleuser_db.t']]]] as [$payload, $expected]
            ) {
                yield $function . ' ' . $payload => [$function, 'POST',
                    static fn (Mysql $m): array => $m->$method('exampleuser_db'),
                    'name=exampleuser_db', $payload, $expected];
            }
        }
        yield 'schema string' => ['dump_database_schema', 'GET',
            static fn (Mysql $m): string => $m->dumpDatabaseSchema('exampleuser_db'),
            'dbname=exampleuser_db', '"CREATE TABLE `t` (id int);"', 'CREATE TABLE `t` (id int);'];
        yield 'empty schema string' => ['dump_database_schema', 'GET',
            static fn (Mysql $m): string => $m->dumpDatabaseSchema('exampleuser_db'),
            'dbname=exampleuser_db', '""', ''];
        yield 'dynamic host notes' => ['get_host_notes', 'GET', static fn (Mysql $m): array => $m->getHostNotes(), '',
            '{"192.0.2.1":"","db.example.test":"note","123":"numeric key"}',
            ['192.0.2.1' => '', 'db.example.test' => 'note', 123 => 'numeric key']];
        yield 'empty host notes' => ['get_host_notes', 'GET', static fn (Mysql $m): array => $m->getHostNotes(),
            '', '{}', []];
        yield 'all routines' => ['list_routines', 'GET', static fn (Mysql $m): array => $m->listRoutines(), '',
            '[]', []];
        yield 'user routines' => ['list_routines', 'GET',
            static fn (Mysql $m): array => $m->listRoutines(databaseUser: 'exampleuser_writer'),
            'database_user=exampleuser_writer', '["exampleuser_db.routine"]', ['exampleuser_db.routine']];
        yield 'empty user retained' => ['list_routines', 'GET',
            static fn (Mysql $m): array => $m->listRoutines(databaseUser: ''),
            'database_user=', '[]', []];
        foreach (
            [
            ['{}', []], ['{"is_remote":0}', ['is_remote' => 0]], ['{"is_remote":1,"remote_host":"db.example.test"}',
            ['is_remote' => 1, 'remote_host' => 'db.example.test']]] as [$payload, $expected]
        ) {
            yield 'locate ' . $payload => ['locate_server', 'GET',
                static fn (Mysql $m): array => $m->locateServer(), '', $payload, $expected];
        }
        foreach (
            [
            ['{}', []], ['{"failures":[]}', ['failures' => []]],
            ['{"failures":[{}, {"host":"db.example.test","error":"denied"}]}', ['failures' => [
            [],
                ['error' => 'denied', 'host' => 'db.example.test']]]]] as [$payload, $expected]
        ) {
            yield 'password ' . $payload => ['set_password', 'POST',
                static fn (Mysql $m): array => $m->setPassword('exampleuser_writer', 'a +&雪'),
                'user=exampleuser_writer&password=a%20%2B%26%E9%9B%AA', $payload, $expected];
        }
        yield 'setup omitted prefix' => ['setup_db_and_user', 'POST',
            static fn (Mysql $m): array => $m->setupDbAndUser(), '', '{}', []];
        yield 'setup empty prefix' => ['setup_db_and_user', 'POST',
            static fn (Mysql $m): array => $m->setupDbAndUser(prefix: ''), 'prefix=', '{}', []];
        yield 'setup credentials' => ['setup_db_and_user', 'POST',
            static fn (Mysql $m): array => $m->setupDbAndUser(prefix: 'app'), 'prefix=app',
            '{"database":"exampleuser_app_db","database_user":"exampleuser_app_user",'
                . '"database_user_password":"a +&雪","hostname":"localhost","port":"3306"}',
            ['database' => 'exampleuser_app_db', 'database_user' => 'exampleuser_app_user',
                'database_user_password' => 'a +&雪', 'hostname' => 'localhost', 'port' => '3306']];
    }

    /** @param Call $call */
    #[DataProvider('invalidPayloads')]
    public function testMalformedOrAbsentPayloadRetainsOutcomeWithoutRetry(
        string $route,
        string $function,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, $payload)));
        try {
            $call($account->mysql());
            self::fail('Malformed data must be rejected.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, Call, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $invalid = [
            'add_host' => ['0', '[{}]'], 'add_host_note' => ['false', '"bad"'], 'delete_host' => ['0'],
            'rename_database' => ['[{}]'], 'rename_user' => ['true'], 'revoke_access_to_database' => ['1'],
            'update_privileges' => ['"bad"'], 'check_database' => ['null', '[{"table":1}]', '[{"msg_text":false}]'],
            'repair_database' => ['null', '[{"msg_type":1}]'], 'dump_database_schema' => ['null', '{}', '0'],
            'get_host_notes' => ['null', '{"db.example.test":null}', '{"db.example.test":1}'],
            'list_routines' => ['null', '[1]', '[{}]'], 'locate_server' => ['null', '{"is_remote":2}',
                '{"remote_host":null}'],
            'set_password' => ['null', '{"failures":null}', '{"failures":[{"error":1}]}',
                '{"failures":[{"host":false}]}'],
            'setup_db_and_user' => ['null', '{"port":3306}', '{"database_user_password":null}'],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            $seen = [];
            foreach (self::calls() as [$function, , $call]) {
                if (isset($seen[$function])) {
                    continue;
                }
                $seen[$function] = true;
                foreach ([null, ...$invalid[$function]] as $index => $payload) {
                    yield $route . ' ' . $function . ' ' . $index => [$route, $function, $call, $payload];
                }
            }
        }
    }

    /** @param Call $call */
    #[DataProvider('successfulCalls')]
    public function testApiFailureReplacesPriorMetadata(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, $payload)));
        self::assertSame($expected, $call($account->mysql()));
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, null, 0)));
        try {
            $call($account->mysql());
            self::fail('API failure must not be interpreted as an empty payload.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertNotSame($previous, $account->responseMeta());
            self::assertSame($error->metadata, $account->responseMeta());
        }
        self::assertCount(2, $http->getRequests());
    }
}
