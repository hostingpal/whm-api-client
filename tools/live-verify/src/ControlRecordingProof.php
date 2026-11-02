<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Pass every check to another filesystem proof unchanged, and remember the first finding: the lifecycle's control.
 *
 * DisposableAccountLifecycle takes its positive control with the first check, after setup or, if setup stopped after
 * creation, in cleanup before removal, and it never exposes the finding before run() returns. A recipe callback that
 * runs inside the lifecycle can read it here instead. This adds no read of its own and writes nothing; a check that
 * throws leaves no control, which reads as not passed.
 *
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class ControlRecordingProof implements FilesystemAbsenceProof
{
    private bool $checked = false;

    /** @var Finding|null */
    private ?array $control = null;

    /** Wrap one proof; construction checks nothing. */
    public function __construct(private readonly FilesystemAbsenceProof $proof)
    {
    }

    public function check(string $user, string $domain): array
    {
        $first = !$this->checked;
        $this->checked = true;
        $finding = $this->proof->check($user, $domain);
        if ($first) {
            $this->control = $finding;
        }

        return $finding;
    }

    /** Whether the first check passed as the lifecycle's control does: present in every account location. */
    public function controlPassed(): bool
    {
        return $this->control !== null && $this->control['outcome'] === 'present'
            && array_diff(self::ACCOUNT_LOCATIONS, array_keys($this->control['residue'])) === [];
    }

    /** Whether the control already saw a session name, before any session of this run could exist. */
    public function controlSawSessions(): bool
    {
        return isset($this->control['residue']['sessions']);
    }
}
