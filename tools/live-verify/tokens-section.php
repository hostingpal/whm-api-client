<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

require __DIR__ . '/src/SectionCommand.php';

// Committed-code API token lifecycle on the designated npr account; each route owns one throwaway token.
exit(SectionCommand::run(
    array_slice($argv, 1),
    dirname(__DIR__, 2),
    'tokens-section.php',
    'Tokens',
    'tokens',
    static fn (#[\SensitiveParameter] array $tokens, string $suffix, array $context): array =>
        (new TokensSectionFixture(new CurlClient(allowFormPost: true), $tokens, $suffix, time() + 3600, $context))
            ->run(),
));
