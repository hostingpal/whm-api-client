<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use HostingPal\WhmApiClient\Tools\LiveVerify\LangPhpMutationEvidence;
use HostingPal\WhmApiClient\Tools\LiveVerify\LangPhpMutationFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @phpstan-import-type Scenario from LangPhpMutationFixture
 * @phpstan-import-type Observation from LangPhpMutationFixture
 * @phpstan-import-type Context from EvidenceRunner
 */
final class LangPhpMutationFixtureTest extends TestCase
{
    private const SET_DIRECTIVES = 'php_ini_set_user_basic_directives';

    private const SET_CONTENT = 'php_ini_set_user_content';

    private const SET_VERSION = 'php_set_vhost_versions';

    private const FAILURE_MODES = ['throw_before', 'throw_after', 'api_before', 'api_after', 'http_before',
        'http_after', 'invalid_before', 'invalid_after'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlphpset',
                'WHM_API_LIVE_DOMAIN' => 'wlphpset.example.com'] as $key => $value
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

    public function testCompleteRunChangesReadsBackAndCleansUpExactly(): void
    {
        $http = new LangPhpMutationServer();
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['status'], (string) json_encode($fixture['failures']));
        self::assertSame('langphp-mutations-disposable-v1', $scenario['scenario']);
        self::assertSame('disposable-test-account', $scenario['target_role']);
        self::assertSame('passed', $scenario['discrepancy_review']['status'], (string) json_encode(
            $scenario['discrepancy_review']['findings'],
        ));
        self::assertSame([], $fixture['failures']);
        self::assertSame('passed', $fixture['capture']);
        self::assertSame('passed', $fixture['expectations']);
        self::assertSame('passed', $fixture['cleanup']);
        self::assertSame(
            ['preflight' => null, 'directives' => null, 'content' => null, 'versions' => null],
            $fixture['stopped_at'],
        );
        foreach (['account', 'token'] as $resource) {
            self::assertSame(['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'], $fixture['resources'][
                $resource
            ]);
        }
        self::assertSame(array_keys(LangPhpMutationEvidence::PLAN), array_column($scenario['probes'], 'phase'));
        self::assertSame([
            'createacct', 'Tokens/create_full_access',
            'direct:LangPHP/' . self::SET_DIRECTIVES, 'LangPHP/' . self::SET_DIRECTIVES,
            'direct:LangPHP/' . self::SET_CONTENT, 'LangPHP/' . self::SET_CONTENT,
            'direct:LangPHP/' . self::SET_CONTENT, 'LangPHP/' . self::SET_CONTENT,
            'direct:LangPHP/' . self::SET_VERSION, 'LangPHP/' . self::SET_VERSION,
            'Tokens/revoke', 'removeacct',
        ], $http->mutations);
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
        self::assertSame('ea-php84', $http->vhostVersion);

        self::assertSame([
            'installed_versions' => 4, 'installed_routes_agree' => true, 'version_alternatives' => 3,
            'vhost_rows' => ['direct-uapi' => 1, 'bridged-uapi' => 1], 'vhost_row_found' => true,
            'vhosts_root' => ['direct-uapi' => 'list', 'bridged-uapi' => 'list'],
            'home_content' => ['direct-uapi' => 'empty', 'bridged-uapi' => 'empty'], 'content_routes_agree' => true,
            'directives_found' => true, 'directive_routes_agree' => true, 'directive_targets_distinct' => true,
        ], $scenario['original_state']);
        self::assertSame([
            'direct-uapi' => ['encoding' => 'scalar', 'fields' => 1, 'readback_exact' => true],
            'bridged-uapi' => ['encoding' => 'numbered', 'fields' => 2, 'readback_exact' => true],
        ], $scenario['directive_changes']);
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            self::assertSame([
                'class' => 'entity_model_after_server_comments', 'server_comment_lines' => 2,
                'server_blank_lines' => 1, 'directive_crosscheck' => true, 'earlier_directives_replaced' => true,
            ], $scenario['content_round_trips'][$route]);
            self::assertSame(
                ['outcome' => 'refused', 'envelope_errors' => 1, 'content_unchanged' => true],
                $scenario['content_type_host'][$route],
            );
            self::assertSame(
                ['result_vhosts_exact' => true, 'readback_root' => 'object', 'readback_matches' => true],
                $scenario['version_changes'][$route],
            );
        }
        self::assertPrivate($scenario);
    }

    /** Two refusals are expected outcomes: the runner keeps them as failed probes while expectations pass. */
    public function testHostRefusalsStayFailedProbesWithPassedExpectations(): void
    {
        $scenario = self::scenario(new LangPhpMutationServer());
        foreach (LangPhpMutationEvidence::REFUSED as $phase) {
            $record = self::observation($scenario, $phase);
            self::assertSame('failed', $record['probe_outcome']);
            self::assertSame('api_failure', $record['failure_kind']);
            self::assertSame('failure', $record['api_outcome']);
            self::assertSame(0, $record['uapi_status']);
            self::assertSame('passed', $record['expectation']);
            self::assertSame('POST', $record['method']);
        }
        self::assertSame(1, self::observation($scenario, 'set_content_bridged')['uapi_status']);
    }

    /** Every field a clean lifecycle reports reaches this report unchanged, so a new one cannot be left out. */
    public function testReportCarriesEveryLifecycleField(): void
    {
        $fixture = self::scenario(new LangPhpMutationServer())['fixture'];
        foreach (CleanLifecycleRun::reportFields() as $field => $value) {
            if ($field === 'resources') {
                foreach ($value as $resource => $outcome) {
                    self::assertSame($outcome, $fixture['resources'][$resource], $resource);
                }
                continue;
            }
            self::assertSame($value, $fixture[$field], $field);
        }
    }

    public function testNoPublicObjectHoldsTheMarker(): void
    {
        $http = new LangPhpMutationServer();
        $fixture = new LangPhpMutationFixture($http, 'WHMTOKEN', self::context());
        $scenario = $fixture->run();
        $marker = self::sentMarker($http->requests);
        foreach ([$marker, rawurlencode($marker)] as $form) {
            MarkerExposure::assertNeverExposed($form, $scenario);
            MarkerExposure::assertNeverExposed($form, $fixture, $http);
        }
    }

    public function testTemporaryTokenIsWritableAndShortLived(): void
    {
        $http = new LangPhpMutationServer();
        self::scenario($http);
        foreach ($http->requests as $request) {
            if (str_contains((string) $request->getUri(), 'cpanel.function=create_full_access')) {
                parse_str((string) $request->getBody(), $fields);
                self::assertArrayNotHasKey('readonly', $fields);
                self::assertSame('wlphpset-php-fixture', $fields['name'] ?? null);
                $expires = (int) ($fields['expires_at'] ?? 0);
                self::assertGreaterThan(time(), $expires);
                self::assertLessThanOrEqual(time() + 900, $expires);

                return;
            }
        }
        self::fail('The fixture created no token.');
    }

    public function testNeverTouchesAnotherAccountOrAnUnplannedFunction(): void
    {
        $http = new LangPhpMutationServer();
        self::scenario($http);
        foreach ($http->requests as $request) {
            $uri = (string) $request->getUri();
            self::assertStringNotContainsString('npr', $uri);
            self::assertStringNotContainsString('php_ini_get_user_paths', $uri);
            self::assertStringNotContainsString('type=vhost', $uri . (string) $request->getBody());
        }
    }

    public function testAcceptedHostTypeFailsAndStopsTheContentGroupButNotTheVersions(): void
    {
        $http = new LangPhpMutationServer();
        $http->acceptHost = true;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame(['expectation_failure'], $fixture['failures']);
        self::assertSame('failed', $fixture['capture']);
        self::assertSame('failed', $fixture['expectations']);
        self::assertSame('set_content_host_direct', $fixture['stopped_at']['content']);
        self::assertSame('accepted', $scenario['content_type_host']['direct-uapi']['outcome']);
        self::assertArrayNotHasKey('bridged-uapi', $scenario['content_type_host']);
        self::assertSame(2, $http->counts['direct:LangPHP/' . self::SET_CONTENT]);
        self::assertSame(1, $http->counts['LangPHP/' . self::SET_CONTENT]);
        self::assertSame('ea-php84', $http->vhostVersion);
        self::assertSame(null, $fixture['stopped_at']['versions']);
        self::assertSame('failed', self::observation($scenario, 'set_content_host_direct')['expectation']);
        self::assertContains(
            'unexpected_outcome:set_content_host_direct',
            $scenario['discrepancy_review']['findings'],
        );
        self::assertSame('passed', $fixture['cleanup']);
    }

    public function testDirectiveWriteThatNeverReachesTheFileStopsOnlyTheDirectives(): void
    {
        $http = new LangPhpMutationServer();
        $http->applyDirectives = false;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(['expectation_failure'], $fixture['failures']);
        self::assertSame('read_directives_direct', $fixture['stopped_at']['directives']);
        self::assertFalse($scenario['directive_changes']['direct-uapi']['readback_exact']);
        self::assertArrayNotHasKey('bridged-uapi', $scenario['directive_changes']);
        self::assertSame(1, $http->counts['direct:LangPHP/' . self::SET_DIRECTIVES]);
        self::assertArrayNotHasKey('LangPHP/' . self::SET_DIRECTIVES, $http->counts);
        self::assertNull($fixture['stopped_at']['content']);
        self::assertNull($fixture['stopped_at']['versions']);
        self::assertSame('passed', $fixture['cleanup']);
    }

    public function testServerIgnoringNumberedDirectivesFailsTheBridgedReadback(): void
    {
        $http = new LangPhpMutationServer();
        $http->readNumbered = false;
        $scenario = self::scenario($http);

        self::assertTrue($scenario['directive_changes']['direct-uapi']['readback_exact']);
        self::assertFalse($scenario['directive_changes']['bridged-uapi']['readback_exact']);
        self::assertSame('read_directives_bridged', $scenario['fixture']['stopped_at']['directives']);
        self::assertSame('failed', $scenario['fixture']['expectations']);
    }

    public function testDoubleDecodedContentIsClassifiedOtherAndFails(): void
    {
        $http = new LangPhpMutationServer();
        $http->doubleDecode = true;
        $scenario = self::scenario($http);

        self::assertSame('other', $scenario['content_round_trips']['direct-uapi']['class']);
        self::assertSame('read_content_direct', $scenario['fixture']['stopped_at']['content']);
        self::assertSame(1, $http->counts['direct:LangPHP/' . self::SET_CONTENT]);
        self::assertArrayNotHasKey('LangPHP/' . self::SET_CONTENT, $http->counts);
        self::assertSame('failed', $scenario['fixture']['status']);
    }

    /** A server without the entity behavior or the comment block still returns the sent content, so it passes. */
    public function testVerbatimRoundTripIsRecoverableAndRecorded(): void
    {
        $http = new LangPhpMutationServer();
        $http->entities = false;
        $http->header = false;
        $scenario = self::scenario($http);

        self::assertSame('passed', $scenario['fixture']['status'], (string) json_encode(
            $scenario['fixture']['failures'],
        ));
        self::assertSame('verbatim', $scenario['content_round_trips']['bridged-uapi']['class']);
    }

    public function testVersionWriteThatDoesNotApplyFailsItsReadbackAndSendsNoSecond(): void
    {
        $http = new LangPhpMutationServer();
        $http->applyVersion = false;
        $scenario = self::scenario($http);

        self::assertFalse($scenario['version_changes']['direct-uapi']['readback_matches']);
        self::assertArrayNotHasKey('bridged-uapi', $scenario['version_changes']);
        self::assertSame('read_version_direct', $scenario['fixture']['stopped_at']['versions']);
        self::assertSame(1, $http->counts['direct:LangPHP/' . self::SET_VERSION]);
        self::assertArrayNotHasKey('LangPHP/' . self::SET_VERSION, $http->counts);
    }

    public function testListReplyToAVhostSelectorIsRecordedAndStillRead(): void
    {
        $http = new LangPhpMutationServer();
        $http->vhostObject = false;
        $scenario = self::scenario($http);

        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertSame('list', $scenario['version_changes']['bridged-uapi']['readback_root']);
        self::assertTrue($scenario['version_changes']['bridged-uapi']['readback_matches']);
    }

    public function testUnexpectedVhostsResultFailsTheVersionGroupBeforeItsReadback(): void
    {
        $http = new LangPhpMutationServer();
        $http->reportedVhosts = ['wlphpset.example.com', 'private-impacted.example'];
        $scenario = self::scenario($http);

        self::assertFalse($scenario['version_changes']['direct-uapi']['result_vhosts_exact']);
        self::assertSame('set_version_direct', $scenario['fixture']['stopped_at']['versions']);
        self::assertArrayNotHasKey('LangPHP/' . self::SET_VERSION, $http->counts);
        self::assertSame('failed', $scenario['fixture']['expectations']);
    }

    public function testTooFewOtherVersionsSendsNoVersionWrite(): void
    {
        $http = new LangPhpMutationServer();
        $http->installed = ['ea-php83', 'ea-php84'];
        $scenario = self::scenario($http);

        self::assertSame(1, $scenario['original_state']['version_alternatives']);
        self::assertSame('vhosts_bridged', $scenario['fixture']['stopped_at']['versions']);
        self::assertArrayNotHasKey('direct:LangPHP/' . self::SET_VERSION, $http->counts);
        self::assertSame(['expectation_failure'], $scenario['fixture']['failures']);
        self::assertNull($scenario['fixture']['stopped_at']['content']);
    }

    public function testDirectiveTargetAlreadyInPlaceSendsNoDirectiveWrite(): void
    {
        $http = new LangPhpMutationServer();
        $http->systemDirectives['max_execution_time'] = '37';
        $scenario = self::scenario($http);

        self::assertFalse($scenario['original_state']['directive_targets_distinct']);
        self::assertSame('directives_bridged', $scenario['fixture']['stopped_at']['directives']);
        self::assertArrayNotHasKey('direct:LangPHP/' . self::SET_DIRECTIVES, $http->counts);
        self::assertSame('ea-php84', $http->vhostVersion);
    }

    #[DataProvider('preflightReads')]
    public function testPreflightFailureSendsNoLangPhpMutation(string $step): void
    {
        $http = new LangPhpMutationServer();
        $http->failures[$step] = 'api_after';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http
            ->mutations);
        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertSame('failed', $fixture['capture']);
        self::assertSame('not_run', $fixture['expectations']);
        self::assertNotNull($fixture['stopped_at']['preflight']);
        self::assertSame('passed', $fixture['cleanup']);
    }

    /** @return iterable<string, array{string}> */
    public static function preflightReads(): iterable
    {
        yield 'installed' => ['direct:LangPHP/php_get_installed_versions:1'];
        yield 'bridged vhosts' => ['LangPHP/php_get_vhost_versions:1'];
        yield 'directives' => ['direct:LangPHP/php_ini_get_user_basic_directives:1'];
        yield 'content' => ['LangPHP/php_ini_get_user_content:1'];
    }

    public function testRoutesThatDisagreeBeforeAnyWriteSendNoMutation(): void
    {
        $http = new LangPhpMutationServer();
        $http->afterEffect = static function (LangPhpMutationServer $server): void {
            if (($server->counts['direct:LangPHP/php_ini_get_user_content'] ?? 0) === 1) {
                $server->homeIni = "; private concurrent change\nmax_input_vars = 5\n";
            }
        };
        $scenario = self::scenario($http);

        self::assertFalse($scenario['original_state']['content_routes_agree']);
        self::assertSame('content_bridged', $scenario['fixture']['stopped_at']['preflight']);
        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http
            ->mutations);
        self::assertSame(['expectation_failure'], $scenario['fixture']['failures']);
    }

    /** No mutation repeats after any outcome; the failing group stops there and the later groups still run. */
    #[DataProvider('everyMutationFailure')]
    public function testEveryMutationFailureStopsItsGroupWithoutRetrying(
        string $step,
        string $group,
        string $phase,
        string $mode,
    ): void {
        $http = new LangPhpMutationServer();
        $http->failures[$step] = $mode;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertSame('passed', $fixture['cleanup'], (string) json_encode($fixture['failures']));
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
        self::assertSame($phase, $fixture['stopped_at'][$group]);
        foreach ($fixture['stopped_at'] as $other => $stopped) {
            self::assertSame($other === $group ? $phase : null, $stopped, $other);
        }
        $planned = [
            'direct:LangPHP/' . self::SET_DIRECTIVES => 1, 'LangPHP/' . self::SET_DIRECTIVES => 1,
            'direct:LangPHP/' . self::SET_CONTENT => 2, 'LangPHP/' . self::SET_CONTENT => 2,
            'direct:LangPHP/' . self::SET_VERSION => 1, 'LangPHP/' . self::SET_VERSION => 1,
        ];
        foreach ($planned as $mutation => $limit) {
            self::assertLessThanOrEqual($limit, $http->counts[$mutation] ?? 0, $mutation);
        }
        self::assertSame(1, $http->counts[substr($step, 0, (int) strrpos($step, ':'))]);
        if ($group !== 'versions') {
            self::assertSame(1, $http->counts['LangPHP/' . self::SET_VERSION] ?? 0);
        }
        self::assertSame(1, $http->counts['createacct']);
        self::assertSame(1, $http->counts['removeacct']);
        self::assertSame('not_checked', self::observation($scenario, $phase)['expectation']);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function everyMutationFailure(): iterable
    {
        $steps = [
            ['direct:LangPHP/' . self::SET_DIRECTIVES . ':1', 'directives', 'set_directives_direct'],
            ['LangPHP/' . self::SET_DIRECTIVES . ':1', 'directives', 'set_directives_bridged'],
            ['direct:LangPHP/' . self::SET_CONTENT . ':1', 'content', 'set_content_direct'],
            ['LangPHP/' . self::SET_CONTENT . ':1', 'content', 'set_content_bridged'],
            ['direct:LangPHP/' . self::SET_VERSION . ':1', 'versions', 'set_version_direct'],
            ['LangPHP/' . self::SET_VERSION . ':1', 'versions', 'set_version_bridged'],
        ];
        foreach ($steps as [$step, $group, $phase]) {
            foreach (self::FAILURE_MODES as $mode) {
                yield $phase . ' ' . $mode => [$step, $group, $phase, $mode];
            }
        }
    }

    /** A lost or unreadable reply to a host attempt is no refusal; only an explicit API refusal is. */
    #[DataProvider('unknownHostOutcomes')]
    public function testUnknownHostOutcomeIsACaptureFailureNotARefusal(string $mode): void
    {
        $http = new LangPhpMutationServer();
        $http->failures['direct:LangPHP/' . self::SET_CONTENT . ':2'] = $mode;
        $scenario = self::scenario($http);

        self::assertSame('unknown', $scenario['content_type_host']['direct-uapi']['outcome']);
        self::assertContains('capture_failure', $scenario['fixture']['failures']);
        self::assertSame('set_content_host_direct', $scenario['fixture']['stopped_at']['content']);
        self::assertSame(1, $http->counts['LangPHP/' . self::SET_CONTENT]);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownHostOutcomes(): iterable
    {
        yield 'lost' => ['throw_before'];
        yield 'gateway' => ['http_before'];
        yield 'unreadable' => ['invalid_before'];
    }

    public function testHostRefusalThatStillChangedTheContentFails(): void
    {
        $http = new LangPhpMutationServer();
        $http->afterEffect = static function (LangPhpMutationServer $server): void {
            $last = end($server->requests);
            if ($last !== false && str_contains((string) $last->getBody(), 'type=host')) {
                $server->homeIni .= "; private change\n";
            }
        };
        $scenario = self::scenario($http);

        self::assertSame('refused', $scenario['content_type_host']['direct-uapi']['outcome']);
        self::assertFalse($scenario['content_type_host']['direct-uapi']['content_unchanged']);
        self::assertSame('read_content_host_direct', $scenario['fixture']['stopped_at']['content']);
        self::assertSame(1, $http->counts['LangPHP/' . self::SET_CONTENT]);
        self::assertSame(['expectation_failure'], $scenario['fixture']['failures']);
    }

    #[DataProvider('requiredEnvironment')]
    public function testMissingOrWrongTargetConfigurationPreventsAllRequests(string $key): void
    {
        putenv($key . '=wrong');
        $http = new LangPhpMutationServer();
        $scenario = self::scenario($http);
        self::assertSame([], $http->requests);
        self::assertSame(['configuration_failure'], $scenario['fixture']['failures']);
        self::assertSame('not_started', $scenario['fixture']['setup']);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredEnvironment(): iterable
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN'] as $key) {
            yield $key => [$key];
        }
    }

    public function testInstanceIsSingleUse(): void
    {
        $http = new LangPhpMutationServer();
        $fixture = new LangPhpMutationFixture($http, 'WHMTOKEN', self::context());
        $fixture->run();
        $requests = count($http->requests);
        $second = $fixture->run();
        self::assertSame(['configuration_failure'], $second['fixture']['failures']);
        self::assertSame('failed', $second['fixture']['status']);
        self::assertCount($requests, $http->requests);
    }

    public function testPreexistingAccountIsNeverAdoptedOrChanged(): void
    {
        $http = new LangPhpMutationServer();
        $http->account = true;
        $scenario = self::scenario($http);

        self::assertSame(['setup_failure'], $scenario['fixture']['failures']);
        self::assertSame('not_run', $scenario['fixture']['capture']);
        self::assertSame([], $http->mutations);
        self::assertTrue($http->account);
    }

    public function testSetupFailureRunsNoCapture(): void
    {
        $http = new LangPhpMutationServer();
        $http->failures['Tokens/create_full_access:1'] = 'api_before';
        $scenario = self::scenario($http);

        self::assertSame('failed', $scenario['fixture']['setup']);
        self::assertSame('not_run', $scenario['fixture']['capture']);
        self::assertSame('not_run', $scenario['fixture']['expectations']);
        self::assertSame([], $scenario['probes']);
        self::assertSame(['createacct', 'removeacct'], $http->mutations);
        self::assertContains('missing_phase:version', $scenario['discrepancy_review']['findings']);
    }

    public function testWithoutTheProofOnlyApiAbsenceIsClaimed(): void
    {
        $scenario = self::scenario(new LangPhpMutationServer());
        self::assertSame('not_claimed', $scenario['fixture']['filesystem_cleanup']);
        self::assertContains('api_absence_only_no_filesystem_cleanup_claim', $scenario['limitations']);
    }

    public function testFilesystemProofNarrowsTheClaimOnlyWhenProven(): void
    {
        $http = new LangPhpMutationServer();
        $proof = RecordingFilesystemProof::after(['outcome' => 'confirmed_absent', 'residue' => []]);
        $scenario = self::scenario($http, $proof);

        self::assertSame([['wlphpset', 'wlphpset.example.com'], ['wlphpset', 'wlphpset.example.com']], $proof
            ->calls);
        self::assertSame('confirmed_absent', $scenario['fixture']['filesystem_cleanup']);
        self::assertSame('passed', $scenario['fixture']['filesystem_control']['status']);
        self::assertContains('filesystem_proof_covers_fixed_locations_at_one_moment', $scenario['limitations']);
        self::assertNotContains('api_absence_only_no_filesystem_cleanup_claim', $scenario['limitations']);

        $residue = self::scenario(
            new LangPhpMutationServer(),
            RecordingFilesystemProof::after(['outcome' => 'present', 'residue' => ['home' => 1]]),
        );
        self::assertSame('failed', $residue['fixture']['status']);
        self::assertContains('api_absence_only_no_filesystem_cleanup_claim', $residue['limitations']);
    }

    /** Synthetic context: tests never bind to the committed artifacts or the current spec hashes. */
    /** @return Context */
    public static function context(): array
    {
        return [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
    }

    /**
     * @param Scenario $scenario
     * @return Observation
     */
    private static function observation(array $scenario, string $phase): array
    {
        foreach ($scenario['probes'] as $record) {
            if ($record['phase'] === $phase) {
                return $record;
            }
        }
        self::fail('No observation for ' . $phase . '.');
    }

    /** @param list<RequestInterface> $requests */
    private static function sentMarker(array $requests): string
    {
        foreach ($requests as $request) {
            if (str_contains((string) $request->getUri(), '/createacct?')) {
                parse_str((string) $request->getBody(), $fields);
                $marker = $fields['contactemail'] ?? null;
                self::assertIsString($marker);

                return $marker;
            }
        }
        self::fail('The fixture sent no createacct request.');
    }

    /** @return Scenario */
    private static function scenario(LangPhpMutationServer $http, ?FilesystemAbsenceProof $filesystem = null): array
    {
        return (new LangPhpMutationFixture($http, 'WHMTOKEN', self::context(), $filesystem))->run();
    }

    /** @param Scenario $scenario */
    private static function assertPrivate(array $scenario): void
    {
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        foreach (
            ['private', 'WHMTOKEN', 'PRIVATEPHPTOKEN', 'wlphpset', 'example.com', '/home', 'ea-php', 'quoted',
                'max_input', 'max_execution', '1357', '2468', 'PHP]', '1790000000', 'plus+sign'] as $value
        ) {
            self::assertStringNotContainsString($value, $json);
        }
    }
}
