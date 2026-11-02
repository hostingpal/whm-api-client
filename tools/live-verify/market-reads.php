<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\LiveVerify\MarketReadsProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

exit(ReadOnlySectionCommand::run(
    array_slice($argv, 1),
    $root,
    'market-reads-v1',
    static fn (): array => MarketReadsProbes::load(),
));
