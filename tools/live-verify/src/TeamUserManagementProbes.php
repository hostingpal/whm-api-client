<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\PasswdStrengthSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\TeamRolesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\TeamSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\UserManagerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed team counts, role descriptions, subaccount inventory and password-policy reads. */
final class TeamUserManagementProbes
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
                ['Team', 'get_team_users_with_roles_count',
                    TeamSpec::GET_TEAM_USERS_WITH_ROLES_COUNT, '',
                    static fn (UapiClient $account): mixed => $account->team()->getTeamUsersWithRolesCount()],
                ['TeamRoles', 'list_feature_descriptions', TeamRolesSpec::LIST_FEATURE_DESCRIPTIONS, '',
                    static fn (UapiClient $account): mixed => $account->teamRoles()->listFeatureDescriptions()],
                ['UserManager', 'list_users', UserManagerSpec::LIST_USERS, 'flat=1',
                    static fn (UapiClient $account): mixed => $account->userManager()->listUsers(flat: true)],
                ['PasswdStrength', 'get_required_strength', PasswdStrengthSpec::GET_REQUIRED_STRENGTH, 'app=webdisk',
                    static fn (UapiClient $account): mixed =>
                        $account->passwdStrength()->getRequiredStrength(app: 'webdisk')],
            ] as [$module, $function, $spec, $query, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
                    ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
                if ($query !== '') {
                    $uri .= ($direct ? '?' : '&') . $query;
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
            static fn (Client $client): mixed => $invoke(
                $route === 'direct-uapi' ? $client->uapi() : $client->uapi('npr'),
            ),
        );
    }
}
