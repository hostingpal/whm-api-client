<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\AddonDomainSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainLookupSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ParkSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SubDomainSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\WebVhostsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed account-bound GET calls; EvidenceRunner enforces each literal URI through RequestGuard. */
final class DomainManagementProbes
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
                sourceVariant: 'whm1:version#GET',
            ),
            new Probe(
                'uapi:AddonDomain/listaddondomains',
                'direct-uapi',
                AddonDomainSpec::LISTADDONDOMAINS,
                'https://test.oblak.host:2083/execute/AddonDomain/listaddondomains?return_https_redirect_status=0',
                static fn (Client $client): mixed => $client->uapi('npr')->addonDomain()->listAddonDomains(
                    returnHttpsRedirectStatus: false,
                ),
                sourceVariant: 'uapi:AddonDomain/listaddondomains#GET',
            ),
            new Probe(
                'uapi:AddonDomain/listaddondomains',
                'bridged-uapi',
                AddonDomainSpec::LISTADDONDOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=AddonDomain&cpanel.function=listaddondomains&return_https_redirect_status=0',
                static fn (Client $client): mixed => $client->uapi('npr')->addonDomain()->listAddonDomains(
                    returnHttpsRedirectStatus: false,
                ),
                sourceVariant: 'uapi:AddonDomain/listaddondomains#GET',
            ),
            new Probe(
                'uapi:Domain/is_temporary_domain',
                'direct-uapi',
                DomainSpec::IS_TEMPORARY_DOMAIN,
                'https://test.oblak.host:2083/execute/Domain/is_temporary_domain?domain=npr.rs',
                static fn (Client $client): mixed => $client->uapi('npr')->domain()->isTemporaryDomain(
                    domain: 'npr.rs',
                ),
                sourceVariant: 'uapi:Domain/is_temporary_domain#GET',
            ),
            new Probe(
                'uapi:Domain/is_temporary_domain',
                'bridged-uapi',
                DomainSpec::IS_TEMPORARY_DOMAIN,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Domain&cpanel.function=is_temporary_domain&domain=npr.rs',
                static fn (Client $client): mixed => $client->uapi('npr')->domain()->isTemporaryDomain(
                    domain: 'npr.rs',
                ),
                sourceVariant: 'uapi:Domain/is_temporary_domain#GET',
            ),
            new Probe(
                'uapi:Domain/temporary_domain_is_disabled',
                'direct-uapi',
                DomainSpec::TEMPORARY_DOMAIN_IS_DISABLED,
                'https://test.oblak.host:2083/execute/Domain/temporary_domain_is_disabled',
                static fn (Client $client): mixed => $client->uapi('npr')->domain()->temporaryDomainIsDisabled(),
                sourceVariant: 'uapi:Domain/temporary_domain_is_disabled#GET',
            ),
            new Probe(
                'uapi:Domain/temporary_domain_is_disabled',
                'bridged-uapi',
                DomainSpec::TEMPORARY_DOMAIN_IS_DISABLED,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Domain&cpanel.function=temporary_domain_is_disabled',
                static fn (Client $client): mixed => $client->uapi('npr')->domain()->temporaryDomainIsDisabled(),
                sourceVariant: 'uapi:Domain/temporary_domain_is_disabled#GET',
            ),
            new Probe(
                'uapi:DomainLookup/countbasedomains',
                'direct-uapi',
                DomainLookupSpec::COUNTBASEDOMAINS,
                'https://test.oblak.host:2083/execute/DomainLookup/countbasedomains',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->countBaseDomains(),
                sourceVariant: 'uapi:DomainLookup/countbasedomains#GET',
            ),
            new Probe(
                'uapi:DomainLookup/countbasedomains',
                'bridged-uapi',
                DomainLookupSpec::COUNTBASEDOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainLookup&cpanel.function=countbasedomains',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->countBaseDomains(),
                sourceVariant: 'uapi:DomainLookup/countbasedomains#GET',
            ),
            new Probe(
                'uapi:DomainLookup/getbasedomains',
                'direct-uapi',
                DomainLookupSpec::GETBASEDOMAINS,
                'https://test.oblak.host:2083/execute/DomainLookup/getbasedomains?return_temporary_domain=0',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->getBaseDomains(
                    returnTemporaryDomain: false,
                ),
                sourceVariant: 'uapi:DomainLookup/getbasedomains#GET',
            ),
            new Probe(
                'uapi:DomainLookup/getbasedomains',
                'bridged-uapi',
                DomainLookupSpec::GETBASEDOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainLookup&cpanel.function=getbasedomains&return_temporary_domain=0',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->getBaseDomains(
                    returnTemporaryDomain: false,
                ),
                sourceVariant: 'uapi:DomainLookup/getbasedomains#GET',
            ),
            new Probe(
                'uapi:DomainLookup/getdocroot',
                'direct-uapi',
                DomainLookupSpec::GETDOCROOT,
                'https://test.oblak.host:2083/execute/DomainLookup/getdocroot?domain=npr.rs',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->getDocumentRoot(
                    domain: 'npr.rs',
                ),
                sourceVariant: 'uapi:DomainLookup/getdocroot#GET',
            ),
            new Probe(
                'uapi:DomainLookup/getdocroot',
                'bridged-uapi',
                DomainLookupSpec::GETDOCROOT,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainLookup&cpanel.function=getdocroot&domain=npr.rs',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->getDocumentRoot(
                    domain: 'npr.rs',
                ),
                sourceVariant: 'uapi:DomainLookup/getdocroot#GET',
            ),
            new Probe(
                'uapi:DomainLookup/getdocroots',
                'direct-uapi',
                DomainLookupSpec::GETDOCROOTS,
                'https://test.oblak.host:2083/execute/DomainLookup/getdocroots',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->getDocumentRoots(),
                sourceVariant: 'uapi:DomainLookup/getdocroots#GET',
            ),
            new Probe(
                'uapi:DomainLookup/getdocroots',
                'bridged-uapi',
                DomainLookupSpec::GETDOCROOTS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainLookup&cpanel.function=getdocroots',
                static fn (Client $client): mixed => $client->uapi('npr')->domainLookup()->getDocumentRoots(),
                sourceVariant: 'uapi:DomainLookup/getdocroots#GET',
            ),
            new Probe(
                'uapi:Park/listparkeddomains',
                'direct-uapi',
                ParkSpec::LISTPARKEDDOMAINS,
                'https://test.oblak.host:2083/execute/Park/listparkeddomains?return_https_redirect_status=0',
                static fn (Client $client): mixed => $client->uapi('npr')->park()->listParkedDomains(
                    returnHttpsRedirectStatus: false,
                ),
                sourceVariant: 'uapi:Park/listparkeddomains#GET',
            ),
            new Probe(
                'uapi:Park/listparkeddomains',
                'bridged-uapi',
                ParkSpec::LISTPARKEDDOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Park&cpanel.function=listparkeddomains&return_https_redirect_status=0',
                static fn (Client $client): mixed => $client->uapi('npr')->park()->listParkedDomains(
                    returnHttpsRedirectStatus: false,
                ),
                sourceVariant: 'uapi:Park/listparkeddomains#GET',
            ),
            new Probe(
                'uapi:SubDomain/getreservedsubdomains',
                'direct-uapi',
                SubDomainSpec::GETRESERVEDSUBDOMAINS,
                'https://test.oblak.host:2083/execute/SubDomain/getreservedsubdomains',
                static fn (Client $client): mixed => $client->uapi('npr')->subDomain()->getReservedSubdomains(),
                sourceVariant: 'uapi:SubDomain/getreservedsubdomains#GET',
            ),
            new Probe(
                'uapi:SubDomain/getreservedsubdomains',
                'bridged-uapi',
                SubDomainSpec::GETRESERVEDSUBDOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SubDomain&cpanel.function=getreservedsubdomains',
                static fn (Client $client): mixed => $client->uapi('npr')->subDomain()->getReservedSubdomains(),
                sourceVariant: 'uapi:SubDomain/getreservedsubdomains#GET',
            ),
            new Probe(
                'uapi:SubDomain/listsubdomains',
                'direct-uapi',
                SubDomainSpec::LISTSUBDOMAINS,
                'https://test.oblak.host:2083/execute/SubDomain/listsubdomains?return_https_redirect_status=0',
                static fn (Client $client): mixed => $client->uapi('npr')->subDomain()->listSubdomains(
                    returnHttpsRedirectStatus: false,
                ),
                sourceVariant: 'uapi:SubDomain/listsubdomains#GET',
            ),
            new Probe(
                'uapi:SubDomain/listsubdomains',
                'bridged-uapi',
                SubDomainSpec::LISTSUBDOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SubDomain&cpanel.function=listsubdomains&return_https_redirect_status=0',
                static fn (Client $client): mixed => $client->uapi('npr')->subDomain()->listSubdomains(
                    returnHttpsRedirectStatus: false,
                ),
                sourceVariant: 'uapi:SubDomain/listsubdomains#GET',
            ),
            new Probe(
                'uapi:SubDomain/validregex',
                'direct-uapi',
                SubDomainSpec::VALIDREGEX,
                'https://test.oblak.host:2083/execute/SubDomain/validregex',
                static fn (Client $client): mixed => $client->uapi('npr')->subDomain()->validRegex(),
                sourceVariant: 'uapi:SubDomain/validregex#GET',
            ),
            new Probe(
                'uapi:SubDomain/validregex',
                'bridged-uapi',
                SubDomainSpec::VALIDREGEX,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SubDomain&cpanel.function=validregex',
                static fn (Client $client): mixed => $client->uapi('npr')->subDomain()->validRegex(),
                sourceVariant: 'uapi:SubDomain/validregex#GET',
            ),
            new Probe(
                'uapi:WebVhosts/list_domains',
                'direct-uapi',
                WebVhostsSpec::LIST_DOMAINS,
                'https://test.oblak.host:2083/execute/WebVhosts/list_domains',
                static fn (Client $client): mixed => $client->uapi('npr')->webVhosts()->listDomains(),
                sourceVariant: 'uapi:WebVhosts/list_domains#GET',
            ),
            new Probe(
                'uapi:WebVhosts/list_domains',
                'bridged-uapi',
                WebVhostsSpec::LIST_DOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=WebVhosts&cpanel.function=list_domains',
                static fn (Client $client): mixed => $client->uapi('npr')->webVhosts()->listDomains(),
                sourceVariant: 'uapi:WebVhosts/list_domains#GET',
            ),
            new Probe(
                'uapi:WebVhosts/list_ssl_capable_domains',
                'direct-uapi',
                WebVhostsSpec::LIST_SSL_CAPABLE_DOMAINS,
                'https://test.oblak.host:2083/execute/WebVhosts/list_ssl_capable_domains?hide_temporary_domains=0',
                static fn (Client $client): mixed => $client->uapi('npr')->webVhosts()->listSslCapableDomains(
                    hideTemporaryDomains: false,
                ),
                sourceVariant: 'uapi:WebVhosts/list_ssl_capable_domains#GET',
            ),
            new Probe(
                'uapi:WebVhosts/list_ssl_capable_domains',
                'bridged-uapi',
                WebVhostsSpec::LIST_SSL_CAPABLE_DOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=WebVhosts&cpanel.function=list_ssl_capable_domains&hide_temporary_domains=0',
                static fn (Client $client): mixed => $client->uapi('npr')->webVhosts()->listSslCapableDomains(
                    hideTemporaryDomains: false,
                ),
                sourceVariant: 'uapi:WebVhosts/list_ssl_capable_domains#GET',
            ),
        ];
    }
}
