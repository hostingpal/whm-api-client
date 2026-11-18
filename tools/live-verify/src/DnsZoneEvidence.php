<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\DnsSpec;

/**
 * Assess the thirty captured probes independently of fixture setup, readback expectations and cleanup.
 * Require populated raw structures for successful reads and writes and a null payload for the bridge refusal.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-type Observation Record
 * @phpstan-type Assessment array{status: 'passed'|'failed', policy: string, findings: list<string>}
 */
final class DnsZoneEvidence
{
    /**
     * @param list<Observation> $probes
     * @param Context $context
     * @return Assessment
     */
    public static function assess(array $probes, array $context, ?string $version, string $bridgeOutcome): array
    {
        $findings = [];
        if ($version === null || preg_match('/\A\d+(?:\.\d+){1,4}\z/', $version) !== 1) {
            $findings[] = 'unusable_server_version';
        }
        if (!in_array($bridgeOutcome, ['known_failure', 'success'], true)) {
            $findings[] = 'unconfirmed_bridge_outcome';
        }
        $ordered = [];
        foreach ($probes as $probe) {
            $phase = $probe['argument_variant'] ?? '';
            if (!in_array($phase, DnsZoneGuard::EXERCISE_PHASES, true)) {
                $findings[] = 'unexpected_phase';
                continue;
            }
            if (in_array($phase, $ordered, true)) {
                $findings[] = 'duplicate_phase:' . $phase;
            }
            $ordered[] = $phase;
            $spec = match (true) {
                str_ends_with($phase, '-swap') => DnsSpec::SWAP_IP_IN_ZONES,
                str_ends_with($phase, '-add'), str_ends_with($phase, '-edit'), str_ends_with($phase, '-remove'),
                    $phase === 'direct-seed-main', $phase === 'direct-seed-alias' => DnsSpec::MASS_EDIT_ZONE,
                default => DnsSpec::PARSE_ZONE,
            };
            $operation = 'uapi:DNS/' . $spec['function'];
            $route = str_starts_with($phase, 'direct-') ? 'direct-uapi' : 'bridged-uapi';
            if (
                $probe['operation'] !== $operation || $probe['variant'] !== $operation . '#GET'
                || $probe['route'] !== $route || $probe['method'] !== $spec['method']
                || $probe['effective_contract_sha256'] !== hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR))
            ) {
                $findings[] = 'contract_mismatch:' . $phase;
            }
            if ($probe['server_version'] !== $version || !self::provenance($probe, $context)) {
                $findings[] = 'invalid_provenance:' . $phase;
            }
            $knownFailure = $phase === 'bridged-swap' && $bridgeOutcome === 'known_failure';
            if (
                $probe['http_status'] !== 200 || ($probe['response_paths']['$'] ?? null) !== ['object']
                || !self::payloadMatches($spec['function'], $probe['payload_paths'], $knownFailure)
                || $probe['api_outcome'] !== ($knownFailure ? 'failure' : 'success')
                || $probe['probe_outcome'] !== ($knownFailure ? 'failed' : 'passed')
                || $probe['payload_validation'] !== ($knownFailure ? 'not_checked' : 'passed')
                || $probe['failure_kind'] !== ($knownFailure ? 'api_failure' : null)
            ) {
                $findings[] = 'unexpected_outcome:' . $phase;
            }
            if ($probe['discrepancies'] !== []) {
                $findings[] = 'unreviewed_discrepancy:' . $phase;
            }
        }
        foreach (array_diff(DnsZoneGuard::EXERCISE_PHASES, $ordered) as $phase) {
            $findings[] = 'missing_phase:' . $phase;
        }
        if ($ordered !== DnsZoneGuard::EXERCISE_PHASES) {
            $findings[] = 'phase_order_mismatch';
        }

        return ['status' => $findings === [] ? 'passed' : 'failed', 'policy' => 'uapi-dns-two-zone-v2',
            'findings' => array_values(array_unique($findings))];
    }

    /** @param array<string, list<string>> $paths */
    private static function payloadMatches(string $function, array $paths, bool $knownFailure): bool
    {
        if ($knownFailure) {
            return $paths === ['$' => ['null']];
        }
        if ($function === 'mass_edit_zone') {
            return ($paths['$'] ?? null) === ['object']
                && in_array($paths['$.new_serial'] ?? [], [['int'], ['numeric-string']], true);
        }
        if (($paths['$'] ?? null) !== ['list'] || ($paths['$[]'] ?? null) !== ['object']) {
            return false;
        }
        $required = $function === 'swap_ip_in_zones'
            ? ['new_value' => 'string', 'old_value' => 'string', 'record_name' => 'string',
                'record_type' => 'string', 'zone_name' => 'string']
            : ['line_index' => 'int', 'type' => 'string', 'record_type' => 'string', 'ttl' => 'int',
                'dname_b64' => 'string', 'data_b64' => 'list', 'data_b64[]' => 'string'];
        foreach ($required as $field => $type) {
            $seen = $paths['$[].' . $field] ?? [];
            if (!in_array($type, $seen, true) || array_diff($seen, [$type, 'missing']) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Record $record
     * @param Context $context
     */
    private static function provenance(array $record, array $context): bool
    {
        foreach (['revision' => 40, 'generator_tree' => 40, 'map_sha256' => 64, 'overlay_sha256' => 64] as $key => $n) {
            if ($record[$key] !== $context[$key] || preg_match('/\A[a-f0-9]{' . $n . '}\z/', $record[$key]) !== 1) {
                return false;
            }
        }

        return $record['source_sha256'] === $context['source_hashes']['uapi']
            && preg_match('/\A[a-f0-9]{64}\z/', $record['source_sha256']) === 1;
    }
}
