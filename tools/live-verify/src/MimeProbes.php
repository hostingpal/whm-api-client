<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\MimeSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Reviewed local configuration reads; no redirects or account configuration are changed. */
final class MimeProbes
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
                ['get_redirect', MimeSpec::GET_REDIRECT, 'domain=npr.rs',
                    static fn (UapiClient $account): array => $account->mime()->getRedirect(domain: 'npr.rs')],
                ['list_handlers', MimeSpec::LIST_HANDLERS, 'type=user',
                    static fn (UapiClient $account): array => $account->mime()->listHandlers(type: 'user')],
                ['list_hotlinks', MimeSpec::LIST_HOTLINKS, '',
                    static fn (UapiClient $account): array => $account->mime()->listHotlinks()],
                ['list_mime', MimeSpec::LIST_MIME, 'type=user',
                    static fn (UapiClient $account): array => $account->mime()->listMime(type: 'user')],
                ['list_redirects', MimeSpec::LIST_REDIRECTS, '',
                    static fn (UapiClient $account): array => $account->mime()->listRedirects()],
                ['redirect_info', MimeSpec::REDIRECT_INFO, 'domain=npr.rs&url=http%3A%2F%2Fnpr.rs',
                    static fn (UapiClient $account): array => $account->mime()->redirectInfo(
                        domain: 'npr.rs',
                        url: 'http://npr.rs',
                    )],
            ] as [$function, $spec, $parameters, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
                    ? 'https://test.oblak.host:2083/execute/Mime/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=Mime&cpanel.function=' . $function;
                if ($parameters !== '') {
                    $uri .= ($direct ? '?' : '&') . $parameters;
                }
                $probes[] = self::probe($function, $route, $spec, $uri, $invoke);
            }
        }

        return $probes;
    }

    /**
     * @param 'direct-uapi'|'bridged-uapi' $route
     * @param array<mixed> $spec
     * @param Closure(UapiClient): mixed $invoke
     */
    private static function probe(string $function, string $route, array $spec, string $uri, Closure $invoke): Probe
    {
        return new Probe(
            'uapi:Mime/' . $function,
            $route,
            $spec,
            $uri,
            static fn (Client $client): mixed => $invoke($client->uapi($route === 'direct-uapi' ? null : 'npr')),
        );
    }
}
