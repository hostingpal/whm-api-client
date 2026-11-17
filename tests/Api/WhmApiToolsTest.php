<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;
use TypeError;

/** Literal pinned and reviewed-source fixtures, independent of generated specs and overlays. */
final class WhmApiToolsTest extends TestCase
{
    protected function setUp(): void
    {
        [$client] = self::client();
        self::assertContains('apiTools', get_class_methods($client->whm()));
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testLiteralRequestsAndPopulatedPayloads(
        string $function,
        string $method,
        array $arguments,
        string $verb,
        string $wire,
        ?string $payload,
        string $unwrap = '',
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        $expected = $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if ($unwrap !== '') {
            self::assertIsArray($expected);
            $expected = $expected[$unwrap];
        }
        self::assertSame(
            self::canonical($expected),
            self::canonical($client->whm()->apiTools()->{$method}(...$arguments)),
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $function . '?api.version=1'
            . ($verb === 'GET' && $wire !== '' ? '&' . $wire : ''), (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame($function, $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string, 6?: string}> */
    public static function operations(): iterable
    {
        yield from self::indexedTokenRequests();
        yield 'create all inputs' => ['api_token_create', 'createToken',
            ['tokenName' => 'new-token', 'acl' => 'list-accts', 'expiresAt' => 0, 'whitelistIp' => '2001:db8::/48'],
            'POST', 'token_name=new-token&acl=list-accts&expires_at=0&whitelist_ip=2001%3Adb8%3A%3A%2F48',
            self::created()];
        yield 'details' => ['api_token_get_details', 'getTokenDetails', ['secret +&=%/雪'], 'POST',
            'token=secret%20%2B%26%3D%25%2F%E9%9B%AA',
            self::details()];
        yield 'token inventory dynamic names and ACLs' => ['api_token_list', 'listTokens', [], 'GET', '',
            '{"tokens":{"first-token":{"acls":{"list-accts":1,"create-acct":0},"create_time":123,'
            . '"expires_at":null,"name":"first-token","whitelist_ips":["192.0.2.1"]},'
            . '"second-token":{"acls":{},"expires_at":0,"whitelist_ips":null}}}', 'tokens'];
        yield 'revoke' => ['api_token_revoke', 'revokeToken', ['old-token'], 'POST', 'token_name=old-token', null];
        yield 'update replacement and clearing' => ['api_token_update', 'updateToken',
            ['tokenName' => 'old-token',
                'acl' => 'list-accts',
                'expiresAt' => 0,
                'newName' => 'new-token',
                'whitelistIp' => 'any'],
            'POST', 'token_name=old-token&acl=list-accts&whitelist_ip=any&expires_at=0&new_name=new-token',
            self::details()];
        yield 'update positional full intent' => ['api_token_update', 'updateToken',
            ['old-token', 'list-accts', '192.0.2.1', 123, 'new-token'],
            'POST', 'token_name=old-token&acl=list-accts&whitelist_ip=192.0.2.1&expires_at=123&new_name=new-token',
            self::details()];
        yield 'dashboard token all inputs' => ['wp_dashboard_create_api_token', 'createDashboardToken',
            ['acl' => 'all', 'tokenName' => 'dashboard', 'expiresAt' => 0, 'whitelistIp' => '192.0.2.0/24'],
            'POST', 'token_name=dashboard&acl=all&expires_at=0&whitelist_ip=192.0.2.0%2F24',
            self::created()];
        yield 'session all inputs' => ['create_user_session', 'createUserSession',
            ['service' => 'cpaneld', 'user' => 'user@example.test', 'app' => 'Email_Accounts',
                'cpSecurityToken' => 'secret +&=%/雪',
                    'locale' => 'fr',
                    'preferredDomain' => 'example.test',
                    'promptToken' => 'aGVsbG8='],
            'POST', 'service=cpaneld&user=user%40example.test&app=Email_Accounts'
            . '&cp_security_token=secret%20%2B%26%3D%25%2F%E9%9B%AA&locale=fr'
            . '&preferred_domain=example.test&promptToken=aGVsbG8%3D',
            self::session()];
        yield 'dashboard link all inputs' => ['wp_dashboard_create_login_link', 'createDashboardLoginLink',
            ['service' => 'webmaild',
                'user' => 'user@example.test'], 'POST', 'service=webmaild&user=user%40example.test', self::session()];
        yield 'available functions' => [
            'applist', 'listApplications', [], 'GET', '', '{"app":["version","applist"]}', 'app'];
        yield 'usage calls' => ['get_api_calls', 'getApiCalls', ['cpapi1'], 'GET', 'type=cpapi1',
            '{"result":[{"count":2,"entry":"Email::printdomainoptions","timestamp":123}]}', 'result'];
        yield 'usage pages pinned object' => ['get_api_pages', 'getApiPages', ['cpapi1'], 'GET', 'type=cpapi1',
            '{"count":2,"entry":"/plugin/index.html.tt","timestamp":123}'];
        yield 'usage pages populated reviewed wrapper' => [
            'get_api_pages', 'getApiPages', [], 'GET', '',
            '{"result":[{"count":2,"entry":"/plugin/index.html.tt","timestamp":123},{},'
            . '{"entry":"/other"}]}'];
        yield 'usage pages simultaneous flat and wrapped fields' => [
            'get_api_pages', 'getApiPages', [], 'GET', '',
            '{"count":3,"entry":"/flat","timestamp":456,"result":[{"count":2,"entry":"/wrapped","timestamp":123}]}'];
        yield 'AppConfig reviewed WHM URL alternatives and mixed rows' => [
            'get_appconfig_application_list', 'getAppconfigApplicationList', [], 'GET', '',
            '{"cpanel":[{"url":"/cpanel"}],"webmail":[{"url":"/webmail"}],'
            . '"whostmgr":[{"name":"scalar","url":"/scalar"},{"name":"list","url":["/one","/two"]},'
            . '{"url":[]},{}]}'];
        yield 'AppConfig full records' => [
            'get_appconfig_application_list', 'getAppconfigApplicationList', [], 'GET', '',
            '{"cpanel":[' . self::application() . '],"webmail":[],"whostmgr":[' . self::application() . ']}'];
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string, 6?: string}> */
    public static function omittedInputs(): iterable
    {
        yield 'create defaults' => [
            'api_token_create', 'createToken', ['new-token'], 'POST', 'token_name=new-token', '{}'];
        yield 'update explicit settings preserve omitted name' => [
            'api_token_update', 'updateToken', ['old-token', 'list-accts', '192.0.2.1', 123],
            'POST', 'token_name=old-token&acl=list-accts&whitelist_ip=192.0.2.1&expires_at=123', '{}'];
        yield 'dashboard required ACL' => [
            'wp_dashboard_create_api_token', 'createDashboardToken', ['dashboard', 'all'],
            'POST', 'token_name=dashboard&acl=all', '{}'];
        yield 'session defaults' => ['create_user_session', 'createUserSession', ['cpaneld', 'user'],
            'POST', 'service=cpaneld&user=user', '{}'];
        yield 'link defaults' => ['wp_dashboard_create_login_link', 'createDashboardLoginLink', [], 'POST', '', '{}'];
        yield 'calls default' => ['get_api_calls', 'getApiCalls', [], 'GET', '', '{"result":[]}', 'result'];
        yield 'pages empty reviewed wrapper' => ['get_api_pages', 'getApiPages', [], 'GET', '', '{"result":[]}'];
        yield 'pages default' => ['get_api_pages', 'getApiPages', [], 'GET', '', '{}'];
        yield 'empty token maps' => ['api_token_list', 'listTokens', [], 'GET', '', '{"tokens":{}}', 'tokens'];
        yield 'empty array token maps compatibility' => [
            'api_token_list', 'listTokens', [], 'GET', '', '{"tokens":[]}', 'tokens'];
        yield 'lookup unrecognized' => [
            'api_token_get_details', 'getTokenDetails', ['unknown'], 'POST', 'token=unknown', null];
        yield 'lookup explicit null compatibility' => [
            'api_token_get_details', 'getTokenDetails', ['unknown'], 'POST', 'token=unknown', 'null'];
        yield 'lookup empty object' => [
            'api_token_get_details', 'getTokenDetails', ['known'], 'POST', 'token=known', '{}'];
        yield 'lookup empty array compatibility' => [
            'api_token_get_details', 'getTokenDetails', ['known'], 'POST', 'token=known', '[]'];
        yield 'created nullable values' => [
            'api_token_create', 'createToken', ['new-token'], 'POST', 'token_name=new-token',
            '{"expires_at":null,"whitelist_ips":null,"acls":[]}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('omittedInputs')]
    public function testOmissionAndNullableCompatibility(
        string $function,
        string $method,
        array $arguments,
        string $verb,
        string $wire,
        ?string $payload,
        string $unwrap = '',
    ): void {
        $this->testLiteralRequestsAndPopulatedPayloads($function, $method, $arguments, $verb, $wire, $payload, $unwrap);
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('failedOperations')]
    public function testApiFailurePrecedesPayloadValidation(
        string $function,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, 'false', 0)));
        try {
            $client->whm()->apiTools()->{$method}(...$arguments);
            self::fail('Failed metadata must raise ApiErrorException.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(['Synthetic'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string}> */
    public static function indexedTokenRequests(): iterable
    {
        foreach (
            self::collectionPositions() as $label => [$function, $method, $parameter, $base, $scalar, $single, $many]
        ) {
            foreach (
                [
                'scalar' => [' +&%雪', $scalar],
                'singleton' => [[''], $single],
                'multiple' => [[' +&%雪', '', ' +&%雪'], $many],
                ] as $variant => [$input, $wire]
            ) {
                yield "$label $variant" => [$function, $method, array_replace($base, [$parameter => $input]),
                    'POST', $wire, $method === 'revokeToken' ? null : '{}'];
            }
        }
        yield 'create scalar positional' => ['api_token_create', 'createToken',
            ['fixture', 'list-accts', 0, '192.0.2.1'], 'POST',
            'token_name=fixture&acl=list-accts&expires_at=0&whitelist_ip=192.0.2.1', '{}'];
        yield 'dashboard scalar positional' => ['wp_dashboard_create_api_token', 'createDashboardToken',
            ['fixture', 'list-accts', 0, '192.0.2.1'], 'POST',
            'token_name=fixture&acl=list-accts&expires_at=0&whitelist_ip=192.0.2.1', '{}'];
        yield 'create both lists positional' => ['api_token_create', 'createToken',
            ['fixture', ['list-accts', 'create-acct'], 0, ['192.0.2.1', '2001:db8::/48']], 'POST',
            'token_name=fixture&acl-0=list-accts&acl-1=create-acct&expires_at=0'
                . '&whitelist_ip-0=192.0.2.1&whitelist_ip-1=2001%3Adb8%3A%3A%2F48', '{}'];
        yield 'update both lists positional' => ['api_token_update', 'updateToken',
            ['fixture', ['list-accts'], ['192.0.2.1'], 0, 'renamed'], 'POST',
            'token_name=fixture&acl-0=list-accts&whitelist_ip-0=192.0.2.1&expires_at=0&new_name=renamed', '{}'];
        yield 'dashboard both lists positional' => ['wp_dashboard_create_api_token', 'createDashboardToken',
            ['fixture', ['list-accts'], 0, ['192.0.2.1']], 'POST',
            'token_name=fixture&acl-0=list-accts&expires_at=0&whitelist_ip-0=192.0.2.1', '{}'];
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>, string, string, string}> */
    public static function collectionPositions(): iterable
    {
        yield 'create ACL' => ['api_token_create', 'createToken', 'acl', ['tokenName' => 'fixture'],
            'token_name=fixture&acl=%20%2B%26%25%E9%9B%AA', 'token_name=fixture&acl-0=',
            'token_name=fixture&acl-0=%20%2B%26%25%E9%9B%AA&acl-1=&acl-2=%20%2B%26%25%E9%9B%AA'];
        yield 'create IP' => ['api_token_create', 'createToken', 'whitelistIp', ['tokenName' => 'fixture'],
            'token_name=fixture&whitelist_ip=%20%2B%26%25%E9%9B%AA', 'token_name=fixture&whitelist_ip-0=',
            'token_name=fixture&whitelist_ip-0=%20%2B%26%25%E9%9B%AA&whitelist_ip-1='
                . '&whitelist_ip-2=%20%2B%26%25%E9%9B%AA'];
        yield 'dashboard ACL' => ['wp_dashboard_create_api_token', 'createDashboardToken', 'acl',
            ['tokenName' => 'fixture'], 'token_name=fixture&acl=%20%2B%26%25%E9%9B%AA', 'token_name=fixture&acl-0=',
            'token_name=fixture&acl-0=%20%2B%26%25%E9%9B%AA&acl-1=&acl-2=%20%2B%26%25%E9%9B%AA'];
        yield 'dashboard IP' => ['wp_dashboard_create_api_token', 'createDashboardToken', 'whitelistIp',
            ['tokenName' => 'fixture', 'acl' => 'list-accts'],
            'token_name=fixture&acl=list-accts&whitelist_ip=%20%2B%26%25%E9%9B%AA',
            'token_name=fixture&acl=list-accts&whitelist_ip-0=',
            'token_name=fixture&acl=list-accts&whitelist_ip-0=%20%2B%26%25%E9%9B%AA&whitelist_ip-1='
                . '&whitelist_ip-2=%20%2B%26%25%E9%9B%AA'];
        yield 'update ACL' => ['api_token_update', 'updateToken', 'acl', ['tokenName' => 'fixture'],
            'token_name=fixture&acl=%20%2B%26%25%E9%9B%AA', 'token_name=fixture&acl-0=',
            'token_name=fixture&acl-0=%20%2B%26%25%E9%9B%AA&acl-1=&acl-2=%20%2B%26%25%E9%9B%AA'];
        yield 'update IP' => ['api_token_update', 'updateToken', 'whitelistIp', ['tokenName' => 'fixture'],
            'token_name=fixture&whitelist_ip=%20%2B%26%25%E9%9B%AA', 'token_name=fixture&whitelist_ip-0=',
            'token_name=fixture&whitelist_ip-0=%20%2B%26%25%E9%9B%AA&whitelist_ip-1='
                . '&whitelist_ip-2=%20%2B%26%25%E9%9B%AA'];
        yield 'revoke names' => ['api_token_revoke', 'revokeToken', 'tokenName', [],
            'token_name=%20%2B%26%25%E9%9B%AA', 'token_name-1=',
            'token_name-1=%20%2B%26%25%E9%9B%AA&token_name-2=&token_name-3=%20%2B%26%25%E9%9B%AA'];
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function failedOperations(): iterable
    {
        foreach (self::operations() as $label => [$function, $method, $arguments]) {
            yield $label => [$function, $method, $arguments];
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadsRetainConfirmedOutcome(
        string $function,
        string $method,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        try {
            $client->whm()->apiTools()->{$method}(...$arguments);
            self::fail('Malformed payload must fail validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach (self::operations() as $label => [$function, $method, $arguments, , , $payload]) {
            if ($method === 'revokeToken') {
                continue;
            }
            foreach (['false', '1', '"bad"', '[{}]'] as $bad) {
                yield "$label root $bad" => [$function, $method, $arguments, $bad];
            }
            if ($method !== 'getTokenDetails') {
                yield "$label missing data" => [$function, $method, $arguments, null];
                yield "$label null data" => [$function, $method, $arguments, 'null'];
            }
        }
        foreach (
            ['api_token_create' => 'createToken', 'api_token_get_details' => 'getTokenDetails',
            'api_token_update' => 'updateToken',
                'wp_dashboard_create_api_token' => 'createDashboardToken'] as $function => $method
        ) {
            $arguments = match ($method) {
                'createDashboardToken' => ['dashboard', 'all'],
                'updateToken' => ['fixture', 'list-accts', '192.0.2.1', 123],
                default => ['fixture'],
            };
            foreach (
                ['acls' => '[1]', 'create_time' => '"123"', 'expires_at' => 'false',
                'name' => 'null', 'whitelist_ips' => '[1]'] as $field => $bad
            ) {
                yield "$method leaf $field" => [$function, $method, $arguments, '{"' . $field . '":' . $bad . '}'];
            }
            if ($method === 'createToken' || $method === 'createDashboardToken') {
                yield "$method secret token type" => [$function, $method, $arguments, '{"token":42}'];
            }
        }
        foreach (
            ['create_user_session' => 'createUserSession',
            'wp_dashboard_create_login_link' => 'createDashboardLoginLink'] as $function => $method
        ) {
            $arguments = $method === 'createUserSession' ? ['cpaneld', 'user'] : [];
            foreach (
                ['cp_security_token' => '1',
                'expires' => '"123"',
                'service' => 'null',
                'session' => 'false',
                'url' => '[]'] as $field => $bad
            ) {
                yield "$method leaf $field" => [$function, $method, $arguments, '{"' . $field . '":' . $bad . '}'];
            }
        }
        foreach (
            ['{"tokens":[{}]}', '{"tokens":{"dynamic":false}}', '{"tokens":{"dynamic":{"acls":{"new-acl":2}}}}',
            '{"tokens":{"dynamic":{"acls":{"new-acl":"1"}}}}', '{"tokens":{"dynamic":{"acls":[1]}}}',
            '{"tokens":{"dynamic":{"create_time":"1"}}}', '{"tokens":{"dynamic":{"expires_at":false}}}',
            '{"tokens":{"dynamic":{"name":1}}}', '{"tokens":{"dynamic":{"whitelist_ips":[false]}}}', '{}'] as $i => $bad
        ) {
            yield "inventory $i" => ['api_token_list', 'listTokens', [], $bad];
        }
        yield 'applist item' => ['applist', 'listApplications', [], '{"app":[1]}'];
        yield 'applist missing unwrapped field' => ['applist', 'listApplications', [], '{}'];
        foreach (['count' => '"1"', 'entry' => 'null', 'timestamp' => '1.5'] as $field => $bad) {
            yield "calls $field" => [
                'get_api_calls', 'getApiCalls', [], '{"result":[{"' . $field . '":' . $bad . '}]}'];
            yield "pages result row $field" => [
                'get_api_pages', 'getApiPages', [], '{"result":[{"' . $field . '":' . $bad . '}]}'];
            yield "pages $field" => ['get_api_pages', 'getApiPages', [], '{"' . $field . '":' . $bad . '}'];
        }
        foreach (['{}', 'null', 'false', '1', '"bad"', '[null]', '[false]', '[1]', '["bad"]'] as $i => $bad) {
            yield "pages invalid wrapped result $i" => ['get_api_pages', 'getApiPages', [], '{"result":' . $bad . '}'];
        }
        foreach (
            ['null', 'false', '{}', '[1]', '[null]', '[false]', '[["/nested"]]', '{"0":"/object"}'] as $i => $bad
        ) {
            yield "AppConfig WHM invalid URL $i" => [
                'get_appconfig_application_list', 'getAppconfigApplicationList', [],
                '{"whostmgr":[{"url":' . $bad . '}]}'];
        }
        foreach (['cpanel', 'webmail'] as $service) {
            yield "AppConfig $service URL remains scalar" => [
                'get_appconfig_application_list', 'getAppconfigApplicationList', [],
                '{"' . $service . '":[{"url":["/list"]}]}'];
        }
        yield 'AppConfig WHM URL alternative keeps siblings strict' => [
            'get_appconfig_application_list', 'getAppconfigApplicationList', [],
            '{"whostmgr":[{"url":["/list"],"name":false}]}'];
        yield 'AppConfig mixed URL list rejects later nonstring' => [
            'get_appconfig_application_list', 'getAppconfigApplicationList', [],
            '{"whostmgr":[{"url":"/scalar"},{"url":["/valid",1]}]}'];
        yield 'calls object result' => ['get_api_calls', 'getApiCalls', [], '{"result":{}}'];
        yield 'calls missing result' => ['get_api_calls', 'getApiCalls', [], '{}'];
        foreach (
            ['acls' => '[0]', 'displayname' => 'null', 'entryurl' => '1', 'features' => '[false]',
            'group' => 'false', 'icon' => '1', 'name' => '1', 'origin' => '1', 'phpConfig' => '1',
            'target' => '1', 'upgradecall' => '1', 'url' => '1', 'user' => '1'] as $field => $bad
        ) {
            foreach (['cpanel', 'webmail', 'whostmgr'] as $service) {
                yield "AppConfig $service $field" => [
                    'get_appconfig_application_list', 'getAppconfigApplicationList', [],
                    '{"' . $service . '":[{"' . $field . '":' . $bad . '}]}'];
            }
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputTypesDoNotDispatch(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        $this->expectException(TypeError::class);
        try {
            $client->whm()->apiTools()->{$method}(...$arguments);
        } finally {
            self::assertSame([], $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidInputs(): iterable
    {
        foreach ([false, 1.5, ['list-accts']] as $i => $bad) {
            yield "create token name $i" => ['createToken', ['tokenName' => $bad]];
            yield "details secret $i" => ['getTokenDetails', ['token' => $bad]];
            if (!is_array($bad)) {
                yield "revoke token name $i" => ['revokeToken', ['tokenName' => $bad]];
            }
            foreach (['acl', 'expiresAt', 'newName', 'whitelistIp'] as $name) {
                if (is_array($bad) && in_array($name, ['acl', 'whitelistIp'], true)) {
                    continue;
                }
                yield "update $name $i" => ['updateToken',
                    array_replace(['tokenName' => 'fixture', 'acl' => 'list-accts',
                        'whitelistIp' => '192.0.2.1', 'expiresAt' => 123], [$name => $bad])];
            }
            if (!is_array($bad)) {
                yield "dashboard ACL $i" => ['createDashboardToken', ['acl' => $bad, 'tokenName' => 'fixture']];
            }
            foreach (
                ['service', 'user', 'app', 'cpSecurityToken', 'locale',
                'preferredDomain', 'promptToken'] as $name
            ) {
                yield "session $name $i" => ['createUserSession',
                    array_replace(['service' => 'cpaneld', 'user' => 'fixture'], [$name => $bad])];
            }
            yield "dashboard service $i" => ['createDashboardLoginLink', ['service' => $bad]];
            yield "dashboard user $i" => ['createDashboardLoginLink', ['user' => $bad]];
            yield "calls type $i" => ['getApiCalls', ['type' => $bad]];
            yield "pages type $i" => ['getApiPages', ['type' => $bad]];
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('partialUpdateInputs')]
    public function testUpdateSendsOnlySuppliedOptions(array $arguments, string $wire): void
    {
        $this->testLiteralRequestsAndPopulatedPayloads(
            'api_token_update',
            'updateToken',
            $arguments,
            'POST',
            $wire,
            '{}',
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function partialUpdateInputs(): iterable
    {
        yield 'name only' => [['tokenName' => 'fixture'], 'token_name=fixture'];
        yield 'rename only' => [
            ['tokenName' => 'fixture', 'newName' => 'renamed'], 'token_name=fixture&new_name=renamed'];
        yield 'explicit null settings' => [['tokenName' => 'fixture', 'acl' => null, 'whitelistIp' => null,
            'expiresAt' => null, 'newName' => null], 'token_name=fixture'];
        yield 'partial ACL' => [['tokenName' => 'fixture', 'acl' => 'list-accts'], 'token_name=fixture&acl=list-accts'];
        yield 'explicit clearing' => [
        ['tokenName' => 'fixture', 'whitelistIp' => 'any', 'expiresAt' => 0],
            'token_name=fixture&whitelist_ip=any&expires_at=0'];
        yield 'empty strings are supplied' => [
            ['tokenName' => 'fixture', 'acl' => '', 'whitelistIp' => '', 'newName' => ''],
            'token_name=fixture&acl=&whitelist_ip=&new_name='];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidTokenCollections')]
    public function testMalformedCollectionsResetMetadataWithoutDispatch(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('applist', '{"app":[]}')));
        $client->whm()->apiTools()->listApplications();
        self::assertNotNull($client->whm()->responseMeta());
        try {
            $client->whm()->apiTools()->{$method}(...$arguments);
            self::fail('Invalid collections must fail before dispatch.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidTokenCollections(): iterable
    {
        foreach (self::collectionPositions() as $label => [, $method, $parameter, $base]) {
            foreach (
                [
                [], ['named' => 'value'], [1 => 'value'], ['ok', null], ['ok', false], ['ok', 1],
                ['ok', 1.5], ['ok', ['nested-input-secret']]] as $i => $bad
            ) {
                yield "$label malformed $i" => [$method, array_replace($base, [$parameter => $bad])];
            }
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function nestedTokenCollections(): iterable
    {
        foreach (self::collectionPositions() as $label => [, $method, $parameter, $base]) {
            yield $label => [$method, array_replace($base, [$parameter => ['valid', ['nested-input-secret']]])];
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('nestedTokenCollections')]
    public function testInvalidCollectionValuesAreHiddenInLibraryTraces(string $method, array $arguments): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            [$client] = self::client();
            try {
                $client->whm()->apiTools()->{$method}(...$arguments);
                self::fail('Invalid collections must fail.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('nested-input-secret', $error->getMessage());
                foreach ($error->getTrace() as $frame) {
                    $class = $frame['class'] ?? '';
                    if (str_starts_with($class, 'HostingPal\\WhmApiClient\\') && !str_contains($class, '\\Tests\\')) {
                        self::assertStringNotContainsString('nested-input-secret', print_r($frame['args'] ?? [], true));
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testWrongNativeScalarsRetainPriorMetadata(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('applist', '{"app":[]}')));
        $client->whm()->apiTools()->listApplications();
        $previous = $client->whm()->responseMeta();
        foreach (self::collectionPositions() as [, $method, $parameter, $base]) {
            foreach ([false, 1, 1.5, null] as $bad) {
                if (
                    $bad === null && $method !== 'revokeToken'
                    && !($method === 'createDashboardToken' && $parameter === 'acl')
                ) {
                    continue;
                }
                try {
                    $client->whm()->apiTools()->{$method}(...array_replace($base, [$parameter => $bad]));
                    self::fail('Wrong native scalar types must fail before entry.');
                } catch (TypeError) {
                    self::assertSame($previous, $client->whm()->responseMeta());
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    #[DataProvider('tokenCreationOperations')]
    public function testCreatedSecretRemainsRecoverableFromMismatchWithoutRetry(string $function, string $method): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            $function,
            '{"name":"created-fixture","token":"recoverable-secret","expires_at":false,"unknown":"kept"}',
        )));
        try {
            $client->whm()->apiTools()->{$method}(tokenName: 'created-fixture', acl: 'list-accts');
            self::fail('A created token with invalid settings must retain its unvalidated payload.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(['name' => 'created-fixture', 'token' => 'recoverable-secret',
                'expires_at' => false, 'unknown' => 'kept'], $error->payload);
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertStringNotContainsString('recoverable-secret', $error->getMessage());
            self::assertStringNotContainsString('recoverable-secret', print_r($error, true));
        }
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('token_name=created-fixture&acl=list-accts', (string) $request->getBody());
    }

    /** @return iterable<string, array{string, string}> */
    public static function tokenCreationOperations(): iterable
    {
        yield 'ordinary token' => ['api_token_create', 'createToken'];
        yield 'dashboard token' => ['wp_dashboard_create_api_token', 'createDashboardToken'];
    }

    public function testSelectorsAndSensitiveParameters(): void
    {
        [$client, $http] = self::client();
        $group = $client->whm()->apiTools();
        self::assertSame($group, $client->whm()->apiTools());
        self::assertSame([], $http->getRequests());
        self::assertNull($client->whm()->responseMeta());
        foreach (
            ['createToken' => ['acl', 'whitelistIp'], 'createDashboardToken' => ['acl', 'whitelistIp'],
            'updateToken' => ['acl', 'whitelistIp'], 'revokeToken' => ['tokenName'], 'getTokenDetails' => ['token'],
            'createUserSession' => ['cpSecurityToken', 'promptToken']] as $method => $parameters
        ) {
            foreach ($parameters as $parameter) {
                self::assertCount(1, (new ReflectionParameter([$group, $method], $parameter))
                    ->getAttributes(SensitiveParameter::class));
            }
        }
        foreach (self::collectionPositions() as [, $method, $parameter]) {
            $nullable = $method === 'createDashboardToken' && $parameter === 'acl' || $method === 'revokeToken'
                ? '' : '|null';
            self::assertStringContainsString(
                '@param string|non-empty-list<string>' . $nullable . ' $' . $parameter,
                (new ReflectionMethod($group, $method))->getDocComment() ?: '',
            );
        }
        foreach (
            ['createToken' => 1, 'getTokenDetails' => 1, 'listTokens' => 0, 'revokeToken' => 1,
            'updateToken' => 1, 'createDashboardToken' => 2, 'createUserSession' => 2, 'createDashboardLoginLink' => 0,
            'listApplications' => 0, 'getApiCalls' => 0, 'getApiPages' => 0,
            'getAppconfigApplicationList' => 0] as $method => $count
        ) {
            self::assertSame($count, (new ReflectionMethod($group, $method))->getNumberOfRequiredParameters());
        }
    }

    public function testSecretArgumentsAndResponseAreRedactedInLibraryTraces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (['getTokenDetails', 'createUserSession'] as $method) {
                [$client, $http] = self::client();
                $http->addResponse(new Response(200, [], '{"metadata":{"result":1},'
                    . '"data":{"name":false,"session":false,"token":"response-secret"}}'));
                try {
                    if ($method === 'getTokenDetails') {
                        $client->whm()->apiTools()->getTokenDetails('input-secret');
                    } else {
                        $client->whm()->apiTools()->createUserSession(
                            'cpaneld',
                            'user',
                            cpSecurityToken: 'input-secret',
                            promptToken: 'prompt-secret',
                        );
                    }
                    self::fail('Malformed response must fail.');
                } catch (PayloadMismatchException $error) {
                    foreach ($error->getTrace() as $frame) {
                        $class = $frame['class'] ?? '';
                        if (
                            !str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                            || str_contains($class, '\\Tests\\')
                        ) {
                            continue;
                        }
                        $trace = print_r($frame['args'] ?? [], true);
                        self::assertStringNotContainsString('input-secret', $trace);
                        self::assertStringNotContainsString('prompt-secret', $trace);
                        self::assertStringNotContainsString('response-secret', $trace);
                    }
                    self::assertStringNotContainsString('response-secret', $error->getMessage());
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    private static function details(): string
    {
        return '{"acls":["list-accts"],"create_time":123,"expires_at":null'
            . ',"name":"fixture","whitelist_ips":["192.0.2.1"]}';
    }

    private static function created(): string
    {
        return '{"acls":["list-accts"],"create_time":123,"expires_at":null'
            . ',"name":"fixture","token":"result-secret","whitelist_ips":null}';
    }

    private static function session(): string
    {
        return '{"cp_security_token":"/cpsess123","expires":123,"service":"cpaneld","session":"user:private",'
            . '"url":"https://private.example.test:2083/cpsess123/login/?session=user:private"}';
    }

    private static function application(): string
    {
        return '{"acls":["any"],"displayname":"Plugin","entryurl":"plugin/","features":["mail"],"group":"mail",'
            . '"icon":"plugin.ico","name":"plugin","origin":"plugin.conf","phpConfig":"php.ini",'
            . '"target":"_self","upgradecall":"/bin/plugin-upgrade","url":"/plugin","user":"root"}';
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }

    private static function envelope(string $function, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $function . '","result":' . $result . ',"reason":"Synthetic"}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory)), $http];
    }
}
