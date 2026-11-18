<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Dns;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DnsSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use TypeError;

/**
 * Literal fixtures for the two UAPI DNS zone writes on direct UAPI and the WHM bridge.
 *
 * The wire strings and payloads are written out here, independently of the generator, so a lost repeated key, a
 * re-encoded record, a reordered field, a coerced serial or a second request each fail a test.
 */
final class UapiDnsZoneEditTest extends TestCase
{
    private const ROUTES = ['direct', 'bridge'];

    /**
     * @param non-empty-list<string>|null $add
     * @param non-empty-list<string>|null $edit
     * @param non-empty-list<int>|null $remove
     */
    #[DataProvider('massEditRequests')]
    public function testMassEditSendsOneFormPostWithExactFields(
        string $route,
        string $wire,
        int $serial,
        ?array $add = null,
        ?array $edit = null,
        ?array $remove = null,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{"new_serial":"2026111806"}')));
        self::assertSame(
            ['new_serial' => 2026111806],
            $account->dns()->massEditZone($serial, 'example.test', $add, $edit, $remove),
        );
        self::assertRequest($http, $route, 'mass_edit_zone', $wire);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /**
     * @return iterable<string, array{
     *     string, string, int, 3?: non-empty-list<string>|null, 4?: non-empty-list<string>|null,
     *     5?: non-empty-list<int>|null,
     * }>
     */
    public static function massEditRequests(): iterable
    {
        foreach (self::ROUTES as $route) {
            yield "$route all families keep order, duplicates and bytes" => [
                $route,
                'serial=0&zone=example.test&add=&add=%7B%22data%22%3A%5B%22a%5C%22%25%26%C4%8D%22%5D%7D'
                    . '&edit=not%20JSON%20%2520%26%3D&edit=not%20JSON%20%2520%26%3D&remove=3&remove=0&remove=3',
                0,
                ['', '{"data":["a\"%&č"]}'],
                ['not JSON %20&=', 'not JSON %20&='],
                [3, 0, 3],
            ];
            yield "$route defaulted families still dispatch" => [$route, 'serial=7&zone=example.test', 7];
            yield "$route null families are omitted" => [$route, 'serial=7&zone=example.test', 7, null, null, null];
            yield "$route singleton lists repeat once" => [
                $route,
                'serial=7&zone=example.test&add=%7B%7D&edit=%7B%7D&remove=0',
                7,
                ['{}'],
                ['{}'],
                [0],
            ];
            yield "$route one family alone" => [$route, 'serial=7&zone=example.test&remove=0', 7, null, null, [0]];
        }
    }

    /** @param array<string, int> $expected */
    #[DataProvider('massEditPayloads')]
    public function testMassEditNormalizesOnlyCanonicalSerialStrings(
        string $route,
        string $payload,
        array $expected,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $account->dns()->massEditZone(1, 'example.test', remove: [4]));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<string, int>}> */
    public static function massEditPayloads(): iterable
    {
        $cases = [
            'observed digit string' => ['{"new_serial":"2026111806"}', ['new_serial' => 2026111806]],
            'pinned native integer' => ['{"new_serial":2021031903}', ['new_serial' => 2021031903]],
            'zero digit string' => ['{"new_serial":"0"}', ['new_serial' => 0]],
            'largest integer string' => ['{"new_serial":"' . PHP_INT_MAX . '"}', ['new_serial' => PHP_INT_MAX]],
            'omitted serial stays absent' => ['{}', []],
            'empty array object compatibility' => ['[]', []],
            'unknown sibling is filtered' => ['{"new_serial":"12","future":{"x":true}}', ['new_serial' => 12]],
        ];
        foreach (self::ROUTES as $route) {
            foreach ($cases as $name => [$payload, $expected]) {
                yield "$route $name" => [$route, $payload, $expected];
            }
        }
    }

    #[DataProvider('massEditBadPayloads')]
    public function testMassEditMismatchAfterSuccessIsConfirmedAndNotRetried(string $route, ?string $payload): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $account->dns()->massEditZone(1, 'example.test', remove: [4]);
            self::fail('A malformed success payload must not be returned.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function massEditBadPayloads(): iterable
    {
        $payloads = [null, 'null', '[1]', '"2026111806"', '{"new_serial":null}', '{"new_serial":1.5}',
            '{"new_serial":true}', '{"new_serial":[]}', '{"new_serial":""}', '{"new_serial":"012"}',
            '{"new_serial":"+12"}', '{"new_serial":" 12"}', '{"new_serial":"12.0"}', '{"new_serial":"1e3"}',
            '{"new_serial":"' . PHP_INT_MAX . '0"}'];
        foreach (self::ROUTES as $route) {
            foreach ($payloads as $i => $payload) {
                yield "$route bad payload $i" => [$route, $payload];
            }
        }
    }

    /** @param string|non-empty-list<string> $domain */
    #[DataProvider('swapRequests')]
    public function testSwapSendsOneFormPostWithRepeatedDomains(
        string $route,
        string|array $domain,
        ?string $sourceIp,
        ?string $ftpIp,
        string $wire,
    ): void {
        [$account, $http] = self::account($route);
        $rows = '[{"zone_name":"example.test","record_name":"example.test","record_type":"A",'
            . '"old_value":"192.0.2.1","new_value":"192.0.2.9"},{"record_type":"TXT"},{}]';
        $http->addResponse(new Response(200, [], self::envelope($route, $rows)));
        self::assertSame(
            [
                ['new_value' => '192.0.2.9', 'old_value' => '192.0.2.1', 'record_name' => 'example.test',
                    'record_type' => 'A', 'zone_name' => 'example.test'],
                ['record_type' => 'TXT'],
                [],
            ],
            $account->dns()->swapIpInZones($domain, '192.0.2.9', $sourceIp, $ftpIp),
        );
        self::assertRequest($http, $route, 'swap_ip_in_zones', $wire);
    }

    /** @return iterable<string, array{string, string|non-empty-list<string>, ?string, ?string, string}> */
    public static function swapRequests(): iterable
    {
        $cases = [
            'scalar domain with omitted optionals' => ['example.test', null, null,
                'domain=example.test&dest_ip=192.0.2.9'],
            'singleton list repeats once' => [
                ['example.test'],
                null,
                null,
                'domain=example.test&dest_ip=192.0.2.9',
            ],
            'list keeps order and duplicates' => [
                ['b.example.test', 'a.example.test', 'b.example.test'],
                '192.0.2.1',
                '192.0.2.7',
                'domain=b.example.test&domain=a.example.test&domain=b.example.test'
                    . '&dest_ip=192.0.2.9&source_ip=192.0.2.1&ftp_ip=192.0.2.7',
            ],
            'empty strings are sent and bytes are encoded once' => ['a%20b&č', '', '',
                'domain=a%2520b%26%C4%8D&dest_ip=192.0.2.9&source_ip=&ftp_ip='],
        ];
        foreach (self::ROUTES as $route) {
            foreach ($cases as $name => [$domain, $sourceIp, $ftpIp, $wire]) {
                yield "$route $name" => [$route, $domain, $sourceIp, $ftpIp, $wire];
            }
        }
    }

    #[DataProvider('routes')]
    public function testSwapReturnsAnEmptyListWhenNothingChanged(string $route): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        self::assertSame([], $account->dns()->swapIpInZones('example.test', '192.0.2.9'));
    }

    #[DataProvider('swapBadPayloads')]
    public function testSwapMismatchAfterSuccessIsConfirmedAndNotRetried(string $route, ?string $payload): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $account->dns()->swapIpInZones('example.test', '192.0.2.9');
            self::fail('A malformed success payload must not be returned.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function swapBadPayloads(): iterable
    {
        $payloads = [null, 'null', '"text"', '{"zone_name":"example.test"}', '[1]', '["example.test"]', '[null]',
            '[{"zone_name":null}]', '[{"new_value":1}]', '[{"record_type":["A"]}]', '[{"old_value":false}]'];
        foreach (self::ROUTES as $route) {
            foreach ($payloads as $i => $payload) {
                yield "$route bad payload $i" => [$route, $payload];
            }
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidCollections')]
    public function testInvalidCollectionsFailBeforeIoAndClearMetadata(
        string $route,
        string $method,
        array $arguments,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->dns()->swapIpInZones('example.test', '192.0.2.9');
        self::assertNotNull($account->responseMeta());
        try {
            $account->dns()->{$method}(...$arguments);
            self::fail('An invalid collection must fail before sending.');
        } catch (InvalidArgumentException $error) {
            self::assertContains($error->getMessage(), [
                'Collection arguments require a nonempty sequential list.',
                'Collection item does not match its declared strict type.',
            ]);
        }
        self::assertNull($account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function invalidCollections(): iterable
    {
        $bad = ['empty' => [], 'sparse' => [1 => 'x'], 'keyed' => ['k' => 'x'], 'nested' => [['x']],
            'null item' => [null], 'bool item' => [true], 'float item' => [1.5]];
        foreach (self::ROUTES as $route) {
            foreach ($bad as $name => $list) {
                foreach (['add', 'edit', 'remove'] as $family) {
                    yield "$route mass edit $family $name" => [$route, 'massEditZone',
                        ['serial' => 1, 'zone' => 'example.test', $family => $list]];
                }
                yield "$route swap domain $name" => [$route, 'swapIpInZones',
                    ['domain' => $list, 'destIp' => '192.0.2.9']];
            }
            yield "$route mass edit add integer item" => [$route, 'massEditZone',
                ['serial' => 1, 'zone' => 'example.test', 'add' => [12]]];
            yield "$route mass edit remove string item" => [$route, 'massEditZone',
                ['serial' => 1, 'zone' => 'example.test', 'remove' => ['12']]];
            yield "$route swap domain integer item" => [$route, 'swapIpInZones',
                ['domain' => ['example.test', 12], 'destIp' => '192.0.2.9']];
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('nativeTypeErrors')]
    public function testNativeTypeErrorsKeepThePreviousMetadata(string $method, array $arguments): void
    {
        [$account, $http] = self::account('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '[]')));
        $account->dns()->swapIpInZones('example.test', '192.0.2.9');
        $metadata = $account->responseMeta();
        try {
            $account->dns()->{$method}(...$arguments);
            self::fail('A wrong native argument type must fail.');
        } catch (TypeError) {
            self::assertSame($metadata, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function nativeTypeErrors(): iterable
    {
        yield 'serial numeric string' => ['massEditZone', ['serial' => '7', 'zone' => 'example.test']];
        yield 'zone integer' => ['massEditZone', ['serial' => 7, 'zone' => 7]];
        yield 'add scalar string' => ['massEditZone', ['serial' => 7, 'zone' => 'example.test', 'add' => '{}']];
        yield 'remove scalar integer' => ['massEditZone', ['serial' => 7, 'zone' => 'example.test', 'remove' => 3]];
        yield 'domain integer' => ['swapIpInZones', ['domain' => 7, 'destIp' => '192.0.2.9']];
        yield 'domain null' => ['swapIpInZones', ['domain' => null, 'destIp' => '192.0.2.9']];
        yield 'destination integer' => ['swapIpInZones', ['domain' => 'example.test', 'destIp' => 7]];
        yield 'source integer' => ['swapIpInZones',
            ['domain' => 'example.test', 'destIp' => '192.0.2.9', 'sourceIp' => 7]];
    }

    #[DataProvider('routes')]
    public function testStaleSerialRaisesTheApiErrorAndHidesItsFailurePayload(string $route): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"type":"Stale","detail":null}',
            ['The given serial number (1) does not match the DNS zone’s serial number (2).'],
        )));
        try {
            $account->dns()->massEditZone(1, 'example.test', remove: [4]);
            self::fail('Status zero must raise before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(
                ['The given serial number (1) does not match the DNS zone’s serial number (2).'],
                $error->errors(),
            );
            self::assertArrayNotHasKey('data', $error->metadata->uapi ?? []);
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testObservedBridgeSwapFailureIsAnOrdinaryApiError(): void
    {
        [$account, $http] = self::account('bridge');
        $reason = "Undefined subroutine &Cpanel::AdminBin::Call::call called at /usr/local/cpanel/Cpanel/API/DNS.pm.\n";
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'null', [$reason])));
        try {
            $account->dns()->swapIpInZones(['example.test', 'sub.example.test'], '192.0.2.9');
            self::fail('The bridged failure must surface.');
        } catch (ApiErrorException $error) {
            self::assertSame([$reason], $error->errors());
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame(0, $error->metadata->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testOuterBridgeFailureWinsOverUapiSuccess(): void
    {
        [$account, $http] = self::account('bridge');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Outer failure"},'
            . '"data":{"uapi":{"status":1,"data":{"new_serial":"5"}}}}'));
        try {
            $account->dns()->massEditZone(1, 'example.test', remove: [4]);
            self::fail('The outer WHM failure must win.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(['Outer failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testHttpAndTransportFailuresClearMetadataWithoutASecondRequest(string $route): void
    {
        foreach (['http', 'transport'] as $failure) {
            foreach (['massEditZone', 'swapIpInZones'] as $method) {
                [$account, $http] = self::account($route);
                $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
                $account->dns()->swapIpInZones('example.test', '192.0.2.9');
                if ($failure === 'http') {
                    $http->addResponse(new Response(503, [], 'unavailable'));
                } else {
                    $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
                    });
                }
                try {
                    $method === 'massEditZone'
                        ? $account->dns()->massEditZone(1, 'example.test', remove: [4])
                        : $account->dns()->swapIpInZones('example.test', '192.0.2.9');
                    self::fail('A failed request must throw.');
                } catch (ApiException $error) {
                    self::assertSame($failure === 'transport', $error instanceof TransportException);
                    self::assertNull($account->responseMeta());
                }
                self::assertCount(2, $http->getRequests());
            }
        }
    }

    public function testPublicSignaturesAndSpecs(): void
    {
        $edit = new ReflectionMethod(Dns::class, 'massEditZone');
        self::assertSame('array', (string) $edit->getReturnType());
        self::assertSame(
            ['serial' => 'int', 'zone' => 'string', 'add' => '?array', 'edit' => '?array', 'remove' => '?array'],
            self::parameterTypes($edit),
        );
        self::assertSame(['add', 'edit', 'remove'], self::sensitiveParameters($edit));
        $doc = (string) $edit->getDocComment();
        self::assertStringContainsString('@param non-empty-list<string>|null $add', $doc);
        self::assertStringContainsString('@param non-empty-list<string>|null $edit', $doc);
        self::assertStringContainsString('@param non-empty-list<int>|null $remove', $doc);
        self::assertStringContainsString('@return MassEditZoneResult', $doc);
        self::assertSame(
            ['type' => 'object', 'fields' => ['new_serial' => ['type' => 'int', 'accept' => ['numeric-string']]]],
            DnsSpec::MASS_EDIT_ZONE['returns'],
        );
        self::assertSame('POST', DnsSpec::MASS_EDIT_ZONE['method']);
        self::assertSame('flag', DnsSpec::MASS_EDIT_ZONE['outcome']);
        self::assertSame(['serial', 'zone', 'add', 'edit', 'remove'], array_keys(DnsSpec::MASS_EDIT_ZONE['params']));

        $swap = new ReflectionMethod(Dns::class, 'swapIpInZones');
        self::assertSame('array', (string) $swap->getReturnType());
        self::assertSame(
            ['domain' => 'array|string', 'destIp' => 'string', 'sourceIp' => '?string', 'ftpIp' => '?string'],
            self::parameterTypes($swap),
        );
        self::assertSame(['domain'], self::sensitiveParameters($swap));
        self::assertStringContainsString(
            '@param string|non-empty-list<string> $domain',
            (string) $swap->getDocComment(),
        );
        self::assertSame('POST', DnsSpec::SWAP_IP_IN_ZONES['method']);
        self::assertSame('flag', DnsSpec::SWAP_IP_IN_ZONES['outcome']);
        self::assertSame(
            ['domain', 'dest_ip', 'source_ip', 'ftp_ip'],
            array_column(DnsSpec::SWAP_IP_IN_ZONES['params'], 'name'),
        );
        self::assertSame(
            ['kind' => 'repeat', 'item_type' => 'string', 'allow_scalar' => true, 'empty' => 'reject'],
            DnsSpec::SWAP_IP_IN_ZONES['params']['domain']['collection'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (self::ROUTES as $route) {
            yield $route => [$route];
        }
    }

    /** @return array<string, string> */
    private static function parameterTypes(ReflectionMethod $method): array
    {
        $types = [];
        foreach ($method->getParameters() as $parameter) {
            $types[$parameter->getName()] = (string) $parameter->getType();
        }

        return $types;
    }

    /** @return list<string> */
    private static function sensitiveParameters(ReflectionMethod $method): array
    {
        $names = [];
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }

    private static function assertRequest(MockClient $http, string $route, string $function, string $wire): void
    {
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=DNS&cpanel.function=' . $function
                : 'https://api.example.test:2083/execute/DNS/' . $function,
            (string) $request->getUri(),
        );
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame($wire, (string) $request->getBody());
    }

    /** @param list<string>|null $errors A non-null list makes the UAPI status 0. */
    private static function envelope(string $route, ?string $payload, ?array $errors = null): string
    {
        $result = '{"status":' . ($errors === null ? 1 : 0) . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            . ',"warnings":null,"messages":null,"metadata":{}}';

        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{\HostingPal\WhmApiClient\UapiClient, MockClient} */
    private static function account(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );

        return [$client->uapi('npr'), $http];
    }
}
