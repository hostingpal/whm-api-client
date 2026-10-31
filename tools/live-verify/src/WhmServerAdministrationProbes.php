<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed configuration inventories; excludes credentials, network probes, notifications and mutations. */
final class WhmServerAdministrationProbes
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
                'whm1:get_all_contact_importances',
                'whm',
                ServerSpec::GET_ALL_CONTACT_IMPORTANCES,
                'https://test.oblak.host:2087/json-api/get_all_contact_importances?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->getAllContactImportances(),
            ),
            new Probe(
                'whm1:get_available_profiles',
                'whm',
                ServerSpec::GET_AVAILABLE_PROFILES,
                'https://test.oblak.host:2087/json-api/get_available_profiles?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->getAvailableProfiles(),
            ),
            new Probe(
                'whm1:get_current_profile',
                'whm',
                ServerSpec::GET_CURRENT_PROFILE,
                'https://test.oblak.host:2087/json-api/get_current_profile?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->getCurrentProfile(),
            ),
            new Probe(
                'whm1:getminimumpasswordstrengths',
                'whm',
                ServerSpec::GETMINIMUMPASSWORDSTRENGTHS,
                'https://test.oblak.host:2087/json-api/getminimumpasswordstrengths?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->getMinimumPasswordStrengths(),
            ),
            new Probe(
                'whm1:installed_versions',
                'whm',
                ServerSpec::INSTALLED_VERSIONS,
                'https://test.oblak.host:2087/json-api/installed_versions?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->installedVersions(),
            ),
            new Probe(
                'whm1:servicestatus',
                'whm',
                ServerSpec::SERVICESTATUS,
                'https://test.oblak.host:2087/json-api/servicestatus?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->serviceStatus(),
            ),
            new Probe(
                'whm1:wp_dashboard_get_hostname',
                'whm',
                ServerSpec::WP_DASHBOARD_GET_HOSTNAME,
                'https://test.oblak.host:2087/json-api/wp_dashboard_get_hostname?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->wpDashboardGetHostname(),
            ),
        ];
    }
}
