<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Dns;
use HostingPal\WhmApiClient\Api\Whm\Spec\DnsSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Literal endpoint fixtures catch lost repeats, reencoded JSON, coercion and accidental retries. */
final class WhmDnsMassEditTest extends TestCase
{
    /** @param array<string, mixed> $arguments */
    #[DataProvider('requests')]
    public function testExactFormRequest(array $arguments, string $wire): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        self::assertSame([], self::invoke($whm->dns(), 'massEditDnsZone', $arguments));
        self::assertRequest($http, $wire);
        $metadata = $whm->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->whmResult());
        self::assertSame('mass_edit_dns_zone', $metadata->whm['command'] ?? null);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function requests(): iterable
    {
        yield 'all repeated fields and exact serialized bytes' => [
            ['serial' => 0, 'zone' => 'example.test', 'add' => ['', '{"data":["a\"%&č"]}'],
                'edit' => ['not JSON %20&=', 'not JSON %20&='], 'remove' => [3, 0, 3]],
            'serial=0&zone=example.test&add=&add=%7B%22data%22%3A%5B%22a%5C%22%25%26%C4%8D%22%5D%7D'
                . '&edit=not%20JSON%20%2520%26%3D&edit=not%20JSON%20%2520%26%3D&remove=3&remove=0&remove=3',
        ];
        yield 'omitted families still dispatch' => [
        ['serial' => 7, 'zone' => 'example.test'],
            'serial=7&zone=example.test'];
        yield 'null families are omitted' => [['serial' => 7, 'zone' => 'example.test',
            'add' => null, 'edit' => null, 'remove' => null], 'serial=7&zone=example.test'];
        yield 'add only' => [
        ['serial' => 7, 'zone' => 'example.test', 'add' => ['{}']],
            'serial=7&zone=example.test&add=%7B%7D'];
        yield 'edit only' => [
        ['serial' => 7, 'zone' => 'example.test', 'edit' => ['{}']],
            'serial=7&zone=example.test&edit=%7B%7D'];
        yield 'remove only' => [
        ['serial' => 7, 'zone' => 'example.test', 'remove' => [0]],
            'serial=7&zone=example.test&remove=0'];
        yield 'native negative integers retain scalar policy' => [['serial' => -1, 'zone' => 'zone%&č',
            'remove' => [-3]], 'serial=-1&zone=zone%25%26%C4%8D&remove=-3'];
    }

    /** @param array<array-key, mixed> $value */
    #[DataProvider('invalidLists')]
    public function testInvalidListsClearMetadataBeforeAnyNewSend(string $argument, array $value): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        $whm->dns()->massEditDnsZone(7, 'example.test');
        self::assertNotNull($whm->responseMeta());
        $arguments = ['serial' => 7, 'zone' => 'example.test', 'add' => ['dns-secret-marker'],
            'edit' => ['dns-secret-marker'], $argument => $value];
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                self::invoke($whm->dns(), 'massEditDnsZone', $arguments);
                self::fail('Invalid collection must fail before sending.');
            } catch (InvalidArgumentException $error) {
                self::assertContains($error->getMessage(), [
                    'Collection arguments require a nonempty sequential list.',
                    'Collection item does not match its declared strict type.',
                ]);
                self::assertStringNotContainsString('dns-secret-marker', $error->getMessage());
                $framesWithArguments = 0;
                foreach ($error->getTrace() as $frame) {
                    if (
                        !str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')
                        || str_starts_with($frame['class'], 'HostingPal\\WhmApiClient\\Tests\\')
                    ) {
                        continue;
                    }
                    if (($frame['args'] ?? []) !== []) {
                        $framesWithArguments++;
                    }
                    self::assertFalse(self::containsSecret($frame['args'] ?? []));
                }
                self::assertGreaterThan(0, $framesWithArguments);
                self::assertNull($whm->responseMeta());
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidLists(): iterable
    {
        foreach (['add', 'edit', 'remove'] as $argument) {
            $valid = $argument === 'remove' ? 1 : 'dns-secret-marker';
            $cases = [[], [1 => $valid], [$valid, 2 => $valid], ['private-key' => $valid]];
            $badItems = [true, false, 1.5, null, [], ['nested']];
            $badItems[] = $argument === 'remove' ? '12' : 12;
            foreach ($badItems as $bad) {
                $cases[] = [$bad];
                $cases[] = [$valid, $bad];
            }
            foreach ($cases as $i => $case) {
                yield $argument . ' invalid ' . $i => [$argument, $case];
            }
        }
    }

    #[DataProvider('nativeTypeErrors')]
    public function testNativeTypeErrorsPreservePreviousMetadata(string $argument, mixed $value): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        $whm->dns()->massEditDnsZone(7, 'example.test');
        $metadata = $whm->responseMeta();
        self::assertNotNull($metadata);
        $arguments = ['serial' => 7, 'zone' => 'example.test', $argument => $value];
        try {
            self::invoke($whm->dns(), 'massEditDnsZone', $arguments);
            self::fail('Wrong native argument type must fail.');
        } catch (TypeError) {
            self::assertSame($metadata, $whm->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function nativeTypeErrors(): iterable
    {
        foreach (['add', 'edit', 'remove'] as $argument) {
            foreach (['single', 1, false, 1.5] as $i => $value) {
                yield $argument . $i => [$argument, $value];
            }
        }
        yield 'serial numeric string' => ['serial', '7'];
        yield 'zone integer' => ['zone', 7];
    }

    /** @param array<string, int> $expected */
    #[DataProvider('successfulPayloads')]
    public function testOptionalSerialAndExistingObjectPolicy(string $payload, array $expected): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, $whm->dns()->massEditDnsZone(0, 'example.test'));
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertRequest($http, 'serial=0&zone=example.test');
    }

    /** @return iterable<string, array{string, array<string, int>}> */
    public static function successfulPayloads(): iterable
    {
        yield 'empty object' => ['{}', []];
        yield 'legacy empty array compatibility' => ['[]', []];
        yield 'zero serial' => ['{"new_serial":0}', ['new_serial' => 0]];
        yield 'large serial' => ['{"new_serial":202001010100}', ['new_serial' => 202001010100]];
        yield 'unknown fields follow existing filtering' => ['{"new_serial":12,"future":{"x":true}}',
            ['new_serial' => 12]];
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedSuccessConfirmsOutcomeAndDoesNotRefreshSerial(?string $payload): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $whm->dns()->massEditDnsZone(7, 'example.test', remove: [0]);
            self::fail('Malformed success must fail payload validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertRequest($http, 'serial=7&zone=example.test&remove=0');
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (
            [null, 'null', '[1]', 'false', '1', '"text"', '{"new_serial":"12"}',
            '{"new_serial":false}', '{"new_serial":null}', '{"new_serial":1.5}',
            '{"new_serial":[]}', '{"new_serial":{}}'] as $i => $payload
        ) {
            yield 'invalid payload ' . $i => [$payload];
        }
    }

    public function testSerialConflictKeepsMetadataAndDoesNotRetry(): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope(null, 0, 'Serial does not match current zone')));
        try {
            $whm->dns()->massEditDnsZone(7, 'example.test', remove: [0]);
            self::fail('Failed serial outcome must throw.');
        } catch (ApiErrorException $error) {
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertRequest($http, 'serial=7&zone=example.test&remove=0');
    }

    public function testHttpAndTransportFailuresMakeNoSecondRequest(): void
    {
        foreach (['http', 'transport'] as $kind) {
            [$client, $http] = self::client();
            $whm = $client->whm();
            $http->addResponse(new Response(200, [], self::envelope('{}')));
            $whm->dns()->massEditDnsZone(7, 'example.test');
            if ($kind === 'http') {
                $http->addResponse(new Response(503, [], 'unavailable'));
            } else {
                $http->addException(new class extends RuntimeException implements NetworkExceptionInterface {
                    public function getRequest(): RequestInterface
                    {
                        return new \Nyholm\Psr7\Request('POST', 'https://api.example.test');
                    }
                });
            }
            try {
                $whm->dns()->massEditDnsZone(7, 'example.test', remove: [0]);
                self::fail('Failed attempt must throw.');
            } catch (HttpException | TransportException $error) {
                self::assertInstanceOf($kind === 'http' ? HttpException::class : TransportException::class, $error);
                self::assertNull($whm->responseMeta());
            }
            self::assertCount(2, $http->getRequests());
        }
    }

    public function testPublicSignatureAndCollectionContract(): void
    {
        $method = new ReflectionMethod(Dns::class, 'massEditDnsZone');
        self::assertSame('array', (string) $method->getReturnType());
        self::assertSame(['serial', 'zone', 'add', 'edit', 'remove'], array_map(
            static fn ($parameter) => $parameter->getName(),
            $method->getParameters(),
        ));
        foreach ($method->getParameters() as $i => $parameter) {
            self::assertSame(['int', 'string', '?array', '?array', '?array'][$i], (string) $parameter->getType());
            self::assertSame($i > 1, $parameter->isDefaultValueAvailable());
            if ($i > 1) {
                self::assertNull($parameter->getDefaultValue());
            }
            self::assertCount(
                $i > 1 ? 1 : 0,
                $parameter->getAttributes(SensitiveParameter::class),
            );
        }
        $doc = $method->getDocComment();
        self::assertIsString($doc);
        self::assertStringContainsString('@param non-empty-list<string>|null $add', $doc);
        self::assertStringContainsString('@param non-empty-list<string>|null $edit', $doc);
        self::assertStringContainsString('@param non-empty-list<int>|null $remove', $doc);
        self::assertSame('flag', DnsSpec::MASS_EDIT_DNS_ZONE['outcome']);
        foreach (['add' => 'string', 'edit' => 'string', 'remove' => 'int'] as $name => $type) {
            self::assertSame(['name' => $name, 'encoding' => 'collection', 'collection' => [
                'kind' => 'repeat', 'item_type' => $type, 'allow_scalar' => false, 'empty' => 'reject',
            ]], DnsSpec::MASS_EDIT_DNS_ZONE['params'][$name]);
        }
    }

    /** @param array<string, mixed> $arguments */
    private static function invoke(Dns $dns, string $method, array $arguments): mixed
    {
        return $dns->{$method}(...$arguments);
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

    private static function assertRequest(MockClient $http, string $wire): void
    {
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/mass_edit_dns_zone?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame($wire, (string) $request->getBody());
    }

    private static function envelope(?string $payload, int $result = 1, string $reason = 'OK'): string
    {
        return '{"metadata":{"command":"mass_edit_dns_zone","reason":"' . $reason . '","result":' . $result
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
