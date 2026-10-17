<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Assess the ten same-response observations from the bounded MySQL mutation fixture.
 *
 * The pinned source variants remain GET while every actual mutation must use POST. This
 * development-only assessment has no acknowledgment mechanism and performs no requests.
 * Already-sanitized capture records stay unchanged except for absent server versions,
 * which can be bound to one successful same-server baseline version observation.
 *
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-type Reason 'missing_probe'|'duplicate_probe'|'unexpected_probe'|'unsuccessful_probe'|'unusable_server_version'|'invalid_provenance'|'unacknowledged_discrepancy'
 * @phpstan-type Identity array{operation: string, variant: string, route: string}
 * @phpstan-type Assessment array{
 *     policy: 'mysql-mutations-no-acknowledgments-v1', status: 'passed'|'failed', acknowledged: array{},
 *     unexpected: list<array{operation: string, variant: string, route: string, reason: Reason}>
 * }
 * @phpstan-type Result array{probes: list<Record>, discrepancy_review: Assessment}
 */
final class MysqlMutationEvidence
{
    /**
     * Bind missing versions and assess the original fixture records without reclassifying outcomes.
     *
     * The caller supplies baseline records captured on the same guarded test server. Only one
     * successful WHM version GET with usable provenance can supply a version; conflicting versions
     * remain visible and fail. Shared revision, map, generator and overlay hashes must match, while
     * each source and effective-contract hash must be canonical. This checks attribution coherence,
     * not the contents of external artifacts. Raw payloads, identities and credentials are never inputs.
     *
     * @param list<Record> $records
     * @param list<Record> $baseline
     * @return Result
     */
    public static function finish(array $records, array $baseline): array
    {
        $versionRecord = self::versionRecord($baseline);
        $version = $versionRecord['server_version'] ?? null;
        foreach ($records as &$record) {
            if ($record['server_version'] === null) {
                $record['server_version'] = $version;
            }
        }
        unset($record);

        return [
            'probes' => $records,
            'discrepancy_review' => self::assess($records, $versionRecord),
        ];
    }

    /**
     * @param list<Record> $baseline
     * @return Record|null
     */
    private static function versionRecord(array $baseline): ?array
    {
        $matches = array_values(array_filter($baseline, static fn (array $record): bool =>
            $record['operation'] === 'whm1:version'));
        if (count($matches) !== 1) {
            return null;
        }
        $record = $matches[0];

        return $record['variant'] === 'whm1:version#GET' && $record['route'] === 'whm'
            && self::successful($record, 'GET') && self::usableVersion($record['server_version'])
            && self::canonicalProvenance($record) && $record['discrepancies'] === [] ? $record : null;
    }

    /**
     * @param list<Record> $records
     * @param Record|null $versionRecord
     * @return Assessment
     */
    private static function assess(array $records, ?array $versionRecord): array
    {
        $expected = [];
        foreach (
            [
            'create_database', 'create_user', 'set_privileges_on_database', 'delete_user', 'delete_database',
            ] as $function
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $operation = 'uapi:Mysql/' . $function;
                $identity = ['operation' => $operation, 'variant' => $operation . '#GET', 'route' => $route];
                $expected[self::identityKey($identity)] = $identity;
            }
        }
        $counts = [];
        foreach ($records as $record) {
            $key = self::identityKey($record);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $unexpected = [];
        foreach ($expected as $key => $identity) {
            if (!isset($counts[$key])) {
                $unexpected[] = [...$identity, 'reason' => 'missing_probe'];
            } elseif ($counts[$key] !== 1) {
                $unexpected[] = [...$identity, 'reason' => 'duplicate_probe'];
            }
        }
        foreach ($records as $record) {
            $identity = [
                'operation' => $record['operation'], 'variant' => $record['variant'], 'route' => $record['route'],
            ];
            if (!isset($expected[self::identityKey($identity)])) {
                $unexpected[] = [...$identity, 'reason' => 'unexpected_probe'];
            }
            if (!self::successful($record, 'POST')) {
                $unexpected[] = [...$identity, 'reason' => 'unsuccessful_probe'];
            }
            if (
                $versionRecord === null || !self::usableVersion($record['server_version'])
                || $record['server_version'] !== $versionRecord['server_version']
            ) {
                $unexpected[] = [...$identity, 'reason' => 'unusable_server_version'];
            }
            $matching = true;
            foreach (['revision', 'map_sha256', 'generator_tree', 'overlay_sha256'] as $field) {
                if ($versionRecord !== null && $record[$field] !== $versionRecord[$field]) {
                    $matching = false;
                }
            }
            if (!self::canonicalProvenance($record) || !$matching) {
                $unexpected[] = [...$identity, 'reason' => 'invalid_provenance'];
            }
            if ($record['discrepancies'] !== []) {
                $unexpected[] = [...$identity, 'reason' => 'unacknowledged_discrepancy'];
            }
        }

        return [
            'policy' => 'mysql-mutations-no-acknowledgments-v1',
            'status' => $unexpected === [] ? 'passed' : 'failed', 'acknowledged' => [], 'unexpected' => $unexpected,
        ];
    }

    /** @param Identity $identity */
    private static function identityKey(array $identity): string
    {
        return $identity['operation'] . '|' . $identity['variant'] . '|' . $identity['route'];
    }

    /** @param Record $record */
    private static function successful(array $record, string $method): bool
    {
        return $record['method'] === $method && $record['http_status'] !== null
            && $record['http_status'] >= 200 && $record['http_status'] < 300
            && $record['api_outcome'] === 'success' && $record['payload_validation'] === 'passed'
            && $record['probe_outcome'] === 'passed' && $record['failure_kind'] === null;
    }

    /** @param Record $record */
    private static function canonicalProvenance(array $record): bool
    {
        foreach (['revision', 'generator_tree'] as $field) {
            if (preg_match('/\A[a-f0-9]{40}\z/', $record[$field]) !== 1) {
                return false;
            }
        }
        foreach (['source_sha256', 'map_sha256', 'overlay_sha256', 'effective_contract_sha256'] as $field) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $record[$field]) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function usableVersion(?string $version): bool
    {
        return $version !== null && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) === 1;
    }
}
