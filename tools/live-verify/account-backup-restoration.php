<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\LiveVerify\AccountBackupProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

exit(ReadOnlySectionCommand::run(
    array_slice($argv, 1),
    $root,
    'account-backup-restoration-v1',
    static fn (): array => AccountBackupProbes::load(),
));
