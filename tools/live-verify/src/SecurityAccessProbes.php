<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ClamScannerSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ExternalAuthenticationSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\LastLoginSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ModSecuritySpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SshSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed security and access inventory; no scans, disinfection, login or settings changes. */
final class SecurityAccessProbes
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
                ['ClamScanner', 'check_disinfection_status',
                    ClamScannerSpec::CHECK_DISINFECTION_STATUS,
                    static fn (UapiClient $account): mixed =>
                        $account->clamScanner()->checkDisinfectionStatus()],
                ['ClamScanner', 'get_scan_paths',
                    ClamScannerSpec::GET_SCAN_PATHS,
                    static fn (UapiClient $account): mixed =>
                        $account->clamScanner()->getScanPaths()],
                ['ClamScanner', 'get_scan_status',
                    ClamScannerSpec::GET_SCAN_STATUS,
                    static fn (UapiClient $account): mixed =>
                        $account->clamScanner()->getScanStatus()],
                ['ClamScanner', 'list_infected_files',
                    ClamScannerSpec::LIST_INFECTED_FILES,
                    static fn (UapiClient $account): mixed =>
                        $account->clamScanner()->listInfectedFiles()],
                ['ModSecurity', 'has_modsecurity_installed',
                    ModSecuritySpec::HAS_MODSECURITY_INSTALLED,
                    static fn (UapiClient $account): mixed =>
                        $account->modSecurity()->hasModsecurityInstalled()],
                ['ModSecurity', 'list_domains',
                    ModSecuritySpec::LIST_DOMAINS,
                    static fn (UapiClient $account): mixed =>
                        $account->modSecurity()->listDomains()],
                ['ExternalAuthentication', 'configured_modules',
                    ExternalAuthenticationSpec::CONFIGURED_MODULES,
                    static fn (UapiClient $account): mixed =>
                        $account->externalAuthentication()->configuredModules()],
                ['ExternalAuthentication', 'get_authn_links',
                    ExternalAuthenticationSpec::GET_AUTHN_LINKS,
                    static fn (UapiClient $account): mixed =>
                        $account->externalAuthentication()->getAuthnLinks()],
                ['ExternalAuthentication', 'has_external_auth_modules_configured',
                    ExternalAuthenticationSpec::HAS_EXTERNAL_AUTH_MODULES_CONFIGURED,
                    static fn (UapiClient $account): mixed =>
                        $account->externalAuthentication()->hasExternalAuthModulesConfigured()],
                ['SSH', 'get_port',
                    SshSpec::GET_PORT,
                    static fn (UapiClient $account): mixed =>
                        $account->ssh()->getPort()],
                ['SSH', 'get_shell',
                    SshSpec::GET_SHELL,
                    static fn (UapiClient $account): mixed =>
                        $account->ssh()->getShell()],
                ['LastLogin', 'get_last_or_current_logged_in_ip',
                    LastLoginSpec::GET_LAST_OR_CURRENT_LOGGED_IN_IP,
                    static fn (UapiClient $account): mixed =>
                        $account->lastLogin()->getLastOrCurrentLoggedInIp()],
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
