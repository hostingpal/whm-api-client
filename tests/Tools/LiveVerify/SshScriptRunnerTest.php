<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use FilesystemIterator;
use HostingPal\WhmApiClient\Tools\LiveVerify\SshScriptRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** A stand-in `ssh` receives the exact command line and script; nothing here can reach a real host. */
final class SshScriptRunnerTest extends TestCase
{
    private const SCRIPT = "printf 'fixed script\\n'\n";

    private string $directory = '';

    public static function setUpBeforeClass(): void
    {
        // Must load before the runner's first call, which binds the function it resolves.
        require_once __DIR__ . '/ProcessSignalSpy.php';
    }

    protected function setUp(): void
    {
        self::signals(reset: true);
        $this->directory = sys_get_temp_dir() . '/ssh-runner-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testRunsTheFixedScriptThroughTheStrictCommandAndReadsCountsByLabel(): void
    {
        $ssh = $this->standIn('printf \'%s\n\' "$@" > ' . escapeshellarg($this->directory . '/argv') . "\n"
            . 'cat > ' . escapeshellarg($this->directory . '/stdin') . "\n"
            . "printf 'first=0\\nsecond=12\\ncomplete=1\\n'\n");

        self::assertSame(
            ['first' => 0, 'second' => 12],
            (new SshScriptRunner($ssh))->counts(self::SCRIPT, ['wlsession', 'a-b.example.com'], ['first', 'second']),
        );
        self::assertSame(
            "-T\n-o\nBatchMode=yes\n-o\nStrictHostKeyChecking=yes\n-o\nConnectTimeout=10\n-o\nUpdateHostKeys=no\n"
                . "-o\nControlMaster=no\n-o\nControlPath=none\noblak.test\nsh -s -- wlsession a-b.example.com\n",
            file_get_contents($this->directory . '/argv'),
        );
        self::assertSame(self::SCRIPT, file_get_contents($this->directory . '/stdin'));
    }

    /** @param list<string> $arguments */
    #[DataProvider('unsafeArguments')]
    public function testAnUnsafeArgumentNeverStartsAProcess(array $arguments): void
    {
        $marker = $this->directory . '/started';
        $ssh = $this->standIn('touch ' . escapeshellarg($marker) . "\n");

        self::assertNull((new SshScriptRunner($ssh))->counts(self::SCRIPT, $arguments, ['first']));
        self::assertFileDoesNotExist($marker);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unsafeArguments(): iterable
    {
        foreach (
            ['', '-o', '.hidden', 'a b', 'a;id', 'a$(id)', 'a`id`', 'a|b', 'a*', 'a?', 'a/b', "a\n", "a'b", 'a@b',
            'a%40b', 'a:b'] as $argument
        ) {
            yield json_encode($argument, JSON_THROW_ON_ERROR) => [['safe', $argument]];
        }
    }

    /** @param non-empty-list<string> $labels */
    #[DataProvider('unreadableReplies')]
    public function testAnythingButTheExactReportIsNull(string $body, array $labels): void
    {
        $ssh = $this->standIn("cat > /dev/null\n" . $body);

        self::assertNull((new SshScriptRunner($ssh, 2.0))->counts(self::SCRIPT, ['safe'], $labels));
    }

    /** @return iterable<string, array{string, non-empty-list<string>}> */
    public static function unreadableReplies(): iterable
    {
        $labels = ['first', 'second'];
        yield 'blank count' => ["printf 'first=\\nsecond=1\\ncomplete=1\\n'\n", $labels];
        yield 'labels out of order' => ["printf 'second=1\\nfirst=1\\ncomplete=1\\n'\n", $labels];
        yield 'missing completion' => ["printf 'first=1\\nsecond=1\\n'\n", $labels];
        yield 'extra line' => ["printf 'first=1\\nsecond=1\\nthird=1\\ncomplete=1\\n'\n", $labels];
        yield 'oversized count' => ["printf 'first=1234567890\\nsecond=1\\ncomplete=1\\n'\n", $labels];
        yield 'bad exit' => ["printf 'first=1\\nsecond=1\\ncomplete=1\\n'\nexit 2\n", $labels];
        yield 'connection refused' => ["exit 255\n", $labels];
        yield 'timeout' => ["sleep 5\n", $labels];
        yield 'endless output' => ["exec yes private-output\n", $labels];
    }

    public function testStandardErrorIsDiscardedAndCredentialsStayOutOfTheChild(): void
    {
        $saved = getenv('OBLAK_TEST_WHM_KEY');
        putenv('OBLAK_TEST_WHM_KEY=private-token');
        try {
            $ssh = $this->standIn('env > ' . escapeshellarg($this->directory . '/env') . "\ncat > /dev/null\n"
                . "echo private-warning >&2\nprintf 'first=3\\ncomplete=1\\n'\n");
            $counts = (new SshScriptRunner($ssh))->counts(self::SCRIPT, ['safe'], ['first']);
        } finally {
            putenv($saved === false ? 'OBLAK_TEST_WHM_KEY' : 'OBLAK_TEST_WHM_KEY=' . $saved);
        }
        self::assertSame(['first' => 3], $counts);
        $environment = file_get_contents($this->directory . '/env');
        self::assertIsString($environment);
        self::assertStringNotContainsString('OBLAK_TEST', $environment);
    }

    public function testAProcessThatHasExitedIsNeverSignalled(): void
    {
        $ssh = $this->standIn("cat > /dev/null\nprintf 'first=1\\ncomplete=1\\n'\n");
        (new SshScriptRunner($ssh))->counts(self::SCRIPT, ['safe'], ['first']);

        self::assertSame([], self::signals());
    }

    public function testAProcessStillRunningAfterTheBoundIsKilled(): void
    {
        $ssh = $this->standIn("cat > /dev/null\nsleep 5\n");
        (new SshScriptRunner($ssh, 0.5))->counts(self::SCRIPT, ['safe'], ['first']);

        self::assertSame([9], self::signals());
    }

    public function testAMissingBinaryIsNull(): void
    {
        self::assertNull(
            (new SshScriptRunner($this->directory . '/missing-ssh'))->counts(self::SCRIPT, ['safe'], ['first']),
        );
    }

    private function standIn(string $body): string
    {
        $path = $this->directory . '/ssh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0700);

        return $path;
    }

    /** @return list<int> */
    private static function signals(bool $reset = false): array
    {
        return \HostingPal\WhmApiClient\Tools\LiveVerify\sentSignals(reset: $reset);
    }
}
