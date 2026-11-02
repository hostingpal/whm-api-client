<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Run one fixed script on the test server over its `oblak.test` SSH alias and read back counts by fixed label.
 *
 * The script travels on standard input and holds no name; values arrive only as positional arguments of the fixed
 * remote command `sh -s --`, and each must be one plain word, so no unvalidated text can reach a shell. An unsafe
 * argument starts no process. Host keys are never accepted or changed: strict checking refuses unknown or changed keys
 * and key updates are disabled. Connection sharing is off, so a user's ControlMaster setting cannot reuse or outlive
 * the session. Nothing is repeated, credentials stay out of the child's environment, and standard error is read and
 * discarded, never logged.
 *
 * The reply must be exactly `label=count` for each given label, in order, then `complete=1`, from a process that
 * exited 0 within the bound. A connection, exit status, timeout, oversized output or reply that is anything else is
 * null, never a count. The process is killed only while it is still running: once reaped, its number can belong to
 * another process.
 */
final class SshScriptRunner
{
    /** One plain word: no shell metacharacter, whitespace, path separator or leading option dash. */
    public const ARGUMENT_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/';

    private const HOST = 'oblak.test';

    private const OPTIONS = [
        '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10',
        '-o', 'UpdateHostKeys=no', '-o', 'ControlMaster=no', '-o', 'ControlPath=none',
    ];

    private const MAXIMUM_OUTPUT = 4096;

    /** The second value bounds the whole run; only connecting is bounded by ConnectTimeout. */
    public function __construct(
        private readonly string $ssh = 'ssh',
        private readonly float $timeout = 30.0,
    ) {
    }

    /**
     * Run the script once with these arguments and return each label's count, or null for anything but the report.
     *
     * @param list<string> $arguments
     * @param non-empty-list<string> $labels
     * @return array<string, int>|null
     */
    public function counts(string $script, array $arguments, array $labels): ?array
    {
        foreach ($arguments as $argument) {
            if (preg_match(self::ARGUMENT_PATTERN, $argument) !== 1) {
                return null;
            }
        }
        $stdout = $this->output($script, implode(' ', ['sh -s --', ...$arguments]));
        $pattern = '/\A';
        foreach ($labels as $label) {
            $pattern .= preg_quote($label, '/') . '=([0-9]{1,9})\n';
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
     * Only standard output ends the read. Standard error can outlive the process, as anything it started may inherit
     * it, so the report is complete at output's end and the wait then needs only the process itself.
     */
    private function output(string $script, string $command): ?string
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
        // proc_get_status() caches the exit status since PHP 8.3, so it never reports a reaped process as running.
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
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
