<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\MarketSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed provider inventory; no commission IDs, OAuth exchange or market changes. */
final class WhmMarketProbes
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
                'whm1:get_market_providers_list',
                'whm',
                MarketSpec::GET_MARKET_PROVIDERS_LIST,
                'https://test.oblak.host:2087/json-api/get_market_providers_list?api.version=1',
                static fn (Client $client): mixed => $client->whm()->market()->getProvidersList(),
            ),
        ];
    }
}
