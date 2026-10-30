<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client as ApiClient;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use Http\Mock\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Context from EvidenceRunner */
final class ReadOnlySectionRunnerTest extends TestCase
{
    public function testCapturesOnlySelectedOriginalResponsesAndKeepsStrictUnknownFailures(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(
            200,
            [],
            '{"status":1,"data":{"primary_domain":"private-value","private-key":"secret-value"}}',
        ));
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            self::probes(),
            'synthetic-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );
        self::assertSame('failed', $report['status']);
        self::assertCount(2, $http->getRequests());
        self::assertCount(2, $report['probes']);
        self::assertSame('passed', $report['probes'][1]['payload_validation']);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $report['probes'][1]['discrepancies']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        $privateValues = ['private-value', 'private-key', 'secret-value', 'private-whm-token', 'private-cpanel-token'];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    #[DataProvider('recordChanges')]
    public function testAssessmentRejectsIncompleteFailedOrMisattributedEvidence(?string $field, mixed $value): void
    {
        $probes = self::probes();
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"primary_domain":"example.com"}}'));
        $context = self::context();
        $report = (new ReadOnlySectionRunner($http, $context))->run(
            $probes,
            'synthetic-v1',
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );
        self::assertSame('passed', $report['status']);
        $records = $report['probes'];
        if ($field === null) {
            array_pop($records);
        } else {
            $records[1][$field] = $value;
        }
        // Deliberately malformed evidence verifies the strict public assessment boundary.
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, $context));
    }

    /** @return iterable<string, array{?string, mixed}> */
    public static function recordChanges(): iterable
    {
        yield 'missing' => [null, null];
        foreach (
            ['operation', 'variant', 'route', 'method', 'revision', 'source_sha256', 'map_sha256',
            'generator_tree', 'overlay_sha256', 'effective_contract_sha256', 'server_version',
            'api_outcome', 'payload_validation', 'probe_outcome'] as $field
        ) {
            yield $field => [$field, 'incorrect'];
        }
        yield 'http status' => ['http_status', 500];
        yield 'failure' => ['failure_kind', 'request_failure'];
        yield 'unknown field' => ['discrepancies', [['path' => '$.*', 'kind' => 'unknown_field']]];
    }

    public function testEmptyAndDuplicateCatalogsCannotPass(): void
    {
        self::assertFalse(ReadOnlySectionRunner::passes([], [], self::context()));
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"primary_domain":"example.com"}}'));
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            self::probes(),
            'synthetic-v1',
            '1',
            ['whm' => 'test-whm', 'cpanel' => 'test-cpanel'],
        );
        self::assertFalse(ReadOnlySectionRunner::passes(
            [self::probes()[0], self::probes()[0]],
            [$report['probes'][0], $report['probes'][0]],
            self::context(),
        ));
    }

    /** @return list<Probe> */
    private static function probes(): array
    {
        return [
            new Probe(
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (ApiClient $api): mixed => $api->whm()->server()->version(),
            ),
            new Probe(
                'uapi:DomainInfo/primary_domain',
                'direct-uapi',
                DomainInfoSpec::PRIMARY_DOMAIN,
                'https://test.oblak.host:2083/execute/DomainInfo/primary_domain',
                static fn (ApiClient $api): mixed => $api->uapi()->domainInfo()->primaryDomain(),
            ),
        ];
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => str_repeat('a', 40), 'source_hashes' => ['whm1' => str_repeat('b', 64),
            'uapi' => str_repeat('c', 64)], 'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40), 'overlay_sha256' => str_repeat('f', 64)];
    }
}
