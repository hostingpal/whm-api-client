<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client as ApiClient;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Exercise bounded MySQL inventory calls with independently authored raw-evidence expectations.
 *
 * @phpstan-import-type Record from EvidenceRunner
 */
final class MysqlProbeTest extends TestCase
{
    /** @param array<string, list<string>> $paths */
    #[DataProvider('payloads')]
    public function testMysqlProbeUsesExactRouteAndCapturesRawStructureOnce(
        string $operation,
        string $route,
        string $payload,
        array $paths,
    ): void {
        $http = new Client();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        $records = self::capture($http, self::probe($operation, $route));

        self::assertRequest($http, $operation, $route);
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('passed', $records[0]['payload_validation']);
        self::assertSame('passed', $records[0]['probe_outcome']);
        self::assertSame([], $records[0]['discrepancies']);
        self::assertSame($paths, $records[0]['payload_paths']);
        self::assertPrivateValuesAbsent($records);
    }

    /** @return iterable<string, array{string, string, string, array<string, list<string>>}> */
    public static function payloads(): iterable
    {
        $cases = [
            ['get_restrictions', '{"max_database_name_length":64,"max_username_length":32,"prefix":"private_"}', [
                '$' => ['object'], '$.max_database_name_length' => ['int'],
                '$.max_username_length' => ['int'], '$.prefix' => ['string'],
            ]],
            ['get_restrictions', '{"prefix":null}', [
                '$' => ['object'], '$.max_database_name_length' => ['missing'],
                '$.max_username_length' => ['missing'], '$.prefix' => ['null'],
            ]],
            ['get_server_information', '{"host":"private.example.test","is_remote":0,"version":"private-version"}', [
                '$' => ['object'], '$.host' => ['string'], '$.is_remote' => ['int'], '$.version' => ['string'],
            ]],
            ['list_databases', '[{"database":"private_database","disk_usage":457891,'
                . '"users":["private_dbuser","private_otheruser"]}]', [
                    '$' => ['list'], '$[]' => ['object'], '$[].database' => ['string'],
                    '$[].disk_usage' => ['int'], '$[].users' => ['list'], '$[].users[]' => ['string'],
                ]],
            ['list_databases', '[{"database":"private_database","disk_usage":0,"users":[]}]', [
                '$' => ['list'], '$[]' => ['object'], '$[].database' => ['string'],
                '$[].disk_usage' => ['int'], '$[].users' => ['empty-list'],
            ]],
            ['list_databases', '[{}]', [
                '$' => ['list'], '$[]' => ['empty-object'], '$[].database' => ['missing'],
                '$[].disk_usage' => ['missing'], '$[].users' => ['missing'],
            ]],
            ['list_users', '[{"databases":["private_database","private_otherdb"],'
                . '"shortuser":"private_shortuser","user":"private_dbuser"}]', [
                    '$' => ['list'], '$[]' => ['object'], '$[].databases' => ['list'],
                    '$[].databases[]' => ['string'], '$[].shortuser' => ['string'], '$[].user' => ['string'],
                ]],
            ['list_users', '[{"databases":[],"shortuser":"private_shortuser","user":"private_dbuser"}]', [
                '$' => ['list'], '$[]' => ['object'], '$[].databases' => ['empty-list'],
                '$[].shortuser' => ['string'], '$[].user' => ['string'],
            ]],
            ['list_users', '[{}]', [
                '$' => ['list'], '$[]' => ['empty-object'], '$[].databases' => ['missing'],
                '$[].shortuser' => ['missing'], '$[].user' => ['missing'],
            ]],
            ['list_databases', '[]', ['$' => ['empty-list']]],
            ['list_users', '[]', ['$' => ['empty-list']]],
        ];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach ($cases as $index => [$operation, $payload, $paths]) {
                yield $route . ' ' . $operation . ' ' . $index => [$operation, $route, $payload, $paths];
            }
        }
    }

    #[DataProvider('operationsAndRoutes')]
    public function testUnknownMysqlKeysRemainWildcardedBeforeTypedFieldRemoval(string $operation, string $route): void
    {
        $http = new Client();
        $list = in_array($operation, ['list_databases', 'list_users'], true);
        $payload = '{"private-account":{"private.example.test":{"database":"private_database"}}}';
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            $list ? '[' . $payload . ']' : $payload,
        )));
        $reviewed = self::probe($operation, $route);
        $normalized = null;
        $probe = new Probe(
            $reviewed->operation,
            $reviewed->route,
            $reviewed->spec,
            $reviewed->uri,
            static function (ApiClient $client) use ($reviewed, &$normalized): mixed {
                $normalized = ($reviewed->invoke)($client);

                return $normalized;
            },
            $reviewed->unavailable,
        );

        $records = self::capture($http, $probe);

        self::assertRequest($http, $operation, $route);
        self::assertSame($list ? [[]] : [], $normalized);
        self::assertSame('passed', $records[0]['payload_validation']);
        self::assertSame('passed', $records[0]['probe_outcome']);
        $path = $list ? '$[].*' : '$.*';
        self::assertSame([['path' => $path, 'kind' => 'unknown_field']], $records[0]['discrepancies']);
        self::assertSame(['object'], $records[0]['payload_paths'][$path]);
        self::assertSame(['object'], $records[0]['payload_paths'][$path . '.*']);
        self::assertSame(['string'], $records[0]['payload_paths'][$path . '.*.*']);
        self::assertArrayNotHasKey($path . '.*.database', $records[0]['payload_paths']);
        self::assertPrivateValuesAbsent($records);
    }

    #[DataProvider('operationsAndRoutes')]
    public function testMysqlApiFailureKeepsStructureWithoutPrivateErrorText(string $operation, string $route): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));

        $records = self::capture($http, self::probe($operation, $route));

        self::assertRequest($http, $operation, $route);
        self::assertSame('failure', $records[0]['api_outcome']);
        self::assertSame('not_checked', $records[0]['payload_validation']);
        self::assertSame('failed', $records[0]['probe_outcome']);
        self::assertSame('api_failure', $records[0]['failure_kind']);
        self::assertSame([], $records[0]['discrepancies']);
        self::assertSame(['$' => ['null']], $records[0]['payload_paths']);
        $prefix = $route === 'direct-uapi' ? '$' : '$.data.uapi';
        self::assertSame(['string'], $records[0]['response_paths'][$prefix . '.errors[]']);
        self::assertPrivateValuesAbsent($records);
    }

    #[DataProvider('malformedPayloads')]
    public function testAcceptedMysqlOutcomeKeepsRawMismatchEvidence(
        string $operation,
        string $route,
        string $payload,
        string $path,
        string $type,
    ): void {
        $http = new Client();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        $records = self::capture($http, self::probe($operation, $route));

        self::assertRequest($http, $operation, $route);
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('failed', $records[0]['payload_validation']);
        self::assertSame('failed', $records[0]['probe_outcome']);
        self::assertSame('payload_mismatch', $records[0]['failure_kind']);
        self::assertSame([['path' => $path, 'kind' => 'type_mismatch']], $records[0]['discrepancies']);
        self::assertSame([$type], $records[0]['payload_paths'][$path]);
        self::assertPrivateValuesAbsent($records);
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function malformedPayloads(): iterable
    {
        $cases = [
            ['get_restrictions', '{"max_username_length":"32"}', '$.max_username_length', 'numeric-string'],
            ['get_restrictions', '{"prefix":42}', '$.prefix', 'int'],
            ['get_server_information', '{"host":null}', '$.host', 'null'],
            ['get_server_information', '{"is_remote":"0"}', '$.is_remote', 'numeric-string'],
            ['list_databases', '[{"disk_usage":"457891"}]', '$[].disk_usage', 'numeric-string'],
            ['list_databases', '[{"users":[{"private_dbuser":"private_database"}]}]', '$[].users[]', 'object'],
            ['list_databases', '[{"database":null}]', '$[].database', 'null'],
            ['list_users', '[{"databases":[{"private_database":"private_dbuser"}]}]', '$[].databases[]', 'object'],
            ['list_users', '[{"user":null}]', '$[].user', 'null'],
            ['list_users', '{}', '$', 'empty-object'],
        ];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach ($cases as $index => [$operation, $payload, $path, $type]) {
                yield $route . ' ' . $operation . ' ' . $index => [$operation, $route, $payload, $path, $type];
            }
        }
    }

    #[DataProvider('operationsAndRoutes')]
    public function testMysqlGuardRejectsUnreviewedMethodBodyAccountOrUri(string $operation, string $route): void
    {
        $probe = self::probe($operation, $route);
        $http = new Client();
        $guard = new RequestGuard($http, $probe->uri);
        $uri = self::uri($operation, $route);
        $otherAccount = $route === 'direct-uapi' ? $uri . '?cpanel.user=private-account'
            : str_replace('cpanel.user=npr', 'cpanel.user=private-account', $uri);
        $requests = [
            new Request('POST', $uri),
            new Request('GET', $uri, [], 'private=secret'),
            new Request('GET', $otherAccount),
            new Request('GET', str_replace($operation, 'private-function', $uri)),
            new Request('GET', str_replace('test.oblak.host', 'private.example.test', $uri)),
            new Request('GET', $uri . ($route === 'direct-uapi' ? '?' : '&') . 'database=private_database'),
        ];
        foreach ($requests as $request) {
            try {
                $guard->sendRequest($request);
                self::fail('A request outside the exact MySQL recipe must be rejected.');
            } catch (LogicException $exception) {
                self::assertStringNotContainsString('private', $exception->getMessage());
            }
        }
        self::assertCount(0, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationsAndRoutes(): iterable
    {
        yield 'databases direct' => ['list_databases', 'direct-uapi'];
        yield 'databases bridge' => ['list_databases', 'bridged-uapi'];
        yield 'users direct' => ['list_users', 'direct-uapi'];
        yield 'users bridge' => ['list_users', 'bridged-uapi'];
        yield 'restrictions direct' => ['get_restrictions', 'direct-uapi'];
        yield 'restrictions bridge' => ['get_restrictions', 'bridged-uapi'];
        yield 'server info direct' => ['get_server_information', 'direct-uapi'];
        yield 'server info bridge' => ['get_server_information', 'bridged-uapi'];
    }

    private static function probe(string $operation, string $route): Probe
    {
        $probes = array_values(array_filter(
            ProbeCatalog::load(dirname(__DIR__, 3)),
            static fn (Probe $probe): bool =>
                $probe->operation === 'uapi:Mysql/' . $operation && $probe->route === $route,
        ));
        self::assertCount(1, $probes, 'Exactly one reviewed MySQL probe must bind this operation and route.');

        return $probes[0];
    }

    /** @return list<Record> */
    private static function capture(Client $http, Probe $probe): array
    {
        return (new EvidenceRunner($http, [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]))->run([$probe], '1', ['whm' => 'private-whm-marker', 'cpanel' => 'private-cpanel-marker']);
    }

    private static function assertRequest(Client $http, string $operation, string $route): void
    {
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::uri($operation, $route), (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'direct-uapi' ? 'cpanel npr:private-cpanel-marker' : 'whm root:private-whm-marker',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function uri(string $operation, string $route): string
    {
        return $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/Mysql/' . $operation
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Mysql&cpanel.function=' . $operation;
    }

    private static function envelope(string $route, string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ',"data":' . $payload
            . ',"errors":' . ($status === 1 ? 'null' : '["private error text"]')
            . ',"warnings":["private warning text"],"metadata":{"private@example.test":"private metadata"}}';

        return $route === 'direct-uapi' ? $result : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}';
    }

    /** @param list<Record> $records */
    private static function assertPrivateValuesAbsent(array $records): void
    {
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        $privateValues = [
            'private-whm-marker', 'private-cpanel-marker', 'private-account', 'private.example.test',
            'private_database', 'private_otherdb', 'private_dbuser', 'private_otheruser', 'private_shortuser',
            'private error text', 'private warning text', 'private@example.test', 'private metadata', '457891',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }
}
