<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

/**
 * Carry a generic call's decoded payload together with its envelope snapshot.
 *
 * $data is the envelope's data member with JSON objects converted to associative
 * arrays (numeric object keys become integer keys), lists kept as lists, and
 * scalars and null unchanged. $hasData distinguishes an omitted data member
 * (null data) from an explicit null. No endpoint schema is applied: callers
 * interpret the payload with the pinned documentation of the operation they
 * named, and the typed operation groups remain the reviewed contracts.
 */
final readonly class CallResult
{
    /**
     * Keep the converted payload, its presence, and the metadata snapshot together.
     *
     * The snapshot is the same instance the owning handle exposes through responseMeta().
     */
    public function __construct(public mixed $data, public bool $hasData, public ResponseMetadata $metadata)
    {
    }

    /**
     * Convert a validated envelope into the public generic result.
     *
     * @internal Built by the family handles after envelope validation.
     */
    public static function fromApiResponse(ApiResponse $response): self
    {
        $read = new PayloadReader($response->metadata, 'call payload');

        return new self($read->json($response->data), $response->hasData, $response->metadata);
    }
}
