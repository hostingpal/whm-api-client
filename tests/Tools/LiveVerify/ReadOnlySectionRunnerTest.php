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
use LogicException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

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
        yield 'unexpected argument label' => ['argument_variant', 'injected'];
        yield 'unexpected null argument label' => ['argument_variant', null];
        yield 'failure' => ['failure_kind', 'request_failure'];
        yield 'unknown field' => ['discrepancies', [['path' => '$.*', 'kind' => 'unknown_field']]];
    }

    #[DataProvider('malformedRecords')]
    public function testAssessmentRejectsMalformedRecordsAndMissingStructuralEvidence(
        int $index,
        ?string $field,
        mixed $replacement,
        bool $remove,
    ): void {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"primary_domain":"example.com"}}'));
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            self::probes(),
            'synthetic-v1',
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );
        self::assertSame('passed', $report['status']);
        $records = $report['probes'];
        if ($field === null) {
            $records[$index] = $replacement;
        } elseif ($remove) {
            unset($records[$index][$field]);
        } else {
            $records[$index][$field] = $replacement;
        }
        self::assertFalse(ReadOnlySectionRunner::passes(self::probes(), $records, self::context()));
    }

    /** @return iterable<string, array{int, ?string, mixed, bool}> */
    public static function malformedRecords(): iterable
    {
        foreach ([0, 1] as $index) {
            yield "null record $index" => [$index, null, null, false];
            yield "scalar record $index" => [$index, null, 'not a record', false];
            yield "object record $index" => [$index, null, new stdClass(), false];
            foreach (['response_paths', 'payload_paths'] as $field) {
                yield "missing $field $index" => [$index, $field, null, true];
                yield "null $field $index" => [$index, $field, null, false];
                yield "scalar $field $index" => [$index, $field, 'not a map', false];
                yield "empty $field $index" => [$index, $field, [], false];
                yield "missing root $field $index" => [$index, $field, ['$.field' => ['string']], false];
                yield "empty types $field $index" => [$index, $field, ['$' => []], false];
                yield "scalar types $field $index" => [$index, $field, ['$' => 'object'], false];
                yield "non-string type $field $index" => [$index, $field, ['$' => [1]], false];
            }
        }
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

    public function testOutcomeRuleReplacesOnlyTheOutcomeChecks(): void
    {
        $probes = self::probes();
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(200, [], '{"status":0,"data":null,"errors":["synthetic failure"]}'));
        $context = self::context();
        $accept = static fn (Probe $probe, array $record): bool => $record['operation'] === $probe->operation;
        $report = (new ReadOnlySectionRunner($http, $context))->run(
            $probes,
            'synthetic-v1',
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
            $accept,
        );
        self::assertSame('passed', $report['status']);
        self::assertSame('api_failure', $report['probes'][1]['failure_kind']);
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $report['probes'], $context));
        self::assertFalse(ReadOnlySectionRunner::passes(
            $probes,
            $report['probes'],
            $context,
            static fn (Probe $probe, array $record): bool => false,
        ));
        $bound = ['variant', 'route', 'method', 'revision', 'source_sha256', 'map_sha256', 'generator_tree',
            'overlay_sha256', 'effective_contract_sha256', 'server_version'];
        foreach ($bound as $field) {
            $records = $report['probes'];
            $records[1][$field] = 'incorrect';
            self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, $context, $accept), $field);
        }
        $records = $report['probes'];
        $records[1]['http_status'] = 500;
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, $context, $accept));
        self::assertFalse(ReadOnlySectionRunner::passes($probes, [$report['probes'][0]], $context, $accept));
    }

    public function testArgumentVariantsCaptureDistinctRequestsAndRejectMisattribution(): void
    {
        $probes = [self::probes()[0]];
        foreach ([false, true] as $hidden) {
            $probes[] = new Probe(
                'uapi:DomainInfo/list_domains',
                'direct-uapi',
                DomainInfoSpec::LIST_DOMAINS,
                'https://test.oblak.host:2083/execute/DomainInfo/list_domains?hide_temporary_domains='
                    . ($hidden ? '1' : '0'),
                static fn (ApiClient $api): mixed => $api->uapi()->domainInfo()->listDomains($hidden),
                argumentVariant: $hidden ? 'hide-temporary' : 'include-temporary',
            );
        }
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"main_domain":"example.com"}}'));
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"main_domain":"example.com"}}'));
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            $probes,
            'argument-variants-v1',
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );
        self::assertSame('passed', $report['status']);
        self::assertCount(3, $http->getRequests());
        self::assertSame('hide_temporary_domains=0', $http->getRequests()[1]->getUri()->getQuery());
        self::assertSame('hide_temporary_domains=1', $http->getRequests()[2]->getUri()->getQuery());
        self::assertSame('include-temporary', $report['probes'][1]['argument_variant'] ?? null);
        self::assertSame('hide-temporary', $report['probes'][2]['argument_variant'] ?? null);
        self::assertSame('uapi:DomainInfo/list_domains#GET', $report['probes'][1]['variant']);
        self::assertArrayNotHasKey('argument_variant', $report['probes'][0]);
        foreach ([null, 'wrong-label', 'hide-temporary'] as $replacement) {
            $records = $report['probes'];
            if ($replacement === null) {
                unset($records[1]['argument_variant']);
            } else {
                $records[1]['argument_variant'] = $replacement;
            }
            self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, self::context()));
        }
        self::assertFalse(ReadOnlySectionRunner::passes(
            $probes,
            [$report['probes'][0], $report['probes'][2], $report['probes'][1]],
            self::context(),
        ));
        self::assertFalse(ReadOnlySectionRunner::passes(
            [$probes[0], $probes[1], $probes[1]],
            [$report['probes'][0], $report['probes'][1], $report['probes'][1]],
            self::context(),
        ));
        foreach ([1, 2] as $unlabeledIndex) {
            $mixed = $probes;
            $original = $mixed[$unlabeledIndex];
            $mixed[$unlabeledIndex] = new Probe(
                $original->operation,
                $original->route,
                $original->spec,
                $original->uri,
                $original->invoke,
            );
            $records = $report['probes'];
            unset($records[$unlabeledIndex]['argument_variant']);
            self::assertFalse(ReadOnlySectionRunner::passes($mixed, $records, self::context()));
        }
    }

    #[DataProvider('invalidArgumentVariants')]
    public function testArgumentVariantLabelsRejectUnsafeSyntax(string $label): void
    {
        $original = self::probes()[1];
        $this->expectException(LogicException::class);
        new Probe(
            $original->operation,
            $original->route,
            $original->spec,
            $original->uri,
            $original->invoke,
            argumentVariant: $label,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidArgumentVariants(): iterable
    {
        foreach (['', 'Upper', 'has space', 'domain=npr.rs', 'a|b', 'path/name', str_repeat('a', 65)] as $label) {
            yield $label => [$label];
        }
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
