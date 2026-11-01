<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\LangPhpSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\Fingerprint;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fields typed from the 2026-11-01 name inspection on cPanel 11.138.0.19.
 *
 * Payloads and expectations are written by hand and every value is synthetic. The live
 * inspection recorded names and structural types only, so nothing here restates a server value.
 *
 * @phpstan-type Operation Closure(UapiClient): mixed
 */
final class DomainPhpObservedFieldsTest extends TestCase
{
    /** @param Operation $call */
    #[DataProvider('accepted')]
    public function testObservedFieldsReachTheCaller(
        string $route,
        Closure $call,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame($expected, $call($client->uapi('npr')));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, Operation, string, mixed}> */
    public static function accepted(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach (self::acceptedCases() as $name => $case) {
                yield $route . ' ' . $name => [$route, ...$case];
            }
        }
    }

    /** @return iterable<string, array{Operation, string, mixed}> */
    private static function acceptedCases(): iterable
    {
        $domains = static fn (UapiClient $u): array => $u->domainInfo()->domainsData();
        $single = static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'example.test');
        $directives = static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserBasicDirectives(type: 'home');
        $paths = static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserPaths();

        yield 'main domain quoted flag and strings' => [
            $domains,
            '{"main_domain":{"phpopenbasedirprotect":"1","phpversion":"fixture-a","status":"fixture-b"}}',
            ['main_domain' => ['phpopenbasedirprotect' => 1, 'phpversion' => 'fixture-a', 'status' => 'fixture-b']],
        ];
        yield 'main domain quoted zero and empty strings' => [
            $domains,
            '{"main_domain":{"phpopenbasedirprotect":"0","phpversion":"","status":""}}',
            ['main_domain' => ['phpopenbasedirprotect' => 0, 'phpversion' => '', 'status' => '']],
        ];
        yield 'main domain integer flag' => [
            $domains,
            '{"main_domain":{"phpopenbasedirprotect":0}}',
            ['main_domain' => ['phpopenbasedirprotect' => 0]],
        ];
        yield 'main domain omitted fields stay absent' => [
            $domains,
            '{"main_domain":{"domain":"example.test"}}',
            ['main_domain' => ['domain' => 'example.test']],
        ];
        yield 'single domain root strings' => [
            $single,
            '{"domain":"example.test","phpversion":"fixture-a","status":"fixture-b"}',
            ['domain' => 'example.test', 'phpversion' => 'fixture-a', 'status' => 'fixture-b'],
        ];
        yield 'single domain omitted fields stay absent' => [
            $single,
            '{"domain":"example.test"}',
            ['domain' => 'example.test'],
        ];
        yield 'customlog list item carries format instead of the pinned domain' => [
            $single,
            '{"customlog":[{"format":"fixture-a","target":"/fixture/one"},{"format":"","target":"/fixture/two"}]}',
            ['customlog' => [
                ['format' => 'fixture-a', 'target' => '/fixture/one'],
                ['format' => '', 'target' => '/fixture/two'],
            ]],
        ];
        yield 'customlog list item keeps the pinned domain' => [
            $single,
            '{"customlog":[{"domain":"fixture-a","target":"/fixture/one"}]}',
            ['customlog' => [['domain' => 'fixture-a', 'target' => '/fixture/one']]],
        ];
        yield 'customlog pinned object form is unchanged' => [
            $single,
            '{"customlog":{"domain":"fixture-a","target":"/fixture/one"}}',
            ['customlog' => ['domain' => 'fixture-a', 'target' => '/fixture/one']],
        ];
        yield 'directive rows carry the field independently' => [
            $directives,
            '{"directives":[{"cpanel_default":"fixture-a","key":"first"},{"key":"second"},'
                . '{"cpanel_default":"","key":"third"}]}',
            ['directives' => [
                ['cpanel_default' => 'fixture-a', 'key' => 'first'],
                ['key' => 'second'],
                ['cpanel_default' => '', 'key' => 'third'],
            ]],
        ];
        yield 'path rows keep any integer without a flag restriction' => [
            $paths,
            '{"paths":[{"is_suspended":0,"type":"home"},{"type":"vhost"},{"is_suspended":1,"type":"vhost"},'
                . '{"is_suspended":7,"type":"vhost"}]}',
            ['paths' => [
                ['is_suspended' => 0, 'type' => 'home'],
                ['type' => 'vhost'],
                ['is_suspended' => 1, 'type' => 'vhost'],
                ['is_suspended' => 7, 'type' => 'vhost'],
            ]],
        ];
    }

    /** @param Operation $call */
    #[DataProvider('unobserved')]
    public function testRecordsWithoutEvidenceKeepTheirDocumentedShape(
        string $route,
        Closure $call,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame($expected, $call($client->uapi('npr')));
    }

    /** @return iterable<string, array{string, Operation, string, mixed}> */
    public static function unobserved(): iterable
    {
        $domains = static fn (UapiClient $u): array => $u->domainInfo()->domainsData();
        $list = static fn (UapiClient $u): array => $u->domainInfo()->domainsData(format: 'list');
        $single = static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'example.test');
        $extra = '"domain":"example.test","phpopenbasedirprotect":"1","phpversion":"fixture-a","status":"fixture-b"';
        $row = ['domain' => 'example.test'];
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            yield $route . ' addon row' => [
                $route, $domains, '{"addon_domains":[{' . $extra . '}]}', ['addon_domains' => [$row]],
            ];
            yield $route . ' subdomain row' => [
                $route, $domains, '{"sub_domains":[{' . $extra . '}]}', ['sub_domains' => [$row]],
            ];
            yield $route . ' main domain list form' => [
                $route, $domains, '{"main_domain":[{' . $extra . '}]}', ['main_domain' => [$row]],
            ];
            yield $route . ' list format row' => [$route, $list, '[{' . $extra . '}]', [$row]];
            yield $route . ' customlog object form' => [
                $route,
                $single,
                '{"customlog":{"format":"fixture-a","target":"/fixture/one"}}',
                ['customlog' => ['target' => '/fixture/one']],
            ];
        }
    }

    /** @param Operation $call */
    #[DataProvider('rejected')]
    public function testObservedFieldsKeepTheirExactTypes(string $route, Closure $call, string $payload): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account);
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, Operation, string}> */
    public static function rejected(): iterable
    {
        $domains = static fn (UapiClient $u): array => $u->domainInfo()->domainsData();
        $single = static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'example.test');
        $directives = static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserBasicDirectives(type: 'home');
        $paths = static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserPaths();
        $cases = [
            'main flag two' => [$domains, '{"main_domain":{"phpopenbasedirprotect":2}}'],
            'main quoted flag two' => [$domains, '{"main_domain":{"phpopenbasedirprotect":"2"}}'],
            'main empty flag' => [$domains, '{"main_domain":{"phpopenbasedirprotect":""}}'],
            'main null flag' => [$domains, '{"main_domain":{"phpopenbasedirprotect":null}}'],
            'main integer phpversion' => [$domains, '{"main_domain":{"phpversion":84}}'],
            'main null phpversion' => [$domains, '{"main_domain":{"phpversion":null}}'],
            'main boolean status' => [$domains, '{"main_domain":{"status":false}}'],
            'main null status' => [$domains, '{"main_domain":{"status":null}}'],
            'single integer phpversion' => [$single, '{"phpversion":84}'],
            'single null phpversion' => [$single, '{"phpversion":null}'],
            'single integer status' => [$single, '{"status":1}'],
            'single list status' => [$single, '{"status":[]}'],
            'customlog integer format' => [$single, '{"customlog":[{"format":7}]}'],
            'customlog null format' => [$single, '{"customlog":[{"format":null}]}'],
            'directive integer default' => [$directives, '{"directives":[{"cpanel_default":1}]}'],
            'directive boolean default' => [$directives, '{"directives":[{"cpanel_default":true}]}'],
            'directive null default' => [$directives, '{"directives":[{"cpanel_default":null}]}'],
            'path string integer' => [$paths, '{"paths":[{"is_suspended":"1"}]}'],
            'path boolean' => [$paths, '{"paths":[{"is_suspended":true}]}'],
            'path fraction' => [$paths, '{"paths":[{"is_suspended":1.5}]}'],
            'path null' => [$paths, '{"paths":[{"is_suspended":null}]}'],
        ];
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$call, $payload]) {
                yield $route . ' ' . $name => [$route, $call, $payload];
            }
        }
    }

    /**
     * @param array<mixed> $returns
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('typedEvidence')]
    public function testTypedFieldsAreNamedInEvidenceWithoutDiscrepancies(
        array $returns,
        string $payload,
        array $expected,
    ): void {
        $analysis = Fingerprint::analyze(json_decode($payload, false, 512, JSON_THROW_ON_ERROR), true, $returns);

        self::assertSame([], $analysis['discrepancies']);
        foreach ($expected as $path => $types) {
            self::assertSame($types, $analysis['paths'][$path] ?? null, $path);
        }
    }

    /** @return iterable<string, array{array<mixed>, string, array<string, list<string>>}> */
    public static function typedEvidence(): iterable
    {
        yield 'main domain' => [
            DomainInfoSpec::DOMAINS_DATA['returns'],
            '{"main_domain":{"phpopenbasedirprotect":"1","phpversion":"fixture-a","status":"fixture-b"}}',
            [
                '$.main_domain.phpopenbasedirprotect' => ['numeric-string'],
                '$.main_domain.phpversion' => ['string'],
                '$.main_domain.status' => ['string'],
            ],
        ];
        yield 'single domain' => [
            DomainInfoSpec::SINGLE_DOMAIN_DATA['returns'],
            '{"customlog":[{"format":"fixture-a","target":"fixture-b"}],"phpversion":"fixture-c","status":"fixture-d"}',
            [
                '$.customlog[].domain' => ['missing'],
                '$.customlog[].format' => ['string'],
                '$.phpversion' => ['string'],
                '$.status' => ['string'],
            ],
        ];
        yield 'directives' => [
            LangPhpSpec::PHP_INI_GET_USER_BASIC_DIRECTIVES['returns'],
            '{"directives":[{"cpanel_default":"fixture-a"},{"key":"fixture-b"}]}',
            ['$.directives[].cpanel_default' => ['missing', 'string']],
        ];
        yield 'paths' => [
            LangPhpSpec::PHP_INI_GET_USER_PATHS['returns'],
            '{"paths":[{"is_suspended":1},{"type":"fixture-a"}]}',
            ['$.paths[].is_suspended' => ['int', 'missing']],
        ];
    }

    /** @param array<mixed> $returns */
    #[DataProvider('remainingUnknownFields')]
    public function testEveryOtherUndeclaredKeyStillRaisesADiscrepancy(
        array $returns,
        string $payload,
        string $path,
    ): void {
        $analysis = Fingerprint::analyze(json_decode($payload, false, 512, JSON_THROW_ON_ERROR), true, $returns);

        self::assertSame([['path' => $path, 'kind' => 'unknown_field']], $analysis['discrepancies']);
        self::assertSame(['string'], $analysis['paths'][$path] ?? null);
    }

    /** @return iterable<string, array{array<mixed>, string, string}> */
    public static function remainingUnknownFields(): iterable
    {
        yield 'main domain' => [
            DomainInfoSpec::DOMAINS_DATA['returns'], '{"main_domain":{"fixture_key":"fixture-a"}}', '$.main_domain.*',
        ];
        yield 'single domain' => [
            DomainInfoSpec::SINGLE_DOMAIN_DATA['returns'], '{"fixture_key":"fixture-a"}', '$.*',
        ];
        yield 'customlog item' => [
            DomainInfoSpec::SINGLE_DOMAIN_DATA['returns'], '{"customlog":[{"fixture_key":"fixture-a"}]}',
            '$.customlog[].*',
        ];
        yield 'directive row' => [
            LangPhpSpec::PHP_INI_GET_USER_BASIC_DIRECTIVES['returns'],
            '{"directives":[{"fixture_key":"fixture-a"}]}',
            '$.directives[].*',
        ];
        yield 'path row' => [
            LangPhpSpec::PHP_INI_GET_USER_PATHS['returns'], '{"paths":[{"fixture_key":"fixture-a"}]}', '$.paths[].*',
        ];
    }

    private static function envelope(string $route, string $payload): string
    {
        $result = '{"status":1,"errors":null,"messages":null,"metadata":{},"data":' . $payload . '}';

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
