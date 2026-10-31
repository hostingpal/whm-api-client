<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\IpAddressesSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed address inventories; no NAT checks, account moves, routing or network-interface changes. */
final class WhmIpAddressesProbes
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
                'whm1:get_shared_ip',
                'whm',
                IpAddressesSpec::GET_SHARED_IP,
                'https://test.oblak.host:2087/json-api/get_shared_ip?api.version=1',
                static fn (Client $client): mixed => $client->whm()->ipAddresses()->getSharedIp(),
            ),
            new Probe(
                'whm1:ipv6_range_list',
                'whm',
                IpAddressesSpec::IPV6_RANGE_LIST,
                'https://test.oblak.host:2087/json-api/ipv6_range_list?api.version=1',
                static fn (Client $client): mixed => $client->whm()->ipAddresses()->listIpv6Ranges(),
            ),
            new Probe(
                'whm1:listips',
                'whm',
                IpAddressesSpec::LISTIPS,
                'https://test.oblak.host:2087/json-api/listips?api.version=1',
                static fn (Client $client): mixed => $client->whm()->ipAddresses()->listIps(),
            ),
            new Probe(
                'whm1:listipv6s',
                'whm',
                IpAddressesSpec::LISTIPV6S,
                'https://test.oblak.host:2087/json-api/listipv6s?api.version=1',
                static fn (Client $client): mixed => $client->whm()->ipAddresses()->listIpv6s(),
            ),
        ];
    }
}
