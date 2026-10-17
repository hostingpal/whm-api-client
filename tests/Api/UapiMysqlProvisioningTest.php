<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Mysql;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use LogicException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independent pinned-contract expectations for account provisioning reads. */
final class UapiMysqlProvisioningTest extends TestCase
{
    /** @param array<mixed> $expected */
    #[DataProvider('successfulPayloads')]
    public function testReadsUseExactAccountBoundGetAndPreserveTheContract(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, $payload)));

        self::assertSame($expected, self::invoke($account->mysql(), $function));
        $endpoint = $function === 'get_privileges_on_database'
            ? 'get_privileges_on_database?database=exampleuser_db%20%2B%26&user=exampleuser_u%2F%23' : $function;
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Mysql&cpanel.function=' . str_replace('?', '&', $endpoint)
            : 'https://api.example.test:2083/execute/Mysql/' . $endpoint;
        MysqlTestSupport::assertRequest(
            $http,
            'GET',
            $uri,
            '',
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
        );
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic MySQL notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<mixed>}> */
    public static function successfulPayloads(): iterable
    {
        $cases = [
            'privileges preserve order and strings' => ['get_privileges_on_database',
                '["SELECT","CREATE TEMPORARY TABLES","SELECT","FUTURE PRIVILEGE"]',
                ['SELECT', 'CREATE TEMPORARY TABLES', 'SELECT', 'FUTURE PRIVILEGE']],
            'all privileges' => ['get_privileges_on_database', '["ALL PRIVILEGES"]', ['ALL PRIVILEGES']],
            'no privileges' => ['get_privileges_on_database', '[]', []],
            'restrictions with prefix' => ['get_restrictions',
                '{"max_database_name_length":64,"max_username_length":16,"prefix":"example_","extra":true}',
                ['max_database_name_length' => 64, 'max_username_length' => 16, 'prefix' => 'example_']],
            'disabled prefix' => ['get_restrictions', '{"prefix":null}', ['prefix' => null]],
            'omitted prefix' => ['get_restrictions', '{"max_username_length":32}', ['max_username_length' => 32]],
            'empty prefix stays present' => ['get_restrictions', '{"prefix":""}', ['prefix' => '']],
            'remote host' => ['get_server_information',
                '{"host":"remote.example.test","is_remote":1,"version":"8.0.36","extra":true}',
                ['host' => 'remote.example.test', 'is_remote' => 1, 'version' => '8.0.36']],
            'local host' => ['get_server_information',
                '{"host":"192.0.2.1","is_remote":0,"version":"10.11.6-MariaDB"}',
                ['host' => '192.0.2.1', 'is_remote' => 0, 'version' => '10.11.6-MariaDB']],
            'omitted host fields' => ['get_server_information', '{"version":""}', ['version' => '']],
        ];
        foreach (['get_restrictions', 'get_server_information'] as $function) {
            $cases[$function . ' absent optional fields'] = [$function, '{}', []];
            $cases[$function . ' existing empty-map compatibility'] = [$function, '[]', []];
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$function, $payload, $expected]) {
                yield $route . ' ' . $name => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadReplacesMetadataAndPreservesTheConfirmedOutcome(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, '[]')));
        self::invoke($account->mysql(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, $payload)));
        try {
            self::invoke($account->mysql(), $function);
            self::fail('The missing or malformed payload must fail validation.');
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
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        foreach (['get_privileges_on_database', 'get_restrictions', 'get_server_information'] as $function) {
            foreach ([null, 'null', 'true', '42', '"string"', '[null]', '[3]'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        foreach (['{}', '[false]', '[{}]', '[["SELECT"]]'] as $payload) {
            $cases[] = ['get_privileges_on_database', $payload];
        }
        foreach (['max_database_name_length', 'max_username_length'] as $field) {
            foreach (['null', '"16"', '1.5', 'true', '{}'] as $value) {
                $cases[] = ['get_restrictions', '{"' . $field . '":' . $value . '}'];
            }
        }
        foreach (['42', 'false', '[]'] as $value) {
            $cases[] = ['get_restrictions', '{"prefix":' . $value . '}'];
        }
        foreach (['host', 'version'] as $field) {
            foreach (['null', '42', 'false', '[]'] as $value) {
                $cases[] = ['get_server_information', '{"' . $field . '":' . $value . '}'];
            }
        }
        foreach (['null', 'true', '"1"', '2', '-1', '0.5'] as $value) {
            $cases[] = ['get_server_information', '{"is_remote":' . $value . '}'];
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $index => [$function, $payload]) {
                yield $route . ' case ' . $index => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('operations')]
    public function testApiFailureIsNotAnEmptySuccessAndNeverRetries(string $route, string $function): void
    {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], MysqlTestSupport::envelope($route, $function, '[]', 0)));
        try {
            self::invoke($account->mysql(), $function);
            self::fail('An unsuccessful API outcome must throw.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['Synthetic MySQL failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['get_privileges_on_database', 'get_restrictions', 'get_server_information'] as $function) {
                yield $route . ' ' . $function => [$route, $function];
            }
        }
    }

    /** @return array<mixed> */
    private static function invoke(Mysql $mysql, string $function): array
    {
        return match ($function) {
            'get_privileges_on_database' => $mysql->getPrivilegesOnDatabase(
                user: 'exampleuser_u/#',
                database: 'exampleuser_db +&',
            ),
            'get_restrictions' => $mysql->getRestrictions(),
            'get_server_information' => $mysql->getServerInformation(),
            default => throw new LogicException('Unsupported test operation.'),
        };
    }
}
