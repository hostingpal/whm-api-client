<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanReviewProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionCommand;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$exit = ReadOnlySectionCommand::run(
    $arguments,
    $root,
    'fileman-zero-byte-review-v1',
    static fn (): array => FilemanReviewProbes::load(getenv('WHM_API_LIVE_ZERO_FILE') ?: ''),
);
if ($arguments === ['--help']) {
    fwrite(STDOUT, "Also requires WHM_API_LIVE_ZERO_FILE=mail/<basename> selected by prior metadata inspection.\n");
}
exit($exit);
