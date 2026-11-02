<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * Observe, then remove, the session files a removed disposable account left under `/var/cpanel/sessions`.
 *
 * This is a root filesystem write over SSH, the one bounded write any recipe makes: account removal does not remove a
 * mailbox's Webmail session files, so the recipe that creates them removes them itself, after the account is gone.
 * Both steps report fixed labels and counts only, never a name, path or content.
 *
 * Every entry is classified once, by the first class it matches: a name beginning with the exact account name and
 * `:` (own), with the recipe's own mailbox at the account's own domain followed by `:`, with `@` encoded as `%40`
 * (mailbox_encoded) or literal (mailbox_literal), any other name containing the account name (unexplained), or
 * anything else (unrelated, counted only in `inventory`). The three anchored classes count regular files; any other
 * anchored entry is `anchored_other`. `wide` counts what the filesystem proof counts, any name containing the account
 * name. Removal deletes only anchored regular files, never a directory, symbolic link or unexplained name, and reports
 * the same classification before and after with the number deleted. A null result is an unreadable answer: it never
 * reads as zero.
 *
 * @phpstan-type Observation array{
 *     inventory: int, wide: int, own: int, mailbox_encoded: int, mailbox_literal: int, anchored_other: int,
 *     unexplained: int, token_suffix: int, directories: int
 * }
 * @phpstan-type Removal array{before: Observation, deleted: int, after: Observation}
 */
interface SessionFileCleanup
{
    /**
     * Count session entries by fixed label, read-only.
     *
     * @return Observation|null
     */
    public function observe(string $user, string $domain, string $mailbox): ?array;

    /**
     * Delete the anchored regular files once, classifying every entry before and after.
     *
     * @return Removal|null
     */
    public function remove(string $user, string $domain, string $mailbox): ?array;
}
