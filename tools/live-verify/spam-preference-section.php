<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

require __DIR__ . '/src/SectionCommand.php';

exit(SectionCommand::run(
    array_slice($argv, 1),
    dirname(__DIR__, 2),
    'spam-preference-section.php',
    'Spam preference',
    'spam_preferences',
    static fn (#[\SensitiveParameter] array $tokens, string $suffix, array $context): array =>
        (new SpamPreferenceFixture(new CurlClient(allowFormPost: true), $tokens, $context))->run(),
));
