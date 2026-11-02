<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\ActiveSessionsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\EmailSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SessionSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use LogicException;

/**
 * Strict phase, provenance and discrepancy gate for one disposable Webmail session run; nothing can be acknowledged.
 *
 * Original records are never altered. Every phase must appear once, in order, on its own route with its reviewed
 * contract, a successful typed outcome, UAPI status 1, a passed expectation and no structural discrepancy. A creator
 * that failed on either route is a finding, as is any unknown field in a populated session row.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Observation from WebmailSessionFixture
 * @phpstan-type Assessment array{
 *     policy: 'webmail-sessions-no-acknowledgments-v1', status: 'passed'|'failed', findings: list<string>
 * }
 */
final class WebmailSessionEvidence
{
    /**
     * The reviewed contract of one phase. Every operation here is pinned as GET; the generated specs of add_pop and the
     * three Session creators send form POST.
     *
     * @return array{operation: string, variant: string, method: string, spec: array<mixed>}
     */
    public static function contract(string $phase): array
    {
        $creator = WebmailSessionRecipe::creator($phase);
        [$operation, $spec] = match (true) {
            $phase === 'version' => ['whm1:version', ServerSpec::VERSION],
            $phase === 'create_mailbox' => ['uapi:Email/add_pop', EmailSpec::ADD_POP],
            str_starts_with($phase, 'sessions_') => [
                'uapi:ActiveSessions/list_active_sessions', ActiveSessionsSpec::LIST_ACTIVE_SESSIONS,
            ],
            $creator === 'self' => [
                'uapi:Session/create_webmail_session_for_self', SessionSpec::CREATE_WEBMAIL_SESSION_FOR_SELF,
            ],
            $creator === 'mail_user' => [
                'uapi:Session/create_webmail_session_for_mail_user', SessionSpec::CREATE_WEBMAIL_SESSION_FOR_MAIL_USER,
            ],
            $creator === 'check_password' => [
                'uapi:Session/create_webmail_session_for_mail_user_check_password',
                SessionSpec::CREATE_WEBMAIL_SESSION_FOR_MAIL_USER_CHECK_PASSWORD,
            ],
            default => throw new LogicException('Unknown Webmail session phase.'),
        };

        return ['operation' => $operation, 'variant' => $operation . '#GET', 'method' => $spec['method'],
            'spec' => $spec];
    }

    /**
     * Require the complete ordered recipe from one server version, retaining every original discrepancy.
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
            if (!in_array($phase, WebmailSessionRecipe::PHASES, true)) {
                $findings[] = 'unexpected_phase';
                continue;
            }
            $contract = self::contract($phase);
            $route = WebmailSessionRecipe::route($phase);
            if (
                $record['operation'] !== $contract['operation'] || $record['variant'] !== $contract['variant']
                || $record['method'] !== $contract['method'] || $record['route'] !== $route
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
            if ($record['uapi_status'] !== ($route === 'whm' ? null : 1)) {
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
        foreach (WebmailSessionRecipe::PHASES as $phase) {
            if (!isset($seen[$phase])) {
                $findings[] = 'missing_phase:' . $phase;
            }
        }
        if (
            array_column($records, 'phase') !== WebmailSessionRecipe::PHASES
            && array_diff(WebmailSessionRecipe::PHASES, array_keys($seen)) === []
        ) {
            $findings[] = 'phase_order_mismatch';
        }
        $findings = array_values(array_unique($findings));

        return [
            'policy' => 'webmail-sessions-no-acknowledgments-v1',
            'status' => $findings === [] ? 'passed' : 'failed',
            'findings' => $findings,
        ];
    }

    /** @param Observation $record */
    private static function successful(array $record): bool
    {
        return $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
            && $record['payload_validation'] === 'passed' && $record['expectation'] === 'passed'
            && $record['failure_kind'] === null && $record['http_status'] !== null
            && $record['http_status'] >= 200 && $record['http_status'] < 300;
    }

    private static function usableVersion(?string $version): bool
    {
        return $version !== null && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) === 1;
    }
}
