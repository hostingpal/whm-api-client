<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\SpamPreferenceEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\SpamPreferenceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Context from \HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner */
final class SpamPreferenceFixtureTest extends TestCase
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

    public function testExactOriginalPreferenceAndOtherValuesAreRestoredOnBothRoutes(): void
    {
        $server = new SpamPreferenceServer();
        $report = self::fixture($server)->run();
        self::assertSame('passed', $report['status']);
        self::assertCount(31, $report['probes']);
        self::assertSame(['direct-uapi' => 'restored', 'bridged-uapi' => 'restored'], $report['restoration']);
        self::assertSame(
            ['required_score' => ['5.0'], 'private_custom_name' => ['private_value']],
            $server->preferences,
        );
        self::assertSame([
            'preference=required_score&value=5.1', 'preference=required_score&value=5.0',
            'preference=required_score&value=5.1', 'preference=required_score&value=5.0',
        ], self::bodies($server));
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['5.0', '5.1', 'private', 'npr', 'test.oblak.host'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame(27, count(array_filter($server->requests, static fn ($r): bool => $r->getMethod() === 'GET')));
    }

    public function testOriginallyAbsentSettingIsRemovedRatherThanAssignedADefault(): void
    {
        $server = new SpamPreferenceServer();
        unset($server->preferences['required_score']);
        self::assertSame('passed', self::fixture($server)->run()['status']);
        self::assertSame(['private_custom_name' => ['private_value']], $server->preferences);
        self::assertSame([
            'preference=required_score&value=5.1', 'preference=required_score',
            'preference=required_score&value=5.1', 'preference=required_score',
        ], self::bodies($server));
    }

    public function testExistingTargetUsesADistinctValueAndRestoresPrecisely(): void
    {
        $server = new SpamPreferenceServer();
        $server->preferences['required_score'] = ['5.10'];
        self::assertSame('passed', self::fixture($server)->run()['status']);
        self::assertSame('preference=required_score&value=6', self::bodies($server)[0]);
        self::assertSame(['5.10'], $server->preferences['required_score']);
    }

    public function testOpaqueCustomValuesStayUnchangedAndMasked(): void
    {
        $server = new SpamPreferenceServer();
        $server->preferences['private_custom_name'] = (object) ['private_nested_name' => [true, null, 5]];
        $initial = $server->preferences;
        $report = self::fixture($server)->run();
        self::assertSame('passed', $report['status']);
        self::assertSame($initial, $server->preferences);
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function unsafePreflights(): iterable
    {
        yield 'multiple scores' => ['{"required_score":["5.0","6"]}'];
        yield 'empty scores' => ['{"required_score":[]}'];
        yield 'numeric original' => ['{"required_score":[5]}'];
        yield 'whitespace' => ['{"required_score":[" 5"]}'];
        yield 'null' => ['{"required_score":null}'];
        yield 'different original' => ['{"required_score":["6"]}'];
        yield 'other preference differs' => ['{"required_score":["5.0"],"private_custom_name":["changed"]}'];
    }

    #[DataProvider('unsafePreflights')]
    public function testUnsafeOrDisagreeingPreflightNeverMutates(string $payload): void
    {
        $server = new SpamPreferenceServer();
        $server->overrides['get_user_preferences/bridge/1'] = $payload;
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertContains('preflight_failure', $report['failures']);
        self::assertSame([], self::bodies($server));
    }

    public function testConfirmedOutcomePayloadMismatchRestoresOnceWithoutRepeatingTheSet(): void
    {
        $server = new SpamPreferenceServer();
        $server->overrides['update_user_preference/direct/1'] = '{"required_score":false}';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'restored', 'bridged-uapi' => 'not_started'], $report['restoration']);
        self::assertSame([
            'preference=required_score&value=5.1', 'preference=required_score&value=5.0',
        ], self::bodies($server));
        self::assertSame(['5.0'], $server->preferences['required_score']);
        self::assertSame('success', $report['probes'][2]['api_outcome']);
        self::assertSame('payload_mismatch', $report['probes'][2]['failure_kind']);
    }

    /** @return iterable<string,array{string,int}> */
    public static function uncertainWrites(): iterable
    {
        yield 'lost set' => ['lost', 1];
        yield 'delayed set' => ['delayed', 1];
        yield 'lost restoration' => ['lost', 2];
        yield 'delayed restoration' => ['delayed', 2];
    }

    #[DataProvider('uncertainWrites')]
    public function testTransportUncertaintyCannotBeResolvedByLaterMatchingReads(string $failure, int $occurrence): void
    {
        $server = new SpamPreferenceServer();
        $server->failures['update_user_preference/direct/' . $occurrence] = $failure;
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['restoration']);
        self::assertCount(2, self::bodies($server));
        self::assertContains('unresolved_mutation', $report['failures']);
        if ($failure === 'delayed' && $occurrence === 1) {
            self::assertSame(['5.0'], $server->preferences['required_score']);
            $server->completeDelayed();
            self::assertSame(['5.1'], $server->preferences['required_score']);
        }
    }

    public function testOtherPreferenceReadbackChangeFailsEvenWhenRequiredScoreMatches(): void
    {
        $server = new SpamPreferenceServer();
        $server->overrides['get_user_preferences/direct/2'] =
            '{"required_score":["5.1"],"private_custom_name":["changed"]}';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('failed', $report['probes'][3]['expectation']);
        self::assertSame(['5.0'], $server->preferences['required_score']);
        self::assertCount(2, self::bodies($server));
    }

    public function testRestorationRequiresBothRoutesAndAllOriginalPreferences(): void
    {
        $server = new SpamPreferenceServer();
        $server->overrides['get_user_preferences/bridge/2'] =
            '{"required_score":["5.0"],"private_custom_name":["changed"]}';
        $report = self::fixture($server)->run();
        self::assertSame('uncertain', $report['restoration']['direct-uapi']);
        self::assertSame('not_started', $report['restoration']['bridged-uapi']);
        self::assertCount(2, self::bodies($server));
    }

    public function testMissingOptInPerformsNoIoAndCannotBeReplayed(): void
    {
        putenv('WHM_API_LIVE_MUTATIONS=0');
        $server = new SpamPreferenceServer();
        $fixture = self::fixture($server);
        self::assertSame('failed', $fixture->run()['status']);
        putenv('WHM_API_LIVE_MUTATIONS=1');
        self::assertContains('fixture_already_used', $fixture->run()['failures']);
        self::assertSame([], $server->requests);
    }

    public function testEvidenceAssessmentRejectsMissingDuplicateOrMalformedObservations(): void
    {
        $report = self::fixture(new SpamPreferenceServer())->run();
        self::assertSame('passed', $report['status']);
        $records = $report['probes'];
        array_pop($records);
        self::assertSame(
            'failed',
            SpamPreferenceEvidence::finish($records, self::context())['assessment']['status'],
        );
        $records = $report['probes'];
        $records[] = $records[0];
        self::assertSame(
            'failed',
            SpamPreferenceEvidence::finish($records, self::context())['assessment']['status'],
        );
        foreach (['payload_paths', 'response_paths', 'method', 'failure_kind', 'expectation', 'revision'] as $field) {
            $records = $report['probes'];
            unset($records[3][$field]);
            self::assertSame(
                'failed',
                SpamPreferenceEvidence::finish($records, self::context())['assessment']['status'],
            );
        }
    }

    public function testCatalogUnknownFieldsDoNotEraseSuccessfulPreferenceRestoration(): void
    {
        $server = new SpamPreferenceServer();
        $server->overrides['validate_current_ptrs/direct/1'] = '[{"private_unknown":1}]';
        $server->overrides['validate_current_ptrs/bridge/1'] = '[{"private_unknown":1}]';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('passed', $report['preference_assessment']['status']);
        self::assertSame('failed', $report['catalog_assessment']['status']);
        self::assertSame(['direct-uapi' => 'restored', 'bridged-uapi' => 'restored'], $report['restoration']);
        self::assertCount(31, $report['probes']);
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testFailedVersionCannotBindPreferenceEvidenceToAnUnconfirmedVersion(): void
    {
        $report = self::fixture(new SpamPreferenceServer())->run();
        $records = $report['probes'];
        $records[12]['probe_outcome'] = 'failed';
        $result = SpamPreferenceEvidence::finish($records, self::context());
        self::assertSame('failed', $result['preference_assessment']['status']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function impreciseNumbers(): iterable
    {
        yield 'overflowing integers' => ['9223372036854775808', '9223372036854775809'];
        yield 'precise decimals' => ['0.100000000000000001', '0.100000000000000002'];
        yield 'same decoded float' => ['5.0', '5.0'];
        yield 'exponent' => ['1e0', '1e0'];
    }

    #[DataProvider('impreciseNumbers')]
    public function testImpreciseOriginalNumbersFailClosedBeforeAnyWrite(string $direct, string $bridge): void
    {
        $server = new SpamPreferenceServer();
        $server->overrides['get_user_preferences/direct/1'] =
            '{"required_score":["5.0"],"private_number":' . $direct . '}';
        $server->overrides['get_user_preferences/bridge/1'] =
            '{"required_score":["5.0"],"private_number":' . $bridge . '}';
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertContains('preflight_failure', $report['failures']);
        self::assertSame([], self::bodies($server));
    }

    private static function fixture(SpamPreferenceServer $server): SpamPreferenceFixture
    {
        return new SpamPreferenceFixture(
            $server,
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            self::context(),
        );
    }

    /** @return list<string> */
    private static function bodies(SpamPreferenceServer $server): array
    {
        return array_values(array_map(static fn ($r): string => (string) $r->getBody(), array_filter(
            $server->requests,
            static fn ($r): bool => $r->getMethod() === 'POST',
        )));
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => str_repeat('a', 40), 'map_sha256' => str_repeat('b', 64),
            'generator_tree' => str_repeat('c', 40), 'overlay_sha256' => str_repeat('d', 64),
            'source_hashes' => ['whm1' => str_repeat('e', 64), 'uapi' => str_repeat('f', 64)]];
    }
}
