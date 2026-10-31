<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\VariablesSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Reviewed broad user-information read, explicitly excluding the documented token field. */
final class AccountEnvironmentReviewProbes
{
    /**
     * Fixed 60 names verified present on npr by reviewer 5720313728 on 11.138.0.19.
     * Seven other pinned names are absent on this account; they remain client-supported.
     * Never derive this allowlist from live payloads or future schemas.
     * Changing the source contract requires another explicit review before expanding this recipe.
     *
     * @var list<string>
     */
    private const USER_FIELDS = [
        'backup_enabled',
        'bandwidth_limit',
        'cgi_enabled',
        'contact_email',
        'contact_email_2',
        'cpanel_root_directory',
        'created',
        'created_in_version',
        'database_owner',
        'dead_domains',
        'demo_mode',
        'disk_block_limit',
        'dkim_enabled',
        'domain',
        'domains',
        'feature',
        'feature_list',
        'gid',
        'home',
        'home_directory_links',
        'ip',
        'last_modified',
        'legacy_backup_enabled',
        'locale',
        'mailbox_format',
        'maximum_addon_domains',
        'maximum_databases',
        'maximum_email_account_disk_quota',
        'maximum_ftp_accounts',
        'maximum_mail_accounts',
        'maximum_mailing_lists',
        'maximum_parked_domains',
        'maximum_passenger_apps',
        'maximum_subdomains',
        'mxcheck',
        'notify_account_authn_link',
        'notify_account_authn_link_notification_disabled',
        'notify_account_login',
        'notify_account_login_for_known_netblock',
        'notify_account_login_notification_disabled',
        'notify_autossl_expiry',
        'notify_autossl_expiry_coverage',
        'notify_autossl_renewal_coverage',
        'notify_autossl_renewal_coverage_reduced',
        'notify_autossl_renewal_uncovered_domains',
        'notify_contact_address_change',
        'notify_contact_address_change_notification_disabled',
        'notify_disk_limit',
        'notify_password_change',
        'notify_password_change_notification_disabled',
        'owner',
        'package_extensions',
        'plan',
        'shell',
        'spf_enabled',
        'ssl_default_key_type',
        'theme',
        'uid',
        'user',
        'utf8_mailbox',
    ];

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
        $query = implode('&', array_map(static fn (string $field): string => 'name=' . $field, self::USER_FIELDS));
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            $uri = $route === 'direct-uapi'
                ? 'https://test.oblak.host:2083/execute/Variables/get_user_information?' . $query
                : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=Variables&cpanel.function=get_user_information&' . $query;
            $probes[] = new Probe(
                'uapi:Variables/get_user_information',
                $route,
                VariablesSpec::GET_USER_INFORMATION,
                $uri,
                static fn (Client $client): mixed => $client->uapi($route === 'direct-uapi' ? null : 'npr')
                    ->variables()->getUserInformation(name: self::USER_FIELDS),
                argumentVariant: 'reviewed-present-fields-except-token',
            );
        }

        return $probes;
    }
}
