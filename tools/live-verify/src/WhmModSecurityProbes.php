<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ModSecuritySpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed inventory reads; excludes rule text, logs, secret-bearing settings and every mutation. */
final class WhmModSecurityProbes
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
                'whm1:modsec_get_configs',
                'whm',
                ModSecuritySpec::MODSEC_GET_CONFIGS,
                'https://test.oblak.host:2087/json-api/modsec_get_configs?api.version=1',
                static fn (Client $client): mixed => $client->whm()->modSecurity()->getConfigs(),
            ),
            new Probe(
                'whm1:modsec_get_configs_with_changes_pending',
                'whm',
                ModSecuritySpec::MODSEC_GET_CONFIGS_WITH_CHANGES_PENDING,
                'https://test.oblak.host:2087/json-api/modsec_get_configs_with_changes_pending?api.version=1',
                static fn (Client $client): mixed => $client->whm()->modSecurity()->getConfigsWithChangesPending(),
            ),
            new Probe(
                'whm1:modsec_get_vendors',
                'whm',
                ModSecuritySpec::MODSEC_GET_VENDORS,
                'https://test.oblak.host:2087/json-api/modsec_get_vendors?api.version=1',
                static fn (Client $client): mixed => $client->whm()->modSecurity()->getVendors(),
            ),
            new Probe(
                'whm1:modsec_is_installed',
                'whm',
                ModSecuritySpec::MODSEC_IS_INSTALLED,
                'https://test.oblak.host:2087/json-api/modsec_is_installed?api.version=1',
                static fn (Client $client): mixed => $client->whm()->modSecurity()->isInstalled(),
            ),
        ];
    }
}
