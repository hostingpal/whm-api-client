<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Market;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use TypeError;

/**
 * Independent fixtures from the pinned UAPI Market documentation, not WHM Market contracts.
 *
 * @phpstan-type MarketCall Closure(Market): mixed
 */
final class UapiMarketReadTest extends TestCase
{
    #[DataProvider('routes')]
    public function testAccessorCachesAccountGroupWithoutIo(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        self::assertInstanceOf(Market::class, $account->market());
        self::assertSame($account->market(), $account->market());
        $other = $route === 'bridge' ? $client->uapi('other') : self::client($route)[0]->uapi('npr');
        self::assertNotSame($account->market(), $other->market());
        self::assertCount(0, $http->getRequests());
    }

    /** @param MarketCall $call */
    #[DataProvider('successfulCalls')]
    public function testGetRequestsPreserveLiteralBytesDataAndMetadata(
        string $route,
        string $function,
        Closure $call,
        string $parameters,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $call($account->market()));
        self::assertRequest($http, $route, $function, 'GET', $parameters);
        self::assertCount(1, $http->getRequests());
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
        self::assertSame(['Fixture warning'], $meta->uapiWarnings());
        self::assertArrayNotHasKey('data', $meta->uapi ?? []);
        $account->market();
        self::assertSame($meta, $account->responseMeta());
    }

    /** @return iterable<string, array{string, string, MarketCall, string, string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::operationCalls() as $function => $call) {
                [$parameters, $payload, $expected] = self::fixtures()[$function];
                yield "$route $function populated" => [$route, $function, $call, $parameters, $payload, $expected];
                if ($function !== 'get_login_url') {
                    $empty = in_array($function, ['get_all_products', 'get_providers_list',
                        'get_pending_ssl_certificates'], true)
                        ? '[]' : '{}';
                    yield "$route $function empty" => [$route, $function, $call, $parameters, $empty, []];
                    if ($empty === '{}') {
                        yield "$route $function legacy empty array" => [$route, $function, $call, $parameters,
                            '[]', []];
                    }
                }
            }
            $catalogs = [
                ['[{"x_identity_verification":null,"x_price_per_wildcard_domain":75,'
                    . '"x_price_per_wildcard_domain_maximum":99.5,'
                    . '"x_price_per_wildcard_domain_minimum":"75.00"}]',
                    [['x_identity_verification' => null, 'x_price_per_wildcard_domain' => 75,
                        'x_price_per_wildcard_domain_maximum' => 99.5,
                            'x_price_per_wildcard_domain_minimum' => '75.00']]],
                ['[{"x_identity_verification":{"label":"Company","name":"org","options":[["a","A"],null],'
                    . '"is_optional":true,"nested":{"n":1},"pattern":null}}]',
                    [['x_identity_verification' => ['label' => 'Company', 'name' => 'org',
                        'options' => [['a', 'A'], null], 'is_optional' => true, 'nested' => ['n' => 1],
                            'pattern' => null]]]],
                ['[{"x_identity_verification":[]},{"x_identity_verification":{}},{"price":null,'
                    . '"minimum_server_price":null,"maximum_server_price":null,"max_users":null,'
                    . '"base_name":null},{}]',
                    [
                ['x_identity_verification' => []], ['x_identity_verification' => []],
                        ['base_name' => null, 'max_users' => null, 'maximum_server_price' => null,
                            'minimum_server_price' => null, 'price' => null], []]],
            ];
            foreach ($catalogs as $i => [$payload, $expected]) {
                yield "$route catalog variants $i" => [$route, 'get_all_products',
                    self::operationCalls()['get_all_products'], '', $payload, $expected];
            }
            yield "$route certificate unavailable" => [$route, 'get_ssl_certificate_if_available',
                self::operationCalls()['get_ssl_certificate_if_available'],
                    self::fixtures()['get_ssl_certificate_if_available'][0],
                '{"certificate_pem":null,"encrypted_action_urls":{"evClickThroughStatus":null,'
                    . '"ovCallbackStatus":null}}',
                ['certificate_pem' => null, 'encrypted_action_urls' => ['evClickThroughStatus' => null,
                    'ovCallbackStatus' => null]]];
        }
    }

    /** @param MarketCall $call */
    #[DataProvider('invalidPayloads')]
    public function testKnownFieldAndRootMismatchesRetainConfirmedFreshMetadata(
        string $route,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->market()->getProvidersList();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account->market());
            self::fail('Invalid payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, MarketCall, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $fields = [
            'get_all_products' => ['[{"product_id":"7"}]', '[{"price":"1.25"}]', '[{"base_name":1}]',
                '[{"x_certificate_term":[1]}]', '[{"x_identity_verification":1}]',
                '[{"x_identity_verification":[1]}]', '[{"x_identity_verification":{"label":null}}]',
                '[{"x_identity_verification":[{"name":7}]}]', '[{"x_price_per_wildcard_domain":"free"}]',
                '[{"x_price_per_wildcard_domain_maximum":null}]', '[{"x_price_per_wildcard_domain_minimum":true}]',
                '[{"enabled":"1"}]', '[{"recommended":2}]', '[{"requires_ip":false}]',
                '[{"x_ssl_per_domain_pricing":"0"}]', '[{"x_supports_dns_dcv":true}]',
                '[{"x_wildcard_parent_domain_free":-1}]', '[{"x_warn_after":"30"}]', '[{"max_users":"3"}]',
                '[{"x_price_per_domain":"9.00"}]', '[{"x_max_http_redirects":1.5}]'],
            'get_certificate_status_details' => ['{"actionUrls":{"evClickThroughStatus":null}}',
                '{"domain_details":[] ,"status_details":{"csrStatus":1}}', '{"domain_details":{"status":false}}',
                '{"status_details":null}'],
            'get_completion_url' => ['{"completion_url":null}'],
            'get_login_url' => ['{}', '[]'],
            'get_pending_ssl_certificates' => ['[{"created_time":"123"}]', '[{"expired":"0"}]',
                '[{"expired":2}]', '[{"domains":[1]}]', '[{"vhost_names":[null]}]',
                '[{"identity_verification":[{}]}]', '[{"order_item_id":12}]', '[{"product_id":7}]',
                '[{"certificate_id":null}]', '[{"first_poll_time":false}]', '[{"parent_cert_expiry":"1"}]'],
            'get_product_info' => ['{"product_id":7}', '{"redirect_path":null}', '{"redirect_url_failure":false}'],
            'get_provider_specific_dcv_constraints' => ['{"dcv_max_redirects":"0"}',
                '{"dcv_file_allowed_characters":[1]}', '{"dcv_file_random_character_count":null}'],
            'get_providers_list' => ['[{"name":null}]', '[{"display_name":7}]'],
            'get_ssl_certificate_if_available' => ['{"certificate_pem":7}', '{"encrypted_action_urls":null}',
                '{"encrypted_action_urls":{"evClickThroughStatus":false}}',
                '{"encrypted_action_urls":{"ovCallbackStatus":[]}}', '{"status_code":1}', '{"status_message":null}'],
        ];
        foreach (self::routes() as [$route]) {
            foreach (self::operationCalls() as $function => $call) {
                $bad = [null, 'null', 'true', '1', '1.5'];
                if ($function !== 'get_login_url') {
                    $bad[] = '"text"';
                    array_push($bad, ...$fields[$function]);
                    $isList = in_array($function, ['get_all_products', 'get_providers_list',
                        'get_pending_ssl_certificates'], true);
                    array_push($bad, ...($isList ? ['{}', '[null]', '[1]', '["text"]', '{"0":{}}'] : ['[{}]']));
                } else {
                    array_push($bad, ...$fields[$function]);
                }
                foreach ($bad as $i => $payload) {
                    yield "$route $function $i" => [$route, $call, $payload];
                }
            }
        }
    }

    /** @param MarketCall $call */
    #[DataProvider('invalidArguments')]
    public function testNativeTypesRejectBeforeMetadataResetAndIo(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->market()->getProvidersList();
        $meta = $account->responseMeta();
        try {
            $call($account->market());
            self::fail('Wrong argument types must fail.');
        } catch (TypeError) {
            self::assertSame($meta, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, MarketCall}> */
    public static function invalidArguments(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach ([null, 1, false, []] as $i => $bad) {
                foreach (
                    ['getCompletionUrl', 'getProductInfo', 'getPendingSslCertificates',
                    'getProviderSpecificDcvConstraints'] as $method
                ) {
                    // Intentionally invalid native inputs must fail before metadata reset.
                    // @phpstan-ignore argument.type, argument.type, argument.type, argument.type
                    yield "$route $method $i" => [$route, static fn (Market $c): mixed => $c->$method($bad)];
                }
                yield "$route available string id $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getSslCertificateIfAvailable($bad, 'provider');
                }];
                yield "$route available provider $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getSslCertificateIfAvailable('id', $bad);
                }];
                yield "$route status provider $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getCertificateStatusDetails(1, $bad);
                }];
                yield "$route login provider $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getLoginUrl($bad, 'url');
                }];
                yield "$route login url $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getLoginUrl('provider', $bad);
                }];
            }
            foreach (['7', null, false, [], 7.0] as $i => $bad) {
                yield "$route status integer id $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getCertificateStatusDetails($bad, 'provider');
                }];
            }
        }
    }

    public function testPublicSignaturesRetainSourceArgumentOrderAndDistinctIdTypes(): void
    {
        $signatures = [
            'getAllProducts' => [], 'getCertificateStatusDetails' => ['orderItemId' => 'int', 'provider' => 'string'],
            'getCompletionUrl' => ['productName' => 'string'],
            'getLoginUrl' => ['provider' => 'string', 'urlAfterLogin' => 'string'],
            'getPendingSslCertificates' => ['provider' => 'string'], 'getProductInfo' => ['productName' => 'string'],
            'getProviderSpecificDcvConstraints' => ['provider' => 'string'], 'getProvidersList' => [],
            'getSslCertificateIfAvailable' => ['orderItemId' => 'string', 'provider' => 'string'],
        ];
        foreach ($signatures as $name => $parameters) {
            $method = new ReflectionMethod(Market::class, $name);
            self::assertSame($name === 'getLoginUrl' ? 'string' : 'array', (string) $method->getReturnType());
            self::assertSame(array_keys($parameters), array_map(
                static fn ($p): string => $p->getName(),
                $method->getParameters(),
            ));
            foreach ($method->getParameters() as $parameter) {
                self::assertSame($parameters[$parameter->getName()] ?? null, (string) $parameter->getType());
                self::assertFalse($parameter->isDefaultValueAvailable());
                self::assertSame([], $parameter->getAttributes());
            }
        }
    }

    /** @return array<string, MarketCall> */
    private static function operationCalls(): array
    {
        return [
            'get_all_products' => static fn (Market $c): mixed => $c->getAllProducts(),
            'get_certificate_status_details' => static fn (Market $c): mixed => $c->getCertificateStatusDetails(
                73,
                'Provider+%26&雪',
            ),
            'get_completion_url' => static fn (Market $c): mixed => $c->getCompletionUrl('Product+%26&ć'),
            'get_login_url' => static fn (Market $c): mixed => $c->getLoginUrl(
                'Provider+%26&雪',
                'https://example.test/a?next=%2F&x=+雪',
            ),
            'get_pending_ssl_certificates' => static fn (Market $c): mixed => $c->getPendingSslCertificates(
                'Provider+%26&雪',
            ),
            'get_product_info' => static fn (Market $c): mixed => $c->getProductInfo('Product+%26&ć'),
            'get_provider_specific_dcv_constraints' => static fn (Market $c): mixed =>
                $c->getProviderSpecificDcvConstraints(
                    'Provider+%26&雪',
                ),
            'get_providers_list' => static fn (Market $c): mixed => $c->getProvidersList(),
            'get_ssl_certificate_if_available' => static fn (Market $c): mixed => $c->getSslCertificateIfAvailable(
                '007+%26&ć',
                'Provider+%26&雪',
            ),
        ];
    }

    /** @return array<string, array{string, string, mixed}> */
    private static function fixtures(): array
    {
        $provider = 'provider=Provider%2B%2526%26%E9%9B%AA';
        $product = 'product_name=Product%2B%2526%26%C4%87';
        return [
            'get_all_products' => ['', '[{"base_name":"base","billing_type":"once","description":"Synthetic product",'
                . '"display_name":"Certificate","enabled":0,"icon":"aWNvbg==","icon_mime_type":"image/png",'
                . '"license_term":12,"max_users":4,"maximum_server_price":99.5,"minimum_server_price":1,'
                . '"price":12.25,"price_unit":"USD","product":"cert","product_category":"SSL",'
                . '"product_group":"ssl_certificate","product_id":7,"provider_display_name":"Provider",'
                . '"provider_name":"provider","recommended":1,"requires_ip":0,"x_certificate_term":["1",'
                . '"year"],"x_identity_verification":[{"label":"Company","name":"company",'
                . '"description":"Opaque","is_optional":0,"options":[["a","A"]],"pattern":null,'
                . '"type":"text"}],"x_max_http_redirects":5,"x_payment_trigger":"future-trigger",'
                . '"x_price_per_domain":12,"x_price_per_domain_maximum":99.5,'
                . '"x_price_per_domain_minimum":1,"x_price_per_wildcard_domain":"75.00",'
                . '"x_price_per_wildcard_domain_maximum":"150.00",'
                . '"x_price_per_wildcard_domain_minimum":"99.00","x_ssl_per_domain_pricing":1,'
                . '"x_supports_dns_dcv":0,"x_validation_type":"future-type","x_warn_after":3600,'
                . '"x_wildcard_parent_domain_free":1,"unknown":true}]',
                [['base_name' => 'base', 'billing_type' => 'once', 'description' => 'Synthetic product',
                    'display_name' => 'Certificate',
                    'enabled' => 0, 'icon' => 'aWNvbg==', 'icon_mime_type' => 'image/png', 'license_term' => 12,
                        'max_users' => 4,
                    'maximum_server_price' => 99.5, 'minimum_server_price' => 1, 'price' => 12.25,
                        'price_unit' => 'USD',
                    'product' => 'cert', 'product_category' => 'SSL', 'product_group' => 'ssl_certificate',
                        'product_id' => 7,
                    'provider_display_name' => 'Provider', 'provider_name' => 'provider', 'recommended' => 1,
                        'requires_ip' => 0,
                    'x_certificate_term' => ['1', 'year'], 'x_identity_verification' => [['label' => 'Company',
                        'name' => 'company',
                        'description' => 'Opaque', 'is_optional' => 0, 'options' => [['a', 'A']],
                            'pattern' => null, 'type' => 'text']],
                    'x_max_http_redirects' => 5, 'x_payment_trigger' => 'future-trigger', 'x_price_per_domain' => 12,
                    'x_price_per_domain_maximum' => 99.5, 'x_price_per_domain_minimum' => 1,
                        'x_price_per_wildcard_domain' => '75.00',
                    'x_price_per_wildcard_domain_maximum' => '150.00', 'x_price_per_wildcard_domain_minimum' => '99.00',
                    'x_ssl_per_domain_pricing' => 1, 'x_supports_dns_dcv' => 0, 'x_validation_type' => 'future-type',
                    'x_warn_after' => 3600, 'x_wildcard_parent_domain_free' => 1]]],
            'get_certificate_status_details' => ['order_item_id=73&' . $provider,
                '{"actionUrls":{"evClickThroughStatus":"https://example.test/ev?opaque=+%26",'
                    . '"ovCallbackStatus":"https://example.test/ov"},"domain_details":{"domain":"example.test",'
                    . '"status":"FUTURE"},"status_details":{"brandValStatus":"brand",'
                    . '"certificateStatus":"cert","csrStatus":"csr","dcvStatus":"dcv",'
                    . '"evClickThroughStatus":"ev","freeDVUPStatus":"dv","organizationValidationStatus":"org",'
                    . '"ovCallbackStatus":"ov","validationStatus":"validation"}}',
                ['actionUrls' => ['evClickThroughStatus' => 'https://example.test/ev?opaque=+%26',
                    'ovCallbackStatus' => 'https://example.test/ov'],
                    'domain_details' => ['domain' => 'example.test', 'status' => 'FUTURE'], 'status_details' => [
                        'brandValStatus' => 'brand', 'certificateStatus' => 'cert', 'csrStatus' => 'csr',
                            'dcvStatus' => 'dcv',
                        'evClickThroughStatus' => 'ev', 'freeDVUPStatus' => 'dv',
                            'organizationValidationStatus' => 'org',
                        'ovCallbackStatus' => 'ov', 'validationStatus' => 'validation']]],
            'get_completion_url' => [$product,
                '{"completion_url":"https://example.test/cpsess-fixture/complete?product=+%26"}',
                ['completion_url' => 'https://example.test/cpsess-fixture/complete?product=+%26']],
            'get_login_url' => [$provider . '&url_after_login=https%3A%2F%2Fexample.test%2Fa'
                . '%3Fnext%3D%252F%26x%3D%2B%E9%9B%AA',
                '"https://example.test/login?opaque=+%26"', 'https://example.test/login?opaque=+%26'],
            'get_pending_ssl_certificates' => [$provider, '[{"created_time":1700000001,'
                . '"csr":"-----BEGIN CERTIFICATE REQUEST-----\\nfixture\\n-----END CERTIFICATE REQUEST-----'
                . '","domains":["example.test","www.example.test"],"expired":0,'
                    . '"first_poll_time":1700000002,"identity_verification":{"company":"Fixture",'
                    . '"extra":{"v":[false,null,7]}},"certificate_id":"0003","last_poll_time":1700000003,'
                    . '"last_status_code":"FutureStatus","order_id":"0001","order_item_id":"0002",'
                    . '"originating_certificate_id":"0004","parent_cert_expiry":1800000000,"product_id":"007",'
                    . '"provider":"provider","status":"future-state",'
                    . '"support_uri":"https://example.test/support","vhost_names":[]}]',
                [['certificate_id' => '0003', 'created_time' => 1700000001,
                    'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nfixture\n-----END CERTIFICATE REQUEST-----",
                    'domains' => ['example.test', 'www.example.test'], 'expired' => 0, 'first_poll_time' => 1700000002,
                    'identity_verification' => ['company' => 'Fixture', 'extra' => ['v' => [false, null, 7]]],
                    'last_poll_time' => 1700000003, 'last_status_code' => 'FutureStatus',
                    'order_id' => '0001', 'order_item_id' => '0002', 'originating_certificate_id' => '0004',
                    'parent_cert_expiry' => 1800000000, 'product_id' => '007', 'provider' => 'provider',
                        'status' => 'future-state',
                    'support_uri' => 'https://example.test/support', 'vhost_names' => []]]],
            'get_product_info' => [$product, '{"product_id":"007","redirect_path":"/frontend/fixture",'
                . '"redirect_url_failure":"https://example.test/failure?token=+%26",'
                . '"redirect_url_success":"https://example.test/success?token=+%26"}',
                ['product_id' => '007', 'redirect_path' => '/frontend/fixture',
                    'redirect_url_failure' => 'https://example.test/failure?token=+%26',
                    'redirect_url_success' => 'https://example.test/success?token=+%26']],
            'get_provider_specific_dcv_constraints' => [$provider,
                '{"dcv_file_allowed_characters":["a","Z","0"],"dcv_file_extension":"txt",'
                . '"dcv_file_random_character_count":32,'
                . '"dcv_file_relative_path":".well-known/pki-validation","dcv_max_redirects":0,'
                . '"dcv_user_agent_string":"Fixture/1"}',
                ['dcv_file_allowed_characters' => ['a', 'Z', '0'], 'dcv_file_extension' => 'txt',
                    'dcv_file_random_character_count' => 32,
                    'dcv_file_relative_path' => '.well-known/pki-validation', 'dcv_max_redirects' => 0,
                        'dcv_user_agent_string' => 'Fixture/1']],
            'get_providers_list' => ['', '[{"display_name":"Fixture Provider","name":"provider"},{}]',
                [['display_name' => 'Fixture Provider', 'name' => 'provider'], []]],
            'get_ssl_certificate_if_available' => ['order_item_id=007%2B%2526%26%C4%87&' . $provider,
                '{"certificate_pem":"-----BEGIN CERTIFICATE-----\\nfixture\\n-----END CERTIFICATE-----",'
                    . '"encrypted_action_urls":{"evClickThroughStatus":"opaque+%26","ovCallbackStatus":"other",'
                    . '"future":{"nested":[null,false,1]}},"status_code":"FutureCode","status_message":""}',
                ['certificate_pem' => "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----",
                    'encrypted_action_urls' => [
                    'evClickThroughStatus' => 'opaque+%26', 'ovCallbackStatus' => 'other',
                        'future' => ['nested' => [null, false, 1]]],
                    'status_code' => 'FutureCode', 'status_message' => '']],
        ];
    }

    /** @param MarketCall $call */
    #[DataProvider('operationRoutes')]
    public function testApiFailurePrecedesPayloadAndReplacesMetadata(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->market()->getProvidersList();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
        try {
            $call($account->market());
            self::fail('Status zero must raise before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param MarketCall $call */
    #[DataProvider('bridgeOperations')]
    public function testOuterBridgeFailureWinsOverUapiSuccess(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->market()->getProvidersList();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Outer failure"},'
            . '"data":{"uapi":{"status":1,"data":null}}}'));
        try {
            $call($account->market());
            self::fail('Outer WHM failure must win.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Outer failure'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param MarketCall $call */
    #[DataProvider('operationRoutes')]
    public function testHttpAndTransportFailureClearMetadataWithoutRetry(string $route, Closure $call): void
    {
        foreach (['http', 'transport'] as $failure) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
            $account->market()->getProvidersList();
            self::assertNotNull($account->responseMeta());
            if ($failure === 'http') {
                $http->addResponse(new Response(503, [], 'unavailable'));
            } else {
                $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
                });
            }
            try {
                $call($account->market());
                self::fail('A failed request must throw.');
            } catch (ApiException $error) {
                self::assertSame($failure === 'transport', $error instanceof TransportException);
                self::assertNull($error->metadata);
                self::assertNull($account->responseMeta());
            }
            self::assertCount(2, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, MarketCall}> */
    public static function operationRoutes(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::operationCalls() as $function => $call) {
                yield "$route $function" => [$route, $call];
            }
        }
    }

    /** @return iterable<string, array{string, MarketCall}> */
    public static function bridgeOperations(): iterable
    {
        foreach (self::operationCalls() as $function => $call) {
            yield $function => ['bridge', $call];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Market&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Market/' . $function;
        self::assertSame(
            $uri . ($parameters === '' ? '' : ($route === 'bridge' ? '&' : '?') . $parameters),
            (string) $request->getUri(),
        );
        self::assertSame($method, $request->getMethod());
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
            . ',"warnings":["Fixture warning"],"messages":null,"metadata":{}}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
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
