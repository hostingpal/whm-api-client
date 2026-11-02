<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Observe and remove one removed account's anchored session files over the `oblak.test` SSH alias.
 *
 * Each step is one fixed script sent on standard input; it holds no name. The account name, its domain and the
 * recipe's mailbox are positional arguments, checked here against narrow patterns and again by the script itself, so
 * no unvalidated text reaches a shell or a find pattern. Each script prints `label=count` lines and nothing else.
 *
 * One classifier walks `/var/cpanel/sessions` once. Every entry takes the first class it matches, in this order: a name
 * beginning with `<account>:` (own), with `<mailbox>%40<domain>:` (mailbox_encoded), with `<mailbox>@<domain>:`
 * (mailbox_literal), any other name containing the account name (unexplained), or anything else (unrelated). One
 * file-type rule then splits the three anchored classes: regular files are counted by class, and every other anchored
 * entry is `anchored_other`, so nothing anchored is lost between counts. Names are never printed, not even inside the
 * classifier's own records, so no name can split or forge a record; find's exit status travels after its records and
 * a failed or unreadable walk prints no count at all.
 *
 * The observation is that one walk. The removal walks once to count, stops if it cannot, deletes the anchored regular
 * files through one find action (find never follows a link), and walks once more to count what remains.
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
    /** Read-only: one walk of the sessions tree. */
    public const OBSERVE = self::PRELUDE . self::CLASSIFY . <<<'SCRIPT'
        classify '' || exit 1
        printf 'complete=1\n'

        SCRIPT;

    /** The one write: count, delete anchored regular files by one find action, count again. */
    public const REMOVE = self::PRELUDE . self::CLASSIFY . <<<'SCRIPT'
        classify before_ || exit 1
        deleted=$({
            find /var/cpanel/sessions -type f \( -name "$own*" -o -name "$encoded*" -o -name "$literal*" \) \
                -printf 'deleted\n' -delete
            printf 'status %s\n' "$?"
        } | awk '
            $1 == "status" && NF == 2 { status = $2; last = NR; next }
            $0 == "deleted" { n++; next }
            { bad = 1 }
            END { if (bad || last != NR || status != 0) exit 1; print n + 0 }')
        printf 'deleted=%s\n' "$deleted"
        classify after_ || exit 1
        printf 'complete=1\n'

        SCRIPT;

    /** The recipe's own mailbox local part, as narrow as the account name. */
    public const MAILBOX_PATTERN = '/\A[a-z][a-z0-9]{0,63}\z/';

    /** Both scripts re-check their arguments and bind the three anchored prefixes. */
    private const PRELUDE = <<<'SCRIPT'
        export LC_ALL=C
        case "$1" in ''|*[!a-z0-9]*) exit 1 ;; esac
        case "$2" in ''|*[!a-z0-9.-]*) exit 1 ;; esac
        case "$3" in ''|*[!a-z0-9]*) exit 1 ;; esac
        user=$1 own="$1:" encoded="$3%40$2:" literal="$3@$2:" other='*[!A-Za-z0-9_]*'

        SCRIPT;

    /**
     * One walk and one classifier; prints every label once with the given prefix, or nothing and fails.
     *
     * Each record is the class, the find type letter, whether the name contains the account name, whether an anchored
     * name's suffix is nonempty and only letters, digits and underscores, and, for an anchored entry, its folder.
     */
    private const CLASSIFY = <<<'SCRIPT'
        classify() {
            {
                find /var/cpanel/sessions -mindepth 1 \
                    \( -name "$own*" -printf 'own %y 1 ' \
                        \( -name "$own$other" -printf 0 -o -name "$own?*" -printf 1 -o -printf 0 \) \
                        -printf ' %h\n' \) \
                    -o \( -name "$encoded*" -printf 'mailbox_encoded %y ' \
                        \( -name "*$user*" -printf '1 ' -o -printf '0 ' \) \
                        \( -name "$encoded$other" -printf 0 -o -name "$encoded?*" -printf 1 -o -printf 0 \) \
                        -printf ' %h\n' \) \
                    -o \( -name "$literal*" -printf 'mailbox_literal %y ' \
                        \( -name "*$user*" -printf '1 ' -o -printf '0 ' \) \
                        \( -name "$literal$other" -printf 0 -o -name "$literal?*" -printf 1 -o -printf 0 \) \
                        -printf ' %h\n' \) \
                    -o \( -name "*$user*" -printf 'unexplained %y 1 0\n' \) \
                    -o -printf 'unrelated %y 0 0\n'
                printf 'status %s\n' "$?"
            } | awk -v prefix="$1" '
                $1 == "status" && NF == 2 { status = $2; last = NR; next }
                NF < 4 || $3 !~ /^[01]$/ || $4 !~ /^[01]$/ { bad = 1; next }
                { inventory++; wide += $3 }
                $1 == "unexplained" { unexplained++; next }
                $1 == "unrelated" { next }
                $1 != "own" && $1 != "mailbox_encoded" && $1 != "mailbox_literal" { bad = 1; next }
                $2 != "f" { anchored_other++; next }
                NF < 5 { bad = 1; next }
                {
                    count[$1]++
                    token += $4
                    folder = $0
                    sub(/^[^ ]+ [^ ]+ [^ ]+ [^ ]+ /, "", folder)
                    folders[folder] = 1
                }
                END {
                    if (bad || last != NR || status != 0) exit 1
                    for (folder in folders) directories++
                    printf "%sinventory=%d\n", prefix, inventory
                    printf "%swide=%d\n", prefix, wide
                    printf "%sown=%d\n", prefix, count["own"]
                    printf "%smailbox_encoded=%d\n", prefix, count["mailbox_encoded"]
                    printf "%smailbox_literal=%d\n", prefix, count["mailbox_literal"]
                    printf "%sanchored_other=%d\n", prefix, anchored_other
                    printf "%sunexplained=%d\n", prefix, unexplained
                    printf "%stoken_suffix=%d\n", prefix, token
                    printf "%sdirectories=%d\n", prefix, directories
                }'
        }

        SCRIPT;

    private const LABELS = ['inventory', 'wide', 'own', 'mailbox_encoded', 'mailbox_literal', 'anchored_other',
        'unexplained', 'token_suffix', 'directories'];

    private readonly SshScriptRunner $runner;

    /** The second value bounds each whole step; only connecting is bounded by ConnectTimeout. */
    public function __construct(string $ssh = 'ssh', float $timeout = 60.0)
    {
        $this->runner = new SshScriptRunner($ssh, $timeout);
    }

    public function observe(string $user, string $domain, string $mailbox): ?array
    {
        $counts = $this->counts(self::OBSERVE, self::LABELS, $user, $domain, $mailbox);
        if ($counts === null || $counts['inventory'] === 0) {
            return null;
        }

        return self::observation($counts, '');
    }

    public function remove(string $user, string $domain, string $mailbox): ?array
    {
        $labels = [];
        foreach (['before_', 'after_'] as $prefix) {
            foreach (self::LABELS as $label) {
                $labels[] = $prefix . $label;
            }
            if ($prefix === 'before_') {
                $labels[] = 'deleted';
            }
        }
        $counts = $this->counts(self::REMOVE, $labels, $user, $domain, $mailbox);
        if ($counts === null) {
            return null;
        }

        return [
            'before' => self::observation($counts, 'before_'),
            'deleted' => $counts['deleted'],
            'after' => self::observation($counts, 'after_'),
        ];
    }

    /**
     * @param array<string, int> $counts
     * @return Observation
     */
    private static function observation(array $counts, string $prefix): array
    {
        return [
            'inventory' => $counts[$prefix . 'inventory'], 'wide' => $counts[$prefix . 'wide'],
            'own' => $counts[$prefix . 'own'], 'mailbox_encoded' => $counts[$prefix . 'mailbox_encoded'],
            'mailbox_literal' => $counts[$prefix . 'mailbox_literal'],
            'anchored_other' => $counts[$prefix . 'anchored_other'], 'unexplained' => $counts[$prefix . 'unexplained'],
            'token_suffix' => $counts[$prefix . 'token_suffix'], 'directories' => $counts[$prefix . 'directories'],
        ];
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
