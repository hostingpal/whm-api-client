<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\DnsSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\DnsZoneEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Record from EvidenceRunner */
final class DnsZoneEvidenceTest extends TestCase
{
    public function testExpectedFailureAndSuccessfulBridgeAreDistinctValidRuns(): void
    {
        foreach (['known_failure', 'success'] as $bridge) {
            $records = self::records($bridge);
            $original = $records;
            $assessment = DnsZoneEvidence::assess($records, DomainRowsEvidenceTest::context(), '11.138.0.24', $bridge);
            self::assertSame('passed', $assessment['status']);
            self::assertSame([], $assessment['findings']);
            self::assertSame($original, $records);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        foreach (
            [
            'missing', 'duplicate', 'reordered', 'unknown', 'no-label', 'route', 'operation', 'variant', 'method',
            'source', 'revision', 'overlay', 'map', 'tree', 'context', 'contract', 'version', 'invalid-version',
            'http', 'outcome', 'validation', 'discrepancy', 'empty-structure', 'bridge-outcome',
            'wrong-bridge-failure', 'failure-elsewhere', 'successful-bridge-reported-as-defect',
            ] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalid')]
    public function testRejectsIncompleteOrMismatchedRuns(string $case): void
    {
        $records = self::records('known_failure');
        self::assertArrayHasKey(0, $records);
        self::assertArrayHasKey(1, $records);
        self::assertArrayHasKey(22, $records);
        $bridge = 'known_failure';
        $version = '11.138.0.24';
        $context = DomainRowsEvidenceTest::context();
        switch ($case) {
            case 'missing':
                array_pop($records);
                break;
            case 'duplicate':
                $records[] = $records[1];
                break;
            case 'reordered':
                [$records[0], $records[1]] = [$records[1], $records[0]];
                break;
            case 'context':
                $context['revision'] = 'invalid';
                foreach ($records as &$record) {
                    $record['revision'] = 'invalid';
                }
                unset($record);
                break;
            case 'invalid-version':
                $version = null;
                break;
            case 'bridge-outcome':
                $bridge = 'unexpected';
                break;
            case 'wrong-bridge-failure':
                $records[22] = [...$records[22], 'failure_kind' => 'payload_mismatch'];
                break;
            case 'successful-bridge-reported-as-defect':
                $records = self::records('success');
                break;
            default:
                $records[1] = self::corrupt($records[1], $case);
        }

        $before = $records;
        $assessment = DnsZoneEvidence::assess($records, $context, $version, $bridge);
        self::assertSame('failed', $assessment['status']);
        self::assertNotEmpty($assessment['findings']);
        self::assertStringNotContainsString('private unexpected value', implode(' ', $assessment['findings']));
        self::assertSame($before, $records);
    }

    /**
     * @param Record $record
     * @return Record
     */
    private static function corrupt(array $record, string $case): array
    {
        switch ($case) {
            case 'unknown':
                $record['argument_variant'] = 'private unexpected value';
                break;
            case 'no-label':
                unset($record['argument_variant']);
                break;
            case 'route':
                $record['route'] = 'bridged-uapi';
                break;
            case 'operation':
                $record['operation'] = 'uapi:DNS/parse_zone';
                break;
            case 'variant':
                $record['variant'] = 'uapi:DNS/mass_edit_zone#POST';
                break;
            case 'method':
                $record['method'] = 'GET';
                break;
            case 'source':
                $record['source_sha256'] = DomainRowsEvidenceTest::context()['source_hashes']['whm1'];
                break;
            case 'revision':
                $record['revision'] = str_repeat('0', 40);
                break;
            case 'overlay':
                $record['overlay_sha256'] = str_repeat('0', 64);
                break;
            case 'map':
                $record['map_sha256'] = str_repeat('0', 64);
                break;
            case 'tree':
                $record['generator_tree'] = str_repeat('0', 40);
                break;
            case 'contract':
                $record['effective_contract_sha256'] = str_repeat('0', 64);
                break;
            case 'version':
                $record['server_version'] = '11.136.0.1';
                break;
            case 'http':
                $record['http_status'] = 503;
                break;
            case 'outcome':
                $record['api_outcome'] = 'failure';
                break;
            case 'validation':
                $record['payload_validation'] = 'not_checked';
                break;
            case 'discrepancy':
                $record['discrepancies'][] = ['path' => '$.*', 'kind' => 'unknown_field'];
                break;
            case 'empty-structure':
                $record['response_paths'] = [];
                break;
            case 'failure-elsewhere':
                $record = [...$record, 'failure_kind' => 'api_failure',
                    'api_outcome' => 'failure', 'probe_outcome' => 'failed', 'payload_validation' => 'not_checked'];
                break;
        }

        return $record;
    }

    public function testSuccessfulPhasesRequirePopulatedRawPayloads(): void
    {
        foreach (['direct-mass-before', 'direct-mass-add', 'direct-swap'] as $phase) {
            $records = [];
            foreach (self::records('known_failure') as $record) {
                if (($record['argument_variant'] ?? null) === $phase) {
                    $record['payload_paths'] = ['$' => ['null']];
                }
                $records[] = $record;
            }
            $result = DnsZoneEvidence::assess(
                $records,
                DomainRowsEvidenceTest::context(),
                '11.138.0.24',
                'known_failure',
            );
            self::assertSame('failed', $result['status']);
            self::assertContains('unexpected_outcome:' . $phase, $result['findings']);
        }
    }

    /** @return list<Record> */
    private static function records(string $bridge): array
    {
        $phases = [];
        foreach (['direct', 'bridged'] as $route) {
            foreach (['before', 'add', 'added', 'edit', 'edited', 'remove', 'removed'] as $phase) {
                $phases[] = [$route . '-mass-' . $phase, in_array($phase, ['add', 'edit', 'remove'], true)
                    ? DnsSpec::MASS_EDIT_ZONE : DnsSpec::PARSE_ZONE, $route];
            }
        }
        foreach (['main', 'alias'] as $zone) {
            $phase = 'direct-seed-' . $zone;
            $phases[] = [$phase . '-before', DnsSpec::PARSE_ZONE, 'direct'];
            $phases[] = [$phase, DnsSpec::MASS_EDIT_ZONE, 'direct'];
            $phases[] = [$phase . '-after', DnsSpec::PARSE_ZONE, 'direct'];
        }
        foreach (['bridged', 'direct'] as $route) {
            $prefix = $route . '-swap';
            foreach (['-main-before', '-alias-before', '', '-main-after', '-alias-after'] as $suffix) {
                $phases[] = [$prefix . $suffix, $suffix === '' ? DnsSpec::SWAP_IP_IN_ZONES : DnsSpec::PARSE_ZONE,
                    $route];
            }
        }

        $context = DomainRowsEvidenceTest::context();
        $records = [];
        foreach ($phases as [$phase, $spec, $route]) {
            $defect = $bridge === 'known_failure' && $phase === 'bridged-swap';
            $operation = 'uapi:DNS/' . $spec['function'];
            $records[] = [
                'argument_variant' => $phase, 'operation' => $operation, 'variant' => $operation . '#GET',
                'route' => $route . '-uapi', 'method' => $spec['method'], 'revision' => $context['revision'],
                'source_sha256' => $context['source_hashes']['uapi'], 'map_sha256' => $context['map_sha256'],
                'generator_tree' => $context['generator_tree'], 'overlay_sha256' => $context['overlay_sha256'],
                'effective_contract_sha256' => hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)),
                'server_version' => '11.138.0.24', 'observed_at' => '2026-11-18T00:00:00Z', 'http_status' => 200,
                'probe_outcome' => $defect ? 'failed' : 'passed', 'api_outcome' => $defect ? 'failure' : 'success',
                'payload_validation' => $defect ? 'not_checked' : 'passed',
                'failure_kind' => $defect ? 'api_failure' : null, 'discrepancies' => [],
                'response_paths' => ['$' => ['object']], 'payload_paths' => match (true) {
                    $defect => ['$' => ['null']],
                    $operation === 'uapi:DNS/mass_edit_zone' => [
                        '$' => ['object'], '$.new_serial' => ['numeric-string'],
                    ],
                    $operation === 'uapi:DNS/swap_ip_in_zones' => ['$' => ['list'], '$[]' => ['object'],
                        '$[].new_value' => ['string'], '$[].old_value' => ['string'], '$[].record_name' => ['string'],
                        '$[].record_type' => ['string'], '$[].zone_name' => ['string']],
                    default => ['$' => ['list'], '$[]' => ['object'], '$[].line_index' => ['int'],
                        '$[].record_type' => ['missing', 'string'], '$[].type' => ['string'],
                        '$[].dname_b64' => ['string'], '$[].ttl' => ['int'], '$[].data_b64' => ['list'],
                        '$[].data_b64[]' => ['string']],
                },
            ];
        }

        return $records;
    }
}
