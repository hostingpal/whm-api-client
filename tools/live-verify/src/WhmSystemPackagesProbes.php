<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\SystemPackagesSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed package inventories and prospective action reads; no installation, update, cache repair or build-log reads. */
final class WhmSystemPackagesProbes
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
                'whm1:list_rpms',
                'whm',
                SystemPackagesSpec::LIST_RPMS,
                'https://test.oblak.host:2087/json-api/list_rpms?api.version=1',
                static fn (Client $client): mixed => $client->whm()->systemPackages()->listRpms(),
            ),
            new Probe(
                'whm1:package_manager_list_packages',
                'whm',
                SystemPackagesSpec::PACKAGE_MANAGER_LIST_PACKAGES,
                'https://test.oblak.host:2087/json-api/package_manager_list_packages?api.version=1&state=installed',
                static fn (Client $client): mixed => $client->whm()->systemPackages()->listPackages(state: 'installed'),
            ),
            ...array_slice(WhmSystemPackagesReviewProbes::load(), 1),
        ];
    }
}
