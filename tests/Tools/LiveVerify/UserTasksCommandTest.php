<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class UserTasksCommandTest extends TestCase
{
    private const SCRIPT = '/tools/live-verify/user-tasks.php';

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
        self::assertSame(['User task verification failed (execution_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    public function testCreatesProtectedNewEvidenceAndRefusesToOverwriteBeforeTransport(): void
    {
        $directory = $this->sandbox(true);
        $result = self::runCommand($directory);
        self::assertSame(0, $result['status'], implode("\n", $result['output']));
        self::assertSame([
            'User task fixture: passed', 'User task discrepancy review: passed', 'User task producer review: failed',
        ], $result['output']);
        $file = $directory . '/evidence.json';
        $json = file_get_contents($file);
        self::assertIsString($json);
        self::assertJson($json);
        self::assertStringNotContainsString('private', $json);
        self::assertStringNotContainsString('wltasks', $json);
        self::assertStringNotContainsString('5a9ec8dd', $json);
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame([], glob($directory . '/.user-tasks-*'));
        unlink($directory . '/transport-used');
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertSame($json, file_get_contents($file));
    }

    public function testFailedRunPublishesItsEvidenceAndExitsOne(): void
    {
        $directory = $this->sandbox(true, 0);
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertSame([
            'User task fixture: failed', 'User task discrepancy review: failed', 'User task producer review: passed',
        ], $result['output']);
        $report = self::fixtureReport($directory);
        self::assertSame('failed', $report['status']);
        self::assertSame('passed', $report['cleanup']);
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
        self::assertIsArray($report['filesystem_control']);
        self::assertSame('passed', $report['filesystem_control']['status']);
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
        self::assertSame(['User task verification failed (configuration_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function refusedEnvironments(): iterable
    {
        yield 'no live opt-in' => [['WHM_API_LIVE' => '0']];
        yield 'no mutation opt-in' => [['WHM_API_LIVE_MUTATIONS' => '0']];
        yield 'npr account' => [['WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs']];
        yield 'another wave account' => [['WHM_API_LIVE_ACCOUNT' => 'wlupload',
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
        self::assertStringContainsString('user-tasks.php --output=PATH', implode("\n", $output));
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --bad 2>&1', $output, $status);
        self::assertSame(1, $status);
        self::assertSame(['User task verification failed (configuration_failure).'], $output);
    }

    /** Put a recording `ssh` first on PATH that shows the account on its first call and nothing on its second. */
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
        self::assertSame(1, $report['schema_version']);
        self::assertIsArray($report['user_tasks']);
        self::assertIsArray($report['user_tasks']['fixture']);

        return $report['user_tasks']['fixture'];
    }

    /** Build an isolated CLI harness: real script, offline HTTP double, optional real repository context. */
    private function sandbox(bool $verifiedContext, ?int $finishAfterReads = null): string
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('CLI requires the cURL extension even with offline transport.');
        }
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/user-tasks-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->directories[] = $directory;
        mkdir($directory . '/tools/live-verify', 0700, true);
        mkdir($directory . '/vendor', 0700);
        copy($root . self::SCRIPT, $directory . self::SCRIPT);
        $context = $verifiedContext ? 'final class RepositoryContext {'
            . ' public static function load(string $root): array {'
            . ' return ' . var_export(FilemanUploadFixtureTest::context(), true) . '; } }' : '';
        $server = '\\HostingPal\\WhmApiClient\\Tests\\Tools\\LiveVerify\\UserTasksServer';
        $interface = '\\Psr\\Http\\Message';
        $autoload = '<?php namespace HostingPal\\WhmApiClient\\Tools\\LiveVerify; '
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . '; ' . $context
            . 'final class CurlClient implements \\Psr\\Http\\Client\\ClientInterface {'
            . 'private ' . $server . ' $server;'
            . ' public function __construct(bool $allowFormPost) {'
            . ' $this->server = new ' . $server . '();'
            . ($finishAfterReads === null ? '' : ' $this->server->finishAfterReads = ' . $finishAfterReads . ';')
            . ' file_put_contents(' . var_export($directory . '/transport-used', true) . ', "used"); }'
            . ' public function sendRequest(' . $interface . '\\RequestInterface $request): '
            . $interface . '\\ResponseInterface { return $this->server->sendRequest($request); } }';
        file_put_contents($directory . '/vendor/autoload.php', $autoload);

        return $directory;
    }

    /**
     * @param array<string, string> $environment
     * @return array{status: int, output: list<string>}
     */
    private static function runCommand(string $directory, array $environment = []): array
    {
        // exec() inherits this process's environment, so the opt-in is cleared here rather than left to chance.
        $defaults = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wltasks',
            'WHM_API_LIVE_DOMAIN' => 'wltasks.example.com', 'OBLAK_TEST_WHM_KEY' => 'private-whm',
            'WHM_API_LIVE_FS_CHECK' => ''];
        $variables = '';
        foreach ($environment + $defaults as $key => $value) {
            $variables .= $key . '=' . escapeshellarg($value) . ' ';
        }
        exec($variables . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($directory . self::SCRIPT)
            . ' --output=' . escapeshellarg($directory . '/evidence.json') . ' 2>&1', $output, $status);

        return ['status' => $status, 'output' => $output];
    }
}
