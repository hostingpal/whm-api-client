<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Whm;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;

/**
 * Read server-wide PHP configuration and PHP-FPM status through WHM.
 *
 * @phpstan-type Handler 'suphp'|'cgi'|'dso'|'none'|'lsapi'
 * @phpstan-type VersionHandlers array{
 *     available_handlers?: list<Handler>,
 *     current_handler?: Handler,
 *     version?: string
 * }
 */
final readonly class Php
{
    /**
     * Bind reviewed requests to their owning WHM client's metadata lifecycle.
     *
     * @param Closure(string, array<string, string|int>): ApiResponse $request
     * @internal Obtain this group through WhmClient::php().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * List the PHP package versions installed on the server.
     *
     * Executes read-only WHM php_get_installed_versions without parameters or a
     * cPanel target. Requires EasyApache 4 and the Web Server role. Returns the
     * required data.versions list, retaining custom names, order, duplicates and
     * empty lists. Missing or malformed lists raise InvalidResponseException.
     * The owning client's responseMeta() and exceptions retain WHM envelope
     * metadata before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:php_get_installed_versions#GET.
     *
     * @return list<string>
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/php_get_installed_versions
     */
    public function listVersions(): array
    {
        $response = ($this->request)('php_get_installed_versions', []);
        $read = self::reader($response);

        return $read->strings($read->field($response->data, 'versions'));
    }

    /**
     * Return the server's default PHP package version.
     *
     * Executes read-only WHM php_get_system_default_version without parameters
     * or a cPanel target. Requires EasyApache 4 and the Web Server role. Returns
     * the required data.version string unchanged, including custom package names
     * and inherit. Missing or malformed values raise InvalidResponseException.
     * The owning client's responseMeta() and exceptions retain WHM envelope
     * metadata before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:php_get_system_default_version#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/php_get_system_default_version
     */
    public function systemDefaultVersion(): string
    {
        $response = ($this->request)('php_get_system_default_version', []);
        $read = self::reader($response);

        return $read->string($read->field($response->data, 'version'));
    }

    /**
     * Return the server's available and selected handlers for PHP versions.
     *
     * Executes read-only WHM php_get_handlers without a cPanel target. A null
     * version omits the filter; other strings, including inherit and custom PHP
     * package names, are forwarded unchanged. Requires the Web Server role.
     * Returns the required data.version_handlers list with each optional record
     * field preserved when present; empty lists and omitted fields remain distinct.
     * Present handlers must match the pinned suphp, cgi, dso or none values,
     * or the lsapi value observed in a successful live response.
     * Missing lists or malformed fields raise InvalidResponseException.
     * The owning client's responseMeta() and exceptions retain WHM envelope
     * metadata before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:php_get_handlers#GET.
     *
     * @return list<VersionHandlers>
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/php_get_handlers
     */
    public function handlers(?string $version = null): array
    {
        $response = ($this->request)('php_get_handlers', $version === null ? [] : ['version' => $version]);
        $read = self::reader($response);

        return $read->listOf(
            $read->field($response->data, 'version_handlers'),
            static fn (mixed $record): array => self::versionHandlers($record, $read),
        );
    }

    /**
     * Report whether new server accounts and domains default to PHP-FPM.
     *
     * Executes read-only WHM php_get_default_accounts_to_fpm without parameters
     * or a cPanel target. Requires the Web Server role. Maps the required integer
     * data.default_accounts_to_fpm value 1 to true and 0 to false; omission and
     * all other values raise InvalidResponseException. This operation reads the
     * server setting and does not enable PHP-FPM or change any account.
     * The owning client's responseMeta() and exceptions retain WHM envelope
     * metadata before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:php_get_default_accounts_to_fpm#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/php_get_default_accounts_to_fpm
     */
    public function defaultAccountsToFpm(): bool
    {
        $response = ($this->request)('php_get_default_accounts_to_fpm', []);
        $read = self::reader($response);

        return $read->flag($read->field($response->data, 'default_accounts_to_fpm')) === 1;
    }

    /**
     * Return the status of the server's preconfigured PHP-FPM configuration.
     *
     * Executes read-only WHM php_get_old_fpm_flag without parameters or a cPanel
     * target. Requires the Web Server role. Returns the required data.old_fpm_flag
     * integer: 0 means no preconfiguration, 1 means preconfiguration exists, and
     * 2 means its dialogue was dismissed. Omission and other values raise
     * InvalidResponseException; the three states are not collapsed to a boolean.
     * The owning client's responseMeta() and exceptions retain WHM envelope
     * metadata before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:php_get_old_fpm_flag#GET.
     *
     * @return 0|1|2
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/php_get_old_fpm_flag
     */
    public function oldFpmFlag(): int
    {
        $response = ($this->request)('php_get_old_fpm_flag', []);
        $read = self::reader($response);
        $flag = $read->field($response->data, 'old_fpm_flag');

        return in_array($flag, [0, 1, 2], true) ? $flag : $read->fail();
    }

    /**
     * Report whether conversion of all WHM accounts to PHP-FPM is in progress.
     *
     * Executes read-only WHM is_conversion_in_progress without parameters or a
     * cPanel target. Requires the Web Server role. Maps required integer
     * data.inProgress value 1 to true and 0 to false. Omission and other values
     * raise InvalidResponseException. Reading this status never starts conversion.
     * The owning client's responseMeta() and exceptions retain WHM envelope
     * metadata before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:is_conversion_in_progress#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/is_conversion_in_progress
     */
    public function isConversionInProgress(): bool
    {
        $response = ($this->request)('is_conversion_in_progress', []);
        $read = self::reader($response);

        return $read->flag($read->field($response->data, 'inProgress')) === 1;
    }

    /**
     * List the server's virtual hosts using a specified PHP package version.
     *
     * Executes read-only WHM php_get_vhosts_by_version without a cPanel target.
     * The required version is forwarded unchanged, including inherit and custom
     * package names. Requires EasyApache 4 and the Web Server role. Returns the
     * required data.vhosts string list, retaining ordering and empty lists;
     * missing or malformed lists raise InvalidResponseException.
     * The owning client's responseMeta() and exceptions retain WHM envelope
     * metadata before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:php_get_vhosts_by_version#GET.
     *
     * @return list<string>
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/php_get_vhosts_by_version
     */
    public function vhostsByVersion(string $version): array
    {
        $response = ($this->request)('php_get_vhosts_by_version', ['version' => $version]);
        $read = self::reader($response);

        return $read->strings($read->field($response->data, 'vhosts'));
    }

    /** Bind payload validation failures to this response's snapshot. */
    private static function reader(ApiResponse $response): PayloadReader
    {
        return new PayloadReader($response->metadata, 'PHP payload');
    }

    /**
     * Copy one handler record, preserving optional field presence.
     *
     * @return VersionHandlers
     */
    private static function versionHandlers(mixed $record, PayloadReader $read): array
    {
        $record = $read->object($record);
        $item = [];
        if (property_exists($record, 'available_handlers')) {
            $item['available_handlers'] = $read->listOf(
                $record->available_handlers,
                static fn (mixed $handler): string => self::handler($handler, $read),
            );
        }
        if (property_exists($record, 'current_handler')) {
            $item['current_handler'] = self::handler($record->current_handler, $read);
        }
        if (property_exists($record, 'version')) {
            $item['version'] = $read->string($record->version);
        }

        return $item;
    }

    /**
     * Validate pinned handlers plus the observed lsapi compatibility value.
     *
     * @return Handler
     */
    private static function handler(mixed $handler, PayloadReader $read): string
    {
        return in_array($handler, ['suphp', 'cgi', 'dso', 'none', 'lsapi'], true) ? $handler : $read->fail();
    }
}
