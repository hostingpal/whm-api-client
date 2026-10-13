<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Uapi;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;

/** Expose the selected cPanel account's feature inventory through either UAPI route. */
final readonly class Features
{
    /**
     * Bind feature requests to one selected account and its metadata state.
     *
     * @param Closure(string, array<string, string|int>): ApiResponse $request
     * @internal Obtain this group through UapiClient::features().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * List enabled and disabled features for the selected cPanel account.
     *
     * Executes read-only `Features/list_features` without parameters through
     * direct cPanel or WHM. Returns the feature-name map with integer 0/1 flags,
     * preserving custom feature names and an empty map. Missing or malformed
     * data raises InvalidResponseException with the current envelope snapshot.
     * PHP converts canonical numeric feature names to integer array keys.
     * responseMeta() retains metadata before API or payload errors, with outer
     * WHM and inner UAPI outcomes checked separately. Requests perform no retries.
     * Pinned API-map identity: `uapi:Features/list_features#GET`.
     *
     * @return array<array-key, 0|1>
     * @throws ApiException If transport, HTTP, either API outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/list_features
     * @see https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel
     */
    public function list(): array
    {
        $response = ($this->request)('list_features', []);
        $read = new PayloadReader($response->metadata, 'feature map payload');

        return array_map($read->flag(...), get_object_vars($read->object($response->data)));
    }
}
