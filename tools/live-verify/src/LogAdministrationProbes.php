<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\LogManagerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\LogsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed log metadata only; no existing log contents, archive deletion or settings changes. */
final class LogAdministrationProbes
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
        ), new Probe(
            'whm1:list_accounts_retention',
            'whm',
            LogsSpec::LIST_ACCOUNTS_RETENTION,
            'https://test.oblak.host:2087/json-api/list_accounts_retention?api.version=1',
            static fn (Client $client): mixed => $client->whm()->logs()->listAccountsRetention(),
        )];
        foreach (
            [
                ['LogManager', 'get_settings', LogManagerSpec::GET_SETTINGS,
                    static fn (UapiClient $account): mixed => $account->logManager()->getSettings()],
                ['LogManager', 'list_archives', LogManagerSpec::LIST_ARCHIVES,
                    static fn (UapiClient $account): mixed => $account->logManager()->listArchives()],
                ['LogManager', 'list_error_logs', LogManagerSpec::LIST_ERROR_LOGS,
                    static fn (UapiClient $account): mixed => $account->logManager()->listErrorLogs('npr.rs')],
            ] as [$module, $function, $spec, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
                    ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
                if ($function === 'list_error_logs') {
                    $uri .= ($direct ? '?' : '&') . 'domain=npr.rs';
                }
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
            static fn (Client $client): mixed => $invoke($client->uapi($route === 'direct-uapi' ? null : 'npr')),
        );
    }
}
