<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\ActiveSessionsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\EmailSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SessionSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionRecipe;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Observation from WebmailSessionFixture */
final class WebmailSessionEvidenceTest extends TestCase
{
    public function testEveryPhaseIsBoundToItsReviewedContract(): void
    {
        $contracts = [
            'version' => ['whm1:version', ServerSpec::VERSION],
            'sessions_initial_direct' => ['uapi:ActiveSessions/list_active_sessions',
                ActiveSessionsSpec::LIST_ACTIVE_SESSIONS],
            'create_mailbox' => ['uapi:Email/add_pop', EmailSpec::ADD_POP],
            'self_bridged' => ['uapi:Session/create_webmail_session_for_self',
                SessionSpec::CREATE_WEBMAIL_SESSION_FOR_SELF],
            'mail_user_direct' => ['uapi:Session/create_webmail_session_for_mail_user',
                SessionSpec::CREATE_WEBMAIL_SESSION_FOR_MAIL_USER],
            'check_password_bridged' => ['uapi:Session/create_webmail_session_for_mail_user_check_password',
                SessionSpec::CREATE_WEBMAIL_SESSION_FOR_MAIL_USER_CHECK_PASSWORD],
            'sessions_final_bridged' => ['uapi:ActiveSessions/list_active_sessions',
                ActiveSessionsSpec::LIST_ACTIVE_SESSIONS],
        ];
        foreach ($contracts as $phase => [$operation, $spec]) {
            $contract = WebmailSessionEvidence::contract($phase);
            self::assertSame($operation, $contract['operation'], $phase);
            // Every operation here is pinned as GET; the Session creators and add_pop are sent as POST.
            self::assertSame($operation . '#GET', $contract['variant'], $phase);
            self::assertSame($spec, $contract['spec'], $phase);
            self::assertSame($spec['method'], $contract['method'], $phase);
        }
        self::assertSame('POST', WebmailSessionEvidence::contract('self_direct')['method']);
        self::assertSame('GET', WebmailSessionEvidence::contract('sessions_final_direct')['method']);
    }

    public function testACompleteCleanRecordSetPasses(): void
    {
        self::assertSame(
            ['policy' => 'webmail-sessions-no-acknowledgments-v1', 'status' => 'passed', 'findings' => []],
            WebmailSessionEvidence::assess(self::records(), self::context()),
        );
    }

    public function testAnEmptyRunFailsWithEveryMissingPhase(): void
    {
        $findings = WebmailSessionEvidence::assess([], self::context())['findings'];
        self::assertContains('unusable_server_version', $findings);
        foreach (WebmailSessionRecipe::PHASES as $phase) {
            self::assertContains('missing_phase:' . $phase, $findings);
        }
    }

    public function testAFailedCreatorIsAFinding(): void
    {
        $records = self::records(['self_bridged' => ['probe_outcome' => 'failed', 'api_outcome' => 'failure',
            'failure_kind' => 'api_failure', 'payload_validation' => 'not_checked', 'uapi_status' => 0]]);
        $findings = WebmailSessionEvidence::assess($records, self::context())['findings'];
        self::assertSame(['unsuccessful_phase:self_bridged', 'unexpected_uapi_status:self_bridged'], $findings);
    }

    public function testDiscrepanciesFailedExpectationsAndBadProvenanceAreFindings(): void
    {
        $records = self::records([
            'sessions_final_direct' => ['discrepancies' => [['path' => '$[].*', 'kind' => 'unknown_field']]],
            'self_direct' => ['expectation' => 'failed'],
            'sessions_initial_bridged' => ['revision' => str_repeat('0', 40)],
            'create_mailbox' => ['effective_contract_sha256' => str_repeat('0', 64)],
            'mail_user_direct' => ['route' => 'bridged-uapi'],
        ]);
        self::assertSame([
            'invalid_provenance:sessions_initial_bridged',
            'contract_mismatch:create_mailbox',
            'unsuccessful_phase:self_direct',
            'contract_mismatch:mail_user_direct',
            'unacknowledged_discrepancy:sessions_final_direct',
        ], WebmailSessionEvidence::assess($records, self::context())['findings']);
    }

    public function testDuplicateAndReorderedPhasesAreFindings(): void
    {
        $records = self::records();
        $swapped = [];
        foreach (array_keys($records) as $index) {
            $swapped[] = $records[match ($index) {
                4 => 7,
                7 => 4,
                default => $index,
            }] ?? self::fail();
        }
        self::assertSame(
            ['phase_order_mismatch'],
            WebmailSessionEvidence::assess($swapped, self::context())['findings'],
        );
        $records = self::records();
        $records[] = $records[11] ?? self::fail();
        self::assertSame(
            ['duplicate_phase:sessions_final_bridged', 'phase_order_mismatch'],
            WebmailSessionEvidence::assess($records, self::context())['findings'],
        );
    }

    public function testMixedServerVersionsAreUnusable(): void
    {
        $records = self::records(['check_password_direct' => ['server_version' => '11.140.0.1']]);
        self::assertSame(
            ['unusable_server_version'],
            WebmailSessionEvidence::assess($records, self::context())['findings'],
        );
    }

    /**
     * Every phase's clean record, with the given fields replaced in the named phases.
     *
     * @param array<string, array<string, mixed>> $changes
     * @return list<Observation>
     */
    private static function records(array $changes = []): array
    {
        $context = self::context();
        $records = [];
        foreach (WebmailSessionRecipe::PHASES as $phase) {
            $contract = WebmailSessionEvidence::contract($phase);
            $route = WebmailSessionRecipe::route($phase);
            $record = [
                'phase' => $phase, 'expectation' => 'passed', 'uapi_status' => $route === 'whm' ? null : 1,
                'operation' => $contract['operation'], 'variant' => $contract['variant'], 'route' => $route,
                'method' => $contract['method'], 'server_version' => '11.138.0.19',
                'observed_at' => '2026-11-02T00:00:00Z', 'revision' => $context['revision'],
                'source_sha256' => $context['source_hashes'][$route === 'whm' ? 'whm1' : 'uapi'],
                'map_sha256' => $context['map_sha256'], 'generator_tree' => $context['generator_tree'],
                'overlay_sha256' => $context['overlay_sha256'],
                'effective_contract_sha256' => hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR)),
                'http_status' => 200, 'api_outcome' => 'success', 'payload_validation' => 'passed',
                'probe_outcome' => 'passed', 'failure_kind' => null, 'response_paths' => [], 'payload_paths' => [],
                'discrepancies' => [],
            ];
            /** @var Observation $changed */
            $changed = [...$record, ...($changes[$phase] ?? [])];
            $records[] = $changed;
        }

        return $records;
    }

    /** @return array{revision: string, source_hashes: array{whm1: string, uapi: string}, map_sha256: string,
     *     generator_tree: string, overlay_sha256: string} */
    private static function context(): array
    {
        return WebmailSessionFixtureTest::context();
    }
}
