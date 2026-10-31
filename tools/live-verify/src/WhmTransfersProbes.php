<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\TransfersSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed local module and designated-user reads; no remote contact or transfers. */
final class WhmTransfersProbes
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
                'whm1:available_transfer_modules',
                'whm',
                TransfersSpec::AVAILABLE_TRANSFER_MODULES,
                'https://test.oblak.host:2087/json-api/available_transfer_modules?api.version=1',
                static fn (Client $client): mixed => $client->whm()->transfers()->availableTransferModules(),
            ),
            new Probe(
                'whm1:transfer_module_schema',
                'whm',
                TransfersSpec::TRANSFER_MODULE_SCHEMA,
                'https://test.oblak.host:2087/json-api/transfer_module_schema?api.version=1'
                . '&module=FeatureListRemoteRoot',
                static fn (Client $client): mixed => $client->whm()->transfers()->transferModuleSchema(
                    module: 'FeatureListRemoteRoot',
                ),
                argumentVariant: 'feature-list-remote-root',
            ),
            new Probe(
                'whm1:validate_system_user',
                'whm',
                TransfersSpec::VALIDATE_SYSTEM_USER,
                'https://test.oblak.host:2087/json-api/validate_system_user?api.version=1&user=npr',
                static fn (Client $client): mixed => $client->whm()->transfers()->validateSystemUser('npr'),
            ),
        ];
    }
}
