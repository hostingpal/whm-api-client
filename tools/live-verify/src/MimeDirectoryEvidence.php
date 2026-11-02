<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\DirectoryIndexesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DirectoryPrivacySpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DirectoryProtectionSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\MimeSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use LogicException;

/**
 * Strict phase, provenance and discrepancy gate for one disposable Mime and directory run; no acknowledgments exist.
 *
 * Original records are never altered. Every phase must appear once, in recipe order, with its reviewed contract, a
 * successful outcome, UAPI status 1, a passed expectation and no discrepancy. Typed phases must report passed payload
 * validation. The public_html listing goes through generic call(), which runs no ShapeValidator, so it must report
 * not_checked; its structural discrepancies are held to the same rule as every other phase.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from MimeDirectoryFixture
 * @phpstan-type Assessment array{
 *     policy: 'mime-directory-no-acknowledgments-v1', status: 'passed'|'failed', findings: list<string>
 * }
 */
final class MimeDirectoryEvidence
{
    /** Phases sent through generic call(), which runs no ShapeValidator. */
    public const GENERIC_PHASES = ['docroot_listing'];

    /** The pinned source documents upload_files as POST; every other operation here is pinned as GET. */
    private const SPECS = [
        'whm1:version' => ServerSpec::VERSION,
        'uapi:Mime/list_mime' => MimeSpec::LIST_MIME,
        'uapi:Mime/list_handlers' => MimeSpec::LIST_HANDLERS,
        'uapi:Mime/list_redirects' => MimeSpec::LIST_REDIRECTS,
        'uapi:Mime/get_redirect' => MimeSpec::GET_REDIRECT,
        'uapi:Mime/list_hotlinks' => MimeSpec::LIST_HOTLINKS,
        'uapi:Mime/add_mime' => MimeSpec::ADD_MIME,
        'uapi:Mime/delete_mime' => MimeSpec::DELETE_MIME,
        'uapi:Mime/add_handler' => MimeSpec::ADD_HANDLER,
        'uapi:Mime/delete_handler' => MimeSpec::DELETE_HANDLER,
        'uapi:Mime/add_redirect' => MimeSpec::ADD_REDIRECT,
        'uapi:Mime/delete_redirect' => MimeSpec::DELETE_REDIRECT,
        'uapi:Mime/add_hotlink' => MimeSpec::ADD_HOTLINK,
        'uapi:Mime/delete_hotlink' => MimeSpec::DELETE_HOTLINK,
        'uapi:Fileman/list_files' => FilemanSpec::LIST_FILES,
        'uapi:Fileman/upload_files' => FilemanSpec::UPLOAD_FILES,
        'uapi:DirectoryProtection/list_directories' => DirectoryProtectionSpec::LIST_DIRECTORIES,
        'uapi:DirectoryIndexes/get_indexing' => DirectoryIndexesSpec::GET_INDEXING,
        'uapi:DirectoryIndexes/set_indexing' => DirectoryIndexesSpec::SET_INDEXING,
        'uapi:DirectoryIndexes/list_directories' => DirectoryIndexesSpec::LIST_DIRECTORIES,
        'uapi:DirectoryPrivacy/is_directory_protected' => DirectoryPrivacySpec::IS_DIRECTORY_PROTECTED,
        'uapi:DirectoryPrivacy/configure_directory_protection' =>
            DirectoryPrivacySpec::CONFIGURE_DIRECTORY_PROTECTION,
        'uapi:DirectoryPrivacy/list_directories' => DirectoryPrivacySpec::LIST_DIRECTORIES,
        'uapi:DirectoryPrivacy/add_user' => DirectoryPrivacySpec::ADD_USER,
        'uapi:DirectoryPrivacy/delete_user' => DirectoryPrivacySpec::DELETE_USER,
        'uapi:DirectoryPrivacy/list_users' => DirectoryPrivacySpec::LIST_USERS,
    ];

    /**
     * The reviewed contract of one phase: its operation, pinned source variant, generated method and spec.
     *
     * @return array{operation: string, variant: string, method: string, spec: array<mixed>}
     * @throws LogicException For an unknown phase.
     */
    public static function contract(string $phase): array
    {
        $operation = (MimeDirectoryRecipe::PHASES[$phase] ?? throw new LogicException('Unknown phase.'))[2];
        $spec = self::SPECS[$operation];
        $pinned = $operation === 'uapi:Fileman/upload_files' ? 'POST' : 'GET';

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
        $phases = array_keys(MimeDirectoryRecipe::PHASES);
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
            if (!isset(MimeDirectoryRecipe::PHASES[$phase])) {
                $findings[] = 'unexpected_phase';
                continue;
            }
            $contract = self::contract($phase);
            if (
                $record['operation'] !== $contract['operation'] || $record['variant'] !== $contract['variant']
                || $record['route'] !== MimeDirectoryRecipe::PHASES[$phase][1]
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
        }
        foreach ($phases as $phase) {
            if (!isset($seen[$phase])) {
                $findings[] = 'missing_phase:' . $phase;
            }
        }
        if (array_column($records, 'phase') !== $phases && array_diff($phases, array_keys($seen)) === []) {
            $findings[] = 'phase_order_mismatch';
        }
        $findings = array_values(array_unique($findings));

        return [
            'policy' => 'mime-directory-no-acknowledgments-v1',
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
