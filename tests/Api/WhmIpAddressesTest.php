<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\IpAddresses;
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

/** Independent literals reviewed against the pinned WHM IP Address Management source. */
final class WhmIpAddressesTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<mixed>, string, string}> */
    public static function requests(): iterable
    {
        yield 'add range and comma exclusions' => [
            'addIps', 'addips', ['192.0.2.16/28', '255.255.255.240', '192.0.2.17,192.0.2.18'], 'POST',
            'ips=192.0.2.16%2F28&netmask=255.255.255.240&excludes=192.0.2.17%2C192.0.2.18',
        ];
        yield 'add retains omitted excludes' => ['addIps', 'addips', ['', ''], 'POST', 'ips=&netmask='];
        yield 'add empty excludes' => ['addIps', 'addips', ['ip', 'mask', ''], 'POST', 'ips=ip&netmask=mask&excludes='];
        yield 'delete explicit false and empty alias' => [
            'deleteIp', 'delip', ['192.0.2.10', '', false], 'POST', 'ip=192.0.2.10&ethernetdev=&skipifshutdown=0',
        ];
        yield 'delete true' => [
            'deleteIp', 'delip', ['192.0.2.10', 'eth0:1', true], 'POST',
            'ip=192.0.2.10&ethernetdev=eth0%3A1&skipifshutdown=1',
        ];
        yield 'delete optional nulls omitted' => [
            'deleteIp', 'delip', ['192.0.2.10', null, null], 'POST', 'ip=192.0.2.10',
        ];
        yield 'public lookup' => ['getPublicIp', 'get_public_ip', ['192.0.2.10'], 'GET', 'ip=192.0.2.10'];
        yield 'shared default user' => ['getSharedIp', 'get_shared_ip', [], 'GET', ''];
        yield 'shared explicit user' => ['getSharedIp', 'get_shared_ip', ['reseller'], 'GET', 'user=reseller'];
        yield 'shared empty user preserved' => ['getSharedIp', 'get_shared_ip', [''], 'GET', 'user='];
        yield 'disable account list' => [
            'disableIpv6Account', 'ipv6_disable_account', ['one,two'], 'POST', 'user=one%2Ctwo',
        ];
        yield 'enable shared account list' => [
            'enableIpv6Account', 'ipv6_enable_account', ['SHARED', 'one,two'], 'POST', 'range=SHARED&user=one%2Ctwo',
        ];
        yield 'add IPv6 range reserved and empty note' => [
            'addIpv6Range', 'ipv6_range_add', ['Block +&', '2001:db8::/64', false, ''], 'POST',
            'name=Block%20%2B%26&range=2001%3Adb8%3A%3A%2F64&enabled=0&note=',
        ];
        yield 'add IPv6 range available' => [
            'addIpv6Range', 'ipv6_range_add', ['Block', '2001:db8::/64', true, 'a +&=%/雪'], 'POST',
            'name=Block&range=2001%3Adb8%3A%3A%2F64&enabled=1&note=a%20%2B%26%3D%25%2F%E9%9B%AA',
        ];
        yield 'add IPv6 range defaults omitted' => [
            'addIpv6Range', 'ipv6_range_add', ['Block', '2001:db8::/64'], 'POST',
            'name=Block&range=2001%3Adb8%3A%3A%2F64',
        ];
        yield 'edit empty values preserved' => [
            'editIpv6Range', 'ipv6_range_edit', ['Block', '', ''], 'POST', 'name=Block&new_name=&note=',
        ];
        yield 'edit null options omitted' => [
            'editIpv6Range', 'ipv6_range_edit', ['Block', null, null], 'POST', 'name=Block',
        ];
        yield 'range inventory' => ['listIpv6Ranges', 'ipv6_range_list', [], 'GET', ''];
        yield 'remove range' => ['removeIpv6Range', 'ipv6_range_remove', ['Block'], 'POST', 'name=Block'];
        yield 'range usage' => ['getIpv6RangeUsage', 'ipv6_range_usage', ['Block'], 'GET', 'name=Block'];
        yield 'IPv4 inventory' => ['listIps', 'listips', [], 'GET', ''];
        yield 'IPv6 inventory' => ['listIpv6s', 'listipv6s', [], 'GET', ''];
        yield 'NAT check uses POST despite lookup name' => [
            'checkNatIp', 'nat_checkip', ['192.0.2.10'], 'POST', 'ip=192.0.2.10',
        ];
        yield 'NAT pairing' => [
            'setNatPublicIp', 'nat_set_public_ip', ['192.0.2.10', '198.51.100.10'], 'POST',
            'local_ip=192.0.2.10&public_ip=198.51.100.10',
        ];
        yield 'site by domain' => [
            'setSiteIp', 'setsiteip', ['192.0.2.10', 'example.test'], 'POST', 'ip=192.0.2.10&domain=example.test',
        ];
        yield 'site by user' => [
            'setSiteIp', 'setsiteip', ['192.0.2.10', null, 'example'], 'POST', 'ip=192.0.2.10&user=example',
        ];
        yield 'both selectors forwarded without guessed exclusivity' => [
            'setSiteIp', 'setsiteip', ['192.0.2.10', '', 'example'], 'POST', 'ip=192.0.2.10&domain=&user=example',
        ];
        yield 'selector conditional requirement belongs to server' => [
            'setSiteIp', 'setsiteip', ['192.0.2.10'], 'POST', 'ip=192.0.2.10',
        ];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('requests')]
    public function testExactWireRequestsRetainDefaultsAndExplicitEmptyValues(
        string $method,
        string $operation,
        array $arguments,
        string $verb,
        string $parameters,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, self::hasPayload($method) ? '{}' : null)));
        $result = $client->whm()->ipAddresses()->{$method}(...$arguments);
        self::assertSame(self::hasPayload($method) ? [] : null, $result);
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
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame($operation, $client->whm()->responseMeta()?->whm['command'] ?? null);
    }

    /** @return iterable<string, array{string, string, list<mixed>, string}> */
    public static function validPayloads(): iterable
    {
        yield 'public address' => ['getPublicIp', 'get_public_ip', ['192.0.2.10'], '{"public_ip":"198.51.100.10"}'];
        yield 'missing shared address sentinel' => ['getSharedIp', 'get_shared_ip', [], '{"ip":"~"}'];
        yield 'empty shared address retained' => ['getSharedIp', 'get_shared_ip', [], '{"ip":""}'];
        yield 'disable count zero and empty failures' => [
            'disableIpv6Account', 'ipv6_disable_account', ['one'], '{"fail_cnt":0,"failures":[]}',
        ];
        yield 'disable failure reason has no pinned type' => [
            'disableIpv6Account', 'ipv6_disable_account', ['one,two'],
            '{"fail_cnt":2,"failures":[{"name":"one","reason":"failure"},{"reason":{"opaque":[0,null]}}]}',
        ];
        yield 'enable per account maps' => [
            'enableIpv6Account', 'ipv6_enable_account', ['Block', 'one,two'],
            '{"fail_cnt":1,"failures":{"one":"failed","two":""},"ipv6":{"three":"2001:db8::1"}}',
        ];
        yield 'enable empty maps' => [
            'enableIpv6Account', 'ipv6_enable_account', ['SHARED', 'one'], '{"failures":{},"ipv6":{}}',
        ];
        yield 'all range leaves' => [
            'listIpv6Ranges', 'ipv6_range_list', [],
            '{"range":[{"CIDR":"2001:db8::/64","enabled":0,"first":"2001:db8::",'
                . '"last":"2001:db8::ffff","mostrecent":"2001:db8::1","name":"Block","note":"",'
                . '"owner":"root","range_users":["one","two"],"reclaimed":["2001:db8::2"]}]}',
        ];
        yield 'range available flag one' => ['listIpv6Ranges', 'ipv6_range_list', [], '{"range":[{"enabled":1}]}'];
        yield 'empty range children and omitted row fields' => [
            'listIpv6Ranges', 'ipv6_range_list', [], '{"range":[{},{"range_users":[],"reclaimed":[]}]}',
        ];
        yield 'usage integer counts and zero' => [
            'getIpv6RangeUsage', 'ipv6_range_usage', ['Block'], '{"usage":{"forbidden":65536,"free":0,"used":20}}',
        ];
        yield 'usage omitted counts' => ['getIpv6RangeUsage', 'ipv6_range_usage', ['Block'], '{"usage":{}}'];
        yield 'all IPv4 leaves' => [
            'listIps', 'listips', [], '{"ip":[{"active":1,"dedicated":0,"if":"eth0:1","ip":"192.0.2.10",'
                . '"mainaddr":0,"netmask":"255.255.255.0","network":"192.0.2.0",'
                . '"public_ip":"198.51.100.10","removable":1,"used":0}]}',
        ];
        yield 'inverse IPv4 flags' => [
            'listIps', 'listips', [], '{"ip":[{"active":0,"dedicated":1,"mainaddr":1,"removable":0,"used":1}]}',
        ];
        yield 'IPv6 addresses retain textual representation' => [
            'listIpv6s', 'listipv6s', [], '{"ip":[{}, {"ip":"2001:0db8:0000:0000:0000:0000:0000:0001"}]}',
        ];
        yield 'NAT checked local address' => [
            'checkNatIp', 'nat_checkip', ['192.0.2.10'], '{"checked_ip":"192.0.2.10"}',
        ];
        foreach (self::payloadOperations() as [$method, $operation, $arguments]) {
            yield "$method omitted fields" => [$method, $operation, $arguments, '{}'];
        }
        foreach (
            [
            ['listIps', 'listips', 'ip'], ['listIpv6s', 'listipv6s', 'ip'],
            ['listIpv6Ranges', 'ipv6_range_list', 'range']] as [$method, $operation, $key]
        ) {
            yield "$method empty list" => [$method, $operation, [], '{"' . $key . '":[]}'];
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('validPayloads')]
    public function testPinnedPopulatedAndOptionalPayloadsRemainUnchanged(
        string $method,
        string $operation,
        array $arguments,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->ipAddresses()->{$method}(...$arguments),
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::payloadOperations() as [$method, $operation, $arguments]) {
            foreach ([null, 'null', 'false', '1', '1.5', '"text"', '[{}]'] as $index => $bad) {
                yield "$method invalid root $index" => [$method, $operation, $arguments, $bad];
            }
        }
        $fields = [
            ['getPublicIp', 'get_public_ip', ['192.0.2.10'], 'public_ip', 'string'],
            ['getSharedIp', 'get_shared_ip', [], 'ip', 'string'],
            ['disableIpv6Account', 'ipv6_disable_account', ['one'], 'fail_cnt', 'int'],
            ['disableIpv6Account', 'ipv6_disable_account', ['one'], 'failures', 'list'],
            ['disableIpv6Account', 'ipv6_disable_account', ['one'], 'failures[].name', 'string'],
            ['enableIpv6Account', 'ipv6_enable_account', ['Block', 'one'], 'fail_cnt', 'int'],
            ['enableIpv6Account', 'ipv6_enable_account', ['Block', 'one'], 'failures', 'object'],
            ['enableIpv6Account', 'ipv6_enable_account', ['Block', 'one'], 'ipv6', 'object'],
            ['enableIpv6Account', 'ipv6_enable_account', ['Block', 'one'], 'failures.account', 'string'],
            ['enableIpv6Account', 'ipv6_enable_account', ['Block', 'one'], 'ipv6.account', 'string'],
            ['listIpv6Ranges', 'ipv6_range_list', [], 'range', 'list'],
            ['getIpv6RangeUsage', 'ipv6_range_usage', ['Block'], 'usage', 'object'],
            ['listIps', 'listips', [], 'ip', 'list'],
            ['listIpv6s', 'listipv6s', [], 'ip', 'list'],
            ['listIpv6s', 'listipv6s', [], 'ip[].ip', 'string'],
            ['checkNatIp', 'nat_checkip', ['192.0.2.10'], 'checked_ip', 'string'],
        ];
        foreach (
            ['CIDR', 'enabled', 'first', 'last', 'mostrecent', 'name', 'note', 'owner',
            'range_users', 'reclaimed'] as $key
        ) {
            $fields[] = ['listIpv6Ranges', 'ipv6_range_list', [], 'range[].' . $key,
                $key === 'enabled' ? 'flag' : (in_array($key, ['range_users', 'reclaimed'], true) ? 'list' : 'string')];
        }
        foreach (['forbidden', 'free', 'used'] as $key) {
            $fields[] = ['getIpv6RangeUsage', 'ipv6_range_usage', ['Block'], 'usage.' . $key, 'int'];
        }
        foreach (
            ['active', 'dedicated', 'if', 'ip', 'mainaddr', 'netmask', 'network',
            'public_ip', 'removable', 'used'] as $key
        ) {
            $fields[] = ['listIps', 'listips', [], 'ip[].' . $key,
                in_array($key, ['active', 'dedicated', 'mainaddr', 'removable', 'used'], true) ? 'flag' : 'string'];
        }
        foreach ($fields as [$method, $operation, $arguments, $path, $type]) {
            $badValues = match ($type) {
                'flag' => ['null', '"0"', '"1"', 'true', 'false', '1.0', '2', '-1', '[]', '{}'],
                'int' => ['null', '"0"', '"1"', 'true', 'false', '1.0', '[]', '{}'],
                'list' => ['null', '0', 'true', '"text"', '{}', '[null]', '[false]'],
                'object' => ['null', '0', 'true', '"text"', '[{}]'],
                default => ['null', '0', 'true', 'false', '[]', '{}'],
            };
            foreach ($badValues as $index => $bad) {
                yield "$method $path invalid $index" =>
                    [$method, $operation, $arguments, self::nestedPayload($path, $bad)];
            }
        }
        foreach (['range_users', 'reclaimed'] as $key) {
            yield "range $key integer item" => ['listIpv6Ranges', 'ipv6_range_list', [],
                '{"range":[{"' . $key . '":[1]}]}'];
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadKeepsNewConfirmedMetadataAndDoesNotRetry(
        string $method,
        string $operation,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('get_shared_ip', '{"ip":"~"}')));
        $whm->ipAddresses()->getSharedIp();
        $previous = $whm->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $whm->ipAddresses()->{$method}(...$arguments);
            self::fail('The pinned IP address payload contract must reject the malformed value.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($operation, $error->metadata->whm['command'] ?? null);
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>}> */
    public static function operationRequests(): iterable
    {
        foreach (self::requests() as $name => [$method, $operation, $arguments]) {
            yield $name => [$method, $operation, $arguments];
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('operationRequests')]
    public function testApiFailurePrecedesPayloadValidationAndDoesNotRetry(
        string $method,
        string $operation,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, 'false', 0)));
        try {
            $client->whm()->ipAddresses()->{$method}(...$arguments);
            self::fail('A failed WHM outcome must be reported.');
        } catch (ApiErrorException $error) {
            self::assertSame(['Synthetic failure'], $error->errors());
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testUnknownFieldsAreFilteredWithoutInventingAContract(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'listips',
            '{"future":true,"ip":[{"ip":"192.0.2.10","future":{"x":1}}]}',
        )));
        self::assertSame(['ip' => [['ip' => '192.0.2.10']]], $client->whm()->ipAddresses()->listIps());
    }

    public function testGroupIsCachedPerHandleWithoutDispatchOrMetadataChanges(): void
    {
        [$client, $http] = self::client();
        [$other] = self::client();
        $whm = $client->whm();
        self::assertSame($whm->ipAddresses(), $whm->ipAddresses());
        self::assertNotSame($whm->ipAddresses(), $other->whm()->ipAddresses());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('get_shared_ip', '{"ip":"~"}')));
        $whm->ipAddresses()->getSharedIp();
        $metadata = $whm->responseMeta();
        $whm->ipAddresses();
        self::assertSame($metadata, $whm->responseMeta());
        self::assertNull($other->whm()->responseMeta());
    }

    public function testNamedSignaturesPreserveReviewedOrderAndRequiredness(): void
    {
        $signatures = [
            'addIps' => [['ips', 'netmask', 'excludes'], 2],
            'deleteIp' => [['ip', 'ethernetDev', 'skipIfShutdown'], 1],
            'getPublicIp' => [['ip'], 1], 'getSharedIp' => [['user'], 0],
            'disableIpv6Account' => [['user'], 1], 'enableIpv6Account' => [['range', 'user'], 2],
            'addIpv6Range' => [['name', 'range', 'enabled', 'note'], 2],
            'editIpv6Range' => [['name', 'newName', 'note'], 1],
            'listIpv6Ranges' => [[], 0], 'removeIpv6Range' => [['name'], 1],
            'getIpv6RangeUsage' => [['name'], 1], 'listIps' => [[], 0], 'listIpv6s' => [[], 0],
            'checkNatIp' => [['ip'], 1], 'setNatPublicIp' => [['localIp', 'publicIp'], 2],
            'setSiteIp' => [['ip', 'domain', 'user'], 1],
        ];
        foreach ($signatures as $method => [$names, $required]) {
            $reflection = new ReflectionMethod(IpAddresses::class, $method);
            self::assertSame($required, $reflection->getNumberOfRequiredParameters());
            self::assertSame($names, array_map(
                static fn ($parameter): string => $parameter->getName(),
                $reflection->getParameters(),
            ));
            foreach ($reflection->getParameters() as $parameter) {
                self::assertSame([], $parameter->getAttributes(SensitiveParameter::class));
                if ($parameter->isOptional()) {
                    self::assertNull($parameter->getDefaultValue());
                }
            }
        }
    }

    /** @return list<array{string, string, list<mixed>}> */
    private static function payloadOperations(): array
    {
        return [
            ['getPublicIp', 'get_public_ip', ['192.0.2.10']], ['getSharedIp', 'get_shared_ip', []],
            ['disableIpv6Account', 'ipv6_disable_account', ['one']],
            ['enableIpv6Account', 'ipv6_enable_account', ['Block', 'one']],
            ['listIpv6Ranges', 'ipv6_range_list', []], ['getIpv6RangeUsage', 'ipv6_range_usage', ['Block']],
            ['listIps', 'listips', []], ['listIpv6s', 'listipv6s', []], ['checkNatIp', 'nat_checkip', ['192.0.2.10']],
        ];
    }

    private static function hasPayload(string $method): bool
    {
        return in_array($method, ['getPublicIp', 'getSharedIp', 'disableIpv6Account', 'enableIpv6Account',
            'listIpv6Ranges', 'getIpv6RangeUsage', 'listIps', 'listIpv6s', 'checkNatIp'], true);
    }

    private static function nestedPayload(string $path, string $value): string
    {
        foreach (array_reverse(explode('.', $path)) as $key) {
            if (str_ends_with($key, '[]')) {
                $value = '{"' . substr($key, 0, -2) . '":[' . $value . ']}';
            } else {
                $value = '{"' . $key . '":' . $value . '}';
            }
        }
        return $value;
    }

    private static function envelope(string $operation, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"'
            . ($result === 1 ? 'OK' : 'Synthetic failure') . '","result":' . $result . ',"version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
