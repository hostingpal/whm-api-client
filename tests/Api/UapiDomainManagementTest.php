<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Literal request and payload oracles reviewed against the pinned domain-management source. */
final class UapiDomainManagementTest extends TestCase
{
    #[DataProvider('operations')]
    public function testExactRequestsAndCompletePayloads(
        string $route,
        string $module,
        string $function,
        string $method,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), self::invoke($account, $function));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        self::assertSame(
            $uri . ($method === 'GET' && $wire !== '' ? ($route === 'bridge' ? '&' : '?') . $wire : ''),
            (string) $request->getUri(),
        );
        self::assertSame($method === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::cases() as [$module, $function, $method, $wire, $payload]) {
                yield $route . ' ' . $function => [$route, $module, $function, $method, $wire, $payload];
            }
        }
    }

    /** @return list<array{string, string, string, string, string}> */
    private static function cases(): array
    {
        return [
            ['AddonDomain', 'addaddondomain', 'POST',
                'newdomain=addon.example.test&subdomain=a.b'
                . '&dir=public_html%2Fa%20b%2B%26%3D%25%2F%E9%9B%AA&disallowdot=0&force=0',
                '{"domain":"addon.example.test"}'],
            ['AddonDomain', 'deladdondomain', 'POST',
                'domain=addon.example.test&subdomain=a.b_example.test&disallowdot=0', 'null'],
            ['AddonDomain', 'listaddondomains', 'GET', 'regex=%5Eshop%5B.%5D&return_https_redirect_status=0',
                '[{"basedir":"public_html/addon","can_https_redirect":1,"dir":"/home/u/public_html/addon",'
                . '"domain":"addon.test","domainkey":"addon_example.test","fullsubdomain":"addon.example.test",'
                . '"is_https_redirecting":0,"reldir":"home:public_html/addon","rootdomain":"example.test",'
                . '"status":"","subdomain":"addon","web_subdomain_aliases":["www",""]}]'],
            ['Domain', 'convert_temporary_to_registered', 'POST',
                'domain=temporary.cpanel.site&registered=new.example.test&docroot=public_html%2Fnew',
                '{"message":"converted"}'],
            ['Domain', 'is_temporary_domain', 'GET', 'domain=example.test', '0'],
            ['Domain', 'rename_domain', 'POST',
                'domain=old.example.test&new_domain=new.example.test&docroot=public_html%2Fnew',
                '{"message":"renamed"}'],
            ['Domain', 'temporary_domain_is_disabled', 'GET', '', '{"is_disabled":0}'],
            ['DomainLookup', 'countbasedomains', 'GET', '', '[{"count":3}]'],
            ['DomainLookup', 'getbasedomains', 'GET', 'return_temporary_domain=0', '[{"domain":"example.test"}]'],
            ['DomainLookup', 'getdocroot', 'GET', 'domain=example.test',
                '[{"docroot":"/home/u/public_html","reldocroot":"public_html"}]'],
            ['DomainLookup', 'getdocroots', 'GET', '', '[{"docroot":"/home/u/public_html","domain":"example.test"}]'],
            ['Park', 'listparkeddomains', 'GET', 'regex=%5Eshop%5B.%5D&return_https_redirect_status=0',
                '[{"basedir":"public_html","can_https_redirect":1,"dir":"/home/u/public_html","domain":"alias.test",'
                . '"is_https_redirecting":0,"reldir":"home:public_html","status":"","web_subdomain_aliases":[]}]'],
            ['Park', 'park', 'POST', 'domain=alias.example.test&disallowdot=0&force=0&topdomain=shop', 'null'],
            ['Park', 'unpark', 'POST', 'domain=alias.example.test&subdomain=shop_example.test', 'null'],
            ['SubDomain', 'addsubdomain', 'POST',
                'domain=shop&rootdomain=example.test&canoff=0&dir=public_html%2Fshop&disallowdot=0',
                '{"opaque":{"zero":0,"empty":[],"none":null}}'],
            ['SubDomain', 'changedocroot', 'POST', 'domain=shop.example.test&docroot=public_html%2Fshop',
                '{"documentroot":"/home/u/public_html/shop"}'],
            ['SubDomain', 'delsubdomain', 'POST', 'domain=shop_example.test&disallowdot=0', 'null'],
            ['SubDomain', 'getreservedsubdomains', 'GET', '', '["www","cpanel",""]'],
            ['SubDomain', 'listsubdomains', 'GET', 'regex=%5Eshop%5B.%5D&return_https_redirect_status=0',
                '[{"basedir":"public_html/shop","can_https_redirect":1,"dir":"/home/u/public_html/shop",'
                . '"domain":"shop.example.test","domainkey":"shop_example.test","is_https_redirecting":0,'
                . '"reldir":"home:public_html/shop","rootdomain":"example.test","status":"",'
                . '"subdomain":"shop","web_subdomain_aliases":["www"]}]'],
            ['SubDomain', 'validregex', 'GET', '', '["^[A-Za-z0-9][A-Za-z0-9-]*$"]'],
            ['WebVhosts', 'list_domains', 'GET', '',
                '[{"domain":"example.test","proxy_subdomains":["cpanel","webmail"],"vhost_is_ssl":1,'
                . '"vhost_name":"example.test"},{"proxy_subdomains":null,"vhost_is_ssl":0}]'],
            ['WebVhosts', 'list_ssl_capable_domains', 'GET', 'hide_temporary_domains=0',
                '[{"domain":"example.test","is_proxy":0,"vhost_name":"example.test"}]'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedOrMissingPayloadIsRejectedAfterConfirmedOutcome(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('A malformed domain-management payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        $void = ['deladdondomain', 'park', 'unpark', 'delsubdomain'];
        foreach (self::cases() as [, $function]) {
            if (in_array($function, $void, true)) {
                continue;
            }
            foreach ([null, 'false', '"wrong"', '1.5'] as $payload) {
                $cases[] = [$function, $payload];
            }
            if ($function !== 'addsubdomain') {
                $cases[] = [$function, 'null'];
            }
        }
        foreach (['listaddondomains', 'listparkeddomains', 'listsubdomains'] as $function) {
            foreach (
                ['[{"can_https_redirect":"1"}]', '[{"is_https_redirecting":2}]', '[{"status":null}]',
                '[{"web_subdomain_aliases":[1]}]', '{}', '[false]'] as $payload
            ) {
                $cases[] = [$function, $payload];
            }
        }
        foreach (['2', '"0"', 'true', '[]', '{}'] as $payload) {
            $cases[] = ['is_temporary_domain', $payload];
        }
        foreach (['[{"count":"3"}]', '[{"count":null}]', '[{"count":true}]'] as $payload) {
            $cases[] = ['countbasedomains', $payload];
        }
        $cases[] = ['temporary_domain_is_disabled', '{"is_disabled":"0"}'];
        $cases[] = ['getdocroot', '[{"docroot":null}]'];
        $cases[] = ['getdocroots', '[{"domain":false}]'];
        $cases[] = ['getbasedomains', '[{"domain":[]}]'];
        $cases[] = ['changedocroot', '{"documentroot":null}'];
        $cases[] = ['convert_temporary_to_registered', '{"message":false}'];
        $cases[] = ['rename_domain', '{"message":null}'];
        $cases[] = ['getreservedsubdomains', '[1]'];
        $cases[] = ['validregex', '[false]'];
        $cases[] = ['list_domains', '[{"proxy_subdomains":[1]}]'];
        $cases[] = ['list_domains', '[{"vhost_is_ssl":"1"}]'];
        $cases[] = ['list_ssl_capable_domains', '[{"is_proxy":2}]'];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $index => [$function, $payload]) {
                yield $route . ' ' . $function . ' ' . $index => [$route, $function, $payload];
            }
        }
    }

    public function testOptionalArgumentsAreOmittedAndSelectorsDoNotSendRequests(): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('exampleuser');
        self::assertSame($account->addonDomain(), $account->addonDomain());
        [$bridge] = self::client('bridge');
        self::assertNotSame($bridge->uapi('exampleuser')->addonDomain(), $bridge->uapi('other')->addonDomain());
        self::assertSame([], $http->getRequests());
        $calls = [
            static fn (): mixed => $account->addonDomain()->addAddonDomain(newDomain: 'addon.test', subdomain: 'addon'),
            static fn (): mixed => $account->addonDomain()->listAddonDomains(),
            static fn (): mixed => $account->domainLookup()->getDocumentRoot(),
            static fn (): mixed => $account->domainLookup()->getBaseDomains(),
            static function () use ($account): void {
                $account->park()->park(domain: 'alias.test');
            },
            static function () use ($account): void {
                $account->park()->unpark(domain: 'alias.test');
            },
            static fn (): mixed => $account->park()->listParkedDomains(),
            static fn (): mixed => $account->subDomain()->addSubdomain(domain: 'shop', rootDomain: 'example.test'),
            static fn (): mixed => $account->subDomain()->listSubdomains(),
            static fn (): mixed => $account->webVhosts()->listSslCapableDomains(),
        ];
        $bodies = ['newdomain=addon.test&subdomain=addon', '', '', '', 'domain=alias.test',
            'domain=alias.test', '', 'domain=shop&rootdomain=example.test', '', ''];
        foreach ($calls as $index => $call) {
            $payload = match ($index) {
                0 => '{}',
                4, 5, 7 => 'null',
                default => '[]',
            };
            $http->addResponse(new Response(200, [], self::envelope('direct', $payload)));
            $call();
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($bodies[$index], (string) $request->getBody());
            self::assertSame('', $request->getUri()->getQuery());
            $metadata = $account->responseMeta();
            $account->addonDomain();
            self::assertSame($metadata, $account->responseMeta());
        }
    }

    #[DataProvider('emptyPayloads')]
    public function testOptionalFieldsNullsEmptyListsAndOpaqueValuesRemainDistinct(
        string $function,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client('bare');
        $http->addResponse(new Response(200, [], self::envelope('bare', $payload)));
        self::assertSame($expected, self::invoke($client->uapi('exampleuser'), $function));
    }

    /** @return iterable<string, array{string, ?string, mixed}> */
    public static function emptyPayloads(): iterable
    {
        foreach (self::cases() as [, $function, , , $full]) {
            if (in_array($function, ['deladdondomain', 'park', 'unpark', 'delsubdomain'], true)) {
                yield $function . ' omitted' => [$function, null, null];
                continue;
            }
            if ($function === 'is_temporary_domain') {
                yield $function => [$function, '1', 1];
                continue;
            }
            $payload = str_starts_with($full, '[') ? '[]' : '{}';
            yield $function => [$function, $payload, []];
            if (str_starts_with($full, '[{')) {
                yield $function . ' omitted row fields' => [$function, '[{}]', [[]]];
            }
        }
        yield 'nullable creation' => ['addsubdomain', 'null', null];
        yield 'nullable proxy list' => ['list_domains', '[{"proxy_subdomains":null}]', [['proxy_subdomains' => null]]];
        yield 'empty proxy list' => ['list_domains', '[{"proxy_subdomains":[]}]', [['proxy_subdomains' => []]]];
    }

    public function testBridgeOuterFailureIsCheckedAndAnApiFailureNeverRetries(): void
    {
        [$client, $http] = self::client('bridge');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"denied"},'
            . '"data":{"uapi":{"status":1,"data":[]}}}'));
        $this->expectException(ApiErrorException::class);
        try {
            $client->uapi('exampleuser')->domainLookup()->getBaseDomains();
        } finally {
            self::assertCount(1, $http->getRequests());
        }
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        return match ($function) {
            'addaddondomain' => $account->addonDomain()->addAddonDomain(
                newDomain: 'addon.example.test',
                subdomain: 'a.b',
                dir: 'public_html/a b+&=%/雪', disallowDot: false, force: false,
            ),
            'deladdondomain' => $account->addonDomain()->deleteAddonDomain(
                domain: 'addon.example.test',
                subdomain: 'a.b_example.test', disallowDot: false,
            ),
            'listaddondomains' => $account->addonDomain()->listAddonDomains(
                regex: '^shop[.]',
                returnHttpsRedirectStatus: false,
            ),
            'convert_temporary_to_registered' => $account->domain()->convertTemporaryToRegistered(
                domain: 'temporary.cpanel.site',
                registered: 'new.example.test', docroot: 'public_html/new',
            ),
            'is_temporary_domain' => $account->domain()->isTemporaryDomain(domain: 'example.test'),
            'rename_domain' => $account->domain()->renameDomain(
                domain: 'old.example.test',
                newDomain: 'new.example.test',
                docroot: 'public_html/new',
            ),
            'temporary_domain_is_disabled' => $account->domain()->temporaryDomainIsDisabled(),
            'countbasedomains' => $account->domainLookup()->countBaseDomains(),
            'getbasedomains' => $account->domainLookup()->getBaseDomains(returnTemporaryDomain: false),
            'getdocroot' => $account->domainLookup()->getDocumentRoot(domain: 'example.test'),
            'getdocroots' => $account->domainLookup()->getDocumentRoots(),
            'listparkeddomains' => $account->park()->listParkedDomains(
                regex: '^shop[.]',
                returnHttpsRedirectStatus: false,
            ),
            'park' => $account->park()->park(
                domain: 'alias.example.test',
                disallowDot: false,
                force: false,
                topDomain: 'shop',
            ),
            'unpark' => $account->park()->unpark(domain: 'alias.example.test', subdomain: 'shop_example.test'),
            'addsubdomain' => $account->subDomain()->addSubdomain(
                domain: 'shop',
                rootDomain: 'example.test',
                canoff: false,
                dir: 'public_html/shop', disallowDot: false,
            ),
            'changedocroot' => $account->subDomain()->changeDocumentRoot(
                domain: 'shop.example.test',
                docroot: 'public_html/shop',
            ),
            'delsubdomain' => $account->subDomain()->deleteSubdomain(domain: 'shop_example.test', disallowDot: false),
            'getreservedsubdomains' => $account->subDomain()->getReservedSubdomains(),
            'listsubdomains' => $account->subDomain()->listSubdomains(
                regex: '^shop[.]',
                returnHttpsRedirectStatus: false,
            ),
            'validregex' => $account->subDomain()->validRegex(),
            'list_domains' => $account->webVhosts()->listDomains(),
            'list_ssl_capable_domains' => $account->webVhosts()->listSslCapableDomains(hideTemporaryDomains: false),
            default => throw new LogicException('Unknown domain test operation.'),
        };
    }

    private static function envelope(string $route, ?string $payload): string
    {
        $result = '{"status":1,"errors":null' . ($payload === null ? '' : ',"data":' . $payload) . '}';

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
        $options = ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser', 'token' => 'fixture-token'];

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
