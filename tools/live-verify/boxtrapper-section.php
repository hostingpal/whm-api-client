<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use SensitiveParameter;

require __DIR__ . '/src/SectionCommand.php';

exit(SectionCommand::run(
    array_slice($argv, 1),
    dirname(__DIR__, 2),
    'boxtrapper-section.php',
    'BoxTrapper',
    'boxtrapper_section',
    static fn (#[SensitiveParameter] array $tokens, string $suffix, array $context): array =>
        (new BoxTrapperSectionFixture(new CurlClient(allowFormPost: true), $tokens, $suffix, $context))->run(),
));
