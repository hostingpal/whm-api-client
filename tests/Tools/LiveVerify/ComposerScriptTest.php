<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\TestCase;

final class ComposerScriptTest extends TestCase
{
    /**
     * Composer stops a script after 300 seconds by default. A live run can take longer, and a stop between creating a
     * disposable account and removing it would leave the account behind, so the script lifts that limit first.
     */
    public function testLiveVerifyLiftsComposersProcessTimeoutBeforeRunning(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $scripts = is_array($composer) ? ($composer['scripts'] ?? null) : null;

        self::assertSame(
            ['Composer\\Config::disableProcessTimeout', 'php tools/live-verify/verify.php'],
            is_array($scripts) ? ($scripts['live:verify'] ?? null) : null,
        );
    }
}
