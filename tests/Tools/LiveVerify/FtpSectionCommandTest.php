<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FtpSectionCommandTest extends TestCase
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

    public function testDirtyRepositoryStopsBeforeAnyTransportConstruction(): void
    {
        $directory = $this->sandbox(false);
        exec('git init --quiet ' . escapeshellarg($directory), $output, $status);
        self::assertSame(0, $status);
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertSame(['FTP section verification failed (execution_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    public function testCreatesProtectedNewEvidenceAndRefusesToOverwriteBeforeTransport(): void
    {
        $directory = $this->sandbox(true);
        $result = self::runCommand($directory);
        self::assertSame(0, $result['status']);
        self::assertSame(['FTP section fixture: passed'], $result['output']);
        $file = $directory . '/evidence.json';
        $json = file_get_contents($file);
        self::assertIsString($json);
        self::assertJson($json);
        self::assertStringNotContainsString('private-', $json);
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame([], glob($directory . '/.ftp-section-*'));
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
        $retained = glob($directory . '/.ftp-section-*');
        self::assertIsArray($retained);
        self::assertCount(1, $retained);
        self::assertSame(0600, fileperms($retained[0]) & 0777);
        self::assertSame(['FTP section verification failed (execution_failure).',
            'FTP section report retained at: ' . $retained[0]], $result['output']);
        $json = file_get_contents($retained[0]);
        self::assertIsString($json);
        self::assertStringNotContainsString('private-', $json);
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(1, $report['schema_version']);
        self::assertIsArray($report['ftp_section']);
        self::assertSame('passed', $report['ftp_section']['status']);
        self::assertSame(
            ['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'],
            $report['ftp_section']['resources'],
        );
        self::assertIsArray($report['ftp_section']['probes']);
        self::assertCount(41, $report['ftp_section']['probes']);
    }

    /** Build an isolated CLI harness: real script, offline HTTP double, optional real repository context. */
    private function sandbox(bool $verifiedContext, bool $destinationRace = false): string
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('CLI requires the cURL extension even with offline transport.');
        }
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/ftp-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->directories[] = $directory;
        mkdir($directory . '/tools/live-verify', 0700, true);
        mkdir($directory . '/vendor', 0700);
        mkdir($directory . '/src', 0700);
        file_put_contents($directory . '/src/uncommitted.php', '<?php');
        copy($root . '/tools/live-verify/ftp-section.php', $directory . '/tools/live-verify/ftp-section.php');
        $context = $verifiedContext ? 'final class RepositoryContext {'
            . ' public static function load(string $root): array {'
            . ' return ' . var_export(FtpSectionFixtureTest::context(), true) . '; } }' : '';
        $autoload = '<?php namespace HostingPal\\WhmApiClient\\Tools\\LiveVerify; '
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . '; ' . $context
            . 'final class CurlClient implements \\Psr\\Http\\Client\\ClientInterface {'
            . 'private \\HostingPal\\WhmApiClient\\Tests\\Tools\\LiveVerify\\FtpSectionServer $server;'
            . ' public function __construct(bool $allowFormPost) { file_put_contents('
            . var_export($directory . '/transport-used', true) . ', "used");'
            . ($destinationRace ? 'file_put_contents(' . var_export($directory . '/evidence.json', true)
                . ', "concurrent destination");' : '')
            . ' $this->server = new \\HostingPal\\WhmApiClient\\Tests\\Tools\\LiveVerify\\FtpSectionServer(); }'
            . ' public function sendRequest(\\Psr\\Http\\Message\\RequestInterface $request): '
            . '\\Psr\\Http\\Message\\ResponseInterface { return $this->server->sendRequest($request); } }';
        file_put_contents($directory . '/vendor/autoload.php', $autoload);

        return $directory;
    }

    /** @return array{status: int, output: list<string>} */
    private static function runCommand(string $directory): array
    {
        exec('WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs '
            . 'OBLAK_TEST_WHM_KEY=private-whm OBLAK_TEST_NPR_KEY=private-cpanel '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($directory . '/tools/live-verify/ftp-section.php')
            . ' --output=' . escapeshellarg($directory . '/evidence.json') . ' 2>&1', $output, $status);

        return ['status' => $status, 'output' => $output];
    }

    public function testHelpAndInvalidOptionsNeedNoCredentialsOrNetwork(): void
    {
        $script = dirname(__DIR__, 3) . '/tools/live-verify/ftp-section.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
        self::assertSame(0, $status);
        self::assertStringContainsString('WHM_API_LIVE_DOMAIN=npr.rs', implode("\n", $output));
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --private-token=secret 2>&1', $output, $status);
        self::assertSame(1, $status);
        self::assertStringNotContainsString('secret', implode("\n", $output));
    }

    public function testMissingPrerequisitesLeaveDestinationAbsentAndErrorsSanitized(): void
    {
        $script = dirname(__DIR__, 3) . '/tools/live-verify/ftp-section.php';
        $outputPath = sys_get_temp_dir() . '/ftp-evidence-' . bin2hex(random_bytes(8)) . '.json';
        exec('WHM_API_LIVE=0 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --output=' . escapeshellarg($outputPath) . ' 2>&1', $output, $status);
        self::assertSame(1, $status);
        self::assertFileDoesNotExist($outputPath);
        self::assertSame(['FTP section verification failed (configuration_failure).'], $output);
    }
}
