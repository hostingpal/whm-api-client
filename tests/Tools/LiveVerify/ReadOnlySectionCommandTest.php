<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadOnlySectionCommandTest extends TestCase
{
    public function testHelpNeedsNoCredentialsCurlRepositoryOrCatalog(): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help'], ['WHM_API_LIVE' => '', 'SECTION_CASE' => 'no-curl']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('--output=PATH', $stdout);
        self::assertStringContainsString('WHM_API_LIVE_ACCOUNT=npr', $stdout);
        self::assertStringContainsString('WHM_API_LIVE_DOMAIN=npr.rs', $stdout);
        self::assertStringNotContainsString('MUTATIONS', $stdout);
        self::assertSame('', $stderr);
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function testMalformedArgumentsFailBeforeContextOrCatalog(array $arguments): void
    {
        [$exit, $stdout, $stderr] = self::execute($arguments, []);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame("Read-only section verification failed (configuration_failure).\n", $stderr);
    }

    /** @return iterable<array{list<string>}> */
    public static function invalidArguments(): iterable
    {
        foreach (
            [
            [], ['--output='], ['--output'], ['--help', '--output=x'], ['--unknown'],
            ['--output=x', '--output=y'], ['--output', 'x', 'extra']] as $arguments
        ) {
            yield [$arguments];
        }
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidEnvironment')]
    public function testAuthorizationAndCurlAreRequiredBeforeContextOrCatalog(array $override): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--output=output.json'], $override);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame("Read-only section verification failed (configuration_failure).\n", $stderr);
    }

    /** @return iterable<array{array<string, string>}> */
    public static function invalidEnvironment(): iterable
    {
        foreach (
            ['WHM_API_LIVE', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN',
            'OBLAK_TEST_WHM_KEY', 'OBLAK_TEST_NPR_KEY'] as $key
        ) {
            yield [[$key => '']];
        }
        yield [['WHM_API_LIVE_ACCOUNT' => 'private-unrelated-account']];
        yield [['WHM_API_LIVE_DOMAIN' => 'private-unrelated-domain']];
        yield [['SECTION_CASE' => 'no-curl']];
    }

    #[DataProvider('outcomes')]
    public function testPublicationAndCompleteReportRecovery(string $scenario, int $exit): void
    {
        $root = self::fixture($scenario);
        try {
            [$actual, $stdout, $stderr] = self::execute(
                ['--output', $root . '/output.json'],
                ['SECTION_CASE' => $scenario],
                $root,
            );
            self::assertSame($exit, $actual);
            self::assertStringNotContainsString('private', $stdout . $stderr);
            self::assertFileExists($root . '/context-loaded');
            $blocked = in_array($scenario, ['dirty', 'existing', 'symlink'], true);
            self::assertSame(!$blocked, file_exists($root . '/network-started'));
            self::assertSame(!$blocked, file_exists($root . '/catalog-loaded'));
            if (!$blocked) {
                self::assertSame('protected-before-network', file_get_contents($root . '/network-started'));
            }
            $recoveries = glob($root . '/.read-only-section-*', GLOB_NOESCAPE);
            self::assertIsArray($recoveries);
            if (in_array($scenario, ['race', 'unsupported'], true)) {
                self::assertCount(1, $recoveries);
                $recovery = $recoveries[0];
                self::assertSame(0600, fileperms($recovery) & 0777);
                $content = file_get_contents($recovery);
                self::assertIsString($content);
                self::assertSame([
                    'schema_version' => 1,
                    'read_only_section' => ['recipe' => 'synthetic-read-only', 'status' => 'passed', 'probes' => []],
                ], json_decode($content, true, 512, JSON_THROW_ON_ERROR));
                self::assertSame('Recovery evidence retained at '
                    . json_encode($recovery, JSON_UNESCAPED_SLASHES) . ".\n"
                    . "Read-only section verification failed (execution_failure).\n", $stderr);
            } else {
                self::assertSame([], $recoveries);
            }
            if (in_array($scenario, ['passed', 'failed'], true)) {
                $content = file_get_contents($root . '/output.json');
                self::assertIsString($content);
                self::assertSame([
                    'schema_version' => 1,
                    'read_only_section' => ['recipe' => 'synthetic-read-only', 'status' => $scenario, 'probes' => []],
                ], json_decode($content, true, 512, JSON_THROW_ON_ERROR));
                self::assertSame(0600, fileperms($root . '/output.json') & 0777);
                self::assertSame('Read-only section verification: ' . $scenario . ".\n", $stdout);
                self::assertSame('', $stderr);
            } elseif (in_array($scenario, ['existing', 'symlink', 'race'], true)) {
                self::assertSame(
                    $scenario === 'race' ? 'competing-content' : 'original-content',
                    file_get_contents($root . '/output.json'),
                );
                if ($scenario === 'symlink') {
                    self::assertTrue(is_link($root . '/output.json'));
                }
            } else {
                self::assertFileDoesNotExist($root . '/output.json');
            }
            if ($scenario === 'throws') {
                self::assertSame("Read-only section verification failed (execution_failure).\n", $stderr);
            }
        } finally {
            self::removeFixture($root);
        }
    }

    public function testFailedOutcomeRuleNamesDeviationsOnTheConsoleOnly(): void
    {
        $root = self::fixture('deviation');
        try {
            [$exit, $stdout, $stderr] = self::execute(
                ['--output=' . $root . '/output.json'],
                ['SECTION_CASE' => 'deviation'],
                $root,
            );
            self::assertSame(1, $exit);
            self::assertSame("Read-only section verification: failed.\nDeviation: synthetic-label\n", $stdout);
            self::assertSame('', $stderr);
            $content = file_get_contents($root . '/output.json');
            self::assertIsString($content);
            self::assertSame([
                'schema_version' => 1,
                'read_only_section' => ['recipe' => 'synthetic-read-only', 'status' => 'failed', 'probes' => []],
            ], json_decode($content, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            self::removeFixture($root);
        }
    }

    /** @return iterable<array{string, int}> */
    public static function outcomes(): iterable
    {
        foreach (['passed', 'failed', 'dirty', 'existing', 'symlink', 'race', 'unsupported', 'throws'] as $scenario) {
            yield [$scenario, $scenario === 'passed' ? 0 : 1];
        }
    }

    private static function fixture(string $scenario): string
    {
        $root = sys_get_temp_dir() . '/read-only-section-cli-' . bin2hex(random_bytes(6));
        if ($scenario === 'unsupported') {
            $root .= '-quoted"-backslash\\-newline' . "\n";
        }
        mkdir($root . '/tools/live-verify/src', 0700, true);
        mkdir($root . '/vendor', 0700, true);
        $source = dirname(__DIR__, 3) . '/tools/live-verify/src/ReadOnlySectionCommand.php';
        if (is_file($source)) {
            self::assertTrue(copy($source, $root . '/tools/live-verify/src/ReadOnlySectionCommand.php'));
        }
        file_put_contents($root . '/entry.php', <<<'ENTRY'
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/tools/live-verify/src/ReadOnlySectionCommand.php';
exit(\HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand::run(
    array_slice($argv, 1), __DIR__, 'synthetic-read-only',
    static function (): array {
        file_put_contents(__DIR__ . '/catalog-loaded', 'yes');
        return [];
    },
    getenv('SECTION_CASE') === 'deviation' ? static fn (): bool => false : null,
));
ENTRY);
        file_put_contents($root . '/vendor/autoload.php', <<<'STUB'
<?php
namespace HostingPal\WhmApiClient\Tools\LiveVerify;
function extension_loaded(string $name): bool
{
    return getenv('SECTION_CASE') !== 'no-curl';
}
function link(string $from, string $to): bool
{
    return getenv('SECTION_CASE') === 'unsupported' ? false : \link($from, $to);
}
final class RepositoryContext
{
    public static function load(string $root): array
    {
        file_put_contents($root . '/context-loaded', 'yes');
        if (getenv('SECTION_CASE') === 'dirty') {
            throw new \RuntimeException('private context diagnostic');
        }
        return [];
    }
}
final class CurlClient
{
    public function __construct() {}
}
final class ReadOnlySectionRunner
{
    public function __construct(object $http, array $context) {}
    public static function deviations(array $probes, array $records, \Closure $outcome): array
    {
        return ['Deviation: synthetic-label'];
    }
    public function run(array $probes, string $recipe, string $optIn, array $tokens): array
    {
        $root = dirname(__DIR__);
        $files = glob($root . '/.read-only-section-*', GLOB_NOESCAPE);
        if ($optIn !== '1' || $tokens !== ['whm' => 'private-whm', 'cpanel' => 'private-cpanel']
            || count($files) !== 1 || (fileperms($files[0]) & 0777) !== 0600
            || dirname($files[0]) !== $root || file_exists($root . '/output.json')) {
            throw new \RuntimeException('private missing preflight protection');
        }
        file_put_contents($root . '/network-started', 'protected-before-network');
        if (getenv('SECTION_CASE') === 'race') {
            file_put_contents($root . '/output.json', 'competing-content');
        }
        if (getenv('SECTION_CASE') === 'throws') {
            throw new \RuntimeException('private raw HTTP error with token private-whm');
        }
        $failed = in_array(getenv('SECTION_CASE'), ['failed', 'deviation'], true);
        return ['recipe' => $recipe, 'status' => $failed ? 'failed' : 'passed', 'probes' => []];
    }
}
STUB);
        if ($scenario === 'existing') {
            file_put_contents($root . '/output.json', 'original-content');
        } elseif ($scenario === 'symlink') {
            file_put_contents($root . '/target.json', 'original-content');
            symlink($root . '/target.json', $root . '/output.json');
        }

        return $root;
    }

    private static function removeFixture(string $root): void
    {
        $files = glob($root . '/.read-only-section-*', GLOB_NOESCAPE);
        self::assertIsArray($files);
        foreach (
            [...$files, ...array_map(
                static fn (string $file): string => $root . '/' . $file,
                ['context-loaded', 'catalog-loaded', 'network-started', 'output.json', 'target.json', 'entry.php',
                'vendor/autoload.php',
                'tools/live-verify/src/ReadOnlySectionCommand.php'],
            )] as $file
        ) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        rmdir($root . '/vendor');
        rmdir($root . '/tools/live-verify/src');
        rmdir($root . '/tools/live-verify');
        rmdir($root . '/tools');
        rmdir($root);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $override
     * @return array{int, string, string}
     */
    private static function execute(array $arguments, array $override, ?string $root = null): array
    {
        $own = $root === null;
        $root ??= self::fixture('none');
        $environment = array_replace([
            'WHM_API_LIVE' => '1', 'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs',
            'OBLAK_TEST_WHM_KEY' => 'private-whm', 'OBLAK_TEST_NPR_KEY' => 'private-cpanel',
        ], $override);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $root . '/entry.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        $exit = proc_close($process);
        if ($own) {
            self::assertFileDoesNotExist($root . '/context-loaded');
            self::assertFileDoesNotExist($root . '/catalog-loaded');
            self::assertFileDoesNotExist($root . '/network-started');
            self::removeFixture($root);
        }

        return [$exit, $stdout, $stderr];
    }
}
