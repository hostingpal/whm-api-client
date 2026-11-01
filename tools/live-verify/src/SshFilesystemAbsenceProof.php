<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Check filesystem absence on the test server over its `oblak.test` SSH alias, by one fixed read-only script.
 *
 * The script is constant: it reads, counts and prints `label=count` lines and nothing else, and it holds no account
 * name. The name and domain are checked against narrow patterns, then passed as positional arguments of a fixed remote
 * command, so unvalidated text can never reach a shell. An invalid name starts no process. Host keys are never accepted
 * or changed: strict checking refuses unknown or changed keys and key updates are disabled. No connection is repeated,
 * and credentials stay out of the child's environment.
 *
 * Each location reports its own inventory beside the match, because an empty or unreadable place cannot show absence:
 * no match in a missing `/etc/passwd` proves nothing. The session match is deliberately wide, any name containing the
 * account name, because nothing has shown how session files are named. A connection, exit status, timeout or reply
 * that is not exactly the expected report is `unconfirmed`. Standard error is read and discarded, never logged.
 *
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class SshFilesystemAbsenceProof implements FilesystemAbsenceProof
{
    /** Each read stays one printed count; the positional account name and domain are `$1` and `$2`. */
    public const SCRIPT = <<<'SCRIPT'
        printf 'home_inventory=%s\n' "$(find /home -mindepth 1 -maxdepth 1 | wc -l)"
        printf 'home=%s\n' "$(find /home -mindepth 1 -maxdepth 1 -name "$1" | wc -l)"
        printf 'users_inventory=%s\n' "$(find /var/cpanel/users -mindepth 1 -maxdepth 1 | wc -l)"
        printf 'users=%s\n' "$(find /var/cpanel/users -mindepth 1 -maxdepth 1 -name "$1" | wc -l)"
        printf 'userdata_inventory=%s\n' "$(find /var/cpanel/userdata -mindepth 1 -maxdepth 1 | wc -l)"
        printf 'userdata=%s\n' "$(find /var/cpanel/userdata -mindepth 1 -maxdepth 1 -name "$1" | wc -l)"
        printf 'userdomains_inventory=%s\n' "$(grep -c '' /etc/userdomains)"
        printf 'userdomains=%s\n' "$(grep -c -F -e "$1" -e "$2" /etc/userdomains)"
        printf 'passwd_inventory=%s\n' "$(grep -c '' /etc/passwd)"
        printf 'passwd=%s\n' "$(grep -c "^$1:" /etc/passwd)"
        printf 'zones_inventory=%s\n' "$(find /var/named -mindepth 1 -maxdepth 1 -name '*.db' | wc -l)"
        printf 'zones=%s\n' "$(find /var/named -name "$2.db*" | wc -l)"
        printf 'sessions_inventory=%s\n' "$(find /var/cpanel/sessions -mindepth 1 -maxdepth 1 | wc -l)"
        printf 'sessions=%s\n' "$(find /var/cpanel/sessions -name "*$1*" | wc -l)"
        printf 'complete=1\n'

        SCRIPT;

    private const HOST = 'oblak.test';

    private const OPTIONS = [
        '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10',
        '-o', 'UpdateHostKeys=no',
    ];

    private const USER = '/\A[a-z][a-z0-9]{0,15}\z/';

    private const DOMAIN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+\z/';

    /** Reported in this order, each as its inventory then its matches. */
    private const LOCATIONS = ['home', 'users', 'userdata', 'userdomains', 'passwd', 'zones', 'sessions'];

    private const MAXIMUM_OUTPUT = 4096;

    /** The second value bounds the whole check; only connecting is bounded by ConnectTimeout. */
    public function __construct(
        private readonly string $ssh = 'ssh',
        private readonly float $timeout = 30.0,
    ) {
    }

    /** Return the proof when `WHM_API_LIVE_FS_CHECK=1` opts in, otherwise null so nothing is claimed. */
    public static function fromEnvironment(): ?self
    {
        return getenv('WHM_API_LIVE_FS_CHECK') === '1' ? new self() : null;
    }

    public function check(string $user, string $domain): array
    {
        $stdout = preg_match(self::USER, $user) === 1 && preg_match(self::DOMAIN, $domain) === 1
            ? $this->remoteOutput('sh -s -- ' . $user . ' ' . $domain) : null;

        return $stdout === null ? self::unconfirmed() : self::finding($stdout);
    }

    /**
     * Read the strict report: a location with a match is residue, and absence needs every inventory to be usable.
     *
     * @return Finding
     */
    private static function finding(string $stdout): array
    {
        $pattern = '/\A';
        foreach (self::LOCATIONS as $location) {
            $pattern .= $location . '_inventory=([0-9]{1,9})\n' . $location . '=([0-9]{1,9})\n';
        }
        if (preg_match($pattern . 'complete=1\n\z/', $stdout, $counts) !== 1) {
            return self::unconfirmed();
        }
        $residue = [];
        $inventories = true;
        foreach (self::LOCATIONS as $index => $location) {
            $inventories = $inventories && (int) $counts[2 * $index + 1] > 0;
            $matches = (int) $counts[2 * $index + 2];
            if ($matches > 0) {
                $residue[$location] = $matches;
            }
        }
        if ($residue !== []) {
            return ['outcome' => 'present', 'residue' => $residue];
        }

        return $inventories ? ['outcome' => 'confirmed_absent', 'residue' => []] : self::unconfirmed();
    }

    /** @return Finding */
    private static function unconfirmed(): array
    {
        return ['outcome' => 'unconfirmed', 'residue' => []];
    }

    /** Run the one fixed connection without a local shell; null unless it exits 0 within the bound. */
    private function remoteOutput(string $command): ?string
    {
        $pipes = [];
        $process = @proc_open(
            [$this->ssh, ...self::OPTIONS, self::HOST, $command],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            self::environment(),
        );
        if ($process === false) {
            return null;
        }
        $deadline = microtime(true) + $this->timeout;
        @fwrite($pipes[0], self::SCRIPT);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== [] && strlen($stdout) <= self::MAXIMUM_OUTPUT && microtime(true) < $deadline) {
            $ready = $open;
            $write = $except = null;
            if (@stream_select($ready, $write, $except, 0, 50000) === false) {
                break;
            }
            foreach ($ready as $index => $pipe) {
                $chunk = fread($pipe, 8192);
                if ($index === 1 && is_string($chunk)) {
                    $stdout .= $chunk;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$index]);
                }
            }
        }
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        $exit = $open === [] ? self::exitStatus($process, $deadline) : null;
        proc_terminate($process, 9);
        proc_close($process);

        return $exit === 0 && strlen($stdout) <= self::MAXIMUM_OUTPUT ? $stdout : null;
    }

    /**
     * Wait for the exit status until the deadline, as a process can close its pipes and keep running.
     *
     * @param resource $process
     */
    private static function exitStatus($process, float $deadline): ?int
    {
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status['exitcode'];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Pass only what ssh needs to find its configuration and keys; credentials stay out of the child.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $environment = [];
        foreach (['PATH', 'HOME', 'USER', 'LOGNAME', 'SSH_AUTH_SOCK'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
