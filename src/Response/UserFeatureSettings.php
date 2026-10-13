<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use ArrayIterator;
use Countable;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use IteratorAggregate;

/**
 * Retain account feature settings returned by WHM as an immutable collection.
 *
 * The optional users_features_settings field remains distinct from an empty
 * list: a missing field produces null in the property and an omitted key in
 * toArray(), while both cases count and iterate as empty.
 *
 * Source: whm.openapi.json.gz, /paths/~1get_users_features_settings/get, pinned
 * under docs/api/sources/2026-10-13; API-map identity
 * whm1:get_users_features_settings#GET.
 *
 * @phpstan-import-type UserFeatureSettingPayload from UserFeatureSetting
 * @phpstan-type UserFeatureSettingsPayload array{users_features_settings?: list<UserFeatureSettingPayload>}
 * @implements IteratorAggregate<int, UserFeatureSetting>
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_users_features_settings
 */
final readonly class UserFeatureSettings implements Countable, IteratorAggregate
{
    /**
     * Store detached immutable records, retaining field omission as null.
     *
     * @param list<UserFeatureSetting>|null $settings
     */
    private function __construct(public ?array $settings)
    {
    }

    /**
     * Validate the WHM data object and copy each account feature setting.
     *
     * Invalid containers or records retain this response's metadata.
     *
     * @throws InvalidResponseException If the container or a record is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM user feature settings payload');
        $payload = $read->object($payload);
        if (!property_exists($payload, 'users_features_settings')) {
            return new self(null);
        }

        return new self($read->listOf(
            $payload->users_features_settings,
            static fn (mixed $setting): UserFeatureSetting => UserFeatureSetting::fromPayload($setting, $metadata),
        ));
    }

    /**
     * Export the container and records without adding omitted fields.
     *
     * @return UserFeatureSettingsPayload
     */
    public function toArray(): array
    {
        return $this->settings === null ? [] : ['users_features_settings' => array_map(
            static fn (UserFeatureSetting $setting): array => $setting->toArray(),
            $this->settings,
        )];
    }

    /** Return the number of supplied records, or zero for an omitted list. */
    public function count(): int
    {
        return count($this->settings ?? []);
    }

    /**
     * Iterate immutable records in upstream order, treating omission as empty.
     *
     * @return ArrayIterator<int, UserFeatureSetting>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->settings ?? []);
    }
}
