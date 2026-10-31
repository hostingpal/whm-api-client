<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\DnssecSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Singleton-list wire acceptance only; no key, registrar, record or account changes. */
final class DnssecIndexedInputProbes
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
                'uapi:DNSSEC/fetch_ds_records',
                'direct-uapi',
                DnssecSpec::FETCH_DS_RECORDS,
                'https://test.oblak.host:2083/execute/DNSSEC/fetch_ds_records?domain-0=npr.rs',
                static fn (Client $client): array => $client->uapi()->dnssec()->fetchDsRecords(domain: ['npr.rs']),
                argumentVariant: 'singleton-domain-list',
            ),
            new Probe(
                'uapi:DNSSEC/fetch_ds_records',
                'bridged-uapi',
                DnssecSpec::FETCH_DS_RECORDS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=DNSSEC&cpanel.function=fetch_ds_records&domain-0=npr.rs',
                static fn (Client $client): array => $client->uapi('npr')->dnssec()->fetchDsRecords(domain: ['npr.rs']),
                argumentVariant: 'singleton-domain-list',
            ),
        ];
    }
}
