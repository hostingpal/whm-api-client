<?php

// phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes -- Exercise real weak PHP callers deliberately.
declare(strict_types=0);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use HostingPal\WhmApiClient\Api\Whm\Mail;

/** Deliberately weak caller verifies native scalar coercion before target policy. */
final class WeakMailCaller
{
    /** @param array<array-key, mixed> $arguments */
    public static function call(Mail $mail, string $method, array $arguments): mixed
    {
        return $mail->{$method}(...$arguments);
    }
}
