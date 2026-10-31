<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ResellersSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed reseller inventories using the authenticated default context; no account or privilege changes. */
final class WhmResellersProbes
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
                'whm1:acctcounts',
                'whm',
                ResellersSpec::ACCTCOUNTS,
                'https://test.oblak.host:2087/json-api/acctcounts?api.version=1',
                static fn (Client $client): mixed => $client->whm()->resellers()->accountCounts(),
            ),
            new Probe(
                'whm1:get_public_contact',
                'whm',
                ResellersSpec::GET_PUBLIC_CONTACT,
                'https://test.oblak.host:2087/json-api/get_public_contact?api.version=1',
                static fn (Client $client): mixed => $client->whm()->resellers()->getPublicContact(),
            ),
            new Probe(
                'whm1:getresellerips',
                'whm',
                ResellersSpec::GETRESELLERIPS,
                'https://test.oblak.host:2087/json-api/getresellerips?api.version=1',
                static fn (Client $client): mixed => $client->whm()->resellers()->getResellerIps(),
            ),
            new Probe(
                'whm1:listacls',
                'whm',
                ResellersSpec::LISTACLS,
                'https://test.oblak.host:2087/json-api/listacls?api.version=1',
                static fn (Client $client): mixed => $client->whm()->resellers()->listAcls(),
            ),
            new Probe(
                'whm1:listresellers',
                'whm',
                ResellersSpec::LISTRESELLERS,
                'https://test.oblak.host:2087/json-api/listresellers?api.version=1',
                static fn (Client $client): mixed => $client->whm()->resellers()->listResellers(),
            ),
        ];
    }
}
