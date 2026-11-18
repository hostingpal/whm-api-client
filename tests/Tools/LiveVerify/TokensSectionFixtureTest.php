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

    private const ORIGINAL = 'whmapiclient-live-0a1b2c-direct';

    private const RENAMED = 'whmapiclient-live-0a1b2c-direct-renamed';

    private const CREATE = 'name=whmapiclient-live-0a1b2c-direct&expires_at=1795080067&readonly=1';

    private const RENAME = 'name=whmapiclient-live-0a1b2c-direct&new_name=whmapiclient-live-0a1b2c-direct-renamed';

    /** A list row carrying the run's first name with exactly the settings the run creates its token with. */
    private const SETTLED_ROW = '{"name":"whmapiclient-live-0a1b2c-direct","expires_at":"1795080067","readonly":1,'
        . '"has_full_access":1}';

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

        self::assertSame('tokens-lifecycle-v4', $report['recipe']);
        self::assertSame('passed', $report['status']);
        self::assertSame(['status' => 'passed', 'findings' => []], $report['assessment']);
        self::assertSame(['direct-uapi' => 'absent', 'bridged-uapi' => 'absent'], $report['tokens']);
        self::assertSame(
            ['direct-uapi' => 'none_configured', 'bridged-uapi' => 'none_configured'],
            $report['notification_targets'],
        );
        self::assertSame([], $report['failures']);
        self::assertSame(self::EXISTING, $server->tokens);
        self::assertSame([
            'version',
            'preflight_direct', 'targets_direct', 'create_direct', 'listed_direct', 'rename_direct',
            'renamed_direct', 'revoke_direct', 'absent_direct',
            'preflight_bridge', 'targets_bridge', 'create_bridge', 'listed_bridge', 'rename_bridge',
            'renamed_bridge', 'revoke_bridge', 'absent_bridge',
        ], array_column($report['probes'], 'phase'));
        // Each name is revoked exactly once: a second revoke would target a name the run no longer holds.
        self::assertSame([
            self::CREATE,
            self::RENAME,
            'name=' . self::RENAMED,
            'name=whmapiclient-live-0a1b2c-bridge&expires_at=1795080067&readonly=1',
            'name=whmapiclient-live-0a1b2c-bridge&new_name=whmapiclient-live-0a1b2c-bridge-renamed',
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
        self::assertSame('uapi:Variables/get_user_information', $report['probes'][2]['operation']);
        self::assertSame('uapi:Tokens/list', $report['probes'][4]['operation']);
        self::assertSame(['null', 'numeric-string'], $report['probes'][4]['payload_paths']['$[].expires_at']);
        self::assertSame(['null'], $report['probes'][4]['payload_paths']['$[].whitelist_ips']);
        self::assertSame(['string'], $report['probes'][3]['payload_paths']['$.token']);
        self::assertSame(['null'], $report['probes'][5]['payload_paths']['$']);
        self::assertSame(['int'], $report['probes'][7]['payload_paths']['$']);
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
        self::assertSame(
            ['direct-uapi' => 'not_checked', 'bridged-uapi' => 'not_checked'],
            $report['notification_targets'],
        );
        self::assertSame([], $server->requests);
    }

    /** @return iterable<string,array{string}> */
    public static function unusablePreflightLists(): iterable
    {
        yield 'the run name already present' => ['[' . self::SETTLED_ROW . ']'];
        yield 'a row without a name' => ['[{}]'];
        yield 'a nameless row beside a named one' => ['[{"name":"existing"},{"create_time":1}]'];
        yield 'a row with an empty name' => ['[{"name":""}]'];
        yield 'a row with a non-string name' => ['[{"name":7}]'];
        yield 'a row that is not an object' => ['["existing"]'];
        yield 'a row exposing a secret' => ['[{"name":"existing","token":"T"}]'];
    }

    #[DataProvider('unusablePreflightLists')]
    public function testAListThatCannotProveAbsenceStopsBeforeAnyMutation(string $list): void
    {
        $server = new TokensSectionServer();
        $server->overrides['list/direct/1'] = $list;
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(['direct-uapi:phase_failure'], $report['failures']);
        self::assertSame(['version', 'preflight_direct'], array_column($report['probes'], 'phase'));
        self::assertSame([], self::bodies($server));
        self::assertSame(self::EXISTING, $server->tokens);
    }

    /** @return iterable<string,array{array<string,mixed>|null}> */
    public static function notificationTargets(): iterable
    {
        $none = ['contact_email' => '', 'contact_email_2' => '', 'pushbullet_access_token' => ''];
        yield 'a contact address' => [['contact_email' => 'owner@example.test'] + $none];
        yield 'a second contact address' => [['contact_email_2' => 'owner@example.test'] + $none];
        yield 'a Pushbullet token' => [['pushbullet_access_token' => 'o.private'] + $none];
        yield 'an unreported contact address' => [['contact_email_2' => '', 'pushbullet_access_token' => '']];
        yield 'an unreported Pushbullet token' => [['contact_email' => null, 'contact_email_2' => null]];
        yield 'a refused read' => [null];
    }

    /** @param array<string,mixed>|null $contact */
    #[DataProvider('notificationTargets')]
    public function testANotificationTargetOrAnUnknownOneStopsBeforeTheFirstCreate(?array $contact): void
    {
        $server = new TokensSectionServer();
        if ($contact === null) {
            $server->failures['get_user_information/direct/1'] = 'api';
        } else {
            $server->contact = $contact;
        }
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(
            ['direct-uapi' => 'not_established', 'bridged-uapi' => 'not_checked'],
            $report['notification_targets'],
        );
        self::assertSame(['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(['direct-uapi:phase_failure'], $report['failures']);
        self::assertSame(['version', 'preflight_direct', 'targets_direct'], array_column($report['probes'], 'phase'));
        self::assertSame([], self::bodies($server));
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('owner@example.test', $json);
        self::assertStringNotContainsString('o.private', $json);
    }

    public function testNullNotificationTargetsCountAsNoneConfigured(): void
    {
        $server = new TokensSectionServer();
        $server->contact = ['contact_email' => null, 'contact_email_2' => null, 'pushbullet_access_token' => ''];

        self::assertSame('passed', self::fixture($server)->run()['status']);
    }

    public function testARefusedCreationIsNotCleanedUpBecauseTheNameIsSomeoneElses(): void
    {
        $server = new TokensSectionServer();
        // Another party creates the run's first name after the preflight list and before the run's create.
        $server->arrivals['create_full_access/direct/1'] = self::ORIGINAL;
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'not_created', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(['direct-uapi:phase_failure'], $report['failures']);
        self::assertSame('create_direct', array_column($report['probes'], 'phase')[3]);
        self::assertCount(4, $report['probes']);
        // The only mutation sent is the refused create: the other party's token is never revoked.
        self::assertSame([self::CREATE], self::bodies($server));
        self::assertSame(self::EXISTING + [self::ORIGINAL => TokensSectionServer::FOREIGN], $server->tokens);
    }

    public function testARefusedRenameLeavesTheOccupiedTargetNameAlone(): void
    {
        $server = new TokensSectionServer();
        // Another party creates the rename target after the preflight list and before the run's rename.
        $server->arrivals['rename/direct/1'] = self::RENAMED;
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi:phase_failure'], $report['failures']);
        // The run's own token, still under its first name, is revoked and confirmed gone.
        self::assertSame(['direct-uapi' => 'absent', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(
            ['rename_direct', 'cleanup_inspect_direct', 'cleanup_original_direct', 'cleanup_check_direct'],
            array_slice(array_column($report['probes'], 'phase'), -4),
        );
        self::assertSame([self::CREATE, self::RENAME, 'name=' . self::ORIGINAL], self::bodies($server));
        self::assertSame(self::EXISTING + [self::RENAMED => TokensSectionServer::FOREIGN], $server->tokens);
    }

    public function testACreationThatNeverShowedItselfStaysUncertainAndCanStillTakeEffect(): void
    {
        $server = new TokensSectionServer();
        $server->failures['create_full_access/direct/1'] = 'delayed';
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        // Both cleanup lists showed no token, yet that cannot settle a request that may still complete.
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(
            ['direct-uapi:phase_failure', 'direct-uapi:creation_outcome_unknown'],
            $report['failures'],
        );
        self::assertSame(
            ['create_direct', 'cleanup_inspect_direct', 'cleanup_check_direct'],
            array_slice(array_column($report['probes'], 'phase'), -3),
        );
        self::assertSame([self::CREATE], self::bodies($server));
        self::assertSame(self::EXISTING, $server->tokens);

        $server->completeDelayed();
        self::assertArrayHasKey(self::ORIGINAL, $server->tokens);
    }

    /** The other party's token gets exactly the run's settings, so only released ownership can protect it. */
    private const LOOKALIKE = ['create_time' => 1794993668, 'expires_at' => '1795080067', 'readonly' => 1];

    public function testANameReusedAfterASuccessfulRenameIsNotRevoked(): void
    {
        $server = new TokensSectionServer();
        $server->foreign = self::LOOKALIKE;
        // Another party takes the freed first name before the list that follows the rename.
        $server->arrivals['list/direct/3'] = self::ORIGINAL;
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(
            ['renamed_direct', 'cleanup_inspect_direct', 'cleanup_renamed_direct', 'cleanup_check_direct'],
            array_slice(array_column($report['probes'], 'phase'), -4),
        );
        // The run's own token is revoked under the name it still holds; the reused first name is never sent.
        self::assertSame([self::CREATE, self::RENAME, 'name=' . self::RENAMED], self::bodies($server));
        self::assertSame(self::EXISTING + [self::ORIGINAL => self::LOOKALIKE], $server->tokens);
        // A token under a released name cannot be told from the run's own, so a person has to look.
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(
            ['direct-uapi:phase_failure', 'direct-uapi:unproven_token_under_run_name'],
            $report['failures'],
        );
    }

    public function testANameReusedAfterASuccessfulRevokeIsNotRevoked(): void
    {
        $server = new TokensSectionServer();
        $server->foreign = self::LOOKALIKE;
        // Another party takes the freed second name before the final list.
        $server->arrivals['list/direct/4'] = self::RENAMED;
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(
            ['absent_direct', 'cleanup_inspect_direct', 'cleanup_check_direct'],
            array_slice(array_column($report['probes'], 'phase'), -3),
        );
        // The only revoke is the lifecycle's own, sent while the run still held the name.
        self::assertSame([self::CREATE, self::RENAME, 'name=' . self::RENAMED], self::bodies($server));
        self::assertSame(self::EXISTING + [self::RENAMED => self::LOOKALIKE], $server->tokens);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(
            ['direct-uapi:phase_failure', 'direct-uapi:unproven_token_under_run_name'],
            $report['failures'],
        );
    }

    public function testAHeldNameListedWithOtherSettingsIsLeftUntouched(): void
    {
        $server = new TokensSectionServer();
        $server->failures['rename/direct/1'] = 'api';
        // Both cleanup lists show the run's first name without an expiry: it may not be the run's token.
        $other = '[{"name":"' . self::ORIGINAL . '","expires_at":null,"readonly":1,"has_full_access":1}]';
        $server->overrides['list/direct/3'] = $other;
        $server->overrides['list/direct/4'] = $other;
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(
            ['direct-uapi:phase_failure', 'direct-uapi:unproven_token_under_run_name'],
            $report['failures'],
        );
        self::assertSame([self::CREATE, self::RENAME], self::bodies($server));
    }

    /**
     * Every case loses a reply, so the run never learns the outcome; some also add a lookalike under a freed name.
     *
     * @return iterable<string,array{string,string,string|null,list<string>,list<string>,list<string>}>
     */
    public static function unknownOutcomes(): iterable
    {
        $phase = ['direct-uapi:phase_failure'];
        $unproven = [...$phase, 'direct-uapi:unproven_token_under_run_name'];
        $created = [...$unproven, 'direct-uapi:creation_outcome_unknown'];
        $revoke = 'name=' . self::RENAMED;
        yield 'create applied, reply lost' => ['create_full_access/direct/1', 'lost', null,
            [self::CREATE], [self::ORIGINAL], $created];
        yield 'create not applied, a lookalike takes the name' => ['create_full_access/direct/1', 'delayed',
            'list/direct/2:' . self::ORIGINAL, [self::CREATE], [self::ORIGINAL], $created];
        yield 'rename applied, reply lost' => ['rename/direct/1', 'lost', null,
            [self::CREATE, self::RENAME], [self::RENAMED], $unproven];
        yield 'rename applied, a lookalike takes the freed name' => ['rename/direct/1', 'lost',
            'list/direct/3:' . self::ORIGINAL, [self::CREATE, self::RENAME], [self::RENAMED, self::ORIGINAL],
            $unproven];
        yield 'rename not applied' => ['rename/direct/1', 'delayed', null,
            [self::CREATE, self::RENAME], [self::ORIGINAL], $unproven];
        yield 'rename not applied, a lookalike takes the target name' => ['rename/direct/1', 'delayed',
            'list/direct/3:' . self::RENAMED, [self::CREATE, self::RENAME], [self::ORIGINAL, self::RENAMED],
            $unproven];
        yield 'revoke not applied' => ['revoke/direct/1', 'delayed', null,
            [self::CREATE, self::RENAME, $revoke], [self::RENAMED], $unproven];
        yield 'revoke applied, a lookalike takes the freed name' => ['revoke/direct/1', 'lost',
            'list/direct/4:' . self::RENAMED, [self::CREATE, self::RENAME, $revoke], [self::RENAMED], $unproven];
    }

    /**
     * @param list<string> $bodies
     * @param list<string> $survivors
     * @param list<string> $failures
     */
    #[DataProvider('unknownOutcomes')]
    public function testAfterAnUnknownOutcomeNoCandidateIsRevokedAndTheRouteStaysUncertain(
        string $key,
        string $failure,
        ?string $arrival,
        array $bodies,
        array $survivors,
        array $failures,
    ): void {
        $server = new TokensSectionServer();
        $server->foreign = self::LOOKALIKE;
        $server->failures[$key] = $failure;
        if ($arrival !== null) {
            [$at, $name] = explode(':', $arrival);
            $server->arrivals[$at] = $name;
        }
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame($failures, $report['failures']);
        // Nothing is sent after the mutation whose reply was lost: no candidate row is revoked.
        self::assertSame($bodies, self::bodies($server));
        self::assertSame(
            ['cleanup_inspect_direct', 'cleanup_check_direct'],
            array_slice(array_column($report['probes'], 'phase'), -2),
        );
        // Every row under the run's names survives, whether it is the run's token or the other party's.
        $names = array_keys($server->tokens);
        sort($names);
        $expected = ['existing', ...$survivors];
        sort($expected);
        self::assertSame($expected, $names);
    }

    public function testALostRevokeReplySettlesOnlyWhenTheNameIsGone(): void
    {
        $server = new TokensSectionServer();
        $server->failures['revoke/direct/1'] = 'lost';
        $report = self::fixture($server)->run();

        // The revoke took effect and no row carries the name: the confirmed token is gone, and nothing was revoked.
        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'absent', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(['direct-uapi:phase_failure'], $report['failures']);
        self::assertSame([self::CREATE, self::RENAME, 'name=' . self::RENAMED], self::bodies($server));
        self::assertSame(self::EXISTING, $server->tokens);
    }

    /** @return iterable<string,array{string,string,string,string,list<string>}> */
    public static function failedPhases(): iterable
    {
        $original = ['cleanup_original_direct'];
        $renamed = ['cleanup_renamed_direct'];
        $create = 'create_full_access/direct/1';
        yield 'creation without a secret' => ['overrides', $create,
            '{"create_time":1794993667,"whitelist_ips":null}', 'create_direct', $original];
        yield 'creation without a creation time' => ['overrides', $create,
            '{"whitelist_ips":null,"token":"T"}', 'create_direct', $original];
        yield 'creation with an empty secret' => ['overrides', $create,
            '{"create_time":1794993667,"token":""}', 'create_direct', $original];
        yield 'creation with an undocumented field' => ['overrides', $create,
            '{"create_time":1794993667,"token":"T","surprise":1}', 'create_direct', $original];
        yield 'list row exposing a secret' => ['overrides', 'list/direct/2',
            '[{"name":"' . self::ORIGINAL . '","expires_at":"1795080067","readonly":1,'
                . '"has_full_access":1,"token":"T"}]', 'listed_direct', $original];
        yield 'listed with another expiry' => ['overrides', 'list/direct/2',
            '[{"name":"' . self::ORIGINAL . '","expires_at":"1795080068","readonly":1,"has_full_access":1}]',
            'listed_direct', $original];
        yield 'listed writable' => ['overrides', 'list/direct/2',
            '[{"name":"' . self::ORIGINAL . '","expires_at":"1795080067","readonly":0,"has_full_access":1}]',
            'listed_direct', $original];
        yield 'listed without full access' => ['overrides', 'list/direct/2',
            '[{"name":"' . self::ORIGINAL . '","expires_at":"1795080067","readonly":1,"has_full_access":0}]',
            'listed_direct', $original];
        yield 'listed twice' => ['overrides', 'list/direct/2',
            '[' . self::SETTLED_ROW . ',' . self::SETTLED_ROW . ']', 'listed_direct', $original];
        yield 'listed beside its renamed name' => ['overrides', 'list/direct/2',
            '[' . self::SETTLED_ROW . ',{"name":"' . self::RENAMED . '"}]', 'listed_direct', $original];
        yield 'listed with a nameless row' => ['overrides', 'list/direct/2',
            '[' . self::SETTLED_ROW . ',{}]', 'listed_direct', $original];
        yield 'rename refused' => ['failures', 'rename/direct/1', 'api', 'rename_direct', $original];
        yield 'rename returning data' => ['overrides', 'rename/direct/1', '{"renamed":1}', 'rename_direct', $renamed];
        yield 'old name still listed after rename' => ['overrides', 'list/direct/3',
            '[' . self::SETTLED_ROW . ']', 'renamed_direct', $renamed];
        yield 'renamed with another expiry' => ['overrides', 'list/direct/3',
            '[{"name":"' . self::RENAMED . '","expires_at":"1795080068","readonly":1,"has_full_access":1}]',
            'renamed_direct', $renamed];
        yield 'renamed token missing from the list' => ['overrides', 'list/direct/3', '[]', 'renamed_direct',
            $renamed];
        yield 'revoke refused' => ['failures', 'revoke/direct/1', 'api', 'revoke_direct', $renamed];
        yield 'revoke reporting nothing removed' => ['overrides', 'revoke/direct/1', '0', 'revoke_direct', []];
        yield 'token still listed after revoke' => ['overrides', 'list/direct/4',
            '[{"name":"' . self::RENAMED . '"}]', 'absent_direct', []];
    }

    /** @param list<string> $revokes */
    #[DataProvider('failedPhases')]
    public function testAFailedPhaseRevokesOnlyWhatTheRunHoldsAndNeverStartsTheNextRoute(
        string $kind,
        string $key,
        string $value,
        string $phase,
        array $revokes,
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
        // Cleanup revoked exactly the names the run held, and a later list confirmed that neither is left.
        self::assertSame(['direct-uapi' => 'absent', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(self::EXISTING, $server->tokens);
        $phases = array_column($report['probes'], 'phase');
        $cleanup = [$phase, 'cleanup_inspect_direct', ...$revokes, 'cleanup_check_direct'];
        self::assertSame($cleanup, array_slice($phases, -count($cleanup)));
        self::assertSame('failed', $report['assessment']['status']);
        self::assertContains('unexpected_phase', $report['assessment']['findings']);
        self::assertStringNotContainsString(TokensSectionServer::SECRET, json_encode($report, JSON_THROW_ON_ERROR));
        // The bridged route was never reached: only the WHM version request went to the WHM port.
        foreach ($server->requests as $request) {
            self::assertStringNotContainsString('uapi_cpanel', (string) $request->getUri());
        }
    }

    public function testATokenThatSurvivesCleanupLeavesTheRouteUncertain(): void
    {
        $server = new TokensSectionServer();
        $server->failures['revoke/direct/1'] = 'api';
        $server->failures['revoke/direct/2'] = 'api';
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(
            ['direct-uapi:phase_failure', 'direct-uapi:cleanup_failure', 'direct-uapi:cleanup_unconfirmed'],
            $report['failures'],
        );
        self::assertArrayHasKey(self::RENAMED, $server->tokens);
    }

    /** @return iterable<string,array{string,list<string>}> */
    public static function unusableCleanupLists(): iterable
    {
        // After a refused rename the cleanup lists are the third and fourth list requests of the route.
        yield 'the inspection has a nameless row' => ['list/direct/3', []];
        yield 'the final check has a nameless row' => ['list/direct/4', ['name=' . self::ORIGINAL]];
    }

    /** @param list<string> $revokes */
    #[DataProvider('unusableCleanupLists')]
    public function testACleanupListThatCannotProveAbsenceLeavesTheRouteUncertain(string $key, array $revokes): void
    {
        $server = new TokensSectionServer();
        $server->failures['rename/direct/1'] = 'api';
        $server->overrides[$key] = '[{}]';
        $report = self::fixture($server)->run();

        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['tokens']);
        self::assertSame(['direct-uapi:phase_failure', 'direct-uapi:cleanup_unconfirmed'], $report['failures']);
        // Nothing is revoked on the strength of a list that does not identify its rows.
        self::assertSame([self::CREATE, self::RENAME, ...$revokes], self::bodies($server));
        $probes = $report['probes'];
        self::assertSame('failed', array_pop($probes)['expectation'] ?? null);
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
        self::assertSame('absent_direct', $report['probes'][8]['phase']);
        self::assertSame('passed', $report['probes'][8]['expectation']);
        self::assertSame('revoke_bridge', $report['probes'][15]['phase']);
        self::assertSame('failed', $report['probes'][15]['expectation']);
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
                4 => ['discrepancies' => [['path' => '$[].x', 'kind' => 'unknown_field']]] + $records[4],
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
