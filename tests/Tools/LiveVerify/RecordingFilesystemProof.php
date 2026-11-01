<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use RuntimeException;

/**
 * Offline stand-in for the SSH proof: returns one configured finding, or throws, and records every call.
 *
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class RecordingFilesystemProof implements FilesystemAbsenceProof
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    /**
     * @param Finding|null $finding Null throws, as an implementation that fails outright would.
     * @param (Closure(): void)|null $observe Runs at the moment of the check, to record what state it saw.
     */
    public function __construct(private readonly ?array $finding, private readonly ?Closure $observe = null)
    {
    }

    public function check(string $user, string $domain): array
    {
        $this->calls[] = [$user, $domain];
        if ($this->observe !== null) {
            ($this->observe)();
        }

        return $this->finding ?? throw new RuntimeException('private filesystem failure');
    }
}
