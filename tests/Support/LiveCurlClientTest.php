<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class LiveCurlClientTest extends TestCase
{
    #[DataProvider('outsideApprovedScope')]
    public function testRejectsOutOfScopeRequestsBeforeExecutingCurl(
        string $method,
        string $uri,
        string $body = '',
        ?string $lifecycleAccount = null,
    ): void {
        $executed = false;
        $client = new LiveCurlClient(static function (RequestInterface $request) use (&$executed): ResponseInterface {
            $executed = true;

            return new Response(200);
        }, $lifecycleAccount);

        try {
            $client->sendRequest(new Request($method, $uri, ['Authorization' => 'whm root:synthetic-token'], $body));
            self::fail('The live transport accepted a request outside its approved scope.');
        } catch (LogicException) {
            self::assertFalse($executed, 'Rejected requests must not reach the cURL execution boundary.');
        }
    }

    /** @return iterable<string, array{string, string, 2?: string, 3?: ?string}> */
    public static function outsideApprovedScope(): iterable
    {
        yield 'unapproved write' => ['POST', 'https://test.oblak.host:2087/json-api/gethostname?api.version=1'];
        yield 'approved write with another body' => [
            'POST',
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            'extra=1',
        ];
        yield 'write filter with another account' => [
            'POST',
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1',
            'search=root&searchmethod=exact&searchtype=user',
        ];
        yield 'another absent domain' => [
            'GET',
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1'
                . '&search=another.example&searchtype=domain&searchmethod=exact',
        ];
        yield 'other missing-user want' => [
            'GET',
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1'
                . '&search=nosuchacct9dy&searchtype=user&searchmethod=exact&want=domain',
        ];
        yield 'other method' => ['PUT', 'https://test.oblak.host:2087/json-api/version?api.version=1'];
        yield 'read with body' => ['GET', 'https://test.oblak.host:2087/json-api/version?api.version=1', 'x=1'];
        yield 'mutation without lifecycle account' => [
            'POST',
            'https://test.oblak.host:2087/json-api/createacct?api.version=1',
            'username=lifecycle1&domain=lifecycle1.example',
        ];
        yield 'mutation targeting another account' => [
            'POST',
            'https://test.oblak.host:2087/json-api/removeacct?api.version=1',
            'username=npr',
            'lifecycle1',
        ];
        yield 'mutation targeting the protected account via user' => [
            'POST',
            'https://test.oblak.host:2087/json-api/suspendacct?api.version=1',
            'user=npr&reason=x',
            'lifecycle1',
        ];
        yield 'mutation without a target' => [
            'POST',
            'https://test.oblak.host:2087/json-api/passwd?api.version=1',
            'password=x',
            'lifecycle1',
        ];
        yield 'alias key overriding the target of a user mutation' => [
            'POST',
            'https://test.oblak.host:2087/json-api/suspendacct?api.version=1',
            'username=lifecycle1&user=npr',
            'lifecycle1',
        ];
        yield 'alias key beside the target of a username mutation' => [
            'POST',
            'https://test.oblak.host:2087/json-api/createacct?api.version=1',
            'username=lifecycle1&user=npr&domain=lc.example',
            'lifecycle1',
        ];
        yield 'alias key beside the target of a user mutation' => [
            'POST',
            'https://test.oblak.host:2087/json-api/passwd?api.version=1',
            'user=lifecycle1&username=npr&password=x',
            'lifecycle1',
        ];
        yield 'alias key instead of the target' => [
            'POST',
            'https://test.oblak.host:2087/json-api/removeacct?api.version=1',
            'user=lifecycle1',
            'lifecycle1',
        ];
        yield 'duplicate target keys with the last one approved' => [
            'POST',
            'https://test.oblak.host:2087/json-api/passwd?api.version=1',
            'user=npr&user=lifecycle1&password=x',
            'lifecycle1',
        ];
        yield 'duplicate target keys with an encoded name' => [
            'POST',
            'https://test.oblak.host:2087/json-api/modifyacct?api.version=1',
            'us%65r=npr&user=lifecycle1',
            'lifecycle1',
        ];
        yield 'duplicate unrelated keys' => [
            'POST',
            'https://test.oblak.host:2087/json-api/modifyacct?api.version=1',
            'user=lifecycle1&newuser=a&newuser=b',
            'lifecycle1',
        ];
        yield 'bracketed target key' => [
            'POST',
            'https://test.oblak.host:2087/json-api/unsuspendacct?api.version=1',
            'user[]=lifecycle1',
            'lifecycle1',
        ];
        yield 'target with a trailing character' => [
            'POST',
            'https://test.oblak.host:2087/json-api/changepackage?api.version=1',
            'user=lifecycle1%20&pkg=default',
            'lifecycle1',
        ];
        yield 'unlisted mutation for the lifecycle account' => [
            'POST',
            'https://test.oblak.host:2087/json-api/removeacct',
            'username=lifecycle1',
            'lifecycle1',
        ];
        yield 'unlisted operation for the lifecycle account' => [
            'POST',
            'https://test.oblak.host:2087/json-api/terminate?api.version=1',
            'username=lifecycle1',
            'lifecycle1',
        ];
        yield 'summary of another account during a lifecycle' => [
            'GET',
            'https://test.oblak.host:2087/json-api/accountsummary?api.version=1&user=root',
            '',
            'lifecycle1',
        ];
        yield 'unencrypted' => ['GET', 'http://test.oblak.host:2087/json-api/version?api.version=1'];
        yield 'another host' => ['GET', 'https://elsewhere.example:2087/json-api/version?api.version=1'];
        yield 'wrong port' => ['GET', 'https://test.oblak.host:2083/json-api/version?api.version=1'];
        yield 'another operation' => ['GET', 'https://test.oblak.host:2087/json-api/createacct?api.version=1'];
        yield 'missing version' => ['GET', 'https://test.oblak.host:2087/json-api/version'];
        yield 'extra query' => ['GET', 'https://test.oblak.host:2087/json-api/version?api.version=1&extra=1'];
        yield 'query in direct call' => [
            'GET',
            'https://test.oblak.host:2083/execute/DomainInfo/list_domains?hide_temporary_domains=1',
        ];
        yield 'another account' => [
            'GET',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
                . '&cpanel.user=root&cpanel.module=DomainInfo&cpanel.function=list_domains',
        ];
        yield 'another bridge operation' => [
            'GET',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
                . '&cpanel.user=npr&cpanel.module=Email&cpanel.function=list_pops',
        ];
        yield 'another DNS zone' => [
            'GET',
            'https://test.oblak.host:2087/json-api/parse_dns_zone?api.version=1&zone=example.test',
        ];
        yield 'unbound zone record' => [
            'GET',
            'https://test.oblak.host:2087/json-api/getzonerecord?api.version=1&domain=npr.rs&line=1',
        ];
        yield 'unbound nameserver lookup' => [
            'GET',
            'https://test.oblak.host:2087/json-api/lookupnsip?api.version=1&host=ns1.example.test',
        ];
        yield 'another bridged DNS zone' => [
            'GET',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
                . '&cpanel.user=npr&cpanel.module=DNS&cpanel.function=parse_zone&zone=example.test',
        ];
        yield 'userinfo' => ['GET', 'https://root@test.oblak.host:2087/json-api/version?api.version=1'];
        yield 'fragment' => ['GET', 'https://test.oblak.host:2087/json-api/version?api.version=1#unexpected'];
    }

    #[DataProvider('approvedUris')]
    public function testAllowsExactlyTheApprovedReadOnlyRequests(string $uri): void
    {
        $client = new LiveCurlClient(static fn (RequestInterface $request): ResponseInterface => new Response(204));

        self::assertSame(204, $client->sendRequest(new Request('GET', $uri))->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function approvedUris(): iterable
    {
        yield 'inventory 0' => ['https://test.oblak.host:2087/json-api/get_feature_metadata?api.version=1'];
        yield 'inventory 1' => ['https://test.oblak.host:2087/json-api/get_feature_names?api.version=1'];
        yield 'inventory 2' => [
            'https://test.oblak.host:2087/json-api/get_featurelist_data?api.version=1'
                . '&featurelist=default',
        ];
        yield 'inventory 3' => ['https://test.oblak.host:2087/json-api/get_featurelists?api.version=1'];
        yield 'inventory 4' => [
            'https://test.oblak.host:2087/json-api/get_featurelists_by_package_types?api.version=1',
        ];
        yield 'inventory 5' => [
            'https://test.oblak.host:2087/json-api/get_users_features_settings?api.version=1'
                . '&user=npr&feature=autossl',
        ];
        yield 'inventory 6' => [
            'https://test.oblak.host:2087/json-api/verify_user_has_feature?api.version=1&user=npr'
                . '&feature=autossl',
        ];
        yield 'inventory 7' => ['https://test.oblak.host:2087/json-api/php_get_installed_versions?api.version=1'];
        yield 'inventory 8' => ['https://test.oblak.host:2087/json-api/php_get_system_default_version?api.version=1'];
        yield 'inventory 9' => ['https://test.oblak.host:2087/json-api/php_get_handlers?api.version=1'];
        yield 'inventory 10' => ['https://test.oblak.host:2087/json-api/php_get_default_accounts_to_fpm?api.version=1'];
        yield 'inventory 11' => ['https://test.oblak.host:2087/json-api/php_get_old_fpm_flag?api.version=1'];
        yield 'inventory 12' => ['https://test.oblak.host:2087/json-api/is_conversion_in_progress?api.version=1'];
        yield 'inventory 13' => [
            'https://test.oblak.host:2087/json-api/php_get_vhosts_by_version?api.version=1'
                . '&version=ea-php82',
        ];
        yield 'inventory 14' => ['https://test.oblak.host:2083/execute/DomainInfo/primary_domain'];
        yield 'inventory 15' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=primary_domain',
        ];
        yield 'inventory 16' => [
            'https://test.oblak.host:2083/execute/DomainInfo/main_domain_builtin_subdomain_aliases',
        ];
        yield 'inventory 17' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=main_domain_builtin_subdomain_aliases',
        ];
        yield 'inventory 18' => ['https://test.oblak.host:2083/execute/LangPHP/php_get_system_default_version'];
        yield 'inventory 19' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_system_default_version',
        ];
        yield 'inventory 20' => ['https://test.oblak.host:2083/execute/LangPHP/php_get_domain_handler?type=home'];
        yield 'inventory 21' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_domain_handler&type=home',
        ];
        yield 'inventory 22' => ['https://test.oblak.host:2083/execute/LangPHP/php_get_impacted_domains?domain=npr.rs'];
        yield 'inventory 23' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_impacted_domains&domain=npr.rs',
        ];
        yield 'inventory 24' => ['https://test.oblak.host:2083/execute/Features/list_features'];
        yield 'inventory 25' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Features&cpanel.function=list_features',
        ];
        yield 'inventory 26' => ['https://test.oblak.host:2083/execute/DNS/lookup?domain=npr.rs'];
        yield 'inventory 27' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DNS&cpanel.function=lookup&domain=npr.rs',
        ];
        yield 'inventory 28' => [
            'https://test.oblak.host:2083/execute/DNS/fetch_cpanel_generated_domains?domain=npr.rs',
        ];
        yield 'inventory 29' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DNS&cpanel.function=fetch_cpanel_generated_domains&domain=npr.rs',
        ];
        yield 'WHM version' => ['https://test.oblak.host:2087/json-api/version?api.version=1'];
        yield 'WHM hostname' => ['https://test.oblak.host:2087/json-api/gethostname?api.version=1'];
        yield 'WHM load average' => ['https://test.oblak.host:2087/json-api/systemloadavg?api.version=1'];
        yield 'WHM disk usage' => ['https://test.oblak.host:2087/json-api/getdiskusage?api.version=1'];
        yield 'WHM domains' => ['https://test.oblak.host:2087/json-api/get_domain_info?api.version=1'];
        yield 'bridged domains' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
                . '&cpanel.user=npr&cpanel.module=DomainInfo&cpanel.function=list_domains',
        ];
        yield 'bridged PHP' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
                . '&cpanel.user=npr&cpanel.module=LangPHP&cpanel.function=php_get_installed_versions',
        ];
        yield 'direct domains' => ['https://test.oblak.host:2083/execute/DomainInfo/list_domains'];
        yield 'direct PHP' => ['https://test.oblak.host:2083/execute/LangPHP/php_get_installed_versions'];
        yield 'WHM accounts' => ['https://test.oblak.host:2087/json-api/listaccts?api.version=1'];
        yield 'WHM filtered accounts' => [
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1'
                . '&search=npr&searchtype=user&searchmethod=exact',
        ];
        yield 'WHM absent exact user with want' => [
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1'
                . '&search=nosuchacct9dy&searchtype=user&searchmethod=exact&want=user',
        ];
        yield 'WHM absent exact domain' => [
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1'
                . '&search=nosuchacct9dy.example&searchtype=domain&searchmethod=exact',
        ];
        yield 'WHM account summary' => ['https://test.oblak.host:2087/json-api/accountsummary?api.version=1&user=npr'];
        yield 'WHM packages' => ['https://test.oblak.host:2087/json-api/listpkgs?api.version=1'];
        yield 'WHM creatable packages' => [
            'https://test.oblak.host:2087/json-api/listpkgs?api.version=1&want=creatable',
        ];
        yield 'WHM DNS zones' => ['https://test.oblak.host:2087/json-api/listzones?api.version=1'];
        yield 'WHM parsed zone' => ['https://test.oblak.host:2087/json-api/parse_dns_zone?api.version=1&zone=npr.rs'];
        yield 'WHM MX records' => ['https://test.oblak.host:2087/json-api/listmxs?api.version=1&domain=npr.rs'];
        yield 'WHM resolve domain' => [
            'https://test.oblak.host:2087/json-api/resolvedomainname?api.version=1&domain=npr.rs',
        ];
        yield 'WHM nameserver config' => ['https://test.oblak.host:2087/json-api/get_nameserver_config?api.version=1'];
        yield 'WHM local authority' => [
            'https://test.oblak.host:2087/json-api/has_local_authority?api.version=1&domain=npr.rs',
        ];
        yield 'WHM ALIAS availability' => ['https://test.oblak.host:2087/json-api/is_alias_available?api.version=1'];
        yield 'WHM HTTPS availability' => ['https://test.oblak.host:2087/json-api/is_https_available?api.version=1'];
        yield 'WHM SVCB availability' => ['https://test.oblak.host:2087/json-api/is_svcb_available?api.version=1'];
        yield 'WHM zone export' => [
            'https://test.oblak.host:2087/json-api/export_zone_files?api.version=1&zone=npr.rs',
        ];
        yield 'WHM DS records' => [
            'https://test.oblak.host:2087/json-api/fetch_ds_records_for_domains?api.version=1&domain=npr.rs',
        ];
        $dns = [
            'parse_zone' => 'zone=npr.rs',
            'has_local_authority' => 'domain=npr.rs',
            'ensure_domains_reside_only_locally' => 'domain=npr.rs',
            'ensure_domains_use_recognized_nameservers' => 'domain=npr.rs',
            'is_alias_available' => '',
            'is_https_available' => '',
            'is_svcb_available' => '',
        ];
        foreach ($dns as $function => $query) {
            yield 'direct DNS ' . $function => [
                'https://test.oblak.host:2083/execute/DNS/' . $function . ($query === '' ? '' : '?' . $query),
            ];
            yield 'bridged DNS ' . $function => [
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=DNS&cpanel.function=' . $function . ($query === '' ? '' : '&' . $query),
            ];
        }
    }

    public function testApprovesDerivedDnsReadsOnlyForTheBoundValues(): void
    {
        $prefix = 'https://test.oblak.host:2087/json-api/';
        $line = $prefix . 'getzonerecord?api.version=1&domain=npr.rs&line=7';
        $address = $prefix . 'lookupnsip?api.version=1&host=ns1.example.test';
        $addresses = $prefix . 'lookupnsips?api.version=1&host=ns1.example.test';
        $executed = 0;
        $respond = static function (RequestInterface $request) use (&$executed): ResponseInterface {
            $executed++;

            return new Response(204);
        };
        $bound = new LiveCurlClient($respond, zoneLine: 7, nameserver: 'ns1.example.test');
        foreach ([$line, $address, $addresses] as $uri) {
            self::assertSame(204, $bound->sendRequest(new Request('GET', $uri))->getStatusCode());
        }

        $rejected = [
            [$bound, 'GET', $prefix . 'getzonerecord?api.version=1&domain=npr.rs&line=8', ''],
            [$bound, 'GET', $prefix . 'getzonerecord?api.version=1&domain=example.test&line=7', ''],
            [$bound, 'GET', $prefix . 'lookupnsip?api.version=1&host=ns2.example.test', ''],
            [$bound, 'GET', $prefix . 'lookupnsips?api.version=1&host=ns2.example.test', ''],
            [$bound, 'GET', $line . '&extra=1', ''],
            [$bound, 'GET', $line, 'x=1'],
            [$bound, 'POST', $address, ''],
            [new LiveCurlClient($respond), 'GET', $line, ''],
            [new LiveCurlClient($respond), 'GET', $address, ''],
            [new LiveCurlClient($respond), 'GET', $addresses, ''],
            [new LiveCurlClient($respond, zoneLine: 7), 'GET', $address, ''],
            [new LiveCurlClient($respond, nameserver: 'ns1.example.test'), 'GET', $line, ''],
        ];
        $rejections = 0;
        foreach ($rejected as [$client, $method, $uri, $body]) {
            try {
                $client->sendRequest(new Request($method, $uri, [], $body));
            } catch (LogicException) {
                $rejections++;
            }
        }

        self::assertSame(count($rejected), $rejections, 'Derived requests outside the bound scope must be rejected.');
        self::assertSame(3, $executed, 'Rejected requests must not reach the cURL execution boundary.');
    }

    /** @param array{zoneLine?: int, nameserver?: string} $binding */
    #[DataProvider('malformedDerivedBindings')]
    public function testRejectsMalformedDerivedBindings(array $binding): void
    {
        $executed = false;
        $execute = static function (RequestInterface $request) use (&$executed): ResponseInterface {
            $executed = true;

            return new Response(204);
        };

        try {
            $client = new LiveCurlClient($execute, ...$binding);
            // Had the binding been accepted, its derived request would reach the execution boundary.
            $prefix = 'https://test.oblak.host:2087/json-api/';
            $client->sendRequest(new Request('GET', array_key_exists('zoneLine', $binding)
                ? $prefix . 'getzonerecord?api.version=1&domain=npr.rs&line=' . $binding['zoneLine']
                : $prefix . 'lookupnsip?api.version=1&host=' . rawurlencode($binding['nameserver'] ?? '')));
            self::fail('The live transport accepted a malformed derived binding.');
        } catch (LogicException) {
            self::assertFalse($executed, 'Malformed bindings must be rejected before the cURL execution boundary.');
        }
    }

    /** @return iterable<string, array{array{zoneLine?: int, nameserver?: string}}> */
    public static function malformedDerivedBindings(): iterable
    {
        yield 'zero line' => [['zoneLine' => 0]];
        yield 'negative line' => [['zoneLine' => -1]];
        yield 'empty host' => [['nameserver' => '']];
        yield 'bare label' => [['nameserver' => 'localhost']];
        yield 'query injection' => [['nameserver' => 'ns1.example.test&host=other.example']];
        yield 'trailing dot' => [['nameserver' => 'ns1.example.test.']];
        yield 'path' => [['nameserver' => 'ns1.example.test/x']];
        yield 'encoded ampersand' => [['nameserver' => 'ns1.example.test%26host=other.example']];
        yield 'query separator' => [['nameserver' => 'ns1.example.test?host=other.example']];
        yield 'fragment' => [['nameserver' => 'ns1.example.test#x']];
        yield 'space' => [['nameserver' => 'ns1.example .test']];
        yield 'trailing newline' => [['nameserver' => "ns1.example.test\n"]];
        yield 'non-ASCII host' => [['nameserver' => 'ns1.exämple.test']];
    }

    #[DataProvider('approvedPosts')]
    public function testAllowsExactlyTheApprovedReadOnlyPosts(string $uri, string $body): void
    {
        $client = new LiveCurlClient(static fn (RequestInterface $request): ResponseInterface => new Response(204));

        self::assertSame(204, $client->sendRequest(new Request('POST', $uri, [], $body))->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function approvedPosts(): iterable
    {
        yield 'WHM version' => ['https://test.oblak.host:2087/json-api/version?api.version=1', ''];
        yield 'WHM filtered accounts' => [
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1',
            'search=npr&searchmethod=exact&searchtype=user',
        ];
        yield 'direct primary domain' => ['https://test.oblak.host:2083/execute/DomainInfo/primary_domain', ''];
        yield 'bridged primary domain' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=primary_domain',
            '',
        ];
    }

    public function testApprovesLifecycleMutationsOnlyForTheNamedThrowawayAccount(): void
    {
        $client = new LiveCurlClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(204),
            'lifecycle1',
        );
        $prefix = 'https://test.oblak.host:2087/json-api/';
        $approved = [
            ['POST', $prefix . 'createacct?api.version=1', 'username=lifecycle1&domain=lc.example'],
            ['POST', $prefix . 'suspendacct?api.version=1', 'user=lifecycle1&reason=test&disallowun=0'],
            ['POST', $prefix . 'unsuspendacct?api.version=1', 'user=lifecycle1'],
            ['POST', $prefix . 'passwd?api.version=1', 'user=lifecycle1&password=x'],
            ['POST', $prefix . 'changepackage?api.version=1', 'user=lifecycle1&pkg=default'],
            ['POST', $prefix . 'modifyacct?api.version=1', 'user=lifecycle1&contactemail=a%40b.example'],
            ['POST', $prefix . 'removeacct?api.version=1', 'username=lifecycle1&keepdns=0'],
            ['GET', $prefix . 'accountsummary?api.version=1&user=lifecycle1', ''],
            ['GET', $prefix . 'listpkgs?api.version=1&want=creatable', ''],
        ];
        foreach ($approved as [$method, $uri, $body]) {
            self::assertSame(204, $client->sendRequest(new Request($method, $uri, [], $body))->getStatusCode());
        }
    }

    #[DataProvider('missingPrerequisites')]
    public function testRequiresExplicitOptInBothTokensAndCurl(
        string|false $optIn,
        string|false $whmToken,
        string|false $cpanelToken,
        bool $curlAvailable,
    ): void {
        $this->expectException(RuntimeException::class);

        LiveTestEnvironment::requireReady($optIn, $whmToken, $cpanelToken, $curlAvailable);
    }

    /** @return iterable<string, array{string|false, string|false, string|false, bool}> */
    public static function missingPrerequisites(): iterable
    {
        yield 'no opt in' => [false, 'synthetic-whm', 'synthetic-cpanel', true];
        yield 'wrong opt in' => ['true', 'synthetic-whm', 'synthetic-cpanel', true];
        yield 'no WHM key' => ['1', false, 'synthetic-cpanel', true];
        yield 'empty WHM key' => ['1', '', 'synthetic-cpanel', true];
        yield 'no cPanel key' => ['1', 'synthetic-whm', false, true];
        yield 'empty cPanel key' => ['1', 'synthetic-whm', '', true];
        yield 'no curl' => ['1', 'synthetic-whm', 'synthetic-cpanel', false];
    }

    public function testAcceptsCompleteExplicitLivePrerequisites(): void
    {
        $tokens = LiveTestEnvironment::requireReady('1', 'synthetic-whm', 'synthetic-cpanel', true);

        self::assertSame(['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'], $tokens);
    }

    #[DataProvider('missingMutationPrerequisites')]
    public function testMutationsRequireTheirOwnOptInAndAThrowawayTarget(
        string|false $optIn,
        string|false $account,
        string|false $domain,
    ): void {
        $this->expectException(RuntimeException::class);

        LiveTestEnvironment::requireMutationsReady($optIn, $account, $domain);
    }

    /** @return iterable<string, array{string|false, string|false, string|false}> */
    public static function missingMutationPrerequisites(): iterable
    {
        yield 'no opt in' => [false, 'lifecycle1', 'lifecycle1.example'];
        yield 'read-only opt in only' => ['yes', 'lifecycle1', 'lifecycle1.example'];
        yield 'no account' => ['1', false, 'lifecycle1.example'];
        yield 'empty account' => ['1', '', 'lifecycle1.example'];
        yield 'protected test account' => ['1', 'npr', 'lifecycle1.example'];
        yield 'root' => ['1', 'root', 'lifecycle1.example'];
        yield 'invalid username' => ['1', 'Life-Cycle', 'lifecycle1.example'];
        yield 'no domain' => ['1', 'lifecycle1', false];
        yield 'invalid domain' => ['1', 'lifecycle1', 'not a domain'];
        yield 'bare label' => ['1', 'lifecycle1', 'localhost'];
    }

    public function testAcceptsCompleteMutationPrerequisites(): void
    {
        $target = LiveTestEnvironment::requireMutationsReady('1', 'lifecycle1', 'lifecycle1.example');

        self::assertSame(['account' => 'lifecycle1', 'domain' => 'lifecycle1.example'], $target);
    }
}
