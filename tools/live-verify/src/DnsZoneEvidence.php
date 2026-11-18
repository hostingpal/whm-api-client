<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\DnsSpec;
use SensitiveParameter;
use stdClass;

/**
 * Assess the thirty captured probes independently of fixture setup, readback expectations and cleanup.
 * Every discrepancy remains in its original capture. A reviewed readback field and the reproduced bridge
 * failure have distinct proofs; neither permits an unrelated write discrepancy or an unsuccessful read.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-type Observation array{
 *     operation: string, variant: string, argument_variant?: string, route: string, method: ?string,
 *     server_version: ?string, observed_at: string, revision: string, source_sha256: string, map_sha256: string,
 *     generator_tree: string, overlay_sha256: string, effective_contract_sha256: string, http_status: ?int,
 *     api_outcome: string, payload_validation: string, probe_outcome: string, failure_kind: ?string,
 *     response_paths: array<string, list<string>>, payload_paths: array<string, list<string>>,
 *     discrepancies: list<array{path: string, kind: string}>, payload_alternatives?: list<Alternative>,
 *     readback_review?: 'known_dname_raw_string'
 * }
 * @phpstan-type Assessment array{status: 'passed'|'failed', policy: string, findings: list<string>}
 */
final class DnsZoneEvidence
{
    private const PARSE_CONTRACT = '9d318e929dc7b2c9f5eb506a482e9af44e1e864548977432beded9f5ed63afd7';
    private const PARSE_SOURCE = '172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc';

    /**
     * Inspect the captured body before the next request overwrites it. The wildcard fingerprint alone cannot
     * identify unknown keys or their count. Every extra key on every row must be the reviewed string field
     * on a resource record. Only a fixed label leaves this scope.
     *
     * @param Observation $record
     * @return Observation
     */
    public static function reviewReadback(array $record, #[SensitiveParameter] ?string $raw): array
    {
        unset($record['readback_review']);
        if ($raw === null || !self::matchesReadback($record)) {
            return $record;
        }
        $document = json_decode($raw);
        if (!$document instanceof stdClass) {
            return $record;
        }
        if ($record['route'] === 'bridged-uapi') {
            $envelope = ($document->data ?? null) instanceof stdClass ? ($document->data->uapi ?? null) : null;
        } else {
            $envelope = property_exists($document, 'result') ? $document->result : $document;
        }
        if (!$envelope instanceof stdClass || !is_array($envelope->data ?? null)) {
            return $record;
        }
        $declared = DnsSpec::PARSE_ZONE['returns']['items']['fields'];
        $found = false;
        foreach ($envelope->data as $row) {
            if (!$row instanceof stdClass) {
                return $record;
            }
            foreach (array_diff_key(get_object_vars($row), $declared) as $name => $value) {
                if (
                    $name !== 'dname_raw' || !is_string($value) || ($row->type ?? null) !== 'record'
                    || !is_string($row->dname_b64 ?? null) || base64_decode($row->dname_b64, true) !== $value
                ) {
                    return $record;
                }
                $found = true;
            }
        }

        return $found ? [...$record, 'readback_review' => 'known_dname_raw_string'] : $record;
    }

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
                || !isset($probe['payload_paths']['$'])
                || $probe['api_outcome'] !== ($knownFailure ? 'failure' : 'success')
                || $probe['probe_outcome'] !== ($knownFailure ? 'failed' : 'passed')
                || $probe['payload_validation'] !== ($knownFailure ? 'not_checked' : 'passed')
                || $probe['failure_kind'] !== ($knownFailure ? 'api_failure' : null)
            ) {
                $findings[] = 'unexpected_outcome:' . $phase;
            }
            $hasReview = ($probe['readback_review'] ?? null) === 'known_dname_raw_string';
            if (
                ($probe['discrepancies'] !== [] || isset($probe['readback_review']))
                && (!$hasReview || !self::matchesReadback($probe))
            ) {
                $findings[] = 'unreviewed_discrepancy:' . $phase;
            }
        }
        foreach (array_diff(DnsZoneGuard::EXERCISE_PHASES, $ordered) as $phase) {
            $findings[] = 'missing_phase:' . $phase;
        }
        if ($ordered !== DnsZoneGuard::EXERCISE_PHASES) {
            $findings[] = 'phase_order_mismatch';
        }

        return ['status' => $findings === [] ? 'passed' : 'failed', 'policy' => 'uapi-dns-two-zone-v1',
            'findings' => array_values(array_unique($findings))];
    }

    /** @param Record $record */
    private static function matchesReadback(array $record): bool
    {
        return $record['operation'] === 'uapi:DNS/parse_zone' && $record['variant'] === 'uapi:DNS/parse_zone#GET'
            && in_array($record['route'], ['direct-uapi', 'bridged-uapi'], true) && $record['method'] === 'GET'
            && $record['server_version'] === '11.138.0.24' && $record['source_sha256'] === self::PARSE_SOURCE
            && $record['effective_contract_sha256'] === self::PARSE_CONTRACT && $record['http_status'] === 200
            && $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
            && $record['payload_validation'] === 'passed' && $record['failure_kind'] === null
            && ($record['payload_paths']['$[].*'] ?? null) === ['string']
            && $record['discrepancies'] === [['path' => '$[].*', 'kind' => 'unknown_field']];
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
