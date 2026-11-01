<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\MarketSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/**
 * Fixed x-readonly Market reads with real selectors only; no order, product, cart, token or queue action.
 *
 * Returned login URLs are structural evidence only: nothing follows them, logs in or consumes a credential.
 */
final class MarketReadsProbes
{
    /** The only provider that the private selector check found on the designated server. */
    public const PROVIDER = 'cPStore';

    /**
     * The pinned parameter example. It names no server resource, and the returned URL is never followed.
     *
     * @see https://api.docs.cpanel.net/specifications/cpanel.openapi/market-integration/market-get_login_url
     */
    public const URL_AFTER_LOGIN = 'http://hostname.example.com/redirectionlocation.cgi?state';

    /**
     * Pinned read-only operations left out of this catalog, with the reason for each.
     *
     * Two take an `order_item_id`. The only pinned read that returns one is `get_pending_ssl_certificates`, which
     * lists only certificates the system currently polls and came back empty, so no read available here enumerated
     * an order item. That does not show that no order exists: a completed or expired order could hold a real ID.
     *
     * Two take a `product_name`. The catalog's `product` values are display labels, the pinned `product_name` example
     * is a different form, and no read enumerates that name. Review 5725042192 also reports a blocker for each that a
     * real name would not clear: `/var/cpanel/market/product_info/<name>` does not exist on this server, and
     * completion URL construction failed on token authentication because server information was unavailable from the
     * environment. The latter does not establish universal token or route incompatibility.
     *
     * @var array<string, string>
     */
    public const NOT_RUN = [
        'uapi:Market/get_certificate_status_details' => 'no-enumerated-order-item',
        'uapi:Market/get_completion_url' => 'no-known-product-name-and-reported-token-url-failure',
        'uapi:Market/get_product_info' => 'no-known-product-name-and-reported-missing-product-files',
        'uapi:Market/get_ssl_certificate_if_available' => 'no-enumerated-order-item',
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
        $provider = self::PROVIDER;
        $login = 'provider=' . $provider . '&url_after_login=' . rawurlencode(self::URL_AFTER_LOGIN);
        foreach (
            [
                ['get_providers_list', '', MarketSpec::GET_PROVIDERS_LIST,
                    static fn (UapiClient $account): mixed => $account->market()->getProvidersList()],
                ['get_all_products', '', MarketSpec::GET_ALL_PRODUCTS,
                    static fn (UapiClient $account): mixed => $account->market()->getAllProducts()],
                ['get_pending_ssl_certificates', 'provider=' . $provider, MarketSpec::GET_PENDING_SSL_CERTIFICATES,
                    static fn (UapiClient $account): mixed => $account->market()->getPendingSslCertificates($provider)],
                ['get_provider_specific_dcv_constraints', 'provider=' . $provider,
                    MarketSpec::GET_PROVIDER_SPECIFIC_DCV_CONSTRAINTS,
                    static fn (UapiClient $account): mixed
                        => $account->market()->getProviderSpecificDcvConstraints($provider)],
                ['get_login_url', $login, MarketSpec::GET_LOGIN_URL,
                    static fn (UapiClient $account): mixed
                        => $account->market()->getLoginUrl($provider, self::URL_AFTER_LOGIN)],
            ] as [$function, $query, $spec, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
                    ? 'https://test.oblak.host:2083/execute/Market/' . $function . ($query === '' ? '' : '?' . $query)
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=Market&cpanel.function=' . $function . ($query === '' ? '' : '&' . $query);
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
            'uapi:Market/' . $function,
            $route,
            $spec,
            $uri,
            static fn (Client $client): mixed => $invoke($client->uapi($route === 'direct-uapi' ? null : 'npr')),
        );
    }
}
