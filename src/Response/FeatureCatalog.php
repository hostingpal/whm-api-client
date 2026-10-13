<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use ArrayIterator;
use Countable;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use IteratorAggregate;

/**
 * Retain the WHM feature catalog as an immutable collection.
 *
 * The optional features field remains distinct from an empty list: a missing
 * field produces null in the property and an omitted key in toArray(), while
 * both cases count and iterate as empty. Unknown response extensions are ignored.
 *
 * Source: whm.openapi.json.gz, /paths/~1get_feature_metadata/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:get_feature_metadata#GET.
 *
 * @phpstan-import-type FeatureMetadataPayload from FeatureMetadata
 * @phpstan-type FeatureCatalogPayload array{features?: list<FeatureMetadataPayload>}
 * @implements IteratorAggregate<int, FeatureMetadata>
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_feature_metadata
 */
final readonly class FeatureCatalog implements Countable, IteratorAggregate
{
    /**
     * Store detached immutable records, retaining field omission as null.
     *
     * @param list<FeatureMetadata>|null $features
     */
    private function __construct(public ?array $features)
    {
    }

    /**
     * Validate the WHM data object and copy each feature record.
     *
     * Invalid containers or records retain this response's metadata.
     *
     * @throws InvalidResponseException If the container or a record is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM feature metadata payload');
        $payload = $read->object($payload);
        if (!property_exists($payload, 'features')) {
            return new self(null);
        }

        return new self($read->listOf(
            $payload->features,
            static fn (mixed $feature): FeatureMetadata => FeatureMetadata::fromPayload($feature, $metadata),
        ));
    }

    /**
     * Export the container and records without adding omitted fields.
     *
     * @return FeatureCatalogPayload
     */
    public function toArray(): array
    {
        return $this->features === null ? [] : ['features' => array_map(
            static fn (FeatureMetadata $feature): array => $feature->toArray(),
            $this->features,
        )];
    }

    /** Return the number of supplied records, or zero for an omitted list. */
    public function count(): int
    {
        return count($this->features ?? []);
    }

    /**
     * Iterate immutable records in upstream order, treating omission as empty.
     *
     * @return ArrayIterator<int, FeatureMetadata>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->features ?? []);
    }
}
