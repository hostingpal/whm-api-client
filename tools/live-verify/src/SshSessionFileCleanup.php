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
 * The connection is SshScriptRunner's, shared with the filesystem proof: strict host-key checking with updates off, no
 * connection sharing, no repeat, credentials kept out of the child's environment and standard error discarded. A
 * connection, exit status, timeout or reply that is not exactly the expected report is null, never a count.
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

    private const OBSERVATION = ['inventory', 'wide', 'own', 'mailbox_encoded', 'mailbox_literal', 'anchored',
        'unexplained', 'token_suffix', 'directories'];

    private const REMOVAL = ['before', 'deleted', 'after', 'wide_after'];

    private readonly SshScriptRunner $runner;

    /** The second value bounds each whole step; only connecting is bounded by ConnectTimeout. */
    public function __construct(string $ssh = 'ssh', float $timeout = 60.0)
    {
        $this->runner = new SshScriptRunner($ssh, $timeout);
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
     * Run one fixed script for these exact identities, which must each match its own narrow pattern first.
     *
     * @param non-empty-list<string> $labels
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

        return $this->runner->counts($script, [$user, $domain, $mailbox], $labels);
    }
}
