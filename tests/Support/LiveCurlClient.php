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

/**
 * Test-only HTTPS adapter restricted to the approved requests.
 *
 * Read-only GET and POST requests are approved by exact method, URI, and body.
 * Lifecycle mutations are approved only when the client was built for an
 * explicitly named throwaway account and the operation's single target key names
 * that account, without duplicate keys or the alias account key. Three
 * read-only DNS GETs are approved only for a zone line or nameserver host bound
 * at construction from an earlier read-only response.
 */
final readonly class LiveCurlClient implements ClientInterface
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const CPANEL = 'https://test.oblak.host:2083/execute/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=npr';

    /** Approved read-only GET requests; the body must be empty. */
    private const READ_ONLY_GET = [
        self::WHM . 'get_feature_metadata?api.version=1',
        self::WHM . 'get_feature_names?api.version=1',
        self::WHM . 'get_featurelist_data?api.version=1&featurelist=default',
        self::WHM . 'get_featurelists?api.version=1',
        self::WHM . 'get_featurelists_by_package_types?api.version=1',
        self::WHM . 'get_users_features_settings?api.version=1&user=npr&feature=autossl',
        self::WHM . 'verify_user_has_feature?api.version=1&user=npr&feature=autossl',
        self::WHM . 'php_get_installed_versions?api.version=1',
        self::WHM . 'php_get_system_default_version?api.version=1',
        self::WHM . 'php_get_handlers?api.version=1',
        self::WHM . 'php_get_default_accounts_to_fpm?api.version=1',
        self::WHM . 'php_get_old_fpm_flag?api.version=1',
        self::WHM . 'is_conversion_in_progress?api.version=1',
        self::WHM . 'php_get_vhosts_by_version?api.version=1&version=ea-php82',
        self::CPANEL . 'DomainInfo/primary_domain',
        self::BRIDGE . '&cpanel.module=DomainInfo&cpanel.function=primary_domain',
        self::CPANEL . 'DomainInfo/main_domain_builtin_subdomain_aliases',
        self::BRIDGE . '&cpanel.module=DomainInfo&cpanel.function=main_domain_builtin_subdomain_aliases',
        self::CPANEL . 'LangPHP/php_get_system_default_version',
        self::BRIDGE . '&cpanel.module=LangPHP&cpanel.function=php_get_system_default_version',
        self::CPANEL . 'LangPHP/php_get_domain_handler?type=home',
        self::BRIDGE . '&cpanel.module=LangPHP&cpanel.function=php_get_domain_handler&type=home',
        self::CPANEL . 'LangPHP/php_get_impacted_domains?domain=npr.rs',
        self::BRIDGE . '&cpanel.module=LangPHP&cpanel.function=php_get_impacted_domains&domain=npr.rs',
        self::CPANEL . 'Features/list_features',
        self::BRIDGE . '&cpanel.module=Features&cpanel.function=list_features',
        self::CPANEL . 'DNS/lookup?domain=npr.rs',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=lookup&domain=npr.rs',
        self::CPANEL . 'DNS/fetch_cpanel_generated_domains?domain=npr.rs',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=fetch_cpanel_generated_domains&domain=npr.rs',
        self::WHM . 'version?api.version=1',
        self::WHM . 'gethostname?api.version=1',
        self::WHM . 'systemloadavg?api.version=1',
        self::WHM . 'getdiskusage?api.version=1',
        self::WHM . 'get_domain_info?api.version=1',
        self::BRIDGE . '&cpanel.module=DomainInfo&cpanel.function=list_domains',
        self::BRIDGE . '&cpanel.module=LangPHP&cpanel.function=php_get_installed_versions',
        self::CPANEL . 'DomainInfo/list_domains',
        self::CPANEL . 'LangPHP/php_get_installed_versions',
        self::WHM . 'listaccts?api.version=1',
        self::WHM . 'listaccts?api.version=1&search=npr&searchtype=user&searchmethod=exact',
        self::WHM . 'listaccts?api.version=1&search=npr&searchtype=user&searchmethod=exact&want=user',
        self::WHM . 'listaccts?api.version=1&search=nosuchacct9dy&searchtype=user&searchmethod=exact',
        self::WHM . 'listaccts?api.version=1&search=%5Enosuchacct9dy%24&searchtype=user&searchmethod=regex',
        self::WHM . 'listaccts?api.version=1&search=%5Enosuchacct9dy%24&searchtype=user&searchmethod=regex&want=user',
        self::WHM . 'accountsummary?api.version=1&user=nosuchacct9dy',
        self::WHM . 'accountsummary?api.version=1&user=npr',
        self::WHM . 'listpkgs?api.version=1',
        self::WHM . 'listpkgs?api.version=1&want=creatable',
        self::WHM . 'listzones?api.version=1',
        self::WHM . 'parse_dns_zone?api.version=1&zone=npr.rs',
        self::WHM . 'listmxs?api.version=1&domain=npr.rs',
        self::WHM . 'resolvedomainname?api.version=1&domain=npr.rs',
        self::WHM . 'get_nameserver_config?api.version=1',
        self::WHM . 'has_local_authority?api.version=1&domain=npr.rs',
        self::WHM . 'is_alias_available?api.version=1',
        self::WHM . 'is_https_available?api.version=1',
        self::WHM . 'is_svcb_available?api.version=1',
        self::WHM . 'export_zone_files?api.version=1&zone=npr.rs',
        self::WHM . 'fetch_ds_records_for_domains?api.version=1&domain=npr.rs',
        self::CPANEL . 'DNS/parse_zone?zone=npr.rs',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=parse_zone&zone=npr.rs',
        self::CPANEL . 'DNS/has_local_authority?domain=npr.rs',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=has_local_authority&domain=npr.rs',
        self::CPANEL . 'DNS/ensure_domains_reside_only_locally?domain=npr.rs',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=ensure_domains_reside_only_locally&domain=npr.rs',
        self::CPANEL . 'DNS/ensure_domains_use_recognized_nameservers?domain=npr.rs',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=ensure_domains_use_recognized_nameservers&domain=npr.rs',
        self::CPANEL . 'DNS/is_alias_available',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=is_alias_available',
        self::CPANEL . 'DNS/is_https_available',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=is_https_available',
        self::CPANEL . 'DNS/is_svcb_available',
        self::BRIDGE . '&cpanel.module=DNS&cpanel.function=is_svcb_available',
    ];

    /** Approved read-only POST requests as exact [URI, form body] pairs. */
    private const READ_ONLY_POST = [
        [self::WHM . 'version?api.version=1', ''],
        [self::WHM . 'listaccts?api.version=1', 'search=npr&searchmethod=exact&searchtype=user'],
        [self::CPANEL . 'DomainInfo/primary_domain', ''],
        [self::BRIDGE . '&cpanel.module=DomainInfo&cpanel.function=primary_domain', ''],
    ];

    /**
     * Mutations approved only for the explicitly named throwaway account, mapped to
     * the single body key that names their target account.
     */
    private const LIFECYCLE = [
        'createacct' => 'username',
        'suspendacct' => 'user',
        'unsuspendacct' => 'user',
        'removeacct' => 'username',
        'passwd' => 'user',
        'changepackage' => 'user',
        'modifyacct' => 'user',
    ];

    /** The other account key of each target key, which the server may also read. */
    private const ALIAS = ['user' => 'username', 'username' => 'user'];

    /** @var Closure(RequestInterface): ResponseInterface */
    private Closure $execute;

    /**
     * An optional execution boundary lets offline tests prove rejection before cURL.
     *
     * The DNS live test derives two read-only GET parameters from earlier
     * responses: a line of the test zone (from parse_dns_zone) and a nameserver
     * host (from get_nameserver_config). Binding a value approves exactly the
     * corresponding getzonerecord or lookupnsip/lookupnsips URI for that value.
     *
     * @param (Closure(RequestInterface): ResponseInterface)|null $execute
     * @param string|null $lifecycleAccount Throwaway account whose lifecycle mutations are approved.
     * @param int|null $zoneLine Test zone line whose getzonerecord GET is approved (at least 1).
     * @param string|null $nameserver Bare hostname whose lookupnsip and lookupnsips GETs are approved.
     * @throws LogicException If a bound zone line or nameserver host is malformed.
     */
    public function __construct(
        ?Closure $execute = null,
        private ?string $lifecycleAccount = null,
        private ?int $zoneLine = null,
        private ?string $nameserver = null,
    ) {
        $label = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';
        if (
            ($zoneLine !== null && $zoneLine < 1)
            || ($nameserver !== null && preg_match('/\A' . $label . '(?:\.' . $label . ')+\z/i', $nameserver) !== 1)
        ) {
            throw new LogicException('Derived live-test approvals require a positive line and a bare hostname.');
        }

        $this->execute = $execute ?? self::sendCurl(...);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $approved = match ($request->getMethod()) {
            'GET' => $body === '' && (
                in_array($uri, self::READ_ONLY_GET, true)
                || $this->lifecycleSummary($uri)
                || $this->derivedDnsRead($uri)
            ),
            'POST' => in_array([$uri, $body], self::READ_ONLY_POST, true) || $this->lifecycleMutation($uri, $body),
            default => false,
        };
        if (!$approved) {
            throw new LogicException('Request outside the approved live-test scope.');
        }

        return ($this->execute)($request);
    }

    /** Approve the read-only DNS requests whose parameter was bound from an earlier response. */
    private function derivedDnsRead(string $uri): bool
    {
        $approved = [];
        if ($this->zoneLine !== null) {
            $approved[] = self::WHM . 'getzonerecord?api.version=1&domain=npr.rs&line=' . $this->zoneLine;
        }

        if ($this->nameserver !== null) {
            $host = rawurlencode($this->nameserver);
            $approved[] = self::WHM . 'lookupnsip?api.version=1&host=' . $host;
            $approved[] = self::WHM . 'lookupnsips?api.version=1&host=' . $host;
        }

        return in_array($uri, $approved, true);
    }

    /** Approve the summary of the throwaway account while a lifecycle runs. */
    private function lifecycleSummary(string $uri): bool
    {
        return $this->lifecycleAccount !== null
            && $uri === self::WHM . 'accountsummary?api.version=1&user=' . $this->lifecycleAccount;
    }

    /**
     * Approve a lifecycle mutation only when its body targets the throwaway account.
     *
     * The body is decoded pair by pair instead of with parse_str(), which keeps only
     * the last duplicate and rewrites bracketed names. Every key must be unique after
     * decoding, the operation's target key must name the account exactly, and the
     * alias key must be absent, so neither a duplicate nor an alias can redirect the
     * mutation to another account.
     */
    private function lifecycleMutation(string $uri, string $body): bool
    {
        if (
            $this->lifecycleAccount === null
            || !str_starts_with($uri, self::WHM)
            || !str_ends_with($uri, '?api.version=1')
        ) {
            return false;
        }
        $operation = substr($uri, strlen(self::WHM), -strlen('?api.version=1'));
        $target = self::LIFECYCLE[$operation] ?? null;
        if ($target === null) {
            return false;
        }

        $parameters = [];
        foreach ($body === '' ? [] : explode('&', $body) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode($key);
            if (array_key_exists($key, $parameters)) {
                return false;
            }
            $parameters[$key] = rawurldecode($value);
        }

        return !array_key_exists(self::ALIAS[$target], $parameters)
            && ($parameters[$target] ?? null) === $this->lifecycleAccount;
    }

    /** Make one verified HTTPS request with no redirect following or retries. */
    private static function sendCurl(RequestInterface $request): ResponseInterface
    {
        LiveTestEnvironment::fromEnvironment();
        $curl = curl_init((string) $request->getUri());
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize the live HTTPS transport.');
        }

        $headers = [
            'Authorization: ' . $request->getHeaderLine('Authorization'),
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if ($request->getMethod() === 'POST') {
            $headers[] = 'Content-Type: ' . $request->getHeaderLine('Content-Type');
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $request->getBody();
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        try {
            curl_setopt_array($curl, $options);
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
