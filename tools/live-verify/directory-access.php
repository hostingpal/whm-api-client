<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\LiveVerify\DirectoryAccessProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

exit(ReadOnlySectionCommand::run(
    array_slice($argv, 1),
    $root,
    'directory-access-v1',
    static fn (): array => DirectoryAccessProbes::load(),
));
