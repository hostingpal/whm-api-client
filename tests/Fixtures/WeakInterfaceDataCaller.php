<?php

// phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes -- Exercise real weak PHP scalar coercion.
declare(strict_types=0);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use HostingPal\WhmApiClient\Api\Whm\Customizations;

/** Exercise PHP's weak scalar coercion at the generated facade boundary. */
final class WeakInterfaceDataCaller
{
    public static function deleteWithZero(Customizations $group): void
    {
        // @phpstan-ignore argument.type (Deliberate weak scalar coercion.)
        $group->deleteCustomizations('cpanel', 'jupiter', 0);
    }
}
