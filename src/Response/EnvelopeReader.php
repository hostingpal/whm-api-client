<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;

/**
 * Read WHM and UAPI envelopes and apply the request's declared outcome policy.
 *
 * The integer 0/1 outcome rule (OutcomePolicy::Flag) is the documented envelope
 * convention of the pinned contracts used by most typed operations.
 * OutcomePolicy::Unchecked records metadata without interpreting the
 * operation's outcome, for operations whose status carries payload semantics. On
 * the WHM bridge, Unchecked waives only the UAPI status: the outer WHM result is
 * checked under both policies because it reports whether the UAPI call ran.
 * Typed UAPI operations additionally validate integer 0/1 status in OperationRunner,
 * even when that status carries feature state. Generic Unchecked calls retain
 * caller-interpreted status semantics.
 * JSON objects remain distinct from lists until an endpoint validates its payload.
 * Metadata is opaque JSON copied into arrays, with payload data excluded.
 * Direct UAPI accepts the pinned result wrapper and the bare result observed
 * over cPanel HTTPS; see docs/api/source-audit.md for the runtime discrepancy.
 *
 * @internal
 */
final class EnvelopeReader
{
    /**
     * Extract available metadata before interpreting HTTP and API outcomes.
     *
     * HTTP failure takes precedence over API outcome. On malformed envelopes,
     * available metadata is retained without inventing missing success flags.
     * Both policies require a usable envelope; only Flag interprets the operation's
     * outcome (WHM metadata.result or UAPI status), while the bridge's outer WHM
     * result is interpreted under both. The response argument is sensitive because
     * its body can contain secrets even when the envelope or outcome is invalid.
     *
     * @throws HttpException If HTTP status is outside 200-299.
     * @throws InvalidResponseException If JSON or required envelope fields are unusable.
     * @throws ApiErrorException If the Flag policy finds a WHM or UAPI failure outcome, or the bridge failed.
     */
    public static function read(
        #[SensitiveParameter] ResponseInterface $response,
        ResponseRoute $route,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        $status = $response->getStatusCode();

        try {
            $document = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException | RuntimeException) {
            self::invalid($status);
        }

        if (!$document instanceof stdClass) {
            self::invalid($status);
        }

        $whm = $route !== ResponseRoute::DirectUapi && ($document->metadata ?? null) instanceof stdClass
            ? self::metadata($document->metadata) : null;
        $uapi = match ($route) {
            ResponseRoute::Whm => null,
            ResponseRoute::DirectUapi => self::directUapiEnvelope($document),
            ResponseRoute::BridgedUapi => ($document->data ?? null) instanceof stdClass
                ? ($document->data->uapi ?? null) : null,
        };
        $uapiMetadata = null;
        if ($uapi instanceof stdClass) {
            // The payload is validated by its endpoint; never copy it into metadata.
            $uapiMetadata = self::metadata($uapi, 'data');
        }
        try {
            $metadata = $whm === null && $uapiMetadata === null
                ? null : new ResponseMetadata($status, $whm, $uapiMetadata);
        } catch (InvalidArgumentException) {
            // JSON numbers can overflow PHP floats. Such metadata cannot form a
            // usable snapshot; keep HTTP failure precedence and safe error types.
            self::invalid($status);
        }

        if ($status < 200 || $status >= 300) {
            throw new HttpException($status, $metadata);
        }

        // The bridge's WHM result reports whether the UAPI call ran at all, so it is
        // checked under both policies; Unchecked waives only the UAPI status there.
        $checkWhm = $route === ResponseRoute::BridgedUapi
            || ($route === ResponseRoute::Whm && $outcome === OutcomePolicy::Flag);
        if ($checkWhm) {
            self::outcome($whm['result'] ?? null, 'WHM', $metadata);
        }

        if ($route !== ResponseRoute::Whm) {
            if ($outcome === OutcomePolicy::Flag) {
                self::outcome($uapiMetadata['status'] ?? null, 'UAPI', $metadata);
            }
            if (!$uapi instanceof stdClass) {
                throw new InvalidResponseException('Missing UAPI response envelope.', $metadata);
            }
        }

        // A usable envelope necessarily came from at least one metadata object.
        if ($metadata === null) {
            throw new InvalidResponseException('Missing API response metadata.');
        }

        return new ApiResponse(
            $route === ResponseRoute::Whm ? ($document->data ?? null) : ($uapi->data ?? null),
            $metadata,
            $route === ResponseRoute::Whm ? property_exists($document, 'data')
                : property_exists($uapi, 'data'),
        );
    }

    /**
     * Select the pinned wrapper or the observed direct HTTP result object.
     *
     * An explicitly present wrapper always wins, even if malformed. Do not mask
     * its failure with a competing root status. A bare envelope must carry status;
     * outcome validation separately enforces this operation's integer 0/1 rule.
     */
    private static function directUapiEnvelope(stdClass $document): mixed
    {
        if (property_exists($document, 'result')) {
            return $document->result;
        }

        return property_exists($document, 'status') ? $document : null;
    }

    /** Fail without echoing a body, request, or JSON parser exception. */
    private static function invalid(int $status): never
    {
        if ($status < 200 || $status >= 300) {
            throw new HttpException($status);
        }

        throw new InvalidResponseException('Invalid JSON response envelope.');
    }

    /**
     * Interpret the documented integer 0/1 success convention (OutcomePolicy::Flag).
     *
     * An invalid outcome or upstream diagnostic can echo secrets, so both raw
     * values and metadata must stay out of automatic exception trace arguments.
     */
    private static function outcome(
        #[SensitiveParameter] mixed $value,
        string $family,
        #[SensitiveParameter] ?ResponseMetadata $metadata,
    ): void {
        if ($value === 0) {
            throw new ApiErrorException(
                $family . ' operation failed; inspect errors() or response metadata.',
                $metadata,
            );
        }

        if ($value !== 1) {
            throw new InvalidResponseException('Missing or invalid ' . $family . ' outcome.', $metadata);
        }
    }

    /**
     * Convert an opaque metadata object into the existing immutable-map boundary.
     *
     * The optional excluded top-level member is skipped before conversion, so
     * large payloads are not copied only to be discarded.
     *
     * @return array<array-key, mixed>
     */
    private static function metadata(stdClass $object, ?string $exclude = null): array
    {
        $result = [];
        foreach (get_object_vars($object) as $key => $value) {
            if ($key !== $exclude) {
                $result[$key] = self::jsonValue($value);
            }
        }

        return $result;
    }

    /** Convert JSON objects recursively while retaining scalar and null values. */
    private static function jsonValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::metadata($value);
        }

        if (is_array($value)) {
            return array_map(self::jsonValue(...), $value);
        }

        return $value;
    }
}
