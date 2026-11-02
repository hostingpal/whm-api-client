<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\LangPhpSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\LangPhpMutationEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\LangPhpMutationFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Observation from LangPhpMutationFixture
 * @phpstan-import-type Context from EvidenceRunner
 */
final class LangPhpMutationEvidenceTest extends TestCase
{
    public function testCompleteOrderedRunWithTwoRefusalsPasses(): void
    {
        $assessment = LangPhpMutationEvidence::assess(self::records(), self::context());
        self::assertSame(
            ['policy' => 'langphp-mutations-no-acknowledgments-v1', 'status' => 'passed', 'findings' => []],
            $assessment,
        );
    }

    /** The plan is written here independently: phases, routes, functions and argument labels, in order. */
    public function testPlanIsTheReviewedOrderedRecipe(): void
    {
        $plan = [];
        foreach (LangPhpMutationEvidence::PLAN as $phase => [$route, $function, $variant]) {
            $plan[] = $phase . ' ' . $route . ' ' . $function . ' ' . ($variant ?? '-');
        }
        self::assertSame([
            'version whm version -',
            'installed_direct direct-uapi php_get_installed_versions -',
            'installed_bridged bridged-uapi php_get_installed_versions -',
            'vhosts_direct direct-uapi php_get_vhost_versions all-vhosts',
            'vhosts_bridged bridged-uapi php_get_vhost_versions all-vhosts',
            'directives_direct direct-uapi php_ini_get_user_basic_directives home-scope',
            'directives_bridged bridged-uapi php_ini_get_user_basic_directives home-scope',
            'content_direct direct-uapi php_ini_get_user_content home-scope',
            'content_bridged bridged-uapi php_ini_get_user_content home-scope',
            'set_directives_direct direct-uapi php_ini_set_user_basic_directives scalar-directive',
            'read_directives_direct direct-uapi php_ini_get_user_basic_directives home-scope',
            'set_directives_bridged bridged-uapi php_ini_set_user_basic_directives numbered-directives',
            'read_directives_bridged bridged-uapi php_ini_get_user_basic_directives home-scope',
            'set_content_direct direct-uapi php_ini_set_user_content home-type',
            'read_content_direct direct-uapi php_ini_get_user_content home-scope',
            'read_content_directives_direct direct-uapi php_ini_get_user_basic_directives home-scope',
            'set_content_bridged bridged-uapi php_ini_set_user_content home-type',
            'read_content_bridged bridged-uapi php_ini_get_user_content home-scope',
            'read_content_directives_bridged bridged-uapi php_ini_get_user_basic_directives home-scope',
            'set_content_host_direct direct-uapi php_ini_set_user_content host-type',
            'read_content_host_direct direct-uapi php_ini_get_user_content home-scope',
            'set_content_host_bridged bridged-uapi php_ini_set_user_content host-type',
            'read_content_host_bridged bridged-uapi php_ini_get_user_content home-scope',
            'set_version_direct direct-uapi php_set_vhost_versions selected-vhost',
            'read_version_direct direct-uapi php_get_vhost_versions selected-vhost',
            'set_version_bridged bridged-uapi php_set_vhost_versions selected-vhost',
            'read_version_bridged bridged-uapi php_get_vhost_versions selected-vhost',
        ], $plan);
        self::assertSame(['set_content_host_direct', 'set_content_host_bridged'], LangPhpMutationEvidence::REFUSED);
    }

    public function testContractsBindTheGeneratedSpecsAndPinnedGetVariants(): void
    {
        self::assertSame(LangPhpSpec::PHP_INI_SET_USER_CONTENT, LangPhpMutationEvidence::contract(
            'set_content_host_bridged',
        )['spec']);
        self::assertSame(
            'uapi:LangPHP/php_set_vhost_versions#GET',
            LangPhpMutationEvidence::contract('set_version_direct')['variant'],
        );
        self::assertSame('POST', LangPhpMutationEvidence::contract('set_directives_bridged')['method']);
        self::assertSame('GET', LangPhpMutationEvidence::contract('read_version_bridged')['method']);
        self::assertSame(ServerSpec::VERSION, LangPhpMutationEvidence::contract('version')['spec']);
    }

    #[DataProvider('defects')]
    public function testEveryDefectIsAFinding(string $defect, string $finding): void
    {
        $assessment = LangPhpMutationEvidence::assess(self::defective($defect), self::context());
        self::assertSame('failed', $assessment['status']);
        self::assertContains($finding, $assessment['findings']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function defects(): iterable
    {
        yield 'accepted host type' => ['accepted_host', 'unexpected_outcome:set_content_host_direct'];
        yield 'refused home write' => ['refused_home', 'unexpected_outcome:set_content_bridged'];
        yield 'discrepancy' => ['discrepancy', 'unacknowledged_discrepancy:read_version_direct'];
        yield 'failed expectation' => ['expectation', 'unexpected_outcome:read_content_direct'];
        yield 'missing phase' => ['missing', 'missing_phase:set_version_bridged'];
        yield 'duplicate phase' => ['duplicate', 'duplicate_phase:content_direct'];
        yield 'unknown phase' => ['unknown', 'unexpected_phase'];
        yield 'order' => ['order', 'phase_order_mismatch'];
        yield 'contract' => ['contract', 'contract_mismatch:set_directives_direct'];
        yield 'route' => ['route', 'contract_mismatch:set_directives_direct'];
        yield 'argument variant' => ['argument', 'contract_mismatch:set_directives_bridged'];
        yield 'status' => ['status', 'unexpected_uapi_status:content_bridged'];
        yield 'provenance' => ['provenance', 'invalid_provenance:installed_direct'];
        yield 'version' => ['version', 'unusable_server_version'];
        yield 'empty run' => ['empty', 'missing_phase:version'];
    }

    /** @return list<Observation> */
    private static function defective(string $defect): array
    {
        $records = [];
        foreach (array_keys(LangPhpMutationEvidence::PLAN) as $phase) {
            $refused = in_array($phase, LangPhpMutationEvidence::REFUSED, true);
            $record = self::record($phase, match ([$defect, $phase]) {
                ['accepted_host', 'set_content_host_direct'] => false,
                ['refused_home', 'set_content_bridged'] => true,
                default => $refused,
            });
            switch ([$defect, $phase]) {
                case ['discrepancy', 'read_version_direct']:
                    $record['discrepancies'] = [['path' => '$.*', 'kind' => 'unknown_field']];
                    break;
                case ['expectation', 'read_content_direct']:
                    $record['expectation'] = 'failed';
                    break;
                case ['missing', 'set_version_bridged']:
                case ['empty', $phase]:
                    continue 2;
                case ['unknown', 'version']:
                    $record['phase'] = 'unknown';
                    break;
                case ['contract', 'set_directives_direct']:
                    $record['effective_contract_sha256'] = str_repeat('0', 64);
                    break;
                case ['route', 'set_directives_direct']:
                    $record['route'] = 'bridged-uapi';
                    break;
                case ['argument', 'set_directives_bridged']:
                    $record['argument_variant'] = 'scalar-directive';
                    break;
                case ['status', 'content_bridged']:
                    $record['uapi_status'] = 0;
                    break;
                case ['provenance', 'installed_direct']:
                    $record['revision'] = str_repeat('9', 40);
                    break;
                case ['version', 'version']:
                    $record['server_version'] = null;
                    break;
            }
            $records[] = $record;
            if ([$defect, $phase] === ['duplicate', 'content_direct']) {
                $records[] = $record;
            }
        }
        if ($defect === 'order') {
            $records = [$records[0], $records[2], $records[1], ...array_slice($records, 3)];
        }

        return $records;
    }

    /** @return list<Observation> */
    private static function records(): array
    {
        $records = [];
        foreach (array_keys(LangPhpMutationEvidence::PLAN) as $phase) {
            $records[] = self::record($phase, in_array($phase, LangPhpMutationEvidence::REFUSED, true));
        }

        return $records;
    }

    /** @return Observation */
    private static function record(string $phase, bool $refused): array
    {
        $contract = LangPhpMutationEvidence::contract($phase);
        $context = self::context();
        [$route, , $variant] = LangPhpMutationEvidence::PLAN[$phase];

        return [
            'phase' => $phase, 'expectation' => 'passed',
            'uapi_status' => $phase === 'version' ? null : ($refused ? 0 : 1),
            'operation' => $contract['operation'], 'variant' => $contract['variant'],
            ...($variant === null ? [] : ['argument_variant' => $variant]),
            'route' => $route, 'method' => $contract['method'], 'server_version' => '11.138.0.19',
            'observed_at' => '2026-11-02T00:00:00Z', 'revision' => $context['revision'],
            'source_sha256' => $context['source_hashes'][$route === 'whm' ? 'whm1' : 'uapi'],
            'map_sha256' => $context['map_sha256'], 'generator_tree' => $context['generator_tree'],
            'overlay_sha256' => $context['overlay_sha256'],
            'effective_contract_sha256' => hash('sha256', json_encode($contract['spec'], JSON_THROW_ON_ERROR)),
            'http_status' => 200, 'api_outcome' => $refused ? 'failure' : 'success',
            'payload_validation' => $refused ? 'not_checked' : 'passed',
            'probe_outcome' => $refused ? 'failed' : 'passed', 'failure_kind' => $refused ? 'api_failure' : null,
            'response_paths' => [], 'payload_paths' => [], 'discrepancies' => [],
        ];
    }

    /** @return Context */
    private static function context(): array
    {
        return LangPhpMutationFixtureTest::context();
    }
}
