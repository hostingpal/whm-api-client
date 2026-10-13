<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Uapi;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use InvalidArgumentException;
use stdClass;

/** Expose reviewed PHP discovery through direct and bridged UAPI. */
final readonly class Php
{
    /**
     * Bind PHP discovery to one selected UAPI account and its metadata state.
     *
     * The synchronous callback retains the LangPHP module and selected account.
     * @param Closure(string, array<string, string|int|list<string>>): ApiResponse $request
     * @internal Obtain this group through UapiClient::php().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * List the system's available PHP package names for the selected cPanel account.
     *
     * Executes read-only `LangPHP/php_get_installed_versions` immediately, without
     * parameters, using this group's immutable account through direct cPanel or WHM.
     * The operation applies to EasyApache 4 systems and is disabled when the
     * WebServer role is disabled. Returns `data.versions` unchanged, preserving
     * ordering, custom PHP package names, duplicate entries, and an empty list.
     * An omitted or malformed data object or versions list cannot satisfy this
     * concrete return contract and raises InvalidResponseException.
     *
     * Envelope status, errors, messages and warnings remain in responseMeta(),
     * including before API or payload-validation errors. Bridged calls check both
     * outcomes and retain outer WHM and inner UAPI metadata separately; exceptions
     * carry the same snapshot. Pinned API-map identity:
     * `uapi:LangPHP/php_get_installed_versions#GET`.
     *
     * @return list<string>
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/php_get_installed_versions
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function listVersions(): array
    {
        $response = ($this->request)('php_get_installed_versions', []);
        if (!$response->data instanceof stdClass || !is_array($response->data->versions ?? null)) {
            throw new InvalidResponseException('Missing or invalid PHP versions payload.', $response->metadata);
        }

        $versions = $response->data->versions;
        if (!array_is_list($versions)) {
            throw new InvalidResponseException('Invalid PHP versions payload.', $response->metadata);
        }

        $result = [];
        foreach ($versions as $version) {
            if (!is_string($version)) {
                throw new InvalidResponseException('Invalid PHP versions payload.', $response->metadata);
            }

            $result[] = $version;
        }

        return $result;
    }

    /**
     * Return the system's default PHP package name for this cPanel account.
     *
     * Executes read-only `LangPHP/php_get_system_default_version` without
     * parameters through direct cPanel or WHM for this group's selected account.
     * Disabling the WebServer role disables this operation. The source payload
     * is array{version?: string|null}; this convenience method requires version
     * and preserves null or any package name without normalization. When no
     * default is set, upstream returns the newest installed PHP version. If no
     * PHP version is installed, version is null and errors explains the failure;
     * the errors field remains available in responseMeta(). Missing or
     * malformed data raises InvalidResponseException with the response snapshot.
     * responseMeta() retains envelope metadata before API and payload failures;
     * bridged calls check both outcomes. Requests perform no retries.
     * Pinned API-map identity: `uapi:LangPHP/php_get_system_default_version#GET`.
     *
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/php_get_system_default_version
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function systemDefaultVersion(): ?string
    {
        $response = ($this->request)('php_get_system_default_version', []);
        if (
            !$response->data instanceof stdClass
            || !property_exists($response->data, 'version')
            || ($response->data->version !== null && !is_string($response->data->version))
        ) {
            throw new InvalidResponseException('Missing or invalid default PHP version payload.', $response->metadata);
        }

        return $response->data->version;
    }

    /**
     * Return the PHP handler for the account's home directory or a virtual host.
     *
     * Executes read-only `LangPHP/php_get_domain_handler` through direct cPanel
     * or WHM for this group's selected account. The documented type is home or
     * vhost; home returns the system default handler and vhost requires a vhost
     * name. Null omits vhost. Parameter combinations are validated by the API.
     * Requires EasyApache 4 and the WebServer role. The source payload is
     * array{php_handler?: string}; this convenience method requires its string
     * value and raises InvalidResponseException for missing or malformed data.
     * Envelope snapshots remain in responseMeta() and on exceptions, including
     * both bridge outcomes. Requests perform no retries. Pinned API-map identity:
     * `uapi:LangPHP/php_get_domain_handler#GET`.
     *
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/php_get_domain_handler
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function domainHandler(string $type, ?string $vhost = null): string
    {
        $query = ['type' => $type];
        if ($vhost !== null) {
            $query['vhost'] = $vhost;
        }
        $response = ($this->request)('php_get_domain_handler', $query);
        if (!$response->data instanceof stdClass || !is_string($response->data->php_handler ?? null)) {
            throw new InvalidResponseException('Missing or invalid PHP handler payload.', $response->metadata);
        }

        return $response->data->php_handler;
    }

    /**
     * List account domains inheriting the selected PHP configuration.
     *
     * Executes read-only `LangPHP/php_get_impacted_domains` for this group's
     * selected account through direct cPanel or WHM. A string selects one
     * domain's configuration; a list sends repeated domain parameters in the
     * supplied order. Parked domains are not valid inputs or returned entries.
     * Null omits system_default, false sends 0, and true sends 1 to include
     * domains inheriting the system default. Provide a domain or true for
     * systemDefault; the API validates these conditions. Empty or invalid domain
     * lists clear previous metadata and are rejected before I/O. Requires EasyApache 4
     * and the WebServer role. The payload is array{domains?: list<string>}; this
     * convenience method requires domains, preserves duplicates and empty lists,
     * and rejects missing or malformed data with InvalidResponseException.
     * responseMeta() and exceptions retain envelope snapshots; both bridge
     * outcomes are checked. Requests perform no retries. Pinned API-map identity:
     * `uapi:LangPHP/php_get_impacted_domains#GET`.
     *
     * @param string|list<string>|null $domain One domain, a nonempty domain list, or no domain selection.
     * @return list<string>
     * @throws InvalidArgumentException If domain is an empty array or not a list of strings.
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/php_get_impacted_domains
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function impactedDomains(string|array|null $domain = null, ?bool $systemDefault = null): array
    {
        $query = [];
        if ($domain !== null) {
            $query['domain'] = $domain;
        }
        if ($systemDefault !== null) {
            $query['system_default'] = $systemDefault ? 1 : 0;
        }
        $response = ($this->request)('php_get_impacted_domains', $query);
        if (
            !$response->data instanceof stdClass
            || !is_array($response->data->domains ?? null)
            || !array_is_list($response->data->domains)
        ) {
            throw new InvalidResponseException('Missing or invalid impacted domains payload.', $response->metadata);
        }

        $domains = [];
        foreach ($response->data->domains as $domainName) {
            if (!is_string($domainName)) {
                throw new InvalidResponseException('Invalid impacted domains payload.', $response->metadata);
            }
            $domains[] = $domainName;
        }

        return $domains;
    }
}
