<?php

// phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes -- Exercise real weak PHP callers deliberately.
declare(strict_types=0);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use HostingPal\WhmApiClient\Api\Whm\NginxManager;

/** Keeps the actual generated-method call in weak mode to expose scalar coercion. */
final class WeakNginxCaller
{
    /** @param array<array-key, mixed> $arguments */
    public static function call(NginxManager $group, string $method, array $arguments): mixed
    {
        return $group->{$method}(...$arguments);
    }
}
