<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\BoxTrapperSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\CpGreyListSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\EmailAuthSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SpamAssassinSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed account/domain reads; mailbox-specific and message-delivery operations are separate. */
final class MailProtectionProbes
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
                ['BoxTrapper', 'list_email_templates', BoxTrapperSpec::LIST_EMAIL_TEMPLATES, '',
                    static fn (UapiClient $account): array => $account->boxTrapper()->listEmailTemplates()],
                ['EmailAuth', 'validate_current_dkims', EmailAuthSpec::VALIDATE_CURRENT_DKIMS, 'domain=npr.rs',
                    static fn (UapiClient $account): array => $account->emailAuth()->validateCurrentDkims(['npr.rs'])],
                ['EmailAuth', 'validate_current_dmarcs', EmailAuthSpec::VALIDATE_CURRENT_DMARCS, 'domain=npr.rs',
                    static fn (UapiClient $account): array => $account->emailAuth()->validateCurrentDmarcs(['npr.rs'])],
                ['EmailAuth', 'validate_current_ptrs', EmailAuthSpec::VALIDATE_CURRENT_PTRS, 'domain=npr.rs',
                    static fn (UapiClient $account): array => $account->emailAuth()->validateCurrentPtrs(['npr.rs'])],
                ['EmailAuth', 'validate_current_spfs', EmailAuthSpec::VALIDATE_CURRENT_SPFS, 'domain=npr.rs',
                    static fn (UapiClient $account): array => $account->emailAuth()->validateCurrentSpfs(['npr.rs'])],
                ['SpamAssassin', 'get_symbolic_test_names', SpamAssassinSpec::GET_SYMBOLIC_TEST_NAMES, '',
                    static fn (UapiClient $account): array => $account->spamAssassin()->getSymbolicTestNames()],
                ['SpamAssassin', 'get_user_preferences', SpamAssassinSpec::GET_USER_PREFERENCES, '',
                    static fn (UapiClient $account): array => $account->spamAssassin()->getUserPreferences()],
                ['cPGreyList', 'has_greylisting_enabled', CpGreyListSpec::HAS_GREYLISTING_ENABLED, '',
                    static fn (UapiClient $account): array => $account->cpGreyList()->hasGreylistingEnabled()],
                ['cPGreyList', 'list_domains', CpGreyListSpec::LIST_DOMAINS, '',
                    static fn (UapiClient $account): array => $account->cpGreyList()->listDomains()],
            ] as [$module, $function, $spec, $parameters, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
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
