<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\MimeSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryRecipe;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Observation from MimeDirectoryFixture
 * @phpstan-import-type Assessment from MimeDirectoryEvidence
 */
final class MimeDirectoryEvidenceTest extends TestCase
{
    /** Written out here, independently of the assessment under test. */
    private const GENERIC_PHASES = ['docroot_listing'];

    public function testACompleteOrderedRunPassesWithNoAcknowledgmentMechanism(): void
    {
        self::assertSame(
            ['policy' => 'mime-directory-no-acknowledgments-v1', 'status' => 'passed', 'findings' => []],
            self::assess(self::records()),
        );
    }

    public function testAnEmptyRunFailsWithEveryPhaseMissing(): void
    {
        $assessment = self::assess([]);
        self::assertSame('failed', $assessment['status']);
        self::assertContains('unusable_server_version', $assessment['findings']);
        foreach (array_keys(MimeDirectoryRecipe::PHASES) as $phase) {
            self::assertContains('missing_phase:' . $phase, $assessment['findings']);
        }
    }

    public function testDuplicateMissingUnexpectedAndReorderedPhasesFail(): void
    {
        $records = self::records();
        self::assertSame(
            ['duplicate_phase:redirects_baseline', 'phase_order_mismatch'],
            self::assess([...$records, $records[3]])['findings'],
        );
        $rows = array_filter($records, static fn (array $record): bool =>
            $record['phase'] !== 'delete_hotlink_bridged');
        self::assertSame(['missing_phase:delete_hotlink_bridged'], self::assess(array_values($rows))['findings']);
        self::assertSame(['phase_order_mismatch'], self::assess(array_reverse($records))['findings']);
        $records = self::changed('add_mime_direct', static function (array $record): array {
            $record['phase'] = 'add_mime_elsewhere';

            return $record;
        });
        self::assertSame(['unexpected_phase', 'missing_phase:add_mime_direct'], self::assess($records)['findings']);
    }

    public function testAnyContractRouteOrMethodDifferenceFails(): void
    {
        $records = self::changed('add_mime_direct', static function (array $record): array {
            $record['effective_contract_sha256'] = hash('sha256', 'other');

            return $record;
        });
        self::assertSame(['contract_mismatch:add_mime_direct'], self::assess($records)['findings']);
        $records = self::changed('delete_mime_bridged', static function (array $record): array {
            $record['route'] = 'direct-uapi';
            $record['source_sha256'] = MimeDirectoryFixtureTest::context()['source_hashes']['uapi'];

            return $record;
        });
        self::assertSame(['contract_mismatch:delete_mime_bridged'], self::assess($records)['findings']);
        $records = self::changed('add_handler_bridged', static function (array $record): array {
            $record['method'] = 'GET';

            return $record;
        });
        self::assertSame(['contract_mismatch:add_handler_bridged'], self::assess($records)['findings']);
    }

    public function testADiscrepancyCannotPassEvenWhenEveryOutcomePassed(): void
    {
        $records = self::changed('hotlinks_after_add_direct', static function (array $record): array {
            $record['discrepancies'] = [['path' => '$.*', 'kind' => 'unknown_field']];

            return $record;
        });
        self::assertSame(
            ['unacknowledged_discrepancy:hotlinks_after_add_direct'],
            self::assess($records)['findings'],
        );
    }

    public function testEveryUapiPhaseMustReportStatusOne(): void
    {
        $records = self::changed('add_user_direct', static function (array $record): array {
            $record['uapi_status'] = 0;

            return $record;
        });
        self::assertSame(['unexpected_uapi_status:add_user_direct'], self::assess($records)['findings']);
    }

    public function testAFailedExpectationOrPayloadMismatchFailsItsPhase(): void
    {
        $records = self::changed('users_after_add_direct', static function (array $record): array {
            $record['expectation'] = 'failed';

            return $record;
        });
        self::assertSame(['unsuccessful_phase:users_after_add_direct'], self::assess($records)['findings']);
        $records = self::changed('unprotect_bridged', static function (array $record): array {
            $record['payload_validation'] = 'failed';
            $record['probe_outcome'] = 'failed';
            $record['failure_kind'] = 'payload_mismatch';

            return $record;
        });
        self::assertSame(['unsuccessful_phase:unprotect_bridged'], self::assess($records)['findings']);
    }

    /** No validator runs on the generic listing, so a passed claim is false however clean the rest is. */
    public function testValidationClaimsMustMatchWhetherAValidatorRan(): void
    {
        $records = self::changed('docroot_listing', static function (array $record): array {
            $record['payload_validation'] = 'passed';

            return $record;
        });
        self::assertSame(['unsuccessful_phase:docroot_listing'], self::assess($records)['findings']);
        $records = self::changed('directory_inventory', static function (array $record): array {
            $record['payload_validation'] = 'not_checked';

            return $record;
        });
        self::assertSame(['unsuccessful_phase:directory_inventory'], self::assess($records)['findings']);
    }

    public function testProvenanceAndOneServerVersionAreRequired(): void
    {
        $records = self::changed('leech_final', static function (array $record): array {
            $record['revision'] = str_repeat('0', 40);

            return $record;
        });
        self::assertSame(['invalid_provenance:leech_final'], self::assess($records)['findings']);
        $records = self::changed('leech_final', static function (array $record): array {
            $record['server_version'] = '11.138.0.20';

            return $record;
        });
        self::assertSame(['unusable_server_version'], self::assess($records)['findings']);
    }

    public function testContractsNameThePinnedVariantAndTheSentMethod(): void
    {
        self::assertSame(['operation' => 'uapi:Mime/add_mime', 'variant' => 'uapi:Mime/add_mime#GET',
            'method' => 'POST', 'spec' => MimeSpec::ADD_MIME], MimeDirectoryEvidence::contract('add_mime_bridged'));
        self::assertSame(
            ['operation' => 'uapi:Fileman/upload_files', 'variant' => 'uapi:Fileman/upload_files#POST',
                'method' => 'POST', 'spec' => FilemanSpec::UPLOAD_FILES],
            MimeDirectoryEvidence::contract('create_directory'),
        );
        foreach (MimeDirectoryRecipe::PHASES as $phase => [, , , $method]) {
            self::assertSame($method, MimeDirectoryEvidence::contract($phase)['method'], $phase);
        }
    }

    public function testAnUnknownPhaseHasNoContract(): void
    {
        $this->expectException(LogicException::class);
        MimeDirectoryEvidence::contract('elsewhere');
    }

    /**
     * @param list<Observation> $records
     * @return Assessment
     */
    private static function assess(array $records): array
    {
        return MimeDirectoryEvidence::assess($records, MimeDirectoryFixtureTest::context());
    }

    /**
     * Return a complete run with one phase's record replaced.
     *
     * @param Closure(Observation): Observation $change
     * @return list<Observation>
     */
    private static function changed(string $phase, Closure $change): array
    {
        return array_map(
            static fn (array $record): array => $record['phase'] === $phase ? $change($record) : $record,
            self::records(),
        );
    }

    /** @return list<Observation> */
    private static function records(): array
    {
        $context = MimeDirectoryFixtureTest::context();
        $records = [];
        foreach (MimeDirectoryRecipe::PHASES as $phase => [$section, $route]) {
            $contract = MimeDirectoryEvidence::contract($phase);
            $whm = $route === 'whm';
            $records[] = [
                'phase' => $phase, 'section' => $section, 'expectation' => 'passed',
                'uapi_status' => $whm ? null : 1,
                'operation' => $contract['operation'], 'variant' => $contract['variant'],
                'route' => $route, 'method' => $contract['method'],
                'server_version' => '11.138.0.19', 'observed_at' => '2026-11-02T00:00:00Z',
                'revision' => $context['revision'],
                'source_sha256' => $context['source_hashes'][$whm ? 'whm1' : 'uapi'],
                'map_sha256' => $context['map_sha256'], 'generator_tree' => $context['generator_tree'],
                'overlay_sha256' => $context['overlay_sha256'],
                'effective_contract_sha256' => hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR)),
                'http_status' => 200, 'api_outcome' => 'success',
                'payload_validation' => in_array($phase, self::GENERIC_PHASES, true) ? 'not_checked' : 'passed',
                'probe_outcome' => 'passed', 'failure_kind' => null, 'response_paths' => [], 'payload_paths' => [],
                'discrepancies' => [],
            ];
        }

        return $records;
    }
}
