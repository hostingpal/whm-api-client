<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use SensitiveParameter;

/**
 * Admit each of one binding's control mutations at most once, on behalf of a recipe's guard.
 *
 * Every guard built on DisposableAccountLifecycle hands each POST it sees to admit(), and refuses the request unless
 * the answer is true. Only an exact URI and body from DisposableAccount::controlPosts() is admitted, and each URI at
 * most once: a later request for it is refused even when the guard went on to reject the first one, so nothing below
 * the guard can retry a mutation. A request that does not match exactly is refused and consumes nothing.
 *
 * The expected bodies are spelled afresh on every call and never kept, so this object never holds the ownership marker.
 */
final class ControlPostAdmission
{
    /** @var array<string, true> */
    private array $consumed = [];

    /** Admit the control mutations of this one binding; performs no I/O. */
    public function __construct(private readonly DisposableAccount $account)
    {
    }

    /** True the first time an exact control mutation arrives; false for any other request and for every repeat. */
    public function admit(string $uri, #[SensitiveParameter] string $body): bool
    {
        if (isset($this->consumed[$uri]) || ($this->account->controlPosts()[$uri] ?? null) !== $body) {
            return false;
        }
        $this->consumed[$uri] = true;

        return true;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Admission is one guard's state for one run and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Control post admission cannot be serialized.');
    }
}
