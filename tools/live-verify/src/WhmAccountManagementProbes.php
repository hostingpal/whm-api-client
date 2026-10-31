<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\AccountsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed account inventories and policy metadata; no credentials, links or account changes. */
final class WhmAccountManagementProbes
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
                'whm1:get_current_users_count',
                'whm',
                AccountsSpec::GET_CURRENT_USERS_COUNT,
                'https://test.oblak.host:2087/json-api/get_current_users_count?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->getCurrentUsersCount(),
            ),
            new Probe(
                'whm1:get_disk_usage',
                'whm',
                AccountsSpec::GET_DISK_USAGE,
                'https://test.oblak.host:2087/json-api/get_disk_usage?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->getDiskUsage(),
            ),
            new Probe(
                'whm1:get_homedir_roots',
                'whm',
                AccountsSpec::GET_HOMEDIR_ROOTS,
                'https://test.oblak.host:2087/json-api/get_homedir_roots?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->getHomedirRoots(),
            ),
            new Probe(
                'whm1:get_maximum_users',
                'whm',
                AccountsSpec::GET_MAXIMUM_USERS,
                'https://test.oblak.host:2087/json-api/get_maximum_users?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->getMaximumUsers(),
            ),
            new Probe(
                'whm1:list_account_enhancements',
                'whm',
                AccountsSpec::LIST_ACCOUNT_ENHANCEMENTS,
                'https://test.oblak.host:2087/json-api/list_account_enhancements?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->listAccountEnhancements(),
            ),
            new Probe(
                'whm1:list_users',
                'whm',
                AccountsSpec::LIST_USERS,
                'https://test.oblak.host:2087/json-api/list_users?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->listUsers(),
            ),
            new Probe(
                'whm1:myprivs',
                'whm',
                AccountsSpec::MYPRIVS,
                'https://test.oblak.host:2087/json-api/myprivs?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->myPrivileges(),
            ),
            new Probe(
                'whm1:quota_enabled',
                'whm',
                AccountsSpec::QUOTA_ENABLED,
                'https://test.oblak.host:2087/json-api/quota_enabled?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->quotaEnabled(),
            ),
        ];
    }
}
