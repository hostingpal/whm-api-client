<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\BackupSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\RestoreSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\WordPressBackupSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\WordPressRestoreSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed backup inventory and task status reads; no backup, extraction or restoration writes. */
final class AccountBackupProbes
{
    /** @return list<Probe> */
    public static function load(): array
    {
        $probes = [new Probe(
            'whm1:version',
            'whm',
            ServerSpec::VERSION,
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            static fn (Client $client): mixed => $client->whm()->server()->version(),
        )];
        foreach (
            [
                ['Backup', 'list_backups',
                    BackupSpec::LIST_BACKUPS,
                    static fn (UapiClient $account): mixed =>
                        $account->backup()->listBackups()],
                ['Restore', 'get_users',
                    RestoreSpec::GET_USERS,
                    static fn (UapiClient $account): mixed =>
                        $account->restore()->getUsers()],
                ['WordPressBackup', 'any_running',
                    WordPressBackupSpec::ANY_RUNNING,
                    static fn (UapiClient $account): mixed =>
                        $account->wordPressBackup()->anyRunning()],
                ['WordPressRestore', 'any_running',
                    WordPressRestoreSpec::ANY_RUNNING,
                    static fn (UapiClient $account): mixed =>
                        $account->wordPressRestore()->anyRunning()],
            ] as [$module, $function, $spec, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $uri = $route === 'direct-uapi'
                    ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
                $probes[] = self::probe($module, $function, $route, $spec, $uri, $invoke);
            }
        }

        return $probes;
    }

    /**
     * @param 'direct-uapi'|'bridged-uapi' $route
     * @param array<mixed> $spec
     * @param Closure(UapiClient): mixed $invoke
     */
    private static function probe(
        string $module,
        string $function,
        string $route,
        array $spec,
        string $uri,
        Closure $invoke,
    ): Probe {
        return new Probe(
            'uapi:' . $module . '/' . $function,
            $route,
            $spec,
            $uri,
            static fn (Client $client): mixed => $invoke(
                $route === 'direct-uapi' ? $client->uapi() : $client->uapi('npr'),
            ),
        );
    }
}
