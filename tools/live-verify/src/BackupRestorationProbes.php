<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\AccountRestorationSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\BackupsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed WHM backup inventory and restoration status reads; no backup or queue writes. */
final class BackupRestorationProbes
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
                'whm1:backup_config_get',
                'whm',
                BackupsSpec::BACKUP_CONFIG_GET,
                'https://test.oblak.host:2087/json-api/backup_config_get?api.version=1',
                static fn (Client $client): mixed => $client->whm()->backups()->backupConfigGet(),
            ),
            new Probe(
                'whm1:get_users_and_domains_with_backup_metadata',
                'whm',
                BackupsSpec::GET_USERS_AND_DOMAINS_WITH_BACKUP_METADATA,
                'https://test.oblak.host:2087/json-api/get_users_and_domains_with_backup_metadata?api.version=1',
                static fn (Client $client): mixed => $client->whm()->backups()->getUsersAndDomainsWithBackupMetadata(),
            ),
            new Probe(
                'whm1:get_users_with_backup_metadata',
                'whm',
                BackupsSpec::GET_USERS_WITH_BACKUP_METADATA,
                'https://test.oblak.host:2087/json-api/get_users_with_backup_metadata?api.version=1',
                static fn (Client $client): mixed => $client->whm()->backups()->getUsersWithBackupMetadata(),
            ),
            new Probe(
                'whm1:list_cparchive_files',
                'whm',
                BackupsSpec::LIST_CPARCHIVE_FILES,
                'https://test.oblak.host:2087/json-api/list_cparchive_files?api.version=1',
                static fn (Client $client): mixed => $client->whm()->backups()->listCparchiveFiles(),
            ),
            new Probe(
                'whm1:restore_modules_summary',
                'whm',
                AccountRestorationSpec::RESTORE_MODULES_SUMMARY,
                'https://test.oblak.host:2087/json-api/restore_modules_summary?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accountRestoration()->restoreModulesSummary(),
            ),
            new Probe(
                'whm1:restore_queue_is_active',
                'whm',
                AccountRestorationSpec::RESTORE_QUEUE_IS_ACTIVE,
                'https://test.oblak.host:2087/json-api/restore_queue_is_active?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accountRestoration()->restoreQueueIsActive(),
            ),
            new Probe(
                'whm1:restore_queue_list_active',
                'whm',
                AccountRestorationSpec::RESTORE_QUEUE_LIST_ACTIVE,
                'https://test.oblak.host:2087/json-api/restore_queue_list_active?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accountRestoration()->restoreQueueListActive(),
            ),
            new Probe(
                'whm1:restore_queue_list_completed',
                'whm',
                AccountRestorationSpec::RESTORE_QUEUE_LIST_COMPLETED,
                'https://test.oblak.host:2087/json-api/restore_queue_list_completed?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accountRestoration()->restoreQueueListCompleted(),
            ),
            new Probe(
                'whm1:restore_queue_list_pending',
                'whm',
                AccountRestorationSpec::RESTORE_QUEUE_LIST_PENDING,
                'https://test.oblak.host:2087/json-api/restore_queue_list_pending?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accountRestoration()->restoreQueueListPending(),
            ),
            new Probe(
                'whm1:restore_queue_state',
                'whm',
                AccountRestorationSpec::RESTORE_QUEUE_STATE,
                'https://test.oblak.host:2087/json-api/restore_queue_state?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accountRestoration()->restoreQueueState(),
            ),
        ];
    }
}
