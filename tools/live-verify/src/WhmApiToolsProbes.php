<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ApiToolsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed API inventory and usage metadata; no token secrets or session changes. */
final class WhmApiToolsProbes
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
                'whm1:applist',
                'whm',
                ApiToolsSpec::APPLIST,
                'https://test.oblak.host:2087/json-api/applist?api.version=1',
                static fn (Client $client): mixed => $client->whm()->apiTools()->listApplications(),
            ),
            new Probe(
                'whm1:api_token_list',
                'whm',
                ApiToolsSpec::API_TOKEN_LIST,
                'https://test.oblak.host:2087/json-api/api_token_list?api.version=1',
                static fn (Client $client): mixed => $client->whm()->apiTools()->listTokens(),
            ),
            new Probe(
                'whm1:get_api_calls',
                'whm',
                ApiToolsSpec::GET_API_CALLS,
                'https://test.oblak.host:2087/json-api/get_api_calls?api.version=1',
                static fn (Client $client): mixed => $client->whm()->apiTools()->getApiCalls(),
            ),
            new Probe(
                'whm1:get_api_pages',
                'whm',
                ApiToolsSpec::GET_API_PAGES,
                'https://test.oblak.host:2087/json-api/get_api_pages?api.version=1',
                static fn (Client $client): mixed => $client->whm()->apiTools()->getApiPages(),
            ),
        ];
    }
}
