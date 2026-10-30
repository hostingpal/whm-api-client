<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Postgresql;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Independent fixtures from the pinned Postgresql operations, not from generated specs. */
final class UapiPostgresqlTest extends TestCase
{
    public function testPostgresqlSelectorBindsAccountsWithoutIoOrMetadataReset(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        self::assertSame($account->postgresql(), $account->postgresql());
        self::assertNotSame($account->postgresql(), $client->uapi('other')->postgresql());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());

        $http->addResponse(new Response(200, [], self::envelope('bridge', 'list_users', '[]')));
        self::assertSame([], $account->postgresql()->listUsers());
        $metadata = $account->responseMeta();
        $account->postgresql();
        self::assertSame($metadata, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed>|int|null $expected */
    #[DataProvider('successfulPayloads')]
    public function testSuccessfulCallsPreservePinnedPayloadsAndSendExactAccountBoundRequests(
        string $route,
        string $function,
        string $payload,
        array|int|null $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));

        self::assertSame($expected, self::invoke($account->postgresql(), $function));

        self::assertRequest($http, $route, $function);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic PostgreSQL notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>|int|null}> */
    public static function successfulPayloads(): iterable
    {
        $cases = [
            ['get_restrictions', '{"max_database_name_length":63,"max_username_length":63,"prefix":"example_"}',
                ['max_database_name_length' => 63, 'max_username_length' => 63, 'prefix' => 'example_']],
            ['get_restrictions', '{}', []],
            ['get_restrictions', '{"prefix":"","future":true}', ['prefix' => '']],
            ['get_restrictions', '{"max_username_length":63}', ['max_username_length' => 63]],
            ['list_databases', '[{"database":"exampleuser_main","disk_usage":673,'
                . '"users":["exampleuser_writer","exampleuser_reader"],"future":{"private":"ignored"}},'
                . '{"database":"exampleuser_empty","disk_usage":0,"users":[]}]', [
                    ['database' => 'exampleuser_main', 'disk_usage' => 673,
                        'users' => ['exampleuser_writer', 'exampleuser_reader']],
                    ['database' => 'exampleuser_empty', 'disk_usage' => 0, 'users' => []],
                ]],
            ['list_databases', '[{},{"database":""},{"disk_usage":0},{"users":[]}]',
                [[], ['database' => ''], ['disk_usage' => 0], ['users' => []]]],
            ['list_databases', '[{"users":["0",""]}]', [['users' => ['0', '']]]],
            ['list_databases', '[]', []],
            ['list_users', '["exampleuser_second","exampleuser_first","0",""]',
                ['exampleuser_second', 'exampleuser_first', '0', '']],
            ['list_users', '[]', []],
            // create_user is nullable integer, unlike every other PostgreSQL mutation.
            ['create_user', 'null', null],
            ['create_user', '0', 0],
            ['create_user', '12345', 12345],
        ];
        foreach (self::objectMutations() as $function) {
            $cases[] = [$function, 'null', null];
            $cases[] = [$function, '{}', []];
            // The shared PHP object boundary accepts an empty JSON array as an empty object.
            $cases[] = [$function, '[]', []];
            $cases[] = [$function, '{"opaque":{"numbers":[0,1],"none":null},"0":"literal"}',
                ['opaque' => ['numbers' => [0, 1], 'none' => null], 0 => 'literal']];
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $index => [$function, $payload, $expected]) {
                yield $route . ' ' . $function . ' ' . $index => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMissingOrMalformedPayloadKeepsTheConfirmedOutcomeAndIsNotRetried(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, self::validPayload($function))));
        self::invoke($account->postgresql(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));

        try {
            self::invoke($account->postgresql(), $function);
            self::fail('Missing or malformed PostgreSQL payload must fail validation.');
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
        self::assertSame(1, $previous?->uapiStatus());
        self::assertRequest($http, $route, $function);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        foreach (['get_restrictions', 'list_databases', 'list_users'] as $function) {
            foreach ([null, 'null', '0', 'false', '"unexpected"'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        foreach (['list_databases', 'list_users'] as $function) {
            foreach (['{}', '[null]', '[7]', '[false]', '[["nested"]]'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        $cases[] = ['list_users', '[{"user":"exampleuser_writer"}]'];
        $cases[] = ['get_restrictions', '[{}]'];
        foreach (['null', '7', 'false', '[]'] as $value) {
            $cases[] = ['get_restrictions', '{"prefix":' . $value . '}'];
            $cases[] = ['list_databases', '[{"database":' . $value . '}]'];
        }
        foreach (['null', '"0"', '"673"', '1.5', 'true', '[]'] as $value) {
            $cases[] = ['list_databases', '[{"disk_usage":' . $value . '}]'];
            $cases[] = ['get_restrictions', '{"max_database_name_length":' . $value . '}'];
            $cases[] = ['get_restrictions', '{"max_username_length":' . $value . '}'];
        }
        foreach (['null', '{}', '"single"', '[null]', '[1]', '[false]', '[{}]', '[["nested"]]'] as $value) {
            $cases[] = ['list_databases', '[{"users":' . $value . '}]'];
        }
        foreach ([null, '"0"', '"12345"', '1.0', '1.5', 'false', 'true', '{}', '[]', '[1]'] as $payload) {
            $cases[] = ['create_user', $payload];
        }
        foreach (self::objectMutations() as $function) {
            foreach ([null, '0', 'false', '"unexpected"', '[{}]'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as [$function, $payload]) {
                yield $route . ' ' . $function . ' ' . ($payload ?? 'missing') => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('operations')]
    public function testApiAndHttpFailuresReplaceMetadataWithoutRetrying(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, self::validPayload($function))));
        self::invoke($account->postgresql(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, null, 0)));

        try {
            self::invoke($account->postgresql(), $function);
            self::fail('Failed UAPI outcomes must be checked before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(['Synthetic PostgreSQL failure'], $error->errors());
        }
        $http->addResponse(new Response(503, [], 'Synthetic unavailable service'));
        try {
            self::invoke($account->postgresql(), $function);
            self::fail('An HTTP failure must clear response metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertSame(1, $previous?->uapiStatus());
        self::assertRequest($http, $route, $function);
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('functions')]
    public function testOuterWhmFailureTakesPrecedenceOverInnerPostgresqlSuccess(string $function): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic denied"},'
            . '"data":{"uapi":{"status":1,"data":' . self::validPayload($function) . '}}}'));

        try {
            self::invoke($account->postgresql(), $function);
            self::fail('The outer WHM outcome must be checked for every PostgreSQL operation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame(['Synthetic denied'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testDirectCallsDefaultToTheAuthenticatedAccount(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', 'list_users', '[]')));

        self::assertSame([], $client->uapi()->postgresql()->listUsers());

        self::assertRequest($http, 'direct', 'list_users');
        self::assertCount(1, $http->getRequests());
    }

    public function testWhmCallsRequireAnExplicitCpanelAccount(): void
    {
        [$client, $http] = self::client('bridge');
        try {
            $client->uapi()->postgresql()->listUsers();
            self::fail('WHM root must not be assumed to be the cPanel target.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testBridgedWritesKeepAccountTargetsAndMetadataIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'create_database', 'null')));
        self::assertNull($first->postgresql()->createDatabase('exampleuser_db'));
        self::assertRequest($http, 'bridge', 'create_database');
        $metadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'update_privileges', 'null')));
        self::assertNull($second->postgresql()->updatePrivileges());

        self::assertRequest($http, 'bridge', 'update_privileges', 'other');
        self::assertSame($metadata, $first->responseMeta());
        self::assertNotSame($metadata, $second->responseMeta());
        self::assertNull($client->whm()->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testRenamesAndPasswordChangeAcceptOldNameFirstAndUserFirstPositionalArguments(string $route): void
    {
        [$client, $http] = self::client($route);
        $postgresql = $client->uapi('exampleuser')->postgresql();
        foreach (['rename_database', 'rename_user', 'rename_user_no_password', 'set_password'] as $function) {
            $http->addResponse(new Response(200, [], self::envelope($route, $function, 'null')));
            $result = match ($function) {
                'rename_database' => $postgresql->renameDatabase('exampleuser_old', 'exampleuser_new'),
                'rename_user' => $postgresql->renameUser('exampleuser_old', 'exampleuser_new', 'p a+&=%/雪'),
                'rename_user_no_password' => $postgresql->renameUserNoPassword('exampleuser_old', 'exampleuser_new'),
                'set_password' => $postgresql->setPassword('exampleuser_writer', 'p a+&=%/雪'),
            };
            self::assertNull($result);
            self::assertRequest($http, $route, $function);
        }
        self::assertCount(4, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::functions() as [$function]) {
                yield $route . ' ' . $function => [$route, $function];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function functions(): iterable
    {
        $functions = ['get_restrictions', 'list_databases', 'list_users', 'create_user', ...self::objectMutations()];
        foreach ($functions as $function) {
            yield $function => [$function];
        }
    }

    /** @return list<string> */
    private static function objectMutations(): array
    {
        return [
            'create_database', 'delete_database', 'delete_user', 'grant_all_privileges', 'rename_database',
            'rename_user', 'rename_user_no_password', 'revoke_all_privileges', 'set_password', 'update_privileges',
        ];
    }

    /** @return array<array-key, mixed>|int|null */
    private static function invoke(Postgresql $postgresql, string $function): array|int|null
    {
        return match ($function) {
            'get_restrictions' => $postgresql->getRestrictions(),
            'list_databases' => $postgresql->listDatabases(),
            'list_users' => $postgresql->listUsers(),
            'create_database' => $postgresql->createDatabase(name: 'exampleuser_db'),
            'create_user' => $postgresql->createUser(name: 'exampleuser_writer', password: 'p a+&=%/雪'),
            'delete_database' => $postgresql->deleteDatabase(name: 'exampleuser_db'),
            'delete_user' => $postgresql->deleteUser(name: 'exampleuser_writer'),
            'grant_all_privileges' => $postgresql->grantAllPrivileges(
                database: 'exampleuser_db',
                user: 'exampleuser_writer',
            ),
            'rename_database' => $postgresql->renameDatabase(oldName: 'exampleuser_old', newName: 'exampleuser_new'),
            'rename_user' => $postgresql->renameUser(
                oldName: 'exampleuser_old',
                newName: 'exampleuser_new',
                password: 'p a+&=%/雪',
            ),
            'rename_user_no_password' => $postgresql->renameUserNoPassword(
                oldName: 'exampleuser_old',
                newName: 'exampleuser_new',
            ),
            'revoke_all_privileges' => $postgresql->revokeAllPrivileges(
                database: 'exampleuser_db',
                user: 'exampleuser_writer',
            ),
            'set_password' => $postgresql->setPassword(user: 'exampleuser_writer', password: 'p a+&=%/雪'),
            'update_privileges' => $postgresql->updatePrivileges(),
            default => throw new LogicException('Unknown PostgreSQL test function.'),
        };
    }

    private static function validPayload(string $function): string
    {
        return match ($function) {
            'get_restrictions' => '{}',
            'list_databases', 'list_users' => '[]',
            default => 'null',
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $account = 'exampleuser',
    ): void {
        // Literal forms are independent expectations, including old/new wire names and encoded password bytes.
        $bodies = [
            'create_database' => 'name=exampleuser_db',
            'create_user' => 'name=exampleuser_writer&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'delete_database' => 'name=exampleuser_db',
            'delete_user' => 'name=exampleuser_writer',
            'grant_all_privileges' => 'database=exampleuser_db&user=exampleuser_writer',
            'rename_database' => 'oldname=exampleuser_old&newname=exampleuser_new',
            'rename_user' => 'oldname=exampleuser_old&newname=exampleuser_new&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'rename_user_no_password' => 'oldname=exampleuser_old&newname=exampleuser_new',
            'revoke_all_privileges' => 'database=exampleuser_db&user=exampleuser_writer',
            'set_password' => 'user=exampleuser_writer&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'update_privileges' => '',
        ];
        $method = isset($bodies[$function]) ? 'POST' : 'GET';
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                    . '&cpanel.module=Postgresql&cpanel.function=' . $function
                : 'https://api.example.test:2083/execute/Postgresql/' . $function,
            (string) $request->getUri(),
        );
        self::assertSame($bodies[$function] ?? '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, string $function, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic PostgreSQL failure"]')
            . ',"messages":null,"warnings":["Synthetic PostgreSQL notice"],"metadata":{"transformed":1}}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Postgresql","func":"' . $function . '","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = [
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ];

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
