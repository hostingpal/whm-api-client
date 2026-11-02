<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\SessionFileCleanup;
use RuntimeException;

/**
 * Offline stand-in for the SSH session-file cleanup: returns configured counts and records every call in order.
 *
 * @phpstan-import-type Observation from SessionFileCleanup
 * @phpstan-import-type Removal from SessionFileCleanup
 */
final class RecordingSessionFileCleanup implements SessionFileCleanup
{
    /** What a removed account's own files look like: two self and two encoded mailbox files in two folders. */
    public const OBSERVED = [
        'inventory' => 40, 'wide' => 4, 'own' => 2, 'mailbox_encoded' => 2, 'mailbox_literal' => 0,
        'anchored' => 4, 'unexplained' => 0, 'token_suffix' => 4, 'directories' => 2,
    ];

    public const REMOVED = ['before' => 4, 'deleted' => 4, 'after' => 0, 'wide_after' => 0];

    /** @var list<array{string, string, string, string}> Step, user, domain and mailbox of every call. */
    public array $calls = [];

    /**
     * @param Observation|null $observation Null answers unreadable.
     * @param Removal|null $removal Null answers unreadable.
     * @param (Closure(string): void)|null $observe Runs at the moment of each call, to record what state it saw.
     */
    public function __construct(
        private readonly ?array $observation = self::OBSERVED,
        private readonly ?array $removal = self::REMOVED,
        private readonly ?Closure $observe = null,
        private readonly bool $throw = false,
    ) {
    }

    public function observe(string $user, string $domain, string $mailbox): ?array
    {
        $this->record('observe', $user, $domain, $mailbox);

        return $this->observation;
    }

    public function remove(string $user, string $domain, string $mailbox): ?array
    {
        $this->record('remove', $user, $domain, $mailbox);

        return $this->removal;
    }

    private function record(string $step, string $user, string $domain, string $mailbox): void
    {
        $this->calls[] = [$step, $user, $domain, $mailbox];
        if ($this->observe !== null) {
            ($this->observe)($step);
        }
        if ($this->throw) {
            throw new RuntimeException('private session cleanup failure');
        }
    }
}
