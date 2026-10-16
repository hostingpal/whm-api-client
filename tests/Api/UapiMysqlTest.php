<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Mysql;
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

/**
 * @phpstan-type Database array{database?: string, disk_usage?: int, users?: list<string>}
 * @phpstan-type DatabaseUser array{databases?: list<string>, shortuser?: string, user?: string}
 */
final class UapiMysqlTest extends TestCase
{
    public function testMysqlInventoryDefaultsToTheAuthenticatedCpanelAccount(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], '{"result":{"status":1,"data":[]}}'));

        self::assertSame([], $client->uapi()->mysql()->listDatabases());
        self::assertRequest($http, 'direct', 'list_databases');
        self::assertCount(1, $http->getRequests());
    }

    public function testMysqlSelectorBindsTheAccountWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');

        self::assertSame($account->mysql(), $account->mysql());
        self::assertNotSame($account->mysql(), $client->uapi('other')->mysql());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    /** @param list<Database>|list<DatabaseUser> $expected */
    #[DataProvider('successfulPayloads')]
    public function testMysqlInventoryUsesAccountBoundGetAndPreservesDocumentedValues(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));

        self::assertSame($expected, self::invoke($account->mysql(), $function));

        self::assertRequest($http, $route, $function);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic MySQL notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertSame($account->mysql(), $account->mysql());
        self::assertSame($metadata, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, list<Database>|list<DatabaseUser>}> */
    public static function successfulPayloads(): iterable
    {
        // Independently authored from the pinned Mysql schemas: all row fields are optional, none nullable.
        $cases = [
            'database bytes and ordered users' => ['list_databases',
                '[{"database":"exampleuser_main","disk_usage":673,"users":["exampleuser_writer","exampleuser_reader"],'
                . '"future":{"private":"ignored"}},{"database":"exampleuser_empty","disk_usage":0,"users":[]}]',
                [
                    ['database' => 'exampleuser_main', 'disk_usage' => 673,
                        'users' => ['exampleuser_writer', 'exampleuser_reader']],
                    ['database' => 'exampleuser_empty', 'disk_usage' => 0, 'users' => []],
                ]],
            'database fields stay omitted' => ['list_databases',
                '[{}, {"database":"exampleuser_main"}, {"disk_usage":0}, {"users":[]}]',
                [[], ['database' => 'exampleuser_main'], ['disk_usage' => 0], ['users' => []]]],
            'database strings stay literal' => ['list_databases', '[{"database":"","users":["0",""]}]',
                [['database' => '', 'users' => ['0', '']]]],
            'user database assignments and short names' => ['list_users',
                '[{"databases":["exampleuser_second","exampleuser_first"],"shortuser":"writer",'
                . '"user":"exampleuser_writer","future":true},'
                . '{"databases":[],"shortuser":"unused","user":"exampleuser_unused"}]',
                [
                    ['databases' => ['exampleuser_second', 'exampleuser_first'],
                        'shortuser' => 'writer', 'user' => 'exampleuser_writer'],
                    ['databases' => [], 'shortuser' => 'unused', 'user' => 'exampleuser_unused'],
                ]],
            'user fields stay omitted' => ['list_users',
                '[{}, {"databases":[]}, {"shortuser":"writer"}, {"user":"exampleuser_writer"}]',
                [[], ['databases' => []], ['shortuser' => 'writer'], ['user' => 'exampleuser_writer']]],
            'user strings stay literal' => ['list_users', '[{"databases":["0",""],"shortuser":"","user":"0"}]',
                [['databases' => ['0', ''], 'shortuser' => '', 'user' => '0']]],
        ];
        foreach (['list_databases', 'list_users'] as $function) {
            $cases[$function . ' empty list'] = [$function, '[]', []];
            $cases[$function . ' unknown fields do not add keys'] = [
                $function, '[{"future":{"private":"ignored"}}]', [[]],
            ];
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$function, $payload, $expected]) {
                yield $route . ' ' . $name => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidMysqlInventoryKeepsTheNewConfirmedOutcome(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, '[]')));
        self::invoke($account->mysql(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));

        try {
            self::invoke($account->mysql(), $function);
            self::fail('Missing or malformed MySQL inventory must fail payload validation.');
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
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        foreach (['list_databases', 'list_users'] as $function) {
            foreach ([null, 'null', '{}', '0', '"row"', 'true', '[null]', '[7]', '[["row"]]'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        $stringFields = ['database' => 'list_databases', 'shortuser' => 'list_users', 'user' => 'list_users'];
        foreach ($stringFields as $field => $function) {
            foreach (['null', '7', 'false', '[]'] as $value) {
                $cases[] = [$function, '[{"' . $field . '":' . $value . '}]'];
            }
        }
        // The source specifies an integer; no observed wire correction permits numeric strings.
        foreach (['null', '"0"', '"673"', '1.5', 'true'] as $value) {
            $cases[] = ['list_databases', '[{"disk_usage":' . $value . '}]'];
        }
        foreach (['users' => 'list_databases', 'databases' => 'list_users'] as $field => $function) {
            foreach (['null', '{}', '"single"', '[null]', '[1]', '[false]', '[{}]', '[["nested"]]'] as $value) {
                $cases[] = [$function, '[{"' . $field . '":' . $value . '}]'];
            }
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as [$function, $payload]) {
                yield $route . ' ' . $function . ' ' . ($payload ?? 'missing') => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('operations')]
    public function testApiAndHttpFailuresReplaceMysqlMetadataWithoutRetrying(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, '[]')));
        self::invoke($account->mysql(), $function);
        $successful = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, null, 0)));

        try {
            self::invoke($account->mysql(), $function);
            self::fail('A failed UAPI outcome must fail before inventory payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($successful, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(['Synthetic MySQL failure'], $error->errors());
        }

        $http->addResponse(new Response(503, [], 'Synthetic unavailable service'));
        try {
            self::invoke($account->mysql(), $function);
            self::fail('An HTTP failure must clear the preceding response metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertSame(1, $successful?->uapiStatus());
        self::assertCount(3, $http->getRequests());
    }

    public function testBridgedMysqlInventoriesKeepAccountsAndMetadataIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::envelope(
            'bridge',
            'list_databases',
            '[{"database":"exampleuser_main"}]',
        )));
        self::assertSame([['database' => 'exampleuser_main']], $first->mysql()->listDatabases());
        self::assertRequest($http, 'bridge', 'list_databases');
        $firstMetadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope(
            'bridge',
            'list_users',
            '[{"user":"other_writer"}]',
        )));

        self::assertSame([['user' => 'other_writer']], $second->mysql()->listUsers());

        self::assertRequest($http, 'bridge', 'list_users', 'other');
        self::assertSame($firstMetadata, $first->responseMeta());
        self::assertNotSame($firstMetadata, $second->responseMeta());
        self::assertNull($client->whm()->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    public function testWhmMysqlInventoryDoesNotAssumeRootIsACpanelAccount(): void
    {
        [$client, $http] = self::client('bridge');

        try {
            $client->uapi()->mysql()->listDatabases();
            self::fail('WHM credentials require an explicit or configured cPanel account.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testWhmMysqlInventoryUsesTheConfiguredCpanelAccount(): void
    {
        [$client, $http] = self::client('bridge', 'configured');
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'list_users', '[]')));

        self::assertSame([], $client->uapi()->mysql()->listUsers());

        self::assertRequest($http, 'bridge', 'list_users', 'configured');
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('mysqlFunctions')]
    public function testOuterWhmFailureTakesPrecedenceOverInnerMysqlSuccess(string $function): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope('bridge', $function, '[]')));
        self::invoke($account->mysql(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic denied"},'
            . '"data":{"uapi":{"status":1,"data":[]}}}'));

        try {
            self::invoke($account->mysql(), $function);
            self::fail('A failed WHM bridge must fail even when its inner UAPI outcome is successful.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame(['Synthetic denied'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['list_databases', 'list_users'] as $function) {
                yield $route . ' ' . $function => [$route, $function];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function mysqlFunctions(): iterable
    {
        yield 'databases' => ['list_databases'];
        yield 'users' => ['list_users'];
    }

    /** @return list<Database>|list<DatabaseUser> */
    private static function invoke(Mysql $mysql, string $function): array
    {
        return match ($function) {
            'list_databases' => $mysql->listDatabases(),
            'list_users' => $mysql->listUsers(),
            default => throw new LogicException('Unknown MySQL inventory test function.'),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $account = 'exampleuser',
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                    . '&cpanel.module=Mysql&cpanel.function=' . $function
                : 'https://api.example.test:2083/execute/Mysql/' . $function,
            (string) $request->getUri(),
        );
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, string $function, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic MySQL failure"]')
            . ',"messages":null,"warnings":["Synthetic MySQL notice"],"metadata":{"transformed":1}}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Mysql","func":"' . $function . '","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route, ?string $defaultCpanelUser = null): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = [
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ];
        if ($defaultCpanelUser !== null) {
            $options['defaultCpanelUser'] = $defaultCpanelUser;
        }

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
