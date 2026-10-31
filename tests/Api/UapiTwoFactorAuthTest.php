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
use SensitiveParameterValue;

/** Independent literal request and payload contracts, derived from the pinned source. */
final class UapiTwoFactorAuthTest extends TestCase
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
        self::assertSame($account->twoFactorAuth(), $account->twoFactorAuth());
        self::assertNotSame($account->twoFactorAuth(), $other->twoFactorAuth());
        self::assertCount(0, $http->getRequests());
        $function = array_key_first(self::cases());
        self::assertNotNull($function);
        $http->addResponse(new Response(200, [], self::envelope($route, self::cases()[$function][0][0])));
        self::invoke($account, $function);
        $meta = $account->responseMeta();
        $account->twoFactorAuth();
        self::assertSame($meta, $account->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bare' => ['bare'];
        yield 'bridge' => ['bridge'];
    }

    private static function assertRequest(MockClient $http, string $route, string $function): void
    {
        [$method, $parameters] = self::wire($function);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=TwoFactorAuth&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/TwoFactorAuth/' . $function;
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
        $configuration = [
        ['{}', []], ['[]', []], ['{"is_enabled":0,"issuer":"","future":1}',
            ['is_enabled' => 0, 'issuer' => '']], ['{"is_enabled":1,"issuer":"Example"}',
            ['is_enabled' => 1, 'issuer' => 'Example']]];
        return [
            'generate_user_configuration' => [
        ['{}', []], ['[]', []],
                ['{"otpauth_str":"otpauth://totp/synthetic?secret=ABC&issuer=Example","secret":"ABC"}',
                    ['otpauth_str' => 'otpauth://totp/synthetic?secret=ABC&issuer=Example', 'secret' => 'ABC']],
                ['{"otpauth_str":"","secret":""}', ['otpauth_str' => '', 'secret' => '']]],
            'get_team_user_configuration' => $configuration,
            'get_user_configuration' => $configuration,
            'remove_user_configuration' => [
            ['{}', []], ['[]', []],
                ['{"tfa_removed":1}', ['tfa_removed' => 1]]],
            'set_user_configuration' => [
            ['{}', []], ['[]', []],
                ['{"tfa_configured":0}', ['tfa_configured' => 0]],
                ['{"tfa_configured":1}', ['tfa_configured' => 1]]],
        ];
    }

    /** @return array<string, list<?string>> */
    private static function badCases(): array
    {
        $cases = [];
        foreach (array_keys(self::cases()) as $function) {
            $bad = [null, 'null', 'false', 'true', '0', '1.5', '"text"', '[{}]'];
            if ($function === 'generate_user_configuration') {
                foreach (['secret', 'otpauth_str'] as $field) {
                    foreach (['null', '0', 'false', '[]', '{}'] as $value) {
                        $bad[] = '{"' . $field . '":' . $value . '}';
                    }
                }
            } elseif (str_starts_with($function, 'get_')) {
                foreach (['null', '0', 'false', '[]', '{}'] as $value) {
                    $bad[] = '{"issuer":' . $value . '}';
                }
                foreach (['null', '"0"', '"1"', 'false', 'true', '0.0', '2', '-1', '[]'] as $value) {
                    $bad[] = '{"is_enabled":' . $value . '}';
                }
            } else {
                $field = $function === 'remove_user_configuration' ? 'tfa_removed' : 'tfa_configured';
                foreach (['null', '"0"', '"1"', 'false', 'true', '0.0', '[]'] as $value) {
                    $bad[] = '{"' . $field . '":' . $value . '}';
                }
                if ($function === 'set_user_configuration') {
                    array_push($bad, '{"tfa_configured":2}', '{"tfa_configured":-1}');
                }
            }
            $cases[$function] = $bad;
        }
        return $cases;
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        return match ($function) {
            'generate_user_configuration' => $account->twoFactorAuth()->generateUserConfiguration(),
            'get_team_user_configuration' => $account->twoFactorAuth()->getTeamUserConfiguration(
                teamUser: 'member+tag@example.test',
            ),
            'get_user_configuration' => $account->twoFactorAuth()->getUserConfiguration(),
            'remove_user_configuration' => $account->twoFactorAuth()->removeUserConfiguration(),
            default => $account->twoFactorAuth()->setUserConfiguration(secret: 'SYNTHETIC+%&=', tfaToken: 123456),
        };
    }

    /** @return array{string, string} */
    private static function wire(string $function): array
    {
        return match ($function) {
            'get_user_configuration' => ['GET', ''],
            'get_team_user_configuration' => ['POST', 'team_user=member%2Btag%40example.test'],
            'set_user_configuration' => ['POST', 'secret=SYNTHETIC%2B%25%26%3D&tfa_token=123456'],
            default => ['POST', ''],
        };
    }

    #[DataProvider('routes')]
    public function testZeroTokenAndEmptyStringsAreSentWithoutInventedDefaults(string $route): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{"tfa_configured":0}')));
        self::assertSame(
            ['tfa_configured' => 0],
            $account->twoFactorAuth()->setUserConfiguration(secret: '', tfaToken: 0),
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('secret=&tfa_token=0', (string) $request->getBody());
        self::assertStringNotContainsString('secret=', (string) $request->getUri());
        self::assertStringNotContainsString('tfa_token=', (string) $request->getUri());
        self::assertCount(1, $http->getRequests());
    }

    public function testSecretAndTokenParametersAreMarkedSensitive(): void
    {
        $method = new ReflectionMethod(\HostingPal\WhmApiClient\Api\Uapi\TwoFactorAuth::class, 'setUserConfiguration');
        foreach ($method->getParameters() as $parameter) {
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
        self::assertCount(2, $method->getParameters());
    }


    #[DataProvider('routes')]
    public function testSensitiveInputsAndReturnedSecretsStayOutOfFailureTraceArguments(string $route): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (
                [
                ['set_user_configuration', 'SYNTHETIC+%&='],
                ['generate_user_configuration', 'SYNTHETIC+%&=']] as [$function, $secret]
            ) {
                foreach ([0, 1] as $status) {
                    [$account, $http] = self::account($route);
                    $payload = json_encode([$secret], JSON_THROW_ON_ERROR);
                    $http->addResponse(new Response(200, [], self::envelope($route, $payload, $status)));
                    try {
                        self::invoke($account, $function);
                        self::fail('The synthetic error must fail without exposing secret arguments.');
                    } catch (ApiErrorException | PayloadMismatchException $error) {
                        self::assertStringNotContainsString($secret, $error->getMessage());
                        foreach ($error->getTrace() as $frame) {
                            self::assertSecretAbsentFromArguments($frame['args'] ?? [], $secret);
                        }
                        if ($status === 1) {
                            self::assertInstanceOf(PayloadMismatchException::class, $error);
                            self::assertIsArray($error->payload);
                            self::assertSame($secret, $error->payload[0]);
                        } else {
                            self::assertInstanceOf(ApiErrorException::class, $error);
                        }
                    }
                    self::assertCount(1, $http->getRequests());
                }
            }
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /** @param array<array-key, mixed> $arguments */
    private static function assertSecretAbsentFromArguments(array $arguments, string $secret): void
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof SensitiveParameterValue) {
                continue;
            }
            if (is_string($argument)) {
                self::assertStringNotContainsString($secret, $argument);
            } elseif (is_array($argument)) {
                self::assertSecretAbsentFromArguments($argument, $secret);
            }
        }
    }
}
