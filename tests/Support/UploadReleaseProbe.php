<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

/** A foreign object whose release must never be invoked by unserialized sets. */
final class UploadReleaseProbe
{
    public static int $releases = 0;
    public string $marker = 'private-foreign-marker';

    public function release(): void
    {
        ++self::$releases;
    }
}
