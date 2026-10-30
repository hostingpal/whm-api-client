<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Strict phase and final-catalog assessment; no acknowledgments or private preference values.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Definition from SpamPreferenceRecipe
 * @phpstan-type Assessment array{status:'passed'|'failed',findings:list<string>}
 * @phpstan-type Result array{assessment:Assessment,preference_assessment:Assessment,catalog_assessment:Assessment}
 */
final class SpamPreferenceEvidence
{
    /**
     * Accept untrusted records and fail closed on missing, duplicate, reordered or malformed evidence.
     *
     * @param array<mixed> $records
     * @param Context $context
     * @return Result
     */
    public static function finish(array $records, array $context): array
    {
        $definitions = SpamPreferenceRecipe::definitions();
        $version = null;
        $versions = array_values(array_filter($records, static fn (mixed $record): bool =>
            is_array($record) && ($record['phase'] ?? null) === 'catalog_0'));
        if (count($versions) === 1) {
            $candidate = $versions[0]['server_version'] ?? null;
            if (self::matches($versions[0], $definitions['catalog_0'], $context, $candidate)) {
                $version = $candidate;
            }
        }
        $preference = [];
        $catalog = [];
        $seen = [];
        $order = [];
        foreach ($records as $record) {
            $phase = is_array($record) && is_string($record['phase'] ?? null) ? $record['phase'] : '';
            $findings = [];
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
            if (str_starts_with($phase, 'catalog_')) {
                array_push($catalog, ...$findings);
            } else {
                array_push($preference, ...$findings);
            }
        }
        foreach ($definitions as $phase => $definition) {
            if (!isset($seen[$phase])) {
                if (str_starts_with($phase, 'catalog_')) {
                    $catalog[] = 'missing_phase';
                } else {
                    $preference[] = 'missing_phase';
                }
            }
        }
        $expectedOrder = array_keys($definitions);
        $preferenceOrder = array_values(array_filter(
            $order,
            static fn (string $p): bool => !str_starts_with($p, 'catalog_'),
        ));
        $catalogOrder = array_values(array_filter(
            $order,
            static fn (string $p): bool => str_starts_with($p, 'catalog_'),
        ));
        if ($preferenceOrder !== array_slice($expectedOrder, 0, 12)) {
            $preference[] = 'phase_order_mismatch';
        }
        if ($catalogOrder !== array_slice($expectedOrder, 12)) {
            $catalog[] = 'phase_order_mismatch';
        }
        $all = [...$preference, ...$catalog];
        if (!array_is_list($records) || $order !== $expectedOrder) {
            $all[] = 'phase_order_mismatch';
        }

        return ['assessment' => self::assessment($all), 'preference_assessment' => self::assessment($preference),
            'catalog_assessment' => self::assessment($catalog)];
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

    /**
     * @param list<string> $findings
     * @return Assessment
     */
    private static function assessment(array $findings): array
    {
        return ['status' => $findings === [] ? 'passed' : 'failed',
            'findings' => array_values(array_unique($findings))];
    }
}
