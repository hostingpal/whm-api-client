<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

/**
 * An optional, read-only check of what an account left on the server's filesystem after API cleanup.
 *
 * Implementations never write, delete or change anything and never retry. They report only fixed labels and counts,
 * never file contents, filenames, tokens or values. Anything short of a proven absence is not absence: a failed check
 * returns `unconfirmed`, and the lifecycle treats a thrown exception the same way.
 *
 * The lifecycle runs a check twice: once after setup, where every ACCOUNT_LOCATIONS entry must show the account, then
 * once after cleanup. Without the first, nothing ties the second to the server that was changed.
 *
 * @phpstan-type Finding array{
 *     outcome: 'confirmed_absent'|'present'|'unconfirmed', residue: array<string, int>
 * }
 */
interface FilesystemAbsenceProof
{
    /** Where an existing account always shows. Sessions are left out: nothing has shown how they are named. */
    public const ACCOUNT_LOCATIONS = ['home', 'users', 'userdata', 'userdomains', 'passwd', 'zones'];

    /**
     * Check one account and domain once; `residue` holds a count by fixed location label, for found locations only.
     *
     * @return Finding
     */
    public function check(string $user, string $domain): array;
}
