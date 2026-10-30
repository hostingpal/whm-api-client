<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\BoxTrapperSectionEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\BoxTrapperSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** @phpstan-import-type Context from \HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner */
final class BoxTrapperSectionFixtureTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
                'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testUnauthorizedFixturePerformsNoIoAndCannotBeReplayed(): void
    {
        putenv('WHM_API_LIVE=0');
        $server = new BoxTrapperSectionServer();
        $fixture = self::fixture($server);
        self::assertSame('failed', $fixture->run()['status']);
        putenv('WHM_API_LIVE=1');
        self::assertContains('fixture_already_used', $fixture->run()['failures']);
        self::assertSame([], $server->requests);
    }

    public function testTwoDisabledOwnedMailboxesReadBackSettingsAndAreCleaned(): void
    {
        $server = new BoxTrapperSectionServer();
        $report = self::fixture($server)->run();
        self::assertSame('passed', $report['status']);
        self::assertSame('passed', $report['assessment']['status']);
        self::assertCount(45, $report['probes']);
        self::assertSame(['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'], $report['resources']);
        self::assertSame(['wbta1b2c3d@npr.rs', 'wbta1b2c3b@npr.rs'], $server->deleted);
        self::assertSame([], $server->mailboxes);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private', 'wbta1b2c3', 'test.oblak.host', 'fixture-whm', 'fixture-cpanel'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        foreach ($server->requests as $request) {
            self::assertStringNotContainsString('password=', $request->getUri()->getQuery());
            self::assertStringNotContainsString('rules=', $request->getUri()->getQuery());
            if (str_contains((string) $request->getBody(), 'password=')) {
                parse_str((string) $request->getBody(), $fields);
                self::assertSame('0', $fields['send_welcome_email'] ?? null);
                self::assertIsString($fields['password']);
                self::assertStringNotContainsString($fields['password'], $json);
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function preflightFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['list_pops', 'list_mail_domains'] as $function) {
                yield $function . $route => [$function . '/' . $route . '/1'];
            }
        }
    }

    #[DataProvider('preflightFailures')]
    public function testBothRoutePreflightFailuresBlockAllWrites(string $key): void
    {
        $server = new BoxTrapperSectionServer();
        $server->failures[$key] = 'before';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertCount(5, $report['probes']);
        self::assertContains('preflight_failure', $report['failures']);
        self::assertSame([], $server->mailboxes);
        foreach ($server->requests as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function testExistingCandidateIsNeverAdoptedOrDeleted(): void
    {
        $server = new BoxTrapperSectionServer();
        $server->mailboxes['wbta1b2c3d@npr.rs'] = ['status' => 0, 'configuration' => [], 'rules' => []];
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->deleted);
        self::assertArrayHasKey('wbta1b2c3d@npr.rs', $server->mailboxes);
        self::assertCount(5, $server->requests);
    }

    /** @return iterable<string,array{string,string}> */
    public static function uncertainCreations(): iterable
    {
        foreach (['lost', 'after', 'delayed'] as $failure) {
            yield $failure => ['add_pop/direct/1', $failure];
        }
        yield 'wrong validated identity' => ['add_pop/direct/1', '"unrelated+npr.rs"'];
        yield 'malformed create payload' => ['add_pop/direct/1', 'false'];
        yield 'inventory omits created identity' => ['list_pops/direct/3', '[]'];
        yield 'inventory malformed login' => ['list_pops/direct/3', '[{}]'];
    }

    #[DataProvider('uncertainCreations')]
    public function testUncertainCreationNeverAuthorizesSettingsOrCleanup(string $key, string $failure): void
    {
        $server = new BoxTrapperSectionServer();
        if (in_array($failure, ['lost', 'after', 'delayed'], true)) {
            $server->failures[$key] = $failure;
        } else {
            $server->overrides[$key] = $failure;
        }
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('uncertain', $report['resources']['direct-uapi']);
        self::assertSame('not_started', $report['resources']['bridged-uapi']);
        self::assertSame([], $server->deleted);
        self::assertContains('unresolved_mutation', $report['failures']);
        self::assertCount(1, array_filter($server->requests, static fn ($r): bool =>
            str_contains((string) $r->getBody(), 'password=')));
        foreach ($server->requests as $request) {
            self::assertStringNotContainsString('BoxTrapper', (string) $request->getUri());
        }
        if ($failure === 'delayed') {
            self::assertSame([], $server->mailboxes);
            $server->completeDelayed();
            self::assertArrayHasKey('wbta1b2c3d@npr.rs', $server->mailboxes);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function settingsFailures(): iterable
    {
        foreach (
            ['set_status', 'save_configuration', 'set_allowlist', 'set_blocklist', 'set_ignorelist'] as $function
        ) {
            yield $function => [$function];
        }
    }

    #[DataProvider('settingsFailures')]
    public function testFailedOwnedSettingCleansUpWithoutRetry(string $function): void
    {
        $server = new BoxTrapperSectionServer();
        $server->failures[$function . '/direct/1'] = 'lost';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('cleaned', $report['resources']['direct-uapi']);
        self::assertSame([], $server->mailboxes);
        self::assertCount(2, $server->deleted);
        $observations = array_filter($report['probes'], static fn (array $r): bool =>
            $r['route'] === 'direct-uapi' && $r['operation'] === 'uapi:BoxTrapper/' . $function);
        self::assertCount(1, $observations);
    }

    public function testInitiallyEnabledMailboxNeverReceivesSettingsButIsCleaned(): void
    {
        $server = new BoxTrapperSectionServer();
        $server->overrides['get_status/direct/1'] = '1';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('cleaned', $report['resources']['direct-uapi']);
        self::assertSame([], $server->mailboxes);
        self::assertNotContains('configure', array_column(array_filter(
            $report['probes'],
            static fn (array $r): bool => $r['route'] === 'direct-uapi',
        ), 'phase'));
    }

    public function testLostCleanupIsNotRetriedAndAbsenceCannotResolveUncertainty(): void
    {
        $server = new BoxTrapperSectionServer();
        $server->failures['delete_pop/direct/1'] = 'lost';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('uncertain', $report['resources']['direct-uapi']);
        self::assertCount(1, $server->deleted);
        self::assertSame([], $server->mailboxes);
        self::assertContains('unresolved_mutation', $report['failures']);
    }

    public function testUnknownFieldsRetainOriginalDiscrepanciesAndFailStrictAssessment(): void
    {
        $server = new BoxTrapperSectionServer();
        $server->overrides['get_log/direct/1'] = '{"lines":[],"path":"","unexpected":"private"}';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('failed', $report['assessment']['status']);
        self::assertContains('unacknowledged_discrepancy', $report['assessment']['findings']);
        self::assertSame([], $server->mailboxes);
    }

    public function testDeletionWithoutConfirmedAbsenceStaysUncertain(): void
    {
        $server = new BoxTrapperSectionServer();
        $server->overrides['list_pops/direct/4'] = '[{"login":"wbta1b2c3d@npr.rs"}]';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('uncertain', $report['resources']['direct-uapi']);
        self::assertSame('not_started', $report['resources']['bridged-uapi']);
        self::assertCount(1, $server->deleted);
        self::assertContains('direct-uapi:cleanup_unconfirmed', $report['failures']);
    }

    public function testStrictAssessmentRejectsMissingDuplicateWrongContractAndProvenance(): void
    {
        $report = self::fixture(new BoxTrapperSectionServer())->run();
        $records = $report['probes'];
        array_pop($records);
        $missing = BoxTrapperSectionEvidence::finish($records, self::context());
        self::assertContains('missing_phase', $missing['assessment']['findings']);
        $records = $report['probes'];
        $records[] = $records[0];
        $duplicate = BoxTrapperSectionEvidence::finish($records, self::context());
        self::assertContains('duplicate_phase', $duplicate['assessment']['findings']);
        $records = $report['probes'];
        foreach ($records as $index => $record) {
            if ($index === 1) {
                $record['method'] = 'POST';
            } elseif ($index === 2) {
                $record['revision'] = str_repeat('0', 40);
            } elseif ($index === 3) {
                $record['source_sha256'] = str_repeat('0', 64);
            } elseif ($index === 4) {
                $record['expectation'] = 'failed';
            }
            $records[$index] = $record;
        }
        $changed = BoxTrapperSectionEvidence::finish($records, self::context());
        self::assertContains('phase_contract_mismatch', $changed['assessment']['findings']);
        self::assertContains('invalid_provenance', $changed['assessment']['findings']);
        self::assertContains('unsuccessful_phase', $changed['assessment']['findings']);
    }

    /** @return iterable<string,array{mixed}> */
    public static function malformedFingerprints(): iterable
    {
        yield 'missing' => [null];
        yield 'empty paths' => [[]];
        yield 'no root' => [['$.status' => ['int']]];
        yield 'empty root types' => [['$' => []]];
        yield 'empty type name' => [['$' => ['']]];
        yield 'null type name' => [['$' => [null]]];
        yield 'scalar type list' => [['$' => 'object']];
        yield 'associative type list' => [['$' => ['kind' => 'object']]];
        yield 'integer path' => [['$' => ['object'], 0 => ['string']]];
        yield 'invalid child types' => [['$' => ['object'], '$.data' => false]];
        yield 'non-array paths' => [false];
    }

    #[DataProvider('malformedFingerprints')]
    public function testMalformedStructuralEvidenceFailsClosedWithoutWarnings(mixed $paths): void
    {
        $report = self::fixture(new BoxTrapperSectionServer())->run();
        self::assertSame('passed', $report['status']);
        foreach (['response_paths', 'payload_paths'] as $field) {
            $records = $report['probes'];
            foreach ($records as $index => $record) {
                if ($paths === null) {
                    unset($record[$field]);
                } else {
                    $record[$field] = $paths;
                }
                $records[$index] = $record;
            }
            // Reflection deliberately sends incomplete/malformed records outside trusted capture PHPDoc.
            $result = (new ReflectionMethod(BoxTrapperSectionEvidence::class, 'finish'))
                ->invoke(null, $records, self::context());
            self::assertIsArray($result);
            self::assertIsArray($result['assessment']);
            self::assertSame('failed', $result['assessment']['status']);
            self::assertIsArray($result['assessment']['findings']);
            self::assertContains('unsuccessful_phase', $result['assessment']['findings']);
        }
    }

    public function testMissingOutcomeFieldsCannotCountAsSuccessfulStructuralEvidence(): void
    {
        $report = self::fixture(new BoxTrapperSectionServer())->run();
        foreach (
            ['probe_outcome', 'api_outcome', 'payload_validation',
                'failure_kind', 'expectation', 'http_status'] as $field
        ) {
            $records = $report['probes'];
            foreach ($records as $index => $record) {
                unset($record[$field]);
                $records[$index] = $record;
            }
            $result = (new ReflectionMethod(BoxTrapperSectionEvidence::class, 'finish'))
                ->invoke(null, $records, self::context());
            self::assertIsArray($result);
            self::assertIsArray($result['assessment']);
            self::assertSame('failed', $result['assessment']['status']);
        }
    }

    private static function fixture(BoxTrapperSectionServer $server): BoxTrapperSectionFixture
    {
        return new BoxTrapperSectionFixture(
            $server,
            ['whm' => 'fixture-whm', 'cpanel' => 'fixture-cpanel'],
            'a1b2c3',
            self::context(),
        );
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => str_repeat('a', 40), 'map_sha256' => str_repeat('b', 64),
            'generator_tree' => str_repeat('c', 40), 'overlay_sha256' => str_repeat('d', 64),
            'source_hashes' => ['whm1' => str_repeat('e', 64), 'uapi' => str_repeat('f', 64)]];
    }
}
