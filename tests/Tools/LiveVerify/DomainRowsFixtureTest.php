<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsRecipe;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Every assertion here runs after run() returns: the lifecycle catches Throwable around its callbacks.
 *
 * @phpstan-import-type Scenario from DomainRowsFixture
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class DomainRowsFixtureTest extends TestCase
{
    private const EXTRA = ['addon_domain', 'addon_subdomain', 'alias_domain', 'subdomain', 'addon_zone', 'alias_zone'];

    private const ADDON = 'direct:AddonDomain/addaddondomain';

    private const PARK = 'direct:Park/park';

    private const SUB = 'direct:SubDomain/addsubdomain';

    private const UNTOUCHED = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

    private const REFUSED = ['creation' => 'unconfirmed', 'cleanup' => 'confirmed_absent'];

    private const REMOVED = ['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wldomains',
                'WHM_API_LIVE_DOMAIN' => 'wldomains.example.com'] as $key => $value
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

    public function testCompleteRunReadsEveryKindAndCleansUpExactly(): void
    {
        $http = new DomainRowsServer();
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['status'], (string) json_encode($fixture['failures']));
        self::assertSame('domain-rows-disposable-v1', $scenario['scenario']);
        self::assertSame('passed', $scenario['discrepancy_review']['status'], (string) json_encode(
            $scenario['discrepancy_review']['findings'],
        ));
        self::assertSame(['passed', 'passed', 'passed', null], [$fixture['capture'], $fixture['expectations'],
            $fixture['cleanup'], $fixture['stopped_at']]);
        self::assertSame([], $fixture['failures']);
        foreach (['account', 'token', ...self::EXTRA] as $resource) {
            self::assertSame(
                ['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'],
                $fixture['resources'][$resource],
                $resource,
            );
        }
        self::assertSame('confirmed_absent', $fixture['domain_cleanup']);
        self::assertSame('confirmed_absent', $fixture['zone_cleanup']);
        self::assertSame('not_claimed', $fixture['filesystem_cleanup']);
        self::assertSame('not_claimed', $fixture['extra_filesystem']['addon_domain']['cleanup']);
        self::assertSame([
            'createacct', 'Tokens/create_full_access', 'modifyacct', self::ADDON, self::PARK, self::SUB,
            'Tokens/revoke', 'removeacct',
        ], $http->mutations);
        self::assertFalse($http->account);
        self::assertSame([], $http->domains);
        self::assertSame([], $http->zones);
        self::assertSame(DomainRowsRecipe::PHASES, array_column($scenario['probes'], 'phase'));
        foreach ($scenario['dns_results'] as $phase => $result) {
            self::assertSame(2, $result['returned'], $phase);
            self::assertSame(
                str_contains($phase, '_authority_') || str_contains($phase, '_ds_') ? 'request' : 'not_observable',
                $result['order'],
                $phase,
            );
        }
        self::assertCount(16, $scenario['dns_results']);
        foreach ($scenario['domain_kinds'] as $phase => $kinds) {
            // As on cPanel 11.138.0.19, format=list holds no alias row and no inventory lists the addon's subdomain.
            $parked = !str_ends_with($phase, '_domains_list');
            self::assertSame(
                ['main' => true, 'addon' => true, 'parked' => $parked, 'sub' => true, 'addon_subdomain' => false],
                $kinds,
                $phase,
            );
        }
        self::assertCount(6, $scenario['domain_kinds']);
        self::assertSame('requested_domain', $scenario['single_domain']['bridged_single_sub']);
        self::assertSame(
            ['request' => 2, 'reverse' => 0, 'other' => 0, 'not_observable' => 0],
            $scenario['dns_order_samples']['whm1:has_local_authority@whm'],
        );
        self::assertPrivate($scenario);
    }

    /** Every field a clean lifecycle reports reaches this report unchanged, so a new one cannot be left out. */
    public function testReportCarriesEveryLifecycleField(): void
    {
        $fixture = self::scenario(new DomainRowsServer())['fixture'];
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

    /** The fixture, its guard, recipe, lifecycle and scenario never keep the marker; only the server double saw it. */
    public function testNoPublicObjectHoldsTheMarker(): void
    {
        $http = new DomainRowsServer();
        $fixture = new DomainRowsFixture($http, 'WHMTOKEN', DomainRowsEvidenceTest::context());
        $scenario = $fixture->run();
        $marker = self::sentMarker($http->requests);
        foreach ([$marker, rawurlencode($marker)] as $form) {
            MarkerExposure::assertNeverExposed($form, $scenario);
            MarkerExposure::assertNeverExposed($form, $fixture, $http);
        }
    }

    /** The alias and the addon's subdomain are recorded wherever they appear, and required by no list form. */
    public function testOptionalKindsAreRecordedWhereverTheyAppear(): void
    {
        $http = new DomainRowsServer();
        $http->listAliasRow = true;
        $http->listAddonSubdomain = true;
        $scenario = self::scenario($http);

        self::assertSame('passed', $scenario['fixture']['status']);
        foreach ($scenario['domain_kinds'] as $phase => $kinds) {
            self::assertSame(
                ['main' => true, 'addon' => true, 'parked' => true, 'sub' => true, 'addon_subdomain' => true],
                $kinds,
                $phase,
            );
        }
    }

    /** Every other inventory must list the alias, as its row is part of what the run exists to observe. */
    public function testAnInventoryWithoutTheAliasFailsItsExpectation(): void
    {
        $http = new DomainRowsServer();
        $http->overrides['direct:DomainInfo/list_domains:1'] = '{"main_domain":"wldomains.example.com",'
            . '"addon_domains":["wldomains-addon.example.com"],"parked_domains":[],'
            . '"sub_domains":["wldsub.wldomains.example.com"]}';
        $scenario = self::scenario($http);

        self::assertSame(['expectation_failure'], $scenario['fixture']['failures']);
        self::assertFalse($scenario['domain_kinds']['direct_list_domains']['parked']);
        self::assertSame('failed', self::observation($scenario, 'direct_list_domains')['expectation']);
    }

    /** A server answering in sorted order reads as request order for one permutation and reverse for the other. */
    public function testOrderIsRecordedPerSampleAndNeverGeneralized(): void
    {
        $http = new DomainRowsServer();
        $http->sortedDns = true;
        $scenario = self::scenario($http);

        self::assertSame('passed', $scenario['fixture']['status']);
        // The addon sorts first, so the main-then-addon request comes back reversed.
        self::assertSame('reverse', $scenario['dns_results']['whm_authority_forward']['order']);
        self::assertSame('request', $scenario['dns_results']['whm_authority_reverse']['order']);
        self::assertSame(
            ['request' => 1, 'reverse' => 1, 'other' => 0, 'not_observable' => 0],
            $scenario['dns_order_samples']['uapi:DNS/has_local_authority@bridged-uapi'],
        );
        self::assertSame(
            ['request' => 0, 'reverse' => 0, 'other' => 0, 'not_observable' => 2],
            $scenario['dns_order_samples']['uapi:DNS/ensure_domains_reside_only_locally@direct-uapi'],
        );
    }

    /** A read that misses a domain fails its expectation, yet every later read still runs and cleanup is exact. */
    public function testAMissingPerDomainResultFailsTheRunButStopsNoRead(): void
    {
        $http = new DomainRowsServer();
        $http->oneDnsResult = true;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame(['expectation_failure'], $fixture['failures']);
        self::assertSame(['passed', 'failed', 'passed'], [$fixture['capture'], $fixture['expectations'],
            $fixture['cleanup']]);
        self::assertSame(DomainRowsRecipe::PHASES, array_column($scenario['probes'], 'phase'));
        self::assertSame(
            ['requested' => 2, 'returned' => 1, 'identities' => 'mismatch', 'order' => 'other'],
            $scenario['dns_results']['direct_authority_forward'],
        );
        self::assertSame('not_observable', $scenario['dns_results']['bridged_reside_reverse']['identities']);
        self::assertSame('failed', self::observation($scenario, 'bridged_reside_reverse')['expectation']);
        self::assertSame('passed', self::observation($scenario, 'direct_list_domains')['expectation']);
        self::assertFalse($http->account);
        self::assertSame([], $http->domains);
    }

    /** A read the server refuses is recorded and the remaining reads still run; it is never retried. */
    public function testARefusedReadIsRecordedOnceAndTheOthersStillRun(): void
    {
        $http = new DomainRowsServer();
        $http->failures['direct:DomainInfo/domains_data:2'] = 'api_before';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertSame('not_run', $fixture['expectations']);
        self::assertSame(2, $http->counts['direct:DomainInfo/domains_data']);
        self::assertSame('not_checked', self::observation($scenario, 'direct_domains_list')['expectation']);
        self::assertSame(DomainRowsRecipe::PHASES, array_column($scenario['probes'], 'phase'));
        self::assertSame('passed', $fixture['cleanup']);
    }

    /** A name that already exists anywhere is never adopted: nothing is raised or created, and the account goes. */
    #[DataProvider('preexistingNames')]
    public function testAnyPreexistingExtraNameCreatesNothing(string $override): void
    {
        $http = new DomainRowsServer();
        $http->overrides[$override === 'zone' ? 'listzones:2' : 'get_domain_info:2'] = $override === 'zone'
            ? '{"zone":[{"domain":"existing.example"},{"domain":"wldomains-alias.example.com"}]}'
            : '{"domains":[{"domain":"existing.example","user":"existing"},'
                . '{"domain":"wldomains-addon.example.com","user":"existing"}]}';
        $fixture = self::scenario($http)['fixture'];

        self::assertSame(['extra_identity_already_present', 'capture_failure'], $fixture['failures']);
        self::assertSame('extra_identity_already_present', $fixture['stopped_at']);
        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http->mutations);
        foreach (self::EXTRA as $resource) {
            self::assertSame(self::UNTOUCHED, $fixture['resources'][$resource]);
        }
        self::assertSame('passed', $fixture['cleanup']);
    }

    /** @return iterable<string, array{string}> */
    public static function preexistingNames(): iterable
    {
        yield 'domain' => ['domain'];
        yield 'zone' => ['zone'];
    }

    /** The server always hosts others, so an empty or unusable inventory cannot show the names absent. */
    #[DataProvider('unusableInventories')]
    public function testAnInventoryThatCannotProveAbsenceCreatesNothing(string $step, string $payload): void
    {
        $http = new DomainRowsServer();
        $http->overrides[$step] = $payload;
        $fixture = self::scenario($http)['fixture'];

        self::assertSame(['extra_identity_inventory_unusable', 'capture_failure'], $fixture['failures']);
        self::assertNotContains('modifyacct', $http->mutations);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableInventories(): iterable
    {
        yield 'empty domains' => ['get_domain_info:2', '{"domains":[]}'];
        yield 'object domains' => ['get_domain_info:2', '{"domains":{}}'];
        yield 'ownerless domain' => ['get_domain_info:2', '{"domains":[{"domain":"existing.example"}]}'];
        yield 'empty zones' => ['listzones:2', '{"zone":[]}'];
        yield 'duplicate zones' => ['listzones:2', '{"zone":[{"domain":"a.example"},{"domain":"a.example"}]}'];
    }

    /** Raising limits is a root mutation, so the marker is read again first; nothing proceeds on another's account. */
    public function testAForeignMarkerAtTheOwnershipGateStopsBeforeAnyMutation(): void
    {
        $http = new DomainRowsServer();
        $http->overrides['listaccts:3'] = '{"acct":[{"user":"wldomains","domain":"wldomains.example.com",'
            . '"email":"fixture-00000000000000000000000000000000@wldomains.example.com"}]}';
        $fixture = self::scenario($http)['fixture'];

        self::assertSame(['ownership_unconfirmed', 'capture_failure'], $fixture['failures']);
        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http->mutations);
        self::assertPrivate(self::scenario(new DomainRowsServer()));
    }

    /** An explicit refusal is a known outcome: nothing was made, so its later absence is proven. */
    public function testARefusedCreationIsAKnownAbsenceAndStopsTheRest(): void
    {
        $http = new DomainRowsServer();
        $http->failures[self::ADDON . ':1'] = 'api_before';
        $fixture = self::scenario($http)['fixture'];

        self::assertSame(['extra_identity_creation_failure', 'capture_failure'], $fixture['failures']);
        foreach (['addon_domain', 'addon_subdomain', 'addon_zone'] as $resource) {
            self::assertSame(self::REFUSED, $fixture['resources'][$resource]);
        }
        self::assertSame(self::UNTOUCHED, $fixture['resources']['subdomain']);
        self::assertSame(1, $http->counts[self::ADDON]);
        self::assertArrayNotHasKey(self::PARK, $http->counts);
    }

    /**
     * A reply that breaks the contract after the server accepted the creation still made the domain: the reads go on,
     * and the mismatch stays a failed observation in the review.
     */
    #[DataProvider('mismatchedSetupReplies')]
    public function testAnAcceptedSetupWhosePayloadMismatchesStillRunsEveryRead(string $step, string $phase): void
    {
        $http = new DomainRowsServer();
        $http->overrides[$step] = '"private unexpected scalar"';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertSame(DomainRowsRecipe::PHASES, array_column($scenario['probes'], 'phase'));
        self::assertSame('failed', self::observation($scenario, $phase)['payload_validation']);
        self::assertContains('unsuccessful_phase:' . $phase, $scenario['discrepancy_review']['findings']);
        self::assertSame(self::REMOVED, $fixture['resources']['addon_domain']);
        self::assertSame('passed', $fixture['cleanup']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function mismatchedSetupReplies(): iterable
    {
        yield 'limits' => ['modifyacct:1', 'limits'];
        yield 'addon' => [self::ADDON . ':1', 'create_addon'];
    }

    /** A creation whose answer was lost may still land, so no later read can call it absent; it is never resent. */
    #[DataProvider('lostCreations')]
    public function testALostCreationIsNeverReportedAbsentOrResent(string $mode): void
    {
        $http = new DomainRowsServer();
        $http->failures[self::PARK . ':1'] = $mode;
        $fixture = self::scenario($http)['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame(1, $http->counts[self::PARK]);
        foreach (['alias_domain', 'alias_zone'] as $resource) {
            $unsettled = ['creation' => 'unconfirmed', 'cleanup' => 'unconfirmed'];
            self::assertSame($unsettled, $fixture['resources'][$resource]);
            self::assertContains($resource . '_absence_unconfirmed', $fixture['failures']);
        }
        self::assertSame(self::REMOVED, $fixture['resources']['addon_domain']);
        self::assertFalse($http->account);
    }

    /** @return iterable<string, array{string}> */
    public static function lostCreations(): iterable
    {
        foreach (['throw_before', 'throw_after', 'http_after', 'invalid_after'] as $mode) {
            yield $mode => [$mode];
        }
    }

    /** A creation the server accepted but never lists for this account is not this run's. */
    public function testAnAcceptedCreationTheInventoryDoesNotListStopsTheReads(): void
    {
        $http = new DomainRowsServer();
        $http->afterEffect = static function (DomainRowsServer $server, string $step): void {
            if ($step === self::SUB . ':1') {
                unset($server->domains[DomainRowsRecipe::SUBDOMAIN]);
            }
        };
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(
            ['extra_identity_unconfirmed', 'capture_failure', 'expectation_failure'],
            $fixture['failures'],
        );
        self::assertSame(self::REFUSED, $fixture['resources']['subdomain']);
        self::assertSame(DomainRowsRecipe::SETUP_PHASES, array_column($scenario['probes'], 'phase'));
    }

    /** An extra name removal leaves behind is reported present and fails the run, without any further mutation. */
    public function testAnExtraNameThatSurvivesRemovalIsReportedPresent(): void
    {
        $http = new DomainRowsServer();
        $http->retain = [DomainRowsRecipe::ALIAS];
        $fixture = self::scenario($http)['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame(['alias_domain_still_present', 'alias_zone_still_present'], $fixture['failures']);
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'present'], $fixture['resources']['alias_domain']);
        self::assertSame(['creation' => 'confirmed', 'cleanup' => 'present'], $fixture['resources']['alias_zone']);
        self::assertSame(self::REMOVED, $fixture['resources']['subdomain']);
        self::assertSame(1, $http->counts['removeacct']);
    }

    /** An unreadable inventory after removal shows nothing, so every extra name stays unconfirmed. */
    public function testAnUnreadableFinalInventoryLeavesEveryNameUnconfirmed(): void
    {
        $http = new DomainRowsServer();
        // Lifecycle preflight, fixture preflight, one confirmation per creation, lifecycle final check, this proof.
        $http->failures['get_domain_info:7'] = 'invalid_before';
        $fixture = self::scenario($http)['fixture'];

        self::assertSame('confirmed_absent', $fixture['domain_cleanup']);
        foreach (['addon_domain', 'addon_subdomain', 'alias_domain', 'subdomain'] as $resource) {
            self::assertSame('unconfirmed', $fixture['resources'][$resource]['cleanup'], $resource);
            self::assertContains($resource . '_absence_unconfirmed', $fixture['failures']);
        }
        self::assertSame('confirmed_absent', $fixture['resources']['addon_zone']['cleanup']);
        self::assertSame(7, $http->counts['get_domain_info']);
    }

    /** Each extra zone gets its own control while it exists and its own final check after removal. */
    public function testFilesystemProofCoversEveryExtraZoneWithItsOwnControl(): void
    {
        $http = new DomainRowsServer();
        $absent = ['outcome' => 'confirmed_absent', 'residue' => []];
        $present = RecordingFilesystemProof::PRESENT;
        $proof = new RecordingFilesystemProof([$present, $present, $present, $absent, $absent, $absent]);
        $scenario = self::scenario($http, $proof);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['status'], (string) json_encode($fixture['failures']));
        self::assertSame([
            ['wldomains', DomainRowsRecipe::DOMAIN], ['wldomains', DomainRowsRecipe::ADDON],
            ['wldomains', DomainRowsRecipe::ALIAS], ['wldomains', DomainRowsRecipe::ADDON],
            ['wldomains', DomainRowsRecipe::ALIAS], ['wldomains', DomainRowsRecipe::DOMAIN],
        ], $proof->calls);
        foreach (['addon_domain', 'alias_domain'] as $label) {
            self::assertSame('passed', $fixture['extra_filesystem'][$label]['control']['status']);
            self::assertSame('confirmed_absent', $fixture['extra_filesystem'][$label]['cleanup']);
        }
        self::assertSame('confirmed_absent', $fixture['filesystem_cleanup']);
        self::assertContains('filesystem_proof_covers_fixed_locations_at_one_moment', $scenario['limitations']);
    }

    /** Without a passed control, an extra domain's later absence proves nothing about this server. */
    public function testAFailedExtraControlNeverTrustsALaterAbsence(): void
    {
        $absent = ['outcome' => 'confirmed_absent', 'residue' => []];
        $present = RecordingFilesystemProof::PRESENT;
        $proof = new RecordingFilesystemProof([$present, $absent, $present, $absent, $absent, $absent]);
        $scenario = self::scenario(new DomainRowsServer(), $proof);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame(
            ['extra_filesystem_control_failed', 'extra_filesystem_absence_unconfirmed'],
            $fixture['failures'],
        );
        self::assertSame('failed', $fixture['extra_filesystem']['addon_domain']['control']['status']);
        self::assertSame('unconfirmed', $fixture['extra_filesystem']['addon_domain']['cleanup']);
        self::assertSame('confirmed_absent', $fixture['extra_filesystem']['alias_domain']['cleanup']);
        // The account's own proof passed, but the claim covers every location only when each extra one passed too.
        self::assertSame('confirmed_absent', $fixture['filesystem_cleanup']);
        $limitation = 'api_absence_only_no_filesystem_or_home_directory_cleanup_claim';
        self::assertContains($limitation, $scenario['limitations']);
    }

    public function testExtraFilesystemResidueIsReportedByLocation(): void
    {
        $absent = ['outcome' => 'confirmed_absent', 'residue' => []];
        $present = RecordingFilesystemProof::PRESENT;
        $residue = ['outcome' => 'present', 'residue' => ['zones' => 1]];
        $proof = new RecordingFilesystemProof([$present, $present, $present, $absent, $residue, $absent]);
        $fixture = self::scenario(new DomainRowsServer(), $proof)['fixture'];

        self::assertSame(['extra_filesystem_residue_present'], $fixture['failures']);
        self::assertSame(['zones' => 1], $fixture['extra_filesystem']['alias_domain']['residue']);
    }

    /** The private inspection sees each phase's original body, in phase order, and nothing it says is kept. */
    public function testInspectorSeesEveryPhaseBodyOnce(): void
    {
        $seen = [];
        $fixture = new DomainRowsFixture(
            new DomainRowsServer(),
            'WHMTOKEN',
            DomainRowsEvidenceTest::context(),
            null,
            static function (string $phase, Probe $probe, ?string $raw) use (&$seen): void {
                $seen[] = [$phase, $probe->operation, $raw !== null];
            },
        );
        $scenario = $fixture->run();

        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertSame(DomainRowsRecipe::PHASES, array_column($seen, 0));
        self::assertNotContains(false, array_column($seen, 2));
    }

    /**
     * @param Scenario $scenario
     * @return array<string, mixed>
     */
    private static function observation(array $scenario, string $phase): array
    {
        foreach ($scenario['probes'] as $record) {
            if ($record['phase'] === $phase) {
                return $record;
            }
        }
        self::fail('No observation for ' . $phase);
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
    private static function scenario(DomainRowsServer $http, ?FilesystemAbsenceProof $filesystem = null): array
    {
        return (new DomainRowsFixture($http, 'WHMTOKEN', DomainRowsEvidenceTest::context(), $filesystem))->run();
    }

    /** @param array<mixed> $scenario */
    private static function assertPrivate(array $scenario): void
    {
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        foreach (
            ['private', 'WHMTOKEN', 'PRIVATEDOMAINROWSTOKEN', 'wldomains', 'example.com', 'wldsub', 'wldaddon',
                '/home', 'fixture-', '1790000000'] as $value
        ) {
            self::assertStringNotContainsString($value, $json);
        }
    }
}
