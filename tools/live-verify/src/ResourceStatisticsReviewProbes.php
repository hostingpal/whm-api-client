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

/** Final review catalog for the npr resource/statistics reads, with three separately identified query depths. */
final class ResourceStatisticsReviewProbes
{
    /**
     * Execute 27 exact GETs: WHM version, then eleven account functions through both UAPI routes.
     *
     * Bandwidth queries select only npr.rs/http within the original October 2026 UTC bounds. The statistics
     * display mixes a normal counter, reviewed nullable counts and the parked-domain role object. Analog site
     * inventory and one suexec log entry exercise the requested alternatives without changing configuration.
     * The shared runner retains only the original responses' sanitized structure, never log text or field values.
     *
     * @return list<Probe>
     */
    public static function load(): array
    {
        $probes = [new Probe(
            'whm1:version',
            'whm',
            ServerSpec::VERSION,
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            static fn (Client $client): mixed => $client->whm()->server()->version(),
        )];
        $display = 'bandwidthusage|apacheversion|cpanelversion|hostingpackage|hostname|kernelversion'
            . '|machinetype|mysqlversion|operatingsystem|perlversion|sharedip|shorthostname|theme|parkeddomains';
        foreach (
            [
                ['Bandwidth', 'get_enabled_protocols', BandwidthSpec::GET_ENABLED_PROTOCOLS, '',
                    static fn (UapiClient $account): array => $account->bandwidth()->getEnabledProtocols(), null],
                ['Bandwidth', 'get_retention_periods', BandwidthSpec::GET_RETENTION_PERIODS, '',
                    static fn (UapiClient $account): array => $account->bandwidth()->getRetentionPeriods(), null],
                self::query('domain', 'grouping-domain'),
                self::query('domain|protocol', 'grouping-domain-protocol'),
                self::query('domain|protocol|year', 'grouping-domain-protocol-year'),
                ['ResourceUsage', 'get_usages', ResourceUsageSpec::GET_USAGES, '',
                    static fn (UapiClient $account): array => $account->resourceUsage()->getUsages(), null],
                ['StatsBar', 'get_stats', StatsBarSpec::GET_STATS,
                    'display=' . rawurlencode($display) . '&warnings=0&warnout=0',
                    static fn (UapiClient $account): array => $account->statsBar()->getStats(
                        display: $display,
                        warnings: false,
                        warnOut: false,
                    ), null],
                ['Stats', 'get_bandwidth', StatsSpec::GET_BANDWIDTH, 'timezone=UTC',
                    static fn (UapiClient $account): array => $account->stats()->getBandwidth(timezone: 'UTC'), null],
                ['Stats', 'get_site_errors', StatsSpec::GET_SITE_ERRORS, 'domain=npr.rs&log=suexec&maxlines=1',
                    static fn (UapiClient $account): array => $account->stats()->getSiteErrors(
                        domain: 'npr.rs',
                        log: 'suexec',
                        maxLines: 1,
                    ), null],
                ['Stats', 'get_stats_daily', StatsSpec::GET_STATS_DAILY, 'domain=npr.rs',
                    static fn (UapiClient $account): array => $account->stats()->getStatsDaily(domain: 'npr.rs'), null],
                ['Stats', 'list_sites', StatsSpec::LIST_SITES, 'engine=analog&traffic=http',
                    static fn (UapiClient $account): array => $account->stats()->listSites(
                        engine: 'analog',
                        traffic: 'http',
                    ), null],
                ['Stats', 'list_stats_by_domain', StatsSpec::LIST_STATS_BY_DOMAIN,
                    'domain=npr.rs&engine=analog&ssl=0',
                    static fn (UapiClient $account): array => $account->stats()->listStatsByDomain(
                        domain: 'npr.rs',
                        engine: 'analog',
                        ssl: false,
                    ), null],
                ['StatsManager', 'get_configuration', StatsManagerSpec::GET_CONFIGURATION, '',
                    static fn (UapiClient $account): array => $account->statsManager()->getConfiguration(), null],
            ] as [$module, $function, $spec, $parameters, $invoke, $variant]
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
                $probes[] = new Probe(
                    'uapi:' . $module . '/' . $function,
                    $route,
                    $spec,
                    $uri,
                    static fn (Client $client): mixed => $invoke($client->uapi($direct ? null : 'npr')),
                    argumentVariant: $variant,
                );
            }
        }

        return $probes;
    }

    /** @return array{string, string, array<mixed>, string, Closure(UapiClient): array<mixed>, string} */
    private static function query(string $grouping, string $variant): array
    {
        return ['Bandwidth', 'query', BandwidthSpec::QUERY,
            'grouping=' . rawurlencode($grouping) . '&domains=npr.rs&end=1793404800'
                . '&interval=daily&protocols=http&start=1790812800&timezone=UTC',
            static fn (UapiClient $account): array => $account->bandwidth()->query(
                grouping: $grouping,
                domains: 'npr.rs',
                end: 1793404800,
                interval: 'daily',
                protocols: 'http',
                start: 1790812800,
                timezone: 'UTC',
            ), $variant];
    }
}
