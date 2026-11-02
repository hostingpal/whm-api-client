<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ActiveSessionsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\WebmailAppsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/**
 * Fixed session and Webmail application inventory; no Session creator, login or returned URL fetch.
 *
 * Both operations take no reviewed arguments, so the server's default theme applies. Session/* creators stay
 * outside this catalog: session files can outlive a removed account and need filesystem access to clean up.
 */
final class WebmailInventoryProbes
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
                ['ActiveSessions', 'list_active_sessions', ActiveSessionsSpec::LIST_ACTIVE_SESSIONS,
                    static fn (UapiClient $account): mixed => $account->activeSessions()->listActiveSessions()],
                ['WebmailApps', 'list_webmail_apps', WebmailAppsSpec::LIST_WEBMAIL_APPS,
                    static fn (UapiClient $account): mixed => $account->webmailApps()->listWebmailApps()],
            ] as [$module, $function, $spec, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
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
            static fn (Client $client): mixed => $invoke($client->uapi($route === 'direct-uapi' ? null : 'npr')),
        );
    }
}
