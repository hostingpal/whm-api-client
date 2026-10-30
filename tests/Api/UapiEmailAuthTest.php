<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionParameter;
use SensitiveParameter;

/** Independent literal contracts from all twelve pinned EmailAuth operations. */
final class UapiEmailAuthTest extends TestCase
{
    #[DataProvider('operationRoutes')]
    public function testExactRequestsAndAllKnownFields(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        [$method, $parameters, $payload] = self::contracts()[$function];
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), self::invoke($account, $function));
        self::assertRequest($http, $route, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi ?? []);
    }

    #[DataProvider('operationRoutes')]
    public function testEmptyListsAndOptionalFieldsRemainDistinct(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        foreach ([['[]', []], ['[{}]', [[]]]] as [$payload, $expected]) {
            $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
            self::assertSame($expected, self::invoke($client->uapi('npr'), $function));
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidOrAbsentDataRetainsConfirmedOutcome(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        try {
            self::invoke($account, $function);
            self::fail('Malformed EmailAuth data must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param list<array<string, int|string|null>> $expected */
    #[DataProvider('observedFieldVariants')]
    public function testObservedFieldsPreserveNullAndNormalizeOnlyPtrIntegers(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($client->uapi('npr'), $function));
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->uapi('npr')->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string, string, string, list<array<string, int|string|null>>}> */
    public static function observedFieldVariants(): iterable
    {
        $cases = [
            ['validate_current_dkims', '[{"error":null}]', [['error' => null]]],
            ['validate_current_dmarcs', '[{"error":null}]', [['error' => null]]],
            ['validate_current_dmarcs', '[{"record":null}]', [['record' => null]]],
            ['validate_current_ptrs', '[{"error":null}]', [['error' => null]]],
            ['validate_current_ptrs', '[{"ip_version":"4"}]', [['ip_version' => 4]]],
            ['validate_current_ptrs', '[{"ip_version":"6"}]', [['ip_version' => 6]]],
            ['validate_current_ptrs', '[{"error":null,"ip_version":"4"},{}]',
                [['error' => null, 'ip_version' => 4], []]],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $i => [$function, $payload, $expected]) {
                yield "$route $function observed $i" => [$route, $function, $payload, $expected];
            }
        }
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operationRoutes() as [$route, $function]) {
            foreach ([null, 'null', '{}', 'false', '0', '"text"', '[null]', '[true]', '[1]'] as $i => $payload) {
                yield "$route $function root $i" => [$route, $function, $payload];
            }
        }
        $fields = [
            'apply_dmarc' => ['domain', 'msg', 'status'],
            'disable_dkim' => ['domain', 'msg', 'status'],
            'enable_dkim' => ['domain', 'msg', 'status'],
            'ensure_dkim_keys_exist' => ['domain', 'msg', 'status'],
            'fetch_dkim_private_keys' => ['domain', 'pem'],
            'install_dkim_private_keys' => ['domain', 'msg', 'status'],
            'install_spf_records' => ['domain', 'msg', 'status'],
            'remove_dmarc' => ['domain', 'msg', 'status'],
            'validate_current_dkims' => ['domain', 'error', 'expected', 'state', 'validity_cache_update'],
            'validate_current_dmarcs' => ['domain', 'error', 'record', 'state', 'subdomain', 'suggested'],
            'validate_current_ptrs' => ['arpa_domain', 'domain', 'error', 'helo', 'ip_address', 'ip_version', 'state'],
            'validate_current_spfs' => ['domain', 'error', 'expected', 'ip_address', 'ip_version', 'state'],
        ];
        foreach ($fields as $function => $leaves) {
            foreach ($leaves as $field) {
                $badValues = match ($field) {
                    'status' => ['null', '"0"', '"1"', '2', '-1', '1.0', 'false', '[]'],
                    'ip_version' => ['null', '"4"', '4.0', 'true', '[]'],
                    default => ['null', '0', 'false', '[]'],
                };
                foreach ($badValues as $i => $bad) {
                    $nullable = ($field === 'error' && in_array(
                        $function,
                        ['validate_current_dkims', 'validate_current_dmarcs', 'validate_current_ptrs'],
                        true,
                    )) || ($function === 'validate_current_dmarcs' && $field === 'record');
                    if ($nullable && $bad === 'null') {
                        continue;
                    }
                    if ($function === 'validate_current_ptrs' && $field === 'ip_version' && $bad === '"4"') {
                        continue;
                    }
                    yield "$function $field $i" => ['direct', $function, '[{"' . $field . '":' . $bad . '}]'];
                }
            }
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['"4.0"', '"4.1"', '"4e0"', '"04"', '"+4"', '" 4"', '"4 "', '""', '"-0"',
                '"9223372036854775808"', '"-9223372036854775809"'] as $i => $version
            ) {
                yield "$route ptr strict integer $i" =>
                    [$route, 'validate_current_ptrs', '[{"ip_version":' . $version . '}]'];
            }
        }
        foreach (['validate_current_dkims', 'validate_current_spfs'] as $function) {
            foreach (
                ['null', '{}', '[null]', '[1]', '[{"current":null}]', '[{"reason":false}]',
                '[{"state":1}]'] as $i => $records
            ) {
                yield "$function records $i" => ['bridge', $function, '[{"records":' . $records . '}]'];
            }
        }
        foreach (['null', '{}', '[1]', '[null]'] as $i => $nameservers) {
            yield "ptr nameservers $i" => ['bare', 'validate_current_ptrs', '[{"nameservers":' . $nameservers . '}]'];
        }
        foreach (
            ['null', '{}', '[null]', '[1]', '[{"domain":null}]', '[{"state":false}]',
            '[{"forward_records":{}}]', '[{"forward_records":[1]}]'] as $i => $records
        ) {
            yield "ptr records $i" => ['bridge', 'validate_current_ptrs', '[{"ptr_records":' . $records . '}]'];
        }
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailurePrecedesPayloadValidation(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($client->uapi('npr'), $function);
            self::fail('UAPI status zero must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
    }

    public function testOuterWhmFailureRejectsInnerSuccess(): void
    {
        [$client, $http] = self::client('bridge');
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":0,"reason":"Outer failure"},"data":{"uapi":{"status":1,"data":[]}}}',
        ));
        $this->expectException(ApiErrorException::class);
        $client->uapi('npr')->emailAuth()->validateCurrentDkims(domains: ['example.com']);
    }

    #[DataProvider('routes')]
    public function testOptionalDomainsOmitTheWireField(string $route): void
    {
        [$client, $http] = self::client($route);
        $auth = $client->uapi('npr')->emailAuth();
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        self::assertSame([], $auth->applyDmarc(policy: 'v=DMARC1; p=none;'));
        self::assertRequest($http, $route, 'apply_dmarc', 'POST', 'policy=v%3DDMARC1%3B%20p%3Dnone%3B');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        self::assertSame([], $auth->removeDmarc());
        self::assertRequest($http, $route, 'remove_dmarc', 'POST', '');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        self::assertSame([], $auth->validateCurrentDmarcs());
        self::assertRequest($http, $route, 'validate_current_dmarcs', 'GET', '');
    }

    #[DataProvider('routes')]
    public function testPrivateKeyBytesEncodedOnceAndExcludedFromTrace(string $route): void
    {
        [$client, $http] = self::client($route);
        $auth = $client->uapi('npr')->emailAuth();
        $parameter = new ReflectionParameter([$auth, 'installDkimPrivateKeys'], 'keys');
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $auth->installDkimPrivateKeys(domains: ['example.com', 'example.net'], keys: ["a +&=%\n雪", 'second']);
        self::assertRequest(
            $http,
            $route,
            'install_dkim_private_keys',
            'POST',
            'domain=example.com&domain=example.net&key=a%20%2B%26%3D%25%0A%E9%9B%AA&key=second',
        );
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
        try {
            $auth->installDkimPrivateKeys(domains: ['example.com'], keys: ['private-key-trace-marker']);
            self::fail('Invalid response must raise an exception.');
        } catch (PayloadMismatchException $error) {
            self::assertStringNotContainsString('private-key-trace-marker', $error->getTraceAsString());
            self::assertStringNotContainsString('private-key-trace-marker', $error->getMessage());
        }
    }

    public function testInvalidRepeatedListClearsMetadataBeforeDispatch(): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope('direct', '[]')));
        $account->emailAuth()->validateCurrentDkims(domains: ['example.com']);
        self::assertNotNull($account->responseMeta());
        try {
            $account->emailAuth()->validateCurrentDkims(domains: []);
            self::fail('Empty repeated domains must fail before dispatch.');
        } catch (InvalidArgumentException) {
            self::assertNull($account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (array_keys(self::contracts()) as $function) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return array<string, array{string, string, string}> */
    private static function contracts(): array
    {
        $domains = 'domain=example.com&domain=example.net';
        $mutation = '[{"domain":"example.com","msg":"fixture status","status":0},'
            . '{"domain":"example.net","msg":"","status":1}]';
        return [
            'apply_dmarc' => ['POST', 'policy=v%3DDMARC1%3B%20p%3Dnone%3B&' . $domains, $mutation],
            'disable_dkim' => ['POST', $domains, $mutation],
            'enable_dkim' => ['POST', $domains, $mutation],
            'ensure_dkim_keys_exist' => ['POST', $domains, $mutation],
            'fetch_dkim_private_keys' => ['POST', $domains,
                '[{"domain":"example.com","pem":"synthetic private key payload"}]'],
            'install_dkim_private_keys' => ['POST', $domains . '&key=synthetic-key&key=second-key', $mutation],
            'install_spf_records' => ['POST',
                $domains . '&record=v%3Dspf1%20%2Ba%20-all&record=v%3Dspf1%20-all', $mutation],
            'remove_dmarc' => ['POST', $domains, $mutation],
            'validate_current_dkims' => ['GET', $domains,
                '[{"domain":"default._domainkey.example.com","error":"lookup failed","expected":"v=DKIM1; p=public",'
                . '"records":[{"current":"v=DKIM1; p=other","reason":"mismatch","state":"MISMATCH"}],'
                . '"state":"ERROR","validity_cache_update":"error"},{"records":[]}]'],
            'validate_current_dmarcs' => ['GET', $domains,
                '[{"domain":"example.com","error":"SPF error","record":"v=DMARC1; p=none;",'
                . '"state":"SPF_ERROR","subdomain":"_dmarc.example.com","suggested":"v=DMARC1; p=none;"}]'],
            'validate_current_ptrs' => ['GET', $domains,
                '[{"arpa_domain":"1.0.0.10.in-addr.arpa","domain":"example.com","error":"fixture error",'
                . '"helo":"mail.example.com","ip_address":"10.0.0.1","ip_version":4,'
                . '"nameservers":["ns1.example.com"],"ptr_records":[{"domain":"example.com",'
                . '"forward_records":["10.0.0.1","::1"],"state":"VALID"}],"state":"IP_IS_PRIVATE"},'
                . '{"nameservers":[],"ptr_records":[{"forward_records":[]}]},{"ptr_records":[]}]'],
            'validate_current_spfs' => ['GET', $domains,
                '[{"domain":"example.com","error":"fixture lookup error","expected":"ip6:::1",'
                . '"ip_address":"::1","ip_version":6,"records":[{"current":"v=spf1 -all",'
                . '"reason":"not permitted","state":"FAIL"}],"state":"MISMATCHED"},{"records":[]}]'],
        ];
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        $auth = $account->emailAuth();
        $domains = ['example.com', 'example.net'];
        return match ($function) {
            'apply_dmarc' => $auth->applyDmarc(policy: 'v=DMARC1; p=none;', domains: $domains),
            'disable_dkim' => $auth->disableDkim(domains: $domains),
            'enable_dkim' => $auth->enableDkim(domains: $domains),
            'ensure_dkim_keys_exist' => $auth->ensureDkimKeysExist(domains: $domains),
            'fetch_dkim_private_keys' => $auth->fetchDkimPrivateKeys(domains: $domains),
            'install_dkim_private_keys' => $auth->installDkimPrivateKeys(
                domains: $domains,
                keys: ['synthetic-key', 'second-key'],
            ),
            'install_spf_records' => $auth->installSpfRecords(
                domains: $domains,
                records: ['v=spf1 +a -all', 'v=spf1 -all'],
            ),
            'remove_dmarc' => $auth->removeDmarc(domains: $domains),
            'validate_current_dkims' => $auth->validateCurrentDkims(domains: $domains),
            'validate_current_dmarcs' => $auth->validateCurrentDmarcs(domains: $domains),
            'validate_current_ptrs' => $auth->validateCurrentPtrs(domains: $domains),
            default => $auth->validateCurrentSpfs(domains: $domains),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=EmailAuth&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/EmailAuth/' . $function;
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
        if ($method === 'POST') {
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
