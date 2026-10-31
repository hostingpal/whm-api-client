<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\LiveVerify\GpgTwoFactorProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

exit(ReadOnlySectionCommand::run(
    array_slice($argv, 1),
    $root,
    'gpg-two-factor-v1',
    static fn (): array => GpgTwoFactorProbes::load(),
));
