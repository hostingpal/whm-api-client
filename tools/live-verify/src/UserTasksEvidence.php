<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\UserTasksSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\VariablesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\VersionControlSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;

/**
 * Strict phase, provenance and discrepancy gates for one user task queue run; no acknowledgment mechanism exists.
 *
 * Original records are never altered. Two reviews read the same records. The discrepancy review gates the run: it
 * covers the UserTasks phases and the server version, which every phase must share. The producer review covers the
 * home read and both VersionControl creations, sent through generic call(), which runs no ShapeValidator: their
 * validation must say not_checked, and their structural discrepancies are VersionControl and Variables evidence. They
 * fail that review, and are reported with the run, but they are not UserTasks evidence, so they never gate it. Every
 * phase must appear once, in order, with its reviewed contract and route, a successful outcome and no discrepancy.
 * Whether a phase met the plan is the fixture's expectation, not a contract question, so it is judged there.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from UserTasksFixture
 * @phpstan-type Assessment array{policy: string, status: 'passed'|'failed', findings: list<string>}
 */
final class UserTasksEvidence
{
    /** Every phase in execution order. */
    public const PHASES = [
        'version', 'home', 'tasks_initial_direct', 'tasks_initial_bridged', 'source_create', 'clone_create',
        'tasks_queued_direct', 'tasks_queued_bridged', 'delete_owned', 'tasks_after_direct', 'tasks_after_bridged',
    ];

    /** Phases sent through generic call(), which runs no ShapeValidator; none of them is UserTasks evidence. */
    public const PRODUCER_PHASES = ['home', 'source_create', 'clone_create'];

    /**
     * The reviewed contract of one phase; creation and deletion are pinned as GET and sent as POST by their specs.
     *
     * @return array{operation: string, variant: string, method: string, route: string, spec: array<mixed>}
     */
    public static function contract(string $phase): array
    {
        [$operation, $spec] = match (true) {
            $phase === 'version' => ['whm1:version', ServerSpec::VERSION],
            $phase === 'home' => ['uapi:Variables/get_user_information', VariablesSpec::GET_USER_INFORMATION],
            str_ends_with($phase, '_create') => ['uapi:VersionControl/create', VersionControlSpec::CREATE],
            $phase === 'delete_owned' => ['uapi:UserTasks/delete', UserTasksSpec::DELETE],
            default => ['uapi:UserTasks/retrieve', UserTasksSpec::RETRIEVE],
        };
        $route = match (true) {
            $phase === 'version' => 'whm',
            str_ends_with($phase, '_bridged') => 'bridged-uapi',
            default => 'direct-uapi',
        };

        return ['operation' => $operation, 'variant' => $operation . '#GET', 'method' => $spec['method'],
            'route' => $route, 'spec' => $spec];
    }

    /**
     * Require the complete ordered recipe from one server version, retaining every original discrepancy.
     *
     * An empty or partial run, including a setup failure before capture, fails with its missing phases.
     *
     * @param list<Observation> $records
     * @param Context $context
     * @return array{discrepancy_review: Assessment, producer_review: Assessment}
     */
    public static function assess(array $records, array $context): array
    {
        $versions = array_values(array_filter($records, static fn (array $record): bool =>
            $record['phase'] === 'version' && self::usableVersion($record['server_version'])));
        $version = count($versions) === 1 ? $versions[0]['server_version'] : null;
        $subject = $version === null ? ['unusable_server_version'] : [];
        $producer = $version === null ? ['unusable_server_version'] : [];
        $seen = [];
        foreach ($records as $record) {
            $phase = $record['phase'];
            $findings = [];
            if (isset($seen[$phase])) {
                $findings[] = 'duplicate_phase:' . $phase;
            }
            $seen[$phase] = true;
            if (!in_array($phase, self::PHASES, true)) {
                $subject[] = 'unexpected_phase';
                continue;
            }
            $contract = self::contract($phase);
            if (
                $record['operation'] !== $contract['operation'] || $record['variant'] !== $contract['variant']
                || $record['method'] !== $contract['method'] || $record['route'] !== $contract['route']
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
            if ($record['uapi_status'] !== ($phase === 'version' ? null : 1)) {
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
            if (in_array($phase, self::PRODUCER_PHASES, true)) {
                array_push($producer, ...$findings);
            } else {
                array_push($subject, ...$findings);
            }
        }
        foreach (self::PHASES as $phase) {
            if (!isset($seen[$phase])) {
                $missing = 'missing_phase:' . $phase;
                in_array($phase, self::PRODUCER_PHASES, true) ? $producer[] = $missing : $subject[] = $missing;
            }
        }
        if (array_column($records, 'phase') !== self::PHASES && array_diff(self::PHASES, array_keys($seen)) === []) {
            $subject[] = 'phase_order_mismatch';
        }

        return [
            'discrepancy_review' => self::assessment('user-tasks-subject-no-acknowledgments-v1', $subject),
            'producer_review' => self::assessment('user-tasks-producer-structure-v1', $producer),
        ];
    }

    /**
     * @param list<string> $findings
     * @return Assessment
     */
    private static function assessment(string $policy, array $findings): array
    {
        $findings = array_values(array_unique($findings));

        return ['policy' => $policy, 'status' => $findings === [] ? 'passed' : 'failed', 'findings' => $findings];
    }

    /** @param Observation $record */
    private static function successful(array $record): bool
    {
        $validation = in_array($record['phase'], self::PRODUCER_PHASES, true) ? 'not_checked' : 'passed';

        return $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
            && $record['payload_validation'] === $validation && $record['failure_kind'] === null
            && $record['http_status'] !== null && $record['http_status'] >= 200 && $record['http_status'] < 300;
    }

    private static function usableVersion(?string $version): bool
    {
        return $version !== null && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) === 1;
    }
}
