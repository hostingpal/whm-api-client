<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Whm;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ServerDiskUsage;
use HostingPal\WhmApiClient\Response\ServerLoadAverage;
use stdClass;

/** Expose reviewed server-level WHM operations. */
final readonly class Server
{
    /**
     * Bind reviewed server requests to their owning WHM client's metadata lifecycle.
     *
     * The synchronous callback executes the named operation with its parameters.
     * @param Closure(string, array<string, string|int>): ApiResponse $request
     * @internal Obtain this group through WhmClient::server().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * Return the server's cPanel & WHM version string.
     *
     * Executes the read-only WHM `version` operation for the configured server;
     * no cPanel target account is used. Returns `data.version` unchanged, without
     * semantic-version coercion. WHM envelope metadata remains on the owning
     * client's responseMeta(), including before API or payload-validation errors.
     * Pinned API-map identity: `whm1:version#GET`.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/version
     */
    public function version(): string
    {
        $response = ($this->request)('version', []);
        if (!$response->data instanceof stdClass || !is_string($response->data->version ?? null)) {
            throw new InvalidResponseException('Missing or invalid server version payload.', $response->metadata);
        }

        return $response->data->version;
    }

    /**
     * Return the configured server's hostname.
     *
     * Immediately executes read-only WHM gethostname without parameters or a
     * cPanel account target. Returns data.hostname unchanged; a missing or
     * non-string field cannot satisfy this scalar accessor and raises an invalid
     * response error. The owning WHM client's responseMeta() retains the envelope
     * before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:gethostname#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/gethostname
     */
    public function hostname(): string
    {
        $response = ($this->request)('gethostname', []);
        if (!$response->data instanceof stdClass || !is_string($response->data->hostname ?? null)) {
            throw new InvalidResponseException('Missing or invalid server hostname payload.', $response->metadata);
        }

        return $response->data->hostname;
    }

    /**
     * Return the server's one-, five-, and fifteen-minute load averages.
     *
     * Immediately executes read-only WHM systemloadavg without parameters or a
     * cPanel account target. The immutable payload preserves optional load windows
     * and their values without rescaling, including the decimal strings observed
     * at runtime (retaining precision and trailing zeros). Upstream describes the values
     * as a percentage of CPU processor capacity; this accessor performs no unit
     * conversion. The owning WHM client's responseMeta() retains the envelope
     * before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:systemloadavg#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/systemloadavg
     */
    public function loadAverage(): ServerLoadAverage
    {
        $response = ($this->request)('systemloadavg', []);

        return ServerLoadAverage::fromPayload($response->data, $response->metadata);
    }

    /**
     * Return the server's drive partition usage.
     *
     * Immediately executes read-only WHM getdiskusage without parameters or a
     * cPanel account target. The immutable iterable/countable collection retains
     * optional partition records and fields, with disk sizes in kilobytes and
     * inode counts as integers. Omitted lists remain distinct from empty lists
     * in toArray(). The owning WHM client's responseMeta() retains the envelope
     * before API or payload errors. No retries are issued.
     * Pinned API-map identity: whm1:getdiskusage#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/getdiskusage
     */
    public function diskUsage(): ServerDiskUsage
    {
        $response = ($this->request)('getdiskusage', []);

        return ServerDiskUsage::fromPayload($response->data, $response->metadata);
    }
}
