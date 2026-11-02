<?php

declare(strict_types=1);

// Test-only: shadows proc_terminate() inside the tools namespace, so a test can see every signal the SSH proof sends.
// PHP resolves the unqualified call to this function when it exists, then delivers the real signal unchanged.
// SshFilesystemAbsenceProofTest loads this file before its first test; nothing in production ever does.

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Record one signal, then deliver it exactly as the real function would.
 *
 * @param resource $process
 */
function proc_terminate($process, int $signal = 15): bool
{
    sentSignals($signal);

    return \proc_terminate($process, $signal);
}

/**
 * Every signal recorded since the last reset; passing a signal records it, and reset clears the record.
 *
 * @return list<int>
 */
function sentSignals(?int $signal = null, bool $reset = false): array
{
    /** @var list<int> $sent */
    static $sent = [];
    if ($reset) {
        $sent = [];
    }
    if ($signal !== null) {
        $sent[] = $signal;
    }

    return $sent;
}
