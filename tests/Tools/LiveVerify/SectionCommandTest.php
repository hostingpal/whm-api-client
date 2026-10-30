<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SectionCommandTest extends TestCase
{
    #[DataProvider('entrypoints')]
    public function testOriginalEntrypointHelpAndFailureLabels(string $script, string $label): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help'], ['WHM_API_LIVE' => ''], entry: $script);
        self::assertSame(0, $exit);
        self::assertSame('Usage: php tools/live-verify/' . $script . " --output=PATH\n"
            . 'Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=npr '
            . "WHM_API_LIVE_DOMAIN=npr.rs and both test tokens.\n", $stdout);
        self::assertSame('', $stderr);

        [$exit, $stdout, $stderr] = self::execute(['--output=private'], ['WHM_API_LIVE' => ''], entry: $script);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame($label . " section verification failed (configuration_failure).\n", $stderr);
    }

    /** @return iterable<array{string,string}> */
    public static function entrypoints(): iterable
    {
        yield ['email-section.php', 'Email'];
        yield ['boxtrapper-section.php', 'BoxTrapper'];
    }

    public function testHelpNeedsNoAuthorizationAutoloaderOrFixture(): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help'], ['WHM_API_LIVE' => '', 'SECTION_CASE' => 'no-curl']);
        self::assertSame(0, $exit);
        self::assertSame("Usage: php tools/live-verify/synthetic-section.php --output=PATH\n"
            . 'Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=npr '
            . "WHM_API_LIVE_DOMAIN=npr.rs and both test tokens.\n", $stdout);
        self::assertSame('', $stderr);
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function testMalformedArgumentsNeverLoadContextOrRunFixture(array $arguments): void
    {
        [$exit, $stdout, $stderr] = self::execute($arguments, []);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame("Synthetic section verification failed (configuration_failure).\n", $stderr);
    }

    /** @return iterable<array{list<string>}> */
    public static function invalidArguments(): iterable
    {
        foreach (
            [
            [], ['--output='], ['--output'], ['--help', '--output=x'], ['--unknown=private'],
            ['--output=x', '--output=y'], ['--output', 'x', 'private']] as $arguments
        ) {
            yield [$arguments];
        }
    }

    /** @param array<string,string> $override */
    #[DataProvider('invalidEnvironment')]
    public function testEveryAuthorizationConditionIsRequiredBeforeAutoloadOrFixture(array $override): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--output=output.json'], $override);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame("Synthetic section verification failed (configuration_failure).\n", $stderr);
    }

    /** @return iterable<array{array<string,string>}> */
    public static function invalidEnvironment(): iterable
    {
        foreach (
            ['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN',
            'OBLAK_TEST_WHM_KEY', 'OBLAK_TEST_NPR_KEY'] as $key
        ) {
            yield [[$key => '']];
        }
        yield [['WHM_API_LIVE' => 'true']];
        yield [['WHM_API_LIVE_MUTATIONS' => 'true']];
        yield [['WHM_API_LIVE_ACCOUNT' => 'private-other-account']];
        yield [['WHM_API_LIVE_DOMAIN' => 'private-other-domain']];
        yield [['SECTION_CASE' => 'no-curl']];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testUnsafeEntrypointMetadataIsNotPrintedOrUsedAsAPath(string $scenario): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help'], ['SECTION_CASE' => $scenario]);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame("Section section verification failed (configuration_failure).\n", $stderr);
    }

    /** @return iterable<array{string}> */
    public static function invalidIdentifiers(): iterable
    {
        yield ['invalid-script'];
        yield ['invalid-label'];
        yield ['invalid-key'];
    }

    #[DataProvider('outcomes')]
    public function testProtectedPublicationPreservesCompleteReportsAndNeverRetries(string $scenario): void
    {
        $root = self::fixture($scenario);
        try {
            [$exit, $stdout, $stderr] = self::execute(
                ['--output', $root . '/evidence/output.json'],
                ['SECTION_CASE' => $scenario],
                $root,
            );
            self::assertSame($scenario === 'passed' ? 0 : 1, $exit);
            self::assertFileExists($root . '/context-loaded');
            self::assertStringNotContainsString('private', $stdout . $stderr);
            $blocked = in_array($scenario, ['dirty', 'existing', 'symlink', 'dangling'], true);
            self::assertSame(!$blocked, file_exists($root . '/fixture-calls'));
            if (!$blocked) {
                self::assertSame('one', file_get_contents($root . '/fixture-calls'));
                self::assertSame(0700, fileperms($root . '/evidence') & 0777);
            }
            $recoveries = glob($root . '/evidence/.synthetic-section-*', GLOB_NOESCAPE);
            self::assertIsArray($recoveries);
            if (in_array($scenario, ['race', 'unsupported'], true)) {
                self::assertCount(1, $recoveries);
                self::assertSame(0600, fileperms($recoveries[0]) & 0777);
                self::assertSame(self::report('passed'), self::readJson($recoveries[0]));
                self::assertSame('Recovery evidence retained at '
                    . json_encode($recoveries[0], JSON_UNESCAPED_SLASHES) . ".\n"
                    . "Synthetic section verification failed (execution_failure).\n", $stderr);
            } else {
                self::assertSame([], $recoveries);
            }
            $output = $root . '/evidence/output.json';
            if ($scenario === 'passed' || $scenario === 'failed') {
                self::assertSame(self::report($scenario), self::readJson($output));
                self::assertSame(0600, fileperms($output) & 0777);
                self::assertSame('Synthetic section fixture: ' . $scenario . "\n", $stdout);
                self::assertSame('', $stderr);
            } elseif (in_array($scenario, ['existing', 'symlink', 'race'], true)) {
                self::assertSame(
                    $scenario === 'race' ? 'competing-content' : 'original-content',
                    file_get_contents($output),
                );
            } else {
                self::assertFileDoesNotExist($output);
            }
            if ($scenario === 'symlink' || $scenario === 'dangling') {
                self::assertTrue(is_link($output));
            }
            if (in_array($scenario, ['dirty', 'throws', 'serialization', 'partial-write'], true)) {
                self::assertSame("Synthetic section verification failed (execution_failure).\n", $stderr);
            }
        } finally {
            self::removeFixture($root);
        }
    }

    /** @return iterable<array{string}> */
    public static function outcomes(): iterable
    {
        foreach (
            ['passed', 'failed', 'dirty', 'existing', 'symlink', 'dangling', 'race', 'unsupported',
            'throws', 'serialization', 'partial-write'] as $scenario
        ) {
            yield [$scenario];
        }
    }

    /** @return array{schema_version:int,synthetic_section:array{status:string,probes:list<mixed>}} */
    private static function report(string $status): array
    {
        return ['schema_version' => 1, 'synthetic_section' => ['status' => $status, 'probes' => []]];
    }

    private static function readJson(string $path): mixed
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function fixture(string $scenario): string
    {
        $root = sys_get_temp_dir() . '/mutating-section-cli-' . bin2hex(random_bytes(6));
        if ($scenario === 'unsupported') {
            $root .= '-quoted"-backslash\\-newline' . "\n";
        }
        mkdir($root . '/tools/live-verify/src', 0700, true);
        mkdir($root . '/vendor', 0700, true);
        $source = dirname(__DIR__, 3) . '/tools/live-verify/src/SectionCommand.php';
        if (is_file($source)) {
            self::assertTrue(copy($source, $root . '/tools/live-verify/src/SectionCommand.php'));
        }
        foreach (['email-section.php', 'boxtrapper-section.php'] as $script) {
            self::assertTrue(copy(
                dirname(__DIR__, 3) . '/tools/live-verify/' . $script,
                $root . '/tools/live-verify/' . $script,
            ));
        }
        file_put_contents($root . '/entry.php', <<<'ENTRY'
<?php
namespace HostingPal\WhmApiClient\Tools\LiveVerify;
function extension_loaded(string $name): bool { return getenv('SECTION_CASE') !== 'no-curl'; }
function link(string $from, string $to): bool
{
    return getenv('SECTION_CASE') === 'unsupported' ? false : \link($from, $to);
}
function file_put_contents(string $path, string $contents): int|false
{
    if (getenv('SECTION_CASE') === 'partial-write' && str_contains($path, '/.synthetic-section-')) {
        return \file_put_contents($path, '{');
    }
    return \file_put_contents($path, $contents);
}
require __DIR__ . '/tools/live-verify/src/SectionCommand.php';
exit(SectionCommand::run(
    array_slice($argv, 1), __DIR__,
    getenv('SECTION_CASE') === 'invalid-script' ? '../private.php' : 'synthetic-section.php',
    getenv('SECTION_CASE') === 'invalid-label' ? "private\nraw-message" : 'Synthetic',
    getenv('SECTION_CASE') === 'invalid-key' ? 'private/raw-key' : 'synthetic_section',
    static function (array $tokens, string $suffix, array $context): array {
        $root = __DIR__;
        $files = glob($root . '/evidence/.synthetic-section-*', GLOB_NOESCAPE);
        if ($tokens !== ['whm' => 'private-whm', 'cpanel' => 'private-cpanel']
            || preg_match('/\A[a-f0-9]{6}\z/', $suffix) !== 1 || $context !== ['revision' => 'committed']
            || !file_exists($root . '/context-loaded') || count($files) !== 1
            || (fileperms($files[0]) & 0777) !== 0600 || dirname($files[0]) !== $root . '/evidence'
            || file_exists($root . '/evidence/output.json')) {
            throw new \RuntimeException('private invalid fixture binding or protection');
        }
        \file_put_contents($root . '/fixture-calls', 'one', FILE_APPEND);
        if (getenv('SECTION_CASE') === 'race') {
            file_put_contents($root . '/evidence/output.json', 'competing-content');
        }
        if (getenv('SECTION_CASE') === 'throws') {
            throw new \RuntimeException('private HTTP payload and private-whm token');
        }
        return ['status' => getenv('SECTION_CASE') === 'failed' ? 'failed' : 'passed',
            'probes' => getenv('SECTION_CASE') === 'serialization' ? [INF] : []];
    },
));
ENTRY);
        file_put_contents($root . '/vendor/autoload.php', <<<'STUB'
<?php
namespace HostingPal\WhmApiClient\Tools\LiveVerify;
file_put_contents(dirname(__DIR__) . '/autoloaded', 'yes');
final class RepositoryContext
{
    public static function load(string $root): array
    {
        file_put_contents($root . '/context-loaded', 'yes');
        if (getenv('SECTION_CASE') === 'dirty') {
            throw new \RuntimeException('private context diagnostic');
        }
        return ['revision' => 'committed'];
    }
}
STUB);
        if (in_array($scenario, ['existing', 'symlink', 'dangling'], true)) {
            mkdir($root . '/evidence', 0700);
            if ($scenario === 'existing') {
                file_put_contents($root . '/evidence/output.json', 'original-content');
            } else {
                if ($scenario === 'symlink') {
                    file_put_contents($root . '/target.json', 'original-content');
                }
                symlink($root . '/target.json', $root . '/evidence/output.json');
            }
        }

        return $root;
    }

    private static function removeFixture(string $root): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }

    /**
     * @param list<string> $arguments
     * @param array<string,string> $override
     * @return array{int,string,string}
     */
    private static function execute(
        array $arguments,
        array $override,
        ?string $root = null,
        ?string $entry = null,
    ): array {
        $own = $root === null;
        $root ??= self::fixture('none');
        try {
            $environment = array_replace([
                'WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
                'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs',
                'OBLAK_TEST_WHM_KEY' => 'private-whm', 'OBLAK_TEST_NPR_KEY' => 'private-cpanel',
            ], $override);
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $root . ($entry === null ? '/entry.php' : '/tools/live-verify/' . $entry), ...$arguments],
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
                self::assertFileDoesNotExist($root . '/autoloaded');
                self::assertFileDoesNotExist($root . '/context-loaded');
                self::assertFileDoesNotExist($root . '/fixture-calls');
                self::assertDirectoryDoesNotExist($root . '/evidence');
            }

            return [$exit, $stdout, $stderr];
        } finally {
            if ($own) {
                self::removeFixture($root);
            }
        }
    }
}
