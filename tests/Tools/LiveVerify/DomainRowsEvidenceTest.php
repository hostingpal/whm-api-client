<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\DnsSpec as WhmDnsSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsRecipe;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** @phpstan-import-type Observation from DomainRowsFixture */
final class DomainRowsEvidenceTest extends TestCase
{
    public function testACompleteOrderedRunPassesWithNoAcknowledgmentMechanism(): void
    {
        $assessment = self::assess(self::records());
        self::assertSame('passed', $assessment['status']);
        self::assertSame('domain-rows-no-acknowledgments-v1', $assessment['policy']);
        self::assertSame([], $assessment['findings']);
    }

    public function testAnEmptyRunFailsWithEveryPhaseMissing(): void
    {
        $assessment = self::assess([]);
        self::assertSame('failed', $assessment['status']);
        self::assertContains('unusable_server_version', $assessment['findings']);
        foreach (DomainRowsRecipe::PHASES as $phase) {
            self::assertContains('missing_phase:' . $phase, $assessment['findings']);
        }
    }

    /** An undeclared key on a populated row is exactly what this run exists to surface; it can never pass. */
    public function testAnUnknownFieldCannotPassEvenWhenEveryOutcomePassed(): void
    {
        $records = self::changed('direct_domains_list', static function (array $record): array {
            $record['discrepancies'] = [['path' => '$[].*', 'kind' => 'unknown_field']];

            return $record;
        });
        self::assertSame(['unacknowledged_discrepancy:direct_domains_list'], self::assess($records)['findings']);
    }

    public function testFailedExpectationFailsTheRecordedPhase(): void
    {
        $records = self::changed('whm_authority_reverse', static function (array $record): array {
            $record['expectation'] = 'failed';

            return $record;
        });
        self::assertSame(['unsuccessful_phase:whm_authority_reverse'], self::assess($records)['findings']);
    }

    public function testEveryPhaseMustHaveValidatedItsPayload(): void
    {
        foreach (['limits', 'create_addon', 'bridged_reside_forward', 'direct_single_sub'] as $phase) {
            $records = self::changed($phase, static function (array $record): array {
                $record['payload_validation'] = 'not_checked';

                return $record;
            });
            self::assertSame(['unsuccessful_phase:' . $phase], self::assess($records)['findings'], $phase);
        }
    }

    /** No refusal is an expected outcome anywhere, so an API refusal fails its phase on every read. */
    public function testAnApiRefusalNeverPasses(): void
    {
        foreach (['direct_single_alias', 'bridged_single_alias', 'whm_ds_forward'] as $phase) {
            $records = self::changed($phase, self::refused(...));
            $findings = self::assess($records)['findings'];
            self::assertContains('unsuccessful_phase:' . $phase, $findings, $phase);
        }
        self::assertSame(2, (new ReflectionMethod(DomainRowsEvidence::class, 'assess'))->getNumberOfParameters());
    }

    public function testContractsBindEveryPhaseToItsReviewedOperationAndRoute(): void
    {
        $contract = DomainRowsEvidence::contract('whm_ds_reverse');
        self::assertSame('whm1:fetch_ds_records_for_domains#GET', $contract['variant']);
        self::assertSame('whm', $contract['route']);
        self::assertSame(WhmDnsSpec::FETCH_DS_RECORDS_FOR_DOMAINS, $contract['spec']);
        $contract = DomainRowsEvidence::contract('bridged_domains_hash');
        self::assertSame('uapi:DomainInfo/domains_data#GET', $contract['variant']);
        self::assertSame('bridged-uapi', $contract['route']);
        self::assertSame(DomainInfoSpec::DOMAINS_DATA, $contract['spec']);
        self::assertSame('POST', DomainRowsEvidence::contract('create_addon')['method']);
        $addon = DomainRowsEvidence::contract('create_addon');
        self::assertSame('uapi:AddonDomain/addaddondomain#GET', $addon['variant']);
        self::assertSame('whm1:modifyacct#GET', DomainRowsEvidence::contract('limits')['variant']);
        foreach (DomainRowsRecipe::PHASES as $phase) {
            $probe = DomainRowsRecipe::probe($phase);
            $contract = DomainRowsEvidence::contract($phase);
            self::assertSame($probe->operation, $contract['operation'], $phase);
            self::assertSame($probe->route, $contract['route'], $phase);
            self::assertSame($probe->spec, $contract['spec'], $phase);
        }
    }

    public function testRouteAndContractMismatchesAreFindings(): void
    {
        $records = self::changed('direct_list_domains', static function (array $record): array {
            $record['route'] = 'bridged-uapi';

            return $record;
        });
        self::assertContains('contract_mismatch:direct_list_domains', self::assess($records)['findings']);
        $records = self::changed('direct_single_main', static function (array $record): array {
            $record['effective_contract_sha256'] = str_repeat('0', 64);

            return $record;
        });
        self::assertSame(['contract_mismatch:direct_single_main'], self::assess($records)['findings']);
    }

    public function testDuplicateReorderedAndUnexpectedPhasesFail(): void
    {
        $records = self::records();
        [$records[6], $records[7]] = [$records[7], $records[6]];
        self::assertSame(['phase_order_mismatch'], self::assess($records)['findings']);

        $records = self::records();
        $records[] = $records[10];
        self::assertContains('duplicate_phase:' . $records[10]['phase'], self::assess($records)['findings']);

        $records = self::changed('limits', static function (array $record): array {
            $record['phase'] = 'export_zone_files';

            return $record;
        });
        self::assertContains('unexpected_phase', self::assess($records)['findings']);
    }

    public function testProvenanceAndVersionMustMatchTheRun(): void
    {
        $records = self::changed('bridged_single_sub', static function (array $record): array {
            $record['revision'] = str_repeat('9', 40);

            return $record;
        });
        self::assertSame(['invalid_provenance:bridged_single_sub'], self::assess($records)['findings']);
        $records = self::changed('whm_ds_forward', static function (array $record): array {
            $record['source_sha256'] = self::context()['source_hashes']['uapi'];

            return $record;
        });
        self::assertSame(['invalid_provenance:whm_ds_forward'], self::assess($records)['findings']);
        $records = self::changed('direct_reside_reverse', static function (array $record): array {
            $record['server_version'] = '11.138.0.20';

            return $record;
        });
        self::assertSame(['unusable_server_version'], self::assess($records)['findings']);
    }

    public function testUapiStatusIsRecordedForBothUapiRoutesAndAbsentForWhm(): void
    {
        $records = self::changed('bridged_authority_forward', static function (array $record): array {
            $record['uapi_status'] = null;

            return $record;
        });
        self::assertSame(['unexpected_uapi_status:bridged_authority_forward'], self::assess($records)['findings']);
        $records = self::changed('whm_authority_forward', static function (array $record): array {
            $record['uapi_status'] = 1;

            return $record;
        });
        self::assertSame(['unexpected_uapi_status:whm_authority_forward'], self::assess($records)['findings']);
    }

    /**
     * @param list<Observation> $records
     * @return array{policy: string, status: string, findings: list<string>}
     */
    private static function assess(array $records): array
    {
        return DomainRowsEvidence::assess($records, self::context());
    }

    /**
     * @param Observation $record
     * @return Observation
     */
    private static function refused(array $record): array
    {
        return [...$record, 'uapi_status' => 0, 'api_outcome' => 'failure', 'payload_validation' => 'not_checked',
            'probe_outcome' => 'failed', 'failure_kind' => 'api_failure', 'payload_paths' => []];
    }

    /**
     * @param Closure(Observation): Observation $change
     * @return list<Observation>
     */
    private static function changed(string $phase, Closure $change): array
    {
        return array_map(
            static fn (array $record): array => $record['phase'] === $phase ? $change($record) : $record,
            self::records(),
        );
    }

    /** @return list<Observation> */
    private static function records(): array
    {
        $context = self::context();
        $records = [];
        foreach (DomainRowsRecipe::PHASES as $phase) {
            $contract = DomainRowsEvidence::contract($phase);
            $whm = $contract['route'] === 'whm';
            $records[] = [
                'phase' => $phase, 'expectation' => 'passed', 'uapi_status' => $whm ? null : 1,
                'operation' => $contract['operation'], 'variant' => $contract['variant'],
                'route' => $contract['route'], 'method' => $contract['method'],
                'server_version' => '11.138.0.19', 'observed_at' => '2026-11-02T00:00:00Z',
                'revision' => $context['revision'],
                'source_sha256' => $context['source_hashes'][$whm ? 'whm1' : 'uapi'],
                'map_sha256' => $context['map_sha256'], 'generator_tree' => $context['generator_tree'],
                'overlay_sha256' => $context['overlay_sha256'],
                'effective_contract_sha256' => hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR)),
                'http_status' => 200, 'api_outcome' => 'success', 'payload_validation' => 'passed',
                'probe_outcome' => 'passed', 'failure_kind' => null, 'response_paths' => [], 'payload_paths' => [],
                'discrepancies' => [],
            ];
        }

        return $records;
    }

    /** @return array{revision: string, source_hashes: array{whm1: string, uapi: string}, map_sha256: string,
     *     generator_tree: string, overlay_sha256: string} */
    public static function context(): array
    {
        return [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
    }
}
