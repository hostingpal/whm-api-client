<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Strict phase-aware assessment with no discrepancy acknowledgments and no raw values as inputs.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from BoxTrapperSectionFixture
 * @phpstan-type Result array{
 *     probes: list<Observation>, assessment: array{status: 'passed'|'failed', findings: list<string>}
 * }
 */
final class BoxTrapperSectionEvidence
{
    /**
     * Bind server versions only from the successful same-run WHM version response and assess every phase.
     *
     * The pinned source variant stays GET; actual transport method comes from the generated spec.
     * Phase identity distinguishes deliberate readbacks and inventory checks from duplicate captures.
     * Missing, unexpected, duplicate, unsuccessful and discrepant observations fail independently.
     *
     * @param list<Observation> $records
     * @param Context $context
     * @return Result
     */
    public static function finish(array $records, array $context): array
    {
        $expected = ['version|whm' => 'version'];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach (BoxTrapperSectionRecipe::preflight() as $phase => $function) {
                $expected[$phase . '|' . $route] = $function;
            }
        }
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach (BoxTrapperSectionRecipe::phases() as $phase => $function) {
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
            if ($record['server_version'] === null) {
                $record['server_version'] = $version;
            }
            $key = $record['phase'] . '|' . $record['route'];
            if (isset($seen[$key])) {
                $findings[] = 'duplicate_phase';
            }
            $seen[$key] = true;
            $function = $expected[$key] ?? null;
            if ($function === null) {
                $findings[] = 'unexpected_phase';
            } else {
                $spec = BoxTrapperSectionRecipe::spec($function);
                $operation = BoxTrapperSectionRecipe::operation($function);
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
            if ($record['server_version'] !== $version || $version === null) {
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
        $order = array_map(static fn (array $record): string => $record['phase'] . '|' . $record['route'], $records);
        if ($order !== array_keys($expected)) {
            $findings[] = 'phase_order_mismatch';
        }
        $findings = array_values(array_unique($findings));

        return ['probes' => $records, 'assessment' => [
            'status' => $findings === [] ? 'passed' : 'failed', 'findings' => $findings,
        ]];
    }

    /** @param array<mixed> $record */
    private static function successful(array $record): bool
    {
        return self::hasPaths($record['response_paths'] ?? null) && self::hasPaths($record['payload_paths'] ?? null)
            && ($record['probe_outcome'] ?? null) === 'passed' && ($record['api_outcome'] ?? null) === 'success'
            && ($record['payload_validation'] ?? null) === 'passed'
            && array_key_exists('failure_kind', $record) && $record['failure_kind'] === null
            && ($record['expectation'] ?? null) === 'passed' && is_int($record['http_status'] ?? null)
            && $record['http_status'] >= 200 && $record['http_status'] < 300;
    }

    /** Require structural evidence, including a root, without revalidating the upstream payload. */
    private static function hasPaths(mixed $paths): bool
    {
        if (!is_array($paths) || !array_key_exists('$', $paths)) {
            return false;
        }
        foreach ($paths as $path => $types) {
            if (!is_string($path) || !is_array($types) || !array_is_list($types) || $types === []) {
                return false;
            }
            foreach ($types as $type) {
                if (!is_string($type) || $type === '') {
                    return false;
                }
            }
        }

        return true;
    }
}
