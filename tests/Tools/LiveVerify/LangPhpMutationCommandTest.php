<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LangPhpMutationCommandTest extends TestCase
{
    private const SCRIPT = '/tools/live-verify/php-mutations.php';

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry instanceof SplFileInfo) {
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
            }
            rmdir($directory);
        }
    }

    public function testDirtyRepositoryStopsBeforeAnyTransportConstruction(): void
    {
        $directory = $this->sandbox(false);
        exec('git init --quiet ' . escapeshellarg($directory), $output, $status);
        self::assertSame(0, $status);
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertSame(['LangPHP mutation verification failed (execution_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    public function testCreatesProtectedNewEvidenceAndRefusesToOverwriteBeforeTransport(): void
    {
        $directory = $this->sandbox(true);
        $result = self::runCommand($directory);
        self::assertSame(0, $result['status'], implode("\n", $result['output']));
        self::assertSame(
            ['LangPHP mutation fixture: passed', 'LangPHP mutation discrepancy review: passed'],
            $result['output'],
        );
        $file = $directory . '/evidence.json';
        $json = file_get_contents($file);
        self::assertIsString($json);
        self::assertJson($json);
        self::assertStringNotContainsString('private', $json);
        self::assertStringNotContainsString('wlphpset', $json);
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame([], glob($directory . '/.php-mutations-*'));
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(1, $report['schema_version']);
        self::assertIsArray($report['langphp_mutations']);
        unlink($directory . '/transport-used');
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertSame($json, file_get_contents($file));
    }

    public function testDestinationRaceRetainsCompleteProtectedReportAndNamesItsPath(): void
    {
        $directory = $this->sandbox(true, true);
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertSame('concurrent destination', file_get_contents($directory . '/evidence.json'));
        $retained = glob($directory . '/.php-mutations-*');
        self::assertIsArray($retained);
        self::assertCount(1, $retained);
        self::assertSame(0600, fileperms($retained[0]) & 0777);
        self::assertSame(['LangPHP mutation verification failed (execution_failure).',
            'LangPHP mutation report retained at: ' . $retained[0]], $result['output']);
        $json = file_get_contents($retained[0]);
        self::assertIsString($json);
        self::assertStringNotContainsString('private', $json);
    }

    public function testFailedRunPublishesItsEvidenceAndExitsOne(): void
    {
        $directory = $this->sandbox(true, false, true);
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertSame(
            ['LangPHP mutation fixture: failed', 'LangPHP mutation discrepancy review: failed'],
            $result['output'],
        );
        self::assertFileExists($directory . '/evidence.json');
    }

    public function testFilesystemOptInRunsTheProofTwiceAndRecordsItsOutcome(): void
    {
        $directory = $this->sandbox(true);
        $path = self::standInSsh($directory);
        $result = self::runCommand($directory, ['WHM_API_LIVE_FS_CHECK' => '1', 'PATH' => $path]);

        self::assertSame(0, $result['status'], implode("\n", $result['output']));
        self::assertSame("used\nused\n", file_get_contents($directory . '/ssh-used'));
        $report = self::fixtureReport($directory);
        self::assertSame('confirmed_absent', $report['filesystem_cleanup']);
    }

    public function testWithoutTheOptInNoConnectionIsMadeAndNothingIsClaimed(): void
    {
        $directory = $this->sandbox(true);
        $path = self::standInSsh($directory);
        $result = self::runCommand($directory, ['PATH' => $path]);

        self::assertSame(0, $result['status'], implode("\n", $result['output']));
        self::assertFileDoesNotExist($directory . '/ssh-used');
        self::assertSame('not_claimed', self::fixtureReport($directory)['filesystem_cleanup']);
    }

    /** @param array<string, string> $environment */
    #[DataProvider('refusedEnvironments')]
    public function testWrongTargetStopsBeforeTheAutoloaderOrAnyRequest(array $environment): void
    {
        $directory = $this->sandbox(true);
        $result = self::runCommand($directory, $environment);
        self::assertSame(1, $result['status']);
        self::assertSame(['LangPHP mutation verification failed (configuration_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function refusedEnvironments(): iterable
    {
        yield 'no live opt-in' => [['WHM_API_LIVE' => '0']];
        yield 'no mutation opt-in' => [['WHM_API_LIVE_MUTATIONS' => '0']];
        yield 'npr account' => [['WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs']];
        yield 'another fixture account' => [['WHM_API_LIVE_ACCOUNT' => 'wlupload',
            'WHM_API_LIVE_DOMAIN' => 'wlupload.example.com']];
        yield 'other domain' => [['WHM_API_LIVE_DOMAIN' => 'other.example.com']];
        yield 'no credential' => [['OBLAK_TEST_WHM_KEY' => '']];
    }

    public function testNeverReadsTheNprToken(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . self::SCRIPT);
        self::assertIsString($script);
        self::assertStringNotContainsString('OBLAK_TEST_NPR_KEY', $script);
    }

    public function testHelpAndInvalidOptionsNeedNoCredentialsOrNetwork(): void
    {
        $script = dirname(__DIR__, 3) . self::SCRIPT;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
        self::assertSame(0, $status);
        self::assertStringContainsString('php-mutations.php --output=PATH', implode("\n", $output));
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --bad 2>&1', $output, $status);
        self::assertSame(1, $status);
        self::assertSame(['LangPHP mutation verification failed (configuration_failure).'], $output);
    }

    /** Put a recording `ssh` first on PATH that shows the account once, then nothing, so no connection starts. */
    private static function standInSsh(string $directory): string
    {
        mkdir($directory . '/bin', 0700);
        $present = '';
        $absent = '';
        foreach (['home', 'users', 'userdata', 'userdomains', 'passwd', 'zones', 'sessions'] as $location) {
            $present .= $location . '_inventory=2\n' . $location . '=' . ($location === 'sessions' ? 0 : 1) . '\n';
            $absent .= $location . '_inventory=2\n' . $location . '=0\n';
        }
        $used = escapeshellarg($directory . '/ssh-used');
        file_put_contents($directory . '/bin/ssh', "#!/bin/sh\ncat > /dev/null\n"
            . 'if [ -e ' . $used . " ]; then printf '" . $absent . "complete=1\\n'; "
            . "else printf '" . $present . "complete=1\\n'; fi\n"
            . 'echo used >> ' . $used . "\n");
        chmod($directory . '/bin/ssh', 0700);

        return $directory . '/bin:' . getenv('PATH');
    }

    /** @return array<mixed> */
    private static function fixtureReport(string $directory): array
    {
        $json = file_get_contents($directory . '/evidence.json');
        self::assertIsString($json);
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['langphp_mutations']);
        self::assertIsArray($report['langphp_mutations']['fixture']);

        return $report['langphp_mutations']['fixture'];
    }

    /** Build an isolated CLI harness: real script, offline HTTP double, optional real repository context. */
    private function sandbox(bool $verifiedContext, bool $destinationRace = false, bool $failing = false): string
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('CLI requires the cURL extension even with offline transport.');
        }
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/php-mutations-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->directories[] = $directory;
        mkdir($directory . '/tools/live-verify', 0700, true);
        mkdir($directory . '/vendor', 0700);
        mkdir($directory . '/src', 0700);
        file_put_contents($directory . '/src/uncommitted.php', '<?php');
        copy($root . self::SCRIPT, $directory . self::SCRIPT);
        $context = $verifiedContext ? 'final class RepositoryContext {'
            . ' public static function load(string $root): array {'
            . ' return ' . var_export(LangPhpMutationFixtureTest::context(), true) . '; } }' : '';
        $server = '\\HostingPal\\WhmApiClient\\Tests\\Tools\\LiveVerify\\LangPhpMutationServer';
        $interface = '\\Psr\\Http\\Message';
        $autoload = '<?php namespace HostingPal\\WhmApiClient\\Tools\\LiveVerify; '
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . '; ' . $context
            . 'final class CurlClient implements \\Psr\\Http\\Client\\ClientInterface {'
            . 'public static ?' . $server . ' $server = null;'
            . ' public function __construct(bool $allowFormPost) {'
            . ' self::$server = new ' . $server . '();'
            . ($failing ? ' self::$server->doubleDecode = true;' : '')
            . ' file_put_contents(' . var_export($directory . '/transport-used', true) . ', "used");'
            . ($destinationRace ? 'file_put_contents(' . var_export($directory . '/evidence.json', true)
                . ', "concurrent destination");' : '')
            . ' }'
            . ' public function sendRequest(' . $interface . '\\RequestInterface $request): '
            . $interface . '\\ResponseInterface { return self::$server->sendRequest($request); } }';
        file_put_contents($directory . '/vendor/autoload.php', $autoload);

        return $directory;
    }

    /**
     * @param array<string, string> $environment
     * @return array{status: int, output: list<string>}
     */
    private static function runCommand(string $directory, array $environment = []): array
    {
        // exec() inherits this process's environment, so the opt-ins are set here rather than left to chance.
        $defaults = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlphpset',
            'WHM_API_LIVE_DOMAIN' => 'wlphpset.example.com', 'OBLAK_TEST_WHM_KEY' => 'private-whm',
            'WHM_API_LIVE_FS_CHECK' => ''];
        $variables = '';
        foreach ($environment + $defaults as $key => $value) {
            $variables .= $key . '=' . escapeshellarg($value) . ' ';
        }
        exec($variables . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($directory . self::SCRIPT)
            . ' --output=' . escapeshellarg($directory . '/evidence.json') . ' 2>&1', $output, $status);

        return ['status' => $status, 'output' => $output];
    }
}
