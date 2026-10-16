<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\EmailSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\LangPhpSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SslSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\AccountsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\WebServerSpec;
use HostingPal\WhmApiClient\Client;
use JsonException;
use RuntimeException;

/** Bind overlay recipes to reviewed generated calls; recipes never execute arbitrary methods or arguments. */
final class ProbeCatalog
{
    /** @return list<Probe> */
    public static function load(string $root): array
    {
        $server = self::read($root . '/docs/api/overlay/whm/server-administration.json');
        $domains = self::read($root . '/docs/api/overlay/uapi/DomainInfo.json');
        $email = self::read($root . '/docs/api/overlay/uapi/Email.json');
        $accounts = self::read($root . '/docs/api/overlay/whm/accounts.json');
        $webServer = self::read($root . '/docs/api/overlay/whm/web-server-configuration.json');
        $php = self::read($root . '/docs/api/overlay/uapi/LangPHP.json');
        $ssl = self::read($root . '/docs/api/overlay/uapi/SSL.json');

        return [
            self::probe(
                $server,
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->version(),
            ),
            self::probe(
                $server,
                'whm1:systemloadavg',
                'whm',
                ServerSpec::SYSTEMLOADAVG,
                'https://test.oblak.host:2087/json-api/systemloadavg?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->getLoadAverage(),
            ),
            self::probe(
                $domains,
                'uapi:DomainInfo/list_domains',
                'direct-uapi',
                DomainInfoSpec::LIST_DOMAINS,
                'https://test.oblak.host:2083/execute/DomainInfo/list_domains',
                static fn (Client $client): mixed => $client->uapi()->domainInfo()->listDomains(),
            ),
            self::probe(
                $domains,
                'uapi:DomainInfo/list_domains',
                'bridged-uapi',
                DomainInfoSpec::LIST_DOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=list_domains',
                static fn (Client $client): mixed => $client->uapi('npr')->domainInfo()->listDomains(),
            ),
            self::probe(
                $email,
                'uapi:Email/list_pops',
                'direct-uapi',
                EmailSpec::LIST_POPS,
                'https://test.oblak.host:2083/execute/Email/list_pops',
                static fn (Client $client): mixed => $client->uapi()->email()->listPops(),
            ),
            self::probe(
                $email,
                'uapi:Email/list_pops',
                'bridged-uapi',
                EmailSpec::LIST_POPS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Email&cpanel.function=list_pops',
                static fn (Client $client): mixed => $client->uapi('npr')->email()->listPops(),
            ),
            self::probe(
                $accounts,
                'whm1:listaccts',
                'whm',
                AccountsSpec::LISTACCTS,
                'https://test.oblak.host:2087/json-api/listaccts?api.version=1'
                . '&search=npr&searchtype=user&searchmethod=exact',
                static fn (Client $client): mixed => $client->whm()->accounts()->listAccounts(
                    search: 'npr',
                    searchtype: 'user',
                    searchmethod: 'exact',
                ),
                ['search' => 'npr', 'searchtype' => 'user', 'searchmethod' => 'exact'],
            ),
            self::probe(
                $accounts,
                'whm1:accountsummary',
                'whm',
                AccountsSpec::ACCOUNTSUMMARY,
                'https://test.oblak.host:2087/json-api/accountsummary?api.version=1&user=npr',
                static fn (Client $client): mixed => $client->whm()->accounts()->accountSummary(user: 'npr'),
                ['user' => 'npr'],
            ),
            self::probe(
                $accounts,
                'whm1:get_domain_info',
                'whm',
                AccountsSpec::GET_DOMAIN_INFO,
                'https://test.oblak.host:2087/json-api/get_domain_info?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->getDomainInfo(),
            ),
            self::probe(
                $webServer,
                'whm1:php_get_installed_versions',
                'whm',
                WebServerSpec::PHP_GET_INSTALLED_VERSIONS,
                'https://test.oblak.host:2087/json-api/php_get_installed_versions?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->phpGetInstalledVersions(),
            ),
            self::probe(
                $webServer,
                'whm1:php_get_system_default_version',
                'whm',
                WebServerSpec::PHP_GET_SYSTEM_DEFAULT_VERSION,
                'https://test.oblak.host:2087/json-api/php_get_system_default_version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->webServer()->phpGetSystemDefaultVersion(),
            ),
            self::probe(
                $domains,
                'uapi:DomainInfo/primary_domain',
                'direct-uapi',
                DomainInfoSpec::PRIMARY_DOMAIN,
                'https://test.oblak.host:2083/execute/DomainInfo/primary_domain',
                static fn (Client $client): mixed => $client->uapi()->domainInfo()->primaryDomain(),
            ),
            self::probe(
                $domains,
                'uapi:DomainInfo/primary_domain',
                'bridged-uapi',
                DomainInfoSpec::PRIMARY_DOMAIN,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=primary_domain',
                static fn (Client $client): mixed => $client->uapi('npr')->domainInfo()->primaryDomain(),
            ),
            self::probe(
                $php,
                'uapi:LangPHP/php_get_installed_versions',
                'direct-uapi',
                LangPhpSpec::PHP_GET_INSTALLED_VERSIONS,
                'https://test.oblak.host:2083/execute/LangPHP/php_get_installed_versions',
                static fn (Client $client): mixed => $client->uapi()->langPhp()->phpGetInstalledVersions(),
            ),
            self::probe(
                $php,
                'uapi:LangPHP/php_get_installed_versions',
                'bridged-uapi',
                LangPhpSpec::PHP_GET_INSTALLED_VERSIONS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_installed_versions',
                static fn (Client $client): mixed => $client->uapi('npr')->langPhp()->phpGetInstalledVersions(),
            ),
            self::probe(
                $php,
                'uapi:LangPHP/php_get_domain_handler',
                'direct-uapi',
                LangPhpSpec::PHP_GET_DOMAIN_HANDLER,
                'https://test.oblak.host:2083/execute/LangPHP/php_get_domain_handler?type=home',
                static fn (Client $client): mixed => $client->uapi()->langPhp()->phpGetDomainHandler(type: 'home'),
                ['type' => 'home'],
            ),
            self::probe(
                $php,
                'uapi:LangPHP/php_get_domain_handler',
                'bridged-uapi',
                LangPhpSpec::PHP_GET_DOMAIN_HANDLER,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_domain_handler&type=home',
                static fn (Client $client): mixed => $client->uapi('npr')->langPhp()->phpGetDomainHandler(type: 'home'),
                ['type' => 'home'],
            ),
            self::probe(
                $email,
                'uapi:Email/list_mail_domains',
                'direct-uapi',
                EmailSpec::LIST_MAIL_DOMAINS,
                'https://test.oblak.host:2083/execute/Email/list_mail_domains',
                static fn (Client $client): mixed => $client->uapi()->email()->listMailDomains(),
            ),
            self::probe(
                $email,
                'uapi:Email/list_mail_domains',
                'bridged-uapi',
                EmailSpec::LIST_MAIL_DOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Email&cpanel.function=list_mail_domains',
                static fn (Client $client): mixed => $client->uapi('npr')->email()->listMailDomains(),
            ),
            self::probe(
                $email,
                'uapi:Email/list_domain_forwarders',
                'direct-uapi',
                EmailSpec::LIST_DOMAIN_FORWARDERS,
                'https://test.oblak.host:2083/execute/Email/list_domain_forwarders',
                static fn (Client $client): mixed => $client->uapi()->email()->listDomainForwarders(),
            ),
            self::probe(
                $email,
                'uapi:Email/list_domain_forwarders',
                'bridged-uapi',
                EmailSpec::LIST_DOMAIN_FORWARDERS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Email&cpanel.function=list_domain_forwarders',
                static fn (Client $client): mixed => $client->uapi('npr')->email()->listDomainForwarders(),
            ),
            self::probe(
                $email,
                'uapi:Email/count_pops',
                'direct-uapi',
                EmailSpec::COUNT_POPS,
                'https://test.oblak.host:2083/execute/Email/count_pops',
                static fn (Client $client): mixed => $client->uapi()->email()->countPops(),
            ),
            self::probe(
                $email,
                'uapi:Email/count_pops',
                'bridged-uapi',
                EmailSpec::COUNT_POPS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Email&cpanel.function=count_pops',
                static fn (Client $client): mixed => $client->uapi('npr')->email()->countPops(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/installed_hosts',
                'direct-uapi',
                SslSpec::INSTALLED_HOSTS,
                'https://test.oblak.host:2083/execute/SSL/installed_hosts',
                static fn (Client $client): mixed => $client->uapi()->ssl()->installedHosts(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/installed_hosts',
                'bridged-uapi',
                SslSpec::INSTALLED_HOSTS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SSL&cpanel.function=installed_hosts',
                static fn (Client $client): mixed => $client->uapi('npr')->ssl()->installedHosts(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/get_autossl_excluded_domains',
                'direct-uapi',
                SslSpec::GET_AUTOSSL_EXCLUDED_DOMAINS,
                'https://test.oblak.host:2083/execute/SSL/get_autossl_excluded_domains',
                static fn (Client $client): mixed => $client->uapi()->ssl()->getAutosslExcludedDomains(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/get_autossl_excluded_domains',
                'bridged-uapi',
                SslSpec::GET_AUTOSSL_EXCLUDED_DOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SSL&cpanel.function=get_autossl_excluded_domains',
                static fn (Client $client): mixed => $client->uapi('npr')->ssl()->getAutosslExcludedDomains(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/get_autossl_problems',
                'direct-uapi',
                SslSpec::GET_AUTOSSL_PROBLEMS,
                'https://test.oblak.host:2083/execute/SSL/get_autossl_problems',
                static fn (Client $client): mixed => $client->uapi()->ssl()->getAutosslProblems(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/get_autossl_problems',
                'bridged-uapi',
                SslSpec::GET_AUTOSSL_PROBLEMS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SSL&cpanel.function=get_autossl_problems',
                static fn (Client $client): mixed => $client->uapi('npr')->ssl()->getAutosslProblems(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/get_autossl_renewal_status',
                'direct-uapi',
                SslSpec::GET_AUTOSSL_RENEWAL_STATUS,
                'https://test.oblak.host:2083/execute/SSL/get_autossl_renewal_status?domain=npr.rs',
                static fn (Client $client): mixed => $client->uapi()->ssl()->getAutosslRenewalStatus(domain: 'npr.rs'),
                ['domain' => 'npr.rs'],
            ),
            self::probe(
                $ssl,
                'uapi:SSL/get_autossl_renewal_status',
                'bridged-uapi',
                SslSpec::GET_AUTOSSL_RENEWAL_STATUS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SSL&cpanel.function=get_autossl_renewal_status&domain=npr.rs',
                static fn (Client $client): mixed =>
                    $client->uapi('npr')->ssl()->getAutosslRenewalStatus(domain: 'npr.rs'),
                ['domain' => 'npr.rs'],
            ),
            self::probe(
                $ssl,
                'uapi:SSL/is_autossl_check_in_progress',
                'direct-uapi',
                SslSpec::IS_AUTOSSL_CHECK_IN_PROGRESS,
                'https://test.oblak.host:2083/execute/SSL/is_autossl_check_in_progress',
                static fn (Client $client): mixed => $client->uapi()->ssl()->isAutosslCheckInProgress(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/is_autossl_check_in_progress',
                'bridged-uapi',
                SslSpec::IS_AUTOSSL_CHECK_IN_PROGRESS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SSL&cpanel.function=is_autossl_check_in_progress',
                static fn (Client $client): mixed => $client->uapi('npr')->ssl()->isAutosslCheckInProgress(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/can_ssl_redirect',
                'direct-uapi',
                SslSpec::CAN_SSL_REDIRECT,
                'https://test.oblak.host:2083/execute/SSL/can_ssl_redirect',
                static fn (Client $client): mixed => $client->uapi()->ssl()->canSslRedirect(),
            ),
            self::probe(
                $ssl,
                'uapi:SSL/can_ssl_redirect',
                'bridged-uapi',
                SslSpec::CAN_SSL_REDIRECT,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SSL&cpanel.function=can_ssl_redirect',
                static fn (Client $client): mixed => $client->uapi('npr')->ssl()->canSslRedirect(),
            ),
        ];
    }

    /**
     * Absent recipes are skipped; unsupported recipes cause the entire run to fail closed before I/O.
     *
     * @param array<mixed> $overlay
     * @param 'whm'|'direct-uapi'|'bridged-uapi' $route
     * @param array<mixed> $spec
     * @param Closure(Client): mixed $invoke
     * @param array<string, string> $arguments Exact reviewed recipe values, never passed through to the call.
     */
    private static function probe(
        array $overlay,
        string $operation,
        string $route,
        array $spec,
        string $uri,
        Closure $invoke,
        array $arguments = [],
    ): Probe {
        $operations = $overlay['operations'] ?? null;
        $entry = is_array($operations) ? ($operations[$operation] ?? null) : null;
        $recipe = is_array($entry) ? ($entry['probe'] ?? null) : null;
        $unavailable = null;
        if ($recipe === null) {
            $unavailable = 'skipped';
        } else {
            $expected = [
                'phase' => 'read-only',
                'arguments' => $arguments,
                'routes' => $route === 'whm' ? ['whm'] : ['direct-uapi', 'bridged-uapi'],
                'expected' => 'success',
            ];
            if (is_array($recipe)) {
                ksort($recipe);
            }
            ksort($expected);
            if ($recipe !== $expected || ($spec['method'] ?? null) !== 'GET') {
                $unavailable = 'unsupported';
            }
        }

        return new Probe($operation, $route, $spec, $uri, $invoke, $unavailable);
    }

    /** @return array<mixed> */
    private static function read(string $path): array
    {
        $bytes = @file_get_contents($path);
        try {
            $value = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $value = null;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Cannot read reviewed probe recipes.');
        }

        return $value;
    }
}
