<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\DatabasesSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed WHM database inventory and status reads; no passwords, profiles or upgrades are changed. */
final class WhmDatabasesProbes
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
                'whm1:current_mysql_version',
                'whm',
                DatabasesSpec::CURRENT_MYSQL_VERSION,
                'https://test.oblak.host:2087/json-api/current_mysql_version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->databases()->currentMysqlVersion(),
            ),
            new Probe(
                'whm1:get_database_optimizations',
                'whm',
                DatabasesSpec::GET_DATABASE_OPTIMIZATIONS,
                'https://test.oblak.host:2087/json-api/get_database_optimizations?api.version=1',
                static fn (Client $client): mixed => $client->whm()->databases()->getDatabaseOptimizations(),
            ),
            new Probe(
                'whm1:installable_mysql_versions',
                'whm',
                DatabasesSpec::INSTALLABLE_MYSQL_VERSIONS,
                'https://test.oblak.host:2087/json-api/installable_mysql_versions?api.version=1',
                static fn (Client $client): mixed => $client->whm()->databases()->installableMysqlVersions(),
            ),
            new Probe(
                'whm1:latest_available_mysql_version',
                'whm',
                DatabasesSpec::LATEST_AVAILABLE_MYSQL_VERSION,
                'https://test.oblak.host:2087/json-api/latest_available_mysql_version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->databases()->latestAvailableMysqlVersion(),
            ),
            new Probe(
                'whm1:list_database_users',
                'whm',
                DatabasesSpec::LIST_DATABASE_USERS,
                'https://test.oblak.host:2087/json-api/list_database_users?api.version=1',
                static fn (Client $client): mixed => $client->whm()->databases()->listDatabaseUsers(),
            ),
            new Probe(
                'whm1:list_databases',
                'whm',
                DatabasesSpec::LIST_DATABASES,
                'https://test.oblak.host:2087/json-api/list_databases?api.version=1',
                static fn (Client $client): mixed => $client->whm()->databases()->listDatabases(),
            ),
            new Probe(
                'whm1:list_mysql_databases_and_users',
                'whm',
                DatabasesSpec::LIST_MYSQL_DATABASES_AND_USERS,
                'https://test.oblak.host:2087/json-api/list_mysql_databases_and_users?api.version=1&user=npr',
                static fn (Client $client): mixed =>
                    $client->whm()->databases()->listMysqlDatabasesAndUsers(user: 'npr'),
            ),
            new Probe(
                'whm1:remote_mysql_monitor_profile_activation',
                'whm',
                DatabasesSpec::REMOTE_MYSQL_MONITOR_PROFILE_ACTIVATION,
                'https://test.oblak.host:2087/json-api/remote_mysql_monitor_profile_activation?api.version=1',
                static fn (Client $client): mixed => $client->whm()->databases()->remoteMysqlMonitorProfileActivation(),
            ),
        ];
    }
}
