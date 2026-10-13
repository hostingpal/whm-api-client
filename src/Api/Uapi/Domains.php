<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Uapi;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\AccountDomains;
use HostingPal\WhmApiClient\Response\ApiResponse;
use stdClass;

/** Expose the same account-domain contract through direct and bridged UAPI. */
final readonly class Domains
{
    /**
     * Bind domain operations to one selected UAPI account and its metadata state.
     *
     * The synchronous callback retains the DomainInfo module and selected account.
     * @param Closure(string, array<string, string|int>): ApiResponse $request
     * @internal Obtain this group through UapiClient::domains().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * List the selected cPanel account's domains.
     *
     * Executes read-only `DomainInfo/list_domains` immediately for this group's
     * immutable account selection. Null omits `hide_temporary_domains`, leaving
     * its documented default (0); false sends 0 and true sends 1. True hides
     * temporary domains from the main domain and addon, parked, and subdomain lists.
     * The DTO retains optional field presence, a nullable main domain, domain lists,
     * and integer 0/1 temporary flags. Both route types return the same DTO.
     *
     * Upstream userdata-file permission errors can produce blank values without
     * an API error; success is not proof of a complete domain inventory. Envelope
     * status, messages, warnings and errors remain in responseMeta(); bridged calls
     * retain outer WHM and inner UAPI metadata separately, also on exceptions.
     * Pinned API-map identity: `uapi:DomainInfo/list_domains#GET`.
     *
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/list_domains
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function list(?bool $hideTemporaryDomains = null): AccountDomains
    {
        $query = $hideTemporaryDomains === null ? [] : ['hide_temporary_domains' => $hideTemporaryDomains ? 1 : 0];
        $response = ($this->request)('list_domains', $query);

        return AccountDomains::fromPayload($response->data, $response->metadata);
    }

    /**
     * Return the selected cPanel account's primary domain.
     *
     * Executes read-only `DomainInfo/primary_domain` immediately through direct
     * cPanel or WHM for this group's account. Null omits hide_temporary_domains;
     * false sends 0 and true sends 1. True returns null when the primary domain
     * is temporary. The payload is array{primary_domain?: string|null}; this
     * convenience method requires the property and preserves an explicit null.
     * Missing or malformed data raises InvalidResponseException with metadata.
     * Both route outcomes are checked, and responseMeta() retains the envelope
     * snapshot before API or payload failures. Requests perform no retries.
     * Pinned API-map identity: `uapi:DomainInfo/primary_domain#GET`.
     *
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/primary_domain
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function primary(?bool $hideTemporaryDomains = null): ?string
    {
        $query = $hideTemporaryDomains === null ? [] : ['hide_temporary_domains' => $hideTemporaryDomains ? 1 : 0];
        $response = ($this->request)('primary_domain', $query);
        if (
            !$response->data instanceof stdClass
            || !property_exists($response->data, 'primary_domain')
            || ($response->data->primary_domain !== null && !is_string($response->data->primary_domain))
        ) {
            throw new InvalidResponseException('Missing or invalid primary domain payload.', $response->metadata);
        }

        return $response->data->primary_domain;
    }

    /**
     * List the built-in alias labels for the account's primary domain.
     *
     * Executes read-only `DomainInfo/main_domain_builtin_subdomain_aliases` for
     * this group's selected account through direct cPanel or WHM. Labels such
     * as mail and www are returned unchanged, including ordering and duplicates.
     * Null omits hide_temporary_domains; false sends 0 and true sends 1. True
     * returns an empty list when the main domain is temporary. Userdata-file
     * differences can limit upstream results. Missing, null, or malformed data
     * raises InvalidResponseException; an empty list remains valid. Envelope
     * snapshots remain in responseMeta() and on exceptions, with both bridge
     * outcomes checked. Requests perform no retries. Pinned API-map identity:
     * `uapi:DomainInfo/main_domain_builtin_subdomain_aliases#GET`.
     *
     * @return list<string>
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/main_domain_builtin_subdomain_aliases
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function builtinAliases(?bool $hideTemporaryDomains = null): array
    {
        $query = $hideTemporaryDomains === null ? [] : ['hide_temporary_domains' => $hideTemporaryDomains ? 1 : 0];
        $response = ($this->request)('main_domain_builtin_subdomain_aliases', $query);
        if (!is_array($response->data) || !array_is_list($response->data)) {
            throw new InvalidResponseException('Missing or invalid built-in aliases payload.', $response->metadata);
        }

        $aliases = [];
        foreach ($response->data as $alias) {
            if (!is_string($alias)) {
                throw new InvalidResponseException('Invalid built-in aliases payload.', $response->metadata);
            }
            $aliases[] = $alias;
        }

        return $aliases;
    }
}
