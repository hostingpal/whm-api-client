<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;

/**
 * Strict phase, provenance and discrepancy gate for one disposable upload run; no acknowledgment mechanism exists.
 *
 * Original records are never altered. Every phase must appear once, in order, with its reviewed contract, a successful
 * outcome, a passed expectation and no discrepancy. An all-failed upload is an expected status-0 row batch, so its
 * record is judged by the same rule: the typed client must return it normally rather than raise. Typed phases must
 * report passed payload validation. The generic phases must report not_checked, because no ShapeValidator runs on them;
 * their structural discrepancies are held to the same rule as every other phase.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from FilemanUploadFixture
 * @phpstan-type Assessment array{
 *     policy: 'fileman-upload-no-acknowledgments-v2', status: 'passed'|'failed', findings: list<string>
 * }
 */
final class FilemanUploadEvidence
{
    /** Every phase in execution order, including the exact cleanup of proven-owned resources. */
    public const PHASES = [
        'version', 'home_initial', 'upload_success', 'inventory_success',
        'upload_partial', 'upload_all_failed', 'upload_raw', 'inventory_final',
        'read_bytes', 'read_cursor', 'read_path', 'read_partial', 'read_raw',
        'upload_empty', 'inventory_complete', 'read_empty',
        'delete_bytes', 'delete_cursor', 'delete_path', 'delete_partial', 'delete_raw', 'delete_empty',
        'inventory_empty', 'delete_directory', 'home_final',
    ];

    /** Phases sent through generic call() or callMultipart(), which run no ShapeValidator. */
    private const GENERIC_PHASES = ['home_initial', 'upload_raw', 'home_final'];

    /**
     * The reviewed contract of one phase; delete_file is pinned as GET and sent as POST by its generated spec.
     *
     * @return array{operation: string, variant: string, method: string, spec: array<mixed>}
     */
    public static function contract(string $phase): array
    {
        [$operation, $pinned, $spec] = match (true) {
            $phase === 'version' => ['whm1:version', 'GET', ServerSpec::VERSION],
            str_starts_with($phase, 'upload_') => ['uapi:Fileman/upload_files', 'POST', FilemanSpec::UPLOAD_FILES],
            str_starts_with($phase, 'delete_') => ['uapi:Fileman/delete_file', 'GET', FilemanSpec::DELETE_FILE],
            str_starts_with($phase, 'read_') => ['uapi:Fileman/get_file_content', 'GET', FilemanSpec::GET_FILE_CONTENT],
            default => ['uapi:Fileman/list_files', 'GET', FilemanSpec::LIST_FILES],
        };

        return ['operation' => $operation, 'variant' => $operation . '#' . $pinned,
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
    public static function assess(array $records, array $context): array
    {
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
            if (!in_array($phase, self::PHASES, true)) {
                $findings[] = 'unexpected_phase';
                continue;
            }
            $contract = self::contract($phase);
            if (
                $record['operation'] !== $contract['operation'] || $record['variant'] !== $contract['variant']
                || $record['method'] !== $contract['method'] || $record['effective_contract_sha256']
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
            $expectedStatus = $phase === 'version' ? null : ($phase === 'upload_all_failed' ? 0 : 1);
            if ($record['uapi_status'] !== $expectedStatus) {
                $findings[] = 'unexpected_uapi_status:' . $phase;
            }
            if (
                $record['revision'] !== $context['revision'] || $record['map_sha256'] !== $context['map_sha256']
                || $record['generator_tree'] !== $context['generator_tree']
                || $record['overlay_sha256'] !== $context['overlay_sha256']
                || $record['source_sha256'] !== $context['source_hashes'][$record['route'] === 'whm' ? 'whm1' : 'uapi']
            ) {
                $findings[] = 'invalid_provenance:' . $phase;
            }
        }
        foreach (self::PHASES as $phase) {
            if (!isset($seen[$phase])) {
                $findings[] = 'missing_phase:' . $phase;
            }
        }
        if (array_column($records, 'phase') !== self::PHASES && array_diff(self::PHASES, array_keys($seen)) === []) {
            $findings[] = 'phase_order_mismatch';
        }
        $findings = array_values(array_unique($findings));

        return [
            'policy' => 'fileman-upload-no-acknowledgments-v2',
            'status' => $findings === [] ? 'passed' : 'failed',
            'findings' => $findings,
        ];
    }

    /** @param Observation $record */
    private static function successful(array $record): bool
    {
        $validation = in_array($record['phase'], self::GENERIC_PHASES, true) ? 'not_checked' : 'passed';

        return $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
            && $record['payload_validation'] === $validation && $record['expectation'] === 'passed'
            && $record['failure_kind'] === null && $record['http_status'] !== null
            && $record['http_status'] >= 200 && $record['http_status'] < 300;
    }

    private static function usableVersion(?string $version): bool
    {
        return $version !== null && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) === 1;
    }
}
