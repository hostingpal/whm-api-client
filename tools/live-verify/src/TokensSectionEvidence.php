<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Strict assessment of one token lifecycle's records; no acknowledgments and no token names or secrets.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Definition from TokensSectionRecipe
 * @phpstan-type Assessment array{status:'passed'|'failed',findings:list<string>}
 */
final class TokensSectionEvidence
{
    /**
     * Accept untrusted records and fail closed on missing, duplicate, reordered, extra or malformed evidence.
     *
     * Every record must be a successful, discrepancy-free observation of its phase's exact operation, route, method,
     * committed context and effective contract, on the version the first record established. A cleanup phase is
     * never expected, so a run that needed one cannot pass.
     *
     * @param array<mixed> $records
     * @param Context $context
     * @return Assessment
     */
    public static function finish(array $records, array $context): array
    {
        $definitions = TokensSectionRecipe::definitions();
        $first = array_is_list($records) ? ($records[0] ?? null) : null;
        $version = is_array($first) && ($first['phase'] ?? null) === 'version'
            ? ($first['server_version'] ?? null) : null;
        $findings = [];
        $seen = [];
        $order = [];
        foreach ($records as $record) {
            $phase = is_array($record) && is_string($record['phase'] ?? null) ? $record['phase'] : '';
            $order[] = $phase;
            if (isset($seen[$phase])) {
                $findings[] = 'duplicate_phase';
            }
            $seen[$phase] = true;
            $definition = $definitions[$phase] ?? null;
            if ($definition === null) {
                $findings[] = 'unexpected_phase';
            } elseif (!is_array($record) || !self::matches($record, $definition, $context, $version)) {
                $findings[] = 'unsuccessful_phase';
            }
            if (is_array($record) && ($record['discrepancies'] ?? []) !== []) {
                $findings[] = 'unacknowledged_discrepancy';
            }
        }
        foreach (array_keys($definitions) as $phase) {
            if (!isset($seen[$phase])) {
                $findings[] = 'missing_phase';
            }
        }
        if (!array_is_list($records) || $order !== array_keys($definitions)) {
            $findings[] = 'phase_order_mismatch';
        }

        return ['status' => $findings === [] ? 'passed' : 'failed',
            'findings' => array_values(array_unique($findings))];
    }

    /**
     * @param array<mixed> $record
     * @param Definition $definition
     * @param Context $context
     */
    private static function matches(array $record, array $definition, array $context, mixed $version): bool
    {
        if (
            !is_string($version) || preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) !== 1
            || !self::hasPaths($record['response_paths'] ?? null) || !self::hasPaths($record['payload_paths'] ?? null)
        ) {
            return false;
        }
        $expected = ['operation' => $definition['operation'], 'variant' => $definition['operation'] . '#GET',
            'route' => $definition['route'], 'method' => $definition['method'], 'http_status' => 200,
            'server_version' => $version, 'probe_outcome' => 'passed', 'api_outcome' => 'success',
            'payload_validation' => 'passed', 'failure_kind' => null, 'expectation' => 'passed', 'discrepancies' => [],
            'revision' => $context['revision'], 'map_sha256' => $context['map_sha256'],
            'generator_tree' => $context['generator_tree'], 'overlay_sha256' => $context['overlay_sha256'],
            'source_sha256' => $context['source_hashes'][$definition['route'] === 'whm' ? 'whm1' : 'uapi'],
            'effective_contract_sha256' => hash('sha256', json_encode($definition['spec'], JSON_THROW_ON_ERROR))];
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $record) || $record[$key] !== $value) {
                return false;
            }
        }
        foreach (['revision', 'generator_tree', 'source_sha256', 'map_sha256', 'overlay_sha256'] as $field) {
            $length = in_array($field, ['revision', 'generator_tree'], true) ? 40 : 64;
            if (!is_string($record[$field]) || preg_match('/\A[a-f0-9]{' . $length . '}\z/', $record[$field]) !== 1) {
                return false;
            }
        }

        return true;
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
