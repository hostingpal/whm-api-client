<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\IntegrationsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed integration-group inventory; no hook commands, token links or configuration changes. */
final class WhmIntegrationsProbes
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
                'whm1:list_integration_groups',
                'whm',
                IntegrationsSpec::LIST_INTEGRATION_GROUPS,
                'https://test.oblak.host:2087/json-api/list_integration_groups?api.version=1&user=npr',
                static fn (Client $client): mixed => $client->whm()->integrations()->listIntegrationGroups('npr'),
            ),
        ];
    }
}
