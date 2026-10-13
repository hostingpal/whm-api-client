<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use RuntimeException;
use SensitiveParameter;

/** Fail explicitly, before I/O, unless the entire bounded live suite is configured. */
final class LiveTestEnvironment
{
    /** @return array{whm: non-empty-string, cpanel: non-empty-string} */
    public static function fromEnvironment(): array
    {
        return self::requireReady(
            getenv('WHM_API_LIVE'),
            getenv('OBLAK_TEST_WHM_KEY'),
            getenv('OBLAK_TEST_NPR_KEY'),
            extension_loaded('curl'),
        );
    }

    /**
     * Validate supplied values without making network calls or exposing token values.
     *
     * @return array{whm: non-empty-string, cpanel: non-empty-string}
     */
    public static function requireReady(
        string|false $optIn,
        #[SensitiveParameter] string|false $whmToken,
        #[SensitiveParameter] string|false $cpanelToken,
        bool $curlAvailable,
    ): array {
        if ($optIn !== '1') {
            throw new RuntimeException('Live tests require explicit WHM_API_LIVE=1 opt-in.');
        }

        if ($whmToken === false || $whmToken === '') {
            throw new RuntimeException('Live tests require OBLAK_TEST_WHM_KEY.');
        }

        if ($cpanelToken === false || $cpanelToken === '') {
            throw new RuntimeException('Live tests require OBLAK_TEST_NPR_KEY.');
        }

        if (!$curlAvailable) {
            throw new RuntimeException('Live tests require the PHP curl extension.');
        }

        return ['whm' => $whmToken, 'cpanel' => $cpanelToken];
    }
}
