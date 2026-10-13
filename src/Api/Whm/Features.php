<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Whm;

use Closure;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\FeatureCatalog;
use HostingPal\WhmApiClient\Response\FeatureListData;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\UserFeatureSettings;
use InvalidArgumentException;

/**
 * Read WHM feature catalogs, lists, and account settings.
 *
 * Records whose flags vary in wire type are returned as immutable DTOs with
 * normalized 0/1 properties and wire-preserving toArray() exports; payloads made
 * only of strings are returned as precise arrays. Field presence, empty lists,
 * and null overrides are retained. Reviewed runtime observations additionally
 * permit exact string flags in four fields and booleans in three presentation
 * flags; this compatibility is separate from the pinned integer contracts.
 * Unknown response extensions are ignored. Returned values are detached from
 * decoded source objects. These methods perform no retries.
 * Source: docs/api/sources/2026-10-13/whm.openapi.json.gz.
 *
 * @phpstan-type FeatureName array{id?: string, name?: string}
 */
final readonly class Features
{
    /**
     * Bind reviewed requests to the owning WHM client's metadata lifecycle.
     *
     * The request callback executes synchronously and validates numbered
     * parameters after clearing stale metadata.
     * @param Closure(string, array<string, string|int|NumberedList>): ApiResponse $request
     * @internal Obtain this group through WhmClient::features().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * Return available feature names and plugin indicators.
     *
     * Immediately executes read-only WHM get_feature_metadata for the authenticated
     * WHM user, without a cPanel target. Returns an iterable/countable catalog of
     * optional feature records with IDs, names, and plugin flags normalized to 0/1;
     * toArray() retains the pinned integer or observed string '0'/'1' wire values.
     * This is feature catalog information, separate from responseMeta(). Envelope
     * metadata remains on the owning client before API or payload errors.
     * Pinned API-map identity: whm1:get_feature_metadata#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_feature_metadata
     */
    public function metadata(): FeatureCatalog
    {
        $response = ($this->request)('get_feature_metadata', []);

        return FeatureCatalog::fromPayload($response->data, $response->metadata);
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
        $read = self::reader($response);
        $data = $read->object($response->data);
        if (!property_exists($data, 'feature')) {
            return [];
        }

        return ['feature' => $read->listOf(
            $data->feature,
            static fn (mixed $record): array => self::namedFeature($record, $read),
        )];
    }

    /**
     * Return the configuration of a named feature list.
     *
     * Immediately executes read-only WHM get_featurelist_data using the supplied
     * list name and the authenticated WHM user's permissions. Returns the list
     * name and an iterable/countable sequence of feature settings with flags,
     * dependencies, display and standalone settings. The disabled list reverses
     * value's meaning: 1 means excluded and 0 means included. Flag properties are
     * normalized to 0/1 while toArray() preserves the wire values, and regex strings
     * in only_one_rules are never executed here. The pinned schema requires id,
     * is_disabled, and value in each feature; its prose says the disabled list does
     * not use is_disabled. Nullable is_meridian_only remains distinct from omission
     * in toArray(). See FeatureSetting for the observed runtime flag forms. The
     * owning client's responseMeta() retains the envelope before API or payload errors.
     * Pinned API-map identity: whm1:get_featurelist_data#GET.
     *
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_featurelist_data
     */
    public function listData(string $featureList): FeatureListData
    {
        $response = ($this->request)('get_featurelist_data', ['featurelist' => $featureList]);

        return FeatureListData::fromPayload($response->data, $response->metadata);
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
        $read = self::reader($response);
        $data = $read->object($response->data);

        return property_exists($data, 'featurelists') ? ['featurelists' => $read->strings($data->featurelists)] : [];
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
        $read = self::reader($response);
        $data = $read->object($response->data);
        if (!property_exists($data, 'package_types_feature_lists')) {
            return [];
        }

        $types = $read->object($data->package_types_feature_lists);
        $values = ['standard' => $read->strings($read->field($types, 'standard'))];
        if (property_exists($types, 'nova')) {
            $values['nova'] = $read->strings($types->nova);
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
     * string reaches the API, which documents an error. Empty or malformed input
     * lists are rejected before I/O after clearing stale response metadata.
     * Returns an iterable/countable collection of optional records: the account
     * override cpuser_setting keeps its pinned nullable integer flag (null means no
     * override), and feature_list_setting is normalized to 0/1 while toArray()
     * preserves the exact string '0'/'1' observed on test.oblak.host on 2026-10-13.
     * Overrides take precedence over feature_list_setting.
     * The owning client's responseMeta() retains the envelope before API or
     * payload errors. Pinned API-map identity: whm1:get_users_features_settings#GET.
     *
     * @param string|list<string> $user One username or a nonempty list of usernames.
     * @param string|list<string>|null $feature One feature, a nonempty list, or no filter.
     * @throws InvalidArgumentException If an input array is empty or is not a list of strings.
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/get_users_features_settings
     */
    public function userSettings(string|array $user, string|array|null $feature = null): UserFeatureSettings
    {
        $parameters = ['user' => new NumberedList($user)];
        if ($feature !== null) {
            $parameters['feature'] = new NumberedList($feature);
        }

        $response = ($this->request)('get_users_features_settings', $parameters);

        return UserFeatureSettings::fromPayload($response->data, $response->metadata);
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
        $read = self::reader($response);
        $data = $read->object($response->data);
        if (property_exists($data, 'query_feature')) {
            $read->string($data->query_feature);
        }

        return $read->flag($read->field($data, 'has_feature')) === 1;
    }

    /** Bind payload validation failures to this response's snapshot. */
    private static function reader(ApiResponse $response): PayloadReader
    {
        return new PayloadReader($response->metadata, 'WHM feature payload');
    }

    /** @return FeatureName */
    private static function namedFeature(mixed $record, PayloadReader $read): array
    {
        $record = $read->object($record);
        $values = [];
        if (property_exists($record, 'id')) {
            $values['id'] = $read->string($record->id);
        }
        if (property_exists($record, 'name')) {
            $values['name'] = $read->string($record->name);
        }

        return $values;
    }
}
