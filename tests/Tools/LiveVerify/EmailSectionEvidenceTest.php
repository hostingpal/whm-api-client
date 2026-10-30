<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EmailSectionEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\EmailSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Context from EmailSectionFixture
 * @phpstan-import-type Observation from EmailSectionFixture
 */
final class EmailSectionEvidenceTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function corruptions(): iterable
    {
        yield 'missing phase' => ['missing', 'missing_phase'];
        yield 'duplicate phase' => ['duplicate', 'duplicate_phase'];
        yield 'unexpected phase' => ['unexpected', 'unexpected_phase'];
        yield 'phase ordering' => ['order', 'phase_order_mismatch'];
        yield 'API failure' => ['api', 'unsuccessful_phase'];
        yield 'HTTP failure' => ['http', 'unsuccessful_phase'];
        yield 'payload failure' => ['payload', 'unsuccessful_phase'];
        yield 'semantic mismatch' => ['expectation', 'unsuccessful_phase'];
        yield 'unknown field discrepancy' => ['discrepancy', 'unacknowledged_discrepancy'];
        yield 'changed actual method' => ['method', 'phase_contract_mismatch'];
        yield 'wrong operation variant' => ['variant', 'phase_contract_mismatch'];
        yield 'wrong effective hash' => ['contract', 'phase_contract_mismatch'];
        yield 'changed revision' => ['revision', 'invalid_provenance'];
        yield 'changed source hash' => ['source', 'invalid_provenance'];
        yield 'missing version' => ['version', 'unusable_server_version'];
    }

    #[DataProvider('corruptions')]
    public function testStrictAssessmentRejectsEachIndependentCorruption(string $corruption, string $finding): void
    {
        $context = self::context();
        $environment = [];
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'] as $key => $value
        ) {
            $environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            $report = (new EmailSectionFixture(
                new EmailSectionServer(),
                ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
                'a1b2c3',
                $context,
            ))->run();
        } finally {
            foreach ($environment as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
        self::assertSame('passed', $report['status']);
        $records = $report['probes'];
        self::assertArrayHasKey(0, $records);
        self::assertArrayHasKey(1, $records);
        self::assertArrayHasKey(2, $records);
        switch ($corruption) {
            case 'missing':
                array_pop($records);
                break;
            case 'duplicate':
                $records[] = $records[1];
                break;
            case 'unexpected':
                $records[1] = [...$records[1], 'phase' => 'dispatch_client_settings'];
                break;
            case 'order':
                [$records[1], $records[2]] = [$records[2], $records[1]];
                break;
            case 'api':
                $records[1] = [...$records[1], 'api_outcome' => 'failure'];
                break;
            case 'http':
                $records[1] = [...$records[1], 'http_status' => 500];
                break;
            case 'payload':
                $records[1] = [...$records[1], 'payload_validation' => 'failed'];
                break;
            case 'expectation':
                $records[1] = [...$records[1], 'expectation' => 'failed'];
                break;
            case 'discrepancy':
                $records[1] = [...$records[1], 'discrepancies' => [
                    ...$records[1]['discrepancies'], ['path' => '$[].*', 'kind' => 'unknown_field'],
                ]];
                break;
            case 'method':
                $records[1] = [...$records[1], 'method' => 'POST'];
                break;
            case 'variant':
                $records[1] = [...$records[1], 'variant' => 'uapi:Email/list_pops#POST'];
                break;
            case 'contract':
                $records[1] = [...$records[1], 'effective_contract_sha256' => str_repeat('0', 64)];
                break;
            case 'revision':
                $records[1] = [...$records[1], 'revision' => str_repeat('0', 40)];
                break;
            case 'source':
                $records[1] = [...$records[1], 'source_sha256' => str_repeat('0', 64)];
                break;
            case 'version':
                $records[0] = [...$records[0], 'server_version' => null];
                break;
        }
        $assessment = EmailSectionEvidence::finish($records, $context)['assessment'];
        self::assertSame('failed', $assessment['status']);
        self::assertContains($finding, $assessment['findings']);
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64)];
    }
}
