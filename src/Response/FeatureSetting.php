<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;

/**
 * Retain one feature's configuration within a WHM feature list.
 *
 * The pinned schema requires id, is_disabled and value; its prose says the
 * disabled list does not use is_disabled. In the disabled list value reverses
 * meaning: 1 means the feature is excluded and 0 means it is included. Other
 * fields are optional, and properties use null for omission. Flag properties
 * are normalized to integer 0 or 1. Runtime observations on test.oblak.host
 * (2026-10-13) returned exact strings '0'/'1' for is_disabled and value, and
 * booleans for is_standalone_experience, is_meridian_only and
 * suppress_plugin_label; only those fields accept those forms, and toArray()
 * preserves the original wire values. Nullable is_meridian_only remains
 * distinct from omission in toArray(). Regex strings in only_one_rules are
 * returned unchanged and never executed. Unknown response extensions are ignored.
 *
 * Source: whm.openapi.json.gz, /paths/~1get_featurelist_data/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:get_featurelist_data#GET.
 *
 * @phpstan-type FeatureSettingPayload array{
 *     id: string,
 *     is_disabled: 0|1|'0'|'1',
 *     value: 0|1|'0'|'1',
 *     dependencies?: list<string>,
 *     name?: string,
 *     is_standalone_experience?: 0|1|bool,
 *     badge_label?: string,
 *     badge_class?: string,
 *     required_package_extension?: string,
 *     is_meridian_only?: 0|1|bool|null,
 *     redirect_url?: string,
 *     suppress_plugin_label?: 0|1|bool,
 *     only_one_rules?: list<string>,
 * }
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_featurelist_data
 */
final readonly class FeatureSetting
{
    /** The feature ID. */
    public string $id;

    /** @var 0|1 Whether the feature is disabled, normalized from either wire form. */
    public int $isDisabled;

    /** @var 0|1 The feature list value; in the disabled list 1 means excluded. */
    public int $value;

    /** @var list<string>|null Feature IDs this feature depends on, or null when omitted. */
    public ?array $dependencies;

    /** The feature's display name, or null when omitted. */
    public ?string $name;

    /** @var 0|1|null Whether the feature is a standalone experience, or null when omitted. */
    public ?int $isStandaloneExperience;

    /** The badge label, or null when omitted. */
    public ?string $badgeLabel;

    /** The badge CSS class, or null when omitted. */
    public ?string $badgeClass;

    /** The required package extension, or null when omitted. */
    public ?string $requiredPackageExtension;

    /** @var 0|1|null Whether the feature is Meridian-only; null when omitted or explicitly null. */
    public ?int $isMeridianOnly;

    /** The feature's redirect URL, or null when omitted. */
    public ?string $redirectUrl;

    /** @var 0|1|null Whether to suppress the plugin label, or null when omitted. */
    public ?int $suppressPluginLabel;

    /** @var list<string>|null Mutual-exclusion regex strings, never executed here, or null when omitted. */
    public ?array $onlyOneRules;

    /**
     * Initialize normalized properties from the validated, detached record.
     *
     * @param FeatureSettingPayload $payload
     */
    private function __construct(private array $payload)
    {
        $this->id = $payload['id'];
        $this->isDisabled = (int) $payload['is_disabled'];
        $this->value = (int) $payload['value'];
        $this->dependencies = $payload['dependencies'] ?? null;
        $this->name = $payload['name'] ?? null;
        $this->isStandaloneExperience = isset($payload['is_standalone_experience'])
            ? (int) $payload['is_standalone_experience'] : null;
        $this->badgeLabel = $payload['badge_label'] ?? null;
        $this->badgeClass = $payload['badge_class'] ?? null;
        $this->requiredPackageExtension = $payload['required_package_extension'] ?? null;
        $this->isMeridianOnly = isset($payload['is_meridian_only']) ? (int) $payload['is_meridian_only'] : null;
        $this->redirectUrl = $payload['redirect_url'] ?? null;
        $this->suppressPluginLabel = isset($payload['suppress_plugin_label'])
            ? (int) $payload['suppress_plugin_label'] : null;
        $this->onlyOneRules = $payload['only_one_rules'] ?? null;
    }

    /**
     * Validate and copy one decoded feature-list record.
     *
     * JSON objects must remain stdClass instances. The three required fields
     * must be present; only the fields named in the class description accept
     * their observed string or boolean flag forms.
     *
     * @throws InvalidResponseException If the record or a known field is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM feature list record');
        $payload = $read->object($payload);
        $values = [
            'id' => $read->string($read->field($payload, 'id')),
            'is_disabled' => $read->stringFlag($read->field($payload, 'is_disabled')),
            'value' => $read->stringFlag($read->field($payload, 'value')),
        ];
        if (property_exists($payload, 'dependencies')) {
            $values['dependencies'] = $read->strings($payload->dependencies);
        }
        if (property_exists($payload, 'name')) {
            $values['name'] = $read->string($payload->name);
        }
        if (property_exists($payload, 'is_standalone_experience')) {
            $values['is_standalone_experience'] = $read->boolFlag($payload->is_standalone_experience);
        }
        if (property_exists($payload, 'badge_label')) {
            $values['badge_label'] = $read->string($payload->badge_label);
        }
        if (property_exists($payload, 'badge_class')) {
            $values['badge_class'] = $read->string($payload->badge_class);
        }
        if (property_exists($payload, 'required_package_extension')) {
            $values['required_package_extension'] = $read->string($payload->required_package_extension);
        }
        if (property_exists($payload, 'is_meridian_only')) {
            $values['is_meridian_only'] = $read->nullable($payload->is_meridian_only, $read->boolFlag(...));
        }
        if (property_exists($payload, 'redirect_url')) {
            $values['redirect_url'] = $read->string($payload->redirect_url);
        }
        if (property_exists($payload, 'suppress_plugin_label')) {
            $values['suppress_plugin_label'] = $read->boolFlag($payload->suppress_plugin_label);
        }
        if (property_exists($payload, 'only_one_rules')) {
            $values['only_one_rules'] = $read->strings($payload->only_one_rules);
        }

        return new self($values);
    }

    /**
     * Export reviewed fields with their original presence and wire values.
     *
     * @return FeatureSettingPayload
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
