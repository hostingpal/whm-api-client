<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\AccountEnhancementsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ContactInformationSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ContactUsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\Ea4Spec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\LocaleSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\NotificationsSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ServerInformationSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ThemesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\VariablesSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed account environment and preference reads; no settings, tokens or messages are changed. */
final class AccountEnvironmentProbes
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
                ['ContactInformation', 'get_notification_preferences',
                    ContactInformationSpec::GET_NOTIFICATION_PREFERENCES,
                    static fn (UapiClient $account): mixed =>
                        $account->contactInformation()->getNotificationPreferences()],
                ['Contactus', 'is_enabled', ContactUsSpec::IS_ENABLED,
                    static fn (UapiClient $account): mixed =>
                        $account->contactUs()->isEnabled()],
                ['Locale', 'get_attributes', LocaleSpec::GET_ATTRIBUTES,
                    static fn (UapiClient $account): mixed =>
                        $account->locale()->getAttributes()],
                ['Locale', 'list_locales', LocaleSpec::LIST_LOCALES,
                    static fn (UapiClient $account): mixed =>
                        $account->locale()->listLocales()],
                ['Themes', 'list', ThemesSpec::LIST,
                    static fn (UapiClient $account): mixed =>
                        $account->themes()->listThemes()],
                ['Notifications', 'get_notifications_count', NotificationsSpec::GET_NOTIFICATIONS_COUNT,
                    static fn (UapiClient $account): mixed =>
                        $account->notifications()->getNotificationsCount()],
                ['AccountEnhancements', 'list', AccountEnhancementsSpec::LIST,
                    static fn (UapiClient $account): mixed =>
                        $account->accountEnhancements()->listEnhancements()],
                ['EA4', 'get_php_recommendations', Ea4Spec::GET_PHP_RECOMMENDATIONS,
                    static fn (UapiClient $account): mixed =>
                        $account->ea4()->getPhpRecommendations()],
                ['EA4', 'get_recommendations', Ea4Spec::GET_RECOMMENDATIONS,
                    static fn (UapiClient $account): mixed =>
                        $account->ea4()->getRecommendations()],
                ['ServerInformation', 'get_information', ServerInformationSpec::GET_INFORMATION,
                    static fn (UapiClient $account): mixed =>
                        $account->serverInformation()->getInformation()],
                ['Variables', 'get_server_information', VariablesSpec::GET_SERVER_INFORMATION,
                    static fn (UapiClient $account): mixed =>
                        $account->variables()->getServerInformation()],
                ['Variables', 'get_session_information', VariablesSpec::GET_SESSION_INFORMATION,
                    static fn (UapiClient $account): mixed =>
                        $account->variables()->getSessionInformation()],
                ['Variables', 'get_user_information', VariablesSpec::GET_USER_INFORMATION,
                    static fn (UapiClient $account): mixed =>
                        $account->variables()->getUserInformation(name: 'domain')],
            ] as [$module, $function, $spec, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
                    ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
                if ($module === 'Variables' && $function === 'get_user_information') {
                    $uri .= ($direct ? '?' : '&') . 'name=domain';
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
