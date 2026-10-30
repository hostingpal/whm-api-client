<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EmailSectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailSectionFixtureTest extends TestCase
{
    /** @var array<string, string|false> */
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

    public function testSafeInventoryAndBothOwnedMailboxLifecyclesCompleteWithoutSendingMail(): void
    {
        $server = new EmailSectionServer();
        $server->mailboxes['unrelated@npr.rs'] = ['quota' => 500, 'password' => 'private-existing', 'suspended' => 0];
        $report = $this->fixture($server)->run();
        self::assertSame('passed', $report['status']);
        self::assertSame('passed', $report['assessment']['status']);
        self::assertSame(['unrelated@npr.rs'], array_keys($server->mailboxes));
        self::assertSame(['wema1b2c3d@npr.rs', 'wema1b2c3b@npr.rs'], $server->deleted);
        self::assertSame(['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'], $report['resources']);
        self::assertCount(45, $report['probes']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private', 'wema1b2c3', 'unrelated', 'test.oblak.host'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        foreach ($server->requests as $request) {
            $uri = (string) $request->getUri();
            foreach (
                ['dispatch_client_settings', 'release_outgoing', 'trace_filter', 'add_auto_responder'] as $outbound
            ) {
                self::assertStringNotContainsString($outbound, $uri);
            }
            if (str_contains((string) $request->getBody(), 'password=')) {
                self::assertSame('POST', $request->getMethod());
                self::assertStringNotContainsString('password=', $request->getUri()->getQuery());
                parse_str((string) $request->getBody(), $body);
                self::assertIsString($body['password']);
                self::assertStringNotContainsString($body['password'], $json);
            }
        }
    }

    /** @return iterable<array{string}> */
    public static function preflightFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                ['list_pops', 'count_pops', 'list_mail_domains', 'list_domain_forwarders',
                'list_forwarders', 'list_mxs', 'get_default_email_quota', 'get_max_email_quota'] as $function
            ) {
                yield [$function . '/' . $route . '/1'];
            }
        }
    }

    #[DataProvider('preflightFailures')]
    public function testAnyFailedPreflightFinishesBothRouteReadsAndBlocksAllMutations(string $key): void
    {
        $server = new EmailSectionServer();
        $server->failures[$key] = 'before';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertCount(17, $report['probes']);
        self::assertContains('preflight_failure', $report['failures']);
        self::assertSame(['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'], $report['resources']);
        self::assertSame([], $server->deleted);
        foreach ($server->requests as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    /** @return iterable<array{string}> */
    public static function uncertainCreations(): iterable
    {
        yield ['before'];
        yield ['after'];
        yield ['lost'];
        yield ['delayed'];
    }

    #[DataProvider('uncertainCreations')]
    public function testUnconfirmedCreationsAreNeverAdoptedDeletedOrDeclaredClean(string $failure): void
    {
        $server = new EmailSectionServer();
        $server->failures['add_pop/direct/1'] = $failure;
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['resources']);
        self::assertSame([], $server->deleted);
        self::assertContains('unresolved_mutation', $report['failures']);
        $server->completeDelayed();
        if ($failure !== 'before') {
            self::assertArrayHasKey('wema1b2c3d@npr.rs', $server->mailboxes);
        }
    }

    public function testConfirmedCreationPayloadMismatchStillDeletesOnlyTheOwnedMailbox(): void
    {
        $server = new EmailSectionServer();
        $server->overrides['add_pop/direct/1'] = 'false';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->mailboxes);
        self::assertContains('wema1b2c3d@npr.rs', $server->deleted);
        self::assertSame('cleaned', $report['resources']['direct-uapi']);
        $record = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'create_mailbox' && $record['route'] === 'direct-uapi'))[0];
        self::assertSame('success', $record['api_outcome']);
        self::assertSame('failed', $record['payload_validation']);
    }

    public function testCandidateCollisionAndIncompleteIdentityInventoryPreventCreation(): void
    {
        foreach (
            ['[{"login":"wema1b2c3d@npr.rs"}]', '[{}]', '[{"login":"duplicate"},{"login":"duplicate"}]'] as $payload
        ) {
            $server = new EmailSectionServer();
            $server->overrides['list_pops/direct/1'] = $payload;
            $report = $this->fixture($server)->run();
            self::assertSame('failed', $report['status']);
            self::assertSame([], $server->deleted);
            self::assertCount(17, $server->requests);
        }
    }

    public function testUnknownFieldsAreMaskedAndFailStrictAssessment(): void
    {
        $server = new EmailSectionServer();
        $server->overrides['list_mail_domains/direct/1'] =
            '[{"domain":"npr.rs","private-new-name":"private-value"}]';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertContains('unacknowledged_discrepancy', $report['assessment']['findings']);
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertCount(17, $server->requests);
    }

    public function testMissingOptInWrongTargetAndReuseNeverSendAnotherRequest(): void
    {
        $server = new EmailSectionServer();
        putenv('WHM_API_LIVE_DOMAIN=other');
        self::assertSame('failed', $this->fixture($server)->run()['status']);
        self::assertSame([], $server->requests);
        putenv('WHM_API_LIVE_DOMAIN=npr.rs');
        $fixture = $this->fixture($server);
        self::assertSame('passed', $fixture->run()['status']);
        $count = count($server->requests);
        self::assertSame('failed', $fixture->run()['status']);
        self::assertCount($count, $server->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ownedFailures(): iterable
    {
        yield 'quota readback mismatch' => ['get_pop_quota/direct/1', '31'];
        yield 'changed quota readback mismatch' => ['get_pop_quota/direct/2', '63'];
        yield 'password readback rejects new password' => ['verify_password/direct/1', '0'];
        yield 'suspension readback mismatch' => ['list_pops/direct/4',
            '[{"login":"wema1b2c3d@npr.rs","suspended_login":0}]'];
        yield 'resumption readback mismatch' => ['list_pops/direct/5',
            '[{"login":"wema1b2c3d@npr.rs","suspended_login":1}]'];
    }

    #[DataProvider('ownedFailures')]
    public function testSemanticMismatchStillCleansConfirmedOwnedMailbox(string $key, string $payload): void
    {
        $server = new EmailSectionServer();
        $server->overrides[$key] = $payload;
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('cleaned', $report['resources']['direct-uapi']);
        self::assertSame([], $server->mailboxes);
        self::assertContains('wema1b2c3d@npr.rs', $server->deleted);
        $failed = array_filter($report['probes'], static fn (array $record): bool =>
            $record['expectation'] === 'failed');
        self::assertCount(1, $failed);
    }

    public function testLostDeletionIsNeverRetriedAndCannotClaimConfirmedCleanup(): void
    {
        $server = new EmailSectionServer();
        $server->failures['delete_pop/direct/1'] = 'lost';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['resources']);
        self::assertSame([], $server->mailboxes);
        self::assertSame(['wema1b2c3d@npr.rs'], $server->deleted);
        self::assertContains('direct-uapi:cleanup_unconfirmed', $report['failures']);
    }

    public function testFailedDeleteRetainsMailboxForManualReviewWithoutSecondRouteCreation(): void
    {
        $server = new EmailSectionServer();
        $server->failures['delete_pop/direct/1'] = 'before';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(['wema1b2c3d@npr.rs'], array_keys($server->mailboxes));
        self::assertSame([], $server->deleted);
        self::assertSame(['direct-uapi' => 'uncertain', 'bridged-uapi' => 'not_started'], $report['resources']);
    }

    public function testConfirmedDeletePayloadMismatchRetainsFailureAndVerifiesAbsence(): void
    {
        $server = new EmailSectionServer();
        $server->overrides['delete_pop/direct/1'] = 'false';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('cleaned', $report['resources']['direct-uapi']);
        self::assertSame([], $server->mailboxes);
        self::assertContains('direct-uapi:cleanup_failure', $report['failures']);
    }

    public function testAtDelimitedCreatePayloadDoesNotSatisfyThePinnedPlusDelimitedIdentity(): void
    {
        $server = new EmailSectionServer();
        $server->overrides['add_pop/direct/1'] = '"wema1b2c3d@npr.rs"';
        $report = $this->fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('cleaned', $report['resources']['direct-uapi']);
        self::assertContains('wema1b2c3d@npr.rs', $server->deleted);
        $create = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'create_mailbox' && $record['route'] === 'direct-uapi'))[0];
        self::assertSame('failed', $create['expectation']);
    }

    private function fixture(EmailSectionServer $server): EmailSectionFixture
    {
        return new EmailSectionFixture($server, ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'], 'a1b2c3', [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]);
    }
}
