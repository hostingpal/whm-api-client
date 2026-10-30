<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MysqlSectionCommandTest extends TestCase
{
    public function testHelpNeedsNoAuthorization(): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help'], []);
        self::assertSame(0, $exit);
        self::assertStringContainsString('--output=PATH', $stdout);
        self::assertSame('', $stderr);
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidEnvironment')]
    public function testMissingAuthorizationFailsBeforeOutput(array $override): void
    {
        $output = sys_get_temp_dir() . '/mysql-section-absent-' . bin2hex(random_bytes(6)) . '.json';
        [$exit, $stdout, $stderr] = self::execute(['--output=' . $output], $override);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('configuration_failure', $stderr);
        self::assertStringNotContainsString('private', $stderr);
        self::assertFileDoesNotExist($output);
    }

    /** @return iterable<array{array<string, string>}> */
    public static function invalidEnvironment(): iterable
    {
        foreach (
            ['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT',
            'OBLAK_TEST_WHM_KEY', 'OBLAK_TEST_NPR_KEY'] as $key
        ) {
            yield [[$key => '']];
        }
        yield [['WHM_API_LIVE_ACCOUNT' => 'private-unrelated-account']];
    }

    #[DataProvider('commandOutcomes')]
    public function testCommittedContextAndCleanupPrecedeEvidencePersistence(string $scenario, int $exit): void
    {
        $root = sys_get_temp_dir() . '/mysql-section-cli-' . bin2hex(random_bytes(6));
        mkdir($root . '/tools/live-verify', 0700, true);
        mkdir($root . '/vendor', 0700, true);
        $source = dirname(__DIR__, 3) . '/tools/live-verify/mysql-section.php';
        self::assertTrue(copy($source, $root . '/tools/live-verify/mysql-section.php'));
        file_put_contents($root . '/vendor/autoload.php', <<<'STUB'
<?php
namespace HostingPal\WhmApiClient\Tools\LiveVerify;
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
    public function __construct(bool $allowFormPost) {}
}
final class MysqlSectionFixture
{
    public function __construct(object $http, array $tokens, string $suffix, array $context) {}
    public function run(): array
    {
        $root = dirname(__DIR__);
        file_put_contents($root . '/cleanup-complete', file_exists($root . '/output.json') ? 'late' : 'before-output');
        if (getenv('SECTION_CASE') === 'race') {
            file_put_contents($root . '/output.json', 'competing-content');
        }
        return ['status' => getenv('SECTION_CASE') === 'failed' ? 'failed' : 'passed'];
    }
}
STUB);
        try {
            if ($scenario === 'existing') {
                file_put_contents($root . '/output.json', 'original-content');
            }
            [$actual, $stdout, $stderr] = self::execute(
                ['--output', $root . '/output.json'],
                ['SECTION_CASE' => $scenario],
                $root,
            );
            self::assertSame($exit, $actual);
            self::assertFileExists($root . '/context-loaded');
            self::assertStringNotContainsString('private', $stdout . $stderr);
            if (in_array($scenario, ['dirty', 'existing'], true)) {
                self::assertFileDoesNotExist($root . '/cleanup-complete');
            } else {
                self::assertSame('before-output', file_get_contents($root . '/cleanup-complete'));
            }
            if ($scenario === 'dirty') {
                self::assertFileDoesNotExist($root . '/output.json');
            } elseif ($scenario === 'existing' || $scenario === 'race') {
                self::assertSame(
                    $scenario === 'existing' ? 'original-content' : 'competing-content',
                    file_get_contents($root . '/output.json'),
                );
            } else {
                $json = file_get_contents($root . '/output.json');
                self::assertIsString($json);
                self::assertStringContainsString($scenario === 'failed' ? 'failed' : 'passed', $json);
                self::assertSame(0600, fileperms($root . '/output.json') & 0777);
                self::assertSame('', $stderr);
            }
            $temporary = glob($root . '/.mysql-section-*');
            self::assertIsArray($temporary);
            if ($scenario === 'race') {
                self::assertCount(1, $temporary);
                self::assertSame(0600, fileperms($temporary[0]) & 0777);
                self::assertStringContainsString($temporary[0], $stderr);
                $recovered = file_get_contents($temporary[0]);
                self::assertIsString($recovered);
                self::assertSame(
                    ['schema_version' => 1, 'mysql_section' => ['status' => 'passed']],
                    json_decode($recovered, true, 512, JSON_THROW_ON_ERROR),
                );
            } else {
                self::assertSame([], $temporary);
            }
        } finally {
            foreach (glob($root . '/.mysql-section-*') ?: [] as $temporary) {
                unlink($temporary);
            }
            foreach (
                ['context-loaded', 'cleanup-complete', 'output.json',
                'vendor/autoload.php', 'tools/live-verify/mysql-section.php'] as $file
            ) {
                if (is_file($root . '/' . $file)) {
                    unlink($root . '/' . $file);
                }
            }
            rmdir($root . '/vendor');
            rmdir($root . '/tools/live-verify');
            rmdir($root . '/tools');
            rmdir($root);
        }
    }

    /** @return iterable<array{string, int}> */
    public static function commandOutcomes(): iterable
    {
        yield ['passed', 0];
        yield ['failed', 1];
        yield ['dirty', 1];
        yield ['existing', 1];
        yield ['race', 1];
    }

    /** @param list<string> $arguments
     * @param array<string, string> $override
     * @return array{int, string, string}
     */
    private static function execute(array $arguments, array $override, ?string $root = null): array
    {
        $environment = array_replace([
            'WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'npr',
            'OBLAK_TEST_WHM_KEY' => 'private-whm', 'OBLAK_TEST_NPR_KEY' => 'private-cpanel',
        ], $override);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ($root ?? dirname(__DIR__, 3)) . '/tools/live-verify/mysql-section.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
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

        return [proc_close($process), $stdout, $stderr];
    }
}
