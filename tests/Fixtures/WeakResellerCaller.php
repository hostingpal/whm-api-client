<?php

// phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes -- Exercise real weak PHP callers deliberately.
declare(strict_types=0);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use HostingPal\WhmApiClient\Api\Whm\Resellers;

/** Keeps the actual generated-method call in weak mode to expose scalar coercion. */
final class WeakResellerCaller
{
    /** @param array<array-key, mixed> $arguments */
    public static function call(Resellers $group, string $method, array $arguments): mixed
    {
        return $group->{$method}(...$arguments);
    }
}
