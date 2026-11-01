<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\RepositoryContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class RepositoryContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/whm-context-' . bin2hex(random_bytes(8)) . " 'quoted";
        mkdir($this->root);
        foreach (['src', 'tools/api-map', 'docs/api/sources/2026-10-13', 'docs/api/overlay/nested'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }

        $this->write('src/Client.php', '<?php');
        $this->write('tools/api-map/generator.go', 'package example');
        $this->write('composer.json', '{}');
        $this->write('composer.lock', '{}');
        $this->write('docs/api/annotations.json', '{}');
        $this->write('docs/api/map.sha256', str_repeat('a', 64) . "  .cache/api-map/map.json\n");
        $this->write('docs/api/overlay/z.json', '{"z":true}');
        $this->write('docs/api/overlay/nested/a.json', '{"a":1}');
        $whm = gzencode('{"whm":1}');
        $uapi = gzencode('{"uapi":1}');
        self::assertIsString($whm);
        self::assertIsString($uapi);
        $this->write('docs/api/sources/2026-10-13/whm.openapi.json.gz', $whm);
        $this->write('docs/api/sources/2026-10-13/cpanel.openapi.json.gz', $uapi);
        $this->write('docs/api/sources/2026-10-13/manifest.json', json_encode(['sources' => [
            ['file' => 'cpanel.openapi.json.gz', 'sha256' => hash('sha256', '{"uapi":1}')],
            ['file' => 'whm.openapi.json.gz', 'sha256' => hash('sha256', '{"whm":1}')],
        ]], JSON_THROW_ON_ERROR));
        $this->git('init --quiet');
        $this->git('config user.email synthetic@example.invalid');
        $this->git('config user.name Synthetic');
        $this->commit();
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                throw new RuntimeException('Invalid temporary repository entry.');
            }

            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->root);
    }

    public function testCleanRepositoryBindsExactCodeAndDecompressedSources(): void
    {
        self::assertSame([
            'revision' => $this->git('rev-parse HEAD'),
            'source_hashes' => [
                'whm1' => '0fbb46d5a0aadc52b061a9b1840d7c27303969d84ff0912915b693b88edf503f',
                'uapi' => '66458a38165863187b9db6d2ca79ef0b39a570ed7e6101b438456aac4abbdfeb',
            ],
            'map_sha256' => str_repeat('a', 64),
            'generator_tree' => $this->git('rev-parse HEAD:tools/api-map'),
            'overlay_sha256' => hash(
                'sha256',
                'nested/a.json' . "\0" . hash('sha256', '{"a":1}') . "\n"
                . 'z.json' . "\0" . hash('sha256', '{"z":true}') . "\n",
            ),
        ], RepositoryContext::load($this->root));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function dirtyPaths(): iterable
    {
        foreach (
            [
            'src/Client.php',
            'tools/api-map/generator.go',
            'composer.json',
            'composer.lock',
            'docs/api/overlay/z.json',
            'docs/api/sources/2026-10-13/whm.openapi.json.gz',
            'docs/api/map.sha256',
            'docs/api/annotations.json',
            ] as $path
        ) {
            yield $path . ' staged' => [$path, true];
            yield $path . ' unstaged' => [$path, false];
            if (
                str_starts_with($path, 'src/') || str_starts_with($path, 'tools/')
                || str_starts_with($path, 'docs/api/overlay/') || str_starts_with($path, 'docs/api/sources/')
            ) {
                yield $path . ' untracked' => [$path . '.new', false];
            }
        }
    }

    #[DataProvider('dirtyPaths')]
    public function testDirtyTrackedAndUntrackedEffectiveInputsAreRejected(string $path, bool $stage): void
    {
        $this->write($path, 'private-value');
        if ($stage) {
            $this->git('add -- ' . escapeshellarg($path));
        }

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testEvidenceAndTestsCanBeWrittenWithoutInvalidatingRevision(): void
    {
        mkdir($this->root . '/docs/api/evidence');
        mkdir($this->root . '/tests');
        $this->write('docs/api/evidence/run.json', '{}');
        $this->write('tests/ExampleTest.php', '<?php');

        self::assertSame($this->git('rev-parse HEAD'), RepositoryContext::load($this->root)['revision']);
    }

    public function testCommittedSourceTamperingIsRejectedAgainstManifest(): void
    {
        $tampered = gzencode('tampered');
        self::assertIsString($tampered);
        $this->write('docs/api/sources/2026-10-13/whm.openapi.json.gz', $tampered);
        $this->commit();

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testCommittedMalformedMapChecksumIsRejected(): void
    {
        $this->write('docs/api/map.sha256', str_repeat('a', 64) . "  wrong-map.json\n");
        $this->commit();

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testCommittedInvalidGzipIsRejectedWithoutEmittingWarnings(): void
    {
        $this->write('docs/api/sources/2026-10-13/whm.openapi.json.gz', 'not gzip');
        $this->commit();

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testOverlaySymlinksAreRejectedEvenWhenCommitted(): void
    {
        symlink('z.json', $this->root . '/docs/api/overlay/link.json');
        $this->commit();

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testAssumeUnchangedCodeCannotBypassDirtyCheck(): void
    {
        $this->git('update-index --assume-unchanged src/Client.php');
        $this->write('src/Client.php', '<?php changed');

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testSkipWorktreeCodeCannotBypassDirtyCheck(): void
    {
        $this->git('update-index --skip-worktree src/Client.php');
        $this->write('src/Client.php', '<?php changed');

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testRuntimeSymlinksCannotBindExternalCodeToRevision(): void
    {
        unlink($this->root . '/src/Client.php');
        symlink('../composer.json', $this->root . '/src/Client.php');
        $this->commit();

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testIgnoredCodeStillInvalidatesRepositoryContext(): void
    {
        $this->write('.gitignore', "src/ignored.php\n");
        $this->write('src/ignored.php', '<?php');

        $this->expectException(RuntimeException::class);
        RepositoryContext::load($this->root);
    }

    public function testShellMetacharactersInRootAreLiteralAndErrorsHidePaths(): void
    {
        $injectedRoot = $this->root . '/missing; touch PWNED';
        $caught = null;
        try {
            RepositoryContext::load($injectedRoot);
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $caught, 'A missing repository must be rejected.');
        self::assertStringNotContainsString($this->root, $caught->getMessage());
        self::assertStringNotContainsString('PWNED', $caught->getMessage());
        self::assertNull($caught->getPrevious());

        self::assertFileDoesNotExist($this->root . '/PWNED');
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($this->root . '/' . $path, $contents);
    }

    private function commit(): void
    {
        $this->git('add --all');
        $this->git('-c commit.gpgsign=false -c core.hooksPath=/dev/null commit --quiet -m synthetic');
    }

    private function git(string $arguments): string
    {
        exec('git -C ' . escapeshellarg($this->root) . ' ' . $arguments . ' 2>/dev/null', $output, $status);
        self::assertSame(0, $status);

        return implode("\n", $output);
    }
}
