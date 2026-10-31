<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use HostingPal\WhmApiClient\Api\Uapi\CallerJson;
use HostingPal\WhmApiClient\Api\Uapi\StatsManager;

/** The public generated method is called here, so this file controls scalar coercion. */
final class StrictJsonCaller
{
    public static function call(CallerJson|StatsManager $group, string $method, mixed $body): mixed
    {
        return $group->{$method}($body);
    }
}
