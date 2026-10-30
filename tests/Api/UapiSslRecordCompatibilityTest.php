<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independent field/record fixtures transcribed from the pinned SSL contracts. */
final class UapiSslRecordCompatibilityTest extends TestCase
{
    /** @param array<string,mixed> $arguments
     * @param array<mixed> $payload
     * @param array<mixed> $expected
     */
    #[DataProvider('acceptedRecords')]
    public function testRecordFormsNormalizeWithoutChangingThePublicTypes(
        string $route,
        string $method,
        array $arguments,
        array $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route, $payload);
        try {
            $actual = $client->uapi('npr')->ssl()->{$method}(...$arguments);
        } catch (PayloadMismatchException) {
            self::fail('A reviewed native/quoted SSL record form must retain its precise public type.');
        }
        self::assertSame($expected, $actual);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string,array{string,string,array<string,mixed>,array<mixed>,array<mixed>}> */
    public static function acceptedRecords(): iterable
    {
        foreach (['wrapped', 'bare', 'bridged'] as $route) {
            foreach (self::contracts() as [$method, $arguments, $prefix, $fields]) {
                foreach ($fields as $field) {
                    $value = match ($field) {
                        'is_self_signed' => 1,
                        'issuer.organizationName' => 'Fixture Org',
                        default => 2048,
                    };
                    foreach (['native', 'quoted'] as $form) {
                        $wire = $form === 'quoted' ? (string) $value : $value;
                        yield "$route $method $field $form" => [$route, $method, $arguments,
                            self::record($prefix, $field, $wire), self::record($prefix, $field, $value)];
                    }
                    if ($field === 'is_self_signed') {
                        foreach ([0, '0'] as $zero) {
                            yield "$route $method exact zero " . get_debug_type($zero) => [$route, $method, $arguments,
                                self::record($prefix, $field, $zero), self::record($prefix, $field, 0)];
                        }
                    }
                    if (in_array($field, ['modulus_length', 'issuer.organizationName'], true)) {
                        yield "$route $method $field explicit null" => [$route, $method, $arguments,
                            self::record($prefix, $field, null), self::record($prefix, $field, null)];
                    }
                }
                yield "$route $method omitted fields" => [$route, $method, $arguments,
                    self::record($prefix, null, null), self::record($prefix, null, null)];
            }
        }
    }

    /** @param array<string,mixed> $arguments
     * @param array<mixed> $payload
     */
    #[DataProvider('rejectedRecords')]
    public function testRecordCorrectionsRejectUnreviewedFormats(
        string $route,
        string $method,
        array $arguments,
        array $payload,
    ): void {
        [$client, $http] = self::client($route, $payload);
        try {
            $client->uapi('npr')->ssl()->{$method}(...$arguments);
            self::fail('Invalid SSL record field must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string,array{string,string,array<string,mixed>,array<mixed>}> */
    public static function rejectedRecords(): iterable
    {
        foreach (['wrapped', 'bare', 'bridged'] as $route) {
            foreach (self::contracts() as [$method, $arguments, $prefix, $fields]) {
                foreach ($fields as $field) {
                    $values = match ($field) {
                        'issuer.organizationName' => [false, 123, []],
                        'is_self_signed' => ['2', '01', '1.0', true, null],
                        default => ['1.5', '1e3', ' 1', '01', '+1', (string) PHP_INT_MAX . '0', true],
                    };
                    if (!in_array($field, ['modulus_length', 'issuer.organizationName', 'is_self_signed'], true)) {
                        $values[] = null;
                    }
                    foreach ($values as $i => $value) {
                        yield "$route $method $field invalid $i"
                            => [$route, $method, $arguments, self::record($prefix, $field, $value)];
                    }
                }
            }
        }
    }

    /** Record locations and arguments are independent of generator/spec output.
     * @return list<array{string,array<string,mixed>,string,list<string>}>
     */
    private static function contracts(): array
    {
        $cert = ['created', 'modulus_length', 'not_after', 'not_before', 'is_self_signed', 'issuer.organizationName'];
        $key = ['created', 'modulus_length'];
        $generation = ['countryName' => 'US', 'domains' => 'npr.rs', 'keyId' => 'owned',
            'localityName' => 'City', 'organizationName' => 'Org', 'stateOrProvinceName' => 'State'];
        return [
            ['deleteCert', ['id' => 'owned'], 'list', $cert],
            ['deleteCsr', ['id' => 'owned'], 'list', ['created']],
            ['deleteKey', ['id' => 'owned'], 'list', $key],
            ['fetchCertInfo', ['id' => 'owned'], '', ['is_self_signed']],
            ['fetchCertificatesForFqdns', ['domains' => 'npr.rs'], 'list', $cert],
            ['findCertificatesForKey', ['id' => 'owned'], 'list', $cert],
            ['findCsrsForKey', ['id' => 'owned'], 'list', ['created']],
            ['generateCert', $generation, '', $cert],
            ['generateCsr', $generation, '', ['created']],
            ['generateKey', ['keytype' => 'rsa-2048'], '', $key],
            ['getCnName', ['domain' => 'npr.rs', 'service' => 'cpanel'], '', ['is_self_signed']],
            ['installedHost', [], 'certificate', array_slice($cert, 1)],
            ['installedHosts', [], 'list.certificate', array_slice($cert, 1)],
            ['listCerts', [], 'list', $cert],
            ['listCsrs', [], 'list', ['created']],
            ['listKeys', [], 'list', $key],
            ['showCert', ['id' => 'owned'], 'details', ['not_after', 'not_before', 'is_self_signed']],
            ['showCsr', ['id' => 'owned'], 'details', ['created']],
            ['showKey', ['id' => 'owned'], 'details', $key],
            ['uploadCert', ['crt' => 'fixture certificate'], 'list', $cert],
            ['uploadKey', ['key' => 'fixture private value'], 'list', $key],
        ];
    }

    /** @return array<mixed> */
    private static function record(string $prefix, ?string $field, mixed $value): array
    {
        $row = $field === null ? [] : [$field => $value];
        if ($prefix === 'certificate' || $prefix === 'list.certificate') {
            $row = ['certificate' => $row];
        } elseif ($prefix === 'details') {
            $row = ['details' => $row];
        }
        return str_starts_with($prefix, 'list') ? [$row] : $row;
    }

    /** @param array<mixed> $payload
     * @return array{Client,MockClient}
     */
    private static function client(string $route, array $payload): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $wire = $payload === [] ? (object) [] : $payload;
        if (is_array($wire)) {
            foreach ($wire as &$row) {
                if ($row === []) {
                    $row = (object) [];
                } elseif (is_array($row) && ($row['certificate'] ?? null) === []) {
                    $row['certificate'] = (object) [];
                }
            }
            unset($row);
        }
        $result = ['status' => 1, 'data' => $wire];
        $body = match ($route) {
            'wrapped' => ['result' => $result],
            'bare' => $result,
            default => ['metadata' => ['result' => 1], 'data' => ['uapi' => $result]],
        };
        // Empty record objects must remain objects on the wire, separately from lists.
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        if ($payload === []) {
            $json = str_replace('"data":[]', '"data":{}', $json);
        }
        $http->addResponse(new Response(200, [], $json));
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridged' ? 'whm' : 'cpanel',
            'username' => $route === 'bridged' ? 'root' : 'npr', 'token' => 'fixture'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$client, $http];
    }
}
