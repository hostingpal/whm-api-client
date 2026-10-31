<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\MailSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed mail settings and designated-account inventories; no messages, keys or changes. */
final class WhmMailManagementProbes
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
                'whm1:get_default_dmarc_record',
                'whm',
                MailSpec::GET_DEFAULT_DMARC_RECORD,
                'https://test.oblak.host:2087/json-api/get_default_dmarc_record?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->getDefaultDmarcRecord(),
            ),
            new Probe(
                'whm1:is_sni_supported',
                'whm',
                MailSpec::IS_SNI_SUPPORTED,
                'https://test.oblak.host:2087/json-api/is_sni_supported?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->isSniSupported(),
            ),
            new Probe(
                'whm1:list_blocked_incoming_email_countries',
                'whm',
                MailSpec::LIST_BLOCKED_INCOMING_EMAIL_COUNTRIES,
                'https://test.oblak.host:2087/json-api/list_blocked_incoming_email_countries?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->listBlockedIncomingEmailCountries(),
            ),
            new Probe(
                'whm1:list_blocked_incoming_email_domains',
                'whm',
                MailSpec::LIST_BLOCKED_INCOMING_EMAIL_DOMAINS,
                'https://test.oblak.host:2087/json-api/list_blocked_incoming_email_domains?api.version=1',
                static fn (Client $client): mixed => $client->whm()->mail()->listBlockedIncomingEmailDomains(),
            ),
            new Probe(
                'whm1:list_pops_for',
                'whm',
                MailSpec::LIST_POPS_FOR,
                'https://test.oblak.host:2087/json-api/list_pops_for?api.version=1&user=npr',
                static fn (Client $client): mixed => $client->whm()->mail()->listPopsFor('npr'),
            ),
            new Probe(
                'whm1:mail_sni_status',
                'whm',
                MailSpec::MAIL_SNI_STATUS,
                'https://test.oblak.host:2087/json-api/mail_sni_status?api.version=1&domain=npr.rs',
                static fn (Client $client): mixed => $client->whm()->mail()->mailSniStatus('npr.rs'),
            ),
            new Probe(
                'whm1:get_user_email_forward_destination',
                'whm',
                MailSpec::GET_USER_EMAIL_FORWARD_DESTINATION,
                'https://test.oblak.host:2087/json-api/get_user_email_forward_destination?api.version=1&user=npr',
                static fn (Client $client): mixed => $client->whm()->mail()->getUserEmailForwardDestination('npr'),
            ),
        ];
    }
}
