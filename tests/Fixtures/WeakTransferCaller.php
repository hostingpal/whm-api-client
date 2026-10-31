<?php

// phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes -- Exercise real weak PHP callers deliberately.
declare(strict_types=0);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use HostingPal\WhmApiClient\Api\Whm\Transfers;

/** Keeps the actual generated-method call in weak mode to expose scalar coercion. */
final class WeakTransferCaller
{
    /** @param array<array-key, mixed> $arguments */
    public static function call(Transfers $group, string $method, array $arguments): mixed
    {
        return $group->{$method}(...$arguments);
    }
}
