<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Observe, then remove, the session files a removed disposable account left under `/var/cpanel/sessions`.
 *
 * This is the one bounded filesystem write any recipe makes. Removal of an account does not remove its session files,
 * so a recipe that creates Webmail sessions removes them itself, after the account is gone. Both steps report fixed
 * labels and counts only, never a name, path or content. Names are classified in memory against three anchored
 * prefixes: the exact account name, and the recipe's own mailbox at the account's own domain, with `@` either
 * literal or encoded as `%40`, each followed by a colon. `wide` counts what the filesystem proof counts, any name
 * containing the account name; `unexplained` is what no anchored prefix explains. Removal deletes only regular files
 * whose names begin with an anchored prefix, and never a directory, symbolic link or unexplained name. A null
 * result is an unreadable answer: it never reads as zero.
 *
 * @phpstan-type Observation array{
 *     inventory: int, wide: int, own: int, mailbox_encoded: int, mailbox_literal: int, anchored: int,
 *     unexplained: int, token_suffix: int, directories: int
 * }
 * @phpstan-type Removal array{before: int, deleted: int, after: int, wide_after: int}
 */
interface SessionFileCleanup
{
    /**
     * Count session names by fixed label, read-only.
     *
     * @return Observation|null
     */
    public function observe(string $user, string $domain, string $mailbox): ?array;

    /**
     * Delete the anchored regular files once, counting them before, as deleted, and after.
     *
     * @return Removal|null
     */
    public function remove(string $user, string $domain, string $mailbox): ?array;
}
