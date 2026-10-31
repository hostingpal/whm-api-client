<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\CpHulkSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\MailSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed protection inventories; no authentication logs, message records, commands or policy changes. */
final class WhmLoginMailProtectionProbes
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
                'whm1:cphulk_status',
                'whm',
                CpHulkSpec::CPHULK_STATUS,
                'https://test.oblak.host:2087/json-api/cphulk_status?api.version=1',
                static fn (Client $client): mixed => $client->whm()->cpHulk()->status(),
            ),
            new Probe(
                'whm1:get_countries_with_known_ip_ranges',
                'whm',
                CpHulkSpec::GET_COUNTRIES_WITH_KNOWN_IP_RANGES,
                'https://test.oblak.host:2087/json-api/get_countries_with_known_ip_ranges?api.version=1',
                static fn (Client $client): mixed => $client->whm()->cpHulk()->getCountriesWithKnownIpRanges(),
            ),
            new Probe(
                'whm1:cpgreylist_status',
                'whm',
                MailSpec::CPGREYLIST_STATUS,
                'https://test.oblak.host:2087/json-api/cpgreylist_status?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->cpgreylistStatus(),
            ),
            new Probe(
                'whm1:cpgreylist_is_server_netblock_trusted',
                'whm',
                MailSpec::CPGREYLIST_IS_SERVER_NETBLOCK_TRUSTED,
                'https://test.oblak.host:2087/json-api/cpgreylist_is_server_netblock_trusted?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->cpgreylistIsServerNetblockTrusted(),
            ),
            new Probe(
                'whm1:cpgreylist_load_common_mail_providers_config',
                'whm',
                MailSpec::CPGREYLIST_LOAD_COMMON_MAIL_PROVIDERS_CONFIG,
                'https://test.oblak.host:2087/json-api/cpgreylist_load_common_mail_providers_config?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->cpgreylistLoadCommonMailProvidersConfig(),
            ),
            new Probe(
                'whm1:load_cpgreylist_config',
                'whm',
                MailSpec::LOAD_CPGREYLIST_CONFIG,
                'https://test.oblak.host:2087/json-api/load_cpgreylist_config?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->loadCpgreylistConfig(),
            ),
            new Probe(
                'whm1:read_cpgreylist_trusted_hosts',
                'whm',
                MailSpec::READ_CPGREYLIST_TRUSTED_HOSTS,
                'https://test.oblak.host:2087/json-api/read_cpgreylist_trusted_hosts?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->readCpgreylistTrustedHosts(),
            ),
        ];
    }
}
