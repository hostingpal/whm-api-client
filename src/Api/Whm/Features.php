<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Whm;

use Closure;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use InvalidArgumentException;
use stdClass;

/**
 * Read WHM feature catalogs, lists, and account settings.
 *
 * Payloads retain reviewed field presence, empty lists, null overrides, and
 * original flag types. Reviewed runtime observations additionally permit exact
 * string flags in four fields and booleans in three presentation flags; this
 * compatibility is separate from the pinned integer contracts. Unknown response
 * extensions are ignored. Returned arrays are detached from decoded source
 * objects. These methods perform no retries.
 * Source: docs/api/sources/2026-10-13/whm.openapi.json.gz.
 *
 * @phpstan-type FeatureName array{id?: string, name?: string}
 * @phpstan-type FeatureMetadata array{id?: string, name?: string, is_plugin?: 0|1|'0'|'1'}
 * @phpstan-type FeatureSetting array{
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
 * @phpstan-type UserFeatureSetting array{
 *     cpuser_setting?: 0|1|null,
 *     feature?: string,
 *     feature_list?: string,
 *     feature_list_setting?: 0|1|'0'|'1',
 *     user?: string,
 * }
 */
final readonly class Features
{
    /**
     * Bind reviewed requests to the owning WHM client's metadata lifecycle.
     *
     * The request callback executes synchronously. The separate lifecycle hook
     * must run before userSettings builds and validates indexed parameters,
     * because rejected input never reaches the request callback's metadata reset.
     * @param Closure(string, array<string, string|int>): ApiResponse $request
     * @param Closure(): void $beginRequest Clear stale metadata before local input validation.
     * @internal Obtain this group through WhmClient::features().
     */
    public function __construct(private Closure $request, private Closure $beginRequest)
    {
    }

    /**
     * Return available feature names and plugin indicators.
     *
     * Immediately executes read-only WHM get_feature_metadata for the authenticated
     * WHM user, without a cPanel target. Returns optional feature records with
     * IDs, names, and plugin flags. The pinned is_plugin integers and exact string
     * flags '0'/'1' observed on test.oblak.host on 2026-10-13 retain their original
     * types. This is feature catalog information, separate from responseMeta().
     * Envelope metadata remains on the owning client
     * before API or payload errors. Pinned API-map identity: whm1:get_feature_metadata#GET.
     *
     * @return array{features?: list<FeatureMetadata>}
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_feature_metadata
     */
    public function metadata(): array
    {
        $response = ($this->request)('get_feature_metadata', []);
        $data = self::object($response->data, $response->metadata);
        $values = [];
        if (property_exists($data, 'features')) {
            $values['features'] = self::records(
                $data->features,
                $response->metadata,
                static function (stdClass $record) use ($response): array {
                    $feature = self::namedFeature($record, $response->metadata);
                    if (property_exists($record, 'is_plugin')) {
                        $feature['is_plugin'] = self::stringCompatibleFlag($record->is_plugin, $response->metadata);
                    }

                    return $feature;
                },
            );
        }

        return $values;
    }

    /**
     * Return the IDs and display names of available features.
     *
     * Immediately executes read-only WHM get_feature_names for the configured
     * server, without a cPanel target or filters. Returns optional feature records
     * while preserving omission and empty lists. The owning client's responseMeta()
     * retains the envelope before API or payload errors.
     * Pinned API-map identity: whm1:get_feature_names#GET.
     *
     * @return array{feature?: list<FeatureName>}
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_feature_names
     */
    public function names(): array
    {
        $response = ($this->request)('get_feature_names', []);
        $data = self::object($response->data, $response->metadata);
        $values = [];
        if (property_exists($data, 'feature')) {
            $values['feature'] = self::records(
                $data->feature,
                $response->metadata,
                static fn (stdClass $record): array => self::namedFeature($record, $response->metadata),
            );
        }

        return $values;
    }

    /**
     * Return the configuration of a named feature list.
     *
     * Immediately executes read-only WHM get_featurelist_data using the supplied
     * list name and the authenticated WHM user's permissions. Returns feature
     * flags, dependencies, display and standalone settings. The disabled list
     * reverses value's meaning: 1 means excluded and 0 means included. Flags stay
     * unchanged, and regex strings in only_one_rules are never executed here.
     * The pinned schema requires id, is_disabled, and value in each feature;
     * its prose says the disabled list does not use is_disabled. Nullable
     * is_meridian_only remains distinct from omission. Runtime observations on
     * test.oblak.host on 2026-10-13 additionally permit exact string '0'/'1' for
     * is_disabled/value and booleans for is_standalone_experience,
     * is_meridian_only, and suppress_plugin_label. Original wire types are
     * preserved without extending these allowances to other flags. The owning client's
     * responseMeta() retains the envelope before API or payload errors.
     * Pinned API-map identity: whm1:get_featurelist_data#GET.
     *
     * @return array{featurelist?: string, features?: list<FeatureSetting>}
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_featurelist_data
     */
    public function listData(string $featureList): array
    {
        $response = ($this->request)('get_featurelist_data', ['featurelist' => $featureList]);
        $data = self::object($response->data, $response->metadata);
        $values = [];
        if (property_exists($data, 'featurelist')) {
            $values['featurelist'] = self::string($data->featurelist, $response->metadata);
        }
        if (property_exists($data, 'features')) {
            $values['features'] = self::records(
                $data->features,
                $response->metadata,
                static fn (stdClass $record): array => self::featureSetting($record, $response->metadata),
            );
        }

        return $values;
    }

    /**
     * Return feature lists available to the authenticated WHM user.
     *
     * Immediately executes read-only WHM get_featurelists without a cPanel target
     * or filters. Root sees all server feature lists; resellers see the lists they
     * own. Returns the optional list of names without conflating omission and an
     * empty list. The owning client's responseMeta() retains the envelope before
     * API or payload errors. Pinned API-map identity: whm1:get_featurelists#GET.
     *
     * @return array{featurelists?: list<string>}
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_featurelists
     */
    public function lists(): array
    {
        $response = ($this->request)('get_featurelists', []);
        $data = self::object($response->data, $response->metadata);
        $values = [];
        if (property_exists($data, 'featurelists')) {
            $values['featurelists'] = self::strings($data->featurelists, $response->metadata);
        }

        return $values;
    }

    /**
     * Return feature list names grouped by package type.
     *
     * Immediately executes read-only WHM get_featurelists_by_package_types for
     * the authenticated WHM user without a cPanel target. The optional grouping
     * contains a required standard list and an optional nova list. Empty lists
     * and omission remain distinct. The owning client's responseMeta() retains
     * the envelope before API or payload errors.
     * Pinned API-map identity: whm1:get_featurelists_by_package_types#GET.
     *
     * @return array{package_types_feature_lists?: array{standard: list<string>, nova?: list<string>}}
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_featurelists_by_package_types
     */
    public function listsByPackageType(): array
    {
        $response = ($this->request)('get_featurelists_by_package_types', []);
        $data = self::object($response->data, $response->metadata);
        if (!property_exists($data, 'package_types_feature_lists')) {
            return [];
        }

        $types = self::object($data->package_types_feature_lists, $response->metadata);
        $values = ['standard' => self::strings($types->standard ?? null, $response->metadata)];
        if (property_exists($types, 'nova')) {
            $values['nova'] = self::strings($types->nova, $response->metadata);
        }

        return ['package_types_feature_lists' => $values];
    }

    /**
     * Return account overrides and feature-list defaults for one or more users.
     *
     * Immediately executes read-only WHM get_users_features_settings for the
     * explicitly supplied cPanel users using the authenticated WHM permissions.
     * Lists use user/user-1/... and feature/feature-1/... query parameters. Null
     * feature omits the filter and requests all features; an explicit empty
     * string reaches the API, which documents an error. Empty input lists are
     * rejected before I/O after clearing stale response metadata. cpuser_setting
     * null follows the pinned nullable flag and prose despite its integer-only
     * enum; omission remains distinct. feature_list_setting also preserves exact
     * string '0'/'1' observed on test.oblak.host on 2026-10-13, separately from its
     * pinned integer contract; cpuser_setting still requires integer 0/1 or null. Returns
     * optional records; overrides take precedence over feature_list_setting.
     * The owning client's responseMeta() retains the envelope before API or
     * payload errors. Pinned API-map identity: whm1:get_users_features_settings#GET.
     *
     * @param string|list<string> $user One username or a nonempty list of usernames.
     * @param string|list<string>|null $feature One feature, a nonempty list, or no filter.
     * @return array{users_features_settings?: list<UserFeatureSetting>}
     * @throws InvalidArgumentException If an input array is empty or is not a list of strings.
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_users_features_settings
     */
    public function userSettings(string|array $user, string|array|null $feature = null): array
    {
        ($this->beginRequest)();
        $parameters = self::numberedParameters('user', $user);
        if ($feature !== null) {
            $parameters += self::numberedParameters('feature', $feature);
        }

        $response = ($this->request)('get_users_features_settings', $parameters);
        $data = self::object($response->data, $response->metadata);
        $values = [];
        if (property_exists($data, 'users_features_settings')) {
            $values['users_features_settings'] = self::records(
                $data->users_features_settings,
                $response->metadata,
                static fn (stdClass $record): array => self::userFeatureSetting($record, $response->metadata),
            );
        }

        return $values;
    }

    /**
     * Check whether a cPanel user has access to a feature.
     *
     * Immediately executes read-only WHM verify_user_has_feature for the explicit
     * user and feature ID using the authenticated WHM permissions. Returns true
     * only for integer has_feature 1 and false for 0. Unknown features also return
     * true upstream, so this method does not verify feature existence. Missing or
     * malformed access flags cannot satisfy the boolean accessor. The owning
     * client's responseMeta() retains the envelope before API or payload errors.
     * Pinned API-map identity: whm1:verify_user_has_feature#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/verify_user_has_feature
     */
    public function userHasFeature(string $user, string $feature): bool
    {
        $response = ($this->request)('verify_user_has_feature', ['user' => $user, 'feature' => $feature]);
        $data = self::object($response->data, $response->metadata);
        if (property_exists($data, 'query_feature')) {
            self::string($data->query_feature, $response->metadata);
        }

        return self::flag($data->has_feature ?? null, $response->metadata) === 1;
    }

    /** @return FeatureName */
    private static function namedFeature(stdClass $record, ResponseMetadata $metadata): array
    {
        $values = [];
        if (property_exists($record, 'id')) {
            $values['id'] = self::string($record->id, $metadata);
        }
        if (property_exists($record, 'name')) {
            $values['name'] = self::string($record->name, $metadata);
        }

        return $values;
    }

    /** @return FeatureSetting */
    private static function featureSetting(stdClass $record, ResponseMetadata $metadata): array
    {
        $values = [
            'id' => self::string($record->id ?? null, $metadata),
            'is_disabled' => self::stringCompatibleFlag($record->is_disabled ?? null, $metadata),
            'value' => self::stringCompatibleFlag($record->value ?? null, $metadata),
        ];
        if (property_exists($record, 'dependencies')) {
            $values['dependencies'] = self::strings($record->dependencies, $metadata);
        }
        if (property_exists($record, 'name')) {
            $values['name'] = self::string($record->name, $metadata);
        }
        if (property_exists($record, 'is_standalone_experience')) {
            $values['is_standalone_experience'] = self::booleanCompatibleFlag(
                $record->is_standalone_experience,
                $metadata,
            );
        }
        if (property_exists($record, 'badge_label')) {
            $values['badge_label'] = self::string($record->badge_label, $metadata);
        }
        if (property_exists($record, 'badge_class')) {
            $values['badge_class'] = self::string($record->badge_class, $metadata);
        }
        if (property_exists($record, 'required_package_extension')) {
            $values['required_package_extension'] = self::string($record->required_package_extension, $metadata);
        }
        if (property_exists($record, 'is_meridian_only')) {
            $values['is_meridian_only'] = $record->is_meridian_only === null
                ? null : self::booleanCompatibleFlag($record->is_meridian_only, $metadata);
        }
        if (property_exists($record, 'redirect_url')) {
            $values['redirect_url'] = self::string($record->redirect_url, $metadata);
        }
        if (property_exists($record, 'suppress_plugin_label')) {
            $values['suppress_plugin_label'] = self::booleanCompatibleFlag($record->suppress_plugin_label, $metadata);
        }
        if (property_exists($record, 'only_one_rules')) {
            $values['only_one_rules'] = self::strings($record->only_one_rules, $metadata);
        }

        return $values;
    }

    /** @return UserFeatureSetting */
    private static function userFeatureSetting(stdClass $record, ResponseMetadata $metadata): array
    {
        $values = [];
        if (property_exists($record, 'cpuser_setting')) {
            $values['cpuser_setting'] = $record->cpuser_setting === null
                ? null : self::flag($record->cpuser_setting, $metadata);
        }
        if (property_exists($record, 'feature')) {
            $values['feature'] = self::string($record->feature, $metadata);
        }
        if (property_exists($record, 'feature_list')) {
            $values['feature_list'] = self::string($record->feature_list, $metadata);
        }
        if (property_exists($record, 'feature_list_setting')) {
            $values['feature_list_setting'] = self::stringCompatibleFlag($record->feature_list_setting, $metadata);
        }
        if (property_exists($record, 'user')) {
            $values['user'] = self::string($record->user, $metadata);
        }

        return $values;
    }

    /**
     * Encode documented repeated parameters without silently dropping invalid lists.
     *
     * @param string|array<array-key, mixed> $input
     * @return array<string, string>
     */
    private static function numberedParameters(string $name, string|array $input): array
    {
        if (is_string($input)) {
            return [$name => $input];
        }
        if ($input === [] || !array_is_list($input)) {
            throw new InvalidArgumentException('Feature query parameters require a nonempty list of strings.');
        }

        $parameters = [];
        foreach ($input as $index => $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Feature query parameters require a nonempty list of strings.');
            }
            $parameters[$index === 0 ? $name : $name . '-' . $index] = $value;
        }

        return $parameters;
    }

    /** Require a decoded JSON object, retaining the response snapshot on failure. */
    private static function object(mixed $value, ResponseMetadata $metadata): stdClass
    {
        if (!$value instanceof stdClass) {
            throw new InvalidResponseException('Invalid WHM feature payload.', $metadata);
        }

        return $value;
    }

    /** Preserve a string without coercion or content restrictions. */
    private static function string(mixed $value, ResponseMetadata $metadata): string
    {
        if (!is_string($value)) {
            throw new InvalidResponseException('Invalid WHM feature string.', $metadata);
        }

        return $value;
    }

    /** @return 0|1 */
    private static function flag(mixed $value, ResponseMetadata $metadata): int
    {
        if ($value !== 0 && $value !== 1) {
            throw new InvalidResponseException('Invalid WHM feature flag.', $metadata);
        }

        return $value;
    }

    /**
     * Preserve exact string flags only for fields with reviewed runtime evidence.
     *
     * @return 0|1|'0'|'1'
     */
    private static function stringCompatibleFlag(mixed $value, ResponseMetadata $metadata): int|string
    {
        return $value === '0' || $value === '1' ? $value : self::flag($value, $metadata);
    }

    /**
     * Preserve boolean flags only for the three observed presentation fields.
     *
     * @return 0|1|bool
     */
    private static function booleanCompatibleFlag(mixed $value, ResponseMetadata $metadata): int|bool
    {
        return is_bool($value) ? $value : self::flag($value, $metadata);
    }

    /** @return list<string> */
    private static function strings(mixed $value, ResponseMetadata $metadata): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidResponseException('Invalid WHM feature string list.', $metadata);
        }

        $values = [];
        foreach ($value as $item) {
            $values[] = self::string($item, $metadata);
        }

        return $values;
    }

    /**
     * Validate and copy each object in a JSON list using its endpoint schema.
     *
     * @template T
     * @param Closure(stdClass): T $parse
     * @return list<T>
     */
    private static function records(mixed $value, ResponseMetadata $metadata, Closure $parse): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidResponseException('Invalid WHM feature record list.', $metadata);
        }

        $values = [];
        foreach ($value as $item) {
            $values[] = $parse(self::object($item, $metadata));
        }

        return $values;
    }
}
