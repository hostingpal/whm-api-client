<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;

/** Independent literal contracts for all twelve pinned DNSSEC operations. */
final class UapiDnssecTest extends TestCase
{
    /** @param array<array-key, mixed> $expected */
    #[DataProvider('successfulPayloads')]
    public function testExactRequestsAndPayloads(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        self::assertRequest($http, $route, $function, self::parameters($function));
        self::assertCount(1, $http->getRequests());
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
        self::assertArrayNotHasKey('data', $meta->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>}> */
    public static function successfulPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $function) {
                foreach (self::validCases($function) as $index => [$payload, $expected]) {
                    yield "$route $function $index" => [$route, $function, $payload, $expected];
                }
            }
        }
    }

    /** @return list<array{string, array<array-key, mixed>}> */
    private static function validCases(string $function): array
    {
        $cases = [['{}', []], ['[]', []]];
        if (in_array($function, ['activate_zone_key', 'deactivate_zone_key', 'remove_zone_key'], true)) {
            return [...$cases,
                ['{"domain":"example.com","error":"","key_id":"0012","success":0,"future":true}',
                    ['domain' => 'example.com', 'error' => '', 'key_id' => '0012', 'success' => 0]],
                ['{"key_id":"12","success":1}', ['key_id' => '12', 'success' => 1]]];
        }
        if ($function === 'add_zone_key' || $function === 'import_zone_key') {
            return [...$cases,
                ['{"domain":"example.com","error":"synthetic failure","new_key_id":"1","success":0}',
                    ['domain' => 'example.com', 'error' => 'synthetic failure', 'new_key_id' => 1, 'success' => 0]],
                ['{"new_key_id":"12","success":1}', ['new_key_id' => 12, 'success' => 1]],
                ['{"new_key_id":12,"success":1}', ['new_key_id' => 12, 'success' => 1]]];
        }
        if ($function === 'export_zone_dnskey') {
            return [...$cases,
                ['{"dnskey":"synthetic DNSKEY text","key_id":12,"success":1}',
                    ['dnskey' => 'synthetic DNSKEY text', 'key_id' => 12, 'success' => 1]],
                ['{"dnskey":"synthetic DNSKEY text","key_id":"12","success":1}',
                    ['dnskey' => 'synthetic DNSKEY text', 'key_id' => 12, 'success' => 1]],
                ['{"dnskey":"","success":0}', ['dnskey' => '', 'success' => 0]]];
        }
        if ($function === 'export_zone_key') {
            return [...$cases,
                ['{"domain":"example.com","key_content":"synthetic private key text","key_id":12,'
                    . '"key_tag":33930,"key_type":"ZSK","success":1}',
                    ['domain' => 'example.com', 'key_content' => 'synthetic private key text', 'key_id' => 12,
                        'key_tag' => 33930, 'key_type' => 'ZSK', 'success' => 1]],
                ['{"key_content":"synthetic private key text","key_id":"12","success":1}',
                    ['key_content' => 'synthetic private key text', 'key_id' => 12, 'success' => 1]],
                ['{"key_content":"","success":0}', ['key_content' => '', 'success' => 0]]];
        }
        if ($function === 'enable_dnssec') {
            return [...$cases,
                ['{"enabled":{"example.com":{"enabled":1,"new_key_id":"1","nsec_version":"NSEC3"},'
                    . '"other.example":{"enabled":0,"new_key_id":0,"nsec_version":"NSEC"}}}',
                    ['enabled' => ['example.com' => ['enabled' => 1, 'new_key_id' => 1, 'nsec_version' => 'NSEC3'],
                        'other.example' => ['enabled' => 0, 'new_key_id' => 0, 'nsec_version' => 'NSEC']]]],
                ['{"enabled":{"example.com":{},"other.example":{"new_key_id":2}}}',
                    ['enabled' => ['example.com' => [], 'other.example' => ['new_key_id' => 2]]]],
                ['{"enabled":{}}', ['enabled' => []]]];
        }
        if ($function === 'fetch_ds_records') {
            return [...$cases,
                ['{"example.com":{"keys":{"33930":{"active":1,"algo_desc":"RSA/SHA-256",'
                    . '"algo_num":"8","algo_tag":"RSASHA256","bits":1024,"created":"1590002705",'
                    . '"digests":[{"algo_desc":"SHA-256","algo_num":"2","digest":"synthetic digest"},{}],'
                    . '"flags":256,"key_id":2,"key_tag":33930,"key_type":"ZSK","privatekey":"synthetic key"},'
                    . '"99":{"active":0}},"nsec_details":{"nsec3_hash_algo_desc":"SHA-1",'
                    . '"nsec3_hash_algo_num":"1","nsec3_iterations":"3","nsec3_narrow":1,'
                    . '"nsec3_opt_out":"0","nsec3_salt":"abcd","nsec_version":"NSEC3"}},'
                    . '"other.example":{"keys":{},"nsec_details":{"nsec_version":"NSEC"}}}',
                    ['example.com' => ['keys' => [33930 => ['active' => 1, 'algo_desc' => 'RSA/SHA-256',
                        'algo_num' => 8, 'algo_tag' => 'RSASHA256', 'bits' => 1024, 'created' => 1590002705,
                        'digests' => [['algo_desc' => 'SHA-256', 'algo_num' => 2, 'digest' => 'synthetic digest'], []],
                        'flags' => 256, 'key_id' => 2, 'key_tag' => 33930, 'key_type' => 'ZSK',
                        'privatekey' => 'synthetic key'], 99 => ['active' => 0]],
                        'nsec_details' => ['nsec3_hash_algo_desc' => 'SHA-1', 'nsec3_hash_algo_num' => 1,
                            'nsec3_iterations' => 3, 'nsec3_narrow' => 1, 'nsec3_opt_out' => 0,
                            'nsec3_salt' => 'abcd', 'nsec_version' => 'NSEC3']],
                        'other.example' => ['keys' => [], 'nsec_details' => ['nsec_version' => 'NSEC']]]],
                ['{"example.com":{},"other.example":{"keys":{"0007":{"created":0,"digests":[]}},'
                    . '"nsec_details":{}}}',
                    ['example.com' => [], 'other.example' => ['keys' => ['0007' => ['created' => 0, 'digests' => []]],
                        'nsec_details' => []]]],
                ['{"example.com":{"keys":{"7":{"created":"0","digests":[{"algo_num":2}]}},'
                    . '"nsec_details":{"nsec3_iterations":"0","nsec3_opt_out":"1","nsec3_narrow":0}}}',
                    ['example.com' => ['keys' => [7 => ['created' => 0, 'digests' => [['algo_num' => 2]]]],
                        'nsec_details' => ['nsec3_iterations' => 0, 'nsec3_narrow' => 0, 'nsec3_opt_out' => 1]]]]];
        }
        $field = $function === 'set_nsec3' ? 'enabled' : 'disabled';
        return [...$cases,
            ['{"' . $field . '":{"example.com":1,"other.example":0}}',
                [$field => ['example.com' => 1, 'other.example' => 0]]],
            ['{"' . $field . '":{}}', [$field => []]]];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidOrMissingPayloadRetainsConfirmedOutcome(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Invalid DNSSEC payload must be rejected.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $function) {
                $cases = [null, 'null', 'false', '0', '1.5', '"text"', '[{}]'];
                if ($function === 'fetch_ds_records') {
                    array_push(
                        $cases,
                        '{"example.com":null}',
                        '{"example.com":false}',
                        '{"example.com":{"keys":null}}',
                        '{"example.com":{"keys":{"1":false}}}',
                        '{"example.com":{"nsec_details":null}}',
                        '{"example.com":{"keys":{"1":{"digests":[null]}}}}',
                        '{"example.com":{"keys":{"1":{"digests":{}}}}}',
                    );
                    $strings = ['algo_desc', 'algo_tag', 'key_type', 'privatekey'];
                    foreach ($strings as $field) {
                        $cases[] = '{"example.com":{"keys":{"1":{"' . $field . '":0}}}}';
                        $cases[] = '{"example.com":{"keys":{"1":{"' . $field . '":null}}}}';
                    }
                    foreach (['active', 'algo_num', 'bits', 'created', 'flags', 'key_id', 'key_tag'] as $field) {
                        foreach (['null', 'true', '1.5', '"1.5"', '"01"', '"1e2"', '"9223372036854775808"'] as $bad) {
                            $cases[] = '{"example.com":{"keys":{"1":{"' . $field . '":' . $bad . '}}}}';
                        }
                        if (!in_array($field, ['algo_num', 'created'], true)) {
                            $cases[] = '{"example.com":{"keys":{"1":{"' . $field . '":"1"}}}}';
                        }
                    }
                    foreach (['nsec3_hash_algo_num', 'nsec3_iterations', 'nsec3_narrow', 'nsec3_opt_out'] as $field) {
                        foreach (['null', 'true', '1.5', '"1.5"', '"01"', '"9223372036854775808"'] as $bad) {
                            $cases[] = '{"example.com":{"nsec_details":{"' . $field . '":' . $bad . '}}}';
                        }
                    }
                    foreach (['nsec3_hash_algo_desc', 'nsec3_salt', 'nsec_version'] as $field) {
                        $cases[] = '{"example.com":{"nsec_details":{"' . $field . '":null}}}';
                        $cases[] = '{"example.com":{"nsec_details":{"' . $field . '":false}}}';
                    }
                    foreach (['algo_desc', 'digest', 'algo_num'] as $field) {
                        $cases[] = '{"example.com":{"keys":{"1":{"digests":[{"' . $field . '":null}]}}}}';
                    }
                    foreach (
                        ['true', '1.5', '"1.5"', '"01"', '"+1"', '" 1"', '"1e2"', '"9223372036854775808"'] as $bad
                    ) {
                        $cases[] = '{"example.com":{"keys":{"1":{"digests":[{"algo_num":' . $bad . '}]}}}}';
                    }
                    array_push(
                        $cases,
                        '{"example.com":{"keys":{"1":{"active":2}}}}',
                        '{"example.com":{"nsec_details":{"nsec3_narrow":"1"}}}',
                        '{"example.com":{"nsec_details":{"nsec3_narrow":2}}}',
                        '{"example.com":{"nsec_details":{"nsec3_opt_out":"2"}}}',
                    );
                } elseif (in_array($function, ['disable_dnssec', 'unset_nsec3', 'set_nsec3', 'enable_dnssec'], true)) {
                    $field = in_array($function, ['set_nsec3', 'enable_dnssec'], true) ? 'enabled' : 'disabled';
                    array_push(
                        $cases,
                        '{"' . $field . '":null}',
                        '{"' . $field . '":false}',
                        '{"' . $field . '":{"example.com":null}}',
                    );
                    if ($function === 'enable_dnssec') {
                        foreach (
                            ['enabled' => ['"1"', '2', 'null', 'false'],
                            'new_key_id' => ['"01"', '"1.0"', '"1e2"', '"9223372036854775808"', 'null', '1.5'],
                            'nsec_version' => ['null', 'false', '0']] as $leaf => $badValues
                        ) {
                            foreach ($badValues as $bad) {
                                $cases[] = '{"enabled":{"example.com":{"' . $leaf . '":' . $bad . '}}}';
                            }
                        }
                    } else {
                        foreach (['"1"', '2', '-1', 'true', '1.0', '[]', '{}'] as $bad) {
                            $cases[] = '{"' . $field . '":{"example.com":' . $bad . '}}';
                        }
                    }
                } else {
                    foreach (['"0"', '"1"', '2', '-1', 'null', 'true', '1.0'] as $bad) {
                        $cases[] = '{"success":' . $bad . '}';
                    }
                    $id = in_array($function, ['add_zone_key', 'import_zone_key'], true) ? 'new_key_id' : 'key_id';
                    $intId = in_array($function, [
                        'export_zone_key', 'export_zone_dnskey', 'add_zone_key', 'import_zone_key',
                    ], true);
                    $badIds = $intId
                        ? ['"01"', '"1.0"', '"+1"', '" 1"', '"1e2"', '"9223372036854775808"']
                        : ['1'];
                    foreach (['null', 'true', '1.5', '[]', ...$badIds] as $bad) {
                        $cases[] = '{"' . $id . '":' . $bad . '}';
                    }
                    $stringFields = match ($function) {
                        'export_zone_key' => ['domain', 'key_content', 'key_type'],
                        'export_zone_dnskey' => ['dnskey'],
                        default => ['domain', 'error'],
                    };
                    foreach ($stringFields as $field) {
                        $cases[] = '{"' . $field . '":null}';
                        $cases[] = '{"' . $field . '":0}';
                    }
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $function $index" => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('routes')]
    public function testCreatedKeyIdentifiersCanBePassedToEveryTypedKeyOperation(string $route): void
    {
        [$client, $http] = self::client($route);
        $dns = $client->uapi('npr')->dnssec();
        foreach (['add', 'import'] as $source) {
            $http->addResponse(new Response(200, [], self::envelope($route, '{"new_key_id":"12","success":1}')));
            $created = $source === 'add'
                ? $dns->addZoneKey('example.com', 13, 'ksk')
                : $dns->importZoneKey('example.com', 'synthetic key', 'ksk');
            self::assertArrayHasKey('new_key_id', $created);
            $keyId = $created['new_key_id'] ?? null;
            self::assertSame(12, $keyId);
            foreach (
                ['activate_zone_key', 'deactivate_zone_key', 'export_zone_dnskey', 'export_zone_key',
                    'remove_zone_key'] as $function
            ) {
                $http->addResponse(new Response(200, [], self::envelope($route, '{"success":1}')));
                $result = match ($function) {
                    'activate_zone_key' => $dns->activateZoneKey('example.com', $keyId),
                    'deactivate_zone_key' => $dns->deactivateZoneKey('example.com', $keyId),
                    'export_zone_dnskey' => $dns->exportZoneDnskey('example.com', $keyId),
                    'export_zone_key' => $dns->exportZoneKey('example.com', $keyId),
                    default => $dns->removeZoneKey('example.com', $keyId),
                };
                self::assertSame(['success' => 1], $result);
                self::assertRequest($http, $route, $function, 'domain=example.com&key_id=12');
            }
        }
        self::assertCount(12, $http->getRequests());
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailureIsNotPerDomainOrPerKeySuccess(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{}', 0)));
        try {
            self::invoke($client->uapi('npr'), $function);
            self::fail('UAPI status zero must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('operationsOnly')]
    public function testOuterWhmFailureOverridesNestedSuccess(string $function): void
    {
        [$client, $http] = self::client('bridge');
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":0,"reason":"Synthetic failure"},"data":{"uapi":{"status":1,"data":{}}}}',
        ));
        try {
            self::invoke($client->uapi('npr'), $function);
            self::fail('Outer WHM failure must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testOmittedDefaultsRemainAbsentAndFalseAndZeroAreSent(string $route): void
    {
        [$client, $http] = self::client($route);
        $dns = $client->uapi('npr')->dnssec();
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $dns->enableDnssec('example.com');
        self::assertRequest($http, $route, 'enable_dnssec', 'domain=example.com');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $dns->addZoneKey('example.com', 8, 'ksk');
        self::assertRequest($http, $route, 'add_zone_key', 'domain=example.com&algo_num=8&key_type=ksk');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $dns->enableDnssec(
            'example.com',
            active: false,
            algoNum: 0,
            nsec3Iterations: 0,
            nsec3Narrow: false,
            nsec3OptOut: false,
            nsec3Salt: '',
            useNsec3: false,
        );
        self::assertRequest($http, $route, 'enable_dnssec', 'domain=example.com&active=0&algo_num=0'
            . '&nsec3_iterations=0&nsec3_narrow=0&nsec3_opt_out=0&nsec3_salt=&use_nsec3=0');
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testPrivateKeyInputIsSensitiveAndEncodedOnce(string $route): void
    {
        [$client, $http] = self::client($route);
        $dns = $client->uapi('npr')->dnssec();
        $parameter = new ReflectionParameter([$dns, 'importZoneKey'], 'keyData');
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        $identifier = new ReflectionParameter([$dns, 'exportZoneDnskey'], 'keyId');
        self::assertCount(0, $identifier->getAttributes(SensitiveParameter::class));
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $dns->importZoneKey('example.com', "a +&=%\n雪", 'ksk');
        self::assertRequest(
            $http,
            $route,
            'import_zone_key',
            'domain=example.com&key_data=a%20%2B%26%3D%25%0A%E9%9B%AA&key_type=ksk',
        );
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testPrivateInputAndReturnedKeyTextAreRedactedFromFailureTraces(string $route): void
    {
        [$client, $http] = self::client($route);
        $dns = $client->uapi('npr')->dnssec();
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (['import', 'export', 'records'] as $kind) {
                $payload = match ($kind) {
                    'import' => '{"success":false}',
                    'export' => '{"key_content":"dns-secret-marker","success":false}',
                    default => '{"example.com":{"keys":{"1":{"privatekey":"dns-secret-marker","active":false}}}}',
                };
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                try {
                    match ($kind) {
                        'import' => $dns->importZoneKey('example.com', 'dns-secret-marker', 'ksk'),
                        'export' => $dns->exportZoneKey('example.com', 1),
                        default => $dns->fetchDsRecords('example.com'),
                    };
                    self::fail('Malformed payload must fail.');
                } catch (PayloadMismatchException $error) {
                    self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $error->payload);
                    foreach ($error->getTrace() as $frame) {
                        if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                            continue;
                        }
                        $seen = [];
                        self::assertFalse(self::hasSecret($frame['args'] ?? [], 'dns-secret-marker', $seen));
                    }
                    self::assertStringNotContainsString('dns-secret-marker', $error->getTraceAsString());
                    self::assertStringNotContainsString('dns-secret-marker', $error->getMessage());
                    self::assertTrue($error->outcomeConfirmed);
                }
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(3, $http->getRequests());
    }

    public function testSelectorCachesPerAccountWithoutChangingMetadata(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($first->dnssec(), $first->dnssec());
        self::assertNotSame($first->dnssec(), $other->dnssec());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{}')));
        $first->dnssec()->fetchDsRecords('example.com');
        $meta = $first->responseMeta();
        $first->dnssec();
        self::assertSame($meta, $first->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $function) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function operationsOnly(): iterable
    {
        foreach (self::operations() as $function) {
            yield $function => [$function];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return list<string> */
    private static function operations(): array
    {
        return ['activate_zone_key', 'add_zone_key', 'deactivate_zone_key', 'disable_dnssec', 'enable_dnssec',
            'export_zone_dnskey', 'export_zone_key', 'fetch_ds_records', 'import_zone_key', 'remove_zone_key',
            'set_nsec3', 'unset_nsec3'];
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        $dns = $account->dnssec();
        return match ($function) {
            'activate_zone_key' => $dns->activateZoneKey('example.com', 12),
            'add_zone_key' => $dns->addZoneKey('example.com', 8, 'ksk', active: false, keySize: 1024),
            'deactivate_zone_key' => $dns->deactivateZoneKey('example.com', 12),
            'disable_dnssec' => $dns->disableDnssec('example.com'),
            'enable_dnssec' => $dns->enableDnssec('example.com', active: false, algoNum: 13, keySetup: 'simple',
                nsec3Iterations: 0, nsec3Narrow: false, nsec3OptOut: true, nsec3Salt: '-', useNsec3: true),
            'export_zone_dnskey' => $dns->exportZoneDnskey('example.com', 12),
            'export_zone_key' => $dns->exportZoneKey('example.com', 12),
            'fetch_ds_records' => $dns->fetchDsRecords('example.com'),
            'import_zone_key' => $dns->importZoneKey('example.com', 'synthetic key', 'ksk'),
            'remove_zone_key' => $dns->removeZoneKey('example.com', 12),
            'set_nsec3' => $dns->setNsec3('example.com', 0, false, true, '-'),
            default => $dns->unsetNsec3('example.com'),
        };
    }

    private static function parameters(string $function): string
    {
        return match ($function) {
            'activate_zone_key', 'deactivate_zone_key', 'export_zone_dnskey', 'export_zone_key', 'remove_zone_key'
                => 'domain=example.com&key_id=12',
            'add_zone_key' => 'domain=example.com&algo_num=8&key_type=ksk&active=0&key_size=1024',
            'enable_dnssec' => 'domain=example.com&active=0&algo_num=13&key_setup=simple&nsec3_iterations=0'
                . '&nsec3_narrow=0&nsec3_opt_out=1&nsec3_salt=-&use_nsec3=1',
            'import_zone_key' => 'domain=example.com&key_data=synthetic%20key&key_type=ksk',
            'set_nsec3' => 'domain=example.com&nsec3_iterations=0&nsec3_narrow=0&nsec3_opt_out=1&nsec3_salt=-',
            default => 'domain=example.com',
        };
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, string $secret, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $secret);
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (str_contains((string) $key, $secret) || self::hasSecret($item, $secret, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function assertRequest(MockClient $http, string $route, string $function, string $parameters): void
    {
        $post = !in_array($function, ['export_zone_dnskey', 'fetch_ds_records'], true);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DNSSEC&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/DNSSEC/' . $function;
        if (!$post) {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($post ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($post) {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"messages":null,"warnings":null,"metadata":{}}';
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
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
