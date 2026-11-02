<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Check filesystem absence on the test server over its `oblak.test` SSH alias, by one fixed read-only script.
 *
 * The script is constant: it reads, counts and prints `label=count` lines and nothing else, and it holds no account
 * name. The name and domain are checked against narrow patterns, then passed as positional arguments of a fixed remote
 * command, so unvalidated text can never reach a shell. An invalid name starts no process. The connection, its fixed
 * options, bounds, environment and strict report parsing belong to SshScriptRunner, shared with the session-file
 * cleanup: host keys are never accepted or changed, connection sharing is off, no connection is repeated, and
 * credentials stay out of the child's environment.
 *
 * A search that fails prints no count, and the strict parser refuses a report with a blank one, so an unreadable place
 * can never read as absent. That covers a subtree a top-level inventory cannot see. Each location also reports its own
 * inventory beside the match, because an empty place cannot show absence either: no match in an empty `/etc/passwd`
 * proves nothing. Names are matched as whole fields wherever the format is known: `/etc/passwd` by its first field,
 * and `/etc/userdomains`, whose lines are `<domain>: <owner>`, by either field, compared exactly. The session match
 * is deliberately wide, any name containing the account name, because nothing has shown how session files are named.
 * Its cost is false residue: another account whose name contains this one makes a run fail, never pass. A
 * connection, exit status, timeout or reply that is not exactly the expected report is `unconfirmed`. Standard error
 * is read and discarded, never logged. The process is killed only while it is still running: once reaped, its number
 * can belong to another process.
 *
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class SshFilesystemAbsenceProof implements FilesystemAbsenceProof
{
    /**
     * Each read stays one printed count; the positional account name and domain are `$1` and `$2`.
     *
     * `matches` keeps find's own exit status, which a pipe into a counter would lose, and `lines` treats grep's exit 1
     * as the answer zero and 2 as no answer. `owners` compares both userdomains fields exactly, as a domain's dots
     * would be wildcards to grep. A failed read prints nothing, leaving the blank the parser refuses.
     */
    public const SCRIPT = <<<'SCRIPT'
        matches() {
            found=$(find "$@") || return 1
            printf '%s' "$found" | grep -c ''
        }
        lines() {
            count=$(grep -c "$@")
            [ "$?" -le 1 ] && printf '%s' "$count"
        }
        owners() {
            found=$(awk -F ': ' -v user="$1" -v domain="$2" '$1 == domain || $2 == user' /etc/userdomains) || return 1
            printf '%s' "$found" | grep -c ''
        }
        printf 'home_inventory=%s\n' "$(matches /home -mindepth 1 -maxdepth 1)"
        printf 'home=%s\n' "$(matches /home -mindepth 1 -maxdepth 1 -name "$1")"
        printf 'users_inventory=%s\n' "$(matches /var/cpanel/users -mindepth 1 -maxdepth 1)"
        printf 'users=%s\n' "$(matches /var/cpanel/users -mindepth 1 -maxdepth 1 -name "$1")"
        printf 'userdata_inventory=%s\n' "$(matches /var/cpanel/userdata -mindepth 1 -maxdepth 1)"
        printf 'userdata=%s\n' "$(matches /var/cpanel/userdata -mindepth 1 -maxdepth 1 -name "$1")"
        printf 'userdomains_inventory=%s\n' "$(lines '' /etc/userdomains)"
        printf 'userdomains=%s\n' "$(owners "$1" "$2")"
        printf 'passwd_inventory=%s\n' "$(lines '' /etc/passwd)"
        printf 'passwd=%s\n' "$(lines "^$1:" /etc/passwd)"
        printf 'zones_inventory=%s\n' "$(matches /var/named -mindepth 1 -maxdepth 1 -name '*.db')"
        printf 'zones=%s\n' "$(matches /var/named -name "$2.db*")"
        printf 'sessions_inventory=%s\n' "$(matches /var/cpanel/sessions -mindepth 1 -maxdepth 1)"
        printf 'sessions=%s\n' "$(matches /var/cpanel/sessions -name "*$1*")"
        printf 'complete=1\n'

        SCRIPT;

    /** Reported in this order, each as its inventory then its matches. */
    private const LOCATIONS = [...self::ACCOUNT_LOCATIONS, 'sessions'];

    private readonly SshScriptRunner $runner;

    /** The second value bounds the whole check; only connecting is bounded by ConnectTimeout. */
    public function __construct(string $ssh = 'ssh', float $timeout = 30.0)
    {
        $this->runner = new SshScriptRunner($ssh, $timeout);
    }

    /** Return the proof when `WHM_API_LIVE_FS_CHECK=1` opts in, otherwise null so nothing is claimed. */
    public static function fromEnvironment(): ?self
    {
        return getenv('WHM_API_LIVE_FS_CHECK') === '1' ? new self() : null;
    }

    public function check(string $user, string $domain): array
    {
        $labels = [];
        foreach (self::LOCATIONS as $location) {
            $labels[] = $location . '_inventory';
            $labels[] = $location;
        }
        $counts = preg_match(DisposableAccount::USER_PATTERN, $user) === 1
            && preg_match(DisposableAccount::DOMAIN_PATTERN, $domain) === 1
            ? $this->runner->counts(self::SCRIPT, [$user, $domain], $labels) : null;

        return $counts === null ? self::unconfirmed() : self::finding($counts);
    }

    /**
     * Read the strict report: a location with a match is residue, and absence needs every inventory to be usable.
     *
     * @param array<string, int> $counts
     * @return Finding
     */
    private static function finding(array $counts): array
    {
        $residue = [];
        $inventories = true;
        foreach (self::LOCATIONS as $location) {
            $inventories = $inventories && $counts[$location . '_inventory'] > 0;
            if ($counts[$location] > 0) {
                $residue[$location] = $counts[$location];
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
}
