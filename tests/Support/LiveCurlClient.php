<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use Closure;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Test-only HTTPS adapter restricted to the approved read-only requests. */
final readonly class LiveCurlClient implements ClientInterface
{
    /** @var Closure(RequestInterface): ResponseInterface */
    private Closure $execute;

    /**
     * An optional execution boundary lets offline tests prove rejection before cURL.
     *
     * @param (Closure(RequestInterface): ResponseInterface)|null $execute
     */
    public function __construct(?Closure $execute = null)
    {
        $this->execute = $execute ?? self::sendCurl(...);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $approvedUris = [
            'https://test.oblak.host:2087/json-api/get_feature_metadata?api.version=1',
            'https://test.oblak.host:2087/json-api/get_feature_names?api.version=1',
            'https://test.oblak.host:2087/json-api/get_featurelist_data?api.version=1&featurelist=default',
            'https://test.oblak.host:2087/json-api/get_featurelists?api.version=1',
            'https://test.oblak.host:2087/json-api/get_featurelists_by_package_types?api.version=1',
            'https://test.oblak.host:2087/json-api/get_users_features_settings?api.version=1&user=npr&feature=autossl',
            'https://test.oblak.host:2087/json-api/verify_user_has_feature?api.version=1&user=npr&feature=autossl',
            'https://test.oblak.host:2087/json-api/php_get_installed_versions?api.version=1',
            'https://test.oblak.host:2087/json-api/php_get_system_default_version?api.version=1',
            'https://test.oblak.host:2087/json-api/php_get_handlers?api.version=1',
            'https://test.oblak.host:2087/json-api/php_get_default_accounts_to_fpm?api.version=1',
            'https://test.oblak.host:2087/json-api/php_get_old_fpm_flag?api.version=1',
            'https://test.oblak.host:2087/json-api/is_conversion_in_progress?api.version=1',
            'https://test.oblak.host:2087/json-api/php_get_vhosts_by_version?api.version=1&version=ea-php82',
            'https://test.oblak.host:2083/execute/DomainInfo/primary_domain',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=primary_domain',
            'https://test.oblak.host:2083/execute/DomainInfo/main_domain_builtin_subdomain_aliases',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=main_domain_builtin_subdomain_aliases',
            'https://test.oblak.host:2083/execute/LangPHP/php_get_system_default_version',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_system_default_version',
            'https://test.oblak.host:2083/execute/LangPHP/php_get_domain_handler?type=home',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_domain_handler&type=home',
            'https://test.oblak.host:2083/execute/LangPHP/php_get_impacted_domains?domain=npr.rs',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_impacted_domains&domain=npr.rs',
            'https://test.oblak.host:2083/execute/Features/list_features',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Features&cpanel.function=list_features',
            'https://test.oblak.host:2083/execute/DNS/lookup?domain=npr.rs',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DNS&cpanel.function=lookup&domain=npr.rs',
            'https://test.oblak.host:2083/execute/DNS/fetch_cpanel_generated_domains?domain=npr.rs',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DNS&cpanel.function=fetch_cpanel_generated_domains&domain=npr.rs',
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            'https://test.oblak.host:2087/json-api/gethostname?api.version=1',
            'https://test.oblak.host:2087/json-api/systemloadavg?api.version=1',
            'https://test.oblak.host:2087/json-api/getdiskusage?api.version=1',
            'https://test.oblak.host:2087/json-api/get_domain_info?api.version=1',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
                . '&cpanel.user=npr&cpanel.module=DomainInfo&cpanel.function=list_domains',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
                . '&cpanel.user=npr&cpanel.module=LangPHP&cpanel.function=php_get_installed_versions',
            'https://test.oblak.host:2083/execute/DomainInfo/list_domains',
            'https://test.oblak.host:2083/execute/LangPHP/php_get_installed_versions',
        ];
        if ($request->getMethod() !== 'GET' || !in_array((string) $request->getUri(), $approvedUris, true)) {
            throw new LogicException('Request outside the approved live-test scope.');
        }

        return ($this->execute)($request);
    }

    /** Make one verified HTTPS request with no redirect following or retries. */
    private static function sendCurl(RequestInterface $request): ResponseInterface
    {
        LiveTestEnvironment::fromEnvironment();
        $curl = curl_init((string) $request->getUri());
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize the live HTTPS transport.');
        }

        try {
            curl_setopt_array($curl, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $request->getHeaderLine('Authorization'),
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $errorNumber = curl_errno($curl);
        } finally {
            curl_close($curl);
        }

        if (!is_string($body)) {
            throw new class (
                'Live HTTPS transport failed (cURL code ' . $errorNumber . ').',
            ) extends RuntimeException implements ClientExceptionInterface {
            };
        }

        return new Response($status, [], $body);
    }
}
