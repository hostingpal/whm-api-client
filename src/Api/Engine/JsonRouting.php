<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Protect caller JSON inputs before library routing fields are added.
 *
 * The entire case-sensitive api. prefix is reserved; route-specific exact names
 * are additional reservations. Legacy form and OrderedFields policies stay separate.
 *
 * @internal
 */
final class JsonRouting
{
    /** @param list<string> $reserved Additional exact names reserved by the route. */
    public static function assertAllowed(#[SensitiveParameter] string $name, array $reserved): void
    {
        if (str_starts_with($name, 'api.') || in_array($name, $reserved, true)) {
            throw new InvalidArgumentException('The JSON input overlaps a reserved routing field.');
        }
    }
}
