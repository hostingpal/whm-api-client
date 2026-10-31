<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\WebServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed PHP and EasyApache inventory; no INI content or configuration changes. */
final class WhmWebServerConfigurationProbes
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
                'whm1:ea4_get_additional_pkg_prefixes',
                'whm',
                WebServerSpec::EA4_GET_ADDITIONAL_PKG_PREFIXES,
                'https://test.oblak.host:2087/json-api/ea4_get_additional_pkg_prefixes?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->ea4GetAdditionalPkgPrefixes(),
            ),
            new Probe(
                'whm1:ea4_get_currently_installed_packages',
                'whm',
                WebServerSpec::EA4_GET_CURRENTLY_INSTALLED_PACKAGES,
                'https://test.oblak.host:2087/json-api/ea4_get_currently_installed_packages?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->ea4GetCurrentlyInstalledPackages(),
            ),
            new Probe(
                'whm1:ea4_list_profiles',
                'whm',
                WebServerSpec::EA4_LIST_PROFILES,
                'https://test.oblak.host:2087/json-api/ea4_list_profiles?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->ea4ListProfiles(),
            ),
            new Probe(
                'whm1:ea4_metainfo',
                'whm',
                WebServerSpec::EA4_METAINFO,
                'https://test.oblak.host:2087/json-api/ea4_metainfo?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->ea4Metainfo(),
            ),
            new Probe(
                'whm1:ea4_recommendations',
                'whm',
                WebServerSpec::EA4_RECOMMENDATIONS,
                'https://test.oblak.host:2087/json-api/ea4_recommendations?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->ea4Recommendations(),
            ),
            new Probe(
                'whm1:eol_php_sites',
                'whm',
                WebServerSpec::EOL_PHP_SITES,
                'https://test.oblak.host:2087/json-api/eol_php_sites?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->eolPhpSites(),
            ),
            new Probe(
                'whm1:get_fpm_count_and_utilization',
                'whm',
                WebServerSpec::GET_FPM_COUNT_AND_UTILIZATION,
                'https://test.oblak.host:2087/json-api/get_fpm_count_and_utilization?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->getFpmCountAndUtilization(),
            ),
            new Probe(
                'whm1:php_get_vhost_versions',
                'whm',
                WebServerSpec::PHP_GET_VHOST_VERSIONS,
                'https://test.oblak.host:2087/json-api/php_get_vhost_versions?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->phpGetVhostVersions(),
            ),
        ];
    }
}
