<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\LangPhpSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use LogicException;

/**
 * Strict phase, provenance and discrepancy gate for one disposable LangPHP mutation run; no acknowledgments exist.
 *
 * Original records are never altered. Every phase must appear once, in order, with its reviewed contract, route and
 * argument label, a passed expectation and no discrepancy. Two phases send the pinned enum's `host` INI type, which
 * the reviewed handler refuses before writing: for those, the expected outcome is an explicit API refusal with UAPI
 * status 0 and no payload validation, and an accepted write is a finding. Every other phase must succeed with a
 * validated payload.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from LangPhpMutationFixture
 * @phpstan-type Assessment array{
 *     policy: 'langphp-mutations-no-acknowledgments-v1', status: 'passed'|'failed', findings: list<string>
 * }
 */
final class LangPhpMutationEvidence
{
    /** Every phase in execution order: route, function and fixed argument label. */
    public const PLAN = [
        'version' => ['whm', 'version', null],
        'installed_direct' => ['direct-uapi', 'php_get_installed_versions', null],
        'installed_bridged' => ['bridged-uapi', 'php_get_installed_versions', null],
        'vhosts_direct' => ['direct-uapi', 'php_get_vhost_versions', 'all-vhosts'],
        'vhosts_bridged' => ['bridged-uapi', 'php_get_vhost_versions', 'all-vhosts'],
        'directives_direct' => ['direct-uapi', 'php_ini_get_user_basic_directives', 'home-scope'],
        'directives_bridged' => ['bridged-uapi', 'php_ini_get_user_basic_directives', 'home-scope'],
        'content_direct' => ['direct-uapi', 'php_ini_get_user_content', 'home-scope'],
        'content_bridged' => ['bridged-uapi', 'php_ini_get_user_content', 'home-scope'],
        'set_directives_direct' => ['direct-uapi', 'php_ini_set_user_basic_directives', 'scalar-directive'],
        'read_directives_direct' => ['direct-uapi', 'php_ini_get_user_basic_directives', 'home-scope'],
        'set_directives_bridged' => ['bridged-uapi', 'php_ini_set_user_basic_directives', 'numbered-directives'],
        'read_directives_bridged' => ['bridged-uapi', 'php_ini_get_user_basic_directives', 'home-scope'],
        'set_content_direct' => ['direct-uapi', 'php_ini_set_user_content', 'home-type'],
        'read_content_direct' => ['direct-uapi', 'php_ini_get_user_content', 'home-scope'],
        'read_content_directives_direct' => ['direct-uapi', 'php_ini_get_user_basic_directives', 'home-scope'],
        'set_content_bridged' => ['bridged-uapi', 'php_ini_set_user_content', 'home-type'],
        'read_content_bridged' => ['bridged-uapi', 'php_ini_get_user_content', 'home-scope'],
        'read_content_directives_bridged' => ['bridged-uapi', 'php_ini_get_user_basic_directives', 'home-scope'],
        'set_content_host_direct' => ['direct-uapi', 'php_ini_set_user_content', 'host-type'],
        'read_content_host_direct' => ['direct-uapi', 'php_ini_get_user_content', 'home-scope'],
        'set_content_host_bridged' => ['bridged-uapi', 'php_ini_set_user_content', 'host-type'],
        'read_content_host_bridged' => ['bridged-uapi', 'php_ini_get_user_content', 'home-scope'],
        'set_version_direct' => ['direct-uapi', 'php_set_vhost_versions', 'selected-vhost'],
        'read_version_direct' => ['direct-uapi', 'php_get_vhost_versions', 'selected-vhost'],
        'set_version_bridged' => ['bridged-uapi', 'php_set_vhost_versions', 'selected-vhost'],
        'read_version_bridged' => ['bridged-uapi', 'php_get_vhost_versions', 'selected-vhost'],
    ];

    /** Phases whose expected outcome is an explicit API refusal. */
    public const REFUSED = ['set_content_host_direct', 'set_content_host_bridged'];

    /**
     * The reviewed contract of one phase; every LangPHP function is pinned as GET, and the setters are sent as POST.
     *
     * @return array{operation: string, variant: string, method: string, spec: array<mixed>}
     */
    public static function contract(string $phase): array
    {
        [, $function] = self::PLAN[$phase] ?? throw new LogicException('Unknown LangPHP mutation phase.');
        $spec = match ($function) {
            'version' => ServerSpec::VERSION,
            'php_get_installed_versions' => LangPhpSpec::PHP_GET_INSTALLED_VERSIONS,
            'php_get_vhost_versions' => LangPhpSpec::PHP_GET_VHOST_VERSIONS,
            'php_ini_get_user_basic_directives' => LangPhpSpec::PHP_INI_GET_USER_BASIC_DIRECTIVES,
            'php_ini_get_user_content' => LangPhpSpec::PHP_INI_GET_USER_CONTENT,
            'php_ini_set_user_basic_directives' => LangPhpSpec::PHP_INI_SET_USER_BASIC_DIRECTIVES,
            'php_ini_set_user_content' => LangPhpSpec::PHP_INI_SET_USER_CONTENT,
            'php_set_vhost_versions' => LangPhpSpec::PHP_SET_VHOST_VERSIONS,
        };
        $operation = $function === 'version' ? 'whm1:version' : 'uapi:LangPHP/' . $function;

        return ['operation' => $operation, 'variant' => $operation . '#GET', 'method' => $spec['method'],
            'spec' => $spec];
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
            if (!isset(self::PLAN[$phase])) {
                $findings[] = 'unexpected_phase';
                continue;
            }
            [$route, , $argument] = self::PLAN[$phase];
            $contract = self::contract($phase);
            if (
                $record['operation'] !== $contract['operation'] || $record['variant'] !== $contract['variant']
                || $record['method'] !== $contract['method'] || $record['route'] !== $route
                || ($record['argument_variant'] ?? null) !== $argument
                || $record['effective_contract_sha256']
                    !== hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR))
            ) {
                $findings[] = 'contract_mismatch:' . $phase;
            }
            $refused = in_array($phase, self::REFUSED, true);
            if (!($refused ? self::refused($record) : self::successful($record))) {
                $findings[] = 'unexpected_outcome:' . $phase;
            }
            if ($record['discrepancies'] !== []) {
                $findings[] = 'unacknowledged_discrepancy:' . $phase;
            }
            if ($record['server_version'] !== $version) {
                $findings[] = 'unusable_server_version';
            }
            if ($record['uapi_status'] !== ($phase === 'version' ? null : ($refused ? 0 : 1))) {
                $findings[] = 'unexpected_uapi_status:' . $phase;
            }
            if (
                $record['revision'] !== $context['revision'] || $record['map_sha256'] !== $context['map_sha256']
                || $record['generator_tree'] !== $context['generator_tree']
                || $record['overlay_sha256'] !== $context['overlay_sha256']
                || $record['source_sha256'] !== $context['source_hashes'][$route === 'whm' ? 'whm1' : 'uapi']
            ) {
                $findings[] = 'invalid_provenance:' . $phase;
            }
        }
        foreach (array_keys(self::PLAN) as $phase) {
            if (!isset($seen[$phase])) {
                $findings[] = 'missing_phase:' . $phase;
            }
        }
        $phases = array_keys(self::PLAN);
        if (array_column($records, 'phase') !== $phases && array_diff($phases, array_keys($seen)) === []) {
            $findings[] = 'phase_order_mismatch';
        }
        $findings = array_values(array_unique($findings));

        return [
            'policy' => 'langphp-mutations-no-acknowledgments-v1',
            'status' => $findings === [] ? 'passed' : 'failed',
            'findings' => $findings,
        ];
    }

    /** @param Observation $record */
    private static function successful(array $record): bool
    {
        return $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
            && $record['payload_validation'] === 'passed' && $record['expectation'] === 'passed'
            && $record['failure_kind'] === null && self::http($record);
    }

    /**
     * An explicit API refusal, read from the same response; a lost or unreadable reply is no refusal.
     *
     * @param Observation $record
     */
    private static function refused(array $record): bool
    {
        return $record['probe_outcome'] === 'failed' && $record['api_outcome'] === 'failure'
            && $record['failure_kind'] === 'api_failure' && $record['payload_validation'] === 'not_checked'
            && $record['expectation'] === 'passed' && self::http($record);
    }

    /** @param Observation $record */
    private static function http(array $record): bool
    {
        return $record['http_status'] !== null && $record['http_status'] >= 200 && $record['http_status'] < 300;
    }

    private static function usableVersion(?string $version): bool
    {
        return $version !== null && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) === 1;
    }
}
