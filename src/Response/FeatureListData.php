<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use ArrayIterator;
use Countable;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use IteratorAggregate;

/**
 * Retain one WHM feature list's name and feature settings.
 *
 * Both fields are optional. Null properties mean omission; toArray() omits them
 * and keeps an empty features list distinct. Iteration and count cover the
 * feature settings, treating omission as empty. In the disabled list each
 * setting's value has reversed meaning (see FeatureSetting).
 *
 * Source: whm.openapi.json.gz, /paths/~1get_featurelist_data/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:get_featurelist_data#GET.
 *
 * @phpstan-import-type FeatureSettingPayload from FeatureSetting
 * @phpstan-type FeatureListDataPayload array{featurelist?: string, features?: list<FeatureSettingPayload>}
 * @implements IteratorAggregate<int, FeatureSetting>
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_featurelist_data
 */
final readonly class FeatureListData implements Countable, IteratorAggregate
{
    /**
     * Store the list name and detached records, retaining omission as null.
     *
     * @param list<FeatureSetting>|null $features
     */
    private function __construct(public ?string $featureList, public ?array $features)
    {
    }

    /**
     * Validate the WHM data object and copy each feature setting.
     *
     * Invalid containers or records retain this response's metadata.
     *
     * @throws InvalidResponseException If the container or a record is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM feature list payload');
        $payload = $read->object($payload);

        return new self(
            property_exists($payload, 'featurelist') ? $read->string($payload->featurelist) : null,
            property_exists($payload, 'features') ? $read->listOf(
                $payload->features,
                static fn (mixed $feature): FeatureSetting => FeatureSetting::fromPayload($feature, $metadata),
            ) : null,
        );
    }

    /**
     * Export the list name and records without adding omitted fields.
     *
     * @return FeatureListDataPayload
     */
    public function toArray(): array
    {
        $values = [];
        if ($this->featureList !== null) {
            $values['featurelist'] = $this->featureList;
        }
        if ($this->features !== null) {
            $values['features'] = array_map(
                static fn (FeatureSetting $feature): array => $feature->toArray(),
                $this->features,
            );
        }

        return $values;
    }

    /** Return the number of feature settings, or zero for an omitted list. */
    public function count(): int
    {
        return count($this->features ?? []);
    }

    /**
     * Iterate immutable settings in upstream order, treating omission as empty.
     *
     * @return ArrayIterator<int, FeatureSetting>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->features ?? []);
    }
}
