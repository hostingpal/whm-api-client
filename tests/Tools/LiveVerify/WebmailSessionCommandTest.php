<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The real entry script, run as a process against an offline server double and a recording `ssh` first on PATH.
 *
 * The stand-in answers each fixed script by what it reads on standard input, as the server would: the proof's control
 * sees the account, its final check sees the session files account removal left, and the rerun after removal sees
 * nothing. Every call is recorded, so no real connection can start.
 */
final class WebmailSessionCommandTest extends TestCase
{
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

    /** The lifecycle's own truth stands: removal left session files, so the run fails even after they are removed. */
    public function testRemovesLeftSessionFilesAndPublishesProtectedEvidence(): void
    {
        $directory = $this->sandbox();
        $result = self::runCommand($directory, ['PATH' => self::standInSsh($directory, 4)]);

        self::assertSame(1, $result['status'], implode("\n", $result['output']));
        self::assertSame([
            'Webmail session fixture: failed', 'Webmail session discrepancy review: passed',
            'Webmail session files: removed',
        ], $result['output']);
        self::assertSame(['proof', 'proof', 'observe', 'remove', 'proof'], self::sshCalls($directory));
        $file = $directory . '/evidence.json';
        self::assertSame(0600, fileperms($file) & 0777);
        $json = file_get_contents($file);
        self::assertIsString($json);
        foreach (['private', 'PRIVATE', 'wlsession', 'example.com', '192.0.2', 'cpsess'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(1, $report['schema_version']);
        self::assertIsArray($report['webmail_sessions']);
        $scenario = $report['webmail_sessions'];
        self::assertIsArray($scenario['fixture']);
        self::assertSame(['filesystem_residue_present'], $scenario['fixture']['failures']);
        self::assertSame(['sessions' => 4], $scenario['fixture']['filesystem_residue']);
        self::assertIsArray($scenario['session_files']);
        self::assertSame(['outcome' => 'confirmed_absent', 'residue' => []], $scenario['session_files']['reproof']);
        self::assertSame([], glob($directory . '/.webmail-sessions-*'));
    }

    public function testACleanRemovalNeedsNoSessionFileCleanupAndPasses(): void
    {
        $directory = $this->sandbox();
        $result = self::runCommand($directory, ['PATH' => self::standInSsh($directory, 0)]);

        self::assertSame(0, $result['status'], implode("\n", $result['output']));
        self::assertSame([
            'Webmail session fixture: passed', 'Webmail session discrepancy review: passed',
            'Webmail session files: not_required',
        ], $result['output']);
        self::assertSame(['proof', 'proof', 'observe'], self::sshCalls($directory));
    }

    public function testRefusesToOverwriteEvidenceBeforeAnyTransport(): void
    {
        $directory = $this->sandbox();
        file_put_contents($directory . '/evidence.json', 'existing');
        $result = self::runCommand($directory, ['PATH' => self::standInSsh($directory, 4)]);

        self::assertSame(1, $result['status']);
        self::assertSame(['Webmail session verification failed (configuration_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/ssh-calls');
        self::assertSame('existing', file_get_contents($directory . '/evidence.json'));
    }

    /** @param array<string, string> $environment */
    #[DataProvider('refusedEnvironments')]
    public function testWrongConfigurationStopsBeforeAnyRequest(array $environment): void
    {
        $directory = $this->sandbox();
        $result = self::runCommand($directory, ['PATH' => self::standInSsh($directory, 4), ...$environment]);

        self::assertSame(1, $result['status']);
        self::assertSame(['Webmail session verification failed (configuration_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/ssh-calls');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function refusedEnvironments(): iterable
    {
        yield 'no live opt-in' => [['WHM_API_LIVE' => '0']];
        yield 'no mutation opt-in' => [['WHM_API_LIVE_MUTATIONS' => '0']];
        yield 'no filesystem proof' => [['WHM_API_LIVE_FS_CHECK' => '']];
        yield 'npr account' => [['WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs']];
        yield 'another disposable account' => [['WHM_API_LIVE_ACCOUNT' => 'wlupload',
            'WHM_API_LIVE_DOMAIN' => 'wlupload.example.com']];
        yield 'other domain' => [['WHM_API_LIVE_DOMAIN' => 'other.example.com']];
        yield 'no credential' => [['OBLAK_TEST_WHM_KEY' => '']];
    }

    public function testNeverReadsTheNprToken(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/tools/live-verify/webmail-sessions.php');
        self::assertIsString($script);
        self::assertStringNotContainsString('OBLAK_TEST_NPR_KEY', $script);
    }

    public function testHelpAndInvalidOptionsNeedNoCredentialsOrNetwork(): void
    {
        $script = dirname(__DIR__, 3) . '/tools/live-verify/webmail-sessions.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
        self::assertSame(0, $status);
        self::assertStringContainsString('webmail-sessions.php --output=PATH', implode("\n", $output));
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --bad 2>&1', $output, $status);
        self::assertSame(1, $status);
        self::assertSame(['Webmail session verification failed (configuration_failure).'], $output);
    }

    /**
     * Put a recording `ssh` first on PATH. It names each fixed script it reads, and answers like the server.
     *
     * @param int $left Session files the final proof finds after account removal.
     */
    private static function standInSsh(string $directory, int $left): string
    {
        mkdir($directory . '/bin', 0700);
        $report = static function (int $account, int $sessions): string {
            $lines = '';
            foreach (['home', 'users', 'userdata', 'userdomains', 'passwd', 'zones', 'sessions'] as $location) {
                $lines .= $location . '_inventory=2\n' . $location . '='
                    . ($location === 'sessions' ? $sessions : $account) . '\n';
            }

            return $lines . 'complete=1\n';
        };
        $classified = static function (string $prefix, int $files) use ($left): string {
            $counts = ['inventory' => 40 - $left + $files, 'wide' => $files, 'own' => 0, 'mailbox_encoded' => 0,
                'mailbox_literal' => $files, 'anchored_other' => 0, 'unexplained' => 0, 'token_suffix' => 0,
                'directories' => $files === 0 ? 0 : 2];
            $lines = '';
            foreach ($counts as $label => $count) {
                $lines .= $prefix . $label . '=' . $count . '\n';
            }

            return $lines;
        };
        $observed = $classified('', $left) . 'complete=1\n';
        $removed = $classified('before_', $left) . 'deleted=' . $left . '\n' . $classified('after_', 0)
            . 'complete=1\n';
        $calls = escapeshellarg($directory . '/ssh-calls');
        file_put_contents($directory . '/bin/ssh', "#!/bin/sh\nscript=$(cat)\n"
            . 'case "$script" in' . "\n"
            . "  *home_inventory*)\n"
            . '    proofs=$(grep -c proof ' . $calls . " 2>/dev/null)\n"
            . "    echo proof >> " . $calls . "\n"
            . '    case "$proofs" in' . "\n"
            . "      ''|0) printf '" . $report(1, 0) . "' ;;\n"
            . "      1) printf '" . $report(0, $left) . "' ;;\n"
            . "      *) printf '" . $report(0, 0) . "' ;;\n"
            . "    esac ;;\n"
            . "  *-delete*) echo remove >> " . $calls . "; printf '" . $removed . "' ;;\n"
            . "  *) echo observe >> " . $calls . "; printf '" . $observed . "' ;;\n"
            . "esac\n");
        chmod($directory . '/bin/ssh', 0700);

        return $directory . '/bin:' . getenv('PATH');
    }

    /** @return list<string> */
    private static function sshCalls(string $directory): array
    {
        $calls = file($directory . '/ssh-calls', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($calls);

        return $calls;
    }

    /** Build an isolated CLI harness: real script, offline HTTP double and verified repository context. */
    private function sandbox(): string
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('CLI requires the cURL extension even with offline transport.');
        }
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/webmail-sessions-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->directories[] = $directory;
        mkdir($directory . '/tools/live-verify', 0700, true);
        mkdir($directory . '/vendor', 0700);
        copy($root . '/tools/live-verify/webmail-sessions.php', $directory . '/tools/live-verify/webmail-sessions.php');
        $server = '\\HostingPal\\WhmApiClient\\Tests\\Tools\\LiveVerify\\WebmailSessionServer';
        $interface = '\\Psr\\Http\\Message';
        $autoload = '<?php namespace HostingPal\\WhmApiClient\\Tools\\LiveVerify; '
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . 'require_once ' . var_export(__DIR__ . '/WebmailSessionServer.php', true) . '; '
            . 'final class RepositoryContext {'
            . ' public static function load(string $root): array {'
            . ' return ' . var_export(WebmailSessionFixtureTest::context(), true) . '; } }'
            . 'final class CurlClient implements \\Psr\\Http\\Client\\ClientInterface {'
            . ' private ' . $server . ' $server;'
            . ' public function __construct(bool $allowFormPost) {'
            . ' $this->server = new ' . $server . '();'
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
    private static function runCommand(string $directory, array $environment): array
    {
        // exec() inherits this process's environment, so every opt-in is set here rather than left to chance.
        $defaults = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_FS_CHECK' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'wlsession', 'WHM_API_LIVE_DOMAIN' => 'wlsession.example.com',
            'OBLAK_TEST_WHM_KEY' => 'private-whm'];
        $variables = '';
        foreach ($environment + $defaults as $key => $value) {
            $variables .= $key . '=' . escapeshellarg($value) . ' ';
        }
        exec($variables . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($directory . '/tools/live-verify/webmail-sessions.php')
            . ' --output=' . escapeshellarg($directory . '/evidence.json') . ' 2>&1', $output, $status);

        return ['status' => $status, 'output' => $output];
    }
}
