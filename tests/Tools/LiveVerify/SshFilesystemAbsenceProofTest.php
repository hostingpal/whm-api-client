<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use FilesystemIterator;
use HostingPal\WhmApiClient\Tools\LiveVerify\SshFilesystemAbsenceProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A stand-in `ssh` receives the exact command line. For the real-script cases it hands the script to a local shell
 * with its absolute paths moved into a synthetic tree, so the fixed script itself is executed offline.
 */
final class SshFilesystemAbsenceProofTest extends TestCase
{
    private const USER = 'wlupload';

    private const DOMAIN = 'wlupload.example.com';

    private const CLEAN = "home_inventory=2\nhome=0\nusers_inventory=2\nusers=0\nuserdata_inventory=2\nuserdata=0\n"
        . "userdomains_inventory=2\nuserdomains=0\npasswd_inventory=30\npasswd=0\nzones_inventory=2\nzones=0\n"
        . "sessions_inventory=3\nsessions=0\ncomplete=1\n";

    private string $directory = '';

    private ?string $savedOptIn = null;

    private string|false $savedToken = false;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ssh-proof-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $optIn = getenv('WHM_API_LIVE_FS_CHECK');
        $this->savedOptIn = $optIn === false ? null : $optIn;
        $this->savedToken = getenv('OBLAK_TEST_WHM_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->savedOptIn === null ? 'WHM_API_LIVE_FS_CHECK' : 'WHM_API_LIVE_FS_CHECK=' . $this->savedOptIn);
        putenv($this->savedToken === false ? 'OBLAK_TEST_WHM_KEY' : 'OBLAK_TEST_WHM_KEY=' . $this->savedToken);
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

    public function testConnectsThroughTheFixedStrictCommandAndNothingElse(): void
    {
        $ssh = $this->standIn('printf \'%s\n\' "$@" > ' . escapeshellarg($this->directory . '/argv') . "\n"
            . 'cat > /dev/null' . "\n" . 'printf ' . escapeshellarg(self::CLEAN) . "\n");
        (new SshFilesystemAbsenceProof($ssh))->check(self::USER, self::DOMAIN);

        self::assertSame(
            "-T\n-o\nBatchMode=yes\n-o\nStrictHostKeyChecking=yes\n-o\nConnectTimeout=10\n-o\nUpdateHostKeys=no\n"
                . "-o\nControlMaster=no\n-o\nControlPath=none\n"
                . "oblak.test\nsh -s -- wlupload wlupload.example.com\n",
            file_get_contents($this->directory . '/argv'),
        );
    }

    public function testTheFixedScriptTravelsOverStandardInputWithoutTheAccountName(): void
    {
        $ssh = $this->standIn('cat > ' . escapeshellarg($this->directory . '/stdin') . "\n"
            . 'printf ' . escapeshellarg(self::CLEAN) . "\n");
        (new SshFilesystemAbsenceProof($ssh))->check(self::USER, self::DOMAIN);

        self::assertSame(SshFilesystemAbsenceProof::SCRIPT, file_get_contents($this->directory . '/stdin'));
        self::assertStringNotContainsString(self::USER, SshFilesystemAbsenceProof::SCRIPT);
    }

    #[DataProvider('unsafeNames')]
    public function testUnsafeNamesNeverStartAProcess(string $user, string $domain): void
    {
        $marker = $this->directory . '/started';
        $ssh = $this->standIn('touch ' . escapeshellarg($marker) . "\n");
        $finding = (new SshFilesystemAbsenceProof($ssh))->check($user, $domain);

        self::assertSame(['outcome' => 'unconfirmed', 'residue' => []], $finding);
        self::assertFileDoesNotExist($marker);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeNames(): iterable
    {
        foreach (
            ['', 'A', 'Wlupload', '1user', 'a b', 'a;id', 'a$(id)', 'a`id`', 'a|b', 'a&b', 'a>b', '-o', '../root',
                'a/b', "a\n", "a\nb", 'a*', 'a?', "a'b", 'a"b', 'a\\b', str_repeat('a', 17)] as $user
        ) {
            yield 'user ' . json_encode($user) => [$user, self::DOMAIN];
        }
        foreach (
            ['', 'localhost', 'a;id.example.com', 'a b.example.com', '-o.example.com', "a.example.com\n", 'a..com',
                '.example.com', 'example.com.', 'a/b.example.com', 'a*.example.com', 'A.example.com',
                '$(id).example.com'] as $domain
        ) {
            yield 'domain ' . json_encode($domain) => [self::USER, $domain];
        }
    }

    /** Only reads: the script holds no redirection, no other command, and no writing or executing find action. */
    public function testTheFixedScriptCanOnlyReadAndPrint(): void
    {
        $lines = explode("\n", rtrim(SshFilesystemAbsenceProof::SCRIPT, "\n"));
        self::assertSame([
            'matches() {',
            '    found=$(find "$@") || return 1',
            "    printf '%s' \"\$found\" | grep -c ''",
            '}',
            'lines() {',
            '    count=$(grep -c "$@")',
            "    [ \"\$?\" -le 1 ] && printf '%s' \"\$count\"",
            '}',
        ], array_splice($lines, 0, 8));
        self::assertSame("printf 'complete=1\\n'", array_pop($lines));
        self::assertCount(14, $lines);
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression(
                '/\Aprintf \'[a-z_]+=%s\\\\n\' "\$\((?:matches|lines) [^;&|<>`]+\)"\z/',
                $line,
            );
            self::assertDoesNotMatchRegularExpression(
                '/ -(?:delete|exec|execdir|ok|okdir|fprint|fprint0|fprintf|fls|newer)\b/',
                $line,
            );
        }
    }

    public function testRealScriptConfirmsAbsenceOnACleanTree(): void
    {
        $tree = $this->tree();
        $before = $this->snapshot($tree);

        self::assertSame(
            ['outcome' => 'confirmed_absent', 'residue' => []],
            $this->realScript($tree)->check(self::USER, self::DOMAIN),
        );
        self::assertSame($before, $this->snapshot($tree));
    }

    /**
     * Every location is reported on its own, by a fixed label, with no name or path from the tree.
     *
     * @param array<string, int> $residue
     */
    #[DataProvider('residues')]
    public function testRealScriptReportsEachLocationByLabelAndCount(
        string $path,
        string $contents,
        array $residue,
    ): void {
        $tree = $this->tree();
        $target = $tree . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        if ($contents === 'directory') {
            mkdir($target, 0700);
        } else {
            file_put_contents($target, $contents, FILE_APPEND);
        }
        $before = $this->snapshot($tree);

        self::assertSame(
            ['outcome' => 'present', 'residue' => $residue],
            $this->realScript($tree)->check(self::USER, self::DOMAIN),
        );
        self::assertSame($before, $this->snapshot($tree));
    }

    /** @return iterable<string, array{string, string, array<string, int>}> */
    public static function residues(): iterable
    {
        yield 'home directory' => ['home/wlupload', 'directory', ['home' => 1]];
        yield 'dangling home link is not a false absence' => ['home/wlupload', '', ['home' => 1]];
        yield 'cPanel user file' => ['var/cpanel/users/wlupload', 'x', ['users' => 1]];
        yield 'cPanel user data' => ['var/cpanel/userdata/wlupload', 'directory', ['userdata' => 1]];
        yield 'userdomains by owner' => ['etc/userdomains', "kept.example: wlupload\n", ['userdomains' => 1]];
        yield 'userdomains by domain' => ['etc/userdomains', "wlupload.example.com: other\n", ['userdomains' => 1]];
        yield 'passwd' => ['etc/passwd', "wlupload:x:1001:1001::/home/wlupload:/bin/bash\n", ['passwd' => 1]];
        yield 'zone file' => ['var/named/wlupload.example.com.db', 'x', ['zones' => 1]];
        yield 'zone file variant' => ['var/named/cache/wlupload.example.com.db.cache', 'x', ['zones' => 1]];
        yield 'colon session file' => ['var/cpanel/sessions/raw/wlupload:abc', 'x', ['sessions' => 1]];
        yield 'session file by another name' => ['var/cpanel/sessions/preauth/x-wlupload-y', 'x', ['sessions' => 1]];
    }

    /** A domain rarely repeats the account name by rule, so userdomains must be searched for both. */
    public function testRealScriptFindsADomainThatDoesNotContainTheAccountName(): void
    {
        $tree = $this->tree();
        file_put_contents($tree . '/etc/userdomains', "separate.example.com: other\n", FILE_APPEND);

        self::assertSame(
            ['outcome' => 'present', 'residue' => ['userdomains' => 1]],
            $this->realScript($tree)->check(self::USER, 'separate.example.com'),
        );
    }

    public function testRealScriptCountsEveryMatchingSessionFile(): void
    {
        $tree = $this->tree();
        foreach (['raw/wlupload:one', 'raw/wlupload:two', 'preauth/wlupload:three'] as $file) {
            file_put_contents($tree . '/var/cpanel/sessions/' . $file, 'private session contents');
        }

        self::assertSame(
            ['outcome' => 'present', 'residue' => ['sessions' => 3]],
            $this->realScript($tree)->check(self::USER, self::DOMAIN),
        );
    }

    /** A similar account is not this account, except in the deliberately wide session match. */
    public function testRealScriptDoesNotConfuseASimilarAccountOutsideSessions(): void
    {
        $tree = $this->tree();
        mkdir($tree . '/home/wluploadextra');
        file_put_contents($tree . '/var/cpanel/users/wluploadextra', 'x');
        mkdir($tree . '/var/cpanel/userdata/wluploadextra');
        file_put_contents($tree . '/etc/passwd', "wluploadextra:x:1001:1001::/home/x:/bin/bash\n", FILE_APPEND);
        file_put_contents($tree . '/var/named/other.example.com.db', 'x');

        self::assertSame(
            ['outcome' => 'confirmed_absent', 'residue' => []],
            $this->realScript($tree)->check(self::USER, self::DOMAIN),
        );
    }

    /** An absent name proves nothing when the place it would appear cannot be read or holds nothing at all. */
    #[DataProvider('unusableInventories')]
    public function testRealScriptRefusesAbsenceFromAnUnusableInventory(string $path, string $change): void
    {
        $tree = $this->tree();
        $target = $tree . '/' . $path;
        if ($change === 'remove') {
            is_dir($target) ? self::removeTree($target) : unlink($target);
        } else {
            file_put_contents($target, '');
        }

        self::assertSame(
            ['outcome' => 'unconfirmed', 'residue' => []],
            $this->realScript($tree)->check(self::USER, self::DOMAIN),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableInventories(): iterable
    {
        yield 'no home directories' => ['home', 'remove'];
        yield 'no cPanel users' => ['var/cpanel/users', 'remove'];
        yield 'no cPanel user data' => ['var/cpanel/userdata', 'remove'];
        yield 'no userdomains' => ['etc/userdomains', 'remove'];
        yield 'empty userdomains' => ['etc/userdomains', 'empty'];
        yield 'no passwd' => ['etc/passwd', 'remove'];
        yield 'empty passwd' => ['etc/passwd', 'empty'];
        yield 'no zone files' => ['var/named/other.db', 'remove'];
        yield 'no sessions' => ['var/cpanel/sessions', 'remove'];
    }

    /** An empty place is still a successful read, so what another location shows stays reportable. */
    public function testRealResidueIsReportedEvenBesideAnEmptyInventory(): void
    {
        $tree = $this->tree();
        mkdir($tree . '/home/wlupload');
        unlink($tree . '/var/named/other.db');

        self::assertSame(
            ['outcome' => 'present', 'residue' => ['home' => 1]],
            $this->realScript($tree)->check(self::USER, self::DOMAIN),
        );
    }

    /**
     * Every search must be able to fail on its own; a failed one leaves a blank count that the parser refuses.
     *
     * Only the name search fails here. Its inventory still succeeds, so the inventory rule cannot be what catches it.
     */
    #[DataProvider('searchedPlaces')]
    public function testAFailedSearchNeverReadsAsAbsent(string $place): void
    {
        $tree = $this->tree();
        $shims = $this->shim(
            'find',
            'case "$*" in *' . $place . '*wlupload*) exit 1;; esac' . "\n" . 'exec /usr/bin/find "$@"',
        );

        self::assertSame(
            ['outcome' => 'unconfirmed', 'residue' => []],
            $this->realScript($tree, $shims)->check(self::USER, self::DOMAIN),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function searchedPlaces(): iterable
    {
        yield '/home' => ['/home'];
        yield '/var/cpanel/users' => ['/var/cpanel/users'];
        yield '/var/cpanel/userdata' => ['/var/cpanel/userdata'];
        yield '/var/named' => ['/var/named'];
        yield '/var/cpanel/sessions' => ['/var/cpanel/sessions'];
    }

    /** Lines already printed before an error are a partial answer, so they must not become a count. */
    public function testASearchThatFailsAfterSomeOutputGivesNoCount(): void
    {
        $tree = $this->tree();
        $shims = $this->shim('find', "printf 'partial\\n'\nexit 1");

        self::assertSame(
            ['outcome' => 'unconfirmed', 'residue' => []],
            $this->realScript($tree, $shims)->check(self::USER, self::DOMAIN),
        );
    }

    /** grep exits 1 when it finds nothing, which is an answer, and 2 when it cannot read, which is not. */
    public function testAGrepThatCannotReadNeverReadsAsAbsent(): void
    {
        $tree = $this->tree();
        $shims = $this->shim(
            'grep',
            'case "$*" in *wlupload*passwd*) printf 0; exit 2;; esac' . "\n" . 'exec /usr/bin/grep "$@"',
        );

        self::assertSame(
            ['outcome' => 'unconfirmed', 'residue' => []],
            $this->realScript($tree, $shims)->check(self::USER, self::DOMAIN),
        );
    }

    /** The real failure: a nested directory that cannot be read used to count as nothing found. */
    public function testAnUnreadableSubtreeNeverReadsAsAbsent(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Root can read every directory, so this needs an unprivileged user.');
        }
        $tree = $this->tree();
        $sealed = $tree . '/var/cpanel/sessions/raw';
        chmod($sealed, 0);
        $finding = $this->realScript($tree)->check(self::USER, self::DOMAIN);
        chmod($sealed, 0700);

        self::assertSame(['outcome' => 'unconfirmed', 'residue' => []], $finding);
    }

    /** @return iterable<string, array{string}> */
    public static function reportedCounts(): iterable
    {
        foreach (['home', 'users', 'userdata', 'userdomains', 'passwd', 'zones', 'sessions'] as $location) {
            yield $location . ' inventory' => [$location . '_inventory'];
            yield $location . ' matches' => [$location];
        }
    }

    /** A blank count is a search that failed, and it refuses the whole report, residue elsewhere included. */
    #[DataProvider('reportedCounts')]
    public function testABlankCountRefusesTheWholeReport(string $label): void
    {
        $report = (string) preg_replace('/^' . $label . '=[0-9]+$/m', $label . '=', self::CLEAN);
        self::assertNotSame(self::CLEAN, $report);
        $report = str_replace("\nzones=0", "\nzones=" . ($label === 'zones' ? '' : '2'), $report);
        $ssh = $this->standIn("cat > /dev/null\nprintf " . escapeshellarg($report) . "\n");

        self::assertSame(
            ['outcome' => 'unconfirmed', 'residue' => []],
            (new SshFilesystemAbsenceProof($ssh))->check(self::USER, self::DOMAIN),
        );
    }

    #[DataProvider('unusableReplies')]
    public function testAnyOtherReplyIsUnconfirmedNeverAbsent(string $body): void
    {
        $ssh = $this->standIn("cat > /dev/null\n" . $body);
        self::assertSame(
            ['outcome' => 'unconfirmed', 'residue' => []],
            (new SshFilesystemAbsenceProof($ssh))->check(self::USER, self::DOMAIN),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unusableReplies(): iterable
    {
        $clean = 'printf ' . escapeshellarg(self::CLEAN) . "\n";
        yield 'connection refused' => ["exit 255\n"];
        yield 'clean text behind a failed exit' => [$clean . "exit 1\n"];
        yield 'no output' => ["exit 0\n"];
        yield 'banner before the report' => ["printf 'Welcome\\n'\n" . $clean];
        yield 'extra line after the report' => [$clean . "printf 'more\\n'\n"];
        yield 'truncated report' => ['printf ' . escapeshellarg(substr(self::CLEAN, 0, 40)) . "\n"];
        yield 'no completion line' => ['printf ' . escapeshellarg(str_replace("complete=1\n", '', self::CLEAN)) . "\n"];
        yield 'reordered report' => ['printf ' . escapeshellarg(
            "home=0\nhome_inventory=2\n" . substr(self::CLEAN, 24),
        ) . "\n"];
        yield 'blank count' => ['printf ' . escapeshellarg(str_replace("\nhome=0", "\nhome=", self::CLEAN)) . "\n"];
        yield 'negative count' => [
            'printf ' . escapeshellarg(str_replace("\nhome=0", "\nhome=-1", self::CLEAN)) . "\n",
        ];
        yield 'text in a count' => [
            'printf ' . escapeshellarg(str_replace("\nhome=0", "\nhome=0x", self::CLEAN)) . "\n",
        ];
        yield 'crlf report' => ['printf ' . escapeshellarg(str_replace("\n", "\r\n", self::CLEAN)) . "\n"];
        yield 'oversized output' => ["head -c 70000 /dev/zero | tr '\\0' 'x'\n"];
    }

    public function testWarningsOnStandardErrorChangeNothingAndAreDiscarded(): void
    {
        $ssh = $this->standIn(
            "cat > /dev/null\nprintf 'private warning\\n' >&2\nprintf " . escapeshellarg(self::CLEAN) . "\n",
        );
        self::assertSame(
            ['outcome' => 'confirmed_absent', 'residue' => []],
            (new SshFilesystemAbsenceProof($ssh))->check(self::USER, self::DOMAIN),
        );
    }

    /** The full default bound is in force here: only the output limit can end this quickly. */
    public function testEndlessOutputStopsAtTheOutputLimitInsteadOfTheTimeout(): void
    {
        $ssh = $this->standIn("exec yes private-output\n");
        $started = microtime(true);
        $finding = (new SshFilesystemAbsenceProof($ssh))->check(self::USER, self::DOMAIN);

        self::assertSame(['outcome' => 'unconfirmed', 'residue' => []], $finding);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    /** A shared connection's master keeps standard error open after ssh exits, which must not cost a clean report. */
    public function testALingeringChildHoldingStandardErrorCostsNothing(): void
    {
        $ssh = $this->standIn(
            "cat > /dev/null\nsleep 5 > /dev/null < /dev/null &\nprintf " . escapeshellarg(self::CLEAN) . "\nexit 0\n",
        );
        $started = microtime(true);
        $finding = (new SshFilesystemAbsenceProof($ssh, 3.0))->check(self::USER, self::DOMAIN);

        self::assertSame(['outcome' => 'confirmed_absent', 'residue' => []], $finding);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testMissingBinaryIsUnconfirmed(): void
    {
        $proof = new SshFilesystemAbsenceProof($this->directory . '/no-such-ssh');
        self::assertSame(['outcome' => 'unconfirmed', 'residue' => []], $proof->check(self::USER, self::DOMAIN));
    }

    /** A stalled connection must end the check in bounded time, whether it holds its output open or closes it. */
    #[DataProvider('stalledConnections')]
    public function testStalledConnectionIsUnconfirmedInBoundedTime(string $body): void
    {
        $ssh = $this->standIn($body);
        $started = microtime(true);
        $finding = (new SshFilesystemAbsenceProof($ssh, 0.2))->check(self::USER, self::DOMAIN);

        self::assertSame(['outcome' => 'unconfirmed', 'residue' => []], $finding);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    /** @return iterable<string, array{string}> */
    public static function stalledConnections(): iterable
    {
        yield 'silent' => ["exec sleep 5\n"];
        yield 'clean output then stalled' => ['printf ' . escapeshellarg(self::CLEAN) . "\nexec sleep 5\n"];
        yield 'output closed then stalled' => ["exec >&- 2>&-\nexec sleep 5\n"];
    }

    public function testCredentialsNeverReachTheSshProcess(): void
    {
        putenv('OBLAK_TEST_WHM_KEY=private-whm-token');
        $ssh = $this->standIn('env > ' . escapeshellarg($this->directory . '/environment') . "\n"
            . 'cat > /dev/null' . "\n" . 'printf ' . escapeshellarg(self::CLEAN) . "\n");
        (new SshFilesystemAbsenceProof($ssh))->check(self::USER, self::DOMAIN);

        $environment = file_get_contents($this->directory . '/environment');
        self::assertIsString($environment);
        self::assertStringNotContainsString('private-whm-token', $environment);
        self::assertStringNotContainsString('OBLAK_TEST', $environment);
    }

    public function testExactOptInEnablesTheProof(): void
    {
        putenv('WHM_API_LIVE_FS_CHECK=1');
        self::assertInstanceOf(SshFilesystemAbsenceProof::class, SshFilesystemAbsenceProof::fromEnvironment());
    }

    #[DataProvider('absentOptIns')]
    public function testAnythingElseLeavesTheProofOff(?string $value): void
    {
        putenv($value === null ? 'WHM_API_LIVE_FS_CHECK' : 'WHM_API_LIVE_FS_CHECK=' . $value);
        self::assertNull(SshFilesystemAbsenceProof::fromEnvironment());
    }

    /** @return iterable<string, array{string|null}> */
    public static function absentOptIns(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'word' => ['true'];
    }

    /** Write an executable stand-in for ssh. */
    private function standIn(string $body): string
    {
        $path = $this->directory . '/ssh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0700);

        return $path;
    }

    /**
     * Run the real fixed script through the exact remote command, with its absolute paths moved under one tree.
     *
     * The script reads its own /home-style arguments, so a shim matching a place sees that place inside the tree.
     */
    private function realScript(string $tree, ?string $shims = null): SshFilesystemAbsenceProof
    {
        $move = 's# /# ' . $tree . '/#g';
        $path = $shims === null ? '' : 'PATH=' . escapeshellarg($shims) . ':"$PATH"' . "\n";

        return new SshFilesystemAbsenceProof($this->standIn(
            $path . "for last; do :; done\nsed " . escapeshellarg($move) . ' | eval "$last"' . "\n",
        ));
    }

    /** Shadow one command for the real script alone, as a failure the operating system would otherwise have to cause. */
    private function shim(string $command, string $body): string
    {
        $directory = $this->directory . '/shims';
        if (!is_dir($directory)) {
            mkdir($directory, 0700);
        }
        file_put_contents($directory . '/' . $command, "#!/bin/sh\n" . $body . "\n");
        chmod($directory . '/' . $command, 0700);

        return $directory;
    }

    /** A healthy server's inventories: other accounts everywhere, and no trace of the checked account. */
    private function tree(): string
    {
        $tree = $this->directory . '/tree';
        foreach (
            ['home/npr', 'var/cpanel/users', 'var/cpanel/userdata/npr', 'var/cpanel/sessions/raw',
            'var/cpanel/sessions/preauth', 'var/named', 'etc'] as $path
        ) {
            mkdir($tree . '/' . $path, 0700, true);
        }
        file_put_contents($tree . '/var/cpanel/users/npr', 'x');
        file_put_contents($tree . '/etc/userdomains', "npr.rs: npr\n*: nobody\n");
        file_put_contents($tree . '/etc/passwd', "root:x:0:0::/root:/bin/bash\nnpr:x:1000:1000::/home/npr:/bin/bash\n");
        file_put_contents($tree . '/var/named/other.db', 'x');

        return $tree;
    }

    /** @return array<string, string> Every entry's type, size and content hash, to show a run changed nothing. */
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
            $snapshot[$path] = $entry->isDir() ? 'directory' : (string) md5_file($path);
        }
        ksort($snapshot);

        return $snapshot;
    }

    private static function removeTree(string $path): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
