<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use RuntimeException;

/**
 * Offline stand-in for the SSH proof: returns configured findings in call order and records every call.
 *
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 */
final class RecordingFilesystemProof implements FilesystemAbsenceProof
{
    /** What a real proof reports while the account exists; sessions may or may not appear. */
    public const PRESENT = [
        'outcome' => 'present',
        'residue' => ['home' => 1, 'users' => 1, 'userdata' => 1, 'userdomains' => 1, 'passwd' => 1, 'zones' => 2],
    ];

    /** @var list<array{string, string}> */
    public array $calls = [];

    /**
     * @param list<Finding|null> $findings Returned in call order; null throws, and the last one repeats.
     * @param (Closure(): void)|null $observe Runs at the moment of each check, to record what state it saw.
     */
    public function __construct(private readonly array $findings, private readonly ?Closure $observe = null)
    {
    }

    /**
     * A proof whose control sees the account, followed by the given final finding.
     *
     * @param Finding|null $final
     * @param (Closure(): void)|null $observe
     */
    public static function after(?array $final, ?Closure $observe = null): self
    {
        return new self([self::PRESENT, $final], $observe);
    }

    public function check(string $user, string $domain): array
    {
        $index = min(count($this->calls), count($this->findings) - 1);
        $this->calls[] = [$user, $domain];
        if ($this->observe !== null) {
            ($this->observe)();
        }

        return $this->findings[$index] ?? throw new RuntimeException('private filesystem failure');
    }
}
