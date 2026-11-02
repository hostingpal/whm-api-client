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

    private const OBSERVED = "inventory=12\nwide=8\nown=0\nmailbox_encoded=0\nmailbox_literal=8\nanchored_other=0\n"
        . "unexplained=0\ntoken_suffix=0\ntop_level=0\nin_subfolders=8\ncomplete=1\n";

    private const REMOVED = "before_inventory=12\nbefore_wide=8\nbefore_own=0\nbefore_mailbox_encoded=0\n"
        . "before_mailbox_literal=8\nbefore_anchored_other=0\nbefore_unexplained=0\nbefore_token_suffix=0\n"
        . "before_top_level=0\nbefore_in_subfolders=8\nattempted=8\ndeletion_failed=0\nafter_inventory=4\n"
        . "after_wide=0\nafter_own=0\nafter_mailbox_encoded=0\nafter_mailbox_literal=0\nafter_anchored_other=0\n"
        . "after_unexplained=0\nafter_token_suffix=0\nafter_top_level=0\nafter_in_subfolders=0\ncomplete=1\n";

    /** What the populated tree holds: every entry has one class, and one file-type rule splits anchored names. */
    private const POPULATED = [
        'inventory' => 15, 'wide' => 10, 'own' => 3, 'mailbox_encoded' => 2, 'mailbox_literal' => 1,
        'anchored_other' => 2, 'unexplained' => 2, 'token_suffix' => 5, 'top_level' => 1, 'in_subfolders' => 5,
    ];

    /** What removal leaves of the populated tree: the anchored directory and link, and the unexplained names. */
    private const LEFT = [
        'inventory' => 9, 'wide' => 4, 'own' => 0, 'mailbox_encoded' => 0, 'mailbox_literal' => 0,
        'anchored_other' => 2, 'unexplained' => 2, 'token_suffix' => 0, 'top_level' => 0, 'in_subfolders' => 0,
    ];

    private const CLEAN = [
        'inventory' => 5, 'wide' => 0, 'own' => 0, 'mailbox_encoded' => 0, 'mailbox_literal' => 0,
        'anchored_other' => 0, 'unexplained' => 0, 'token_suffix' => 0, 'top_level' => 0, 'in_subfolders' => 0,
    ];

    private string $directory = '';

    private string|false $optIn = false;

    public static function setUpBeforeClass(): void
    {
        // Must load before the shared runner's first call, which binds the function it resolves.
        require_once __DIR__ . '/ProcessSignalSpy.php';
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/session-cleanup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->optIn = getenv(SshSessionFileCleanup::OPT_IN);
        putenv(SshSessionFileCleanup::OPT_IN . '=1');
    }

    protected function tearDown(): void
    {
        putenv($this->optIn === false ? SshSessionFileCleanup::OPT_IN
            : SshSessionFileCleanup::OPT_IN . '=' . $this->optIn);
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            if ($entry->isDir() && !$entry->isLink()) {
                chmod($entry->getPathname(), 0700);
            }
        }
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
            . 'case "$script" in *-delete*) printf ' . escapeshellarg(self::REMOVED) . ' ;; '
            . '*) printf ' . escapeshellarg(self::OBSERVED) . ' ;; esac' . "\n");
        $cleanup = new SshSessionFileCleanup($ssh);
        self::assertNotNull($cleanup->observe(self::USER, self::DOMAIN, self::MAILBOX));
        self::assertNotNull($cleanup->remove(self::USER, self::DOMAIN, self::MAILBOX));

        $command = "-T\n-o\nBatchMode=yes\n-o\nStrictHostKeyChecking=yes\n-o\nConnectTimeout=10\n"
            . "-o\nUpdateHostKeys=no\n-o\nControlMaster=no\n-o\nControlPath=none\n"
            . "oblak.test\nsh -s -- wlsession wlsession.example.com box0123456789abcdef\n";
        self::assertSame($command . $command, file_get_contents($this->directory . '/argv'));
    }

    /** The removal is a root filesystem write, so it needs its own opt-in; without it no process starts. */
    #[DataProvider('absentOptIns')]
    public function testRemovalWithoutItsOwnOptInStartsNoProcess(?string $value): void
    {
        putenv($value === null ? SshSessionFileCleanup::OPT_IN : SshSessionFileCleanup::OPT_IN . '=' . $value);
        $marker = $this->directory . '/started';
        $ssh = $this->standIn('touch ' . escapeshellarg($marker) . "\ncat > /dev/null\nprintf "
            . escapeshellarg(self::OBSERVED) . "\n");
        $cleanup = new SshSessionFileCleanup($ssh);

        self::assertFalse(SshSessionFileCleanup::removalOptedIn());
        self::assertNull($cleanup->remove(self::USER, self::DOMAIN, self::MAILBOX));
        self::assertFileDoesNotExist($marker);
        // The read-only observation needs no such opt-in.
        self::assertNotNull($cleanup->observe(self::USER, self::DOMAIN, self::MAILBOX));
    }

    /** @return iterable<string, array{string|null}> */
    public static function absentOptIns(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'word' => ['true'];
    }

    public function testTheOptInIsSeparateFromTheFilesystemProof(): void
    {
        self::assertSame('WHM_API_LIVE_SESSION_CLEANUP', SshSessionFileCleanup::OPT_IN);
        self::assertTrue(SshSessionFileCleanup::removalOptedIn());
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
            // No find action prints a path or a name: only fixed tokens, type letters and depths.
            self::assertDoesNotMatchRegularExpression('/%[hfpPlHk]/', $script);
        }
    }

    /** The observation reads only, and walks the sessions tree once: one find, classified by one awk program. */
    public function testTheObservationWalksTheTreeOnceAndCanOnlyRead(): void
    {
        $script = SshSessionFileCleanup::OBSERVE;
        self::assertSame(1, substr_count($script, 'find '));
        self::assertSame(1, substr_count($script, 'find /var/cpanel/sessions -mindepth 1 '));
        self::assertSame(1, substr_count($script, "\nclassify '' || exit 1\n"));
        self::assertDoesNotMatchRegularExpression(
            '/-(?:delete|exec|execdir|ok|okdir|fprint|fprint0|fprintf|fls)\b|\brm\b|\bmv\b|\bunlink\b|>/',
            $script,
        );
    }

    /** The removal classifies once before and once after one find action bound to anchored regular files. */
    public function testTheRemovalDeletesOnlyThroughOneBoundFindAction(): void
    {
        $script = SshSessionFileCleanup::REMOVE;
        self::assertSame(1, substr_count($script, '-delete'));
        self::assertSame(2, substr_count($script, 'find '));
        self::assertSame(1, substr_count(
            $script,
            'find /var/cpanel/sessions -type f \( -name "$own*" -o -name "$encoded*" '
                . "-o -name \"\$literal*\" \\) \\\n        -printf 'attempt\\n' -delete\n",
        ));
        self::assertSame(1, substr_count($script, "\nclassify before_ || exit 1\n"));
        self::assertSame(1, substr_count($script, "\nclassify after_ || exit 1\n"));
        self::assertLessThan(strpos($script, '-delete'), strpos($script, 'classify before_'));
        self::assertDoesNotMatchRegularExpression(
            '/-(?:exec|execdir|ok|okdir|fprint|fprint0|fprintf|fls)\b|\brm\b|\bmv\b|\bunlink\b|>/',
            $script,
        );
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

    /**
     * Every entry gets exactly one class: the anchored directory and link are reported as anchored_other rather than
     * lost between a regular-file count and an any-type one, and the classes add up to the wide count.
     */
    public function testRealObservationGivesEveryEntryOneClassAndChangesNothing(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);
        $observed = $this->realScript($tree)->observe(self::USER, self::DOMAIN, self::MAILBOX);

        self::assertSame(self::POPULATED, $observed);
        self::assertSame(self::POPULATED['wide'], self::POPULATED['own'] + self::POPULATED['mailbox_encoded']
            + self::POPULATED['mailbox_literal'] + self::POPULATED['anchored_other'] + self::POPULATED['unexplained']);
        self::assertSame($before, $this->snapshot($tree));
    }

    public function testRealRemovalDeletesOnlyThisAccountsAnchoredRegularFiles(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);

        self::assertSame(
            ['before' => self::POPULATED, 'attempted' => 6, 'deletion_failed' => false, 'after' => self::LEFT],
            $this->realScript($tree)->remove(self::USER, self::DOMAIN, self::MAILBOX),
        );
        $sessions = $tree . '/var/cpanel/sessions/';
        $removed = [
            'wlsession:Top1', 'raw/wlsession:AbC123', 'cache/wlsession:AbC123',
            'raw/' . self::MAILBOX . '%40' . self::DOMAIN . ':Xy_9', 'cache/' . self::MAILBOX . '%40' . self::DOMAIN
                . ':Xy_9', 'raw/' . self::MAILBOX . '@' . self::DOMAIN . ':a,b',
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
            ['before' => self::CLEAN, 'attempted' => 0, 'deletion_failed' => false, 'after' => self::CLEAN],
            $this->realScript($tree)->remove(self::USER, self::DOMAIN, self::MAILBOX),
        );
        self::assertSame($before, $this->snapshot($tree));
    }

    /**
     * A deletion refused partway, as by a folder it cannot write, still gives a full report: it says the deletion
     * failed and carries the counts before and after, so what remains is known.
     */
    public function testAPartlyRefusedDeletionStillReportsItsCounts(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root ignores the folder permission that refuses the deletion here.');
        }
        $tree = $this->tree();
        chmod($tree . '/var/cpanel/sessions/cache', 0500);

        self::assertSame([
            'before' => self::POPULATED, 'attempted' => 6, 'deletion_failed' => true,
            'after' => ['inventory' => 11, 'wide' => 6, 'own' => 1, 'mailbox_encoded' => 1, 'mailbox_literal' => 0,
                'anchored_other' => 2, 'unexplained' => 2, 'token_suffix' => 2, 'top_level' => 0, 'in_subfolders' => 2],
        ], $this->realScript($tree)->remove(self::USER, self::DOMAIN, self::MAILBOX));
    }

    /** A deletion whose find reports failure, for any reason, is reported as failed with every count kept. */
    public function testAFailedDeletionIsReportedWithItsCountsWhateverTheUser(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);
        $real = trim((string) shell_exec('command -v find'));
        self::assertNotSame('', $real);
        $shims = $this->shim('find', 'for argument; do [ "$argument" = -delete ] && '
            . "{ printf 'attempt\\nattempt\\n'; exit 1; }; done\nexec " . escapeshellarg($real) . ' "$@"');

        self::assertSame(
            ['before' => self::POPULATED, 'attempted' => 2, 'deletion_failed' => true, 'after' => self::POPULATED],
            $this->realScript($tree, $shims)->remove(self::USER, self::DOMAIN, self::MAILBOX),
        );
        self::assertSame($before, $this->snapshot($tree));
    }

    /** Names are never printed, so a newline inside a file's name cannot split or forge a record. */
    public function testAFileNameHoldingANewlineIsCountedOnceAndRemoved(): void
    {
        $tree = $this->tree();
        $path = $tree . "/var/cpanel/sessions/raw/wlsession:new\nmailbox_literal f 1 1 1";
        file_put_contents($path, 'x');

        self::assertSame(
            [...self::POPULATED, 'inventory' => 16, 'wide' => 11, 'own' => 4, 'in_subfolders' => 6],
            $this->realScript($tree)->observe(self::USER, self::DOMAIN, self::MAILBOX),
        );
        $removal = $this->realScript($tree)->remove(self::USER, self::DOMAIN, self::MAILBOX);
        self::assertNotNull($removal);
        self::assertSame(7, $removal['attempted']);
        self::assertFalse($removal['deletion_failed']);
        self::assertFileDoesNotExist($path);
    }

    /**
     * Folder paths are never printed either, so a folder whose name holds a newline and a forged record changes
     * nothing but its own honest counts: the folder is unrelated, and the file inside it is one more own file.
     */
    public function testAFolderNameHoldingANewlineCannotForgeARecord(): void
    {
        $tree = $this->tree();
        $folder = $tree . "/var/cpanel/sessions/odd\nmailbox_literal f 1 1 1\nunexplained d 1 0";
        mkdir($folder, 0700);
        file_put_contents($folder . '/wlsession:Zz9', 'x');

        self::assertSame(
            [...self::POPULATED, 'inventory' => 17, 'wide' => 11, 'own' => 4, 'token_suffix' => 6,
                'in_subfolders' => 6],
            $this->realScript($tree)->observe(self::USER, self::DOMAIN, self::MAILBOX),
        );
        $removal = $this->realScript($tree)->remove(self::USER, self::DOMAIN, self::MAILBOX);
        self::assertNotNull($removal);
        self::assertSame(7, $removal['attempted']);
        self::assertDirectoryExists($folder);
        self::assertFileDoesNotExist($folder . '/wlsession:Zz9');
    }

    /** The script re-checks its own arguments, so even a bypassed caller cannot widen a pattern. */
    public function testTheScriptsRefuseUnsafeArgumentsThemselves(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);
        $unsafe = [
            ['*', self::DOMAIN, self::MAILBOX], [self::USER, '*', self::MAILBOX], [self::USER, self::DOMAIN, '*'],
        ];
        foreach ([SshSessionFileCleanup::OBSERVE, SshSessionFileCleanup::REMOVE] as $script) {
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

    /** A walk that fails after printing some records gives no count, and removal stops before it deletes anything. */
    public function testAFailedWalkNeverGivesACount(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);
        $shims = $this->shim('find', "printf 'unrelated d 0 0\\n'\nexit 1");

        self::assertNull($this->realScript($tree, $shims)->observe(self::USER, self::DOMAIN, self::MAILBOX));
        self::assertNull($this->realScript($tree, $shims)->remove(self::USER, self::DOMAIN, self::MAILBOX));
        self::assertSame($before, $this->snapshot($tree));
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
        yield 'blank count' => ["printf 'inventory=\\nbefore_inventory=\\n'\n"];
        yield 'incomplete' => ["printf 'inventory=9\\nwide=1\\n'\n"];
        yield 'earlier labels' => ["printf 'inventory=9\\nwide=4\\nown=2\\nmailbox_encoded=2\\nmailbox_literal=0\\n"
            . "anchored_other=0\\nunexplained=0\\ntoken_suffix=4\\ndirectories=2\\ncomplete=1\\n'\n"];
        yield 'a deletion flag other than 0 or 1' => ['printf ' . escapeshellarg(str_replace(
            'deletion_failed=0',
            'deletion_failed=2',
            self::REMOVED,
        )) . "\n"];
        yield 'bad exit after a report' => ['printf ' . escapeshellarg(self::OBSERVED . self::REMOVED) . "\nexit 2\n"];
        yield 'extra line' => ['printf ' . escapeshellarg(self::OBSERVED . "private\n") . "\n"];
        yield 'timeout' => ["sleep 5\n"];
    }

    public function testStandardErrorIsDiscarded(): void
    {
        $ssh = $this->standIn(
            "cat > /dev/null\necho private-warning >&2\nprintf " . escapeshellarg(self::REMOVED) . "\n",
        );
        $removal = (new SshSessionFileCleanup($ssh))->remove(self::USER, self::DOMAIN, self::MAILBOX);

        self::assertNotNull($removal);
        self::assertSame(8, $removal['attempted']);
        self::assertFalse($removal['deletion_failed']);
        self::assertSame(8, $removal['before']['mailbox_literal']);
        self::assertSame(4, $removal['after']['inventory']);
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
     * Anchored regular files: one own file at the top level, two own, two encoded and one literal mailbox file (one
     * with a comma) in subfolders. Anchored but not regular: an own-named directory and symlink. Unexplained: another
     * account whose name only contains this one, and a mailbox on a domain that only extends this one. Unrelated:
     * three folders, npr, and a session that holds the name only in its contents.
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
            ['wlsession:Top1', 'raw/wlsession:AbC123', 'cache/wlsession:AbC123',
                'raw/' . $mailbox . '%40' . self::DOMAIN . ':Xy_9',
                'cache/' . $mailbox . '%40' . self::DOMAIN . ':Xy_9',
                'raw/' . $mailbox . '@' . self::DOMAIN . ':a,b', 'raw/wlsession2:Other',
                'raw/' . $mailbox . '%40' . self::DOMAIN . '.au:Q1'] as $file
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
