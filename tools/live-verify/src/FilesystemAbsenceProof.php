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
 * @phpstan-type Finding array{
 *     outcome: 'confirmed_absent'|'present'|'unconfirmed', residue: array<string, int>
 * }
 */
interface FilesystemAbsenceProof
{
    /**
     * Check one account and domain once; `residue` holds a count by fixed location label, for found locations only.
     *
     * @return Finding
     */
    public function check(string $user, string $domain): array;
}
