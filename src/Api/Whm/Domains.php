<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Whm;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ServerDomains;

/** Expose reviewed server-wide WHM domain operations. */
final readonly class Domains
{
    /**
     * Bind the domain inventory request to its owning WHM metadata lifecycle.
     *
     * The synchronous callback executes the named operation with its parameters.
     * @param Closure(string, array<string, string|int>): ApiResponse $request
     * @internal Obtain this group through WhmClient::domains().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * Return the server's manually created domain inventory.
     *
     * Immediately executes the read-only WHM get_domain_info operation for the
     * configured server, without a cPanel target account or request parameters.
     * The returned collection contains typed domain records, excluding service
     * subdomains and automatically created domains. Optional fields retain their
     * presence, nullable IPv6, blank strings, and integer flags. Port properties
     * normalize observed decimal strings to integers; exports preserve wire types.
     * WHM envelope metadata remains on the owning client's responseMeta(),
     * including before API or payload-validation errors. No retries are issued.
     * Pinned API-map identity: whm1:get_domain_info#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_domain_info
     */
    public function list(): ServerDomains
    {
        $response = ($this->request)('get_domain_info', []);

        return ServerDomains::fromPayload($response->data, $response->metadata);
    }
}
