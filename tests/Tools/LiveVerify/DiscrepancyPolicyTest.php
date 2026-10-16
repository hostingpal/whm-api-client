<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DiscrepancyPolicy;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Fingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-type Discrepancy array{path: string, kind: string}
 * @phpstan-type ScopeChanges array{
 *     operation?: string, variant?: string, route?: string, method?: string|null, server_version?: string|null,
 *     source_sha256?: string, effective_contract_sha256?: string
 * }
 * @phpstan-type OutcomeChanges array{
 *     probe_outcome?: string, api_outcome?: string, payload_validation?: string,
 *     failure_kind?: string|null, http_status?: int|null
 * }
 */
final class DiscrepancyPolicyTest extends TestCase
{
    private const EVIDENCE = 'docs/api/evidence/2026-10-16-quota-inventory.json';
    private const EVIDENCE_HASH = '5f65d66acc36673ee37be1b4e024dad3ac725e5313d45c208abb489d5c9f6811';
    private const DIRECT = 'quota-total-direct-11.138.0.15';
    private const BRIDGE = 'quota-total-bridge-11.138.0.15';

    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot === null) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->fixtureRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->fixtureRoot);
    }

    public function testUnchangedSuccessfulQuotaObservationsAreAcknowledged(): void
    {
        $policy = DiscrepancyPolicy::load(dirname(__DIR__, 3));

        $assessment = $policy->assess(self::quotaRecords());

        self::assertSame('passed', $assessment['status']);
        self::assertEqualsCanonicalizing([
            'quota-total-direct-11.138.0.15',
            'quota-total-bridge-11.138.0.15',
        ], $assessment['acknowledged']);
        self::assertSame([], $assessment['unexpected']);
        self::assertSame([], $assessment['stale']);
        self::assertSame('quota-structural-observations-v1', $assessment['policy']);
        self::assertSame('whm-api-client-8x0.4.3.1', $assessment['tracking']);
        self::assertSame(self::EVIDENCE, $assessment['evidence']);
        self::assertSame(self::EVIDENCE_HASH, $assessment['evidence_sha256']);
    }

    public function testAcknowledgementDoesNotRemoveTheOriginalDiscrepancyEvidence(): void
    {
        $records = self::quotaRecords();
        $original = $records;

        DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame($original, $records);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $records[0]['discrepancies']);
        self::assertSame(['int'], $records[0]['payload_paths']['$.*']);
    }

    public function testAdditionalSuccessfulCleanProbesDoNotNeedAcknowledgements(): void
    {
        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess(self::savedRecords());

        self::assertSame('passed', $assessment['status']);
        self::assertEqualsCanonicalizing([self::DIRECT, self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([], $assessment['unexpected']);
        self::assertSame([], $assessment['stale']);
    }

    public function testANewDiscrepancyCannotBorrowAnotherOperationsAcknowledgement(): void
    {
        $records = self::savedRecords();
        $records[0]['discrepancies'] = [['path' => '$.*', 'kind' => 'unknown_field']];
        $records[0]['payload_paths']['$.*'] = ['int'];

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('failed', $assessment['status']);
        self::assertEqualsCanonicalizing([self::DIRECT, self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([], $assessment['stale']);
        self::assertContains([
            'operation' => 'whm1:version', 'variant' => 'whm1:version#GET',
            'route' => 'whm', 'reason' => 'unacknowledged_discrepancy',
        ], $assessment['unexpected']);
    }

    public function testObservationOrderingDoesNotChangeTheAssessment(): void
    {
        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess(array_reverse(self::quotaRecords()));

        self::assertSame('passed', $assessment['status']);
        self::assertEqualsCanonicalizing([self::DIRECT, self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([], $assessment['unexpected']);
        self::assertSame([], $assessment['stale']);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function missingRoutes(): iterable
    {
        yield 'missing direct observation' => [0, self::DIRECT, self::BRIDGE];
        yield 'missing bridge observation' => [1, self::BRIDGE, self::DIRECT];
    }

    #[DataProvider('missingRoutes')]
    public function testMissingQuotaObservationMakesItsAcknowledgementStale(
        int $missing,
        string $stale,
        string $acknowledged,
    ): void {
        $records = self::quotaRecords();
        unset($records[$missing]);

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess(array_values($records));

        self::assertSame('failed', $assessment['status']);
        self::assertSame([$acknowledged], $assessment['acknowledged']);
        self::assertSame([$stale], $assessment['stale']);
        self::assertSame([], $assessment['unexpected']);
    }

    public function testEmptyRunFailsWithBothAcknowledgementsStale(): void
    {
        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess([]);

        self::assertSame('failed', $assessment['status']);
        self::assertSame([], $assessment['acknowledged']);
        self::assertEqualsCanonicalizing([self::DIRECT, self::BRIDGE], $assessment['stale']);
        self::assertContains('empty_run', array_column($assessment['unexpected'], 'reason'));
    }

    /** @return iterable<string, array{ScopeChanges}> */
    public static function scopeChanges(): iterable
    {
        yield 'different operation' => [['operation' => 'uapi:Quota/get_local_quota_info']];
        yield 'different variant' => [['variant' => 'uapi:Quota/get_quota_info#POST']];
        yield 'different route' => [['route' => 'whm']];
        yield 'different method' => [['method' => 'POST']];
        yield 'missing method' => [['method' => null]];
        yield 'different server version' => [['server_version' => '11.138.0.16']];
        yield 'missing server version' => [['server_version' => null]];
        yield 'different source snapshot' => [['source_sha256' => str_repeat('a', 64)]];
        yield 'different effective contract' => [['effective_contract_sha256' => str_repeat('b', 64)]];
    }

    /** @param ScopeChanges $changes */
    #[DataProvider('scopeChanges')]
    public function testAcknowledgementDoesNotTransferToAnotherScopeOrContract(array $changes): void
    {
        $records = self::quotaRecords();
        $changed = array_replace($records[0], $changes);
        $records[0] = $changed;

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('failed', $assessment['status']);
        self::assertSame([self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([self::DIRECT], $assessment['stale']);
        self::assertContains([
            'operation' => $changed['operation'], 'variant' => $changed['variant'], 'route' => $changed['route'],
            'reason' => 'unacknowledged_discrepancy',
        ], $assessment['unexpected']);
    }

    /** @return iterable<string, array{string|null, list<string>|null, list<Discrepancy>|null}> */
    public static function changedStructures(): iterable
    {
        yield 'unknown field changes type' => ['$.*', ['float'], null];
        yield 'known field changes wire type' => ['$.inodes_used', ['numeric-string'], null];
        yield 'known field disappears' => ['$.inodes_used', null, null];
        yield 'new nested path' => ['$.*.*', ['int'], null];
        yield 'additional type at existing path' => ['$.*', ['int', 'null'], null];
        yield 'different discrepancy path' => [null, null, [['path' => '$.*.*', 'kind' => 'unknown_field']]];
        yield 'different discrepancy kind' => [null, null, [['path' => '$.*', 'kind' => 'type_mismatch']]];
        yield 'additional discrepancy' => [null, null, [
            ['path' => '$.*', 'kind' => 'unknown_field'],
            ['path' => '$.inodes_used', 'kind' => 'type_mismatch'],
        ]];
    }

    /**
     * @param list<string>|null $types
     * @param list<Discrepancy>|null $discrepancies
     */
    #[DataProvider('changedStructures')]
    public function testTheWholePayloadShapeAndEveryDiscrepancyMustStillMatch(
        ?string $path,
        ?array $types,
        ?array $discrepancies,
    ): void {
        $records = self::quotaRecords();
        if ($path !== null) {
            if ($types === null) {
                unset($records[0]['payload_paths'][$path]);
            } else {
                $records[0]['payload_paths'][$path] = $types;
            }
        }
        if ($discrepancies !== null) {
            $records[0]['discrepancies'] = $discrepancies;
        }

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('failed', $assessment['status']);
        self::assertSame([self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([self::DIRECT], $assessment['stale']);
        self::assertContains([
            'operation' => 'uapi:Quota/get_quota_info', 'variant' => 'uapi:Quota/get_quota_info#GET',
            'route' => 'direct-uapi', 'reason' => 'unacknowledged_discrepancy',
        ], $assessment['unexpected']);
    }

    public function testResolvedDiscrepancyLeavesTheHistoricalAcknowledgementStale(): void
    {
        $records = self::quotaRecords();
        $records[0]['discrepancies'] = [];
        unset($records[0]['payload_paths']['$.*']);

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('failed', $assessment['status']);
        self::assertSame([self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([self::DIRECT], $assessment['stale']);
        self::assertSame([], $assessment['unexpected']);
    }

    /** @return iterable<string, array{OutcomeChanges}> */
    public static function unsuccessfulStates(): iterable
    {
        yield 'failed probe' => [['probe_outcome' => 'failed']];
        yield 'skipped probe' => [['probe_outcome' => 'skipped']];
        yield 'unsupported probe' => [['probe_outcome' => 'unsupported']];
        yield 'failed API' => [['api_outcome' => 'failure']];
        yield 'unavailable API outcome' => [['api_outcome' => 'unavailable']];
        yield 'failed payload validation' => [['payload_validation' => 'failed']];
        yield 'unchecked payload' => [['payload_validation' => 'not_checked']];
        yield 'non-null failure kind' => [['failure_kind' => 'request_failure']];
        yield 'missing HTTP status' => [['http_status' => null]];
        yield 'informational HTTP status' => [['http_status' => 199]];
        yield 'redirect HTTP status' => [['http_status' => 300]];
        yield 'HTTP server error' => [['http_status' => 503]];
    }

    /** @param OutcomeChanges $changes */
    #[DataProvider('unsuccessfulStates')]
    public function testMatchingDiscrepanciesNeverOverrideAnUnsuccessfulProbe(array $changes): void
    {
        $records = self::quotaRecords();
        $failed = array_replace($records[0], $changes);
        $records[0] = $failed;

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('failed', $assessment['status']);
        self::assertSame([self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([self::DIRECT], $assessment['stale']);
        self::assertContains([
            'operation' => 'uapi:Quota/get_quota_info', 'variant' => 'uapi:Quota/get_quota_info#GET',
            'route' => 'direct-uapi', 'reason' => 'unsuccessful_probe',
        ], $assessment['unexpected']);
    }

    public function testAnUnsuccessfulCleanProbeAlsoFailsTheRun(): void
    {
        $records = self::savedRecords();
        $records[0]['probe_outcome'] = 'failed';
        $records[0]['failure_kind'] = 'request_failure';

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('failed', $assessment['status']);
        self::assertEqualsCanonicalizing([self::DIRECT, self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([], $assessment['stale']);
        self::assertContains([
            'operation' => 'whm1:version', 'variant' => 'whm1:version#GET',
            'route' => 'whm', 'reason' => 'unsuccessful_probe',
        ], $assessment['unexpected']);
    }

    /** @return iterable<string, array{bool}> */
    public static function duplicateTypes(): iterable
    {
        yield 'duplicate acknowledged quota probe' => [true];
        yield 'duplicate clean version probe' => [false];
    }

    #[DataProvider('duplicateTypes')]
    public function testEveryOperationVariantAndRouteMustAppearOnlyOnce(bool $quota): void
    {
        $records = self::quotaRecords();
        $duplicate = $quota ? $records[0] : self::savedRecords()[0];
        if (!$quota) {
            $records[] = $duplicate;
        }
        $records[] = $duplicate;

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('failed', $assessment['status']);
        self::assertEqualsCanonicalizing(
            $quota ? [self::BRIDGE] : [self::DIRECT, self::BRIDGE],
            $assessment['acknowledged'],
        );
        self::assertSame($quota ? [self::DIRECT] : [], $assessment['stale']);
        self::assertContains([
            'operation' => $duplicate['operation'], 'variant' => $duplicate['variant'], 'route' => $duplicate['route'],
            'reason' => 'duplicate_probe',
        ], $assessment['unexpected']);
    }

    public function testUnrelatedBuildProvenanceAndObservationTimeDoNotInvalidateTheRelevantContract(): void
    {
        $records = self::quotaRecords();
        foreach ($records as &$record) {
            $record['observed_at'] = '2026-10-17T12:00:00Z';
            $record['revision'] = str_repeat('a', 40);
            $record['generator_tree'] = str_repeat('b', 40);
            $record['overlay_sha256'] = str_repeat('c', 64);
            $record['map_sha256'] = str_repeat('d', 64);
        }
        unset($record);
        $records[0]['http_status'] = 201;
        $records[1]['http_status'] = 299;

        $assessment = DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records);

        self::assertSame('passed', $assessment['status']);
        self::assertEqualsCanonicalizing([self::DIRECT, self::BRIDGE], $assessment['acknowledged']);
        self::assertSame([], $assessment['unexpected']);
        self::assertSame([], $assessment['stale']);
    }

    public function testUnknownNamesAndValuesWithTheSameWildcardTypeRemainIndistinguishable(): void
    {
        $expected = [
            'paths' => ['$' => ['object'], '$.*' => ['int']],
            'discrepancies' => [['path' => '$.*', 'kind' => 'unknown_field']],
        ];
        foreach ([(object) ['private-one' => 1], (object) ['private-two' => 99, 'private-three' => 0]] as $payload) {
            $analysis = Fingerprint::analyze($payload, true, ['type' => 'object', 'fields' => []]);
            self::assertSame($expected, $analysis);
            $records = self::quotaRecords();
            $records[0]['payload_paths']['$.*'] = $analysis['paths']['$.*'];
            $records[0]['discrepancies'] = $analysis['discrepancies'];

            self::assertSame('passed', DiscrepancyPolicy::load(dirname(__DIR__, 3))->assess($records)['status']);
        }
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidSources(): iterable
    {
        yield 'missing immutable evidence' => [null];
        yield 'malformed evidence' => ['private-source-content'];
        yield 'valid JSON with a different hash' => ['{"schema_version":1,"probes":[]}'];
    }

    #[DataProvider('invalidSources')]
    public function testMissingOrModifiedEvidenceFailsWithoutLeakingSourcePathsOrContents(?string $contents): void
    {
        $root = $this->fixture();
        if ($contents === null) {
            unlink($root . '/' . self::EVIDENCE);
        } else {
            file_put_contents($root . '/' . self::EVIDENCE, $contents);
        }

        try {
            DiscrepancyPolicy::load($root);
            self::fail('The hardcoded evidence hash must be verified before assessing records.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString($root, $error->getMessage());
            self::assertStringNotContainsString('private-source-content', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function protectedTargets(): iterable
    {
        yield 'exact source path' => ['source'];
        yield 'normalized source path' => ['normalized'];
        yield 'source file symlink' => ['symlink'];
        yield 'source directory symlink' => ['directory-symlink'];
        yield 'source hardlink' => ['hardlink'];
    }

    #[DataProvider('protectedTargets')]
    public function testEvidenceOutputCannotOverwriteTheImmutableSourceThroughAliases(string $kind): void
    {
        $root = $this->fixture();
        $source = $root . '/' . self::EVIDENCE;
        $target = $source;
        if ($kind === 'normalized') {
            $target = $root . '/docs/api/evidence/../evidence/2026-10-16-quota-inventory.json';
        } elseif ($kind === 'symlink') {
            $target = $root . '/output-private-alias.json';
            symlink($source, $target);
        } elseif ($kind === 'directory-symlink') {
            symlink($root . '/docs/api/evidence', $root . '/private-directory-alias');
            $target = $root . '/private-directory-alias/2026-10-16-quota-inventory.json';
        } elseif ($kind === 'hardlink') {
            $target = $root . '/output-private-alias.json';
            link($source, $target);
        }
        $policy = DiscrepancyPolicy::load($root);

        try {
            $policy->assertOutputAllowed($target);
            self::fail('An output alias must not replace the immutable baseline.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString($root, $error->getMessage());
            self::assertStringNotContainsString('private-alias', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(self::EVIDENCE_HASH, hash_file('sha256', $source));
    }

    public function testNewOutputAndIndependentExistingCopiesAreAllowed(): void
    {
        $root = $this->fixture();
        $existing = $root . '/previous-output.json';
        copy($root . '/' . self::EVIDENCE, $existing);
        $policy = DiscrepancyPolicy::load($root);

        $policy->assertOutputAllowed($root . '/new-output.json');
        $policy->assertOutputAllowed($existing);

        self::assertFileDoesNotExist($root . '/new-output.json');
        self::assertSame(self::EVIDENCE_HASH, hash_file('sha256', $existing));
    }

    private function fixture(): string
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/whm-policy-private-' . bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot . '/docs/api/evidence', 0700, true);
        copy(dirname(__DIR__, 3) . '/' . self::EVIDENCE, $this->fixtureRoot . '/' . self::EVIDENCE);

        return $this->fixtureRoot;
    }

    /** @return array{Record, Record} */
    private static function quotaRecords(): array
    {
        $records = array_values(array_filter(
            self::savedRecords(),
            static fn (array $record): bool => $record['operation'] === 'uapi:Quota/get_quota_info',
        ));
        self::assertSame(['direct-uapi', 'bridged-uapi'], array_column($records, 'route'));

        return [$records[0], $records[1]];
    }

    /** @return non-empty-list<Record> */
    private static function savedRecords(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/' . self::EVIDENCE);
        self::assertIsString($raw);
        /** @var array{probes: list<Record>} $document */
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($document['probes'] === []) {
            throw new RuntimeException('Saved evidence fixture must contain records.');
        }

        return $document['probes'];
    }
}
