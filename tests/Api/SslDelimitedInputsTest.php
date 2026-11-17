<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\ModSecurity;
use HostingPal\WhmApiClient\Api\Uapi\Ssl;
use HostingPal\WhmApiClient\Api\Whm\Ssl as WhmSsl;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Offline synthetic fixtures: no certificate generation or server contact occurs. */
final class SslDelimitedInputsTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, int}> */
    public static function signatures(): iterable
    {
        yield 'ModSecurity disableDomains' => [ModSecurity::class, 'disableDomains', 0];
        yield 'ModSecurity enableDomains' => [ModSecurity::class, 'enableDomains', 0];
        yield 'SSL addAutosslExcludedDomains' => [Ssl::class, 'addAutosslExcludedDomains', 0];
        yield 'SSL disableMailSni' => [Ssl::class, 'disableMailSni', 0];
        yield 'SSL enableMailSni' => [Ssl::class, 'enableMailSni', 0];
        yield 'SSL fetchCertificatesForFqdns' => [Ssl::class, 'fetchCertificatesForFqdns', 0];
        yield 'SSL generateCert' => [Ssl::class, 'generateCert', 1];
        yield 'SSL generateCsr' => [Ssl::class, 'generateCsr', 1];
        yield 'SSL setAutosslExcludedDomains' => [Ssl::class, 'setAutosslExcludedDomains', 0];
        yield 'SSL toggleSslRedirectForDomains' => [Ssl::class, 'toggleSslRedirectForDomains', 0];
        yield 'WHM fetchSslCertificatesForFqdns' => [WhmSsl::class, 'fetchSslCertificatesForFqdns', 0];
        yield 'WHM generateSsl' => [WhmSsl::class, 'generateSsl', 0];
    }

    /** @param class-string $class */
    #[DataProvider('signatures')]
    public function testEachDomainsPositionAcceptsListsAndProtectsItsTrace(
        string $class,
        string $method,
        int $position,
    ): void {
        $reflection = new ReflectionMethod($class, $method);
        $parameter = $reflection->getParameters()[$position];
        $optional = $method === 'setAutosslExcludedDomains';
        self::assertSame($optional ? 'array|string|null' : 'array|string', (string) $parameter->getType());
        self::assertSame('domains', $parameter->getName());
        self::assertSame($optional, $parameter->isOptional());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        self::assertStringContainsString(
            '@param string|non-empty-list<string>' . ($optional ? '|null' : '') . ' $domains',
            (string) $reflection->getDocComment(),
        );
        if ($optional) {
            self::assertNull($parameter->getDefaultValue());
        }
        if ($position === 1) {
            self::assertSame('countryName', $reflection->getParameters()[0]->getName());
            self::assertSame('keyId', $reflection->getParameters()[2]->getName());
        }
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function adopters(): iterable
    {
        $positions = [
            ['modSecurity', 'disableDomains', ',', 'ModSecurity/disable_domains'],
            ['modSecurity', 'enableDomains', ',', 'ModSecurity/enable_domains'],
            ['ssl', 'addAutosslExcludedDomains', ',', 'SSL/add_autossl_excluded_domains'],
            ['ssl', 'disableMailSni', '|', 'SSL/disable_mail_sni'],
            ['ssl', 'enableMailSni', '|', 'SSL/enable_mail_sni'],
            ['ssl', 'fetchCertificatesForFqdns', ',', 'SSL/fetch_certificates_for_fqdns'],
            ['ssl', 'generateCert', ',', 'SSL/generate_cert'],
            ['ssl', 'generateCsr', ',', 'SSL/generate_csr'],
            ['ssl', 'setAutosslExcludedDomains', ',', 'SSL/set_autossl_excluded_domains'],
            ['ssl', 'toggleSslRedirectForDomains', ',', 'SSL/toggle_ssl_redirect_for_domains'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($positions as $position) {
                yield "$route {$position[1]}" => [$route, ...$position];
            }
        }
        yield 'whm fetchSslCertificatesForFqdns' => ['whm', 'ssl', 'fetchSslCertificatesForFqdns', ',',
            'fetch_ssl_certificates_for_fqdns'];
        yield 'whm generateSsl' => ['whm', 'ssl', 'generateSsl', ',', 'generatessl'];
    }

    #[DataProvider('adopters')]
    public function testListsAndEquivalentScalarsPreserveOneEncodedPostAndPayload(
        string $route,
        string $group,
        string $method,
        string $delimiter,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $method);
        $cases = $delimiter === ','
            ? [
        [['b.test', 'a.test', 'b.test'], 'b.test%2Ca.test%2Cb.test'],
                ['b.test,a.test,b.test', 'b.test%2Ca.test%2Cb.test']]
            : [
        [['b.test', 'a.test', 'b.test'], 'b.test%7Ca.test%7Cb.test'],
                ['b.test|a.test|b.test', 'b.test%7Ca.test%7Cb.test']];
        foreach ($cases as [$value, $encoded]) {
            self::assertSame(
                self::fixtureResult($method),
                self::invoke($handle, $group, $method, ['domains' => $value]),
            );
            self::assertRequest($http, $route, $operation, self::fields($method, $encoded));
            self::assertNotNull($handle->responseMeta());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function byteCases(): iterable
    {
        yield 'comma surrounding empties' => ['addAutosslExcludedDomains', ['', 'x', ''], '%2Cx%2C'];
        yield 'comma singleton' => ['addAutosslExcludedDomains', ['x'], 'x'];
        yield 'comma only empty' => ['addAutosslExcludedDomains', [''], ''];
        yield 'comma empty scalar' => ['addAutosslExcludedDomains', '', ''];
        yield 'comma raw scalar escapes' => ['addAutosslExcludedDomains', ',%2C%7C|', '%2C%252C%257C%7C'];
        yield 'comma literal bytes' => ['addAutosslExcludedDomains', [" a+&=%2C%7C/雪\n\0\xFF", '|'],
            '%20a%2B%26%3D%252C%257C%2F%E9%9B%AA%0A%00%FF%2C%7C'];
        yield 'pipe surrounding empties' => ['enableMailSni', ['', 'x', ''], '%7Cx%7C'];
        yield 'pipe only empty' => ['enableMailSni', [''], ''];
        yield 'pipe raw scalar escapes' => ['enableMailSni', '|%7C%2C,', '%7C%257C%252C%2C'];
        yield 'pipe literal bytes' => ['enableMailSni', [" a+&=%2C%7C/雪\n\0\xFF", ','],
            '%20a%2B%26%3D%252C%257C%2F%E9%9B%AA%0A%00%FF%7C%2C'];
    }

    #[DataProvider('byteCases')]
    public function testRawBytesAndNonselectedDelimiterArePreserved(string $method, mixed $value, string $body): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$handle, $http] = self::client($route, $method);
            self::assertSame(
                self::fixtureResult($method),
                self::invoke($handle, 'ssl', $method, ['domains' => $value]),
            );
            self::assertRequest($http, $route, 'SSL/' . ($method === 'enableMailSni'
                ? 'enable_mail_sni' : 'add_autossl_excluded_domains'), 'domains=' . $body);
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testSetAutoSslOmissionNullExplicitEmptyAndRejectedEmptyListStayDistinct(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$handle, $http] = self::client($route, 'setAutosslExcludedDomains');
            foreach ([[], ['domains' => null], ['domains' => ''], ['domains' => ['']]] as $index => $arguments) {
                self::assertSame(['opaque' => ['value' => null]], self::invoke(
                    $handle,
                    'ssl',
                    'setAutosslExcludedDomains',
                    $arguments,
                ));
                self::assertRequest($http, $route, 'SSL/set_autossl_excluded_domains', $index < 2 ? '' : 'domains=');
            }
            $old = $handle->responseMeta();
            self::assertNotNull($old);
            $raw = [$old->whm, $old->uapi];
            try {
                self::invoke($handle, 'ssl', 'setAutosslExcludedDomains', ['domains' => []]);
                self::fail('Empty list must not become omitted exclusions.');
            } catch (InvalidArgumentException) {
                self::assertNull($handle->responseMeta());
                self::assertSame($raw, [$old->whm, $old->uapi]);
                self::assertCount(4, $http->getRequests());
            }
        }
    }

    #[DataProvider('adopters')]
    public function testInvalidEnteredArraysClearMetadataAndDispatchNothing(
        string $route,
        string $group,
        string $method,
        string $delimiter,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $method);
        $invalid = [[], ['synthetic-private-first', 'synthetic-private-late' . $delimiter]];
        // Distributed malformed shapes supplement the two invariants checked at every selected position.
        $invalid[] = match ($method) {
            'disableDomains', 'generateSsl' => [1 => 'synthetic-private-sparse'],
            'enableDomains' => ['key' => 'synthetic-private-keyed'],
            'addAutosslExcludedDomains' => ['synthetic-private-first', null],
            'disableMailSni' => ['synthetic-private-first', 0],
            'enableMailSni' => ['synthetic-private-first', false],
            'fetchCertificatesForFqdns' => ['synthetic-private-first', 1.5],
            'generateCert' => ['synthetic-private-first', ['nested']],
            default => ['synthetic-private-first', new \stdClass()],
        };
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ($invalid as $value) {
                self::invoke($handle, $group, $method, ['domains' => ['seed']]);
                $old = $handle->responseMeta();
                self::assertNotNull($old);
                $raw = [$old->whm, $old->uapi];
                $count = count($http->getRequests());
                try {
                    self::invoke($handle, $group, $method, ['domains' => $value]);
                    self::fail('Invalid domains accepted for ' . $operation);
                } catch (InvalidArgumentException $error) {
                    self::assertNull($handle->responseMeta());
                    self::assertSame($raw, [$old->whm, $old->uapi]);
                    self::assertCount($count, $http->getRequests());
                    self::assertStringNotContainsString('synthetic-private-', $error->getMessage());
                    $seen = false;
                    foreach ($error->getTrace() as $frame) {
                        $class = $frame['class'] ?? '';
                        if ($class === self::group($handle, $group)::class) {
                            self::assertSame($method, $frame['function']);
                            $index = in_array($method, ['generateCert', 'generateCsr'], true) ? 1 : 0;
                            self::assertInstanceOf(
                                SensitiveParameterValue::class,
                                ($frame['args'] ?? [])[$index] ?? null,
                            );
                            $seen = true;
                        }
                        if (
                            str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                            && !str_contains($class, '\\Tests\\')
                        ) {
                            self::assertFalse(self::containsPrivateValue($frame['args'] ?? []));
                        }
                    }
                    self::assertTrue($seen, 'The actual generated domains frame must be inspected.');
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testNativeRequiredTypeErrorsRemainBeforeEntry(): void
    {
        foreach (['direct', 'bridge', 'whm'] as $route) {
            $method = $route === 'whm' ? 'generateSsl' : 'generateCsr';
            [$handle, $http] = self::client($route, $method);
            self::invoke($handle, 'ssl', $method, ['domains' => ['seed']]);
            $old = $handle->responseMeta();
            self::assertNotNull($old);
            foreach ([null, false, 1, 1.5, new \stdClass()] as $value) {
                try {
                    self::invoke($handle, 'ssl', $method, ['domains' => $value]);
                    self::fail('Native wrong scalar type accepted.');
                } catch (TypeError) {
                    self::assertSame($old, $handle->responseMeta());
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    public function testGenerationNamedArgumentsKeepOrderAdjacentFieldsFalseAndZero(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['generateCert' => 'generate_cert', 'generateCsr' => 'generate_csr'] as $method => $function) {
                [$handle, $http] = self::client($route, $method);
                self::assertInstanceOf(UapiClient::class, $handle);
                // Deliberately pass domains before countryName by name; generated wire order remains source order.
                $result = $handle->ssl()->$method(
                    domains: ['a.test', 'a.test', 'b.test'],
                    countryName: 'RS',
                    keyId: 'existing-key',
                    localityName: 'City',
                    organizationName: 'Org',
                    stateOrProvinceName: 'State',
                    emailAddress: 'a+ssl@example.test',
                    friendlyName: '',
                    organizationalUnitName: '',
                );
                self::assertSame(self::fixtureResult($method), $result);
                self::assertRequest(
                    $http,
                    $route,
                    'SSL/' . $function,
                    'countryName=RS&domains=a.test%2Ca.test%2Cb.test&key_id=existing-key&localityName=City'
                    . '&organizationName=Org&stateOrProvinceName=State&emailAddress=a%2Bssl%40example.test'
                    . '&friendly_name=&organizationalUnitName=',
                );
                self::assertCount(1, $http->getRequests());
            }
        }
        [$handle, $http] = self::client('whm', 'generateSsl');
        self::assertInstanceOf(WhmClient::class, $handle);
        self::assertSame(self::fixtureResult('generateSsl'), $handle->ssl()->generateSsl(
            ['a.test', 'b.test'],
            countryName: 'RS',
            keysize: 0,
            organizationName: 'Org',
            organizationalUnitName: '',
            passphrase: 'pass +&',
            skipCertificate: false,
        ));
        self::assertRequest(
            $http,
            'whm',
            'generatessl',
            'domains=a.test%2Cb.test&countryName=RS&keysize=0&organizationName=Org&organizationalUnitName='
            . '&pass=pass%20%2B%26&skip_certificate=0',
        );
        self::assertCount(1, $http->getRequests());
    }

    public function testArrayCallsPreserveDiagnosticsNullabilityAndFailurePrecedenceWithoutRetry(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$handle, $http] = self::client($route, 'disableDomains');
            foreach (
                ['{"no_domains_provided":1}', '{"invalid_domains":["bad"],"invalid_domains_provided":1}'] as $payload
            ) {
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                self::assertSame(json_decode($payload, true), self::invoke(
                    $handle,
                    'modSecurity',
                    'disableDomains',
                    ['domains' => ['a.test']],
                ));
            }
            $http->addResponse(new Response(200, [], self::envelope($route, '[{"enabled":0}]', 0)));
            try {
                self::invoke($handle, 'modSecurity', 'disableDomains', ['domains' => ['a.test']]);
                self::fail('API zero must win over a valid partial-change payload.');
            } catch (ApiErrorException) {
                self::assertInstanceOf(UapiClient::class, $handle);
                self::assertSame(0, $handle->responseMeta()?->uapiStatus());
                self::assertCount(3, $http->getRequests());
            }
            foreach (['null', null, '0'] as $payload) {
                [$handle, $http] = self::client($route, 'setAutosslExcludedDomains');
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                try {
                    $result = self::invoke($handle, 'ssl', 'setAutosslExcludedDomains', ['domains' => ['a.test']]);
                    self::assertSame('null', $payload);
                    self::assertNull($result);
                } catch (PayloadMismatchException) {
                    self::assertNotSame('null', $payload);
                    self::assertInstanceOf(UapiClient::class, $handle);
                    self::assertSame(1, $handle->responseMeta()?->uapiStatus());
                }
                self::assertCount(1, $http->getRequests());
            }
            [$handle, $http] = self::client($route, 'enableMailSni');
            $http->addResponse(new Response(200, [], self::envelope(
                $route,
                '{"failed_domains":{},"updated_domains":{"a.test":0}}',
            )));
            self::assertSame(
                ['failed_domains' => [], 'updated_domains' => ['a.test' => 0]],
                self::invoke($handle, 'ssl', 'enableMailSni', ['domains' => ['a.test']]),
            );
            self::assertInstanceOf(UapiClient::class, $handle);
            self::assertSame(['fixture warning'], $handle->responseMeta()?->uapiWarnings());
            self::assertCount(1, $http->getRequests());
        }
        [$handle, $http] = self::client('bridge', 'addAutosslExcludedDomains');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"fixture failure"},'
            . '"data":{"uapi":{"status":1,"data":null}}}'));
        try {
            self::invoke($handle, 'ssl', 'addAutosslExcludedDomains', ['domains' => ['a.test']]);
            self::fail('Outer WHM failure must win over nested UAPI success.');
        } catch (ApiErrorException) {
            self::assertInstanceOf(UapiClient::class, $handle);
            self::assertSame(0, $handle->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @param array<string, mixed> $arguments */
    private static function invoke(WhmClient|UapiClient $handle, string $group, string $method, array $arguments): mixed
    {
        $base = match ($method) {
            'generateCert', 'generateCsr' => ['countryName' => 'RS', 'keyId' => 'existing-key',
                'localityName' => 'City', 'organizationName' => 'Org', 'stateOrProvinceName' => 'State'],
            'toggleSslRedirectForDomains' => ['state' => false],
            'generateSsl' => ['skipCertificate' => false],
            default => [],
        };
        return self::group($handle, $group)->$method(...($arguments + $base));
    }

    private static function group(WhmClient|UapiClient $handle, string $group): ModSecurity|Ssl|WhmSsl
    {
        return $handle instanceof WhmClient ? $handle->ssl()
            : ($group === 'modSecurity' ? $handle->modSecurity() : $handle->ssl());
    }

    private static function fields(string $method, string $encoded): string
    {
        return match ($method) {
            'generateCert', 'generateCsr' => 'countryName=RS&domains=' . $encoded
                . '&key_id=existing-key&localityName=City&organizationName=Org&stateOrProvinceName=State',
            'toggleSslRedirectForDomains' => 'domains=' . $encoded . '&state=0',
            'generateSsl' => 'domains=' . $encoded . '&skip_certificate=0',
            default => 'domains=' . $encoded,
        };
    }

    /** @return array<array-key, mixed> */
    private static function fixtureResult(string $method): array
    {
        return match ($method) {
            'disableDomains', 'enableDomains' => [['dependencies' => ['b.test'], 'domain' => 'a.test',
                'enabled' => 0, 'exception' => null, 'searchhint' => 'a,b', 'type' => 'main']],
            'disableMailSni', 'enableMailSni' => ['failed_domains' => ['b.test' => 'fixture diagnostic'],
                'updated_domains' => ['a.test' => 0]],
            'fetchCertificatesForFqdns' => [['cab' => null, 'created' => 123, 'is_self_signed' => 0,
                'modulus_length' => 2048, 'validation_type' => null, 'verify_error' => null]],
            'fetchSslCertificatesForFqdns' => ['payload' => [['cab' => null, 'created' => 123,
                'is_self_signed' => 0, 'modulus_length' => 2048, 'validation_type' => null, 'verify_error' => null]]],
            'generateCert' => ['created' => 123, 'domains' => ['a.test', 'b.test'], 'id' => 'synthetic-cert',
                'is_self_signed' => 1, 'text' => 'SYNTHETIC CERTIFICATE'],
            'generateCsr' => ['created' => 123, 'domains' => ['a.test', 'a.test'], 'id' => 'synthetic-csr',
                'text' => 'SYNTHETIC CSR'],
            'generateSsl' => ['cert' => 'SYNTHETIC CERTIFICATE', 'csr' => 'SYNTHETIC CSR',
                'key' => 'SYNTHETIC PRIVATE KEY', 'key_id' => 'synthetic-key'],
            'toggleSslRedirectForDomains' => ['fixture warning'],
            default => ['opaque' => ['value' => null]],
        };
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $data = $payload === null ? '' : ',"data":' . $payload;
        $uapi = '{"status":' . $status . ',"warnings":["fixture warning"]' . $data . '}';
        return match ($route) {
            'whm' => '{"metadata":{"result":' . $status . ',"reason":"fixture"}' . $data . '}',
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $uapi . '}}',
            default => '{"result":' . $uapi . '}',
        };
    }

    /** @return array{WhmClient|UapiClient, MockClient} */
    private static function client(string $route, string $method): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $payload = json_encode(self::fixtureResult($method), JSON_THROW_ON_ERROR);
        if (in_array($method, ['fetchCertificatesForFqdns', 'fetchSslCertificatesForFqdns'], true)) {
            $payload = str_replace('"created":123', '"created":"123"', $payload);
            $payload = str_replace('"modulus_length":2048', '"modulus_length":"2048"', $payload);
            $payload = str_replace('"is_self_signed":0', '"is_self_signed":"0"', $payload);
        }
        $http->setDefaultResponse(new Response(200, [], self::envelope($route, $payload)));
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'direct' ? 'cpanel' : 'whm',
            'username' => $route === 'direct' ? 'fixture' : 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$route === 'whm' ? $client->whm() : $client->uapi('fixture'), $http];
    }

    private static function assertRequest(MockClient $http, string $route, string $operation, string $fields): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        $url = match ($route) {
            'whm' => 'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            'bridge' => 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=' . explode('/', $operation)[0] . '&cpanel.function=' . explode('/', $operation)[1],
            default => 'https://api.example.test:2083/execute/' . $operation,
        };
        self::assertSame($url, (string) $request->getUri());
        self::assertSame($fields, (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'direct' ? 'cpanel fixture:fixture-token' : 'whm root:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function containsPrivateValue(mixed $value): bool
    {
        if ($value instanceof SensitiveParameterValue) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (self::containsPrivateValue($key) || self::containsPrivateValue($item)) {
                    return true;
                }
            }
        }
        return is_string($value) && str_contains($value, 'synthetic-private-');
    }
}
