<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Offline coverage for the generated read-only DNS slice: 14 WHM API 1 functions and 7 new UAPI functions
 * (plus the two pre-existing UAPI ones, which keep their own tests). Fixtures are synthetic and follow the
 * pinned schema; they contain only example.test names and 192.0.2.0/24 / 2001:db8::/32 addresses.
 */
final class DnsSliceTest extends TestCase
{
    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('whmOperations')]
    public function testWhmOperationsSendTheExactRequestAndNormalizeThePayload(
        string $method,
        array $arguments,
        string $function,
        string $query,
        string $data,
        mixed $expected,
    ): void {
        [$client, $http] = self::client('whm');
        $whm = $client->whm();
        $group = $whm->dns();
        self::assertSame($group, $whm->dns());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::whmResponse($function, $data)));

        self::assertSame($expected, $group->{$method}(...$arguments));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?api.version=1'
                . ($query === '' ? '' : '&' . $query),
            (string) $request->getUri(),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());
        $metadata = $whm->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(200, $metadata->httpStatus);
        self::assertSame($function, $metadata->whm['command'] ?? null);
        self::assertSame(1, $metadata->whm['result'] ?? null);
        self::assertNull($metadata->uapi);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, list<mixed>, string, string, string, mixed}> */
    public static function whmOperations(): iterable
    {
        // getzonerecord returns the base fields plus the fields of the record's type (the pinned discriminator
        // mapping); each fixture is [line, wire record, expected record in spec field order].
        $zoneRecords = [
            'A' => [5, '{"Line":5,"address":"192.0.2.1","class":"IN","name":"www.example.test.","ttl":14400,'
                . '"type":"A"}', [
                'Line' => 5,
                'address' => '192.0.2.1',
                'class' => 'IN',
                'name' => 'www.example.test.',
                'ttl' => 14400,
                'type' => 'A',
            ]],
            'AAAA' => [6, '{"Line":6,"address":"2001:db8::1","class":"IN","name":"www.example.test.","ttl":14400,'
                . '"type":"AAAA"}', [
                'Line' => 6,
                'address' => '2001:db8::1',
                'class' => 'IN',
                'name' => 'www.example.test.',
                'ttl' => 14400,
                'type' => 'AAAA',
            ]],
            'MX' => [9, '{"Line":9,"class":"IN","exchange":"mail.example.test.","name":"example.test.","preference":10,'
                . '"ttl":14400,"type":"MX"}', [
                'Line' => 9,
                'class' => 'IN',
                'exchange' => 'mail.example.test.',
                'name' => 'example.test.',
                'preference' => 10,
                'ttl' => 14400,
                'type' => 'MX',
            ]],
            'TXT' => [11, '{"Line":11,"class":"IN","name":"example.test.","ttl":14400,"txtdata":"v=spf1 -all",'
                . '"type":"TXT","unencoded":1}', [
                'Line' => 11,
                'class' => 'IN',
                'name' => 'example.test.',
                'ttl' => 14400,
                'txtdata' => 'v=spf1 -all',
                'type' => 'TXT',
                'unencoded' => 1,
            ]],
            'SOA' => [1, '{"Line":1,"Lines":10,"class":"IN","expire":604800,"mname":"ns1.example.test.",'
                . '"name":"example.test.","refresh":3600,"retry":1800,"rname":"hostmaster.example.test.",'
                . '"serial":2026101501,"ttl":86400,"type":"SOA"}', [
                'Line' => 1,
                'Lines' => 10,
                'class' => 'IN',
                'expire' => 604800,
                'mname' => 'ns1.example.test.',
                'name' => 'example.test.',
                'refresh' => 3600,
                'retry' => 1800,
                'rname' => 'hostmaster.example.test.',
                'serial' => 2026101501,
                'ttl' => 86400,
                'type' => 'SOA',
            ]],
            'NS' => [3, '{"Line":3,"class":"IN","name":"example.test.","nsdname":"ns1.example.test.","ttl":86400,'
                . '"type":"NS"}', [
                'Line' => 3,
                'class' => 'IN',
                'name' => 'example.test.',
                'nsdname' => 'ns1.example.test.',
                'ttl' => 86400,
                'type' => 'NS',
            ]],
            'CNAME' => [12, '{"Line":12,"class":"IN","cname":"example.test.","flatten":1,"flatten_to":"192.0.2.1",'
                . '"name":"ftp.example.test.","ttl":14400,"type":"CNAME"}', [
                'Line' => 12,
                'class' => 'IN',
                'cname' => 'example.test.',
                'flatten' => 1,
                'flatten_to' => '192.0.2.1',
                'name' => 'ftp.example.test.',
                'ttl' => 14400,
                'type' => 'CNAME',
            ]],
            'SRV' => [14, '{"Line":14,"class":"IN","name":"_sip._tcp.example.test.","port":5060,"priority":10,'
                . '"target":"sip.example.test.","ttl":14400,"type":"SRV","weight":5}', [
                'Line' => 14,
                'class' => 'IN',
                'name' => '_sip._tcp.example.test.',
                'port' => 5060,
                'priority' => 10,
                'target' => 'sip.example.test.',
                'ttl' => 14400,
                'type' => 'SRV',
                'weight' => 5,
            ]],
            'CAA' => [15, '{"Line":15,"class":"IN","flag":0,"name":"example.test.","tag":"issue","ttl":14400,'
                . '"type":"CAA","value":"ca.example.test"}', [
                'Line' => 15,
                'class' => 'IN',
                'flag' => 0,
                'name' => 'example.test.',
                'tag' => 'issue',
                'ttl' => 14400,
                'type' => 'CAA',
                'value' => 'ca.example.test',
            ]],
        ];
        $key = '{"active":1,"algo_desc":"ECDSA","algo_num":13,"algo_tag":"ECDSAP256SHA256","bits":256,'
            . '"created":1760000000,"digests":[{"algo_desc":"SHA-256","algo_num":2,"digest":"ABCDEF"}],'
            . '"flags":257,"key_id":1,"key_tag":12345,"key_type":"ksk","privatekey":"hidden"}';
        $keyExpected = [
            'active' => 1,
            'algo_desc' => 'ECDSA',
            'algo_num' => 13,
            'algo_tag' => 'ECDSAP256SHA256',
            'bits' => 256,
            'created' => 1760000000,
            'digests' => [['algo_desc' => 'SHA-256', 'algo_num' => 2, 'digest' => 'ABCDEF']],
            'flags' => 257,
            'key_id' => 1,
            'key_tag' => 12345,
            'key_type' => 'ksk',
            'privatekey' => 'hidden',
        ];

        yield 'export_zone_files single' => [
            'exportZoneFiles', ['example.test'], 'export_zone_files', 'zone=example.test',
            '{"payload":[{"text_b64":"Zm9v","zone":"example.test"}]}',
            ['payload' => [['text_b64' => 'Zm9v', 'zone' => 'example.test']]],
        ];
        yield 'export_zone_files repeated' => [
            'exportZoneFiles', [['example.test', 'example.org']], 'export_zone_files',
            'zone=example.test&zone=example.org',
            '{"payload":[{"text_b64":"Zm9v","zone":"example.test"},{"zone":"example.org"}]}',
            ['payload' => [['text_b64' => 'Zm9v', 'zone' => 'example.test'], ['zone' => 'example.org']]],
        ];
        yield 'export_zone_files empty payload' => [
            'exportZoneFiles', ['example.test'], 'export_zone_files', 'zone=example.test',
            '{"payload":[]}', ['payload' => []],
        ];
        yield 'fetch_ds_records_for_domains repeated' => [
            'fetchDsRecordsForDomains', [['example.test', 'example.org']], 'fetch_ds_records_for_domains',
            'domain=example.test&domain=example.org',
            '{"domains":[{"domain":"example.test","ds_records":{"keys":{"1":' . $key . '},'
                . '"nsec_details":{"nsec3_hash_algo_desc":"SHA-1","nsec3_hash_algo_num":1,"nsec3_iterations":0,'
                . '"nsec3_narrow":0,"nsec3_opt_out":1,"nsec3_salt":"","nsec_version":"NSEC3"}}},'
                . '{"domain":"example.org"}]}',
            ['domains' => [
                [
                    'domain' => 'example.test',
                    'ds_records' => [
                        'keys' => ['1' => $keyExpected],
                        'nsec_details' => [
                            'nsec3_hash_algo_desc' => 'SHA-1',
                            'nsec3_hash_algo_num' => 1,
                            'nsec3_iterations' => 0,
                            'nsec3_narrow' => 0,
                            'nsec3_opt_out' => 1,
                            'nsec3_salt' => '',
                            'nsec_version' => 'NSEC3',
                        ],
                    ],
                ],
                ['domain' => 'example.org'],
            ]],
        ];
        yield 'fetch_ds_records_for_domains single, no records' => [
            'fetchDsRecordsForDomains', ['example.test'], 'fetch_ds_records_for_domains', 'domain=example.test',
            '{"domains":[]}', ['domains' => []],
        ];
        yield 'get_nameserver_config' => [
            'getNameserverConfig', [], 'get_nameserver_config', '',
            '{"nameservers":["ns1.example.test","ns2.example.test"]}',
            ['nameservers' => ['ns1.example.test', 'ns2.example.test']],
        ];
        yield 'get_nameserver_config omitted' => ['getNameserverConfig', [], 'get_nameserver_config', '', '{}', []];
        foreach ($zoneRecords as $type => [$line, $wire, $record]) {
            yield 'getzonerecord ' . $type => [
                'getZoneRecord', ['example.test', $line], 'getzonerecord', 'domain=example.test&line=' . $line,
                '{"record":[' . $wire . ']}', ['record' => [$record]],
            ];
        }
        yield 'getzonerecord SOA decimal-string timers (observed 2026-10-15)' => [
            'getZoneRecord', ['example.test', 1], 'getzonerecord', 'domain=example.test&line=1',
            '{"record":[{"Line":1,"Lines":10,"expire":"604800","refresh":"3600","retry":"1800",'
                . '"serial":"2026101501","ttl":86400,"type":"SOA"}]}',
            ['record' => [[
                'Line' => 1,
                'Lines' => 10,
                'expire' => 604800,
                'refresh' => 3600,
                'retry' => 1800,
                'serial' => 2026101501,
                'ttl' => 86400,
                'type' => 'SOA',
            ]]],
        ];
        yield 'getzonerecord MX decimal-string preference (observed 2026-10-15)' => [
            'getZoneRecord', ['example.test', 9], 'getzonerecord', 'domain=example.test&line=9',
            '{"record":[{"Line":9,"exchange":"mail.example.test.","preference":"10","type":"MX"}]}',
            ['record' => [['Line' => 9, 'exchange' => 'mail.example.test.', 'preference' => 10, 'type' => 'MX']]],
        ];
        yield 'getzonerecord omitted fields' => [
            'getZoneRecord', ['example.test', 0], 'getzonerecord', 'domain=example.test&line=0',
            '{"record":[{"Line":7,"type":"TXT"}]}', ['record' => [['Line' => 7, 'type' => 'TXT']]],
        ];
        yield 'has_local_authority' => [
            'hasLocalAuthority', ['example.test'], 'has_local_authority', 'domain=example.test',
            '{"records":[{"domain":"example.test","local_authority":1,"nameservers":["ns1.example.test"],'
                . '"zone":"example.test"},{"domain":"missing.test","error":"No zone","local_authority":0,'
                . '"zone":null},{"domain":"bare.test"}]}',
            ['records' => [
                [
                    'domain' => 'example.test',
                    'local_authority' => 1,
                    'nameservers' => ['ns1.example.test'],
                    'zone' => 'example.test',
                ],
                ['domain' => 'missing.test', 'error' => 'No zone', 'local_authority' => 0, 'zone' => null],
                ['domain' => 'bare.test'],
            ]],
        ];
        yield 'has_local_authority repeated' => [
            'hasLocalAuthority', [['example.test', 'example.org']], 'has_local_authority',
            'domain=example.test&domain=example.org',
            '{"records":[{"domain":"example.test","local_authority":1},{"domain":"example.org","local_authority":0}]}',
            ['records' => [
                ['domain' => 'example.test', 'local_authority' => 1],
                ['domain' => 'example.org', 'local_authority' => 0],
            ]],
        ];
        yield 'is_alias_available' => [
            'isAliasAvailable', [], 'is_alias_available', '',
            '{"alias":1,"aname":0,"resolver":"192.0.2.53"}',
            ['alias' => 1, 'aname' => 0, 'resolver' => '192.0.2.53'],
        ];
        yield 'is_alias_available omitted' => ['isAliasAvailable', [], 'is_alias_available', '', '{"alias":0}', [
            'alias' => 0,
        ]];
        yield 'has_local_authority explicit null error (observed 2026-10-15)' => [
            'hasLocalAuthority', ['example.test'], 'has_local_authority', 'domain=example.test',
            '{"records":[{"domain":"example.test","error":null,"local_authority":1,"zone":"example.test"}]}',
            ['records' => [
                ['domain' => 'example.test', 'error' => null, 'local_authority' => 1, 'zone' => 'example.test'],
            ]],
        ];
        yield 'is_alias_available explicit null resolver (observed 2026-10-15)' => [
            'isAliasAvailable', [], 'is_alias_available', '',
            '{"alias":0,"aname":0,"resolver":null}', ['alias' => 0, 'aname' => 0, 'resolver' => null],
        ];
        yield 'is_https_available' => [
            'isHttpsAvailable', [], 'is_https_available', '',
            '{"dns_server":"powerdns","https":1}', ['dns_server' => 'powerdns', 'https' => 1],
        ];
        yield 'is_svcb_available' => [
            'isSvcbAvailable', [], 'is_svcb_available', '',
            '{"dns_server":"bind","svcb":0}', ['dns_server' => 'bind', 'svcb' => 0],
        ];
        yield 'listmxs decimal-string preference (observed 2026-10-15)' => [
            'listMxRecords', ['example.test'], 'listmxs', 'domain=example.test',
            '{"record":[{"preference":"10","type":"MX"}]}', ['record' => [['preference' => 10, 'type' => 'MX']]],
        ];
        yield 'listmxs' => [
            'listMxRecords', ['example.test'], 'listmxs', 'domain=example.test',
            '{"record":[{"Line":9,"class":"IN","exchange":"mail.example.test.","name":"example.test.",'
                . '"preference":10,"ttl":14400,"type":"MX"}]}',
            ['record' => [[
                'Line' => 9,
                'class' => 'IN',
                'exchange' => 'mail.example.test.',
                'name' => 'example.test.',
                'preference' => 10,
                'ttl' => 14400,
                'type' => 'MX',
            ]]],
        ];
        yield 'listmxs empty' => [
            'listMxRecords', ['example.test'], 'listmxs', 'domain=example.test', '{"record":[]}', ['record' => []],
        ];
        yield 'listzones' => [
            'listZones', [], 'listzones', '',
            '{"zone":[{"domain":"example.test","zonefile":"example.test.db"},{"domain":"example.org"}]}',
            ['zone' => [['domain' => 'example.test', 'zonefile' => 'example.test.db'], ['domain' => 'example.org']]],
        ];
        yield 'lookupnsip' => [
            'lookupNameserverIp', ['ns1.example.test'], 'lookupnsip', 'host=ns1.example.test',
            '{"ip":"192.0.2.1"}', ['ip' => '192.0.2.1'],
        ];
        yield 'lookupnsips' => [
            'lookupNameserverIps', ['ns1.example.test'], 'lookupnsips', 'host=ns1.example.test',
            '{"ipv4":"192.0.2.1","ipv6":"2001:db8::1"}', ['ipv4' => '192.0.2.1', 'ipv6' => '2001:db8::1'],
        ];
        yield 'lookupnsips ipv4 only' => [
            'lookupNameserverIps', ['ns1.example.test'], 'lookupnsips', 'host=ns1.example.test',
            '{"ipv4":"192.0.2.1"}', ['ipv4' => '192.0.2.1'],
        ];
        yield 'lookupnsips empty ipv6 (observed 2026-10-15)' => [
            'lookupNameserverIps', ['ns1.example.test'], 'lookupnsips', 'host=ns1.example.test',
            '{"ipv4":"192.0.2.1","ipv6":""}', ['ipv4' => '192.0.2.1', 'ipv6' => ''],
        ];
        yield 'parse_dns_zone' => [
            'parseDnsZone', ['example.test'], 'parse_dns_zone', 'zone=example.test',
            '{"payload":[{"data_b64":["MTkyLjAuMi4x"],"dname_b64":"ZXhhbXBsZQ==","line_index":3,'
                . '"record_type":"A","ttl":14400,"type":"record"},{"line_index":1,"text_b64":"JE9SSUdJTg==",'
                . '"type":"control"},{"line_index":2,"text_b64":"IGNvbW1lbnQ=","type":"comment"}]}',
            ['payload' => [
                [
                    'data_b64' => ['MTkyLjAuMi4x'],
                    'dname_b64' => 'ZXhhbXBsZQ==',
                    'line_index' => 3,
                    'record_type' => 'A',
                    'ttl' => 14400,
                    'type' => 'record',
                ],
                ['line_index' => 1, 'text_b64' => 'JE9SSUdJTg==', 'type' => 'control'],
                ['line_index' => 2, 'text_b64' => 'IGNvbW1lbnQ=', 'type' => 'comment'],
            ]],
        ];
        yield 'parse_dns_zone empty' => [
            'parseDnsZone', ['example.test'], 'parse_dns_zone', 'zone=example.test', '{"payload":[]}',
            ['payload' => []],
        ];
        yield 'resolvedomainname' => [
            'resolveDomainName', ['example.test'], 'resolvedomainname', 'domain=example.test',
            '{"ip":"192.0.2.1"}', ['ip' => '192.0.2.1'],
        ];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('whmInvalidPayloads')]
    public function testWhmMalformedPayloadsRetainTheResponseMetadata(
        string $method,
        array $arguments,
        string $function,
        string $data,
    ): void {
        [$client, $http] = self::client('whm');
        $http->addResponse(new Response(200, [], self::whmResponse($function, $data)));

        try {
            $client->whm()->dns()->{$method}(...$arguments);
            self::fail('Expected a malformed DNS payload to be rejected.');
        } catch (InvalidResponseException $exception) {
            self::assertNotNull($exception->metadata);
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            self::assertSame($function, $exception->metadata->whm['command'] ?? null);
            self::assertSame(1, $exception->metadata->whm['result'] ?? null);
            self::assertSame(200, $exception->metadata->httpStatus);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, list<mixed>, string, string}> */
    public static function whmInvalidPayloads(): iterable
    {
        $cases = [
            ['exportZoneFiles', [['example.test']], 'export_zone_files', ['null', '[1]', '{"payload":{"a":1}}',
                '{"payload":[{"zone":7}]}', '{"payload":[1]}']],
            ['fetchDsRecordsForDomains', [['example.test']], 'fetch_ds_records_for_domains', ['null', '"x"',
                '{"domains":[{"domain":5}]}', '{"domains":[{"ds_records":{"keys":{"1":{"active":2}}}}]}']],
            ['getNameserverConfig', [], 'get_nameserver_config', ['null', '"x"', '{"nameservers":"ns1.example.test"}',
                '{"nameservers":[1]}', '{"nameservers":[null]}']],
            ['getZoneRecord', ['example.test', 1], 'getzonerecord', ['null', '{"record":[{"Line":"5"}]}',
                '{"record":[{"ttl":"14400"}]}', '{"record":{"Line":5}}', '{"record":[{"address":1,"type":"A"}]}',
                '{"record":[{"preference":"ten","type":"MX"}]}', '{"record":[{"txtdata":[],"type":"TXT"}]}',
                '{"record":[{"type":"TXT","unencoded":2}]}', '{"record":[{"serial":1.5,"type":"SOA"}]}',
                '{"record":[{"serial":"2026.1","type":"SOA"}]}', '{"record":[{"refresh":"036","type":"SOA"}]}',
                '{"record":[{"preference":"010","type":"MX"}]}', '{"record":[{"flatten":true,"type":"CNAME"}]}']],
            ['hasLocalAuthority', ['example.test'], 'has_local_authority', ['null', '{"records":[{"domain":1}]}',
                '{"records":[{"local_authority":2}]}', '{"records":[{"nameservers":"ns1"}]}',
                '{"records":[{"error":5}]}']],
            ['isAliasAvailable', [], 'is_alias_available', ['null', '"x"', '{"alias":2}', '{"aname":"yes"}',
                '{"resolver":5}']],
            ['isHttpsAvailable', [], 'is_https_available', ['null', '{"https":2}', '{"dns_server":false}']],
            ['isSvcbAvailable', [], 'is_svcb_available', ['null', '{"svcb":-1}', '{"dns_server":[]}']],
            ['listMxRecords', ['example.test'], 'listmxs', ['null', '{"record":[{"preference":"ten"}]}',
                '{"record":[{"preference":"1.5"}]}', '{"record":[{"preference":"010"}]}',
                '{"record":[{"exchange":1}]}', '{"record":"mail"}']],
            ['listZones', [], 'listzones', ['null', '{"zone":[{"domain":1}]}', '{"zone":[{"zonefile":[]}]}',
                '{"zone":"example.test"}']],
            ['lookupNameserverIp', ['ns1.example.test'], 'lookupnsip', ['null', '{"ip":1}', '{"ip":null}',
                '"192.0.2.1"']],
            ['lookupNameserverIps', ['ns1.example.test'], 'lookupnsips', ['null', '{"ipv4":1}', '{"ipv6":[]}']],
            ['parseDnsZone', ['example.test'], 'parse_dns_zone', ['null', '{"payload":[{"line_index":"1"}]}',
                '{"payload":[{"data_b64":"x"}]}', '{"payload":[1]}']],
            ['resolveDomainName', ['example.test'], 'resolvedomainname', ['null', '{"ip":1}', '{"ip":true}']],
        ];
        foreach ($cases as [$method, $arguments, $function, $payloads]) {
            foreach ($payloads as $index => $data) {
                yield $function . ' ' . $index => [$method, $arguments, $function, $data];
            }
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('uapiOperations')]
    public function testUapiOperationsSendTheExactRequestOnBothRoutes(
        string $route,
        string $method,
        array $arguments,
        string $function,
        string $query,
        string $data,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $uapi = $client->uapi('npr');
        $group = $uapi->dns();
        self::assertSame($group, $uapi->dns());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::uapiResponse($route, $data)));

        self::assertSame($expected, $group->{$method}(...$arguments));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=DNS&cpanel.function=' . $function . ($query === '' ? '' : '&' . $query)
                : 'https://api.example.test:2083/execute/DNS/' . $function . ($query === '' ? '' : '?' . $query),
            (string) $request->getUri(),
        );
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('', (string) $request->getBody());
        $metadata = $uapi->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(200, $metadata->httpStatus);
        self::assertSame(1, $metadata->uapi['status'] ?? null);
        self::assertSame(['dns notice'], $metadata->uapi['warnings'] ?? null);
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whm['result'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>, string, string, string, mixed}> */
    public static function uapiOperations(): iterable
    {
        $cases = [
            'ensure_domains_reside_only_locally single' => [
                'ensureDomainsResideOnlyLocally', ['example.test'], 'ensure_domains_reside_only_locally',
                'domain=example.test', '["example.test"]', ['example.test'],
            ],
            'ensure_domains_reside_only_locally repeated' => [
                'ensureDomainsResideOnlyLocally', [['example.test', 'example.org']],
                'ensure_domains_reside_only_locally', 'domain=example.test&domain=example.org',
                '["example.test","example.org"]', ['example.test', 'example.org'],
            ],
            'ensure_domains_reside_only_locally empty' => [
                'ensureDomainsResideOnlyLocally', ['example.test'], 'ensure_domains_reside_only_locally',
                'domain=example.test', '[]', [],
            ],
            'ensure_domains_reside_only_locally null' => [
                'ensureDomainsResideOnlyLocally', ['example.test'], 'ensure_domains_reside_only_locally',
                'domain=example.test', 'null', null,
            ],
            'ensure_domains_reside_only_locally null item (observed 2026-10-15)' => [
                'ensureDomainsResideOnlyLocally', [['example.test', 'example.org']],
                'ensure_domains_reside_only_locally', 'domain=example.test&domain=example.org',
                '[null,"Resolves elsewhere"]', [null, 'Resolves elsewhere'],
            ],
            'ensure_domains_use_recognized_nameservers repeated' => [
                'ensureDomainsUseRecognizedNameservers', [['example.test', 'example.org']],
                'ensure_domains_use_recognized_nameservers', 'domain=example.test&domain=example.org',
                '[{"message":"Nameservers differ","type":"warning"},null,{"type":"notice"}]',
                [['message' => 'Nameservers differ', 'type' => 'warning'], null, ['type' => 'notice']],
            ],
            'ensure_domains_use_recognized_nameservers single empty' => [
                'ensureDomainsUseRecognizedNameservers', ['example.test'],
                'ensure_domains_use_recognized_nameservers', 'domain=example.test', '[]', [],
            ],
            'ensure_domains_use_recognized_nameservers null' => [
                'ensureDomainsUseRecognizedNameservers', ['example.test'],
                'ensure_domains_use_recognized_nameservers', 'domain=example.test', 'null', null,
            ],
            'has_local_authority' => [
                'hasLocalAuthority', ['example.test'], 'has_local_authority', 'domain=example.test',
                '[{"domain":"example.test","local_authority":1,"nameservers":["ns1.example.test"],'
                    . '"zone":"example.test"},{"domain":"missing.test","error":"No zone","local_authority":0,'
                    . '"zone":null},{"domain":"bare.test"}]',
                [
                    [
                        'domain' => 'example.test',
                        'local_authority' => 1,
                        'nameservers' => ['ns1.example.test'],
                        'zone' => 'example.test',
                    ],
                    ['domain' => 'missing.test', 'error' => 'No zone', 'local_authority' => 0, 'zone' => null],
                    ['domain' => 'bare.test'],
                ],
            ],
            'has_local_authority repeated' => [
                'hasLocalAuthority', [['example.test', 'example.org']], 'has_local_authority',
                'domain=example.test&domain=example.org',
                '[{"domain":"example.test","local_authority":1},{"domain":"example.org","local_authority":0}]',
                [
                    ['domain' => 'example.test', 'local_authority' => 1],
                    ['domain' => 'example.org', 'local_authority' => 0],
                ],
            ],
            'has_local_authority empty' => [
                'hasLocalAuthority', ['example.test'], 'has_local_authority', 'domain=example.test', '[]', [],
            ],
            'has_local_authority explicit null error (observed 2026-10-15)' => [
                'hasLocalAuthority', ['example.test'], 'has_local_authority', 'domain=example.test',
                '[{"domain":"example.test","error":null,"local_authority":1}]',
                [['domain' => 'example.test', 'error' => null, 'local_authority' => 1]],
            ],
            'is_alias_available explicit null resolver (observed 2026-10-15)' => [
                'isAliasAvailable', [], 'is_alias_available', '',
                '{"alias":0,"aname":0,"resolver":null}', ['alias' => 0, 'aname' => 0, 'resolver' => null],
            ],
            'is_alias_available' => [
                'isAliasAvailable', [], 'is_alias_available', '',
                '{"alias":1,"aname":0,"resolver":"192.0.2.53"}',
                ['alias' => 1, 'aname' => 0, 'resolver' => '192.0.2.53'],
            ],
            'is_alias_available omitted' => ['isAliasAvailable', [], 'is_alias_available', '', '{"aname":1}', [
                'aname' => 1,
            ]],
            'is_https_available' => [
                'isHttpsAvailable', [], 'is_https_available', '',
                '{"dns_server":"powerdns","https":1}', ['dns_server' => 'powerdns', 'https' => 1],
            ],
            'is_svcb_available' => [
                'isSvcbAvailable', [], 'is_svcb_available', '',
                '{"dns_server":"bind","svcb":0}', ['dns_server' => 'bind', 'svcb' => 0],
            ],
            'parse_zone' => [
                'parseZone', ['example.test'], 'parse_zone', 'zone=example.test',
                '[{"data_b64":["MTkyLjAuMi4x"],"dname_b64":"ZXhhbXBsZQ==","line_index":3,"record_type":"A",'
                    . '"ttl":14400,"type":"record"},{"line_index":1,"text_b64":"JE9SSUdJTg==","type":"control"}]',
                [
                    [
                        'data_b64' => ['MTkyLjAuMi4x'],
                        'dname_b64' => 'ZXhhbXBsZQ==',
                        'line_index' => 3,
                        'record_type' => 'A',
                        'ttl' => 14400,
                        'type' => 'record',
                    ],
                    ['line_index' => 1, 'text_b64' => 'JE9SSUdJTg==', 'type' => 'control'],
                ],
            ],
            'parse_zone empty' => ['parseZone', ['example.test'], 'parse_zone', 'zone=example.test', '[]', []],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => [$method, $arguments, $function, $query, $data, $expected]) {
                yield $route . ' ' . $name => [$route, $method, $arguments, $function, $query, $data, $expected];
            }
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('uapiInvalidPayloads')]
    public function testUapiMalformedPayloadsRetainTheResponseMetadata(
        string $route,
        string $method,
        array $arguments,
        string $data,
    ): void {
        [$client, $http] = self::client($route);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::uapiResponse($route, $data)));

        try {
            $uapi->dns()->{$method}(...$arguments);
            self::fail('Expected a malformed DNS payload to be rejected.');
        } catch (InvalidResponseException $exception) {
            self::assertNotNull($exception->metadata);
            self::assertSame($uapi->responseMeta(), $exception->metadata);
            self::assertSame(1, $exception->metadata->uapi['status'] ?? null);
            self::assertSame($route === 'bridge' ? 1 : null, $exception->metadata->whm['result'] ?? null);
            self::assertSame(200, $exception->metadata->httpStatus);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>, string}> */
    public static function uapiInvalidPayloads(): iterable
    {
        $cases = [
            ['ensureDomainsResideOnlyLocally', ['example.test'], ['{}', '"example.test"', '[1]', '[false]',
                '[["x"]]']],
            ['ensureDomainsUseRecognizedNameservers', ['example.test'], ['{}', '"x"', '[1]',
                '[{"message":1}]', '[{"type":[]}]']],
            ['hasLocalAuthority', ['example.test'], ['null', '{}', '[1]', '[{"domain":1}]',
                '[{"local_authority":2}]', '[{"nameservers":"ns1"}]', '[{"error":5}]']],
            ['isAliasAvailable', [], ['null', '[1]', '{"alias":2}', '{"resolver":5}']],
            ['isHttpsAvailable', [], ['null', '{"https":"yes"}', '{"dns_server":false}']],
            ['isSvcbAvailable', [], ['null', '{"svcb":3}', '{"dns_server":[]}']],
            ['parseZone', ['example.test'], ['null', '{"a":1}', '[1]', '[{"line_index":"1"}]',
                '[{"data_b64":"x"}]']],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as [$method, $arguments, $payloads]) {
                foreach ($payloads as $index => $data) {
                    yield $route . ' ' . $method . ' ' . $index => [$route, $method, $arguments, $data];
                }
            }
        }
    }

    private static function whmResponse(string $function, string $data): string
    {
        return '{"metadata":{"command":"' . $function . '","reason":"OK","result":1,"version":1},'
            . '"data":' . $data . '}';
    }

    private static function uapiResponse(string $route, string $data): string
    {
        $result = '{"status":1,"errors":null,"messages":null,"warnings":["dns notice"],"data":' . $data . '}';

        return match ($route) {
            'direct' => '{"result":' . $result . '}',
            'bridge' => '{"metadata":{"result":1,"command":"uapi_cpanel"},"data":{"uapi":' . $result . '}}',
            default => throw new RuntimeException('Unknown DNS test route.'),
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'direct' ? 'cpanel' : 'whm',
            'username' => $route === 'direct' ? 'npr' : 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
