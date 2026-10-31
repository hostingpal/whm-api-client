<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Dns;
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
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Independent pinned WHM DNS contracts. Fixtures use synthetic reserved domains/addresses.
 * Wrong operation names, routing, verb, omission, wire encoding or response schemas must fail these tests.
 *
 * @phpstan-type Contract array{string, string, string, array<string, mixed>, string, ?string, mixed}
 */
final class WhmDnsManagementTest extends TestCase
{
    /** @param Contract $case */
    #[DataProvider('contracts')]
    public function testExactRequestsAndDocumentedPayloads(array $case): void
    {
        [$method, $function, $verb, $arguments, $parameters, $payload, $expected] = $case;
        [$client, $http] = self::client();
        $whm = $client->whm();
        $dns = $whm->dns();
        self::assertSame($dns, $whm->dns());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));

        self::assertSame($expected, $dns->{$method}(...$arguments));
        self::assertRequest($http, $function, $verb, $parameters);
        $meta = $whm->responseMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta->whmResult());
        self::assertSame($function, $meta->whm['command'] ?? null);
        self::assertNull($meta->uapi);
        self::assertArrayNotHasKey('data', $meta->whm ?? []);
    }

    /** @return iterable<string, array{Contract}> */
    public static function contracts(): iterable
    {
        foreach (self::operations() as $function => $case) {
            yield $function . ' populated' => [$case];
            $empty = $case;
            $empty[5] = $case[5] === null ? null : '{}';
            $empty[6] = $case[5] === null ? null : [];
            yield $function . ' omitted fields' => [$empty];
            $empty[5] = '[]';
            yield $function . ' empty optional object' => [$empty];
            if ($case[5] === null) {
                $empty[5] = 'null';
                yield $function . ' explicit null void' => [$empty];
            }
        }
        foreach (self::omissionCases() as $name => $case) {
            yield $name => [$case];
        }
        $deleted = self::operations()['delete_domain'];
        $deleted[5] = '{"type":null,"username":null}';
        $deleted[6] = ['type' => null, 'username' => null];
        yield 'delete nonexistent domain nullable fields' => [$deleted];
    }

    /** @return array<string, Contract> */
    private static function operations(): array
    {
        return [
            'activate_zone_key' => ['activateZoneKey', 'activate_zone_key', 'POST',
                ['domain' => 'example.test', 'keyId' => 12], 'domain=example.test&key_id=12', null, null],
            'add_zone_key' => ['addZoneKey', 'add_zone_key', 'POST',
                ['algoNum' => 13, 'domain' => 'example.test', 'keyType' => 'ksk', 'active' => false, 'keySize' => 256],
                'algo_num=13&domain=example.test&key_type=ksk&active=0&key_size=256',
                '{"new_key_id":"0012","future":"ignored"}', ['new_key_id' => '0012']],
            'adddns' => ['addDns', 'adddns', 'POST',
                ['domain' => 'example.test', 'ip' => '192.0.2.1', 'ipv6' => '2001:db8::1',
                    'template' => 'custom + template', 'trueowner' => 'fixture'],
                'domain=example.test&ip=192.0.2.1&ipv6=2001%3Adb8%3A%3A1&template=custom%20%2B%20template'
                    . '&trueowner=fixture', null, null],
            'cluster_member_has_trust_with' => ['clusterMemberHasTrustWith', 'cluster_member_has_trust_with', 'GET',
                ['host' => '192.0.2.1', 'althost' => '192.0.2.2'], 'host=192.0.2.1&althost=192.0.2.2',
                '{"has_trust":0}', ['has_trust' => 0]],
            'create_parked_domain_for_user' => ['createParkedDomainForUser', 'create_parked_domain_for_user', 'POST',
                ['domain' => 'alias.example.test', 'username' => 'fixture', 'webVhostDomain' => 'example.test'],
                'domain=alias.example.test&username=fixture&web_vhost_domain=example.test', null, null],
            'create_subdomain' => ['createSubdomain', 'create_subdomain', 'POST',
                ['documentRoot' => 'public_html/a + b', 'domain' => 'sub.example.test', 'useCanonicalName' => false],
                'document_root=public_html%2Fa%20%2B%20b&domain=sub.example.test&use_canonical_name=0',
                '{"username":"fixture"}', ['username' => 'fixture']],
            'deactivate_zone_key' => ['deactivateZoneKey', 'deactivate_zone_key', 'POST',
                ['domain' => 'example.test', 'keyId' => 12], 'domain=example.test&key_id=12', null, null],
            'delete_domain' => ['deleteDomain', 'delete_domain', 'POST', ['domain' => 'alias.example.test'],
                'domain=alias.example.test', '{"type":"parked","username":"fixture"}',
                ['type' => 'parked', 'username' => 'fixture']],
            'disable_dnssec_for_domains' => ['disableDnssecForDomains', 'disable_dnssec_for_domains', 'POST',
                ['domains' => ['example.test', 'other.test']], 'domain=example.test&domain=other.test',
                '{"domains":[{"disabled":1,"domain":"example.test"},{"disabled":0,"domain":"other.test"},{}]}',
                ['domains' => [
            ['disabled' => 1, 'domain' => 'example.test'],
                    ['disabled' => 0, 'domain' => 'other.test'], []]]],
            'enable_dnssec_for_domains' => ['enableDnssecForDomains', 'enable_dnssec_for_domains', 'POST',
                ['domains' => ['example.test', 'other.test'], 'active' => false,
                    'algoNum' => 13, 'keySetup' => 'simple',
                    'nsec3Iterations' => 1, 'nsec3Narrow' => false, 'nsec3OptOut' => false,
                    'nsec3Salt' => 'a1b2', 'useNsec3' => false],
                'domain=example.test&domain=other.test&active=0&algo_num=13&key_setup=simple&nsec3_iterations=1'
                    . '&nsec3_narrow=0&nsec3_opt_out=0&nsec3_salt=a1b2&use_nsec3=0',
                '{"domains":[{"domain":"example.test","enabled":1,"new_key_id":"0012",'
                    . '"nsec_error":"synthetic error","nsec_version":"NSEC3"},'
                    . '{"domain":"other.test","enabled":0},{}]}',
                ['domains' => [
            ['domain' => 'example.test', 'enabled' => 1, 'new_key_id' => '0012',
                    'nsec_error' => 'synthetic error', 'nsec_version' => 'NSEC3'],
                    ['domain' => 'other.test', 'enabled' => 0], []]]],
            'export_zone_dnskey' => ['exportZoneDnskey', 'export_zone_dnskey', 'GET',
                ['domain' => 'example.test', 'keyId' => 12], 'domain=example.test&key_id=12',
                '{"dnskey":"synthetic public DNSKEY + / =","key_id":12}',
                ['dnskey' => 'synthetic public DNSKEY + / =', 'key_id' => 12]],
            'export_zone_key' => ['exportZoneKey', 'export_zone_key', 'POST',
                ['domain' => 'example.test', 'keyId' => 12], 'domain=example.test&key_id=12',
                '{"key_tag":40481,"key_type":"CSK"}', ['key_tag' => 40481, 'key_type' => 'CSK']],
            'import_zone_key' => ['importZoneKey', 'import_zone_key', 'POST',
                ['domain' => 'example.test', 'keyData' => "synthetic-private-key + / =\n", 'keyType' => 'ksk'],
                'domain=example.test&key_data=synthetic-private-key%20%2B%20%2F%20%3D%0A&key_type=ksk',
                '{"import_key_id":12}', ['import_key_id' => 12]],
            'killdns' => ['killDns', 'killdns', 'POST', ['domain' => 'example.test'],
                'domain=example.test', null, null],
            'remove_zone_key' => ['removeZoneKey', 'remove_zone_key', 'POST',
                ['domain' => 'example.test', 'keyId' => 12], 'domain=example.test&key_id=12', null, null],
            'removezonerecord' => ['removeZoneRecord', 'removezonerecord', 'POST',
                ['line' => 4, 'zone' => 'example.test', 'serialnum' => '2026103101'],
                'line=4&zone=example.test&serialnum=2026103101', null, null],
            'resetzone' => ['resetZone', 'resetzone', 'POST',
                ['domain' => 'example.test', 'user' => 'fixture', 'zone' => 'example.test.db'],
                'domain=example.test&user=fixture&zone=example.test.db', null, null],
            'savemxs' => ['saveMxs', 'savemxs', 'POST',
                ['domain' => 'example.test', 'exchange' => 'mail.example.test', 'name' => 'example.test.',
                    'preference' => 20, 'class' => 'IN', 'ttl' => 14400],
                'domain=example.test&exchange=mail.example.test&name=example.test.&preference=20&class=IN&ttl=14400',
                null, null],
            'set_nameserver' => ['setNameserver', 'set_nameserver', 'POST', ['nameserver' => 'PowerDNS'],
                'nameserver=PowerDNS', '{"message":"synthetic queued task","nameserver":"powerdns"}',
                ['message' => 'synthetic queued task', 'nameserver' => 'powerdns']],
            'set_nsec3_for_domains' => ['setNsec3ForDomains', 'set_nsec3_for_domains', 'POST',
                ['domain' => 'example.test', 'nsec3Iterations' => 0, 'nsec3Narrow' => false,
                    'nsec3OptOut' => false, 'nsec3Salt' => 'a1b2'],
                'domain=example.test&nsec3_iterations=0&nsec3_narrow=0&nsec3_opt_out=0&nsec3_salt=a1b2',
                '{"domains":[{"domain":"example.test","enabled":0,"error":"synthetic error"},{}]}',
                ['domains' => [['domain' => 'example.test', 'enabled' => 0, 'error' => 'synthetic error'], []]]],
            'set_up_dns_resolver_workarounds' => ['setUpDnsResolverWorkarounds', 'set_up_dns_resolver_workarounds',
                'POST', [], '', '{"flags":{"do-ip6":"no","do-udp":"no","edns-buffer-size":"512"}}',
                ['flags' => ['do-ip6' => 'no', 'do-udp' => 'no', 'edns-buffer-size' => '512']]],
            'setresolvers' => ['setResolvers', 'setresolvers', 'POST',
                ['nameserver1' => '192.0.2.1', 'nameserver2' => '2001:db8::2', 'nameserver3' => ''],
                'nameserver1=192.0.2.1&nameserver2=2001%3Adb8%3A%3A2&nameserver3=', null, null],
            'unset_nsec3_for_domains' => ['unsetNsec3ForDomains', 'unset_nsec3_for_domains', 'POST',
                ['domain' => 'example.test'], 'domain=example.test',
                '{"domains":[{"disabled":0,"domain":"example.test","error":"synthetic error"},{}]}',
                ['domains' => [['disabled' => 0, 'domain' => 'example.test', 'error' => 'synthetic error'], []]]],
            'update_nameservers_config' => ['updateNameserversConfig', 'update_nameservers_config', 'POST',
                ['nameserver' => 'ns1.example.test', 'nameserver2' => 'ns2.example.test',
                    'nameserver3' => 'ns3.example.test', 'nameserver4' => 'ns4.example.test'],
                'nameserver=ns1.example.test&nameserver2=ns2.example.test&nameserver3=ns3.example.test'
                    . '&nameserver4=ns4.example.test', null, null],
            'update_reverse_dns_cache' => ['updateReverseDnsCache', 'update_reverse_dns_cache', 'POST',
                [], '', null, null],
            'updateuserdomains' => ['updateUserDomains', 'updateuserdomains', 'POST', [], '', null, null],
        ];
    }

    /** @return iterable<string, Contract> */
    private static function omissionCases(): iterable
    {
        $operations = self::operations();
        $variants = [
            'add_zone_key' => [
        ['algoNum' => 13, 'domain' => 'example.test', 'keyType' => 'zsk'],
                'algo_num=13&domain=example.test&key_type=zsk'],
            'adddns' => [
            ['domain' => 'example.test', 'ip' => '192.0.2.1', 'ipv6' => null, 'trueowner' => null],
                'domain=example.test&ip=192.0.2.1'],
            'cluster_member_has_trust_with' => [['host' => '192.0.2.1'], 'host=192.0.2.1'],
            'create_subdomain' => [
            ['documentRoot' => 'public_html/sub', 'domain' => 'sub.example.test'],
                'document_root=public_html%2Fsub&domain=sub.example.test'],
            'disable_dnssec_for_domains' => [['domains' => 'example.test'], 'domain=example.test'],
            'enable_dnssec_for_domains' => [['domains' => 'example.test'], 'domain=example.test'],
            'removezonerecord' => [['line' => 4, 'zone' => 'example.test'], 'line=4&zone=example.test'],
            'resetzone' => [['domain' => 'example.test'], 'domain=example.test'],
            'savemxs' => [['domain' => 'example.test', 'exchange' => 'mail.example.test', 'name' => 'example.test.',
                'preference' => 20], 'domain=example.test&exchange=mail.example.test&name=example.test.&preference=20'],
            'setresolvers' => [
            ['nameserver1' => '192.0.2.1', 'nameserver2' => '192.0.2.2'],
                'nameserver1=192.0.2.1&nameserver2=192.0.2.2'],
            'update_nameservers_config' => [[], ''],
        ];
        foreach ($variants as $function => [$arguments, $wire]) {
            $case = $operations[$function];
            $case[3] = $arguments;
            $case[4] = $wire;
            yield $function . ' omitted defaults' => $case;
        }
        $case = $operations['resetzone'];
        $case[3] = ['zone' => 'example.test.db'];
        $case[4] = 'zone=example.test.db';
        yield 'resetzone zone alternative' => $case;
        $case = $operations['enable_dnssec_for_domains'];
        $case[3] = ['domains' => 'example.test', 'algoNum' => 13];
        $case[4] = 'domain=example.test&algo_num=13';
        yield 'algorithm selection does not infer key setup' => $case;
        $case = $operations['enable_dnssec_for_domains'];
        $case[3] = ['domains' => 'example.test', 'active' => true, 'nsec3Narrow' => true,
            'nsec3OptOut' => true, 'useNsec3' => true];
        $case[4] = 'domain=example.test&active=1&nsec3_narrow=1&nsec3_opt_out=1&use_nsec3=1';
        yield 'enable DNSSEC explicit true flags' => $case;
        $case = $operations['add_zone_key'];
        $case[3] = ['algoNum' => 13, 'domain' => 'example.test', 'keyType' => 'ksk', 'active' => true];
        $case[4] = 'algo_num=13&domain=example.test&key_type=ksk&active=1';
        yield 'new key explicitly active' => $case;
        $case = $operations['create_subdomain'];
        $case[3] = ['documentRoot' => 'public_html/sub', 'domain' => 'sub.example.test', 'useCanonicalName' => true];
        $case[4] = 'document_root=public_html%2Fsub&domain=sub.example.test&use_canonical_name=1';
        yield 'subdomain canonical name enabled' => $case;
        $case = $operations['set_nsec3_for_domains'];
        $case[3] = ['domain' => 'example.test', 'nsec3Iterations' => 0,
            'nsec3Narrow' => true, 'nsec3OptOut' => true, 'nsec3Salt' => 'a1b2'];
        $case[4] = 'domain=example.test&nsec3_iterations=0&nsec3_narrow=1&nsec3_opt_out=1&nsec3_salt=a1b2';
        yield 'NSEC3 explicit true flags' => $case;
    }

    /** @param Contract $case */
    #[DataProvider('failureOperations')]
    public function testApiFailuresKeepMetadataAndNeverRetry(array $case): void
    {
        [$method, $function, $verb, $arguments, $wire] = $case;
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($function, null, 0)));
        try {
            $whm->dns()->{$method}(...$arguments);
            self::fail('A failed WHM outcome must throw.');
        } catch (ApiErrorException $error) {
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertRequest($http, $function, $verb, $wire);
    }

    /** @return iterable<string, array{Contract}> */
    public static function failureOperations(): iterable
    {
        foreach (self::operations() as $function => $case) {
            yield $function => [$case];
        }
    }

    /** @param Contract $case */
    #[DataProvider('invalidPayloads')]
    public function testMalformedSuccessRetainsConfirmedOutcomeWithoutRetry(array $case, ?string $payload): void
    {
        [$method, $function, $verb, $arguments, $wire] = $case;
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        try {
            $whm->dns()->{$method}(...$arguments);
            self::fail('Malformed DNS payload must be rejected.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertRequest($http, $function, $verb, $wire);
    }

    /** @return iterable<string, array{Contract, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $leaves = [
            'add_zone_key' => ['{"new_key_id":12}', '{"new_key_id":null}'],
            'cluster_member_has_trust_with' => ['{"has_trust":"1"}', '{"has_trust":2}'],
            'create_subdomain' => ['{"username":false}'],
            'delete_domain' => ['{"type":1}', '{"username":false}'],
            'disable_dnssec_for_domains' => ['{"domains":{}}', '{"domains":[1]}',
                '{"domains":[{"disabled":true}]}', '{"domains":[{"domain":1}]}'],
            'enable_dnssec_for_domains' => ['{"domains":[{"domain":1}]}', '{"domains":[{"enabled":2}]}',
                '{"domains":[{"new_key_id":12}]}', '{"domains":[{"nsec_error":null}]}',
                '{"domains":[{"nsec_version":false}]}'],
            'export_zone_dnskey' => ['{"dnskey":1}', '{"key_id":"12"}'],
            'export_zone_key' => ['{"key_tag":"12"}', '{"key_type":1}'],
            'import_zone_key' => ['{"import_key_id":"12"}', '{"import_key_id":null}'],
            'set_nameserver' => ['{"message":1}', '{"nameserver":false}'],
            'set_nsec3_for_domains' => ['{"domains":[{"domain":1}]}', '{"domains":[{"enabled":"0"}]}',
                '{"domains":[{"error":null}]}'],
            'set_up_dns_resolver_workarounds' => ['{"flags":[1]}', '{"flags":{"do-ip6":0}}',
                '{"flags":{"do-udp":false}}', '{"flags":{"edns-buffer-size":512}}'],
            'unset_nsec3_for_domains' => ['{"domains":[{"disabled":2}]}', '{"domains":[{"domain":false}]}',
                '{"domains":[{"error":null}]}'],
        ];
        foreach (self::operations() as $function => $case) {
            if ($case[5] === null) {
                continue;
            }
            foreach ([null, 'null', 'true', '1', '"x"', '[1]', ...($leaves[$function] ?? [])] as $i => $payload) {
                yield $function . ' invalid ' . $i => [$case, $payload];
            }
        }
    }

    /** @param array<array-key, mixed> $domains */
    #[DataProvider('invalidDomainLists')]
    public function testRepeatedDomainValidationClearsMetadataBeforeRejectingWithoutDispatch(
        string $method,
        array $domains,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('enable_dnssec_for_domains', '{}')));
        $whm->dns()->enableDnssecForDomains('example.test');
        self::assertNotNull($whm->responseMeta());
        try {
            $whm->dns()->{$method}($domains);
            self::fail('Invalid repeated domains must fail before dispatch.');
        } catch (InvalidArgumentException) {
            self::assertNull($whm->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidDomainLists(): iterable
    {
        $invalid = [[], [1 => 'example.test'], ['domain' => 'example.test'], [12], ['example.test', false]];
        foreach (['enableDnssecForDomains', 'disableDnssecForDomains'] as $method) {
            foreach ($invalid as $index => $domains) {
                yield $method . ' ' . $index => [$method, $domains];
            }
        }
    }

    public function testPrivateKeyInputIsSensitiveButPublicSelectorsRemainUsable(): void
    {
        $secret = new ReflectionParameter([Dns::class, 'importZoneKey'], 'keyData');
        self::assertCount(1, $secret->getAttributes(SensitiveParameter::class));
        foreach (
            [
            ['importZoneKey', 'keyType'], ['addZoneKey', 'keyType'], ['addZoneKey', 'keySize'],
            ['enableDnssecForDomains', 'keySetup'], ['activateZoneKey', 'keyId'], ['deactivateZoneKey', 'keyId'],
            ['removeZoneKey', 'keyId'], ['exportZoneKey', 'keyId'], ['exportZoneDnskey', 'keyId']] as [$method, $arg]
        ) {
            self::assertSame([], (new ReflectionParameter([Dns::class, $method], $arg))
                ->getAttributes(SensitiveParameter::class));
        }
    }

    public function testPrivateMaterialDoesNotLeakIntoFailureMessagesOrLibraryTraceArguments(): void
    {
        [$client, $http] = self::client();
        $dns = $client->whm()->dns();
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (['importZoneKey', 'exportZoneKey'] as $method) {
                $payload = '{"key_tag":"dns-secret-marker","import_key_id":"dns-secret-marker"}';
                $http->addResponse(new Response(200, [], self::envelope('synthetic', $payload)));
                try {
                    if ($method === 'importZoneKey') {
                        $dns->importZoneKey('example.test', 'dns-secret-marker', 'ksk');
                    } else {
                        $dns->exportZoneKey('example.test', 12);
                    }
                    self::fail('Malformed key payload must fail.');
                } catch (PayloadMismatchException $error) {
                    self::assertTrue($error->outcomeConfirmed);
                    self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $error->payload);
                    self::assertStringNotContainsString('dns-secret-marker', $error->getMessage());
                    self::assertStringNotContainsString('dns-secret-marker', $error->getTraceAsString());
                    foreach ($error->getTrace() as $frame) {
                        if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                            continue;
                        }
                        self::assertFalse(self::containsSecret($frame['args'] ?? []));
                    }
                }
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    private static function containsSecret(mixed $value): bool
    {
        if ($value instanceof SensitiveParameterValue) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'dns-secret-marker');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsSecret($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function assertRequest(MockClient $http, string $function, string $verb, string $wire): void
    {
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $function . '?api.version=1'
            . ($verb === 'GET' && $wire !== '' ? '&' . $wire : ''), (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        if ($verb === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $function, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $function . '","reason":"synthetic result","result":' . $result
            . ',"version":1}' . ($payload === null ? '' : ',"data":' . $payload) . '}';
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
