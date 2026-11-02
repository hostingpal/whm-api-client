<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\AddonDomainSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DnsSpec as UapiDnsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ParkSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SubDomainSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\AccountsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\DnsSpec as WhmDnsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use LogicException;

/**
 * Strict phase, provenance and discrepancy gate for one domain-rows run; no acknowledgment mechanism exists.
 *
 * Original records are never altered. Every phase must appear once, in order, with its reviewed contract and route, a
 * passed expectation and no discrepancy, so an undeclared key on any populated row fails the run. Every phase is a
 * typed call and must report passed payload validation; no phase has a refusal as its expected outcome. The contracts
 * here are written out independently of the recipe's probes, which the tests compare.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from DomainRowsFixture
 * @phpstan-type Assessment array{
 *     policy: 'domain-rows-no-acknowledgments-v1', status: 'passed'|'failed', findings: list<string>
 * }
 */
final class DomainRowsEvidence
{
    /**
     * The reviewed contract of one phase; every operation here is pinned as GET, and the mutations are sent as POST.
     *
     * @return array{operation: string, variant: string, route: string, method: string, spec: array<mixed>}
     */
    public static function contract(string $phase): array
    {
        if (!in_array($phase, DomainRowsRecipe::PHASES, true)) {
            throw new LogicException('Unknown domain rows phase.');
        }
        $route = match (true) {
            in_array($phase, ['version', 'limits'], true), str_starts_with($phase, 'whm_') => 'whm',
            str_starts_with($phase, 'bridged_') => 'bridged-uapi',
            default => 'direct-uapi',
        };
        [$operation, $spec] = match (true) {
            $phase === 'version' => ['whm1:version', ServerSpec::VERSION],
            $phase === 'limits' => ['whm1:modifyacct', AccountsSpec::MODIFYACCT],
            $phase === 'create_addon' => ['uapi:AddonDomain/addaddondomain', AddonDomainSpec::ADDADDONDOMAIN],
            $phase === 'park_alias' => ['uapi:Park/park', ParkSpec::PARK],
            $phase === 'create_subdomain' => ['uapi:SubDomain/addsubdomain', SubDomainSpec::ADDSUBDOMAIN],
            str_contains($phase, '_authority_') => $route === 'whm'
                ? ['whm1:has_local_authority', WhmDnsSpec::HAS_LOCAL_AUTHORITY]
                : ['uapi:DNS/has_local_authority', UapiDnsSpec::HAS_LOCAL_AUTHORITY],
            str_contains($phase, '_reside_') => [
                'uapi:DNS/ensure_domains_reside_only_locally', UapiDnsSpec::ENSURE_DOMAINS_RESIDE_ONLY_LOCALLY,
            ],
            str_contains($phase, '_nameservers_') => [
                'uapi:DNS/ensure_domains_use_recognized_nameservers',
                UapiDnsSpec::ENSURE_DOMAINS_USE_RECOGNIZED_NAMESERVERS,
            ],
            str_contains($phase, '_ds_') => [
                'whm1:fetch_ds_records_for_domains', WhmDnsSpec::FETCH_DS_RECORDS_FOR_DOMAINS,
            ],
            str_ends_with($phase, '_list_domains') => ['uapi:DomainInfo/list_domains', DomainInfoSpec::LIST_DOMAINS],
            str_contains($phase, '_domains_') => ['uapi:DomainInfo/domains_data', DomainInfoSpec::DOMAINS_DATA],
            default => ['uapi:DomainInfo/single_domain_data', DomainInfoSpec::SINGLE_DOMAIN_DATA],
        };

        return ['operation' => $operation, 'variant' => $operation . '#GET', 'route' => $route,
            'method' => $spec['method'], 'spec' => $spec];
    }

    /**
     * Require the complete ordered recipe from one server version, retaining every original discrepancy.
     *
     * An empty or partial run, including a setup failure before capture, fails with its missing phases.
     *
     * @param list<Observation> $records
     * @param Context $context
     * @return Assessment
     */
    public static function assess(
        array $records,
        array $context,
    ): array {
        $versions = array_values(array_filter($records, static fn (array $record): bool =>
            $record['phase'] === 'version' && self::usableVersion($record['server_version'])));
        $version = count($versions) === 1 ? $versions[0]['server_version'] : null;
        $findings = $version === null ? ['unusable_server_version'] : [];
        $seen = [];
        foreach ($records as $record) {
            $phase = $record['phase'];
            if (isset($seen[$phase])) {
                $findings[] = 'duplicate_phase:' . $phase;
            }
            $seen[$phase] = true;
            if (!in_array($phase, DomainRowsRecipe::PHASES, true)) {
                $findings[] = 'unexpected_phase';
                continue;
            }
            $contract = self::contract($phase);
            if (
                $record['operation'] !== $contract['operation'] || $record['variant'] !== $contract['variant']
                || $record['route'] !== $contract['route'] || $record['method'] !== $contract['method']
                || $record['effective_contract_sha256']
                    !== hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR))
            ) {
                $findings[] = 'contract_mismatch:' . $phase;
            }
            if (!self::successful($record)) {
                $findings[] = 'unsuccessful_phase:' . $phase;
            }
            if ($record['discrepancies'] !== []) {
                $findings[] = 'unacknowledged_discrepancy:' . $phase;
            }
            if ($record['server_version'] !== $version) {
                $findings[] = 'unusable_server_version';
            }
            $status = $contract['route'] === 'whm' ? null : 1;
            if ($record['uapi_status'] !== $status) {
                $findings[] = 'unexpected_uapi_status:' . $phase;
            }
            if (
                $record['revision'] !== $context['revision'] || $record['map_sha256'] !== $context['map_sha256']
                || $record['generator_tree'] !== $context['generator_tree']
                || $record['overlay_sha256'] !== $context['overlay_sha256']
                || $record['source_sha256']
                    !== $context['source_hashes'][$contract['route'] === 'whm' ? 'whm1' : 'uapi']
            ) {
                $findings[] = 'invalid_provenance:' . $phase;
            }
        }
        foreach (DomainRowsRecipe::PHASES as $phase) {
            if (!isset($seen[$phase])) {
                $findings[] = 'missing_phase:' . $phase;
            }
        }
        if (
            array_column($records, 'phase') !== DomainRowsRecipe::PHASES
            && array_diff(DomainRowsRecipe::PHASES, array_keys($seen)) === []
        ) {
            $findings[] = 'phase_order_mismatch';
        }
        $findings = array_values(array_unique($findings));

        return [
            'policy' => 'domain-rows-no-acknowledgments-v1',
            'status' => $findings === [] ? 'passed' : 'failed',
            'findings' => $findings,
        ];
    }

    /** @param Observation $record */
    private static function successful(array $record): bool
    {
        return $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
            && $record['payload_validation'] === 'passed' && $record['expectation'] === 'passed'
            && $record['failure_kind'] === null && self::httpSuccess($record);
    }

    /** @param Observation $record */
    private static function httpSuccess(array $record): bool
    {
        return $record['http_status'] !== null && $record['http_status'] >= 200 && $record['http_status'] < 300;
    }

    private static function usableVersion(?string $version): bool
    {
        return $version !== null && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) === 1;
    }
}
