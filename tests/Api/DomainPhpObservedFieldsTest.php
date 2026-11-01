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
 * Fields added from the 2026-11-01 name inspection on cPanel 11.138.0.19.
 *
 * Seven paths are carried as optional `mixed` because their meaning, or their presence on that
 * operation, is not established: a read must never fail on them, whatever JSON type a server
 * sends. `customlog[].format` stays a string because pinned domains_data examples document it.
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

        yield 'main domain carries the observed forms unchanged' => [
            $domains,
            '{"main_domain":{"phpopenbasedirprotect":"1","phpversion":"fixture-a","status":"fixture-b"}}',
            ['main_domain' => ['phpopenbasedirprotect' => '1', 'phpversion' => 'fixture-a', 'status' => 'fixture-b']],
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
        yield 'single domain keeps normalizing its pinned flag' => [
            $single,
            '{"phpopenbasedirprotect":"1"}',
            ['phpopenbasedirprotect' => 1],
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
        yield 'directive rows mix every JSON type, omission and null' => [
            $directives,
            '{"directives":[{"key":"omitted"},{"cpanel_default":null,"key":"null"},{"cpanel_default":"fixture-a"},'
                . '{"cpanel_default":0},{"cpanel_default":1.5},{"cpanel_default":false},{"cpanel_default":[]},'
                . '{"cpanel_default":{"a":"fixture-a"}}]}',
            ['directives' => [
                ['key' => 'omitted'],
                ['cpanel_default' => null, 'key' => 'null'],
                ['cpanel_default' => 'fixture-a'],
                ['cpanel_default' => 0],
                ['cpanel_default' => 1.5],
                ['cpanel_default' => false],
                ['cpanel_default' => []],
                ['cpanel_default' => ['a' => 'fixture-a']],
            ]],
        ];
        yield 'path rows mix every JSON type, omission and null' => [
            $paths,
            '{"paths":[{"type":"omitted"},{"is_suspended":null,"type":"null"},{"is_suspended":1,"type":"vhost"},'
                . '{"is_suspended":"1"},{"is_suspended":true},{"is_suspended":1.5},{"is_suspended":["fixture-a"]}]}',
            ['paths' => [
                ['type' => 'omitted'],
                ['is_suspended' => null, 'type' => 'null'],
                ['is_suspended' => 1, 'type' => 'vhost'],
                ['is_suspended' => '1'],
                ['is_suspended' => true],
                ['is_suspended' => 1.5],
                ['is_suspended' => ['fixture-a']],
            ]],
        ];
    }

    /** @param Operation $call */
    #[DataProvider('carried')]
    public function testCarriedFieldsPassEveryJsonTypeThroughUnchanged(
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
    public static function carried(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach (self::carriedFields() as $field => [$call, $template, $place]) {
                foreach (self::jsonValues() as $name => [$json, $value]) {
                    yield $route . ' ' . $field . ' ' . $name => [
                        $route, $call, sprintf($template, $json), $place($value),
                    ];
                }
            }
        }
    }

    /** @return iterable<string, array{Operation, string, Closure(mixed): mixed}> */
    private static function carriedFields(): iterable
    {
        $domains = static fn (UapiClient $u): array => $u->domainInfo()->domainsData();
        $single = static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'example.test');
        $directives = static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserBasicDirectives(type: 'home');
        $paths = static fn (UapiClient $u): array => $u->langPhp()->phpIniGetUserPaths();

        foreach (['phpopenbasedirprotect', 'phpversion', 'status'] as $name) {
            yield 'main domain ' . $name => [
                $domains,
                '{"main_domain":{"' . $name . '":%s}}',
                static fn (mixed $value): array => ['main_domain' => [$name => $value]],
            ];
        }
        foreach (['phpversion', 'status'] as $name) {
            yield 'single domain ' . $name => [
                $single,
                '{"' . $name . '":%s}',
                static fn (mixed $value): array => [$name => $value],
            ];
        }
        yield 'directive cpanel_default' => [
            $directives,
            '{"directives":[{"cpanel_default":%s}]}',
            static fn (mixed $value): array => ['directives' => [['cpanel_default' => $value]]],
        ];
        yield 'path is_suspended' => [
            $paths,
            '{"paths":[{"is_suspended":%s}]}',
            static fn (mixed $value): array => ['paths' => [['is_suspended' => $value]]],
        ];
    }

    /** @return iterable<string, array{string, mixed}> */
    private static function jsonValues(): iterable
    {
        yield 'string' => ['"fixture-a"', 'fixture-a'];
        yield 'empty string' => ['""', ''];
        yield 'quoted one' => ['"1"', '1'];
        yield 'zero' => ['0', 0];
        yield 'integer outside a flag' => ['7', 7];
        yield 'negative integer' => ['-3', -3];
        yield 'float' => ['1.5', 1.5];
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'null' => ['null', null];
        yield 'empty list' => ['[]', []];
        yield 'list' => ['["fixture-a",1,null]', ['fixture-a', 1, null]];
        yield 'empty object' => ['{}', []];
        yield 'object' => ['{"a":"fixture-a","b":{"c":null}}', ['a' => 'fixture-a', 'b' => ['c' => null]]];
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
    #[DataProvider('invalidCustomlogFormats')]
    public function testCustomlogFormatStaysAString(string $route, Closure $call, string $payload): void
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
    public static function invalidCustomlogFormats(): iterable
    {
        $single = static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'example.test');
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            yield $route . ' integer' => [$route, $single, '{"customlog":[{"format":7}]}'];
            yield $route . ' null' => [$route, $single, '{"customlog":[{"format":null}]}'];
        }
    }

    /**
     * @param array<mixed> $returns
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('namedEvidence')]
    public function testFieldsAreNamedInEvidenceWithoutDiscrepancies(
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
    public static function namedEvidence(): iterable
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
        yield 'directives with every JSON type' => [
            LangPhpSpec::PHP_INI_GET_USER_BASIC_DIRECTIVES['returns'],
            '{"directives":[{"cpanel_default":"fixture-a"},{"cpanel_default":1},{"cpanel_default":1.5},'
                . '{"cpanel_default":true},{"cpanel_default":null},{"cpanel_default":["fixture-b"]},'
                . '{"cpanel_default":{"a":"fixture-c"}},{"key":"fixture-d"}]}',
            ['$.directives[].cpanel_default' => [
                'bool', 'float', 'int', 'list', 'missing', 'null', 'object', 'string',
            ]],
        ];
        yield 'paths' => [
            LangPhpSpec::PHP_INI_GET_USER_PATHS['returns'],
            '{"paths":[{"is_suspended":1},{"type":"fixture-a"}]}',
            ['$.paths[].is_suspended' => ['int', 'missing']],
        ];
    }

    /** @param array<mixed> $returns */
    #[DataProvider('carriedEvidence')]
    public function testCarriedValuesRaiseNoDiscrepancyAndKeepNestedKeysMasked(
        array $returns,
        string $template,
        string $path,
    ): void {
        $payload = sprintf($template, '{"private_key":{"private_child":"fixture-a"}}');
        $analysis = Fingerprint::analyze(json_decode($payload, false, 512, JSON_THROW_ON_ERROR), true, $returns);

        self::assertSame([], $analysis['discrepancies']);
        self::assertSame(['object'], $analysis['paths'][$path] ?? null);
        self::assertSame(['object'], $analysis['paths'][$path . '.*'] ?? null);
        self::assertSame(['string'], $analysis['paths'][$path . '.*.*'] ?? null);
        self::assertStringNotContainsString('private', json_encode($analysis, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<mixed>, string, string}> */
    public static function carriedEvidence(): iterable
    {
        $domains = DomainInfoSpec::DOMAINS_DATA['returns'];
        $single = DomainInfoSpec::SINGLE_DOMAIN_DATA['returns'];
        foreach (['phpopenbasedirprotect', 'phpversion', 'status'] as $name) {
            yield 'main domain ' . $name => [
                $domains, '{"main_domain":{"' . $name . '":%s}}', '$.main_domain.' . $name,
            ];
        }
        foreach (['phpversion', 'status'] as $name) {
            yield 'single domain ' . $name => [$single, '{"' . $name . '":%s}', '$.' . $name];
        }
        yield 'directive cpanel_default' => [
            LangPhpSpec::PHP_INI_GET_USER_BASIC_DIRECTIVES['returns'],
            '{"directives":[{"cpanel_default":%s}]}',
            '$.directives[].cpanel_default',
        ];
        yield 'path is_suspended' => [
            LangPhpSpec::PHP_INI_GET_USER_PATHS['returns'],
            '{"paths":[{"is_suspended":%s}]}',
            '$.paths[].is_suspended',
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
