<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** @phpstan-type Operation Closure(UapiClient): mixed */
final class DomainFeaturesPhpSectionTest extends TestCase
{
    /** @param Operation $call */
    #[DataProvider('successfulCalls')]
    public function testAccountBoundRequestsPreserveThePinnedPayload(
        string $route,
        string $module,
        string $function,
        Closure $call,
        string $method,
        string $parameters,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $other = $route === 'bridge' ? $client->uapi('other') : null;
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame($expected, $call($account));

        self::assertRequest($http, $route, $module, $function, $method, $parameters);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertNull($other?->responseMeta());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, Operation, string, string, string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach (self::cases() as $name => $case) {
                yield $route . ' ' . $name => [$route, ...$case];
            }
        }
    }

    /**
     * Independently authored endpoint requests and payloads catch wrong wire names,
     * dropped null/false/zero values, shape flattening and secrets encoded in URLs.
     *
     * @return iterable<string, array{string, string, Operation, string, string, string, mixed}>
     */
    public static function cases(): iterable
    {
        yield 'domain hash defaults' => [
            'DomainInfo', 'domains_data', static fn (UapiClient $u): array => $u->domainInfo()->domainsData(),
            'GET', '',
            '{"addon_domains":[],"main_domain":{"domain":"npr.rs","ipv6":null},"parked_domains":[],"sub_domains":[]}',
            ['addon_domains' => [], 'main_domain' => ['domain' => 'npr.rs', 'ipv6' => null],
                'parked_domains' => [], 'sub_domains' => []],
        ];
        yield 'domain list explicit false filters' => [
            'DomainInfo', 'domains_data', static fn (UapiClient $u): array => $u->domainInfo()->domainsData(
                format: 'list',
                hideTemporaryDomains: false,
                returnHttpsRedirectsStatus: false,
            ),
            'GET', 'format=list&hide_temporary_domains=0&return_https_redirects_status=0',
            '[{"domain":"npr.rs","hascgi":1,"is_temporary":0,"userdirprotect":0,"ipv6":null},'
                . '{"parked_with_https_redirects":[]}]',
            [
        ['domain' => 'npr.rs', 'hascgi' => 1, 'ipv6' => null, 'is_temporary' => 0, 'userdirprotect' => 0],
                ['parked_with_https_redirects' => []]],
        ];
        yield 'domain list pinned string flags and log lists' => [
            'DomainInfo', 'domains_data', static fn (UapiClient $u): array => $u->domainInfo()->domainsData(
                format: 'list',
                hideTemporaryDomains: true,
                returnHttpsRedirectsStatus: true,
            ),
            'GET', 'format=list&hide_temporary_domains=1&return_https_redirects_status=1',
            '[{"domain":"npr.rs","hascgi":"1","is_temporary":"0","customlog":'
                . '[{"format":"combined","target":"/logs/npr.rs"}],"scriptalias":'
                . '[{"path":"/home/npr/cgi-bin","url":"/cgi-bin/"}]}]',
            [['customlog' => [['format' => 'combined', 'target' => '/logs/npr.rs']], 'domain' => 'npr.rs',
                'hascgi' => 1, 'is_temporary' => 0,
                'scriptalias' => [['path' => '/home/npr/cgi-bin', 'url' => '/cgi-bin/']]]],
        ];
        yield 'single domain singular redirect argument' => [
            'DomainInfo', 'single_domain_data', static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(
                domain: 'npr.rs',
                hideTemporaryDomains: false,
                returnHttpsRedirectStatus: true,
            ),
            'GET', 'domain=npr.rs&hide_temporary_domains=0&return_https_redirect_status=1',
            '{"domain":"npr.rs","all_aliases_valid":0,"hascgi":1,"port":80,"userdirprotect":0}',
            ['all_aliases_valid' => 0, 'domain' => 'npr.rs', 'hascgi' => 1, 'port' => 80, 'userdirprotect' => 0],
        ];
        yield 'single domain observed lists and bounded numeric strings' => [
            'DomainInfo', 'single_domain_data', static fn (UapiClient $u): array =>
                $u->domainInfo()->singleDomainData(domain: 'npr.rs'), 'GET', 'domain=npr.rs',
            '{"customlog":[{"domain":"combined","target":"/logs/example"}],'
                . '"scriptalias":[{"path":"/cgi","url":"/cgi-bin/"}],'
                . '"hascgi":"1","phpopenbasedirprotect":"0","port":"80"}',
            ['customlog' => [['domain' => 'combined', 'target' => '/logs/example']], 'hascgi' => 1,
                'phpopenbasedirprotect' => 0, 'port' => 80,
                'scriptalias' => [['path' => '/cgi', 'url' => '/cgi-bin/']]],
        ];
        yield 'feature metadata observed quoted flags' => [
            'Features', 'get_feature_metadata', static fn (UapiClient $u): array =>
                $u->features()->getFeatureMetadata(), 'GET', '',
            '[{"is_plugin":"0"},{"is_plugin":"1"}]', [['is_plugin' => 0], ['is_plugin' => 1]],
        ];
        foreach ([0, 1, 7, -1] as $value) {
            yield 'feature observed integer payload ' . $value => [
                'Features', 'has_feature', static fn (UapiClient $u): array|int|null =>
                    $u->features()->hasFeature(name: 'autossl'), 'GET', 'name=autossl', (string) $value, $value,
            ];
        }
        yield 'single domain omitted flags' => [
            'DomainInfo', 'single_domain_data', static fn (UapiClient $u): array =>
                $u->domainInfo()->singleDomainData(domain: 'npr.rs'), 'GET', 'domain=npr.rs', '{}', [],
        ];
        yield 'feature metadata optional rows' => [
            'Features', 'get_feature_metadata', static fn (UapiClient $u): array =>
                $u->features()->getFeatureMetadata(),
            'GET', '', '[{"id":"plugin_search","is_plugin":0,"name":"Search"},{}]',
            [['id' => 'plugin_search', 'is_plugin' => 0, 'name' => 'Search'], []],
        ];
        yield 'feature null access payload' => [
            'Features', 'has_feature', static fn (UapiClient $u): array|int|null =>
                $u->features()->hasFeature(name: 'autossl'),
            'GET', 'name=autossl', 'null', null,
        ];
        yield 'feature opaque access payload' => [
            'Features', 'has_feature', static fn (UapiClient $u): array|int|null =>
                $u->features()->hasFeature(name: 'plugin'),
            'GET', 'name=plugin', '{"opaque":{"flag":false,"zero":0}}',
            ['opaque' => ['flag' => false, 'zero' => 0]],
        ];
        yield 'feature regex disabled' => [
            'Features', 'has_features_like', static fn (UapiClient $u): ?array =>
                $u->features()->hasFeaturesLike(pattern: '^mail.*$', isRegex: false),
            'GET', 'pattern=%5Email.%2A%24&is_regex=0', '{"has_matching_features":0}', ['has_matching_features' => 0],
        ];
        yield 'feature regex enabled list' => [
            'Features', 'list_features_like', static fn (UapiClient $u): ?array =>
                $u->features()->listFeaturesLike(pattern: 'mail+', isRegex: true),
            'GET', 'pattern=mail%2B&is_regex=1', '["webmail","mail_plugin"]', ['webmail', 'mail_plugin'],
        ];
        yield 'feature null search' => [
            'Features', 'has_features_like', static fn (UapiClient $u): ?array =>
                $u->features()->hasFeaturesLike(pattern: 'missing'), 'GET', 'pattern=missing', 'null', null,
        ];
        yield 'feature empty search object' => [
            'Features', 'has_features_like', static fn (UapiClient $u): ?array =>
                $u->features()->hasFeaturesLike(pattern: 'missing'), 'GET', 'pattern=missing', '{}', [],
        ];
        yield 'feature null list' => [
            'Features', 'list_features_like', static fn (UapiClient $u): ?array =>
                $u->features()->listFeaturesLike(pattern: 'missing'), 'GET', 'pattern=missing', 'null', null,
        ];
        yield 'feature empty list' => [
            'Features', 'list_features_like', static fn (UapiClient $u): ?array =>
                $u->features()->listFeaturesLike(pattern: 'missing'), 'GET', 'pattern=missing', '[]', [],
        ];
        yield 'php vhost list' => [
            'LangPHP', 'php_get_vhost_versions', static fn (UapiClient $u): array =>
                $u->langPhp()->phpGetVhostVersions(),
            'GET', '', '[{"main_domain":0,"php_fpm":1,"php_fpm_pool_parms":{"pm_max_children":5},'
                . '"phpversion_source":[{"domain":"npr.rs"},{"system_default":1}],"version":"vendor-php84",'
                . '"vhost":"sub.npr.rs"}]',
            [['main_domain' => 0, 'php_fpm' => 1, 'php_fpm_pool_parms' => ['pm_max_children' => 5],
                'phpversion_source' => [['domain' => 'npr.rs'], ['system_default' => 1]],
                'version' => 'vendor-php84', 'vhost' => 'sub.npr.rs']],
        ];
        yield 'php single vhost object per prose' => [
            'LangPHP', 'php_get_vhost_versions', static fn (UapiClient $u): array =>
                $u->langPhp()->phpGetVhostVersions(vhost: 'npr.rs'),
            'GET', 'vhost=npr.rs', '{"main_domain":1,"php_fpm":0,"version":"ea-php84","vhost":"npr.rs"}',
            ['main_domain' => 1, 'php_fpm' => 0, 'version' => 'ea-php84', 'vhost' => 'npr.rs'],
        ];
        yield 'php directive polymorphic defaults' => [
            'LangPHP', 'php_ini_get_user_basic_directives', static fn (UapiClient $u): array =>
                $u->langPhp()->phpIniGetUserBasicDirectives(type: 'vhost', vhost: 'npr.rs'),
            'GET', 'type=vhost&vhost=npr.rs',
            '{"directives":[{"default_value":false,"key":"display_errors","value":"Off"},'
                . '{"default_value":0},{"default_value":0.5},{"default_value":"0"},{}]}',
            ['directives' => [
        ['default_value' => false, 'key' => 'display_errors', 'value' => 'Off'],
                ['default_value' => 0], ['default_value' => 0.5], ['default_value' => '0'], []]],
        ];
        yield 'php content preserves raw ini text' => [
            'LangPHP', 'php_ini_get_user_content', static fn (UapiClient $u): array =>
                $u->langPhp()->phpIniGetUserContent(type: 'home'),
            'GET', 'type=home', '{"content":"[PHP]\\n; a+b&x=1\\ndisplay_errors=0\\n"}',
            ['content' => "[PHP]\n; a+b&x=1\ndisplay_errors=0\n"],
        ];
        yield 'php versions observed source object' => [
            'LangPHP', 'php_get_vhost_versions', static fn (UapiClient $u): array =>
                $u->langPhp()->phpGetVhostVersions(),
            'GET', '', '[{"phpversion_source":{"domain":"npr.rs"}}]',
            [['phpversion_source' => ['domain' => 'npr.rs']]],
        ];
        yield 'php single vhost observed source object' => [
            'LangPHP', 'php_get_vhost_versions', static fn (UapiClient $u): array =>
                $u->langPhp()->phpGetVhostVersions(vhost: 'npr.rs'),
            'GET', 'vhost=npr.rs', '{"phpversion_source":{"system_default":1}}',
            ['phpversion_source' => ['system_default' => 1]],
        ];
        yield 'php ini paths retain home and vhost rows' => [
            'LangPHP', 'php_ini_get_user_paths', static fn (UapiClient $u): array =>
                $u->langPhp()->phpIniGetUserPaths(),
            'GET', '', '{"paths":[{"main_domain":0,"path":"php.ini","type":"home"},'
                . '{"main_domain":1,"type":"vhost","vhost":"npr.rs"},{}]}',
            ['paths' => [
        ['main_domain' => 0, 'path' => 'php.ini', 'type' => 'home'],
                ['main_domain' => 1, 'type' => 'vhost', 'vhost' => 'npr.rs'], []]],
        ];
        yield 'php numbered directive values and unconstrained response' => [
            'LangPHP', 'php_ini_set_user_basic_directives', static fn (UapiClient $u): mixed =>
                $u->langPhp()->phpIniSetUserBasicDirectives(
                    directives: ['display_errors:0', 'session.save_path:/tmp/a+b&c'],
                    type: 'home',
                ),
            'POST', 'directive=display_errors%3A0&directive-1=session.save_path%3A%2Ftmp%2Fa%2Bb%26c&type=home',
            '{"opaque":[null,false,0,"0"]}', ['opaque' => [null, false, 0, '0']],
        ];
        yield 'php single directive null response' => [
            'LangPHP', 'php_ini_set_user_basic_directives', static fn (UapiClient $u): mixed =>
                $u->langPhp()->phpIniSetUserBasicDirectives(
                    directives: 'display_errors:0',
                    type: 'vhost',
                    vhost: 'npr.rs',
                ),
            'POST', 'directive=display_errors%3A0&type=vhost&vhost=npr.rs', 'null', null,
        ];
        yield 'php secret content encoded once' => [
            'LangPHP', 'php_ini_set_user_content', static fn (UapiClient $u): ?array =>
                $u->langPhp()->phpIniSetUserContent(content: "x=secret+a&b%\n", type: 'vhost', vhost: 'npr.rs'),
            'POST', 'content=x%3Dsecret%2Ba%26b%25%0A&type=vhost&vhost=npr.rs', 'null', null,
        ];
        yield 'php empty content not omitted' => [
            'LangPHP', 'php_ini_set_user_content', static fn (UapiClient $u): ?array =>
                $u->langPhp()->phpIniSetUserContent(content: '', type: 'host'),
            'POST', 'content=&type=host', '{}', [],
        ];
        yield 'php home scope remains a caller string despite the enum conflict' => [
            'LangPHP', 'php_ini_set_user_content', static fn (UapiClient $u): ?array =>
                $u->langPhp()->phpIniSetUserContent(content: '%5BPHP%5D', type: 'home'),
            'POST', 'content=%255BPHP%255D&type=home', 'null', null,
        ];
        yield 'php nullable opaque content result' => [
            'LangPHP', 'php_ini_set_user_content', static fn (UapiClient $u): ?array =>
                $u->langPhp()->phpIniSetUserContent(content: 'x=0', type: 'host'),
            'POST', 'content=x%3D0&type=host', '{"opaque":false}', ['opaque' => false],
        ];
        yield 'php repeated virtual hosts' => [
            'LangPHP', 'php_set_vhost_versions', static fn (UapiClient $u): array =>
                $u->langPhp()->phpSetVhostVersions(version: 'vendor-php84', vhosts: ['npr.rs', 'sub.npr.rs']),
            'POST', 'version=vendor-php84&vhost=npr.rs&vhost=sub.npr.rs',
            '{"vhosts":["npr.rs","sub.npr.rs"]}', ['vhosts' => ['npr.rs', 'sub.npr.rs']],
        ];
        yield 'php single virtual host' => [
            'LangPHP', 'php_set_vhost_versions', static fn (UapiClient $u): array =>
                $u->langPhp()->phpSetVhostVersions(version: 'ea-php84', vhosts: 'npr.rs'),
            'POST', 'version=ea-php84&vhost=npr.rs', '{"vhosts":[]}', ['vhosts' => []],
        ];
    }

    #[DataProvider('routes')]
    public function testFeatureAbsenceIsAnOutcomeValueAndOuterBridgeFailureStillFails(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
        self::assertNull($account->features()->hasFeature(name: 'disabled'));
        self::assertSame(0, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());

        if ($route === 'bridge') {
            $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"bridge failure"},'
                . '"data":{"uapi":{"status":0,"data":null}}}'));
            $this->expectException(ApiErrorException::class);
            $account->features()->hasFeature(name: 'disabled');
        }
    }

    #[DataProvider('malformedFeatureStatuses')]
    public function testTypedFeatureStatusMustBeAnIntegerFlagWithCurrentMetadata(string $route, string $members): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
        $account->features()->hasFeature('enabled');
        $previous = $account->responseMeta();
        $result = '{' . $members . '"data":null,"errors":["current error"]}';
        $body = match ($route) {
            'wrapped' => '{"result":' . $result . '}',
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            default => $result,
        };
        $http->addResponse(new Response(200, [], $body));
        try {
            $account->features()->hasFeature('disabled');
            self::fail('Typed feature status must be exactly integer 0 or 1.');
        } catch (InvalidResponseException $error) {
            self::assertNotInstanceOf(PayloadMismatchException::class, $error);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            if ($route !== 'bare' || $members !== '') {
                self::assertNotNull($error->metadata);
                self::assertSame(['current error'], $error->metadata->uapiErrors());
            } else {
                self::assertNull($error->metadata);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedFeatureStatuses(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach (
                ['', '"status":null,', '"status":"1",', '"status":"0",',
                '"status":2,', '"status":true,', '"status":{},'] as $index => $members
            ) {
                yield $route . ' status ' . $index => [$route, $members];
            }
        }
    }

    #[DataProvider('routes')]
    public function testStatusZeroErrorsRemainExplicitFailureMetadata(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], str_replace(
            '"errors":null',
            '"errors":["feature lookup failed"]',
            self::envelope($route, 'null', 0),
        )));
        self::assertNull($account->features()->hasFeature('lookup'));
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(0, $metadata->uapiStatus());
        self::assertSame(['feature lookup failed'], $metadata->uapiErrors());
    }

    #[DataProvider('routes')]
    public function testGenericUncheckedRetainsItsCallerInterpretedStatusSemantics(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], str_replace(
            '"status":1',
            '"status":"1"',
            self::envelope($route, 'null'),
        )));
        self::assertNull($account->call('Features', 'has_feature', outcome: OutcomePolicy::Unchecked)->data);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertNull($metadata->uapiStatus());
        self::assertSame('1', $metadata->uapi['status'] ?? null);
        if ($route !== 'bare') {
            $http->addResponse(new Response(200, [], str_replace('"status":1,', '', self::envelope($route, 'null'))));
            self::assertNull($account->call('Features', 'has_feature', outcome: OutcomePolicy::Unchecked)->data);
            $metadata = $account->responseMeta();
            self::assertNotNull($metadata);
            self::assertNull($metadata->uapiStatus());
        }
    }

    /** @param Operation $call */
    #[DataProvider('malformedPayloads')]
    public function testMissingOrMalformedPayloadRetainsConfirmedOutcomeWithoutRetry(
        string $route,
        Closure $call,
        ?string $payload,
        bool $outcomeConfirmed,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account);
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertSame($outcomeConfirmed, $error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, Operation, ?string, bool}> */
    public static function malformedPayloads(): iterable
    {
        $calls = [
            'domain hash' => static fn (UapiClient $u): array => $u->domainInfo()->domainsData(),
            'single domain' => static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'npr.rs'),
            'metadata' => static fn (UapiClient $u): array => $u->features()->getFeatureMetadata(),
            'has feature' => static fn (UapiClient $u): array|int|null => $u->features()->hasFeature(name: 'autossl'),
            'has like' => static fn (UapiClient $u): ?array => $u->features()->hasFeaturesLike(pattern: 'mail'),
            'list like' => static fn (UapiClient $u): ?array => $u->features()->listFeaturesLike(pattern: 'mail'),
            'versions' => static fn (UapiClient $u): array => $u->langPhp()->phpGetVhostVersions(),
            'basic directives' => static fn (UapiClient $u): array =>
                $u->langPhp()->phpIniGetUserBasicDirectives(type: 'home'),
            'get content' => static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserContent(type: 'home'),
            'paths' => static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserPaths(),
            'set directives' => static fn (UapiClient $u): mixed =>
                $u->langPhp()->phpIniSetUserBasicDirectives(directives: 'display_errors:0', type: 'home'),
            'set content' => static fn (UapiClient $u): ?array =>
                $u->langPhp()->phpIniSetUserContent(content: '', type: 'host'),
            'set versions' => static fn (UapiClient $u): array =>
                $u->langPhp()->phpSetVhostVersions(version: 'ea-php84', vhosts: 'npr.rs'),
        ];
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach ($calls as $name => $call) {
                yield $route . ' ' . $name . ' absent data' => [$route, $call, null, $name !== 'has feature'];
                if ($name !== 'set directives') {
                    yield $route . ' ' . $name . ' false data' => [$route, $call, 'false', $name !== 'has feature'];
                }
            }
            yield $route . ' invalid redirect flag' => [$route, $calls['single domain'], '{"hascgi":2}', true];
            yield $route . ' invalid feature row' => [$route, $calls['metadata'], '[{"is_plugin":2}]', true];
            yield $route . ' invalid search flag' => [$route, $calls['has like'], '{"has_matching_features":2}', true];
            yield $route . ' invalid quoted domain flag' => [
                $route, $calls['single domain'], '{"hascgi":"2"}', true,
            ];
            yield $route . ' invalid quoted plugin flag' => [
                $route, $calls['metadata'], '[{"is_plugin":"2"}]', true,
            ];
            yield $route . ' invalid feature numeric string' => [$route, $calls['has feature'], '"1"', false];
            yield $route . ' invalid fractional port' => [$route, $calls['single domain'], '{"port":"80.5"}', true];
            yield $route . ' invalid enabled name' => [$route, $calls['list like'], '[false]', true];
            yield $route . ' invalid ini content' => [$route, $calls['get content'], '{"content":false}', true];
            yield $route . ' invalid path flag' => [$route, $calls['paths'], '{"paths":[{"main_domain":2}]}', true];
            yield $route . ' invalid version host' => [$route, $calls['set versions'], '{"vhosts":[false]}', true];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $module,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ',"errors":null,"messages":null,"metadata":{}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
