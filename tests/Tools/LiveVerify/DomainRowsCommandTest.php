<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DomainRowsCommandTest extends TestCase
{
    private const FAILED = 'Domain rows verification failed (configuration_failure).';

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
        self::assertSame(['Domain rows verification failed (execution_failure).'], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    public function testCreatesProtectedNewEvidenceAndRefusesToOverwriteBeforeTransport(): void
    {
        $directory = $this->sandbox(true);
        $result = self::runCommand($directory);
        self::assertSame(0, $result['status'], implode("\n", $result['output']));
        self::assertSame(['Domain rows fixture: passed', 'Domain rows discrepancy review: passed'], $result['output']);
        $file = $directory . '/evidence.json';
        $json = file_get_contents($file);
        self::assertIsString($json);
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(1, $report['schema_version']);
        self::assertIsArray($report['domain_rows']);
        self::assertSame('domain-rows-disposable-v1', $report['domain_rows']['scenario']);
        foreach (['private', 'wldomains', 'example.com', 'WHMTOKEN'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame([], glob($directory . '/.domain-rows-*'));
        unlink($directory . '/transport-used');
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertSame($json, file_get_contents($file));
    }

    public function testFailedRunPublishesItsEvidenceAndExitsOne(): void
    {
        $directory = $this->sandbox(true, true);
        $result = self::runCommand($directory);
        self::assertSame(1, $result['status']);
        self::assertSame(['Domain rows fixture: failed', 'Domain rows discrepancy review: failed'], $result['output']);
        self::assertFileExists($directory . '/evidence.json');
    }

    /** The private inspection prints names, types and fixed labels only, and writes no evidence at all. */
    public function testNameInspectionPrintsNamesAndTypesOnlyAndWritesNothing(): void
    {
        $directory = $this->sandbox(true);
        $result = self::runCommand($directory, [], '--inspect-names');
        $output = implode("\n", $result['output']);

        self::assertSame(0, $result['status'], $output);
        self::assertStringContainsString('phase direct_domains_list direct-uapi: probe=passed', $output);
        self::assertStringContainsString('dns whm_authority_reverse: returned=2 identities=request_set', $output);
        self::assertStringContainsString('resource alias_zone: confirmed confirmed_absent', $output);
        // The synthetic rows carry undeclared keys only where a test puts them; the double's rows are all declared.
        self::assertStringNotContainsString('undeclared', $output);
        foreach (['private', 'wldomains', 'example', '/home', 'WHMTOKEN', 'fixture-'] as $value) {
            self::assertStringNotContainsString($value, $output);
        }
        self::assertSame(['bin', 'src', 'tools', 'transport-used', 'vendor'], self::entries($directory));
    }

    /** @param array<string, string> $environment */
    #[DataProvider('refusedEnvironments')]
    public function testWrongTargetStopsBeforeTheAutoloaderOrAnyRequest(array $environment): void
    {
        $directory = $this->sandbox(true);
        $result = self::runCommand($directory, $environment);
        self::assertSame(1, $result['status']);
        self::assertSame([self::FAILED], $result['output']);
        self::assertFileDoesNotExist($directory . '/transport-used');
        self::assertFileDoesNotExist($directory . '/evidence.json');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function refusedEnvironments(): iterable
    {
        yield 'no live opt-in' => [['WHM_API_LIVE' => '0']];
        yield 'no mutation opt-in' => [['WHM_API_LIVE_MUTATIONS' => '0']];
        yield 'npr account' => [['WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs']];
        yield 'other domain' => [['WHM_API_LIVE_DOMAIN' => 'other.example.com']];
        yield 'no credential' => [['OBLAK_TEST_WHM_KEY' => '']];
    }

    public function testNeverReadsTheNprToken(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/tools/live-verify/domain-rows.php');
        self::assertIsString($script);
        self::assertStringNotContainsString('OBLAK_TEST_NPR_KEY', $script);
    }

    public function testHelpAndInvalidOptionsNeedNoCredentialsOrNetwork(): void
    {
        $script = dirname(__DIR__, 3) . '/tools/live-verify/domain-rows.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
        self::assertSame(0, $status);
        self::assertStringContainsString('domain-rows.php --output=PATH', implode("\n", $output));
        self::assertStringContainsString('domain-rows.php --inspect-names', implode("\n", $output));
        foreach (['--bad', '--inspect-names --output=x', '--output='] as $arguments) {
            $output = [];
            $line = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $arguments . ' 2>&1';
            exec($line, $output, $status);
            self::assertSame(1, $status, $arguments);
            self::assertSame([self::FAILED], $output, $arguments);
        }
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $entries = array_values(array_diff((array) scandir($directory), ['.', '..']));
        sort($entries);

        return array_map(static fn (mixed $entry): string => (string) $entry, $entries);
    }

    /** Build an isolated CLI harness: real script, offline server double, optional verified repository context. */
    private function sandbox(bool $verifiedContext, bool $failing = false): string
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('CLI requires the cURL extension even with offline transport.');
        }
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/domain-rows-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->directories[] = $directory;
        mkdir($directory . '/tools/live-verify', 0700, true);
        mkdir($directory . '/vendor', 0700);
        mkdir($directory . '/src', 0700);
        mkdir($directory . '/bin', 0700);
        file_put_contents($directory . '/src/uncommitted.php', '<?php');
        copy($root . '/tools/live-verify/domain-rows.php', $directory . '/tools/live-verify/domain-rows.php');
        $context = $verifiedContext ? 'final class RepositoryContext {'
            . ' public static function load(string $root): array {'
            . ' return ' . var_export(DomainRowsEvidenceTest::context(), true) . '; } }' : '';
        $server = '\\HostingPal\\WhmApiClient\\Tests\\Tools\\LiveVerify\\DomainRowsServer';
        $interface = '\\Psr\\Http\\Message';
        $autoload = '<?php namespace HostingPal\\WhmApiClient\\Tools\\LiveVerify; '
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . '; ' . $context
            . 'final class CurlClient implements \\Psr\\Http\\Client\\ClientInterface {'
            . 'public static ?' . $server . ' $server = null;'
            . ' public function __construct(bool $allowFormPost) {'
            . ' self::$server = new ' . $server . '();'
            . ($failing ? ' self::$server->oneDnsResult = true;' : '')
            . ' file_put_contents(' . var_export($directory . '/transport-used', true) . ', "used");'
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
    private static function runCommand(
        string $directory,
        array $environment = [],
        ?string $argument = null,
    ): array {
        // exec() inherits this process's environment, so the opt-ins are cleared here rather than left to chance.
        $defaults = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wldomains',
            'WHM_API_LIVE_DOMAIN' => 'wldomains.example.com', 'OBLAK_TEST_WHM_KEY' => 'WHMTOKEN',
            'WHM_API_LIVE_FS_CHECK' => ''];
        $variables = '';
        foreach ($environment + $defaults as $key => $value) {
            $variables .= $key . '=' . escapeshellarg($value) . ' ';
        }
        exec($variables . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($directory . '/tools/live-verify/domain-rows.php') . ' '
            . ($argument ?? '--output=' . escapeshellarg($directory . '/evidence.json')) . ' 2>&1', $output, $status);

        return ['status' => $status, 'output' => $output];
    }
}
