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
use ReflectionMethod;
use SensitiveParameter;

/** Independent literal contracts from pinned sources and separately reviewed wire shapes. */
final class UapiGpgTest extends TestCase
{
    /** @param array<array-key, mixed>|null $expected */
    #[DataProvider('payloads')]
    public function testExactRequestsPreserveDocumentedPayloads(
        string $route,
        string $function,
        string $payload,
        ?array $expected,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        self::assertRequest($http, $route, $function);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>|null}> */
    public static function payloads(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::cases() as $function => $cases) {
                foreach ($cases as $index => [$payload, $expected]) {
                    yield "$route $function $index" => [$route, $function, $payload, $expected];
                }
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedOrAbsentPayloadDoesNotRetryAndRetainsFreshConfirmedMetadata(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, self::cases()[$function][0][0])));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Malformed or absent payload must fail after the API outcome was confirmed.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotNull($error->metadata);
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
        self::assertRequest($http, $route, $function);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::badCases() as $function => $cases) {
                foreach ($cases as $index => $payload) {
                    yield "$route $function $index" => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('operations')]
    public function testApiFailureIsCheckedBeforePayloadAndNeverRetried(string $route, string $function): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, self::cases()[$function][0][0])));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, 'false', 0)));
        try {
            self::invoke($account, $function);
            self::fail('UAPI status zero must remain an API error.');
        } catch (ApiErrorException $error) {
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
        }
        self::assertCount(2, $http->getRequests());
        if ($route === 'bridge') {
            $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Outer failure"},'
                . '"data":{"uapi":{"status":1,"data":{}}}}'));
            try {
                self::invoke($account, $function);
                self::fail('WHM failure must remain checked.');
            } catch (ApiErrorException $error) {
                self::assertSame(0, $error->metadata?->whmResult());
                self::assertSame($account->responseMeta(), $error->metadata);
            }
            self::assertCount(3, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (array_keys(self::cases()) as $function) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    #[DataProvider('routes')]
    public function testSelectorsCacheWithoutIoOrMetadataReset(string $route): void
    {
        [$account, $http, $client] = self::account($route);
        $other = $route === 'bridge' ? $client->uapi('other') : self::account($route)[0];
        self::assertSame($account->gpg(), $account->gpg());
        self::assertNotSame($account->gpg(), $other->gpg());
        self::assertCount(0, $http->getRequests());
        $function = array_key_first(self::cases());
        self::assertNotNull($function);
        $http->addResponse(new Response(200, [], self::envelope($route, self::cases()[$function][0][0])));
        self::invoke($account, $function);
        $meta = $account->responseMeta();
        $account->gpg();
        self::assertSame($meta, $account->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @param list<array<string, int|string>> $expected */
    #[DataProvider('populatedLists')]
    public function testPopulatedExpiringAndNonExpiringKeysNormalizeOnlyReviewedFields(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $result = self::invoke($account, $function);
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::fail('Reviewed populated key rows must pass their corrected contract.');
        }
        self::assertSame($expected, $result);
        self::assertRequest($http, $route, $function);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string, string, string, list<array<string, int|string>>}> */
    public static function populatedLists(): iterable
    {
        foreach (self::routes() as [$route]) {
            yield $route . ' public keys' => [$route, 'list_public_keys',
                '[{"algorithm":"RSA","bits":"2048","created":"1700000000","expires":"1800000000",'
                    . '"id":"FIXTURE-EXPIRING","type":"pub","user_id":"Fixture Expiring"},'
                    . '{"algorithm":"RSA","bits":"4096","created":"1700000001","expires":"",'
                    . '"id":"FIXTURE-NONEXPIRING","type":"pub","user_id":"Fixture Nonexpiring"}]',
                [
                    ['algorithm' => 'RSA', 'bits' => 2048, 'created' => '1700000000', 'expires' => '1800000000',
                        'id' => 'FIXTURE-EXPIRING', 'type' => 'pub', 'user_id' => 'Fixture Expiring'],
                    ['algorithm' => 'RSA', 'bits' => 4096, 'created' => '1700000001', 'expires' => '',
                        'id' => 'FIXTURE-NONEXPIRING', 'type' => 'pub', 'user_id' => 'Fixture Nonexpiring'],
                ]];
            yield $route . ' secret keys' => [$route, 'list_secret_keys',
                '[{"algorithm":"RSA","bits":"2048","created":"1700000000","expires":"1800000000",'
                    . '"id":"FIXTURE-EXPIRING","type":"sec","user_id":"Fixture Expiring"},'
                    . '{"algorithm":"RSA","bits":"4096","created":"1700000001","expires":"",'
                    . '"id":"FIXTURE-NONEXPIRING","type":"sec","user_id":"Fixture Nonexpiring"}]',
                [
                    ['algorithm' => 'RSA', 'bits' => 2048, 'created' => 1700000000, 'expires' => 1800000000,
                        'id' => 'FIXTURE-EXPIRING', 'type' => 'sec', 'user_id' => 'Fixture Expiring'],
                    ['algorithm' => 'RSA', 'bits' => 4096, 'created' => 1700000001, 'expires' => '',
                        'id' => 'FIXTURE-NONEXPIRING', 'type' => 'sec', 'user_id' => 'Fixture Nonexpiring'],
                ]];
        }
    }

    #[DataProvider('routes')]
    public function testGenerationPreservesExactEmptyStringAfterConfirmedOutcomeWithoutRetry(string $route): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '""')));
        try {
            $result = $account->gpg()->generateKey('fixture@example.test', 'Fixture', 'fixture-passphrase');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::fail('The observed empty-string generation payload must pass after API success.');
        }
        self::assertSame('', $result);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bare' => ['bare'];
        yield 'bridge' => ['bridge'];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function numericLeaves(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (
                [
                    'list_public_keys' => ['bits'],
                    'list_secret_keys' => ['bits', 'created', 'expires'],
                ] as $function => $fields
            ) {
                foreach ($fields as $field) {
                    yield "$route $function $field" => [$route, $function, $field];
                }
            }
        }
    }

    #[DataProvider('numericLeaves')]
    public function testReviewedIntegerLeavesPreserveNativeValuesAndNormalizeCanonicalBounds(
        string $route,
        string $function,
        string $field,
    ): void {
        foreach (
            [
                ['0', 0], ['"0"', 0], ['-1', -1], ['"-1"', -1],
                ['9223372036854775807', PHP_INT_MAX], ['"9223372036854775807"', PHP_INT_MAX],
                ['-9223372036854775808', PHP_INT_MIN], ['"-9223372036854775808"', PHP_INT_MIN],
            ] as [$wire, $expected]
        ) {
            [$account, $http] = self::account($route);
            $http->addResponse(new Response(200, [], self::envelope($route, '[{"' . $field . '":' . $wire . '}]')));
            self::assertSame([[$field => $expected]], self::invoke($account, $function));
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('numericLeaves')]
    public function testReviewedIntegerLeavesRejectNoncanonicalOverflowAndWrongTypes(
        string $route,
        string $function,
        string $field,
    ): void {
        $invalid = ['"00"', '"+1"', '"-0"', '" 1"', '"1 "', '"1.0"', '"1e3"', '"text"',
            '"9223372036854775808"', '"-9223372036854775809"', 'null', 'true', 'false', '1.5', '[]', '{}'];
        if ($function !== 'list_secret_keys' || $field !== 'expires') {
            $invalid[] = '""';
        }
        foreach ($invalid as $value) {
            [$account, $http] = self::account($route);
            $http->addResponse(new Response(200, [], self::envelope($route, '[{"' . $field . '":' . $value . '}]')));
            try {
                self::invoke($account, $function);
                self::fail('The reviewed integer leaf must reject this noncanonical or malformed value.');
            } catch (PayloadMismatchException $error) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(1, $error->metadata?->uapiStatus());
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('routes')]
    public function testGenerationEmptyStringAlternativeDoesNotAdmitOtherStringOrScalarResults(string $route): void
    {
        foreach (['" "', '"0"', '"created"', '0', 'false', 'true', '1.5', '[{}]', '[""]', '[1]', null] as $payload) {
            [$account, $http] = self::account($route);
            $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
            try {
                $account->gpg()->generateKey('fixture@example.test', 'Fixture', 'fixture-passphrase');
                self::fail('Only the exact empty string extends the pinned nullable map result.');
            } catch (PayloadMismatchException $error) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(1, $error->metadata?->uapiStatus());
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    private static function assertRequest(MockClient $http, string $route, string $function): void
    {
        [$method, $parameters] = self::wire($function);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=GPG&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/GPG/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
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

    /** @return array{UapiClient, MockClient, Client} */
    private static function account(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
        $account = $client->uapi('npr');
        return [$account, $http, $client];
    }

    /** @return array<string, list<array{string, array<array-key, mixed>|null}>> */
    private static function cases(): array
    {
        $opaque = [
        ['null', null], ['{}', []], ['[]', []],
            ['{"future":{"zero":0,"null":null,"list":[],"flag":false}}',
                ['future' => ['zero' => 0, 'null' => null, 'list' => [], 'flag' => false]]]];
        return [
            'delete_keypair' => $opaque,
            'generate_key' => $opaque,
            'export_public_key' => [
        ['{}', []], ['[]', []],
                ['{"key_data":"public+%&=\\nkey","future":1}', ['key_data' => "public+%&=\nkey"]],
                ['{"key_data":""}', ['key_data' => '']]],
            'export_secret_key' => [
            ['{}', []], ['[]', []],
                ['{"key_data":"private+%&=\\nkey"}', ['key_data' => "private+%&=\nkey"]]],
            'import_key' => [['{}', []], ['[]', []], ['{"key_id":"000ABC+id"}', ['key_id' => '000ABC+id']]],
            'list_public_keys' => [
            ['[]', []], ['[{}]', [[]]],
                ['[{"algorithm":"RSA","bits":2048,"created":"000123","expires":"","id":"000ABC",'
                    . '"type":"pub","user_id":"Example (comment) <user@example.test>","future":false}]',
                    [['algorithm' => 'RSA', 'bits' => 2048, 'created' => '000123', 'expires' => '',
                        'id' => '000ABC', 'type' => 'pub', 'user_id' => 'Example (comment) <user@example.test>']]]],
            'list_secret_keys' => [
            ['[]', []], ['[{}]', [[]]],
                ['[{"algorithm":"DSA","bits":1024,"created":0,"expires":0,"id":"000ABC",'
                    . '"type":"sec","user_id":"","future":null}]',
                    [['algorithm' => 'DSA', 'bits' => 1024, 'created' => 0, 'expires' => 0,
                        'id' => '000ABC', 'type' => 'sec', 'user_id' => '']]]],
        ];
    }

    /** @return array<string, list<?string>> */
    private static function badCases(): array
    {
        $cases = [];
        foreach (array_keys(self::cases()) as $function) {
            $bad = [null, 'false', 'true', '0', '1.5', '"text"'];
            if ($function !== 'delete_keypair' && $function !== 'generate_key') {
                $bad[] = 'null';
            }
            if (str_starts_with($function, 'list_')) {
                array_push($bad, '{}', '[null]', '[0]', '[false]', '[[1]]');
                foreach (['algorithm', 'id', 'type', 'user_id'] as $field) {
                    foreach (['null', '0', 'false', '[]'] as $value) {
                        $bad[] = '[{"' . $field . '":' . $value . '}]';
                    }
                }
                foreach (['created', 'expires'] as $field) {
                    $bad[] = '[{"' . $field . '":' . ($function === 'list_public_keys' ? '0' : '"00"') . '}]';
                    $bad[] = '[{"' . $field . '":null}]';
                }
                array_push($bad, '[{"bits":"02048"}]', '[{"bits":false}]', '[{"bits":2048.5}]');
            } else {
                $bad[] = '[{}]';
                if ($function === 'import_key' || str_starts_with($function, 'export_')) {
                    $field = $function === 'import_key' ? 'key_id' : 'key_data';
                    foreach (['null', '0', 'false', '[]', '{}'] as $value) {
                        $bad[] = '{"' . $field . '":' . $value . '}';
                    }
                }
            }
            $cases[$function] = $bad;
        }
        return $cases;
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        return match ($function) {
            'delete_keypair' => $account->gpg()->deleteKeypair(keyId: '000ABC+id'),
            'export_public_key' => $account->gpg()->exportPublicKey(keyId: '000ABC+id'),
            'export_secret_key' => $account->gpg()->exportSecretKey(keyId: '000ABC+id', passphrase: 'phrase+%&='),
            'generate_key' => $account->gpg()->generateKey(
                email: 'user+tag@example.test',
                name: 'Example User', passphrase: 'phrase+%&=',
                comment: 'A +&=%/雪', expire: '0', keysize: 4096, noExpire: false,
            ),
            'import_key' => $account->gpg()->importKey(keyData: "armored+%&=\nkey"),
            'list_public_keys' => $account->gpg()->listPublicKeys(),
            default => $account->gpg()->listSecretKeys(),
        };
    }

    /** @return array{string, string} */
    private static function wire(string $function): array
    {
        return match ($function) {
            'delete_keypair' => ['POST', 'key_id=000ABC%2Bid'],
            'export_public_key' => ['GET', 'key_id=000ABC%2Bid'],
            'export_secret_key' => ['POST', 'key_id=000ABC%2Bid&passphrase=phrase%2B%25%26%3D'],
            'generate_key' => ['POST', 'email=user%2Btag%40example.test&name=Example%20User'
                . '&passphrase=phrase%2B%25%26%3D'
                . '&comment=A%20%2B%26%3D%25%2F%E9%9B%AA&expire=0&keysize=4096&no_expire=0'],
            'import_key' => ['POST', 'key_data=armored%2B%25%26%3D%0Akey'],
            default => ['GET', ''],
        };
    }

    #[DataProvider('routes')]
    public function testOptionalInputsOmitDefaultsAndPreserveEmptyAndFalseValues(string $route): void
    {
        [$account, $http] = self::account($route);
        foreach ([null, '', 'phrase+%&='] as $passphrase) {
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->gpg()->exportSecretKey(keyId: '', passphrase: $passphrase);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('key_id=' . match ($passphrase) {
                null => '', '' => '&passphrase=', default => '&passphrase=phrase%2B%25%26%3D',
            }, (string) $request->getBody());
            self::assertStringNotContainsString('passphrase', (string) $request->getUri());
        }
        foreach ([null, false, true] as $noExpire) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
            $account->gpg()->generateKey(email: '', name: '', passphrase: '', noExpire: $noExpire);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('email=&name=&passphrase=' . match ($noExpire) {
                null => '', false => '&no_expire=0', true => '&no_expire=1',
            }, (string) $request->getBody());
        }
        self::assertCount(6, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testImportEncodesTheSuppliedStringOnceWithoutAssumingServerDecoding(string $route): void
    {
        foreach (
            [
                "fixture+\n" => 'key_data=fixture%2B%0A',
                'fixture%2B%0A' => 'key_data=fixture%252B%250A',
            ] as $input => $wire
        ) {
            [$account, $http] = self::account($route);
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            self::assertSame([], $account->gpg()->importKey($input));
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($wire, (string) $request->getBody());
            self::assertSame('POST', $request->getMethod());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testSecretInputParametersAreMarkedSensitive(): void
    {
        $parameters = [['exportSecretKey', 'passphrase'], ['generateKey', 'passphrase'], ['importKey', 'keyData']];
        foreach ($parameters as $case) {
            $method = new ReflectionMethod(\HostingPal\WhmApiClient\Api\Uapi\Gpg::class, $case[0]);
            $found = false;
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() === $case[1]) {
                    self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                    $found = true;
                }
            }
            self::assertTrue($found);
        }
    }
}
