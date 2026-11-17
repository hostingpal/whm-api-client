<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
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
 * Fields added from the 2026-11-02 name inspection of populated addon, alias and subdomain rows on 11.138.0.19.
 *
 * domains_data addon, subdomain and list-format rows carry `phpopenbasedirprotect` and `status` as optional `mixed`,
 * as the main domain already does: `status` has no known meaning, and domains_data does not promise
 * `phpopenbasedirprotect`. single_domain_data addon and subdomain results carry `ipv6` and `no_cache_update`, which the
 * pinned DomainInfo record components document with a type and a meaning, so they keep validation: a nullable string
 * and a 0/1 flag accepting quoted forms, exactly as on domains_data rows.
 *
 * Payloads and expectations are written by hand and every value is synthetic. The live inspection recorded names and
 * structural types only, so nothing here restates a server value.
 *
 * @phpstan-type Operation Closure(UapiClient): mixed
 */
final class DomainRowsObservedFieldsTest extends TestCase
{
    private const ROUTES = ['wrapped', 'bare', 'bridge'];

    /** @param Operation $call */
    #[DataProvider('carried')]
    public function testCarriedRowFieldsPassEveryJsonTypeThroughUnchanged(
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
        $hash = static fn (UapiClient $u): array => $u->domainInfo()->domainsData(format: 'hash');
        $list = static fn (UapiClient $u): array => $u->domainInfo()->domainsData(format: 'list');
        foreach (self::ROUTES as $route) {
            foreach (['phpopenbasedirprotect', 'status'] as $name) {
                foreach (self::jsonValues() as $kind => [$json, $value]) {
                    foreach (['addon_domains', 'sub_domains'] as $container) {
                        yield $route . ' ' . $container . ' ' . $name . ' ' . $kind => [
                            $route,
                            $hash,
                            '{"' . $container . '":[{"domain":"example.test","' . $name . '":' . $json . '}]}',
                            [$container => [['domain' => 'example.test', $name => $value]]],
                        ];
                    }
                    yield $route . ' list row ' . $name . ' ' . $kind => [
                        $route,
                        $list,
                        '[{"domain":"example.test","' . $name . '":' . $json . '}]',
                        [['domain' => 'example.test', $name => $value]],
                    ];
                }
            }
        }
    }

    /** @param Operation $call */
    #[DataProvider('documented')]
    public function testSingleDomainDocumentedFieldsKeepTheirValidation(
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
    public static function documented(): iterable
    {
        $single = static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'example.test');
        foreach (self::ROUTES as $route) {
            yield $route . ' null ipv6 stays null' => [$route, $single, '{"ipv6":null}', ['ipv6' => null]];
            $address = '2001:db8::1';
            yield $route . ' ipv6 address string' => [
                $route, $single, '{"ipv6":"' . $address . '"}', ['ipv6' => $address],
            ];
            yield $route . ' omitted fields stay absent' => [
                $route, $single, '{"domain":"example.test"}', ['domain' => 'example.test'],
            ];
            foreach (['"0"' => 0, '"1"' => 1] as $json => $flag) {
                yield $route . ' quoted flag ' . $json => [
                    $route, $single, '{"no_cache_update":' . $json . '}', ['no_cache_update' => $flag],
                ];
            }
            yield $route . ' native flag' => [$route, $single, '{"no_cache_update":1}', ['no_cache_update' => 1]];
            yield $route . ' both together' => [
                $route,
                $single,
                '{"domain":"example.test","ipv6":null,"no_cache_update":"0"}',
                ['domain' => 'example.test', 'ipv6' => null, 'no_cache_update' => 0],
            ];
        }
    }

    /** @param Operation $call */
    #[DataProvider('invalidDocumented')]
    public function testSingleDomainDocumentedFieldsRejectOtherForms(
        string $route,
        Closure $call,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account);
            $mismatch = null;
        } catch (PayloadMismatchException $error) {
            $mismatch = $error;
        }
        self::assertNotNull($mismatch, 'Expected a payload mismatch.');
        self::assertTrue($mismatch->outcomeConfirmed);
        self::assertSame($account->responseMeta(), $mismatch->metadata);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, Operation, string}> */
    public static function invalidDocumented(): iterable
    {
        $single = static fn (UapiClient $u): array => $u->domainInfo()->singleDomainData(domain: 'example.test');
        foreach (self::ROUTES as $route) {
            foreach (['7', 'true', '["2001:db8::1"]', '{"a":"fixture-a"}'] as $json) {
                yield $route . ' ipv6 ' . $json => [$route, $single, '{"ipv6":' . $json . '}'];
            }
            foreach (['2', '"2"', '"yes"', '""', 'null', 'true', '1.5'] as $json) {
                yield $route . ' no_cache_update ' . $json => [$route, $single, '{"no_cache_update":' . $json . '}'];
            }
        }
    }

    /** @param Operation $call */
    #[DataProvider('unobserved')]
    public function testFieldsWithoutEvidenceOnARecordKindAreStillDropped(
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
        $hash = static fn (UapiClient $u): array => $u->domainInfo()->domainsData(format: 'hash');
        $list = static fn (UapiClient $u): array => $u->domainInfo()->domainsData(format: 'list');
        $extra = '"domain":"example.test","phpopenbasedirprotect":"1","phpversion":"fixture-a","status":"fixture-b"';
        $carried = ['domain' => 'example.test', 'phpopenbasedirprotect' => '1', 'status' => 'fixture-b'];
        $row = ['domain' => 'example.test'];
        foreach (self::ROUTES as $route) {
            yield $route . ' addon row drops phpversion' => [
                $route, $hash, '{"addon_domains":[{' . $extra . '}]}', ['addon_domains' => [$carried]],
            ];
            yield $route . ' list row drops phpversion' => [$route, $list, '[{' . $extra . '}]', [$carried]];
            yield $route . ' main domain list form is unchanged' => [
                $route, $hash, '{"main_domain":[{' . $extra . '}]}', ['main_domain' => [$row]],
            ];
            yield $route . ' parked object row is unchanged' => [
                $route, $hash, '{"parked_domains":[{' . $extra . '}]}', ['parked_domains' => [$row]],
            ];
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
        $observed = '"phpopenbasedirprotect":"1","status":"fixture-a"';
        yield 'hash rows' => [
            DomainInfoSpec::DOMAINS_DATA['returns'],
            '{"addon_domains":[{' . $observed . '}],"sub_domains":[{' . $observed . '}]}',
            [
                '$.addon_domains[].phpopenbasedirprotect' => ['numeric-string'],
                '$.addon_domains[].status' => ['string'],
                '$.sub_domains[].phpopenbasedirprotect' => ['numeric-string'],
                '$.sub_domains[].status' => ['string'],
            ],
        ];
        yield 'list rows' => [
            DomainInfoSpec::DOMAINS_DATA['returns'],
            '[{' . $observed . '}]',
            ['$[].phpopenbasedirprotect' => ['numeric-string'], '$[].status' => ['string']],
        ];
        yield 'single domain' => [
            DomainInfoSpec::SINGLE_DOMAIN_DATA['returns'],
            '{"ipv6":null,"no_cache_update":"0"}',
            ['$.ipv6' => ['null'], '$.no_cache_update' => ['numeric-string']],
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
    }

    /** @return iterable<string, array{array<mixed>, string, string}> */
    public static function remainingUnknownFields(): iterable
    {
        $domains = DomainInfoSpec::DOMAINS_DATA['returns'];
        yield 'addon row phpversion' => [
            $domains, '{"addon_domains":[{"phpversion":"fixture-a"}]}', '$.addon_domains[].*',
        ];
        yield 'subdomain row key' => [$domains, '{"sub_domains":[{"fixture_key":"fixture-a"}]}', '$.sub_domains[].*'];
        yield 'list row key' => [$domains, '[{"fixture_key":"fixture-a"}]', '$[].*'];
        yield 'single domain key' => [DomainInfoSpec::SINGLE_DOMAIN_DATA['returns'], '{"fixture_key":"a"}', '$.*'];
    }

    /** @return iterable<string, array{string, mixed}> */
    private static function jsonValues(): iterable
    {
        yield 'string' => ['"fixture-a"', 'fixture-a'];
        yield 'empty string' => ['""', ''];
        yield 'quoted one' => ['"1"', '1'];
        yield 'zero' => ['0', 0];
        yield 'integer outside a flag' => ['7', 7];
        yield 'float' => ['1.5', 1.5];
        yield 'false' => ['false', false];
        yield 'null' => ['null', null];
        yield 'list' => ['["fixture-a",1,null]', ['fixture-a', 1, null]];
        yield 'object' => ['{"a":"fixture-a"}', ['a' => 'fixture-a']];
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
