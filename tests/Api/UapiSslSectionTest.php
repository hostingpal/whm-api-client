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
use Psr\Http\Message\RequestInterface;

/** Independent fixtures transcribed from the pinned SSL operation contracts, never generated specs. */
final class UapiSslSectionTest extends TestCase
{
    /** @param array<string, mixed> $arguments */
    #[DataProvider('successfulCalls')]
    public function testRequestAndDocumentedPayload(
        string $route,
        string $method,
        string $function,
        array $arguments,
        string $verb,
        string $parameters,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $ssl = $account->ssl();
        self::assertTrue(method_exists($ssl, $method), 'Missing SSL method: ' . $method);
        $actual = $ssl->{$method}(...$arguments);
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $actual);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $base = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=SSL&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/SSL/' . $function;
        $suffix = $verb === 'GET' && $parameters !== '' ? ($route === 'bridge' ? '&' : '?') . $parameters : '';
        self::assertSame($base . $suffix, (string) $request->getUri());
        self::assertSame($verb === 'MULTIPART' ? 'POST' : $verb, $request->getMethod());
        if ($verb === 'MULTIPART') {
            $contentType = $request->getHeaderLine('Content-Type');
            self::assertStringStartsWith('multipart/form-data; boundary=', $contentType);
            $boundary = substr($contentType, strlen('multipart/form-data; boundary='));
            $fields = json_decode($parameters, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($fields);
            $body = '';
            foreach ($fields as $name => $value) {
                self::assertIsString($name);
                self::assertIsString($value);
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name
                    . "\"\r\n\r\n" . $value . "\r\n";
            }
            self::assertSame($body . '--' . $boundary . "--\r\n", (string) $request->getBody());
        } else {
            self::assertSame($verb === 'POST' ? $parameters : '', (string) $request->getBody());
        }
        if ($verb === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>, string, string, string}> */
    public static function successfulCalls(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ([...self::cases(), ...self::optionalArguments()] as $index => $case) {
                yield $route . ' ' . $case[1] . ' ' . $index => [$route, ...$case];
            }
            foreach (self::cases() as $case) {
                [$method, $function, $arguments, $verb, $parameters] = $case;
                foreach (self::emptyPayloads($function) as $payload) {
                    yield $route . ' ' . $function . ' empty ' . $payload
                        => [$route, $method, $function, $arguments, $verb, $parameters, $payload];
                }
            }
        }
    }

    /** @return list<array{string, string, array<string, mixed>, string, string, string}> */
    private static function cases(): array
    {
        return [
            [
                'fetchKeyAndCabundleForCertificate',
                'fetch_key_and_cabundle_for_certificate',
                ['certificate' => "fixture certificate\n+&\r\n"],
                'MULTIPART',
                '{"certificate":"fixture certificate\n+&\r\n"}',
                '{"cab":"fixture cab","crt":"fixture crt","domain":"a.test","key":"fixture private value",'
                . '"searched_users":[],"status":1,"statusmsg":"fixture","user":"exampleuser"}',
            ],
            [
                'uploadCert',
                'upload_cert',
                ['crt' => 'fixture certificate +&', 'friendlyName' => 'owned'],
                'MULTIPART',
                '{"crt":"fixture certificate +&","friendly_name":"owned"}',
                '[{"created":123,"domains":[],"ecdsa_curve_name":null,"ecdsa_public":null,'
                . '"friendly_name":"owned","id":"owned","is_self_signed":1,"issuer.commonName":"issuer",'
                . '"modulus":null,"modulus_length":null,"subject.commonName":"a.test","validation_type":null}]',
            ],
            [
                'uploadKey',
                'upload_key',
                ['key' => 'fixture private value +&', 'friendlyName' => 'owned'],
                'MULTIPART',
                '{"key":"fixture private value +&","friendly_name":"owned"}',
                '[{"created":123,"ecdsa_curve_name":"prime256v1","ecdsa_public":"abc",'
                . '"friendly_name":"owned","id":"owned","key_algorithm":"id-ecPublicKey",'
                . '"modulus":null,"modulus_length":null}]',
            ],
            [
                'installSsl',
                'install_ssl',
                ['cert' => 'fixture cert', 'domain' => 'a.test', 'cabundle' => '', 'key' => 'fixture private value',
                    'orderItemId' => '12', 'originatingCertificateId' => '34', 'parentCertExpiry' => 456,
                    'vhostNames' => ['a.test', 'b.test']],
                'POST',
                'cert=fixture%20cert&domain=a.test&cabundle=&key=fixture%20private%20value&order_item_id=12'
                . '&originating_certificate_id=34&parent_cert_expiry=456&vhost_names=a.test&vhost_names=b.test',
                '{"action":"install","cert_id":"owned","domain":"a.test","extra_certificate_domains":[],"html":"",'
                . '"ip":"2001:db8::1","key_id":"owned","message":"fixture","statusmsg":"fixture",'
                . '"user":"exampleuser","warning_domains":[],"working_domains":["a.test"]}',
            ],
            [
                'addAutosslExcludedDomains',
                'add_autossl_excluded_domains',
                ['domains' => 'a.test,b.test'],
                'POST',
                'domains=a.test%2Cb.test',
                'null',
            ],
            [
                'deleteCert',
                'delete_cert',
                ['id' => 'owned-cert'],
                'POST',
                'id=owned-cert',
                '[{"created":123,"domains":[],"id":"owned-cert","is_self_signed":1,'
                . '"issuer.commonName":"issuer","modulus":null,"serial":"0001","subject.commonName":"a.test"}]',
            ],
            [
                'deleteCsr',
                'delete_csr',
                ['friendlyName' => 'owned csr'],
                'POST',
                'friendly_name=owned%20csr',
                '[{"commonName":"a.test","created":123,"domains":[],"ecdsa_curve_name":null,"id":"owned-csr",'
                . '"modulus":null}]',
            ],
            [
                'deleteKey',
                'delete_key',
                ['id' => 'owned-key'],
                'POST',
                'id=owned-key',
                '[{"created":123,"ecdsa_curve_name":"prime256v1","ecdsa_public":"abc",'
                . '"friendly_name":"owned","key_algorithm":"id-ecPublicKey","modulus":null,'
                . '"modulus_length":null}]',
            ],
            [
                'deleteSsl',
                'delete_ssl',
                ['domain' => 'a.test'],
                'POST',
                'domain=a.test',
                '{"opaque":{"flag":false}}',
            ],
            [
                'disableMailSni',
                'disable_mail_sni',
                ['domains' => 'a.test|b.test'],
                'POST',
                'domains=a.test%7Cb.test',
                '{"failed_domains":{"b.test":"fixture error"},"updated_domains":{"a.test":0}}',
            ],
            [
                'enableMailSni',
                'enable_mail_sni',
                ['domains' => 'a.test'],
                'POST',
                'domains=a.test',
                '{"failed_domains":{},"updated_domains":{"a.test":1}}',
            ],
            [
                'fetchBestForDomain',
                'fetch_best_for_domain',
                ['domain' => 'a.test'],
                'POST',
                'domain=a.test',
                '{"cab":"fixture cab","crt":"fixture crt","crt_origin":"exampleuser","domain":"a.test",'
                . '"ip":"192.0.2.1","key":"fixture private value","key_origin":"exampleuser",'
                . '"searched_users":[],"status":0,"statusmsg":"fixture","user":"exampleuser"}',
            ],
            [
                'fetchCertInfo',
                'fetch_cert_info',
                ['friendlyName' => 'owned', 'id' => 'owned-cert'],
                'POST',
                'friendly_name=owned&id=owned-cert',
                '{"cabundle":null,"certificate":"fixture cert","is_self_signed":1,'
                . '"key":"fixture private value","subject.commonName_ip":"192.0.2.1"}',
            ],
            [
                'fetchCertificatesForFqdns',
                'fetch_certificates_for_fqdns',
                ['domains' => 'a.test,b.test'],
                'POST',
                'domains=a.test%2Cb.test',
                '[{"cab":"fixture cab","crt":"fixture crt","domain_is_configured":0,"domains":[],'
                . '"id":"owned","key":"fixture private value","modulus_length":null,"validation_type":"dv",'
                . '"verify_error":"fixture"}]',
            ],
            [
                'findCertificatesForKey',
                'find_certificates_for_key',
                ['id' => 'owned-key'],
                'POST',
                'id=owned-key',
                '[{"created":123,"domains":["a.test"],"id":"owned","is_self_signed":0,'
                . '"issuer.commonName":"issuer","subject.commonName":"a.test","validation_type":null}]',
            ],
            [
                'findCsrsForKey',
                'find_csrs_for_key',
                ['friendlyName' => 'owned'],
                'POST',
                'friendly_name=owned',
                '[{"commonName":"a.test","created":123,"domains":[],"id":"owned","modulus":null}]',
            ],
            [
                'generateCert',
                'generate_cert',
                [
                    'countryName' => 'US',
                    'domains' => 'a.test',
                    'keyId' => 'owned-key',
                    'localityName' => 'City',
                    'organizationName' => 'Org',
                    'stateOrProvinceName' => 'State',
                ],
                'POST',
                'countryName=US&domains=a.test&key_id=owned-key&localityName=City&organizationName=Org'
                . '&stateOrProvinceName=State',
                '{"created":123,"domains":["a.test"],"id":"owned","is_self_signed":1,'
                . '"issuer.commonName":"a.test","not_after":456,"not_before":123,"serial":"001",'
                . '"subject.commonName":"a.test","text":"fixture cert","validation_type":null}',
            ],
            [
                'generateCsr',
                'generate_csr',
                [
                    'countryName' => 'US',
                    'domains' => 'a.test',
                    'keyId' => 'owned-key',
                    'localityName' => 'City',
                    'organizationName' => 'Org',
                    'stateOrProvinceName' => 'State',
                ],
                'POST',
                'countryName=US&domains=a.test&key_id=owned-key&localityName=City&organizationName=Org'
                . '&stateOrProvinceName=State',
                '{"commonName":"a.test","created":123,"domains":[],"ecdsa_curve_name":null,'
                . '"ecdsa_public":null,"id":"owned","modulus":"abc","text":"fixture csr"}',
            ],
            [
                'generateKey',
                'generate_key',
                ['friendlyName' => 'owned', 'keytype' => 'rsa-2048'],
                'POST',
                'friendly_name=owned&keytype=rsa-2048',
                '{"created":123,"ecdsa_curve_name":null,"ecdsa_public":null,"id":"owned",'
                . '"key_algorithm":"rsaEncryption","modulus":"abc","modulus_length":2048,'
                . '"text":"fixture private value"}',
            ],
            [
                'getCabundle',
                'get_cabundle',
                ['cert' => 'fixture +& cert'],
                'GET',
                'cert=fixture%20%2B%26%20cert',
                '{"bundle":"fixture","cab":"fixture cab","domain":"a.test"}',
            ],
            [
                'getCnName',
                'get_cn_name',
                ['domain' => 'a.test', 'service' => 'cpanel', 'addMailSubdomain' => false],
                'GET',
                'domain=a.test&service=cpanel&add_mail_subdomain=0',
                '{"cert_match_method":"future-value","cert_valid_not_after":123,"is_currently_valid":1,'
                . '"is_self_signed":0,"is_wild_card":0,"ssldomain":"a.test","ssldomain_matches_cert":1}',
            ],
            [
                'installedHost',
                'installed_host',
                ['domain' => 'a.test', 'verifyCertificate' => true],
                'GET',
                'domain=a.test&verify_certificate=1',
                '{"certificate":{"domains":[],"id":"owned","is_self_signed":0,"issuer.commonName":"issuer",'
                . '"modulus":null,"modulus_length":null,"subject.commonName":"a.test",'
                . '"verify_error":"fixture"},"host":"a.test"}',
            ],
            [
                'isMailSniSupported',
                'is_mail_sni_supported',
                [],
                'GET',
                '',
                '1',
            ],
            [
                'isSniSupported',
                'is_sni_supported',
                [],
                'GET',
                '',
                '0',
            ],
            [
                'listCerts',
                'list_certs',
                [],
                'GET',
                '',
                '[{"created":123,"domain_is_configured":1,"domains":[],"id":"owned","is_self_signed":0,'
                . '"issuer.commonName":"issuer","modulus_length":null,"not_after":456,"not_before":123,'
                . '"serial":"001","subject.commonName":"a.test","validation_type":null}]',
            ],
            [
                'listCsrs',
                'list_csrs',
                [],
                'GET',
                '',
                '[{"commonName":"a.test","created":123,"domains":[],"ecdsa_curve_name":null,"id":"owned",'
                . '"modulus":null}]',
            ],
            [
                'listKeys',
                'list_keys',
                [],
                'GET',
                '',
                '[{"created":123,"ecdsa_curve_name":"prime256v1","ecdsa_public":"abc",'
                . '"friendly_name":"owned","id":"owned","key_algorithm":"id-ecPublicKey","modulus":null,'
                . '"modulus_length":null}]',
            ],
            [
                'listSslItems',
                'list_ssl_items',
                ['domains' => 'a.test,b.test', 'item' => 'key'],
                'GET',
                'domains=a.test%2Cb.test&item=key',
                '[{"host":"a.test","id":"owned","type":"key"}]',
            ],
            [
                'mailSniStatus',
                'mail_sni_status',
                ['domain' => 'a.test'],
                'GET',
                'domain=a.test',
                '{"enabled":0}',
            ],
            [
                'rebuildMailSniConfig',
                'rebuild_mail_sni_config',
                ['reloadDovecot' => false],
                'POST',
                'reload_dovecot=0',
                '{"success":1}',
            ],
            [
                'rebuildSslDb',
                'rebuildssldb',
                [],
                'POST',
                '',
                '{"opaque":[null,true,12,"fixture"]}',
            ],
            [
                'removeAutosslExcludedDomains',
                'remove_autossl_excluded_domains',
                ['domains' => 'a.test'],
                'POST',
                'domains=a.test',
                'null',
            ],
            [
                'setAutosslExcludedDomains',
                'set_autossl_excluded_domains',
                [],
                'POST',
                '',
                'null',
            ],
            [
                'setCertFriendlyName',
                'set_cert_friendly_name',
                ['friendlyName' => 'owned', 'newFriendlyName' => 'new'],
                'POST',
                'friendly_name=owned&new_friendly_name=new',
                'null',
            ],
            [
                'setCsrFriendlyName',
                'set_csr_friendly_name',
                ['friendlyName' => 'owned', 'id' => 'owned', 'newFriendlyName' => 'new'],
                'POST',
                'new_friendly_name=new&friendly_name=owned&id=owned',
                '{"opaque":false}',
            ],
            [
                'setDefaultKeyType',
                'set_default_key_type',
                ['type' => 'system'],
                'POST',
                'type=system',
                'null',
            ],
            [
                'setKeyFriendlyName',
                'set_key_friendly_name',
                ['friendlyName' => 'owned', 'id' => 'owned', 'newFriendlyName' => 'new'],
                'POST',
                'new_friendly_name=new&friendly_name=owned&id=owned',
                'null',
            ],
            [
                'setPrimarySsl',
                'set_primary_ssl',
                ['servername' => 'a.test'],
                'POST',
                'servername=a.test',
                'null',
            ],
            [
                'showCert',
                'show_cert',
                ['id' => 'owned-cert'],
                'POST',
                'id=owned-cert',
                '{"cert":"fixture cert","details":{"domains":[],"id":"owned","is_self_signed":1,'
                . '"issuer":{"commonName":"issuer","countryName":"US"},"modulus":null,'
                . '"subject":{"commonName":"a.test"},"validation_type":null},"text":"fixture text"}',
            ],
            [
                'showCsr',
                'show_csr',
                ['id' => 'owned-csr'],
                'POST',
                'id=owned-csr',
                '{"csr":"fixture csr","details":{"commonName":"a.test","countryName":"US","created":123,'
                . '"domains":[],"emailAddress":"a@a.test","friendly_name":"owned","id":"owned",'
                . '"key_algorithm":"rsaEncryption","localityName":"City","modulus":null,'
                . '"organizationName":"Org","organizationalUnitName":"Unit","stateOrProvinceName":"State"},'
                . '"text":"fixture text"}',
            ],
            [
                'showKey',
                'show_key',
                ['id' => 'owned-key'],
                'POST',
                'id=owned-key',
                '{"details":{"created":123,"ecdsa_curve_name":"prime256v1","ecdsa_public":"abc","id":"owned",'
                . '"key":"fixture private value","modulus":null,"modulus_length":null},"text":"fixture text"}',
            ],
            [
                'startAutosslCheck',
                'start_autossl_check',
                [],
                'POST',
                '',
                'null',
            ],
            [
                'toggleSslRedirectForDomains',
                'toggle_ssl_redirect_for_domains',
                ['domains' => 'a.test,b.test', 'state' => false],
                'POST',
                'domains=a.test%2Cb.test&state=0',
                '["fixture warning"]',
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedPayloadWithoutLosingOutcome(
        string $route,
        string $method,
        array $arguments,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $ssl = $account->ssl();
        self::assertTrue(method_exists($ssl, $method), 'Missing SSL method: ' . $method);
        try {
            $ssl->{$method}(...$arguments);
            self::fail('A malformed SSL payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::cases() as [$method, $function, $arguments, , , $valid]) {
                if (in_array($function, ['rebuildssldb', 'set_default_key_type'], true)) {
                    continue;
                }
                $payloads = ['true', '"bad"'];
                $nested = self::malformedFields()[$function] ?? null;
                if ($nested !== null) {
                    $payloads[] = $nested;
                }
                if (!in_array($function, ['is_mail_sni_supported', 'is_sni_supported'], true)) {
                    $payloads[] = '123';
                } else {
                    $payloads[] = '2';
                }
                if ($valid !== 'null' && !in_array($function, ['delete_ssl', 'set_csr_friendly_name'], true)) {
                    $payloads[] = 'null';
                }
                foreach ($payloads as $payload) {
                    yield $route . ' ' . $function . ' ' . $payload => [$route, $method, $arguments, $payload];
                }
            }
        }
    }

    /** @return list<string> */
    private static function emptyPayloads(string $function): array
    {
        return match ($function) {
            'is_mail_sni_supported', 'is_sni_supported' => ['0', '1'],
            'delete_cert', 'delete_csr', 'delete_key', 'fetch_certificates_for_fqdns',
            'find_certificates_for_key', 'find_csrs_for_key', 'list_certs', 'list_csrs',
            'list_keys', 'list_ssl_items', 'toggle_ssl_redirect_for_domains', 'upload_cert', 'upload_key' => ['[]'],
            'add_autossl_excluded_domains', 'remove_autossl_excluded_domains',
            'set_autossl_excluded_domains', 'set_cert_friendly_name', 'set_csr_friendly_name',
            'set_key_friendly_name', 'set_primary_ssl', 'delete_ssl', 'start_autossl_check'
                => ['null', '{}', '{"opaque":{"value":null}}'],
            'set_default_key_type' => ['null'],
            'rebuildssldb' => ['null', '[]', '{}', '0', 'false', '"opaque"'],
            default => ['{}'],
        };
    }

    /** @return list<array{string, string, array<string, mixed>, string, string, string}> */
    private static function optionalArguments(): array
    {
        return [
            ['fetchCertInfo', 'fetch_cert_info', ['id' => 'owned'], 'POST', 'id=owned', '{}'],
            ['fetchCertInfo', 'fetch_cert_info', ['friendlyName' => 'owned'], 'POST', 'friendly_name=owned', '{}'],
            ['setCsrFriendlyName', 'set_csr_friendly_name', ['id' => 'owned', 'newFriendlyName' => 'new'],
                'POST', 'new_friendly_name=new&id=owned', 'null'],
            ['setKeyFriendlyName', 'set_key_friendly_name', ['id' => 'owned', 'newFriendlyName' => 'new'],
                'POST', 'new_friendly_name=new&id=owned', 'null'],
            ['setCsrFriendlyName', 'set_csr_friendly_name', ['friendlyName' => 'owned', 'newFriendlyName' => 'new'],
                'POST', 'new_friendly_name=new&friendly_name=owned', 'null'],
            ['setKeyFriendlyName', 'set_key_friendly_name', ['friendlyName' => 'owned', 'newFriendlyName' => 'new'],
                'POST', 'new_friendly_name=new&friendly_name=owned', 'null'],
            [
                'generateCert',
                'generate_cert',
                [
                    'countryName' => 'US',
                    'domains' => 'a.test,b.test',
                    'keyId' => 'owned',
                    'localityName' => 'City',
                    'organizationName' => 'Org',
                    'stateOrProvinceName' => 'State',
                    'emailAddress' => 'a@a.test',
                    'friendlyName' => 'owned',
                    'organizationalUnitName' => '',
                ],
                'POST',
                'countryName=US&domains=a.test%2Cb.test&key_id=owned&localityName=City&organizationName=Org'
                . '&stateOrProvinceName=State&emailAddress=a%40a.test&friendly_name=owned&organizationalUnitName=',
                '{}',
            ],
            [
                'generateCsr',
                'generate_csr',
                [
                    'countryName' => 'US',
                    'domains' => 'a.test,b.test',
                    'keyId' => 'owned',
                    'localityName' => 'City',
                    'organizationName' => 'Org',
                    'stateOrProvinceName' => 'State',
                    'emailAddress' => 'a@a.test',
                    'friendlyName' => 'owned',
                    'organizationalUnitName' => 'Unit',
                ],
                'POST',
                'countryName=US&domains=a.test%2Cb.test&key_id=owned&localityName=City&organizationName=Org'
                . '&stateOrProvinceName=State&emailAddress=a%40a.test&friendly_name=owned&organizationalUnitName=Unit',
                '{}',
            ],
            ['generateKey', 'generate_key', ['keysize' => 2048], 'POST', 'keysize=2048', '{}'],
            ['generateKey', 'generate_key', [], 'POST', '', '{}'],
            ['installedHost', 'installed_host', [], 'GET', '', '{}'],
            ['installedHost', 'installed_host', ['verifyCertificate' => false], 'GET', 'verify_certificate=0', '{}'],
            ['listSslItems', 'list_ssl_items', [], 'GET', '', '[]'],
            ['rebuildMailSniConfig', 'rebuild_mail_sni_config', [], 'POST', '', '{}'],
            ['rebuildMailSniConfig', 'rebuild_mail_sni_config', ['reloadDovecot' => true],
                'POST', 'reload_dovecot=1', '{}'],
            ['setAutosslExcludedDomains', 'set_autossl_excluded_domains', ['domains' => 'a.test,b.test'],
                'POST', 'domains=a.test%2Cb.test', 'null'],
            ['setAutosslExcludedDomains', 'set_autossl_excluded_domains', ['domains' => ''],
                'POST', 'domains=', 'null'],
            ['deleteCert', 'delete_cert', ['friendlyName' => 'owned'], 'POST', 'friendly_name=owned', '[]'],
            ['deleteCsr', 'delete_csr', ['id' => 'owned'], 'POST', 'id=owned', '[]'],
            ['deleteKey', 'delete_key', ['friendlyName' => 'owned'], 'POST', 'friendly_name=owned', '[]'],
            ['showCert', 'show_cert', ['friendlyName' => 'owned'], 'POST', 'friendly_name=owned', '{}'],
            ['showCsr', 'show_csr', ['friendlyName' => 'owned'], 'POST', 'friendly_name=owned', '{}'],
            ['showKey', 'show_key', ['friendlyName' => 'owned'], 'POST', 'friendly_name=owned', '{}'],
            ['findCertificatesForKey', 'find_certificates_for_key', ['friendlyName' => 'owned'],
                'POST', 'friendly_name=owned', '[]'],
            ['findCsrsForKey', 'find_csrs_for_key', ['id' => 'owned'], 'POST', 'id=owned', '[]'],
            ['getCnName', 'get_cn_name', ['domain' => 'a.test', 'service' => 'smtp'],
                'GET', 'domain=a.test&service=smtp', '{}'],
        ];
    }

    /** @return array<string, string> */
    private static function malformedFields(): array
    {
        return [
            'upload_cert' => '[{"is_self_signed":true}]',
            'upload_key' => '[{"modulus_length":"2048"}]',
            'fetch_key_and_cabundle_for_certificate' => '{"key":null}',
            'install_ssl' => '{"working_domains":[false]}',
            'delete_cert' => '[{"is_self_signed":true}]',
            'delete_csr' => '[{"domains":[null]}]',
            'delete_key' => '[{"created":"123"}]',
            'disable_mail_sni' => '{"updated_domains":{"a.test":2}}',
            'enable_mail_sni' => '{"failed_domains":{"a.test":null}}',
            'fetch_best_for_domain' => '{"key":null}',
            'fetch_cert_info' => '{"subject.commonName_ip":false}',
            'fetch_certificates_for_fqdns' => '[{"modulus_length":"2048"}]',
            'find_certificates_for_key' => '[{"issuer.commonName":null}]',
            'find_csrs_for_key' => '[{"created":null}]',
            'generate_cert' => '{"text":false}',
            'generate_csr' => '{"domains":{}}',
            'generate_key' => '{"ecdsa_curve_name":123}',
            'get_cabundle' => '{"cab":null}',
            'get_cn_name' => '{"is_currently_valid":"1"}',
            'installed_host' => '{"certificate":{"modulus_length":false}}',
            'list_certs' => '[{"validation_type":false}]',
            'list_csrs' => '[{"commonName":null}]',
            'list_keys' => '[{"ecdsa_public":12}]',
            'list_ssl_items' => '[{"host":null}]',
            'mail_sni_status' => '{"enabled":true}',
            'rebuild_mail_sni_config' => '{"success":2}',
            'show_cert' => '{"details":{"issuer":{"commonName":false}}}',
            'show_csr' => '{"details":{"created":"123"}}',
            'show_key' => '{"details":{"key":false}}',
            'toggle_ssl_redirect_for_domains' => '[null]',
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('missingPayloads')]
    public function testAbsentDataIsNotExplicitNull(string $route, string $method, array $arguments): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], str_replace('"data":null,', '', self::envelope($route, 'null'))));
        $account = $client->uapi('exampleuser');
        $this->expectException(PayloadMismatchException::class);
        $account->ssl()->{$method}(...$arguments);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function missingPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::cases() as [$method, $function, $arguments]) {
                if ($function !== 'set_default_key_type') {
                    yield $route . ' ' . $function => [$route, $method, $arguments];
                }
            }
        }
    }

    /** @param list<array<string, int|string|null>> $expected */
    #[DataProvider('inventoryWireVariants')]
    public function testObservedInventoryVariants(string $route, string $method, string $payload, array $expected): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $client->uapi('exampleuser')->ssl()->{$method}());
    }

    /** @return iterable<string, array{string, string, string, list<array<string, int|string|null>>}> */
    public static function inventoryWireVariants(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['listKeys' => ['created', 'modulus_length'],
                'listCerts' => ['created', 'not_after', 'not_before', 'modulus_length']] as $method => $fields
            ) {
                foreach ($fields as $field) {
                    yield "$route $method $field"
                        => [$route, $method, '[{"' . $field . '":"2048"}]', [[$field => 2048]]];
                }
                yield "$route $method nullable modulus"
                    => [$route, $method, '[{"modulus_length":null},{}]', [['modulus_length' => null], []]];
            }
            foreach ([0, 1] as $flag) {
                yield "$route exact string flag $flag"
                    => [$route, 'listCerts', '[{"is_self_signed":"' . $flag . '"}]', [['is_self_signed' => $flag]]];
            }
            yield "$route literal dotted nullable field" => [$route, 'listCerts',
                '[{"issuer.organizationName":null},{"issuer.organizationName":"Fixture Organization"},{}]',
                [['issuer.organizationName' => null], ['issuer.organizationName' => 'Fixture Organization'], []]];
        }
    }

    #[DataProvider('invalidInventoryVariants')]
    public function testInventoryCorrectionsRemainNarrow(string $route, string $method, string $payload): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $this->expectException(PayloadMismatchException::class);
        $client->uapi('exampleuser')->ssl()->{$method}();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidInventoryVariants(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['listKeys' => ['created', 'modulus_length'],
                'listCerts' => ['created', 'not_after', 'not_before', 'modulus_length']] as $method => $fields
            ) {
                foreach ($fields as $field) {
                    foreach (['"1.5"', '"1e3"', '"invalid"', 'true', '"999999999999999999999999"'] as $value) {
                        yield "$route $method $field $value"
                            => [$route, $method, '[{"' . $field . '":' . $value . '}]'];
                    }
                    if ($field !== 'modulus_length') {
                        yield "$route $method $field null" => [$route, $method, '[{"' . $field . '":null}]'];
                    }
                }
            }
            foreach (['"2"', '"01"', '"1.0"', 'true', 'null'] as $value) {
                yield "$route invalid flag $value" => [$route, 'listCerts', '[{"is_self_signed":' . $value . '}]'];
            }
            yield "$route organization remains string" => [$route, 'listCerts', '[{"issuer.organizationName":12}]'];
            yield "$route unrelated flag unchanged" => [$route, 'listCerts', '[{"domain_is_configured":"1"}]'];
            yield "$route unrelated issuer unchanged" => [$route, 'listCerts', '[{"issuer.commonName":null}]'];
        }
    }

    private static function envelope(string $route, string $payload): string
    {
        $result = '{"status":1,"data":' . $payload . ',"errors":null,"warnings":null}';
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
        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
