<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Market;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;

/** Independent source-reviewed literal contracts; no fixtures read generated specs or overlays. */
final class WhmMarketTest extends TestCase
{
    protected function setUp(): void
    {
        [$client] = self::client();
        self::assertContains('market', get_class_methods($client->whm()));
    }

    public function testPinnedTwelveProductExampleRetainsSourceTypesAndAdditionalFields(): void
    {
        $payload = file_get_contents(__DIR__ . '/../Fixtures/whm-market-products-pinned.json');
        self::assertIsString($payload);
        $expected = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);
        self::assertIsArray($expected['products']);
        self::assertCount(12, $expected['products']);
        $nullIdentityLists = 0;
        foreach ($expected['products'] as $row) {
            self::assertIsArray($row);
            if ($row['x_identity_verification'] === null) {
                $nullIdentityLists++;
            }
            foreach (['x_price_per_domain', 'x_price_per_domain_maximum', 'x_price_per_domain_minimum'] as $field) {
                self::assertIsInt($row[$field]);
            }
            self::assertArrayHasKey('base_name', $row);
            self::assertArrayHasKey('license_term', $row);
        }
        self::assertSame(4, $nullIdentityLists);
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('get_market_providers_products', $payload)));
        foreach ($expected['products'] as &$row) {
            self::assertIsArray($row);
            self::assertContains($row['requires_ip'], ['0', '1']);
            $row['requires_ip'] = $row['requires_ip'] === '1' ? 1 : 0;
        }
        unset($row);
        self::assertSame(self::canonical($expected), self::canonical($client->whm()->market()->getProvidersProducts()));
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testExactRequestsAndPopulatedPayloads(
        string $function,
        string $method,
        array $arguments,
        string $verb,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        self::assertSame(
            self::canonical($payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR)),
            self::canonical($client->whm()->market()->{$method}(...$arguments)),
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame($function, $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string}> */
    public static function operations(): iterable
    {
        yield 'disable provider' => ['disable_market_provider', 'disableProvider', ['a +&=%/雪'], 'POST',
            'name=a%20%2B%26%3D%25%2F%E9%9B%AA', null];
        yield 'enable provider' => ['enable_market_provider', 'enableProvider', ['provider'], 'POST',
            'name=provider', null];
        yield 'adjusted prices and opaque extensions' => ['get_adjusted_market_providers_products',
            'getAdjustedProvidersProducts', [], 'POST', '', '{"products":[{"base_name":"base",'
                . '"billing_type":"future interval","description":"product description","display_name":"Product",'
                . '"enabled":0,"icon":"base64","icon_mime_type":"image/svg+xml","license_term":0,"max_users":null,'
                . '"maximum_server_price":null,"minimum_server_price":0,"price":6.25,"price_unit":"USD",'
                . '"product":"Product","product_category":null,"product_group":"future group","product_id":123,'
                . '"provider_display_name":"Provider","provider_name":"provider","recommended":1,"requires_ip":0,'
                . '"x_future":{"nested":[null,false,0,"0"]},"x_null":null,"vendor_future":"opaque"},{}]}'];
        yield 'commission config' => ['get_market_providers_commission_config', 'getProvidersCommissionConfig', [],
            'GET', '', '{"payload":[{"local_commission_id":"local@example.test","provider_display_name":"Provider",'
                . '"provider_name":"provider","remote_commission_id":"remote@example.test"},{}]}'];
        yield 'nullable commission identifiers' => ['get_market_providers_commission_config',
            'getProvidersCommissionConfig', [], 'GET', '',
            '{"payload":[{"local_commission_id":null,"remote_commission_id":null}]}'];
        yield 'extensions and nullable category' => ['get_market_providers_products', 'getProvidersProducts', [],
            'POST', '', '{"products":[{"base_name":"base","license_term":0,"product_category":null,'
                . '"x_future":{"zero":0},'
                . '"x_price_per_domain":0,"x_price_per_domain_maximum":"0","x_price_per_domain_minimum":""}]}'];
        yield 'provider list' => ['get_market_providers_list', 'getProvidersList', [], 'GET', '',
            '{"payload":[{"display_name":"Provider","enabled":0,"even_commission_divisor":6,"name":"provider",'
                . '"supports_commission":1},{"supports_commission":0}]}'];
        yield 'metadata includes base and SSL alternatives' => ['get_market_providers_product_metadata',
            'getProvidersProductMetadata', [], 'POST', '', '{"product_metadata":[{"attributes":{'
                . '"display_name":{"read_only":1},"enabled":{"read_only":0},"icon":{"read_only":1},'
                . '"icon_mime_type":{"read_only":0},"minimum_server_price":{"read_only":1},"price":{"read_only":0},'
                . '"price_unit":{"read_only":1},"product_description":{"read_only":0},"product_group":{"read_only":1},'
                . '"product_id":{"read_only":0},"recommended":{"read_only":1},'
                . '"x_identity_verification":{"read_only":0},"x_max_http_redirects":{"read_only":1},'
                . '"x_price_per_domain":{"read_only":0},"x_price_per_domain_maximum":{"read_only":1},'
                . '"x_price_per_domain_minimum":{"read_only":0},"x_price_per_wildcard_domain":{"read_only":1},'
                . '"x_ssl_per_domain_pricing":{"read_only":0},"x_supports_dns_dcv":{"read_only":1},'
                . '"x_warn_after":{"read_only":0},"x_wildcard_parent_domain_free":{"read_only":1}},'
                . '"product_id":"00123","provider_display_name":"Provider","provider_name":"provider"},{}]}'];
        yield 'products preserve string prices and SSL-specific fields' => ['get_market_providers_products',
            'getProvidersProducts', [], 'POST', '', '{"products":[{"billing_type":"future interval",'
                . '"description":"Description","display_name":"Product","icon":"base64",'
                . '"icon_mime_type":"image/svg+xml",'
                . '"max_users":null,"maximum_server_price":null,"minimum_server_price":"0.00","price":"6.25",'
                . '"price_unit":"USD","product":"Product","product_category":"Category",'
                . '"product_group":"ssl_certificate",'
                . '"product_id":"00123","provider_display_name":"Provider","provider_name":"provider",'
                . '"x_certificate_term":["2","year"],"x_identity_verification":[{'
                . '"description":"Field description","is_optional":1,"label":"Label",'
                . '"name":"name","options":[["value","Label"],[]],"pattern":".*","type":"future input"},{}],'
                . '"x_max_http_redirects":0,"x_payment_trigger":"future trigger","x_price_per_domain":"6.25",'
                . '"x_price_per_domain_maximum":"10.00","x_price_per_domain_minimum":"0.00",'
                . '"x_price_per_wildcard_domain":"5.00","x_price_per_wildcard_domain_maximum":"20.00",'
                . '"x_price_per_wildcard_domain_minimum":"0.00","x_ssl_per_domain_pricing":1,"x_supports_dns_dcv":0,'
                . '"x_validation_type":"future validation","x_warn_after":60,"x_wildcard_parent_domain_free":0},{}]}'];
        yield 'set attribute exact strings' => ['set_market_product_attribute', 'setProductAttribute',
            ['provider', '00123', 'future_attribute', 'a +&=%/雪'], 'POST',
            'provider=provider&product_id=00123&attribute=future_attribute&value=a%20%2B%26%3D%25%2F%E9%9B%AA', null];
        yield 'commission identifier' => ['set_market_provider_commission_id', 'setProviderCommissionId',
            ['provider', 'a+tag@example.test'], 'POST', 'provider=provider&commission_id=a%2Btag%40example.test', null];
        yield 'zero attribute value remains a string' => ['set_market_product_attribute', 'setProductAttribute',
            ['provider', '0', 'price', '0'], 'POST', 'provider=provider&product_id=0&attribute=price&value=0', null];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiErrorsPrecedePayloadValidationAndNeverRetry(
        string $function,
        string $method,
        array $arguments,
        string $verb,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, 'false', 0)));
        try {
            $client->whm()->market()->{$method}(...$arguments);
            self::fail('Failed API outcome expected.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($error->metadata, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function sparsePayloads(): iterable
    {
        foreach (
            ['getAdjustedProvidersProducts' => 'products', 'getProvidersCommissionConfig' => 'payload',
            'getProvidersList' => 'payload', 'getProvidersProductMetadata' => 'product_metadata',
            'getProvidersProducts' => 'products'] as $method => $field
        ) {
            yield "$method omitted" => [$method, '{}'];
            yield "$method empty list" => [$method, '{"' . $field . '":[]}'];
            yield "$method sparse row" => [$method, '{"' . $field . '":[{}]}'];
            yield "$method empty array object compatibility" => [$method, '[]'];
            yield "$method empty array row compatibility" => [$method, '{"' . $field . '":[[]]}'];
        }
        yield 'empty attributes compatibility' => ['getProvidersProductMetadata',
            '{"product_metadata":[{"attributes":[]}]}'];
    }

    #[DataProvider('sparsePayloads')]
    public function testOptionalFieldsAndEmptyListsRemainDistinct(string $method, string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('synthetic', $payload)));
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $client->whm()->market()->{$method}());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach (
            ['getAdjustedProvidersProducts' => 'products', 'getProvidersCommissionConfig' => 'payload',
            'getProvidersList' => 'payload', 'getProvidersProductMetadata' => 'product_metadata',
            'getProvidersProducts' => 'products'] as $method => $field
        ) {
            foreach ([null, 'null', 'false', '0', '1.5', '"text"', '[1]'] as $i => $bad) {
                yield "$method root $i" => [$method, $bad];
            }
            foreach (['null', 'false', '{}', '"list"', '[null]', '[false]', '[[1]]'] as $bad) {
                yield "$method list $bad" => [$method, '{"' . $field . '":' . $bad . '}'];
            }
        }
        foreach (['display_name', 'name'] as $field) {
            yield "provider $field" => ['getProvidersList', '{"payload":[{"' . $field . '":1}]}'];
        }
        yield 'provider divisor string' => ['getProvidersList', '{"payload":[{"even_commission_divisor":"6"}]}'];
        foreach (['provider_name', 'provider_display_name'] as $field) {
            yield "commission $field" => ['getProvidersCommissionConfig', '{"payload":[{"' . $field . '":null}]}'];
        }
        foreach (['local_commission_id', 'remote_commission_id'] as $field) {
            yield "commission integer $field" => ['getProvidersCommissionConfig', '{"payload":[{"' . $field . '":0}]}'];
        }
        foreach (['x_price_per_domain', 'x_price_per_domain_maximum', 'x_price_per_domain_minimum'] as $field) {
            foreach (['null', '1.5', '[]', '{}'] as $bad) {
                yield "price neighbor $field $bad" => ['getProvidersProducts',
                    '{"products":[{"' . $field . '":' . $bad . '}]}'];
            }
        }
        foreach (['product_id', 'provider_name', 'provider_display_name'] as $field) {
            yield "metadata $field" => ['getProvidersProductMetadata', '{"product_metadata":[{"' . $field . '":1}]}'];
        }
        foreach (['null', 'false', '[1]', '1', '"metadata"'] as $bad) {
            yield "metadata attributes $bad" => ['getProvidersProductMetadata',
                '{"product_metadata":[{"attributes":' . $bad . '}]}'];
        }
        foreach (
            ['base_name', 'billing_type', 'description', 'display_name', 'icon', 'icon_mime_type', 'price_unit',
            'product', 'product_group', 'provider_name', 'provider_display_name'] as $field
        ) {
            yield "adjusted string $field" => ['getAdjustedProvidersProducts', '{"products":[{"' . $field . '":1}]}'];
        }
        foreach (['license_term', 'product_id', 'max_users'] as $field) {
            yield "adjusted integer $field" => ['getAdjustedProvidersProducts',
                '{"products":[{"' . $field . '":"1"}]}'];
        }
        foreach (['maximum_server_price', 'minimum_server_price', 'price'] as $field) {
            yield "adjusted number $field" => ['getAdjustedProvidersProducts',
                '{"products":[{"' . $field . '":"1.25"}]}'];
            yield "regular string $field" => ['getProvidersProducts', '{"products":[{"' . $field . '":1.25}]}'];
        }
        yield 'adjusted category boolean' => ['getAdjustedProvidersProducts',
            '{"products":[{"product_category":false}]}'];
        foreach (
            ['billing_type', 'description', 'display_name', 'icon', 'icon_mime_type', 'price_unit', 'product',
            'product_category', 'product_group', 'product_id', 'provider_name', 'provider_display_name',
            'x_payment_trigger', 'x_price_per_domain', 'x_price_per_domain_maximum',
                'x_price_per_domain_minimum',
            'x_price_per_wildcard_domain', 'x_price_per_wildcard_domain_maximum', 'x_price_per_wildcard_domain_minimum',
            'x_validation_type'] as $field
        ) {
            yield "regular string $field" => ['getProvidersProducts', '{"products":[{"' . $field . '":false}]}'];
        }
        foreach (['max_users', 'x_max_http_redirects', 'x_warn_after'] as $field) {
            yield "regular integer $field" => ['getProvidersProducts', '{"products":[{"' . $field . '":"1"}]}'];
        }
        foreach (['true', 'false', '1.0', '2', '-1', 'null', '[]', '{}', '"00"'] as $bad) {
            yield "requires_ip $bad" => ['getProvidersProducts', '{"products":[{"requires_ip":' . $bad . '}]}'];
        }
        foreach (['null', '{}', '[1]'] as $bad) {
            yield "certificate term $bad" => ['getProvidersProducts',
                '{"products":[{"x_certificate_term":' . $bad . '}]}'];
            if ($bad === 'null') {
                continue;
            }
            yield "identity list $bad" => ['getProvidersProducts',
                '{"products":[{"x_identity_verification":' . $bad . '}]}'];
        }
        foreach (
            ['description' => '1', 'is_optional' => '"1"', 'label' => '1', 'name' => 'false', 'pattern' => 'null',
            'type' => '[]',
            'options' => '[[1]]'] as $field => $bad
        ) {
            yield "identity leaf $field" => ['getProvidersProducts', '{"products":[{"x_identity_verification":[{"'
                . $field . '":' . $bad . '}]}]}'];
        }
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedRootSiblingAndNestedLeavesFailWithoutRetry(string $method, ?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('synthetic', $payload)));
        try {
            $client->whm()->market()->{$method}();
            self::fail('Malformed source contract must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($error->metadata, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedFlags(): iterable
    {
        foreach (['"0"', '"1"', 'true', 'false', '1.0', '2', '-1', 'null', '[]', '{}'] as $bad) {
            foreach (['enabled', 'supports_commission'] as $field) {
                yield "provider $field $bad" => ['getProvidersList', '{"payload":[{"' . $field . '":' . $bad . '}]}'];
            }
            foreach (['enabled', 'recommended', 'requires_ip'] as $field) {
                yield "adjusted $field $bad" => ['getAdjustedProvidersProducts',
                    '{"products":[{"' . $field . '":' . $bad . '}]}'];
            }
            foreach (['x_ssl_per_domain_pricing', 'x_supports_dns_dcv', 'x_wildcard_parent_domain_free'] as $field) {
                yield "SSL $field $bad" => ['getProvidersProducts', '{"products":[{"' . $field . '":' . $bad . '}]}'];
            }
            foreach (
                ['display_name', 'enabled', 'icon', 'icon_mime_type', 'minimum_server_price', 'price', 'price_unit',
                'product_description', 'product_group', 'product_id', 'recommended', 'x_identity_verification',
                'x_max_http_redirects', 'x_price_per_domain', 'x_price_per_domain_maximum',
                'x_price_per_domain_minimum',
                'x_price_per_wildcard_domain', 'x_ssl_per_domain_pricing', 'x_supports_dns_dcv', 'x_warn_after',
                'x_wildcard_parent_domain_free'] as $field
            ) {
                yield "metadata $field $bad" => ['getProvidersProductMetadata', '{"product_metadata":[{"attributes":{"'
                    . $field . '":{"read_only":' . $bad . '}}}]}'];
            }
        }
    }

    #[DataProvider('malformedFlags')]
    public function testIntegerFlagsRejectCoercion(string $method, string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('synthetic', $payload)));
        $this->expectException(PayloadMismatchException::class);
        try {
            $client->whm()->market()->{$method}();
        } finally {
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testQuotedRequiresIpFlagsNormalizeNarrowly(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'get_market_providers_products',
            '{"products":[{"requires_ip":"0"},{"requires_ip":"1"},{"requires_ip":0},{"requires_ip":1}]}',
        )));
        self::assertSame(['products' => [
        ['requires_ip' => 0], ['requires_ip' => 1], ['requires_ip' => 0],
            ['requires_ip' => 1]]], $client->whm()->market()->getProvidersProducts());
    }

    public function testNullableValuesAndNumericKindsRemainDistinct(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('synthetic', '{"products":[{"max_users":0,'
            . '"maximum_server_price":0.0,"minimum_server_price":null,"price":null,"product_category":""},{}]}')));
        self::assertSame(['products' => [['max_users' => 0, 'maximum_server_price' => 0.0,
            'minimum_server_price' => null,
            'price' => null, 'product_category' => ''], []]], $client->whm()->market()->getAdjustedProvidersProducts());
        $http->addResponse(new Response(200, [], self::envelope('synthetic', '{"products":[{"max_users":0,'
            . '"maximum_server_price":"0.00","minimum_server_price":null,"price":null},{}]}')));
        self::assertSame(['products' => [['max_users' => 0, 'maximum_server_price' => '0.00',
            'minimum_server_price' => null,
            'price' => null], []]], $client->whm()->market()->getProvidersProducts());
    }

    public function testSelectorSensitivityAndRequiredArguments(): void
    {
        [$client, $http] = self::client();
        $group = $client->whm()->market();
        self::assertInstanceOf(Market::class, $group);
        self::assertSame($group, $client->whm()->market());
        self::assertSame([], $http->getRequests());
        self::assertNull($client->whm()->responseMeta());
        self::assertCount(1, (new ReflectionParameter([$group, 'setProductAttribute'], 'value'))
            ->getAttributes(SensitiveParameter::class));
        foreach (
            ['disableProvider' => 1, 'enableProvider' => 1, 'getAdjustedProvidersProducts' => 0,
            'getProvidersCommissionConfig' => 0, 'getProvidersList' => 0, 'getProvidersProductMetadata' => 0,
            'getProvidersProducts' => 0, 'setProductAttribute' => 4,
            'setProviderCommissionId' => 2] as $method => $count
        ) {
            self::assertSame($count, (new ReflectionMethod($group, $method))->getNumberOfRequiredParameters());
        }
    }

    public function testNextAttemptClearsPriorMetadataOnHttpFailure(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('get_market_providers_list', '{"payload":[]}')));
        $client->whm()->market()->getProvidersList();
        $snapshot = $client->whm()->responseMeta();
        self::assertSame(1, $snapshot?->whmResult());
        $http->addResponse(new Response(503, [], 'unavailable'));
        try {
            $client->whm()->market()->disableProvider('provider');
            self::fail('HTTP failure expected.');
        } catch (HttpException) {
            self::assertNull($client->whm()->responseMeta());
            self::assertSame(1, $snapshot->whmResult());
        }
        self::assertCount(2, $http->getRequests());
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }

    private static function envelope(string $function, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $function . '","result":' . $result . ',"reason":"Synthetic"}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
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
