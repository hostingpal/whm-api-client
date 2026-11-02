<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Observe and remove one removed account's anchored session files over the `oblak.test` SSH alias.
 *
 * Each step is one fixed script sent on standard input; it holds no name. The account name, its domain and the
 * recipe's mailbox are positional arguments, checked here against narrow patterns and again by the script itself, so
 * no unvalidated text reaches a shell or a find pattern. The observation only reads and counts. The removal deletes
 * through one find action bound to `/var/cpanel/sessions`, regular files only (find never follows a link) and the
 * three anchored prefixes, then counts again. Each script prints `label=count` lines and nothing else.
 *
 * The connection is the filesystem proof's: strict host-key checking with updates off, no connection sharing, no
 * repeat, credentials kept out of the child's environment and standard error discarded. A connection, exit status,
 * timeout or reply that is not exactly the expected report is null, never a count. The process is killed only while
 * it is still running.
 *
 * @phpstan-import-type Observation from SessionFileCleanup
 * @phpstan-import-type Removal from SessionFileCleanup
 */
final class SshSessionFileCleanup implements SessionFileCleanup
{
    /** Read-only: every line is one count, and a failed search prints a blank the parser refuses. */
    public const OBSERVE = <<<'SCRIPT'
        export LC_ALL=C
        case "$1" in ''|*[!a-z0-9]*) exit 1 ;; esac
        case "$2" in ''|*[!a-z0-9.-]*) exit 1 ;; esac
        case "$3" in ''|*[!a-z0-9]*) exit 1 ;; esac
        own="$1:" encoded="$3%40$2:" literal="$3@$2:" other='*[!A-Za-z0-9_]*'
        count() {
            found=$(find /var/cpanel/sessions "$@") || return 1
            printf '%s' "$found" | grep -c ''
        }
        folders() {
            found=$(find /var/cpanel/sessions "$@" -printf '%h\n') || return 1
            printf '%s' "$found" | sort -u | grep -c ''
        }
        printf 'inventory=%s\n' "$(count -mindepth 1)"
        printf 'wide=%s\n' "$(count -name "*$1*")"
        printf 'own=%s\n' "$(count -type f -name "$own*")"
        printf 'mailbox_encoded=%s\n' "$(count -type f -name "$encoded*")"
        printf 'mailbox_literal=%s\n' "$(count -type f -name "$literal*")"
        printf 'anchored=%s\n' "$(count \( -name "$own*" -o -name "$encoded*" -o -name "$literal*" \))"
        printf 'unexplained=%s\n' "$(count -name "*$1*" ! -name "$own*" ! -name "$encoded*" ! -name "$literal*")"
        printf 'token_suffix=%s\n' "$(count -type f \( -name "$own?*" -o -name "$encoded?*" -o -name "$literal?*" \) \
            ! -name "$own$other" ! -name "$encoded$other" ! -name "$literal$other")"
        printf 'directories=%s\n' "$(folders -type f \( -name "$own*" -o -name "$encoded*" -o -name "$literal*" \))"
        printf 'complete=1\n'

        SCRIPT;

    /** The one write: anchored regular files only, deleted by one find action and counted before and after. */
    public const REMOVE = <<<'SCRIPT'
        export LC_ALL=C
        case "$1" in ''|*[!a-z0-9]*) exit 1 ;; esac
        case "$2" in ''|*[!a-z0-9.-]*) exit 1 ;; esac
        case "$3" in ''|*[!a-z0-9]*) exit 1 ;; esac
        own="$1:" encoded="$3%40$2:" literal="$3@$2:"
        count() {
            found=$(find /var/cpanel/sessions "$@") || return 1
            printf '%s' "$found" | grep -c ''
        }
        owned() {
            count -type f \( -name "$own*" -o -name "$encoded*" -o -name "$literal*" \) "$@"
        }
        before=$(owned)
        [ -n "$before" ] || exit 1
        printf 'before=%s\n' "$before"
        printf 'deleted=%s\n' "$(owned -print -delete)"
        printf 'after=%s\n' "$(owned)"
        printf 'wide_after=%s\n' "$(count -name "*$1*")"
        printf 'complete=1\n'

        SCRIPT;

    /** The recipe's own mailbox local part, as narrow as the account name. */
    public const MAILBOX_PATTERN = '/\A[a-z][a-z0-9]{0,63}\z/';

    private const HOST = 'oblak.test';

    private const OPTIONS = [
        '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10',
        '-o', 'UpdateHostKeys=no', '-o', 'ControlMaster=no', '-o', 'ControlPath=none',
    ];

    private const OBSERVATION = ['inventory', 'wide', 'own', 'mailbox_encoded', 'mailbox_literal', 'anchored',
        'unexplained', 'token_suffix', 'directories'];

    private const REMOVAL = ['before', 'deleted', 'after', 'wide_after'];

    private const MAXIMUM_OUTPUT = 4096;

    /** The second value bounds each whole step; only connecting is bounded by ConnectTimeout. */
    public function __construct(
        private readonly string $ssh = 'ssh',
        private readonly float $timeout = 60.0,
    ) {
    }

    public function observe(string $user, string $domain, string $mailbox): ?array
    {
        $counts = $this->counts(self::OBSERVE, self::OBSERVATION, $user, $domain, $mailbox);
        if ($counts === null || $counts['inventory'] === 0) {
            return null;
        }

        /** @var Observation $counts */
        return $counts;
    }

    public function remove(string $user, string $domain, string $mailbox): ?array
    {
        /** @var Removal|null */
        return $this->counts(self::REMOVE, self::REMOVAL, $user, $domain, $mailbox);
    }

    /**
     * Run one fixed script and read its strict report: exactly these labels, in order, then completion.
     *
     * @param list<string> $labels
     * @return array<string, int>|null
     */
    private function counts(string $script, array $labels, string $user, string $domain, string $mailbox): ?array
    {
        if (
            preg_match(DisposableAccount::USER_PATTERN, $user) !== 1
            || preg_match(DisposableAccount::DOMAIN_PATTERN, $domain) !== 1
            || preg_match(self::MAILBOX_PATTERN, $mailbox) !== 1
        ) {
            return null;
        }
        $stdout = $this->remoteOutput($script, 'sh -s -- ' . $user . ' ' . $domain . ' ' . $mailbox);
        $pattern = '/\A';
        foreach ($labels as $label) {
            $pattern .= $label . '=([0-9]{1,9})\n';
        }
        if ($stdout === null || preg_match($pattern . 'complete=1\n\z/', $stdout, $match) !== 1) {
            return null;
        }
        $counts = [];
        foreach ($labels as $index => $label) {
            $counts[$label] = (int) $match[$index + 1];
        }

        return $counts;
    }

    /**
     * Run the one fixed connection without a local shell; null unless it exits 0 within the bound.
     *
     * Only standard output ends the read; the wait then needs only the process itself.
     */
    private function remoteOutput(string $script, string $command): ?string
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
        @fwrite($pipes[0], $script);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while (isset($open[1]) && strlen($stdout) <= self::MAXIMUM_OUTPUT && microtime(true) < $deadline) {
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
        $exit = isset($open[1]) ? null : self::exitStatus($process, $deadline);
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        // A reaped process's number may already belong to another process, so kill only one still running.
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);

        return $exit === 0 && strlen($stdout) <= self::MAXIMUM_OUTPUT ? $stdout : null;
    }

    /** @param resource $process */
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
