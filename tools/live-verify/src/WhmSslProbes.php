<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\SslSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed SSL metadata inventories; no private material, provider payloads or certificate changes. */
final class WhmSslProbes
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
                'whm1:get_autossl_check_schedule',
                'whm',
                SslSpec::GET_AUTOSSL_CHECK_SCHEDULE,
                'https://test.oblak.host:2087/json-api/get_autossl_check_schedule?api.version=1',
                static fn (Client $client): mixed => $client->whm()->ssl()->getAutoSslCheckSchedule(),
            ),
            new Probe(
                'whm1:get_autossl_logs_catalog',
                'whm',
                SslSpec::GET_AUTOSSL_LOGS_CATALOG,
                'https://test.oblak.host:2087/json-api/get_autossl_logs_catalog?api.version=1',
                static fn (Client $client): mixed => $client->whm()->ssl()->getAutoSslLogsCatalog(),
            ),
            new Probe(
                'whm1:get_autossl_metadata',
                'whm',
                SslSpec::GET_AUTOSSL_METADATA,
                'https://test.oblak.host:2087/json-api/get_autossl_metadata?api.version=1',
                static fn (Client $client): mixed => $client->whm()->ssl()->getAutoSslMetadata(),
            ),
            new Probe(
                'whm1:get_autossl_problems_for_domain',
                'whm',
                SslSpec::GET_AUTOSSL_PROBLEMS_FOR_DOMAIN,
                'https://test.oblak.host:2087/json-api/get_autossl_problems_for_domain?api.version=1&domain=npr.rs',
                static fn (Client $client): mixed => $client->whm()->ssl()->getAutoSslProblemsForDomain('npr.rs'),
            ),
            new Probe(
                'whm1:get_autossl_problems_for_user',
                'whm',
                SslSpec::GET_AUTOSSL_PROBLEMS_FOR_USER,
                'https://test.oblak.host:2087/json-api/get_autossl_problems_for_user?api.version=1&username=npr',
                static fn (Client $client): mixed => $client->whm()->ssl()->getAutoSslProblemsForUser('npr'),
            ),
            new Probe(
                'whm1:get_autossl_user_excluded_domains',
                'whm',
                SslSpec::GET_AUTOSSL_USER_EXCLUDED_DOMAINS,
                'https://test.oblak.host:2087/json-api/get_autossl_user_excluded_domains?api.version=1&username=npr',
                static fn (Client $client): mixed => $client->whm()->ssl()->getAutoSslUserExcludedDomains('npr'),
            ),
            new Probe(
                'whm1:get_best_ssldomain_for_service',
                'whm',
                SslSpec::GET_BEST_SSLDOMAIN_FOR_SERVICE,
                'https://test.oblak.host:2087/json-api/get_best_ssldomain_for_service?api.version=1&service=whostmgr',
                static fn (Client $client): mixed => $client->whm()->ssl()->getBestSslDomainForService('whostmgr'),
            ),
            new Probe(
                'whm1:listcrts',
                'whm',
                SslSpec::LISTCRTS,
                'https://test.oblak.host:2087/json-api/listcrts?api.version=1&user=npr',
                static fn (Client $client): mixed => $client->whm()->ssl()->listCertificates(user: 'npr'),
            ),
        ];
    }
}
