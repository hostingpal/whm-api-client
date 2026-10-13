<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;

/**
 * Retain one WHM feature catalog record as an immutable snapshot.
 *
 * Every field is optional. Properties use null for omission and expose the
 * plugin flag as integer 0 or 1. The pinned contract declares an integer flag;
 * test.oblak.host returned exact strings '0'/'1' on 2026-10-13, so both forms are
 * accepted and toArray() preserves the original wire value. Unknown response
 * extensions are ignored.
 *
 * Source: whm.openapi.json.gz, /paths/~1get_feature_metadata/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:get_feature_metadata#GET.
 *
 * @phpstan-type FeatureMetadataPayload array{id?: string, name?: string, is_plugin?: 0|1|'0'|'1'}
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_feature_metadata
 */
final readonly class FeatureMetadata
{
    /** The feature ID, or null when omitted. */
    public ?string $id;

    /** The feature's display name, or null when omitted. */
    public ?string $name;

    /** @var 0|1|null Whether the feature is a plugin, normalized from either wire form, or null when omitted. */
    public ?int $isPlugin;

    /**
     * Initialize normalized properties from the validated, detached record.
     *
     * @param FeatureMetadataPayload $payload
     */
    private function __construct(private array $payload)
    {
        $this->id = $payload['id'] ?? null;
        $this->name = $payload['name'] ?? null;
        $this->isPlugin = isset($payload['is_plugin']) ? (int) $payload['is_plugin'] : null;
    }

    /**
     * Validate and copy one decoded feature catalog record.
     *
     * JSON objects must remain stdClass instances. Present IDs and names must
     * be strings; a present plugin flag must be integer or exact string 0/1.
     *
     * @throws InvalidResponseException If the record or a known field is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM feature metadata record');
        $payload = $read->object($payload);
        $values = [];
        if (property_exists($payload, 'id')) {
            $values['id'] = $read->string($payload->id);
        }
        if (property_exists($payload, 'name')) {
            $values['name'] = $read->string($payload->name);
        }
        if (property_exists($payload, 'is_plugin')) {
            $values['is_plugin'] = $read->stringFlag($payload->is_plugin);
        }

        return new self($values);
    }

    /**
     * Export reviewed fields with their original presence and wire values.
     *
     * @return FeatureMetadataPayload
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
