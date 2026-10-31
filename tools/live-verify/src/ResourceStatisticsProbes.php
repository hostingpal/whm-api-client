<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\BandwidthSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ResourceUsageSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\StatsBarSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\StatsManagerSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\StatsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed account resource and web statistics reads; no configuration is changed. */
final class ResourceStatisticsProbes
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
                ['Bandwidth', 'get_enabled_protocols', BandwidthSpec::GET_ENABLED_PROTOCOLS, '',
                    static fn (UapiClient $account): array => $account->bandwidth()->getEnabledProtocols()],
                ['Bandwidth', 'get_retention_periods', BandwidthSpec::GET_RETENTION_PERIODS, '',
                    static fn (UapiClient $account): array => $account->bandwidth()->getRetentionPeriods()],
                ['Bandwidth', 'query', BandwidthSpec::QUERY,
                    'grouping=domain%7Cprotocol&domains=npr.rs&end=1793404800'
                        . '&interval=daily&protocols=http&start=1790812800&timezone=UTC',
                    static fn (UapiClient $account): array => $account->bandwidth()->query(
                        grouping: 'domain|protocol',
                        domains: 'npr.rs',
                        end: 1793404800,
                        interval: 'daily',
                        protocols: 'http',
                        start: 1790812800,
                        timezone: 'UTC',
                    )],
                ['ResourceUsage', 'get_usages', ResourceUsageSpec::GET_USAGES, '',
                    static fn (UapiClient $account): array => $account->resourceUsage()->getUsages()],
                ['StatsBar', 'get_stats', StatsBarSpec::GET_STATS,
                    'display=bandwidthusage%7Cdiskusage&warnings=0&warnout=0',
                    static fn (UapiClient $account): array => $account->statsBar()->getStats(
                        display: 'bandwidthusage|diskusage',
                        warnings: false,
                        warnOut: false,
                    )],
                ['Stats', 'get_bandwidth', StatsSpec::GET_BANDWIDTH, 'timezone=UTC',
                    static fn (UapiClient $account): array => $account->stats()->getBandwidth(timezone: 'UTC')],
                ['Stats', 'get_site_errors', StatsSpec::GET_SITE_ERRORS, 'domain=npr.rs&log=error&maxlines=1',
                    static fn (UapiClient $account): array => $account->stats()->getSiteErrors(
                        domain: 'npr.rs',
                        log: 'error',
                        maxLines: 1,
                    )],
                ['Stats', 'get_stats_daily', StatsSpec::GET_STATS_DAILY, 'domain=npr.rs',
                    static fn (UapiClient $account): array => $account->stats()->getStatsDaily(domain: 'npr.rs')],
                ['Stats', 'list_sites', StatsSpec::LIST_SITES, 'engine=webalizer&traffic=http',
                    static fn (UapiClient $account): array => $account->stats()->listSites(
                        engine: 'webalizer',
                        traffic: 'http',
                    )],
                ['Stats', 'list_stats_by_domain', StatsSpec::LIST_STATS_BY_DOMAIN,
                    'domain=npr.rs&engine=analog&ssl=0',
                    static fn (UapiClient $account): array => $account->stats()->listStatsByDomain(
                        domain: 'npr.rs',
                        engine: 'analog',
                        ssl: false,
                    )],
                ['StatsManager', 'get_configuration', StatsManagerSpec::GET_CONFIGURATION, '',
                    static fn (UapiClient $account): array => $account->statsManager()->getConfiguration()],
            ] as [$module, $function, $spec, $parameters, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
                    ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
                if ($parameters !== '') {
                    $uri .= ($direct ? '?' : '&') . $parameters;
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
