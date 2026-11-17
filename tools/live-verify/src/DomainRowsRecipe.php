<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\AddonDomainSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DnsSpec as UapiDnsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ParkSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SubDomainSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\AccountsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\DnsSpec as WhmDnsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use SensitiveParameter;

/**
 * The exact requests of the domain-rows recipe: one disposable account holding a main, addon, parked and subdomain.
 *
 * Every name the recipe creates is fixed here, is an RFC 2606 name under example.com carrying this recipe's prefix,
 * and is created only by this account's own token. Every request is spelled here independently of the client's
 * encoder, so the guard keeps comparing two representations of each one. The DNS reads send the main domain and the
 * addon, two distinct owned domains, in both orders; the addon sorts first, so neither order is also the sorted one.
 * export_zone_files is deliberately absent: a raw multi-zone export was refused earlier and stays excluded.
 */
final readonly class DomainRowsRecipe
{
    public const ACCOUNT = 'wldomains';

    public const DOMAIN = 'wldomains.example.com';

    public const TOKEN_NAME = 'wldomains-rows';

    public const ADDON = 'wldomains-addon.example.com';

    /** The label of the subdomain the addon is parked on, which its creation makes implicitly. */
    public const ADDON_LABEL = 'wldaddon';

    public const ADDON_SUBDOMAIN = self::ADDON_LABEL . '.' . self::DOMAIN;

    public const ALIAS = 'wldomains-alias.example.com';

    public const SUB_LABEL = 'wldsub';

    public const SUBDOMAIN = self::SUB_LABEL . '.' . self::DOMAIN;

    /** Every extra domain identity this run can create, by fixed report label. */
    public const DOMAINS = [
        'addon_domain' => self::ADDON,
        'addon_subdomain' => self::ADDON_SUBDOMAIN,
        'alias_domain' => self::ALIAS,
        'subdomain' => self::SUBDOMAIN,
    ];

    /** Every extra zone this run can create, by fixed report label; a subdomain lives in its parent's zone. */
    public const ZONES = ['addon_zone' => self::ADDON, 'alias_zone' => self::ALIAS];

    public const SETUP_PHASES = ['version', 'limits', 'create_addon', 'park_alias', 'create_subdomain'];

    public const DNS_PHASES = [
        'whm_authority_forward', 'whm_authority_reverse',
        'direct_authority_forward', 'direct_authority_reverse',
        'bridged_authority_forward', 'bridged_authority_reverse',
        'direct_reside_forward', 'direct_reside_reverse',
        'bridged_reside_forward', 'bridged_reside_reverse',
        'direct_nameservers_forward', 'direct_nameservers_reverse',
        'bridged_nameservers_forward', 'bridged_nameservers_reverse',
        'whm_ds_forward', 'whm_ds_reverse',
    ];

    public const DOMAIN_INFO_PHASES = [
        'direct_list_domains', 'bridged_list_domains',
        'direct_domains_hash', 'bridged_domains_hash',
        'direct_domains_list', 'bridged_domains_list',
        'direct_single_main', 'bridged_single_main',
        'direct_single_addon', 'bridged_single_addon',
        'direct_single_alias', 'bridged_single_alias',
        'direct_single_sub', 'bridged_single_sub',
    ];

    public const PHASES = [...self::SETUP_PHASES, ...self::DNS_PHASES, ...self::DOMAIN_INFO_PHASES];


    /** Account limits raised on this account alone, so it can hold one addon, one alias and two subdomains. */
    public const LIMITS = ['MAXADDON' => 1, 'MAXFTP' => 2, 'MAXPARK' => 1, 'MAXSUB' => 2];

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=' . self::ACCOUNT;

    /** Bind the one designated binding with a writable token, which domain creation needs; performs no I/O. */
    public function __construct(#[SensitiveParameter] public DisposableAccount $account)
    {
        if (
            $account->user !== self::ACCOUNT || $account->domain !== self::DOMAIN
            || $account->tokenName !== self::TOKEN_NAME || $account->readOnlyToken
        ) {
            throw new LogicException('Domain rows recipe binding is invalid.');
        }
    }

    /**
     * The requested domains of one DNS phase: forward sends the main domain first, reverse the addon.
     *
     * @return list<string>
     */
    public static function requestedDomains(string $phase): array
    {
        if (!in_array($phase, self::DNS_PHASES, true)) {
            throw new LogicException('Not a domain rows DNS phase.');
        }

        return str_ends_with($phase, '_forward') ? [self::DOMAIN, self::ADDON] : [self::ADDON, self::DOMAIN];
    }

    /**
     * The route of one phase, as EvidenceRunner names it.
     *
     * @return 'whm'|'direct-uapi'|'bridged-uapi'
     */
    public static function route(string $phase): string
    {
        return match (true) {
            in_array($phase, ['version', 'limits'], true), str_starts_with($phase, 'whm_') => 'whm',
            in_array($phase, ['create_addon', 'park_alias', 'create_subdomain'], true),
            str_starts_with($phase, 'direct_') => 'direct-uapi',
            str_starts_with($phase, 'bridged_') => 'bridged-uapi',
            default => throw new LogicException('Unknown domain rows phase.'),
        };
    }

    /** The exact form body of a mutation phase, or null for a read. */
    public static function mutationBody(string $phase): ?string
    {
        return match ($phase) {
            'limits' => 'user=' . self::ACCOUNT . '&MAXADDON=1&MAXFTP=2&MAXPARK=1&MAXSUB=2',
            'create_addon' => 'newdomain=' . self::ADDON . '&subdomain=' . self::ADDON_LABEL,
            'park_alias' => 'domain=' . self::ALIAS,
            'create_subdomain' => 'domain=' . self::SUB_LABEL . '&rootdomain=' . self::DOMAIN,
            default => in_array($phase, self::PHASES, true) ? null
                : throw new LogicException('Unknown domain rows phase.'),
        };
    }

    /**
     * Every read the recipe sends, as exact URIs.
     *
     * @return list<string>
     */
    public static function readUris(): array
    {
        $uris = [];
        foreach (self::PHASES as $phase) {
            if (self::mutationBody($phase) === null) {
                $uris[] = self::probe($phase)->uri;
            }
        }

        return $uris;
    }

    /**
     * Every mutation the recipe sends, as exact form body by exact URI.
     *
     * @return array<string, string>
     */
    public static function mutations(): array
    {
        $mutations = [];
        foreach (self::PHASES as $phase) {
            $body = self::mutationBody($phase);
            if ($body !== null) {
                $mutations[self::probe($phase)->uri] = $body;
            }
        }

        return $mutations;
    }

    /** The reviewed generated call, exact URI and argument variant of one phase. */
    public static function probe(string $phase): Probe
    {
        return match (true) {
            in_array($phase, self::SETUP_PHASES, true) => self::setup($phase),
            in_array($phase, self::DNS_PHASES, true) => self::dns($phase),
            in_array($phase, self::DOMAIN_INFO_PHASES, true) => self::domainInfo($phase),
            default => throw new LogicException('Unknown domain rows phase.'),
        };
    }

    private static function setup(string $phase): Probe
    {
        return match ($phase) {
            'version' => new Probe(
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                self::WHM . 'version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->version(),
            ),
            'limits' => new Probe(
                'whm1:modifyacct',
                'whm',
                AccountsSpec::MODIFYACCT,
                self::WHM . 'modifyacct?api.version=1',
                static fn (Client $client): mixed => $client->whm()->accounts()->modifyAccount(
                    user: self::ACCOUNT,
                    maxaddon: self::LIMITS['MAXADDON'],
                    maxftp: self::LIMITS['MAXFTP'],
                    maxpark: self::LIMITS['MAXPARK'],
                    maxsub: self::LIMITS['MAXSUB'],
                ),
                argumentVariant: 'own-domain-limits',
            ),
            'create_addon' => new Probe(
                'uapi:AddonDomain/addaddondomain',
                'direct-uapi',
                AddonDomainSpec::ADDADDONDOMAIN,
                self::DIRECT . 'AddonDomain/addaddondomain',
                static fn (Client $client): mixed => $client->uapi()->addonDomain()->addAddonDomain(
                    newDomain: self::ADDON,
                    subdomain: self::ADDON_LABEL,
                ),
                argumentVariant: 'owned-addon',
            ),
            'park_alias' => new Probe(
                'uapi:Park/park',
                'direct-uapi',
                ParkSpec::PARK,
                self::DIRECT . 'Park/park',
                static function (Client $client): mixed {
                    $client->uapi()->park()->park(domain: self::ALIAS);

                    return null;
                },
                argumentVariant: 'owned-alias-on-main',
            ),
            'create_subdomain' => new Probe(
                'uapi:SubDomain/addsubdomain',
                'direct-uapi',
                SubDomainSpec::ADDSUBDOMAIN,
                self::DIRECT . 'SubDomain/addsubdomain',
                static fn (Client $client): mixed => $client->uapi()->subDomain()->addSubdomain(
                    domain: self::SUB_LABEL,
                    rootDomain: self::DOMAIN,
                ),
                argumentVariant: 'owned-subdomain-of-main',
            ),
            default => throw new LogicException('Unknown domain rows setup phase.'),
        };
    }

    private static function dns(string $phase): Probe
    {
        $domains = self::requestedDomains($phase);
        $query = 'domain=' . $domains[0] . '&domain=' . $domains[1];
        $variant = str_ends_with($phase, '_forward') ? 'main-then-addon' : 'addon-then-main';
        $route = self::route($phase);
        $user = $route === 'bridged-uapi' ? self::ACCOUNT : null;
        $operation = explode('_', $phase)[1];
        if ($route === 'whm') {
            [$function, $spec, $invoke] = $operation === 'authority'
                ? ['has_local_authority', WhmDnsSpec::HAS_LOCAL_AUTHORITY,
                    static fn (Client $client): mixed => $client->whm()->dns()->hasLocalAuthority($domains)]
                : ['fetch_ds_records_for_domains', WhmDnsSpec::FETCH_DS_RECORDS_FOR_DOMAINS,
                    static fn (Client $client): mixed => $client->whm()->dns()->fetchDsRecordsForDomains($domains)];

            return new Probe(
                'whm1:' . $function,
                'whm',
                $spec,
                self::WHM . $function . '?api.version=1&' . $query,
                $invoke,
                argumentVariant: $variant,
            );
        }
        [$function, $spec, $invoke] = match ($operation) {
            'authority' => ['has_local_authority', UapiDnsSpec::HAS_LOCAL_AUTHORITY,
                static fn (Client $client): mixed => $client->uapi($user)->dns()->hasLocalAuthority($domains)],
            'reside' => ['ensure_domains_reside_only_locally', UapiDnsSpec::ENSURE_DOMAINS_RESIDE_ONLY_LOCALLY,
                static fn (Client $client): mixed => $client->uapi($user)->dns()
                    ->ensureDomainsResideOnlyLocally($domains)],
            'nameservers' => ['ensure_domains_use_recognized_nameservers',
                UapiDnsSpec::ENSURE_DOMAINS_USE_RECOGNIZED_NAMESERVERS,
                static fn (Client $client): mixed => $client->uapi($user)->dns()
                    ->ensureDomainsUseRecognizedNameservers($domains)],
            default => throw new LogicException('Unknown domain rows DNS phase.'),
        };

        return new Probe(
            'uapi:DNS/' . $function,
            $route,
            $spec,
            self::uapiUri($route, 'DNS', $function, $query),
            $invoke,
            argumentVariant: $variant,
        );
    }

    private static function domainInfo(string $phase): Probe
    {
        $route = self::route($phase);
        $user = $route === 'bridged-uapi' ? self::ACCOUNT : null;
        $kind = substr($phase, strpos($phase, '_') + 1);
        if ($kind === 'list_domains') {
            return new Probe(
                'uapi:DomainInfo/list_domains',
                $route,
                DomainInfoSpec::LIST_DOMAINS,
                self::uapiUri($route, 'DomainInfo', 'list_domains', ''),
                static fn (Client $client): mixed => $client->uapi($user)->domainInfo()->listDomains(),
            );
        }
        if (str_starts_with($kind, 'domains_')) {
            $format = $kind === 'domains_hash' ? 'hash' : 'list';

            return new Probe(
                'uapi:DomainInfo/domains_data',
                $route,
                DomainInfoSpec::DOMAINS_DATA,
                self::uapiUri(
                    $route,
                    'DomainInfo',
                    'domains_data',
                    'format=' . $format . '&hide_temporary_domains=0&return_https_redirects_status=0',
                ),
                static fn (Client $client): mixed => $client->uapi($user)->domainInfo()->domainsData(
                    format: $format,
                    hideTemporaryDomains: false,
                    returnHttpsRedirectsStatus: false,
                ),
                argumentVariant: $format . '-format',
            );
        }
        [$domain, $variant] = match ($kind) {
            'single_main' => [self::DOMAIN, 'main-domain'],
            'single_addon' => [self::ADDON, 'addon-domain'],
            'single_alias' => [self::ALIAS, 'alias-domain'],
            'single_sub' => [self::SUBDOMAIN, 'subdomain'],
            default => throw new LogicException('Unknown domain rows DomainInfo phase.'),
        };

        return new Probe(
            'uapi:DomainInfo/single_domain_data',
            $route,
            DomainInfoSpec::SINGLE_DOMAIN_DATA,
            self::uapiUri(
                $route,
                'DomainInfo',
                'single_domain_data',
                'domain=' . $domain . '&hide_temporary_domains=0&return_https_redirect_status=0',
            ),
            static fn (Client $client): mixed => $client->uapi($user)->domainInfo()->singleDomainData(
                domain: $domain,
                hideTemporaryDomains: false,
                returnHttpsRedirectStatus: false,
            ),
            argumentVariant: $variant,
        );
    }

    private static function uapiUri(string $route, string $module, string $function, string $query): string
    {
        if ($route === 'direct-uapi') {
            return self::DIRECT . $module . '/' . $function . ($query === '' ? '' : '?' . $query);
        }

        return self::BRIDGE . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            . ($query === '' ? '' : '&' . $query);
    }
}
