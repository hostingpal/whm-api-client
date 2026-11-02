<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Market;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Literal response shapes reported in review 5725042192; representative values are synthetic.
 *
 * These fixtures are independent of generated specs and are not saved live responses.
 */
final class UapiMarketReadCompatibilityTest extends TestCase
{
    #[DataProvider('compatiblePayloads')]
    public function testReviewedShapesPreserveValuesAndOneGet(
        string $route,
        string $function,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::call($client->uapi('npr')->market(), $function));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $parameters = match ($function) {
            'get_provider_specific_dcv_constraints' => 'provider=synthetic',
            'get_ssl_certificate_if_available' => 'order_item_id=synthetic&provider=synthetic',
            default => '',
        };
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Market&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Market/' . $function;
        self::assertSame(
            $uri . ($parameters === '' ? '' : ($route === 'bridge' ? '&' : '?') . $parameters),
            (string) $request->getUri(),
        );
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(1, $client->uapi('npr')->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function compatiblePayloads(): iterable
    {
        $cases = [
            'quoted license term' => ['get_all_products', '[{"license_term":"12"}]', [['license_term' => 12]]],
            'quoted product id' => ['get_all_products', '[{"product_id":"7"}]', [['product_id' => 7]]],
            'quoted flag zero' => ['get_all_products', '[{"requires_ip":"0"}]', [['requires_ip' => 0]]],
            'quoted flag one' => ['get_all_products', '[{"requires_ip":"1"}]', [['requires_ip' => 1]]],
            'mixed characters' => ['get_provider_specific_dcv_constraints',
                '{"dcv_file_allowed_characters":[0,"0",9,"a","f",0,"a","Z"]}',
                ['dcv_file_allowed_characters' => [0, '0', 9, 'a', 'f', 0, 'a', 'Z']]],
            'nullable action object' => ['get_ssl_certificate_if_available', '{"encrypted_action_urls":null}',
                ['encrypted_action_urls' => null]],
            'nullable status message' => ['get_ssl_certificate_if_available', '{"status_message":null}',
                ['status_message' => null]],
            'unavailable and unknown nulls' => ['get_ssl_certificate_if_available',
                '{"certificate_pem":null,"encrypted_action_urls":null,"status_message":null,'
                    . '"certificate_id":null,"parent_cert_expiry":null,"status_code":"SyntheticPending"}',
                ['certificate_pem' => null, 'encrypted_action_urls' => null,
                    'status_code' => 'SyntheticPending', 'status_message' => null]],
            'absent nullable leaves' => ['get_ssl_certificate_if_available', '{}', []],
            'known nullable strings and opaque extras' => ['get_ssl_certificate_if_available',
                '{"encrypted_action_urls":{"evClickThroughStatus":null,"ovCallbackStatus":"opaque",'
                    . '"synthetic":{"nested":[null,false,1]}},"status_message":""}',
                ['encrypted_action_urls' => ['evClickThroughStatus' => null, 'ovCallbackStatus' => 'opaque',
                    'synthetic' => ['nested' => [null, false, 1]]], 'status_message' => '']],
            'native flags and absent integer leaves' => ['get_all_products', '[{"requires_ip":0},{"requires_ip":1},{}]',
                [['requires_ip' => 0], ['requires_ip' => 1], []]],
        ];
        foreach (['license_term', 'product_id'] as $field) {
            foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $value) {
                foreach ([$value, (string) $value] as $wire) {
                    $cases[$field . ' bounded ' . get_debug_type($wire) . ' ' . $wire] = ['get_all_products',
                        json_encode([[$field => $wire]], JSON_THROW_ON_ERROR), [[$field => $value]]];
                }
            }
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => [$function, $payload, $expected]) {
                yield "$route $name" => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('malformedPayloads')]
    public function testStrictBoundariesKeepFreshConfirmedMetadataAndDoNotRetry(
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[]')));
        $account->market()->getProvidersList();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope('bridge', $payload)));
        $error = null;
        try {
            self::call($account->market(), $function);
        } catch (PayloadMismatchException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(PayloadMismatchException::class, $error);
        self::assertTrue($error->outcomeConfirmed);
        self::assertNotSame($previous, $error->metadata);
        self::assertSame($account->responseMeta(), $error->metadata);
        self::assertSame(1, $error->metadata?->uapiStatus());
        self::assertSame(
            $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $error->payload,
        );
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach (['license_term', 'product_id'] as $field) {
            foreach (
                ['+1', '01', '-0', '1.0', '1e2', ' 1', '1 ', (string) PHP_INT_MAX . '0',
                (string) PHP_INT_MIN . '0', true, false, 1.0, 1.5, null, [], new \stdClass()] as $i => $value
            ) {
                yield "$field $i" => ['get_all_products',
                    json_encode([[$field => $value]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
        }
        foreach (['2', 2, '01', true, false, '', null, 1.0, []] as $i => $value) {
            yield "requires_ip $i" => ['get_all_products', json_encode(
                [['requires_ip' => $value]],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            )];
        }
        foreach (['enabled', 'recommended'] as $field) {
            yield "strict $field" => ['get_all_products', '[{"' . $field . '":"1"}]'];
        }
        foreach (['true', '1.5', 'null', '[]', '{}', '1.0'] as $value) {
            yield "character $value" => ['get_provider_specific_dcv_constraints',
                '{"dcv_file_allowed_characters":[' . $value . ']}'];
        }
        foreach (['null', '{}', '"0"'] as $value) {
            yield "character list $value" => ['get_provider_specific_dcv_constraints',
                '{"dcv_file_allowed_characters":' . $value . '}'];
        }
        foreach (['"opaque"', 'true', '1', '[{}]'] as $value) {
            yield "action object $value" => ['get_ssl_certificate_if_available',
                '{"encrypted_action_urls":' . $value . '}'];
        }
        foreach (['evClickThroughStatus', 'ovCallbackStatus'] as $field) {
            yield "known action $field" => ['get_ssl_certificate_if_available',
                '{"encrypted_action_urls":{"' . $field . '":false}}'];
        }
        foreach (['{}', '[]', '1', 'false'] as $value) {
            yield "status message $value" => ['get_ssl_certificate_if_available', '{"status_message":' . $value . '}'];
        }
        yield 'status code remains nonnull' => ['get_ssl_certificate_if_available', '{"status_code":null}'];
        foreach ([null, 'null', '1', '[{}]'] as $i => $value) {
            yield "availability root $i" => ['get_ssl_certificate_if_available', $value];
        }
    }

    private static function call(Market $market, string $function): mixed
    {
        return match ($function) {
            'get_all_products' => $market->getAllProducts(),
            'get_provider_specific_dcv_constraints' => $market->getProviderSpecificDcvConstraints('synthetic'),
            'get_ssl_certificate_if_available' => $market->getSslCertificateIfAvailable('synthetic', 'synthetic'),
            default => throw new LogicException('Unknown fixture operation.'),
        };
    }

    private static function envelope(string $route, ?string $payload): string
    {
        $result = '{"status":1' . ($payload === null ? '' : ',"data":' . $payload) . ',"errors":null}';
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
