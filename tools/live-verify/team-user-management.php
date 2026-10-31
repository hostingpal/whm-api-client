<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand;
use HostingPal\WhmApiClient\Tools\LiveVerify\TeamUserManagementProbes;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

exit(ReadOnlySectionCommand::run(
    array_slice($argv, 1),
    $root,
    'team-user-management-v1',
    static fn (): array => TeamUserManagementProbes::load(),
));
