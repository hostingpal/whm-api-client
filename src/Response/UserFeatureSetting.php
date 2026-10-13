<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;

/**
 * Retain one account's override and feature-list default for a feature.
 *
 * Every field is optional and properties use null for omission. The account
 * override cpuser_setting follows the pinned nullable flag: null means no
 * override and remains distinct from omission in toArray(). Overrides take
 * precedence over feature_list_setting. The pinned feature_list_setting is an
 * integer flag; test.oblak.host returned exact strings '0'/'1' on 2026-10-13, so
 * both forms are accepted, normalized to 0 or 1 in the property, and preserved
 * by toArray(). cpuser_setting still requires integer 0/1 or null.
 *
 * Source: whm.openapi.json.gz, /paths/~1get_users_features_settings/get, pinned
 * under docs/api/sources/2026-10-13; API-map identity
 * whm1:get_users_features_settings#GET.
 *
 * @phpstan-type UserFeatureSettingPayload array{
 *     cpuser_setting?: 0|1|null,
 *     feature?: string,
 *     feature_list?: string,
 *     feature_list_setting?: 0|1|'0'|'1',
 *     user?: string,
 * }
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_users_features_settings
 */
final readonly class UserFeatureSetting
{
    /** @var 0|1|null The account override; null when omitted or explicitly absent. */
    public ?int $cpuserSetting;

    /** The feature ID, or null when omitted. */
    public ?string $feature;

    /** The account's feature list name, or null when omitted. */
    public ?string $featureList;

    /** @var 0|1|null The feature list's default, normalized from either wire form, or null when omitted. */
    public ?int $featureListSetting;

    /** The cPanel account username, or null when omitted. */
    public ?string $user;

    /**
     * Initialize normalized properties from the validated, detached record.
     *
     * @param UserFeatureSettingPayload $payload
     */
    private function __construct(private array $payload)
    {
        $this->cpuserSetting = $payload['cpuser_setting'] ?? null;
        $this->feature = $payload['feature'] ?? null;
        $this->featureList = $payload['feature_list'] ?? null;
        $this->featureListSetting = isset($payload['feature_list_setting'])
            ? (int) $payload['feature_list_setting'] : null;
        $this->user = $payload['user'] ?? null;
    }

    /**
     * Validate and copy one decoded account feature setting.
     *
     * JSON objects must remain stdClass instances; only feature_list_setting
     * accepts the observed string flag form.
     *
     * @throws InvalidResponseException If the record or a known field is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM user feature setting');
        $payload = $read->object($payload);
        $values = [];
        if (property_exists($payload, 'cpuser_setting')) {
            $values['cpuser_setting'] = $read->nullable($payload->cpuser_setting, $read->flag(...));
        }
        if (property_exists($payload, 'feature')) {
            $values['feature'] = $read->string($payload->feature);
        }
        if (property_exists($payload, 'feature_list')) {
            $values['feature_list'] = $read->string($payload->feature_list);
        }
        if (property_exists($payload, 'feature_list_setting')) {
            $values['feature_list_setting'] = $read->stringFlag($payload->feature_list_setting);
        }
        if (property_exists($payload, 'user')) {
            $values['user'] = $read->string($payload->user);
        }

        return new self($values);
    }

    /**
     * Export reviewed fields with their original presence and wire values.
     *
     * @return UserFeatureSettingPayload
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
