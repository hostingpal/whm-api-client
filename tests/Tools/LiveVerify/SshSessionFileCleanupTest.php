<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use FilesystemIterator;
use HostingPal\WhmApiClient\Tools\LiveVerify\SshSessionFileCleanup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A stand-in `ssh` receives the exact command line. For the real-script cases it hands the fixed script to a local
 * shell with its absolute paths moved into a synthetic tree, so both scripts are executed offline.
 */
final class SshSessionFileCleanupTest extends TestCase
{
    private const USER = 'wlsession';

    private const DOMAIN = 'wlsession.example.com';

    private const MAILBOX = 'box0123456789abcdef';

    private const OBSERVED = "inventory=9\nwide=4\nown=2\nmailbox_encoded=2\nmailbox_literal=0\nanchored=4\n"
        . "unexplained=0\ntoken_suffix=4\ndirectories=2\ncomplete=1\n";

    private const REMOVED = "before=4\ndeleted=4\nafter=0\nwide_after=0\ncomplete=1\n";

    private string $directory = '';

    public static function setUpBeforeClass(): void
    {
        // Must load before the shared runner's first call, which binds the function it resolves.
        require_once __DIR__ . '/ProcessSignalSpy.php';
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/session-cleanup-' . bin2hex(random_bytes(8));
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
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testBothScriptsConnectThroughTheFixedStrictCommandAndNothingElse(): void
    {
        $ssh = $this->standIn('printf \'%s\n\' "$@" >> ' . escapeshellarg($this->directory . '/argv') . "\n"
            . 'script=$(cat)' . "\n"
            . 'case "$script" in *deleted=*) printf ' . escapeshellarg(self::REMOVED) . ' ;; '
            . '*) printf ' . escapeshellarg(self::OBSERVED) . ' ;; esac' . "\n");
        $cleanup = new SshSessionFileCleanup($ssh);
        $cleanup->observe(self::USER, self::DOMAIN, self::MAILBOX);
        $cleanup->remove(self::USER, self::DOMAIN, self::MAILBOX);

        $command = "-T\n-o\nBatchMode=yes\n-o\nStrictHostKeyChecking=yes\n-o\nConnectTimeout=10\n"
            . "-o\nUpdateHostKeys=no\n-o\nControlMaster=no\n-o\nControlPath=none\n"
            . "oblak.test\nsh -s -- wlsession wlsession.example.com box0123456789abcdef\n";
        self::assertSame($command . $command, file_get_contents($this->directory . '/argv'));
    }

    public function testEachFixedScriptTravelsOverStandardInputWithoutAnyName(): void
    {
        $ssh = $this->standIn('cat >> ' . escapeshellarg($this->directory . '/stdin') . "\n"
            . 'printf ' . escapeshellarg(self::OBSERVED) . "\n");
        (new SshSessionFileCleanup($ssh))->observe(self::USER, self::DOMAIN, self::MAILBOX);

        self::assertSame(SshSessionFileCleanup::OBSERVE, file_get_contents($this->directory . '/stdin'));
        foreach ([SshSessionFileCleanup::OBSERVE, SshSessionFileCleanup::REMOVE] as $script) {
            self::assertStringNotContainsString(self::USER, $script);
            self::assertStringNotContainsString(self::MAILBOX, $script);
            self::assertStringNotContainsString(self::DOMAIN, $script);
        }
    }

    /** The observation can only read: no deleting, writing or executing find action and no redirection anywhere. */
    public function testTheObservationScriptCanOnlyReadAndCount(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/-(?:delete|exec|execdir|ok|okdir|fprint|fprint0|fprintf|fls)\b|\brm\b|\bmv\b|\bunlink\b|>/',
            str_replace(['2>/dev/null'], '', SshSessionFileCleanup::OBSERVE),
        );
        self::assertStringNotContainsString('>', SshSessionFileCleanup::OBSERVE);
    }

    /** The removal can delete only through one find action, bound to regular files and the anchored names. */
    public function testTheRemovalScriptDeletesOnlyThroughOneBoundFindAction(): void
    {
        $script = SshSessionFileCleanup::REMOVE;
        self::assertSame(1, substr_count($script, '-delete'));
        self::assertDoesNotMatchRegularExpression(
            '/-(?:exec|execdir|ok|okdir|fprint|fprint0|fprintf|fls)\b|\brm\b|\bmv\b|\bunlink\b|>/',
            $script,
        );
        self::assertSame(1, substr_count($script, '"$(owned -print -delete)"'));
        self::assertSame(1, substr_count(
            $script,
            "\n    count -type f \\( -name \"\$own*\" -o -name \"\$encoded*\" -o -name \"\$literal*\" \\) \"\$@\"\n",
        ));
        self::assertSame(1, substr_count($script, 'find /var/cpanel/sessions '));
    }

    #[DataProvider('unsafeArguments')]
    public function testUnsafeArgumentsNeverStartAProcess(string $user, string $domain, string $mailbox): void
    {
        $marker = $this->directory . '/started';
        $ssh = $this->standIn('touch ' . escapeshellarg($marker) . "\n");
        $cleanup = new SshSessionFileCleanup($ssh);

        self::assertNull($cleanup->observe($user, $domain, $mailbox));
        self::assertNull($cleanup->remove($user, $domain, $mailbox));
        self::assertFileDoesNotExist($marker);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unsafeArguments(): iterable
    {
        foreach (['', 'A', '1user', 'a b', 'a;id', 'a*', 'a?', '../root', 'a/b', "a\n", str_repeat('a', 17)] as $user) {
            yield 'user ' . json_encode($user) => [$user, self::DOMAIN, self::MAILBOX];
        }
        foreach (['', 'localhost', 'a*.example.com', 'a/b.example.com', '-o.example.com', 'a b.example.com'] as $d) {
            yield 'domain ' . json_encode($d) => [self::USER, $d, self::MAILBOX];
        }
        foreach (['', 'Box', '1box', 'box*', 'box@x', 'box%40x', 'box:x', 'box x', str_repeat('b', 65)] as $mailbox) {
            yield 'mailbox ' . json_encode($mailbox) => [self::USER, self::DOMAIN, $mailbox];
        }
    }

    public function testRealObservationClassifiesNamesByCountAndChangesNothing(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);

        self::assertSame([
            'inventory' => 14, 'wide' => 9, 'own' => 2, 'mailbox_encoded' => 2, 'mailbox_literal' => 1,
            'anchored' => 7, 'unexplained' => 2, 'token_suffix' => 4, 'directories' => 2,
        ], $this->realScript($tree)->observe(self::USER, self::DOMAIN, self::MAILBOX));
        self::assertSame($before, $this->snapshot($tree));
    }

    public function testRealRemovalDeletesOnlyThisAccountsAnchoredRegularFiles(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);

        self::assertSame(
            ['before' => 5, 'deleted' => 5, 'after' => 0, 'wide_after' => 4],
            $this->realScript($tree)->remove(self::USER, self::DOMAIN, self::MAILBOX),
        );
        $sessions = $tree . '/var/cpanel/sessions/';
        $removed = [
            'raw/wlsession:AbC123', 'cache/wlsession:AbC123', 'raw/' . self::MAILBOX . '%40' . self::DOMAIN . ':Xy_9',
            'cache/' . self::MAILBOX . '%40' . self::DOMAIN . ':Xy_9',
            'raw/' . self::MAILBOX . '@' . self::DOMAIN . ':a,b',
        ];
        $expected = $before;
        foreach ($removed as $path) {
            unset($expected[$sessions . $path]);
        }
        self::assertSame($expected, $this->snapshot($tree));
        self::assertDirectoryExists($sessions . 'raw/wlsession:directory');
        self::assertTrue(is_link($sessions . 'raw/wlsession:link'));
        self::assertFileExists($tree . '/outside/wlsession:target');
    }

    public function testRealRemovalOnACleanTreeDeletesNothing(): void
    {
        $tree = $this->tree(false);
        $before = $this->snapshot($tree);

        self::assertSame(
            ['before' => 0, 'deleted' => 0, 'after' => 0, 'wide_after' => 0],
            $this->realScript($tree)->remove(self::USER, self::DOMAIN, self::MAILBOX),
        );
        self::assertSame($before, $this->snapshot($tree));
    }

    /** The script re-checks its own arguments, so even a bypassed caller cannot widen a pattern. */
    public function testTheScriptsRefuseUnsafeArgumentsThemselves(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);
        foreach ([SshSessionFileCleanup::OBSERVE, SshSessionFileCleanup::REMOVE] as $script) {
            $unsafe = [
            ['*', self::DOMAIN, self::MAILBOX], [self::USER, '*', self::MAILBOX],
                [self::USER, self::DOMAIN, '*']];
            foreach ($unsafe as $arguments) {
                $moved = str_replace(' /var/', ' ' . $tree . '/var/', $script);
                $output = [];
                exec('sh -c ' . escapeshellarg($moved) . ' sh ' . implode(' ', array_map('escapeshellarg', $arguments))
                    . ' 2>/dev/null', $output, $status);
                self::assertSame(1, $status);
                self::assertSame([], $output);
            }
        }
        self::assertSame($before, $this->snapshot($tree));
    }

    public function testAFailedSearchNeverGivesACount(): void
    {
        $tree = $this->tree();
        $shims = $this->shim('find', 'exit 1');

        self::assertNull($this->realScript($tree, $shims)->observe(self::USER, self::DOMAIN, self::MAILBOX));
        self::assertNull($this->realScript($tree, $shims)->remove(self::USER, self::DOMAIN, self::MAILBOX));
    }

    public function testAnEmptySessionStoreCannotBeObserved(): void
    {
        $tree = $this->directory . '/empty';
        mkdir($tree . '/var/cpanel/sessions', 0700, true);

        self::assertNull($this->realScript($tree)->observe(self::USER, self::DOMAIN, self::MAILBOX));
    }

    #[DataProvider('unusableReplies')]
    public function testAnyOtherReplyIsUnreadable(string $body): void
    {
        $ssh = $this->standIn("cat > /dev/null\n" . $body);
        $cleanup = new SshSessionFileCleanup($ssh, 2.0);

        self::assertNull($cleanup->observe(self::USER, self::DOMAIN, self::MAILBOX));
        self::assertNull($cleanup->remove(self::USER, self::DOMAIN, self::MAILBOX));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableReplies(): iterable
    {
        yield 'connection refused' => ["exit 255\n"];
        yield 'empty' => [''];
        yield 'blank count' => ["printf 'inventory=\\nbefore=\\n'\n"];
        yield 'incomplete' => ["printf 'inventory=9\\nwide=1\\n'\n"];
        yield 'bad exit after a report' => ['printf ' . escapeshellarg(self::OBSERVED . self::REMOVED) . "\nexit 2\n"];
        yield 'extra line' => ['printf ' . escapeshellarg(self::OBSERVED . "private\n") . "\n"];
        yield 'timeout' => ["sleep 5\n"];
    }

    public function testStandardErrorIsDiscarded(): void
    {
        $ssh = $this->standIn(
            "cat > /dev/null\necho private-warning >&2\nprintf " . escapeshellarg(self::REMOVED) . "\n",
        );

        self::assertSame(
            ['before' => 4, 'deleted' => 4, 'after' => 0, 'wide_after' => 0],
            (new SshSessionFileCleanup($ssh))->remove(self::USER, self::DOMAIN, self::MAILBOX),
        );
    }

    public function testCredentialsStayOutOfTheChildEnvironment(): void
    {
        $saved = getenv('OBLAK_TEST_WHM_KEY');
        putenv('OBLAK_TEST_WHM_KEY=private-token');
        try {
            $ssh = $this->standIn('env > ' . escapeshellarg($this->directory . '/env') . "\ncat > /dev/null\n"
                . 'printf ' . escapeshellarg(self::OBSERVED) . "\n");
            (new SshSessionFileCleanup($ssh))->observe(self::USER, self::DOMAIN, self::MAILBOX);
        } finally {
            putenv($saved === false ? 'OBLAK_TEST_WHM_KEY' : 'OBLAK_TEST_WHM_KEY=' . $saved);
        }
        $environment = file_get_contents($this->directory . '/env');
        self::assertIsString($environment);
        self::assertStringNotContainsString('private-token', $environment);
        self::assertStringNotContainsString('OBLAK_TEST', $environment);
    }

    public function testMissingBinaryIsUnreadable(): void
    {
        $cleanup = new SshSessionFileCleanup($this->directory . '/missing-ssh');

        self::assertNull($cleanup->observe(self::USER, self::DOMAIN, self::MAILBOX));
    }

    /** Write an executable stand-in for ssh. */
    private function standIn(string $body): string
    {
        $path = $this->directory . '/ssh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0700);

        return $path;
    }

    /** Run the real fixed script through the exact remote command, with its absolute paths moved under one tree. */
    private function realScript(string $tree, ?string $shims = null): SshSessionFileCleanup
    {
        $move = 's# /var/# ' . $tree . '/var/#g';
        $path = $shims === null ? '' : 'PATH=' . escapeshellarg($shims) . ':"$PATH"' . "\n";

        return new SshSessionFileCleanup($this->standIn(
            $path . "for last; do :; done\nsed " . escapeshellarg($move) . ' | eval "$last"' . "\n",
        ));
    }

    private function shim(string $command, string $body): string
    {
        $directory = $this->directory . '/shims';
        mkdir($directory, 0700);
        file_put_contents($directory . '/' . $command, "#!/bin/sh\n" . $body . "\n");
        chmod($directory . '/' . $command, 0700);

        return $directory;
    }

    /**
     * A session store holding this account's files beside other accounts' and lookalikes that must survive.
     *
     * Anchored: two own, two encoded and one literal mailbox file (one with a comma), plus an own-named directory and
     * symlink that are never regular files. Unexplained: another account whose name only contains this one, and a
     * mailbox on a domain that only extends this one. Unrelated: npr and a session that holds the name in its contents.
     */
    private function tree(bool $populated = true): string
    {
        $tree = $this->directory . '/tree';
        $sessions = $tree . '/var/cpanel/sessions';
        foreach (['raw', 'cache', 'preauth'] as $folder) {
            mkdir($sessions . '/' . $folder, 0700, true);
        }
        mkdir($tree . '/outside', 0700);
        file_put_contents($sessions . '/raw/npr:Zz9', 'x');
        file_put_contents($sessions . '/preauth/random', 'wlsession');
        if (!$populated) {
            return $tree;
        }
        $mailbox = self::MAILBOX;
        foreach (
            ['raw/wlsession:AbC123', 'cache/wlsession:AbC123', 'raw/' . $mailbox . '%40' . self::DOMAIN . ':Xy_9',
                'cache/' . $mailbox . '%40' . self::DOMAIN . ':Xy_9', 'raw/' . $mailbox . '@' . self::DOMAIN . ':a,b',
                'raw/wlsession2:Other', 'raw/' . $mailbox . '%40' . self::DOMAIN . '.au:Q1'] as $file
        ) {
            file_put_contents($sessions . '/' . $file, 'x');
        }
        mkdir($sessions . '/raw/wlsession:directory', 0700);
        file_put_contents($tree . '/outside/wlsession:target', 'x');
        symlink($tree . '/outside/wlsession:target', $sessions . '/raw/wlsession:link');

        return $tree;
    }

    /** @return array<string, string> Every entry's type and content hash, to show what a run changed. */
    private function snapshot(string $tree): array
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $snapshot = [];
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            $path = $entry->getPathname();
            $snapshot[$path] = match (true) {
                $entry->isLink() => 'link',
                $entry->isDir() => 'directory',
                default => (string) md5_file($path),
            };
        }
        ksort($snapshot);

        return $snapshot;
    }
}
