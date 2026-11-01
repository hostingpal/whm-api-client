<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use FilesystemIterator;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class VerifyCommandTest extends TestCase
{
    private const SECRET = 'synthetic-private-token-marker';

    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot === null) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->fixtureRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->fixtureRoot);
    }

    public function testHelpNeedsNoOptInOrCredentials(): void
    {
        [$status, $stdout, $stderr] = self::executeCli(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('--output=PATH', $stdout);
        self::assertSame('', $stderr);
    }

    public function testMissingOptInFailsBeforeCreatingOutput(): void
    {
        $output = sys_get_temp_dir() . '/live-verify-absent-' . bin2hex(random_bytes(8)) . '.json';
        [$status, $stdout, $stderr] = self::executeCli(['--output=' . $output]);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertFileDoesNotExist($output);
    }

    public function testMissingTokensFailBeforeCreatingOutput(): void
    {
        $output = sys_get_temp_dir() . '/live-verify-absent-' . bin2hex(random_bytes(8)) . '.json';
        [$status, $stdout, $stderr] = self::executeCli(['--output=' . $output], ['WHM_API_LIVE' => '1']);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertFileDoesNotExist($output);
    }

    public function testMysqlFixtureRequiresSeparateMutationOptInBeforeAnySetup(): void
    {
        $root = $this->fixtureHarness();
        [$status, , $stderr] = self::executeCli(
            ['--mysql-fixture', '--output=' . $root . '/observed.json'],
            self::fixtureEnvironment(),
            $root,
        );

        self::assertSame(1, $status);
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertFileDoesNotExist($root . '/fixture-cleaned');
        self::assertFileDoesNotExist($root . '/runner-invoked');
        self::assertFileDoesNotExist($root . '/observed.json');
    }

    #[DataProvider('mysqlFixtureOutcomes')]
    public function testMysqlFixturePersistsAfterCleanupAndControlsExit(string $case, string $expected, int $exit): void
    {
        $root = $this->fixtureHarness();
        $output = $root . '/observed.json';
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $output, '--mysql-fixture'],
            array_replace(self::fixtureEnvironment($case), ['WHM_API_LIVE_MUTATIONS' => '1']),
            $root,
        );

        self::assertSame($exit, $status);
        self::assertSame('', $stderr);
        self::assertFileExists($root . '/runner-invoked');
        self::assertFileExists($root . '/fixture-cleaned');
        self::assertSame('before-output', file_get_contents($root . '/fixture-cleaned'));
        $document = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['mysql_fixture']);
        self::assertSame($expected, $document['mysql_fixture']['status']);
        self::assertStringContainsString('MySQL fixture: ' . $expected, $stdout);
        self::assertIsArray($document['mysql_mutations']);
        self::assertSame([
            ['operation' => 'uapi:Mysql/create_database', 'method' => 'POST', 'route' => 'direct-uapi'],
        ], $document['mysql_mutations']['probes']);
        $mutationStatus = $case === 'mysql-mutation-failure' ? 'failed' : 'passed';
        self::assertStringContainsString('MySQL mutation evidence: ' . $mutationStatus, $stdout);
        self::assertSame('before-output', file_get_contents($root . '/mutations-assessed'));
        self::assertStringNotContainsString(self::SECRET, $stdout . $stderr . (string) file_get_contents($output));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function mysqlFixtureOutcomes(): iterable
    {
        yield 'success' => ['passed', 'passed', 0];
        yield 'cleanup failure despite passing probes' => ['mysql-cleanup-failure', 'failed', 1];
        yield 'capture failure still cleans' => ['mysql-capture-failure', 'failed', 1];
        yield 'mutation assessment failure despite cleanup and baseline passing' => [
            'mysql-mutation-failure', 'passed', 1,
        ];
    }

    public function testMysqlFixtureCleanupCompletesBeforeOutputWriteFailure(): void
    {
        $root = $this->fixtureHarness();
        [$status, , $stderr] = self::executeCli(
            ['--mysql-fixture', '--output=' . $root . '/observed.json'],
            array_replace(self::fixtureEnvironment('mysql-output-failure'), ['WHM_API_LIVE_MUTATIONS' => '1']),
            $root,
        );

        self::assertSame(1, $status);
        self::assertFileExists($root . '/fixture-cleaned');
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertSame([], glob($root . '/.live-verify-*'));
    }

    public function testMutationEnvironmentAloneDoesNotEnableMysqlFixture(): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            array_replace(self::fixtureEnvironment(), ['WHM_API_LIVE_MUTATIONS' => '1']),
            $root,
        );

        self::assertSame(0, $status);
        self::assertFileDoesNotExist($root . '/fixture-cleaned');
        self::assertStringNotContainsString('MySQL fixture:', $stdout);
    }

    public function testDuplicateFixtureFlagFailsBeforeSetup(): void
    {
        $root = $this->fixtureHarness();
        [$status] = self::executeCli(
            ['--mysql-fixture', '--mysql-fixture', '--output=' . $root . '/observed.json'],
            array_replace(self::fixtureEnvironment(), ['WHM_API_LIVE_MUTATIONS' => '1']),
            $root,
        );

        self::assertSame(1, $status);
        self::assertFileDoesNotExist($root . '/fixture-cleaned');
        self::assertFileDoesNotExist($root . '/runner-invoked');
    }

    public function testUnknownFlagsNeverEchoTheirPrivateContents(): void
    {
        [$status, $stdout, $stderr] = self::executeCli(['--private=' . self::SECRET]);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stderr);
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidFiniteQuotaConfiguration')]
    public function testFiniteQuotaRefusesInvalidConfigurationBeforeBaselineOrFixture(array $override): void
    {
        $root = $this->fixtureHarness();
        [$status, , $stderr] = self::executeCli(
            ['--finite-quota-fixture', '--output=' . $root . '/observed.json'],
            array_replace(self::finiteQuotaEnvironment(), $override),
            $root,
        );

        self::assertSame(1, $status);
        self::assertFileDoesNotExist($root . '/runner-invoked');
        self::assertFileDoesNotExist($root . '/finite-cleaned');
        self::assertFileDoesNotExist($root . '/observed.json');
        self::assertStringNotContainsString(self::SECRET, $stderr);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidFiniteQuotaConfiguration(): iterable
    {
        yield 'mutation opt-in absent' => [['WHM_API_LIVE_MUTATIONS' => '']];
        yield 'account absent' => [['WHM_API_LIVE_ACCOUNT' => '']];
        yield 'existing designated account still wrong recipe' => [['WHM_API_LIVE_ACCOUNT' => 'npr']];
        yield 'domain absent' => [['WHM_API_LIVE_DOMAIN' => '']];
        yield 'unrelated private domain' => [['WHM_API_LIVE_DOMAIN' => self::SECRET]];
    }

    #[DataProvider('finiteQuotaOutcomes')]
    public function testFiniteQuotaKeepsBaselineAssessmentSeparateAndCleansBeforeWriting(
        string $case,
        string $fixtureStatus,
        string $finiteStatus,
        int $exit,
    ): void {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--finite-quota-fixture', '--output=' . $root . '/observed.json'],
            self::finiteQuotaEnvironment($case),
            $root,
        );

        self::assertSame($exit, $status);
        self::assertSame('', $stderr);
        self::assertSame('before-output', file_get_contents($root . '/finite-cleaned'));
        $json = (string) file_get_contents($root . '/observed.json');
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame($document['probes'], json_decode(
            (string) file_get_contents($root . '/assessed-probes.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertIsArray($document['finite_quota']);
        self::assertIsArray($document['finite_quota']['fixture']);
        self::assertIsArray($document['finite_quota']['discrepancy_review']);
        self::assertSame('finite-quota-disposable-v1', $document['finite_quota']['scenario']);
        self::assertSame('disposable-test-account', $document['finite_quota']['target_role']);
        self::assertSame($fixtureStatus, $document['finite_quota']['fixture']['status']);
        self::assertSame($finiteStatus, $document['finite_quota']['discrepancy_review']['status']);
        self::assertArrayNotHasKey('mysql_fixture', $document);
        self::assertStringContainsString('Finite Quota fixture: ' . $fixtureStatus, $stdout);
        self::assertStringContainsString('Finite Quota discrepancy review: ' . $finiteStatus, $stdout);
        self::assertStringNotContainsString(self::SECRET, $json . $stdout . $stderr);
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function finiteQuotaOutcomes(): iterable
    {
        yield 'all pass' => ['passed', 'passed', 'passed', 0];
        yield 'cleanup failure' => ['quota-cleanup-failure', 'failed', 'passed', 1];
        yield 'finite discrepancies' => ['quota-discrepancy', 'passed', 'failed', 1];
        yield 'capture failure' => ['quota-capture-failure', 'failed', 'failed', 1];
        yield 'baseline discrepancies' => ['discrepancy', 'passed', 'passed', 1];
    }

    public function testFiniteQuotaCleanupCompletesBeforeOutputWriteFailure(): void
    {
        $root = $this->fixtureHarness();
        [$status, , $stderr] = self::executeCli(
            ['--finite-quota-fixture', '--output=' . $root . '/observed.json'],
            self::finiteQuotaEnvironment('quota-output-failure'),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('before-output', file_get_contents($root . '/finite-cleaned'));
        self::assertSame([], glob($root . '/.live-verify-*'));
        self::assertStringNotContainsString(self::SECRET, $stderr);
    }

    /** @param list<string> $flags */
    #[DataProvider('conflictingFixtureFlags')]
    public function testConflictingFixtureModesRefuseBeforeAnyRequests(array $flags): void
    {
        $root = $this->fixtureHarness();
        [$status] = self::executeCli(
            [...$flags, '--output=' . $root . '/observed.json'],
            self::finiteQuotaEnvironment(),
            $root,
        );

        self::assertSame(1, $status);
        self::assertFileDoesNotExist($root . '/runner-invoked');
        self::assertFileDoesNotExist($root . '/fixture-cleaned');
        self::assertFileDoesNotExist($root . '/finite-cleaned');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function conflictingFixtureFlags(): iterable
    {
        yield 'two mutation recipes' => [['--mysql-fixture', '--finite-quota-fixture']];
        yield 'duplicate finite recipe' => [['--finite-quota-fixture', '--finite-quota-fixture']];
    }

    public function testFiniteQuotaEnvironmentWithoutFlagKeepsDefaultReadOnly(): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            self::finiteQuotaEnvironment(),
            $root,
        );

        self::assertSame(0, $status);
        self::assertFileDoesNotExist($root . '/finite-cleaned');
        self::assertStringNotContainsString('Finite Quota', $stdout);
    }

    public function testFiniteQuotaUnavailableCatalogPreventsAllNetworkAndSetup(): void
    {
        $root = $this->fixtureHarness();
        [$status] = self::executeCli(
            ['--finite-quota-fixture', '--output=' . $root . '/observed.json'],
            self::finiteQuotaEnvironment('quota-unavailable-catalog'),
            $root,
        );

        self::assertSame(1, $status);
        self::assertFileDoesNotExist($root . '/runner-invoked');
        self::assertFileDoesNotExist($root . '/finite-cleaned');
        self::assertFileDoesNotExist($root . '/observed.json');
    }

    public function testFixtureHarnessWritesPrivateJsonAndCreatesParentDirectory(): void
    {
        $root = $this->fixtureHarness();
        $output = $root . '/evidence/observed.json';
        [$status, $stdout, $stderr] = self::executeCli(['--output=' . $output], self::fixtureEnvironment(), $root);

        self::assertSame(0, $status);
        self::assertFileExists($root . '/runner-invoked');
        self::assertSame(
            "whm1:version whm: passed\nDiscrepancy review: passed (acknowledged: 0, unexpected: 0, stale: 0)\n",
            $stdout,
        );
        self::assertSame('', $stderr);
        self::assertSame(0600, fileperms($output) & 0777);
        self::assertSame([
            'schema_version' => 2,
            'probes' => [[
                'operation' => 'whm1:version', 'route' => 'whm', 'probe_outcome' => 'passed', 'discrepancies' => [],
            ]],
            'discrepancy_review' => [
                'status' => 'passed', 'acknowledged' => [], 'unexpected' => [], 'stale' => [],
                'policy' => 'reviewed-quota-discrepancies-v1', 'tracking' => 'whm-api-client-8x0.4.3.1',
                'evidence' => 'docs/api/evidence/2026-10-16-quota-inventory.json',
                'evidence_sha256' => str_repeat('a', 64),
            ],
        ], json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame([], glob($root . '/evidence/.live-verify-*'));
    }

    public function testFixtureHarnessTreatsZeroTokenAsNonempty(): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            array_replace(self::fixtureEnvironment(), ['OBLAK_TEST_WHM_KEY' => '0', 'OBLAK_TEST_NPR_KEY' => '0']),
            $root,
        );

        self::assertSame(0, $status);
        self::assertSame('', $stderr);
        self::assertSame(
            "whm1:version whm: passed\nDiscrepancy review: passed (acknowledged: 0, unexpected: 0, stale: 0)\n",
            $stdout,
        );
    }

    #[DataProvider('inventoryLabels')]
    public function testFixtureHarnessPrintsReviewedInventoryLabels(string $operation, string $route): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            array_replace(self::fixtureEnvironment(), [
                'CLI_FIXTURE_OPERATION' => $operation, 'CLI_FIXTURE_ROUTE' => $route,
            ]),
            $root,
        );

        self::assertSame(0, $status);
        self::assertSame(
            $operation . ' ' . $route . ": passed\n"
                . "Discrepancy review: passed (acknowledged: 0, unexpected: 0, stale: 0)\n",
            $stdout,
        );
        self::assertSame('', $stderr);
    }

    public function testEveryCatalogProbeHasAnIndependentCliLabelCase(): void
    {
        $covered = [];
        foreach (self::inventoryLabels() as [$operation, $route]) {
            $covered[$operation . '|' . $route] = true;
        }
        foreach (ProbeCatalog::load(dirname(__DIR__, 3)) as $probe) {
            self::assertArrayHasKey($probe->operation . '|' . $probe->route, $covered);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function inventoryLabels(): iterable
    {
        yield 'server version' => ['whm1:version', 'whm'];
        yield 'server load' => ['whm1:systemloadavg', 'whm'];
        foreach (
            ['DomainInfo/list_domains', 'Email/list_pops', 'DomainInfo/domains_data',
            'DomainInfo/single_domain_data', 'Features/get_feature_metadata', 'Features/has_feature',
            'Features/has_features_like', 'Features/list_features_like', 'LangPHP/php_get_vhost_versions',
            'LangPHP/php_ini_get_user_basic_directives', 'LangPHP/php_ini_get_user_content',
            'LangPHP/php_ini_get_user_paths'] as $function
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                yield $function . ' ' . $route => ['uapi:' . $function, $route];
            }
        }
        yield 'direct database inventory' => ['uapi:Mysql/list_databases', 'direct-uapi'];
        yield 'bridged database inventory' => ['uapi:Mysql/list_databases', 'bridged-uapi'];
        yield 'direct database user inventory' => ['uapi:Mysql/list_users', 'direct-uapi'];
        yield 'bridged database user inventory' => ['uapi:Mysql/list_users', 'bridged-uapi'];
        foreach (['get_restrictions', 'get_server_information', 'get_privileges_on_database'] as $function) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                yield $function . ' ' . $route => ['uapi:Mysql/' . $function, $route];
            }
        }
        yield 'direct total quota' => ['uapi:Quota/get_quota_info', 'direct-uapi'];
        yield 'bridged total quota' => ['uapi:Quota/get_quota_info', 'bridged-uapi'];
        yield 'direct local quota' => ['uapi:Quota/get_local_quota_info', 'direct-uapi'];
        yield 'bridged local quota' => ['uapi:Quota/get_local_quota_info', 'bridged-uapi'];
        yield 'account search' => ['whm1:listaccts', 'whm'];
        yield 'account summary' => ['whm1:accountsummary', 'whm'];
        yield 'domain inventory' => ['whm1:get_domain_info', 'whm'];
        yield 'WHM PHP versions' => ['whm1:php_get_installed_versions', 'whm'];
        yield 'WHM default PHP' => ['whm1:php_get_system_default_version', 'whm'];
        yield 'direct primary domain' => ['uapi:DomainInfo/primary_domain', 'direct-uapi'];
        yield 'bridged primary domain' => ['uapi:DomainInfo/primary_domain', 'bridged-uapi'];
        yield 'direct PHP versions' => ['uapi:LangPHP/php_get_installed_versions', 'direct-uapi'];
        yield 'bridged PHP versions' => ['uapi:LangPHP/php_get_installed_versions', 'bridged-uapi'];
        yield 'direct PHP handler' => ['uapi:LangPHP/php_get_domain_handler', 'direct-uapi'];
        yield 'bridged PHP handler' => ['uapi:LangPHP/php_get_domain_handler', 'bridged-uapi'];
        yield 'direct mail domains' => ['uapi:Email/list_mail_domains', 'direct-uapi'];
        yield 'bridged mail domains' => ['uapi:Email/list_mail_domains', 'bridged-uapi'];
        yield 'direct domain forwarders' => ['uapi:Email/list_domain_forwarders', 'direct-uapi'];
        yield 'bridged domain forwarders' => ['uapi:Email/list_domain_forwarders', 'bridged-uapi'];
        yield 'direct mailbox count' => ['uapi:Email/count_pops', 'direct-uapi'];
        yield 'bridged mailbox count' => ['uapi:Email/count_pops', 'bridged-uapi'];
        yield 'direct-uapi installed_hosts' => ['uapi:SSL/installed_hosts', 'direct-uapi'];
        yield 'bridged-uapi installed_hosts' => ['uapi:SSL/installed_hosts', 'bridged-uapi'];
        yield 'direct-uapi get_autossl_excluded_domains' => ['uapi:SSL/get_autossl_excluded_domains', 'direct-uapi'];
        yield 'bridged-uapi get_autossl_excluded_domains' => ['uapi:SSL/get_autossl_excluded_domains', 'bridged-uapi'];
        yield 'direct-uapi get_autossl_problems' => ['uapi:SSL/get_autossl_problems', 'direct-uapi'];
        yield 'bridged-uapi get_autossl_problems' => ['uapi:SSL/get_autossl_problems', 'bridged-uapi'];
        yield 'direct-uapi get_autossl_renewal_status' => ['uapi:SSL/get_autossl_renewal_status', 'direct-uapi'];
        yield 'bridged-uapi get_autossl_renewal_status' => ['uapi:SSL/get_autossl_renewal_status', 'bridged-uapi'];
        yield 'direct-uapi is_autossl_check_in_progress' => ['uapi:SSL/is_autossl_check_in_progress', 'direct-uapi'];
        yield 'bridged-uapi is_autossl_check_in_progress' => ['uapi:SSL/is_autossl_check_in_progress', 'bridged-uapi'];
        yield 'direct-uapi can_ssl_redirect' => ['uapi:SSL/can_ssl_redirect', 'direct-uapi'];
        yield 'bridged-uapi can_ssl_redirect' => ['uapi:SSL/can_ssl_redirect', 'bridged-uapi'];
    }

    #[DataProvider('unsuccessfulEvidence')]
    public function testFixtureHarnessRetainsEvidenceOnFailedExit(string $case): void
    {
        $root = $this->fixtureHarness();
        $output = $root . '/observed.json';
        file_put_contents($output, 'previous evidence');
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $output],
            self::fixtureEnvironment($case),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('', $stderr);
        self::assertStringContainsString('whm1:version whm:', $stdout);
        $document = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(2, $document['schema_version']);
        self::assertIsArray($document['discrepancy_review']);
        self::assertSame('failed', $document['discrepancy_review']['status']);
        self::assertStringContainsString('Discrepancy review: failed', $stdout);
        self::assertSame([], glob($root . '/.live-verify-*'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsuccessfulEvidence(): iterable
    {
        yield 'discrepancies' => ['discrepancy'];
        yield 'probe failure' => ['failed'];
        yield 'skipped' => ['skipped'];
        yield 'stale acknowledgment' => ['stale'];
    }

    public function testAcknowledgedDiscrepanciesPassWithoutChangingObservedRecords(): void
    {
        $root = $this->fixtureHarness();
        $output = $root . '/observed.json';
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $output],
            self::fixtureEnvironment('acknowledged'),
            $root,
        );

        self::assertSame(0, $status);
        self::assertSame('', $stderr);
        self::assertSame(
            "uapi:Quota/get_quota_info direct-uapi: passed\n"
                . "uapi:Quota/get_quota_info bridged-uapi: passed\n"
                . "Discrepancy review: passed (acknowledged: 2, unexpected: 0, stale: 0)\n"
                . "Acknowledged: fixture-direct (fixture label)\n"
                . "Acknowledged: fixture-bridge (fixture label)\n",
            $stdout,
        );
        $document = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(2, $document['schema_version']);
        $expectedProbes = [
            [
                'operation' => 'uapi:Quota/get_quota_info', 'route' => 'direct-uapi',
                'probe_outcome' => 'passed', 'payload_paths' => ['$.*' => ['int']],
                'discrepancies' => [['path' => '$.*', 'kind' => 'unknown_field']],
            ],
            [
                'operation' => 'uapi:Quota/get_quota_info', 'route' => 'bridged-uapi',
                'probe_outcome' => 'passed', 'payload_paths' => ['$.*' => ['int']],
                'discrepancies' => [['path' => '$.*', 'kind' => 'unknown_field']],
            ],
        ];
        self::assertSame($expectedProbes, $document['probes']);
        self::assertSame($expectedProbes, json_decode(
            (string) file_get_contents($root . '/assessed-probes.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertIsArray($document['discrepancy_review']);
        self::assertSame(['fixture-direct', 'fixture-bridge'], $document['discrepancy_review']['acknowledged']);
        self::assertSame([], glob($root . '/.live-verify-*'));
    }

    public function testStaleAcknowledgmentFailsWhenEveryObservedProbePasses(): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            self::fixtureEnvironment('stale'),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('', $stderr);
        self::assertSame(
            "whm1:version whm: passed\nDiscrepancy review: failed (acknowledged: 0, unexpected: 0, stale: 1)\n"
                . "Stale: fixture-direct (fixture label)\n",
            $stdout,
        );
    }

    public function testEmptyRunKeepsFailedAssessmentAndEmptyObservedRecords(): void
    {
        $root = $this->fixtureHarness();
        $output = $root . '/observed.json';
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $output],
            self::fixtureEnvironment('empty'),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('', $stderr);
        self::assertSame(
            "Discrepancy review: failed (acknowledged: 0, unexpected: 1, stale: 0)\nUnexpected: empty run\n",
            $stdout,
        );
        $document = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame([], $document['probes']);
        self::assertIsArray($document['discrepancy_review']);
        self::assertSame('failed', $document['discrepancy_review']['status']);
    }

    #[DataProvider('policyPreflightFailures')]
    public function testPolicyPreflightFailuresNeverRunOrPrepareOutput(string $case): void
    {
        $root = $this->fixtureHarness();
        $output = $root . '/not-created/observed.json';
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $output],
            self::fixtureEnvironment($case),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertDirectoryDoesNotExist(dirname($output));
        self::assertFileDoesNotExist($root . '/runner-invoked');
    }

    /** @return iterable<string, array{string}> */
    public static function policyPreflightFailures(): iterable
    {
        yield 'reference cannot be loaded' => ['policy-load-failure'];
        yield 'output would overwrite reference' => ['policy-output-failure'];
    }

    public function testUnexpectedAssessmentDetailsNeverReachConsole(): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            self::fixtureEnvironment('private-review'),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('', $stderr);
        self::assertStringContainsString('unexpected: 1', $stdout);
        self::assertStringContainsString('Unexpected: unrecognized probe (unacknowledged discrepancy)', $stdout);
        self::assertStringNotContainsString(self::SECRET, $stdout);
    }

    #[DataProvider('unexpectedReasons')]
    public function testUnexpectedFindingsPrintOnlyReviewedOperationAndReasonLabels(string $case, string $reason): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            self::fixtureEnvironment($case),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Unexpected: whm1:version whm (' . $reason . ')', $stdout);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unexpectedReasons(): iterable
    {
        yield 'unacknowledged discrepancy' => ['discrepancy', 'unacknowledged discrepancy'];
        yield 'duplicate probe' => ['duplicate', 'duplicate probe'];
        yield 'failed probe' => ['failed', 'unsuccessful probe'];
        yield 'skipped probe' => ['skipped', 'unsuccessful probe'];
    }

    public function testUnrecognizedUnexpectedReasonFailsWithoutReachingConsole(): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            self::fixtureEnvironment('private-reason'),
            $root,
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stdout);
        self::assertStringNotContainsString(self::SECRET, $stderr);
    }

    #[DataProvider('privateAcknowledgmentIds')]
    public function testUnknownAcknowledgmentIdsFailWithoutReachingConsole(string $case): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            self::fixtureEnvironment($case),
            $root,
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stdout);
        self::assertStringNotContainsString(self::SECRET, $stderr);
    }

    /** @return iterable<string, array{string}> */
    public static function privateAcknowledgmentIds(): iterable
    {
        yield 'unreviewed acknowledgment' => ['private-acknowledgment'];
        yield 'unreviewed stale acknowledgment' => ['private-stale'];
    }

    public function testFixtureHarnessOutputFailureNeverLeaksPrivatePath(): void
    {
        $root = $this->fixtureHarness();
        $output = $root . '/' . self::SECRET;
        mkdir($output);
        [$status, $stdout, $stderr] = self::executeCli(['--output=' . $output], self::fixtureEnvironment(), $root);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertFileDoesNotExist($root . '/runner-invoked');
        self::assertSame([], glob($root . '/.live-verify-*'));
    }

    #[DataProvider('symlinkDestinations')]
    public function testFixtureHarnessRejectsSymlinkDestinationBeforeRunner(bool $existingTarget): void
    {
        $root = $this->fixtureHarness();
        $target = $root . '/target.json';
        if ($existingTarget) {
            file_put_contents($target, 'existing evidence');
        }
        $output = $root . '/output.json';
        symlink($target, $output);
        [$status, $stdout, $stderr] = self::executeCli(['--output=' . $output], self::fixtureEnvironment(), $root);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertFileDoesNotExist($root . '/runner-invoked');
        self::assertTrue(is_link($output));
        if ($existingTarget) {
            self::assertSame('existing evidence', file_get_contents($target));
        } else {
            self::assertFileDoesNotExist($target);
        }
        self::assertSame([], glob($root . '/.live-verify-*'));
    }

    /** @return iterable<string, array{bool}> */
    public static function symlinkDestinations(): iterable
    {
        yield 'regular file target' => [true];
        yield 'dangling link' => [false];
    }

    public function testFixtureHarnessUnexpectedExceptionNeverLeaksItsMessage(): void
    {
        $root = $this->fixtureHarness();
        [$status, $stdout, $stderr] = self::executeCli(
            ['--output=' . $root . '/observed.json'],
            self::fixtureEnvironment('exception'),
            $root,
        );

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Live verification failed', $stderr);
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertFileDoesNotExist($root . '/observed.json');
        self::assertSame([], glob($root . '/.live-verify-*'));
    }

    /**
     * Isolated CLI boundary: the copied entrypoint cannot load real CurlClient or make network requests.
     * Core runner, context and transport behavior is covered separately against their real classes.
     */
    private function fixtureHarness(): string
    {
        $root = sys_get_temp_dir() . '/verify-command-' . bin2hex(random_bytes(8));
        $this->fixtureRoot = $root;
        mkdir($root . '/tools/live-verify', 0700, true);
        mkdir($root . '/vendor', 0700);
        copy(dirname(__DIR__, 3) . '/tools/live-verify/verify.php', $root . '/tools/live-verify/verify.php');
        file_put_contents($root . '/vendor/autoload.php', <<<'FIXTURE'
<?php
namespace HostingPal\WhmApiClient\Tools\LiveVerify;
final class RepositoryContext
{
    public static function load(string $root): array { return []; }
}
final class ProbeCatalog
{
    public static function load(string $root): array
    {
        return getenv('CLI_FIXTURE_CASE') === 'quota-unavailable-catalog'
            ? [(object) ['unavailable' => 'unsupported']] : [];
    }
}
final class DiscrepancyPolicy
{
    public static function load(string $root): self
    {
        if (getenv('CLI_FIXTURE_CASE') === 'policy-load-failure') {
            throw new \RuntimeException(getenv('CLI_PRIVATE_MARKER'));
        }
        return new self();
    }
    public function assertOutputAllowed(string $output): void
    {
        if (getenv('CLI_FIXTURE_CASE') === 'policy-output-failure') {
            throw new \RuntimeException(getenv('CLI_PRIVATE_MARKER'));
        }
    }
    public function acknowledgmentLabel(string $id): ?string
    {
        return in_array($id, ['fixture-direct', 'fixture-bridge'], true) ? $id . ' (fixture label)' : null;
    }
    public function assess(array $records): array
    {
        file_put_contents(dirname(__DIR__) . '/assessed-probes.json', json_encode($records));
        $case = getenv('CLI_FIXTURE_CASE');
        $unexpected = in_array($case, [
            'failed', 'skipped', 'discrepancy', 'duplicate', 'empty', 'private-review', 'private-reason',
        ], true);
        $failed = $unexpected || $case === 'stale';
        $reason = match ($case) {
            'failed', 'skipped' => 'unsuccessful_probe',
            'duplicate' => 'duplicate_probe',
            'empty' => 'empty_run',
            'private-reason' => getenv('CLI_PRIVATE_MARKER'),
            default => 'unacknowledged_discrepancy',
        };
        $review = [
            'status' => $failed ? 'failed' : 'passed',
            'acknowledged' => $case === 'acknowledged' ? ['fixture-direct', 'fixture-bridge'] : [],
            'unexpected' => $unexpected ? [[
                'operation' => $case === 'private-review' ? getenv('CLI_PRIVATE_MARKER') : 'whm1:version',
                'variant' => 'whm1:version#GET', 'route' => 'whm', 'reason' => $reason,
            ]] : [],
            'stale' => $case === 'stale' ? ['fixture-direct'] : [],
            'policy' => 'reviewed-quota-discrepancies-v1', 'tracking' => 'whm-api-client-8x0.4.3.1',
            'evidence' => 'docs/api/evidence/2026-10-16-quota-inventory.json',
            'evidence_sha256' => str_repeat('a', 64),
        ];
        if ($case === 'private-acknowledgment') {
            $review['acknowledged'] = [getenv('CLI_PRIVATE_MARKER')];
        } elseif ($case === 'private-stale') {
            $review['stale'] = [getenv('CLI_PRIVATE_MARKER')];
        }
        return $review;
    }
}
final class CurlClient
{
    public function __construct(bool $allowFormPost = false) {}
}
final class MysqlFixture
{
    public function __construct(object $client, array $tokens, string $suffix, array $context) {}
    public function run(array $probes, \Closure $capture): array
    {
        $root = dirname(__DIR__);
        $records = $capture($probes);
        $case = getenv('CLI_FIXTURE_CASE');
        $timing = file_exists($root . '/observed.json') ? 'too-late' : 'before-output';
        file_put_contents($root . '/fixture-cleaned', $timing);
        if ($case === 'mysql-output-failure') {
            mkdir($root . '/observed.json');
        }
        return ['probes' => $records, 'mysql_mutations' => [
            ['operation' => 'uapi:Mysql/create_database', 'method' => 'POST', 'route' => 'direct-uapi'],
        ], 'mysql_fixture' => [
            'recipe' => 'mysql-npr-linked-unlinked-v1',
            'status' => in_array($case, ['mysql-cleanup-failure', 'mysql-capture-failure'], true) ? 'failed' : 'passed',
        ]];
    }
}
final class MysqlMutationEvidence
{
    public static function finish(array $records, array $baseline): array
    {
        $root = dirname(__DIR__);
        if (!file_exists($root . '/fixture-cleaned') || $baseline === []) {
            throw new \RuntimeException(getenv('CLI_PRIVATE_MARKER'));
        }
        $timing = file_exists($root . '/observed.json') ? 'too-late' : 'before-output';
        file_put_contents($root . '/mutations-assessed', $timing);
        return ['probes' => $records, 'discrepancy_review' => [
            'status' => getenv('CLI_FIXTURE_CASE') === 'mysql-mutation-failure' ? 'failed' : 'passed',
        ]];
    }
}
final class EvidenceRunner
{
    public function __construct(object $client, array $context) {}
    public function run(array $probes, string|false $optIn, array $tokens): array
    {
        file_put_contents(dirname(__DIR__) . '/runner-invoked', '1');
        $case = getenv('CLI_FIXTURE_CASE');
        if ($case === 'exception') {
            throw new \RuntimeException(getenv('CLI_PRIVATE_MARKER'));
        }
        if ($case === 'empty') {
            return [];
        }
        if ($case === 'acknowledged') {
            return array_map(static fn (string $route): array => [
                'operation' => 'uapi:Quota/get_quota_info', 'route' => $route,
                'probe_outcome' => 'passed', 'payload_paths' => ['$.*' => ['int']],
                'discrepancies' => [['path' => '$.*', 'kind' => 'unknown_field']],
            ], ['direct-uapi', 'bridged-uapi']);
        }
        return [[
            'operation' => getenv('CLI_FIXTURE_OPERATION') ?: 'whm1:version',
            'route' => getenv('CLI_FIXTURE_ROUTE') ?: 'whm',
            'probe_outcome' => in_array($case, ['failed', 'skipped'], true) ? $case : 'passed',
            'discrepancies' => $case === 'discrepancy' ? [['path' => '$.load', 'kind' => 'type_mismatch']] : [],
        ]];
    }
}
final class FiniteQuotaEvidence
{
    public function __construct(object $client, array $context) {}
    public function run(array $probes, string $whmToken, string $cpanelToken): array
    {
        return [
            'probes' => [['operation' => 'uapi:Quota/get_quota_info', 'route' => 'direct-uapi']],
            'expectations' => 'passed',
            'unit_relationships' => ['direct-uapi' => 'binary-mib', 'bridged-uapi' => 'binary-mib'],
            'discrepancy_review' => [
                'status' => in_array(getenv('CLI_FIXTURE_CASE'), [
                    'quota-discrepancy', 'quota-capture-failure',
                ], true) ? 'failed' : 'passed',
                'acknowledged' => [],
            ],
        ];
    }
}
final class FiniteQuotaFixture
{
    public function __construct(object $client, string $token) {}
    public function run(\Closure $capture): array
    {
        $root = dirname(__DIR__);
        $case = getenv('CLI_FIXTURE_CASE');
        $result = $capture(new CurlClient(), getenv('CLI_PRIVATE_MARKER'));
        $timing = file_exists($root . '/observed.json') ? 'too-late' : 'before-output';
        file_put_contents($root . '/finite-cleaned', $timing);
        if ($case === 'quota-output-failure') {
            mkdir($root . '/observed.json');
        }
        unset($result['expectations']);
        return [
            'scenario' => 'finite-quota-disposable-v1',
            'target_role' => 'disposable-test-account',
            ...$result,
            'fixture' => ['status' => in_array($case, [
                'quota-cleanup-failure', 'quota-capture-failure',
            ], true) ? 'failed' : 'passed'],
        ];
    }
}
FIXTURE);

        return $root;
    }

    /** @return array<string, string> */
    private static function fixtureEnvironment(string $case = 'passed'): array
    {
        return [
            'WHM_API_LIVE' => '1',
            'OBLAK_TEST_WHM_KEY' => self::SECRET,
            'OBLAK_TEST_NPR_KEY' => self::SECRET,
            'CLI_FIXTURE_CASE' => $case,
            'CLI_PRIVATE_MARKER' => self::SECRET,
        ];
    }

    /** @return array<string, string> */
    private static function finiteQuotaEnvironment(string $case = 'passed'): array
    {
        return array_replace(self::fixtureEnvironment($case), [
            'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'wqfinite',
            'WHM_API_LIVE_DOMAIN' => 'wqfinite.example.com',
        ]);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{int, string, string}
     */
    private static function executeCli(
        array $arguments,
        array $environment = [],
        ?string $root = null,
    ): array {
        $root ??= dirname(__DIR__, 3);
        $process = proc_open(
            [PHP_BINARY, $root . '/tools/live-verify/verify.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            array_replace([
                'WHM_API_LIVE' => '',
                'OBLAK_TEST_WHM_KEY' => self::SECRET,
                'OBLAK_TEST_NPR_KEY' => '',
            ], $environment),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }
}
