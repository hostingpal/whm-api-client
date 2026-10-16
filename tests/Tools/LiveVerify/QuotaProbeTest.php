<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

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
 * Exercise bounded Quota calls with independently authored payload and raw-evidence expectations.
 *
 * @phpstan-import-type Record from EvidenceRunner
 */
final class QuotaProbeTest extends TestCase
{
    /** @param array<string, list<string>> $paths */
    #[DataProvider('payloads')]
    public function testQuotaProbeUsesExactRouteAndCapturesRawFieldTypesOnce(
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
            ['get_quota_info', '{"inode_limit":0,"inodes_remain":0,"inodes_used":1035,'
                . '"megabyte_limit":0,"megabytes_remain":64.5,"megabytes_used":5.46,'
                . '"under_inode_limit":1,"under_megabyte_limit":0,"under_quota_overall":0}', [
                    '$' => ['object'], '$.inode_limit' => ['int'], '$.inodes_remain' => ['int'],
                    '$.inodes_used' => ['int'], '$.megabyte_limit' => ['int'], '$.megabytes_remain' => ['float'],
                    '$.megabytes_used' => ['float'], '$.under_inode_limit' => ['int'],
                    '$.under_megabyte_limit' => ['int'], '$.under_quota_overall' => ['int'],
                ]],
            ['get_quota_info', '{}', [
                '$' => ['empty-object'], '$.inode_limit' => ['missing'], '$.inodes_remain' => ['missing'],
                '$.inodes_used' => ['missing'], '$.megabyte_limit' => ['missing'],
                '$.megabytes_remain' => ['missing'], '$.megabytes_used' => ['missing'],
                '$.under_inode_limit' => ['missing'], '$.under_megabyte_limit' => ['missing'],
                '$.under_quota_overall' => ['missing'],
            ]],
            ['get_local_quota_info', '{"byte_limit":0,"bytes_used":4149284,"inode_limit":0,"inodes_used":1035}', [
                '$' => ['object'], '$.byte_limit' => ['int'], '$.bytes_used' => ['int'],
                '$.inode_limit' => ['int'], '$.inodes_used' => ['int'],
            ]],
            ['get_local_quota_info', '{}', [
                '$' => ['empty-object'], '$.byte_limit' => ['missing'], '$.bytes_used' => ['missing'],
                '$.inode_limit' => ['missing'], '$.inodes_used' => ['missing'],
            ]],
        ];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach ($cases as $index => [$operation, $payload, $paths]) {
                yield $route . ' ' . $operation . ' ' . $index => [$operation, $route, $payload, $paths];
            }
        }
    }

    #[DataProvider('operationsAndRoutes')]
    public function testUnknownQuotaKeysAreWildcardedBeforeGeneratedFieldRemoval(string $operation, string $route): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"inodes_used":1035,"private-account":{"private.example.test":{"inodes_used":"private usage"}}}',
        )));

        $records = self::capture($http, self::probe($operation, $route));

        self::assertRequest($http, $operation, $route);
        self::assertSame('passed', $records[0]['payload_validation']);
        self::assertSame('passed', $records[0]['probe_outcome']);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $records[0]['discrepancies']);
        self::assertSame(['int'], $records[0]['payload_paths']['$.inodes_used']);
        self::assertSame(['object'], $records[0]['payload_paths']['$.*']);
        self::assertSame(['string'], $records[0]['payload_paths']['$.*.*.*']);
        self::assertPrivateValuesAbsent($records);
    }

    #[DataProvider('operationsAndRoutes')]
    public function testApiFailureKeepsStructureWithoutPrivateErrorText(string $operation, string $route): void
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
    public function testAcceptedOutcomeKeepsRawMismatchEvidence(
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
            ['get_quota_info', '{"under_quota_overall":2}', '$.under_quota_overall', 'int'],
            ['get_quota_info', '{"megabytes_used":"private usage"}', '$.megabytes_used', 'string'],
            ['get_quota_info', '{"inode_limit":null}', '$.inode_limit', 'null'],
            ['get_local_quota_info', '{"bytes_used":"private usage"}', '$.bytes_used', 'string'],
            ['get_local_quota_info', '{"byte_limit":null}', '$.byte_limit', 'null'],
        ];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach ($cases as $index => [$operation, $payload, $path, $type]) {
                yield $route . ' ' . $operation . ' ' . $index => [$operation, $route, $payload, $path, $type];
            }
        }
    }

    #[DataProvider('operationsAndRoutes')]
    public function testQuotaGuardRejectsUnreviewedMethodBodyAccountOrUri(string $operation, string $route): void
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
            new Request('GET', $uri . ($route === 'direct-uapi' ? '?' : '&') . 'private=secret'),
        ];
        foreach ($requests as $request) {
            try {
                $guard->sendRequest($request);
                self::fail('A request outside the exact Quota recipe must be rejected.');
            } catch (LogicException $exception) {
                self::assertStringNotContainsString('private', $exception->getMessage());
            }
        }
        self::assertCount(0, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationsAndRoutes(): iterable
    {
        yield 'total direct' => ['get_quota_info', 'direct-uapi'];
        yield 'total bridge' => ['get_quota_info', 'bridged-uapi'];
        yield 'local direct' => ['get_local_quota_info', 'direct-uapi'];
        yield 'local bridge' => ['get_local_quota_info', 'bridged-uapi'];
    }

    private static function probe(string $operation, string $route): Probe
    {
        $probes = array_values(array_filter(
            ProbeCatalog::load(dirname(__DIR__, 3)),
            static fn (Probe $probe): bool =>
                $probe->operation === 'uapi:Quota/' . $operation && $probe->route === $route,
        ));
        self::assertCount(1, $probes, 'Exactly one reviewed Quota probe must bind this operation and route.');

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
        return $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/Quota/' . $operation
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Quota&cpanel.function=' . $operation;
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
            'private usage', 'private error text', 'private warning text', 'private@example.test',
            'private metadata', '4149284', '1035', '64.5', '5.46',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }
}
