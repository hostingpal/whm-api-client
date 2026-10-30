<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Strict phase, provenance and discrepancy gate, independent of baseline discrepancy acknowledgments.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from FtpSectionFixture
 * @phpstan-type Result array{
 *     probes: list<Observation>, assessment: array{status: 'passed'|'failed', findings: list<string>}
 * }
 */
final class FtpSectionEvidence
{
    /**
     * Require the exact ordered recipe and same-run successful version; retain every original discrepancy.
     *
     * @param list<Observation> $records
     * @param Context $context
     * @return Result
     */
    public static function finish(array $records, array $context): array
    {
        $expected = ['version|whm' => 'version'];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach (FtpSectionRecipe::phases() as $phase => $function) {
                $expected[$phase . '|' . $route] = $function;
            }
        }
        $versions = array_values(array_filter($records, static fn (array $record): bool =>
            $record['phase'] === 'version' && $record['route'] === 'whm'));
        $version = count($versions) === 1 && self::successful($versions[0]) ? $versions[0]['server_version'] : null;
        $findings = [];
        if ($version === null || preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) !== 1) {
            $version = null;
            $findings[] = 'unusable_server_version';
        }
        $seen = [];
        foreach ($records as &$record) {
            $record['server_version'] ??= $version;
            $key = $record['phase'] . '|' . $record['route'];
            if (isset($seen[$key])) {
                $findings[] = 'duplicate_phase';
            }
            $seen[$key] = true;
            $function = $expected[$key] ?? null;
            if ($function === null) {
                $findings[] = 'unexpected_phase';
            } else {
                $spec = FtpSectionRecipe::spec($function);
                $operation = $function === 'version' ? 'whm1:version' : 'uapi:Ftp/' . $function;
                if (
                    $record['operation'] !== $operation || $record['variant'] !== $operation . '#GET'
                    || $record['method'] !== $spec['method']
                    || $record['effective_contract_sha256'] !== hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR))
                ) {
                    $findings[] = 'phase_contract_mismatch';
                }
            }
            if (!self::successful($record)) {
                $findings[] = 'unsuccessful_phase';
            }
            if ($record['discrepancies'] !== []) {
                $findings[] = 'unacknowledged_discrepancy';
            }
            if ($version === null || $record['server_version'] !== $version) {
                $findings[] = 'unusable_server_version';
            }
            foreach (['revision', 'map_sha256', 'generator_tree', 'overlay_sha256'] as $field) {
                $length = in_array($field, ['revision', 'generator_tree'], true) ? 40 : 64;
                if (
                    $record[$field] !== $context[$field]
                    || preg_match('/\A[a-f0-9]{' . $length . '}\z/', $record[$field]) !== 1
                ) {
                    $findings[] = 'invalid_provenance';
                }
            }
            $source = $context['source_hashes'][$record['route'] === 'whm' ? 'whm1' : 'uapi'];
            if ($record['source_sha256'] !== $source || preg_match('/\A[a-f0-9]{64}\z/', $source) !== 1) {
                $findings[] = 'invalid_provenance';
            }
        }
        unset($record);
        if (array_diff_key($expected, $seen) !== []) {
            $findings[] = 'missing_phase';
        }
        if (
            array_map(static fn (array $record): string => $record['phase'] . '|' . $record['route'], $records)
            !== array_keys($expected)
        ) {
            $findings[] = 'phase_order_mismatch';
        }
        $findings = array_values(array_unique($findings));

        return ['probes' => $records, 'assessment' => ['status' => $findings === [] ? 'passed' : 'failed',
            'findings' => $findings]];
    }

    /** @param Observation $record */
    private static function successful(array $record): bool
    {
        return $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
            && $record['payload_validation'] === 'passed' && $record['expectation'] === 'passed'
            && $record['failure_kind'] === null && $record['http_status'] !== null
            && $record['http_status'] >= 200 && $record['http_status'] < 300;
    }
}
