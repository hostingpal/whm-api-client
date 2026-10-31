<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

/** Literal request and pinned payload fixtures independent of the generated specs. */
final class WhmWebServerConfigurationTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string, mixed}> */
    public static function operations(): iterable
    {
        yield 'conversion queues work, build ID does not establish completion' => [
            'convert_all_domains_to_fpm', 'convertAllDomainsToFpm', [], 'POST', '',
            '{"build_id":1493645268}', ['build_id' => 1493645268],
        ];
        yield 'additional prefixes' => [
            'ea4_get_additional_pkg_prefixes', 'ea4GetAdditionalPkgPrefixes', [], 'GET', '',
            '{"additional_pkg_prefixes":["altea","ea-custom"]}', ['altea', 'ea-custom'],
        ];
        yield 'installed packages' => [
            'ea4_get_currently_installed_packages', 'ea4GetCurrentlyInstalledPackages', [], 'GET', '',
            '{"packages":["ea-apache24","ea-php84"]}', ['ea-apache24', 'ea-php84'],
        ];
        yield 'profiles preserve built in fields, custom rows and dynamic vendor names' => [
            'ea4_list_profiles', 'ea4ListProfiles', [], 'GET', '',
            '{"cpanel":[{"active":0,"desc":"built in","name":"Base","pkgs":["ea-apache24"],'
                . '"tags":["Apache 2.4"],"validation_data":{"not_on_server":[]},"version":"1.3"}],'
                . '"custom":[{"desc":"custom","name":"Custom","path":"custom.json","pkgs":[],"tags":[], '
                . '"validation_data":{"not_on_server":["ea-missing"]},"version":"0"}],'
                . '"vendor-example":[{"name":"Vendor","path":"vendor.json","pkgs":["ea-php84"]}],"empty":[]}',
            ['cpanel' => [['active' => 0, 'desc' => 'built in', 'name' => 'Base', 'pkgs' => ['ea-apache24'],
                'tags' => ['Apache 2.4'], 'validation_data' => ['not_on_server' => []], 'version' => '1.3']],
                'custom' => [['desc' => 'custom', 'name' => 'Custom', 'path' => 'custom.json', 'pkgs' => [],
                    'tags' => [], 'validation_data' => ['not_on_server' => ['ea-missing']], 'version' => '0']],
                'vendor-example' => [['name' => 'Vendor', 'path' => 'vendor.json', 'pkgs' => ['ea-php84']]],
                'empty' => []],
        ];
        yield 'metadata pinned example strings and lists plus formal object values' => [
            'ea4_metainfo', 'ea4Metainfo', [], 'GET', '',
            '{"additional_packages":["ea-nginx","ea-example"],"default_php_handler":"cgi",'
                . '"default_php_package":"ea-php84","type":"object","vendor":{"future":7},"empty":{}}',
            ['additional_packages' => ['ea-nginx', 'ea-example'], 'default_php_handler' => 'cgi',
                'default_php_package' => 'ea-php84', 'type' => 'object', 'vendor' => ['future' => 7], 'empty' => []],
        ];
        yield 'recommendations retain package keys and real boolean options' => [
            'ea4_recommendations', 'ea4Recommendations', [], 'GET', '',
            '{"ea-php84-php":[{"desc":"synthetic","level":"danger","name":"PHP DSO","on":"add",'
                . '"options":[{"items":["ea-apache24"],"level":"success","recommended":true,"text":"Install",'
                . '"url":"https://example.test/info"},{"recommended":false,"text":"Avoid"}],'
                . '"url":"https://example.test"}],"empty":[]}',
            ['ea-php84-php' => [['desc' => 'synthetic', 'level' => 'danger', 'name' => 'PHP DSO', 'on' => 'add',
                'options' => [
        ['items' => ['ea-apache24'], 'level' => 'success', 'recommended' => true,
                    'text' => 'Install', 'url' => 'https://example.test/info'],
                    ['recommended' => false, 'text' => 'Avoid']], 'url' => 'https://example.test']], 'empty' => []],
        ];
        yield 'save profile forwards repeated packages and tags and explicit defaults' => [
            'ea4_save_profile', 'ea4SaveProfile', ['filename' => 'new profile.json', 'name' => 'New & Profile',
                'packages' => ['ea-apache24', 'ea-php84'], 'desc' => '', 'overwrite' => false,
                'tags' => ['Apache 2.4', 'Static & Fast'], 'version' => '0'], 'POST',
            'filename=new%20profile.json&name=New%20%26%20Profile&pkg=ea-apache24&pkg=ea-php84&desc=&overwrite=0'
                . '&tag=Apache%202.4&tag=Static%20%26%20Fast&version=0',
            '{"already_exists":0,"path":"/etc/cpanel/ea4/profiles/custom/new profile.json"}',
            ['already_exists' => 0, 'path' => '/etc/cpanel/ea4/profiles/custom/new profile.json'],
        ];
        yield 'profile omissions preserve server defaults' => [
            'ea4_save_profile', 'ea4SaveProfile', ['filename' => 'new.json',
                'name' => 'New', 'packages' => 'ea-apache24'],
            'POST', 'filename=new.json&name=New&pkg=ea-apache24', '{"already_exists":1}', ['already_exists' => 1],
        ];
        yield 'EOL domain count' => [
            'eol_php_sites', 'eolPhpSites', [], 'GET', '', '{"eol_php_sites":0}', 0,
        ];
        yield 'FPM capacity values retain units and zero flag' => [
            'get_fpm_count_and_utilization', 'getFpmCountAndUtilization', [], 'GET', '',
            '{"domains_to_be_enabled":2,"domains_using_fpm":20,"memory_needed":60,'
                . '"number_of_new_fpm_accounts_we_can_handle":8,"show_warning":0,"total_domains":22}',
            ['domains_to_be_enabled' => 2, 'domains_using_fpm' => 20, 'memory_needed' => 60,
                'number_of_new_fpm_accounts_we_can_handle' => 8, 'show_warning' => 0, 'total_domains' => 22],
        ];
        yield 'impacted domains repeated selectors with explicit zero' => [
            'php_get_impacted_domains', 'phpGetImpactedDomains', ['domains' => ['example.test', 'other.test'],
                'systemDefault' => false], 'GET', 'domain=example.test&domain=other.test&system_default=0',
            '{"domains":["example.test","sub.example.test"]}', ['example.test', 'sub.example.test'],
        ];
        yield 'impacted domain selector alone' => [
            'php_get_impacted_domains', 'phpGetImpactedDomains', ['domains' => 'one.test'], 'GET',
            'domain=one.test', '{"domains":[]}', [],
        ];
        yield 'impacted system default selector alone' => [
            'php_get_impacted_domains', 'phpGetImpactedDomains', ['systemDefault' => true], 'GET',
            'system_default=1', '{"domains":["one.test"]}', ['one.test'],
        ];
        yield 'vhost inventory retains all pinned fields' => [
            'php_get_vhost_versions', 'phpGetVhostVersions', [], 'GET', '',
            '{"versions":[{"account":"example","account_owner":"root","documentroot":"/home/example/public_html",'
                . '"homedir":"/home/example","is_suspended":0,"main_domain":1,"php_fpm":1,'
                . '"php_fpm_pool_parms":{"pm_max_children":5,"pm_max_requests":20,"pm_process_idle_timeout":10},'
                . '"phpversion_source":[{"domain":"example.test"},{"system_default":1}],'
                . '"version":"custom-php84","vhost":"example.test"},{}]}',
            [['account' => 'example', 'account_owner' => 'root', 'documentroot' => '/home/example/public_html',
                'homedir' => '/home/example', 'is_suspended' => 0, 'main_domain' => 1, 'php_fpm' => 1,
                'php_fpm_pool_parms' => ['pm_max_children' => 5, 'pm_max_requests' => 20,
                    'pm_process_idle_timeout' => 10],
                'phpversion_source' => [['domain' => 'example.test'], ['system_default' => 1]],
                'version' => 'custom-php84', 'vhost' => 'example.test'], []],
        ];
        yield 'INI content retains exact bytes as payload data' => [
            'php_ini_get_content', 'phpIniGetContent', ['version' => 'custom+php'], 'GET', 'version=custom%2Bphp',
            '{"content":"[PHP]\nsecret=fixture&value%20\n"}', "[PHP]\nsecret=fixture&value%20\n",
        ];
        yield 'INI directives preserve all optional string fields' => [
            'php_ini_get_directives', 'phpIniGetDirectives', ['version' => 'ea-php84'], 'GET', 'version=ea-php84',
            '{"directives":[{"default_value":"1","info":"Synthetic","key":"allow_url_fopen",'
                . '"php_ini_mode":"PHP_INI_SYSTEM","type":"boolean","value":"On"},{}]}',
            [['default_value' => '1', 'info' => 'Synthetic', 'key' => 'allow_url_fopen',
                'php_ini_mode' => 'PHP_INI_SYSTEM', 'type' => 'boolean', 'value' => 'On'], []],
        ];
        yield 'INI content is form encoded exactly once, never in URL' => [
            'php_ini_set_content', 'phpIniSetContent', ['content' => "[PHP]\nsecret=fixture&x=%20+\n",
                'version' => 'ea-php84'],
            'POST', 'content=%5BPHP%5D%0Asecret%3Dfixture%26x%3D%2520%2B%0A&version=ea-php84',
            '{"future":{"opaque":true}}', ['future' => ['opaque' => true]],
        ];
        yield 'INI directives repeat literal directive value without interpreting delimiters' => [
            'php_ini_set_directives', 'phpIniSetDirectives', [
                'directives' => ['allow_url_fopen:0', 'synthetic:a&b=%20'],
                'version' => 'ea-php84'], 'POST',
            'directive=allow_url_fopen%3A0&directive=synthetic%3Aa%26b%3D%2520&version=ea-php84', '{}', [],
        ];
        yield 'new account FPM default remains zero and unconstrained result remains mixed' => [
            'php_set_default_accounts_to_fpm', 'phpSetDefaultAccountsToFpm', ['defaultAccountsToFpm' => false],
            'POST', 'default_accounts_to_fpm=0', '{"opaque":[0,false]}', ['opaque' => [0, false]],
        ];
        yield 'handler change no documented data' => [
            'php_set_handler', 'phpSetHandler', ['handler' => 'none', 'version' => 'custom-php84'],
            'POST', 'handler=none&version=custom-php84', null, null,
        ];
        yield 'old FPM flag records payload failure separately from API success' => [
            'php_set_old_fpm_flag', 'phpSetOldFpmFlag', [], 'POST', '', '{"success":0}', ['success' => 0],
        ];
        yield 'session path defaults omitted' => [
            'php_set_session_save_path', 'phpSetSessionSavePath', [], 'POST', '', null, null,
        ];
        yield 'session path explicit false retained' => [
            'php_set_session_save_path', 'phpSetSessionSavePath', ['overwrite' => false,
                'path' => '/var/private/php sessions'],
            'POST', 'overwrite=0&path=%2Fvar%2Fprivate%2Fphp%20sessions', null, null,
        ];
        yield 'system default accepts custom PHP package' => [
            'php_set_system_default_version', 'phpSetSystemDefaultVersion', ['version' => 'custom-php84'],
            'POST', 'version=custom-php84', null, null,
        ];
        yield 'vhosts numbered and caller JSON string form encoded once' => [
            'php_set_vhost_versions', 'phpSetVhostVersions', ['version' => 'custom-php84',
                'vhosts' => ['one.test', 'two.test'], 'phpFpm' => false,
                'phpFpmPoolParms' => '{"pm_max_children":5,"pm_max_requests":20,"pm_process_idle_timeout":10}'],
            'POST', 'version=custom-php84&vhost=one.test&vhost-1=two.test&php_fpm=0'
                . '&php_fpm_pool_parms=%7B%22pm_max_children%22%3A5%2C%22pm_max_requests%22%3A20'
                . '%2C%22pm_process_idle_timeout%22%3A10%7D', null, null,
        ];
        yield 'vhost optional flags and pool JSON omitted' => [
            'php_set_vhost_versions', 'phpSetVhostVersions', ['version' => 'inherit', 'vhosts' => 'one.test'],
            'POST', 'version=inherit&vhost=one.test', null, null,
        ];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testLiteralRoutesBodiesAndPopulatedResponses(
        string $operation,
        string $method,
        array $arguments,
        string $verb,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        self::assertSame($expected, $whm->webServer()->{$method}(...$arguments));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
                . ($verb === 'GET' && $parameters !== '' ? '&' . $parameters : ''),
            (string) $request->getUri(),
        );
        self::assertSame($verb === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame($operation, ($whm->responseMeta()->whm['command'] ?? null));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function apiOperations(): iterable
    {
        foreach (self::operations() as $name => [$operation, $method, $arguments]) {
            yield $name => [$operation, $method, $arguments];
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('apiOperations')]
    public function testApiFailureIsRecordedAndNeverRetried(
        string $operation,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"' . $operation
            . '","result":0,"reason":"synthetic failure","version":1},"data":{}}'));
        try {
            $client->whm()->webServer()->{$method}(...$arguments);
            self::fail('API failure must throw before payload processing.');
        } catch (ApiErrorException $exception) {
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            self::assertSame(0, $exception->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string}> */
    public static function malformedPayloads(): iterable
    {
        yield 'conversion ID quoted' => [
            'convert_all_domains_to_fpm', 'convertAllDomainsToFpm', [], '{"build_id":"1"}',
        ];
        yield 'prefix item integer' => ['ea4_get_additional_pkg_prefixes', 'ea4GetAdditionalPkgPrefixes', [],
            '{"additional_pkg_prefixes":[1]}'];
        yield 'package list object' => ['ea4_get_currently_installed_packages', 'ea4GetCurrentlyInstalledPackages', [],
            '{"packages":{}}'];
        yield 'profile cpanel active boolean' => ['ea4_list_profiles', 'ea4ListProfiles', [],
            '{"cpanel":[{"active":false}]}'];
        yield 'profile vendor packages integer' => ['ea4_list_profiles', 'ea4ListProfiles', [],
            '{"vendor":[{"pkgs":[1]}]}'];
        yield 'profile custom path integer' => ['ea4_list_profiles', 'ea4ListProfiles', [],
            '{"custom":[{"path":1}]}'];
        yield 'profile dynamic vendor scalar' => ['ea4_list_profiles', 'ea4ListProfiles', [], '{"vendor":7}'];
        yield 'metainfo unpinned numeric child' => ['ea4_metainfo', 'ea4Metainfo', [], '{"default_php_handler":7}'];
        yield 'metainfo null child' => ['ea4_metainfo', 'ea4Metainfo', [], '{"vendor":null}'];
        yield 'recommendation quoted boolean' => ['ea4_recommendations', 'ea4Recommendations', [],
            '{"pkg":[{"options":[{"recommended":"true"}]}]}'];
        yield 'recommendation scalar option' => ['ea4_recommendations', 'ea4Recommendations', [],
            '{"pkg":[{"options":[1]}]}'];
        yield 'profile save quoted flag' => ['ea4_save_profile', 'ea4SaveProfile',
            ['filename' => 'a.json', 'name' => 'A', 'packages' => 'ea-apache24'], '{"already_exists":"0"}'];
        yield 'EOL count float' => ['eol_php_sites', 'eolPhpSites', [], '{"eol_php_sites":0.0}'];
        yield 'FPM memory string' => ['get_fpm_count_and_utilization', 'getFpmCountAndUtilization', [],
            '{"memory_needed":"60"}'];
        yield 'FPM warning boolean' => ['get_fpm_count_and_utilization', 'getFpmCountAndUtilization', [],
            '{"show_warning":false}'];
        yield 'impacted domain null' => ['php_get_impacted_domains', 'phpGetImpactedDomains',
            ['domains' => 'one.test', 'systemDefault' => true], '{"domains":[null]}'];
        yield 'vhost quoted suspension flag' => ['php_get_vhost_versions', 'phpGetVhostVersions', [],
            '{"versions":[{"is_suspended":"0"}]}'];
        yield 'vhost pool numeric string' => ['php_get_vhost_versions', 'phpGetVhostVersions', [],
            '{"versions":[{"php_fpm_pool_parms":{"pm_max_requests":"20"}}]}'];
        yield 'vhost source object is not source list' => ['php_get_vhost_versions', 'phpGetVhostVersions', [],
            '{"versions":[{"phpversion_source":{"system_default":1}}]}'];
        yield 'INI content null' => ['php_ini_get_content', 'phpIniGetContent', ['version' => 'ea-php84'],
            '{"content":null}'];
        yield 'directive current value integer' => ['php_ini_get_directives', 'phpIniGetDirectives',
            ['version' => 'ea-php84'], '{"directives":[{"value":1}]}'];
        yield 'content write scalar result' => ['php_ini_set_content', 'phpIniSetContent',
            ['content' => 'synthetic', 'version' => 'ea-php84'], 'true'];
        yield 'directive write null result' => ['php_ini_set_directives', 'phpIniSetDirectives',
            ['directives' => 'allow_url_fopen:0', 'version' => 'ea-php84'], 'null'];
        yield 'old FPM success quoted' => ['php_set_old_fpm_flag', 'phpSetOldFpmFlag', [], '{"success":"0"}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPinnedFieldsFailAfterRecordingOutcome(
        string $operation,
        string $method,
        array $arguments,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $client->whm()->webServer()->{$method}(...$arguments);
            self::fail('Malformed field must fail.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, ?string}> */
    public static function malformedRoots(): iterable
    {
        foreach (self::operations() as $name => [$operation, $method, $arguments, , , $payload]) {
            if ($payload === null || $operation === 'php_set_default_accounts_to_fpm') {
                continue;
            }
            yield $name . ' scalar' => [$operation, $method, $arguments, '7'];
            yield $name . ' populated list' => [$operation, $method, $arguments, '[1]'];
            yield $name . ' explicit null' => [$operation, $method, $arguments, 'null'];
            yield $name . ' absent data' => [$operation, $method, $arguments, null];
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedRoots')]
    public function testAbsentNullAndWrongRootsStayDistinct(
        string $operation,
        string $method,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $this->expectException(PayloadMismatchException::class);
        $client->whm()->webServer()->{$method}(...$arguments);
    }

    public function testSensitiveIniAndPoolInputsAndExcludedJsonBodies(): void
    {
        [$client, $http] = self::client();
        $group = $client->whm()->webServer();
        self::assertSame($group, $client->whm()->webServer());
        self::assertSame([], $http->getRequests());
        self::assertNotContains('phpFpmConfigGet', get_class_methods($group));
        self::assertNotContains('phpFpmConfigSet', get_class_methods($group));
        foreach (
            ['phpIniSetContent' => 'content', 'phpIniSetDirectives' => 'directives',
            'phpSetVhostVersions' => 'phpFpmPoolParms'] as $method => $name
        ) {
            $parameters = (new ReflectionMethod($group, $method))->getParameters();
            $matches = array_filter($parameters, static fn ($parameter): bool => $parameter->getName() === $name);
            self::assertCount(1, $matches);
            foreach ($matches as $parameter) {
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
            }
        }
    }

    private static function envelope(string $operation, ?string $payload): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"OK","result":1,"version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'username' => 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
