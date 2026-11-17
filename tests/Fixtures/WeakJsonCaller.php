<?php

// phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes -- Exercise real weak PHP callers deliberately.
declare(strict_types=0);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use HostingPal\WhmApiClient\Api\Uapi\CallerJson;
use HostingPal\WhmApiClient\Api\Uapi\StatsManager;

/** The public generated method is called here, to exercise native argument binding in this caller mode. */
final class WeakJsonCaller
{
    public static function factory(string $class, string $method, mixed $input): mixed
    {
        return $class::$method($input);
    }

    public static function call(CallerJson|StatsManager $group, string $method, mixed $body): mixed
    {
        return $group->{$method}($body);
    }
}
