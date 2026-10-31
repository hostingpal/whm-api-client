<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\SystemPackagesSpec;
use HostingPal\WhmApiClient\Client;

/**
 * Fixed new review reads, separate from the original package inventory capture.
 *
 * The ea namespace and package selector are read-only inputs. target_settings
 * exercises string-valued RPM keys and rpm_groups exercises string lists. Only
 * type structure is captured; package names, dependency alternatives and vendor
 * values remain inert payload data. No package actions or cache repair execute.
 *
 * @see https://github.com/hostingpal/whm-api-client/pull/60#pullrequestreview-5721000633
 */
final class WhmSystemPackagesReviewProbes
{
    /** @return list<Probe> */
    public static function load(): array
    {
        return [
            new Probe(
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->version(),
            ),
            new Probe(
                'whm1:package_manager_get_package_info',
                'whm',
                SystemPackagesSpec::PACKAGE_MANAGER_GET_PACKAGE_INFO,
                'https://test.oblak.host:2087/json-api/package_manager_get_package_info?api.version=1'
                . '&package=ea-apache24',
                static fn (Client $client): mixed => $client->whm()->systemPackages()->getPackageInfo(
                    packages: 'ea-apache24',
                ),
                argumentVariant: 'ea-apache24',
            ),
            new Probe(
                'whm1:get_rpm_version_data',
                'whm',
                SystemPackagesSpec::GET_RPM_VERSION_DATA,
                'https://test.oblak.host:2087/json-api/get_rpm_version_data?api.version=1&section=target_settings',
                static fn (Client $client): mixed => $client->whm()->systemPackages()->getRpmVersionData(
                    section: 'target_settings',
                ),
                argumentVariant: 'target-settings',
            ),
            new Probe(
                'whm1:get_rpm_version_data',
                'whm',
                SystemPackagesSpec::GET_RPM_VERSION_DATA,
                'https://test.oblak.host:2087/json-api/get_rpm_version_data?api.version=1&section=rpm_groups',
                static fn (Client $client): mixed => $client->whm()->systemPackages()->getRpmVersionData(
                    section: 'rpm_groups',
                ),
                argumentVariant: 'rpm-groups',
            ),
            new Probe(
                'whm1:package_manager_resolve_actions',
                'whm',
                SystemPackagesSpec::PACKAGE_MANAGER_RESOLVE_ACTIONS,
                'https://test.oblak.host:2087/json-api/package_manager_resolve_actions?api.version=1'
                . '&package=ea-apache24&ns=ea',
                static fn (Client $client): mixed => $client->whm()->systemPackages()->resolveActions(
                    packages: 'ea-apache24',
                    namespaces: 'ea',
                ),
                argumentVariant: 'ea-apache24-ea',
            ),
        ];
    }
}
