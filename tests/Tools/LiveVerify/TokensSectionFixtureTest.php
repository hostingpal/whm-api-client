<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\TokensSectionEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\TokensSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** @phpstan-import-type Context from \HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner */
final class TokensSectionFixtureTest extends TestCase
{
    private const EXPIRY = 1795080067;

    private const EXISTING = ['existing' => ['create_time' => 1791912500, 'expires_at' => null, 'readonly' => 0]];

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

    public function testBothRoutesCreateRenameAndRevokeOnlyTheirOwnToken(): void
    {
        $server = new TokensSectionServer();
        $report = self::fixture($server)->run();

        self::assertSame('passed', $report['status']);
        self::assertSame(['status' => 'passed', 'findings' => []], $report['assessment']);
        self::assertSame(['direct-uapi' => 'absent', 'bridged-uapi' => 'absent'], $report['tokens']);
        self::assertSame([], $report['failures']);
        self::assertSame(self::EXISTING, $server->tokens);
        self::assertSame([
            'version',
            'preflight_direct', 'create_direct', 'listed_direct', 'rename_direct', 'renamed_direct',
            'revoke_direct', 'revoke_again_direct', 'absent_direct',
            'preflight_bridge', 'create_bridge', 'listed_bridge', 'rename_bridge', 'renamed_bridge',
            'revoke_bridge', 'revoke_again_bridge', 'absent_bridge',
        ], array_column($report['probes'], 'phase'));
        self::assertSame([
            'name=whmapiclient-live-0a1b2c-direct&expires_at=1795080067&readonly=1',
            'name=whmapiclient-live-0a1b2c-direct&new_name=whmapiclient-live-0a1b2c-direct-renamed',
            'name=whmapiclient-live-0a1b2c-direct-renamed',
            'name=whmapiclient-live-0a1b2c-direct-renamed',
            'name=whmapiclient-live-0a1b2c-bridge&expires_at=1795080067&readonly=1',
            'name=whmapiclient-live-0a1b2c-bridge&new_name=whmapiclient-live-0a1b2c-bridge-renamed',
            'name=whmapiclient-live-0a1b2c-bridge-renamed',
            'name=whmapiclient-live-0a1b2c-bridge-renamed',
        ], self::bodies($server));
        foreach ($server->requests as $request) {
            self::assertStringNotContainsString('whmapiclient', (string) $request->getUri());
        }
    }

    public function testReportHoldsStructureOnlyAndRecordsTheDigitStringExpiry(): void
    {
        $report = self::fixture(new TokensSectionServer())->run();
        $json = json_encode($report, JSON_THROW_ON_ERROR);

        $privateValues = [
            TokensSectionServer::SECRET, '0a1b2c', 'whmapiclient', 'existing', 'private', 'test.oblak.host',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame('uapi:Tokens/list', $report['probes'][3]['operation']);
        self::assertSame(['null', 'numeric-string'], $report['probes'][3]['payload_paths']['$[].expires_at']);
        self::assertSame(['null'], $report['probes'][3]['payload_paths']['$[].whitelist_ips']);
        self::assertSame(['string'], $report['probes'][2]['payload_paths']['$.token']);
        self::assertSame(['null'], $report['probes'][4]['payload_paths']['$']);
        self::assertSame(['int'], $report['probes'][6]['payload_paths']['$']);
    }

    public function testUsedFixtureAndMissingOptInSendNothing(): void
    {
        $server = new TokensSectionServer();
        $fixture = self::fixture($server);
        self::assertSame('passed', $fixture->run()['status']);
        $sent = count($server->requests);
        $second = $fixture->run();
        self::assertSame('failed', $second['status']);
        self::assertSame(['fixture_already_used'], $second['failures']);
        self::assertCount($sent, $server->requests);

        putenv('WHM_API_LIVE_MUTATIONS=0');
        $server = new TokensSectionServer();
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['configuration_failure'], $report['failures']);
        self::assertSame([], $server->requests);
    }

    public function testANameAlreadyPresentStopsBeforeAnyMutation(): void
    {
        $server = new TokensSectionServer();
        $server->tokens['whmapiclient-live-0a1b2c-direct'] = self::EXISTING['existing'];
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(['direct-uapi:phase_failure'], $report['failures']);
        self::assertSame('failed', $report['probes'][1]['expectation']);
        self::assertSame([], self::bodies($server));
        self::assertArrayHasKey('whmapiclient-live-0a1b2c-direct', $server->tokens);
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function failedPhases(): iterable
    {
        yield 'creation refused' => ['failures', 'create_full_access/direct/1', 'api', 'create_direct'];
        yield 'creation reply lost after it took effect' => ['failures', 'create_full_access/direct/1', 'lost',
            'create_direct'];
        yield 'creation without a secret' => ['overrides', 'create_full_access/direct/1',
            '{"create_time":1794993667,"whitelist_ips":null}', 'create_direct'];
        yield 'creation with an undocumented field' => ['overrides', 'create_full_access/direct/1',
            '{"create_time":1794993667,"token":"T","surprise":1}', 'create_direct'];
        yield 'list row exposing a secret' => ['overrides', 'list/direct/2',
            '[{"name":"whmapiclient-live-0a1b2c-direct","expires_at":"1795080067","readonly":1,'
                . '"has_full_access":1,"token":"T"}]', 'listed_direct'];
        yield 'listed with another expiry' => ['overrides', 'list/direct/2',
            '[{"name":"whmapiclient-live-0a1b2c-direct","expires_at":"1795080068","readonly":1,'
                . '"has_full_access":1}]', 'listed_direct'];
        yield 'listed writable' => ['overrides', 'list/direct/2',
            '[{"name":"whmapiclient-live-0a1b2c-direct","expires_at":"1795080067","readonly":0,'
                . '"has_full_access":1}]', 'listed_direct'];
        yield 'rename refused' => ['failures', 'rename/direct/1', 'api', 'rename_direct'];
        yield 'rename returning data' => ['overrides', 'rename/direct/1', '{"renamed":1}', 'rename_direct'];
        yield 'rename reply lost after it took effect' => ['failures', 'rename/direct/1', 'lost', 'rename_direct'];
        yield 'old name still listed after rename' => ['overrides', 'list/direct/3',
            '[{"name":"whmapiclient-live-0a1b2c-direct","expires_at":"1795080067","readonly":1,'
                . '"has_full_access":1}]', 'renamed_direct'];
        yield 'revoke reporting nothing removed' => ['overrides', 'revoke/direct/1', '0', 'revoke_direct'];
        yield 'revoke reply lost after it took effect' => ['failures', 'revoke/direct/1', 'lost', 'revoke_direct'];
        yield 'second revoke reporting a removal' => ['overrides', 'revoke/direct/2', '1', 'revoke_again_direct'];
        yield 'token still listed after revoke' => ['overrides', 'list/direct/4',
            '[{"name":"whmapiclient-live-0a1b2c-direct-renamed"}]', 'absent_direct'];
    }

    #[DataProvider('failedPhases')]
    public function testAFailedPhaseCleansItsRouteUpAndNeverStartsTheNext(
        string $kind,
        string $key,
        string $value,
        string $phase,
    ): void {
        $server = new TokensSectionServer();
        if ($kind === 'failures') {
            $server->failures[$key] = $value;
        } else {
            $server->overrides[$key] = $value;
        }
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi:phase_failure'], $report['failures']);
        // Cleanup revoked both names and a later list confirmed that neither is left.
        self::assertSame(['direct-uapi' => 'absent', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(self::EXISTING, $server->tokens);
        $phases = array_column($report['probes'], 'phase');
        self::assertSame(
            [$phase, 'cleanup_original_direct', 'cleanup_renamed_direct', 'cleanup_check_direct'],
            array_slice($phases, -4),
        );
        self::assertSame('failed', $report['assessment']['status']);
        self::assertContains('unexpected_phase', $report['assessment']['findings']);
        self::assertStringNotContainsString(TokensSectionServer::SECRET, json_encode($report, JSON_THROW_ON_ERROR));
        // The bridged route was never reached: only the WHM version request went to the WHM port.
        foreach ($server->requests as $request) {
            self::assertStringNotContainsString('uapi_cpanel', (string) $request->getUri());
        }
    }

    public function testUnconfirmedCleanupLeavesTheTokenStateUncertain(): void
    {
        $server = new TokensSectionServer();
        $server->failures['revoke/direct/1'] = 'api';
        $server->failures['revoke/direct/2'] = 'api';
        $server->failures['revoke/direct/3'] = 'api';
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(
            ['direct-uapi:phase_failure', 'direct-uapi:cleanup_failure', 'direct-uapi:cleanup_unconfirmed'],
            $report['failures'],
        );
        self::assertArrayHasKey('whmapiclient-live-0a1b2c-direct-renamed', $server->tokens);
    }

    public function testASecondRouteFailureKeepsTheFirstRoutesPassingEvidence(): void
    {
        $server = new TokensSectionServer();
        $server->overrides['revoke/bridge/1'] = '0';
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['bridged-uapi:phase_failure'], $report['failures']);
        self::assertSame(['direct-uapi' => 'absent', 'bridged-uapi' => 'absent'], $report['tokens']);
        self::assertSame(self::EXISTING, $server->tokens);
        self::assertSame('passed', $report['probes'][8]['expectation']);
        self::assertSame('failed', $report['probes'][14]['expectation']);
    }

    public function testAssessmentFailsClosedOnAlteredEvidence(): void
    {
        $report = self::fixture(new TokensSectionServer())->run();
        $records = $report['probes'];
        self::assertSame('passed', TokensSectionEvidence::finish($records, self::context())['status']);

        $cases = [
            'missing_phase' => array_slice($records, 0, 16),
            'duplicate_phase' => [...$records, $records[16]],
            'phase_order_mismatch' => [$records[0], $records[2], $records[1], ...array_slice($records, 3)],
            'unsuccessful_phase' => array_replace($records, [5 => ['expectation' => 'failed'] + $records[5]]),
            'unacknowledged_discrepancy' => array_replace($records, [
                3 => ['discrepancies' => [['path' => '$[].x', 'kind' => 'unknown_field']]] + $records[3],
            ]),
        ];
        foreach ($cases as $finding => $altered) {
            $assessment = TokensSectionEvidence::finish($altered, self::context());
            self::assertSame('failed', $assessment['status'], $finding);
            self::assertContains($finding, $assessment['findings']);
        }
        $other = self::context();
        $other['overlay_sha256'] = str_repeat('0', 64);
        self::assertSame('failed', TokensSectionEvidence::finish($records, $other)['status']);
        self::assertSame('failed', TokensSectionEvidence::finish([], self::context())['status']);
    }

    private static function fixture(TokensSectionServer $server): TokensSectionFixture
    {
        return new TokensSectionFixture(
            $server,
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            '0a1b2c',
            self::EXPIRY,
            self::context(),
        );
    }

    /** @return list<string> */
    private static function bodies(TokensSectionServer $server): array
    {
        return array_values(array_map(
            static fn (RequestInterface $request): string => (string) $request->getBody(),
            array_filter(
                $server->requests,
                static fn (RequestInterface $request): bool => $request->getMethod() === 'POST',
            ),
        ));
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => str_repeat('a', 40), 'map_sha256' => str_repeat('b', 64),
            'generator_tree' => str_repeat('c', 40), 'overlay_sha256' => str_repeat('d', 64),
            'source_hashes' => ['whm1' => str_repeat('e', 64), 'uapi' => str_repeat('f', 64)]];
    }
}
