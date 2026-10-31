<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\HostingPlansSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed parameterless hosting-plan inventory; no package or feature changes. */
final class WhmHostingPlansProbes
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
                'whm1:matchpkgs',
                'whm',
                HostingPlansSpec::MATCHPKGS,
                'https://test.oblak.host:2087/json-api/matchpkgs?api.version=1',
                static fn (Client $client): mixed => $client->whm()->hostingPlans()->matchPackages(),
            ),
        ];
    }
}
