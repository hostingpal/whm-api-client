<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use ArgumentCountError;
use HostingPal\WhmApiClient\Api\Uapi\Ssl;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UapiSslTest extends TestCase
{
    public function testSslSelectorIsAvailableWithoutMakingARequest(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');

        self::assertSame($account->ssl(), $account->ssl());
        self::assertNotSame($account->ssl(), $client->uapi('other')->ssl());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    /** @param array<mixed>|int $expected */
    #[DataProvider('successfulPayloads')]
    public function testReadOnlyOperationsPreserveTheirDocumentedPayloads(
        string $route,
        string $function,
        string $payload,
        array|int $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame($expected, self::invoke($account->ssl(), $function));

        self::assertRequest($http, $route, $function);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['synthetic notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertSame($account->ssl(), $account->ssl());
        self::assertSame($metadata, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<mixed>|int}> */
    public static function successfulPayloads(): iterable
    {
        $cases = [
            ['get_autossl_excluded_domains', '[{"excluded_domain":"excluded.example.test","future":1},{}]',
                [['excluded_domain' => 'excluded.example.test'], []]],
            ['get_autossl_problems', '[{"domain":"example.test","problem":"Synthetic DCV failure",'
                . '"time":"2026-10-16T08:00:00.000Z","future":1},{}]',
                [['domain' => 'example.test', 'problem' => 'Synthetic DCV failure',
                    'time' => '2026-10-16T08:00:00.000Z'], []]],
            ['get_autossl_renewal_status', '{"will_renew":0,"has_problems":1,"is_excluded":0,"is_active":1,'
                . '"future":1}', ['has_problems' => 1, 'is_active' => 1, 'is_excluded' => 0, 'will_renew' => 0]],
            ['get_autossl_renewal_status', '{}', []],
            ['get_autossl_renewal_status', '{"is_active":0}', ['is_active' => 0]],
        ];
        foreach (['installed_hosts', 'get_autossl_excluded_domains', 'get_autossl_problems'] as $function) {
            $cases[] = [$function, '[]', []];
            $cases[] = [$function, '[{}]', [[]]];
        }
        foreach (['is_autossl_check_in_progress', 'can_ssl_redirect'] as $function) {
            $cases[] = [$function, '0', 0];
            $cases[] = [$function, '1', 1];
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $index => [$function, $payload, $expected]) {
                yield $route . ' ' . $function . ' ' . $index => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('routes')]
    public function testInstalledHostsPreservesDottedKeysPublicCertificateAndNullableKeyDetails(string $route): void
    {
        [$client, $http] = self::client($route);
        // Independently authored from the pinned operation's documented wire fields, never from generated specs.
        $payload = '[{"certificate":{"auto_ssl_provider":"ExampleCA",'
            . '"auto_ssl_provider_display_name":"Example CA","domains":["example.test","www.example.test"],'
            . '"ecdsa_curve_name":null,"ecdsa_public":null,"id":"synthetic-certificate",'
            . '"is_autossl":1,"is_self_signed":0,"issuer.commonName":"Example Issuer",'
            . '"issuer.organizationName":"Example Organization","issuer_text":"Synthetic issuer text",'
            . '"key_algorithm":"rsaEncryption","modulus":"abcd","modulus_length":2048,'
            . '"not_after":1800000000,"not_before":1700000000,"signature_algorithm":"sha256WithRSAEncryption",'
            . '"subject.commonName":"example.test","subject_text":"Synthetic subject text",'
            . '"validation_type":"dv","future":false},"certificate_text":"PUBLIC CERTIFICATE FIXTURE",'
            . '"docroot":"/home/exampleuser/public_html","domains":["example.test"],'
            . '"fqdns":["example.test","webmail.example.test"],"ip":"192.0.2.10",'
            . '"is_primary_on_ip":0,"mail_sni_status":1,"needs_sni":1,"servername":"example.test",'
            . '"future":"drop"},{"certificate":{"ecdsa_curve_name":"prime256v1","ecdsa_public":"ef01",'
            . '"key_algorithm":"id-ecPublicKey","modulus":null,"modulus_length":null,"validation_type":null},'
            . '"ip":"2001:db8::1"}]';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame([
            [
                'certificate' => [
                    'auto_ssl_provider' => 'ExampleCA',
                    'auto_ssl_provider_display_name' => 'Example CA',
                    'domains' => ['example.test', 'www.example.test'],
                    'ecdsa_curve_name' => null,
                    'ecdsa_public' => null,
                    'id' => 'synthetic-certificate',
                    'is_autossl' => 1,
                    'is_self_signed' => 0,
                    'issuer.commonName' => 'Example Issuer',
                    'issuer.organizationName' => 'Example Organization',
                    'issuer_text' => 'Synthetic issuer text',
                    'key_algorithm' => 'rsaEncryption',
                    'modulus' => 'abcd',
                    'modulus_length' => 2048,
                    'not_after' => 1800000000,
                    'not_before' => 1700000000,
                    'signature_algorithm' => 'sha256WithRSAEncryption',
                    'subject.commonName' => 'example.test',
                    'subject_text' => 'Synthetic subject text',
                    'validation_type' => 'dv',
                ],
                'certificate_text' => 'PUBLIC CERTIFICATE FIXTURE',
                'docroot' => '/home/exampleuser/public_html',
                'domains' => ['example.test'],
                'fqdns' => ['example.test', 'webmail.example.test'],
                'ip' => '192.0.2.10',
                'is_primary_on_ip' => 0,
                'mail_sni_status' => 1,
                'needs_sni' => 1,
                'servername' => 'example.test',
            ],
            [
                'certificate' => [
                    'ecdsa_curve_name' => 'prime256v1',
                    'ecdsa_public' => 'ef01',
                    'key_algorithm' => 'id-ecPublicKey',
                    'modulus' => null,
                    'modulus_length' => null,
                    'validation_type' => null,
                ],
                'ip' => '2001:db8::1',
            ],
        ], $client->uapi('exampleuser')->ssl()->installedHosts());
        self::assertRequest($http, $route, 'installed_hosts');
    }

    #[DataProvider('routes')]
    public function testRenewalStatusEscapesTheRequiredDomainAsOneQueryValue(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{"will_renew":1}')));

        self::assertSame(['will_renew' => 1], $client->uapi('exampleuser')->ssl()->getAutosslRenewalStatus(
            domain: 'example.test&cpanel.user=other +?#%',
        ));

        self::assertRequest(
            $http,
            $route,
            'get_autossl_renewal_status',
            'domain=example.test%26cpanel.user%3Dother%20%2B%3F%23%25',
        );
    }

    public function testRenewalStatusRequiresAnExplicitDomainBeforeIo(): void
    {
        [$client, $http] = self::client('bridge');

        try {
            // @phpstan-ignore arguments.count (Exercise the public required-argument boundary.)
            $client->uapi('exampleuser')->ssl()->getAutosslRenewalStatus();
            self::fail('The domain must not default to the account username or hostname.');
        } catch (ArgumentCountError) {
            self::assertSame([], $http->getRequests());
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadPreservesTheConfirmedOutcomeAndFreshMetadata(
        string $route,
        string $function,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '0')));
        $account->ssl()->canSslRedirect();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        try {
            self::invoke($account->ssl(), $function);
            self::fail('Malformed SSL payload must fail validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $error->payload);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [
            ['installed_hosts', '[{"certificate":null}]'],
            ['installed_hosts', '[{"certificate":{"issuer.commonName":null}}]'],
            ['installed_hosts', '[{"certificate":{"modulus_length":"2048.5"}}]'],
            ['installed_hosts', '[{"certificate":{"ecdsa_curve_name":1}}]'],
            ['installed_hosts', '[{"certificate":{"not_after":null}}]'],
            ['installed_hosts', '[{"domains":[null]}]'],
            ['installed_hosts', '[{"fqdns":{}}]'],
            ['installed_hosts', '[{"needs_sni":true}]'],
            ['installed_hosts', '[{"certificate_text":null}]'],
            ['get_autossl_excluded_domains', '[{"excluded_domain":null}]'],
            ['get_autossl_problems', '[{"domain":null}]'],
            ['get_autossl_problems', '[{"problem":1}]'],
            ['get_autossl_problems', '[{"time":1700000000}]'],
            ['get_autossl_renewal_status', 'null'],
            ['get_autossl_renewal_status', '[1]'],
        ];
        foreach (['installed_hosts', 'get_autossl_excluded_domains', 'get_autossl_problems'] as $function) {
            foreach (['null', '{}', '[null]'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        foreach (['is_autossl_check_in_progress', 'can_ssl_redirect'] as $function) {
            foreach (['null', '{}', '[]', '"1"', 'true', '2'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        foreach (['has_problems', 'is_active', 'is_excluded', 'will_renew'] as $field) {
            foreach (['null', '"1"', 'true', '2'] as $value) {
                $cases[] = ['get_autossl_renewal_status', '{"' . $field . '":' . $value . '}'];
            }
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as [$function, $payload]) {
                yield $route . ' ' . $function . ' ' . $payload => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('routes')]
    public function testApiAndHttpFailuresReplaceMetadataWithoutRetrying(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->ssl()->getAutosslProblems();
        $successful = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));

        try {
            $account->ssl()->getAutosslProblems();
            self::fail('The unsuccessful UAPI outcome must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($successful, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(['Synthetic unavailable role'], $error->errors());
        }

        $http->addResponse(new Response(503, [], 'Synthetic unavailable service'));
        try {
            $account->ssl()->getAutosslProblems();
            self::fail('An unsuccessful HTTP response must fail.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertSame(1, $successful?->uapiStatus());
        self::assertCount(3, $http->getRequests());
    }

    public function testBridgedSslGroupsKeepTheirAccountAndResponseMetadataIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::envelope('bridge', '1')));
        self::assertSame(1, $first->ssl()->isAutosslCheckInProgress());
        $firstMetadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        self::assertNull($client->whm()->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[{"excluded_domain":"other.test"}]')));

        self::assertSame([['excluded_domain' => 'other.test']], $second->ssl()->getAutosslExcludedDomains());

        self::assertSame($firstMetadata, $first->responseMeta());
        self::assertNotSame($firstMetadata, $second->responseMeta());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=other'
            . '&cpanel.module=SSL&cpanel.function=get_autossl_excluded_domains',
            (string) $request->getUri(),
        );
        self::assertCount(2, $http->getRequests());
    }

    public function testOuterWhmFailureIsCheckedBeforeInnerSslSuccess(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic denied"},'
            . '"data":{"uapi":{"status":1,"data":1}}}'));

        try {
            $account->ssl()->canSslRedirect();
            self::fail('An unsuccessful outer WHM outcome must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct wrapped' => ['direct'];
        yield 'direct bare' => ['bare'];
        yield 'WHM bridge' => ['bridge'];
    }

    /** @return array<mixed>|int */
    private static function invoke(Ssl $ssl, string $function): array|int
    {
        return match ($function) {
            'installed_hosts' => $ssl->installedHosts(),
            'get_autossl_excluded_domains' => $ssl->getAutosslExcludedDomains(),
            'get_autossl_problems' => $ssl->getAutosslProblems(),
            'is_autossl_check_in_progress' => $ssl->isAutosslCheckInProgress(),
            'can_ssl_redirect' => $ssl->canSslRedirect(),
            'get_autossl_renewal_status' => $ssl->getAutosslRenewalStatus(domain: 'example.test'),
            default => throw new LogicException('Unknown SSL test function.'),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        ?string $query = null,
    ): void {
        $query ??= $function === 'get_autossl_renewal_status' ? 'domain=example.test' : '';
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $base = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=SSL&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/SSL/' . $function;
        self::assertSame(
            $base . ($query === '' ? '' : ($route === 'bridge' ? '&' : '?') . $query),
            (string) $request->getUri(),
        );
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ',"data":' . $payload
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic unavailable role"]')
            . ',"warnings":["synthetic notice"]}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"SSL","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
