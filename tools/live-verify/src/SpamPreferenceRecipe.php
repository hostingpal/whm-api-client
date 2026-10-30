<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\SpamAssassinSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use SensitiveParameter;

/**
 * Fixed required_score-only phases followed by the reviewed read catalog once.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Step array{probe:Probe,method:string,body:string}
 * @phpstan-type Definition array{operation:string,route:'whm'|Route,spec:array<mixed>,method:string}
 */
final class SpamPreferenceRecipe
{
    private ?SpamPreferenceState $state = null;

    /** Bind one agreed, exactly restorable preflight snapshot before any POST can be authorized. */
    public function bind(#[SensitiveParameter] SpamPreferenceState $state): void
    {
        if ($this->state !== null) {
            throw new LogicException('Preference snapshot already bound.');
        }
        $this->state = $state;
    }

    /** @return array<string,Definition> */
    public static function definitions(): array
    {
        $result = [];
        foreach (['direct', 'bridge'] as $name) {
            $route = $name === 'direct' ? 'direct-uapi' : 'bridged-uapi';
            $result['preflight_' . $name] = self::definition($route, false);
        }
        foreach (['direct', 'bridge'] as $name) {
            $route = $name === 'direct' ? 'direct-uapi' : 'bridged-uapi';
            $result['set_' . $name] = self::definition($route, true);
            $result['read_' . $name] = self::definition($route, false);
            $result['restore_' . $name] = self::definition($route, true);
            $result['restored_' . $name . '_direct'] = self::definition('direct-uapi', false);
            $result['restored_' . $name . '_bridge'] = self::definition('bridged-uapi', false);
        }
        foreach (MailProtectionProbes::load() as $index => $probe) {
            $result['catalog_' . $index] = ['operation' => $probe->operation, 'route' => $probe->route,
                'spec' => $probe->spec, 'method' => 'GET'];
        }

        return $result;
    }

    /**
     * Independent literal wire encoding is checked against the generated method's actual request.
     *
     * @return Step
     */
    public function step(string $phase): array
    {
        $definition = self::definitions()[$phase] ?? null;
        if ($definition === null) {
            throw new LogicException('Unknown preference phase.');
        }
        if (str_starts_with($phase, 'catalog_')) {
            $index = (int) substr($phase, 8);

            return ['probe' => MailProtectionProbes::load()[$index], 'method' => 'GET', 'body' => ''];
        }
        $route = $definition['route'];
        $write = $definition['method'] === 'POST';
        if ($write && $this->state === null) {
            throw new LogicException('Preference writes require an agreed snapshot.');
        }
        $value = $write && str_starts_with($phase, 'set_') ? $this->state->target() : $this->state?->original();
        $function = $write ? 'update_user_preference' : 'get_user_preferences';
        $uri = $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/SpamAssassin/' . $function
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SpamAssassin&cpanel.function=' . $function;
        $body = $write ? 'preference=required_score' . ($value === null ? '' : '&value=' . rawurlencode($value)) : '';
        $probe = new Probe(
            $definition['operation'],
            $route,
            $definition['spec'],
            $uri,
            static function (#[SensitiveParameter] Client $client) use ($route, $write, $value): array {
                $group = $client->uapi($route === 'direct-uapi' ? null : 'npr')->spamAssassin();

                return $write ? $group->updateUserPreference('required_score', $value) : $group->getUserPreferences();
            },
        );

        return ['probe' => $probe, 'method' => $definition['method'], 'body' => $body];
    }

    /**
     * @param Route $route
     * @return Definition
     */
    private static function definition(string $route, bool $write): array
    {
        return ['operation' => 'uapi:SpamAssassin/' . ($write ? 'update_user_preference' : 'get_user_preferences'),
            'route' => $route,
            'spec' => $write ? SpamAssassinSpec::UPDATE_USER_PREFERENCE : SpamAssassinSpec::GET_USER_PREFERENCES,
            'method' => $write ? 'POST' : 'GET'];
    }
}
