<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmSystemPackagesProbes;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

exit(ReadOnlySectionCommand::run(
    array_slice($argv, 1),
    $root,
    'whm-system-packages-v1',
    static fn (): array => WhmSystemPackagesProbes::load(),
));
