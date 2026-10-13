<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Uapi;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;

/** Expose reviewed DNS discovery through the selected cPanel account's UAPI route. */
final readonly class Dns
{
    /**
     * Bind DNS requests to one selected account and its metadata state.
     *
     * @param Closure(string, array<string, string|int>): ApiResponse $request
     * @internal Obtain this group through UapiClient::dns().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * Return textual DNS information for a fully qualified domain name.
     *
     * Executes read-only `DNS/lookup` for domain through direct cPanel or WHM
     * using this group's selected account. The returned strings are upstream
     * response lines, preserved in order without parsing into DNS records.
     * Explicit null and an empty list remain distinct. Missing or malformed
     * data raises InvalidResponseException with the current envelope snapshot.
     * responseMeta() retains metadata before API or payload errors; bridged
     * requests check both outcomes. Requests perform no retries.
     * Pinned API-map identity: `uapi:DNS/lookup#GET`.
     *
     * @return list<string>|null
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/lookup
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function lookup(string $domain): ?array
    {
        $response = ($this->request)('lookup', ['domain' => $domain]);
        $read = new PayloadReader($response->metadata, 'DNS lookup payload');
        if (!$response->hasData) {
            $read->fail();
        }

        return $read->nullable($response->data, $read->strings(...));
    }

    /**
     * List cPanel-generated domain names associated with a domain.
     *
     * Executes read-only `DNS/fetch_cpanel_generated_domains` through direct
     * cPanel or WHM using this group's selected account. Available in cPanel 120,
     * it reports system-generated names such as cpanel, webmail and mail, and
     * can include the supplied domain itself. Records retain optional domain
     * fields and fully qualified names with trailing dots; empty lists remain
     * empty. Missing or malformed data raises InvalidResponseException with
     * metadata. responseMeta() retains the envelope before API or payload errors,
     * with both bridge outcomes checked. Requests perform no retries.
     * Pinned API-map identity: `uapi:DNS/fetch_cpanel_generated_domains#GET`.
     *
     * @return list<array{domain?: string}>
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/fetch_cpanel_generated_domains
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function generatedDomains(string $domain): array
    {
        $response = ($this->request)('fetch_cpanel_generated_domains', ['domain' => $domain]);
        $read = new PayloadReader($response->metadata, 'generated domains payload');

        return $read->listOf(
            $response->data,
            static fn (mixed $record): array => self::generatedDomain($record, $read),
        );
    }

    /**
     * Copy one generated-domain record, preserving an omitted domain.
     *
     * @return array{domain?: string}
     */
    private static function generatedDomain(mixed $record, PayloadReader $read): array
    {
        $record = $read->object($record);

        return property_exists($record, 'domain') ? ['domain' => $read->string($record->domain)] : [];
    }
}
