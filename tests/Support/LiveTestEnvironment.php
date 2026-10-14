<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use InvalidArgumentException;
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

    /**
     * Require the separate mutating opt-in and the approved throwaway account.
     *
     * @return array{account: non-empty-string, domain: non-empty-string}
     */
    public static function mutationsFromEnvironment(): array
    {
        return self::requireMutationsReady(
            getenv('WHM_API_LIVE_MUTATIONS'),
            getenv('WHM_API_LIVE_ACCOUNT'),
            getenv('WHM_API_LIVE_DOMAIN'),
        );
    }

    /**
     * Validate the mutation opt-in without network calls.
     *
     * The account must be a valid cPanel username and must not be the protected
     * test account; the domain must be a bare hostname.
     *
     * @return array{account: non-empty-string, domain: non-empty-string}
     */
    public static function requireMutationsReady(
        string|false $optIn,
        string|false $account,
        string|false $domain,
    ): array {
        if ($optIn !== '1') {
            throw new RuntimeException('Mutating live tests require explicit WHM_API_LIVE_MUTATIONS=1 opt-in.');
        }

        if ($account === false || $account === '' || $account === 'npr' || $account === 'root') {
            throw new RuntimeException('Mutating live tests require WHM_API_LIVE_ACCOUNT naming a throwaway account.');
        }

        try {
            TokenCredentials::validateUsername($account);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('WHM_API_LIVE_ACCOUNT must be a valid cPanel username.');
        }

        $label = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';
        if ($domain === false || preg_match('/\A' . $label . '(?:\.' . $label . ')+\z/i', $domain) !== 1) {
            throw new RuntimeException('Mutating live tests require WHM_API_LIVE_DOMAIN naming a bare domain.');
        }

        return ['account' => $account, 'domain' => $domain];
    }
}
