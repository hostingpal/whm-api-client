<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\NginxManagerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed NGINX configuration reads; no cache changes or all-user inventory. */
final class WhmNginxProbes
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
                'whm1:nginxmanager_get_cache_config_system',
                'whm',
                NginxManagerSpec::NGINXMANAGER_GET_CACHE_CONFIG_SYSTEM,
                'https://test.oblak.host:2087/json-api/nginxmanager_get_cache_config_system?api.version=1&merge=1',
                static fn (Client $client): mixed => $client->whm()->nginxManager()->getSystemCacheConfig(merge: true),
            ),
            new Probe(
                'whm1:nginxmanager_get_cache_config_users',
                'whm',
                NginxManagerSpec::NGINXMANAGER_GET_CACHE_CONFIG_USERS,
                'https://test.oblak.host:2087/json-api/nginxmanager_get_cache_config_users'
                . '?api.version=1&user=npr&merge=1',
                static fn (Client $client): mixed => $client->whm()->nginxManager()->getUsersCacheConfig(
                    merge: true,
                    users: 'npr',
                ),
            ),
        ];
    }
}
