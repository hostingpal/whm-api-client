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
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** @phpstan-type MarketCall Closure(Market): mixed */
final class UapiMarketActionTest extends TestCase
{
    /** @param MarketCall $call */
    #[DataProvider('successfulCalls')]
    public function testSingleFormPostPreservesWireBytesPayloadAndMetadata(
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
        self::assertRequest($http, $route, $function, 'POST', $parameters);
        self::assertCount(1, $http->getRequests());
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
        self::assertSame(['Fixture warning'], $meta->uapiWarnings());
    }

    /** @return array<string, MarketCall> */
    private static function operationCalls(): array
    {
        return [
            'cancel_pending_ssl_certificate' => static fn (Market $c): mixed =>
                $c->cancelPendingSslCertificate('007+%26&ć', 'provider+%26&ć'),
            'create_shopping_cart_non_ssl' => static fn (Market $c): mixed =>
                $c->createShoppingCartNonSsl('token+%26&ć', 'product+%26&ć', 'https://example.test/a?x=+%26&y=雪'),
            'get_build_cart_url' => static fn (Market $c): mixed => $c->getBuildCartUrl('product+%26&ć'),
            'get_license_info' => static fn (Market $c): mixed => $c->getLicenseInfo(),
            'process_ssl_pending_queue' => static fn (Market $c): mixed => $c->processSslPendingQueue(),
            'set_status_of_pending_queue_items' => static fn (Market $c): mixed =>
                // Intentionally exercise an open native string beyond the source PHPDoc enum.
                // @phpstan-ignore argument.type
                $c->setStatusOfPendingQueueItems('007+%26&ć', 'provider+%26&ć', 'future+%26&ć'),
            'set_url_after_checkout' => static fn (Market $c): mixed =>
                $c->setUrlAfterCheckout('token+%26&ć', 0, 'provider+%26&ć', 'https://example.test/a?x=+%26&y=雪'),
            'validate_login_token' => static fn (Market $c): mixed =>
                $c->validateLoginToken('token+%26&ć', 'provider+%26&ć', 'https://example.test/a?x=+%26&y=雪'),
        ];
    }

    /** @return array<string, array{string, string, mixed}> */
    private static function fixtures(): array
    {
        $provider = 'provider=provider%2B%2526%26%C4%87';
        $product = 'product_name=product%2B%2526%26%C4%87';
        $token = 'access_token=token%2B%2526%26%C4%87';
        $url = 'https%3A%2F%2Fexample.test%2Fa%3Fx%3D%2B%2526%26y%3D%E9%9B%AA';
        $cancel = ['created_time' => 1700000001, 'csr' => 'synthetic CSR', 'domains' => ['example.test'],
            'expired' => 0, 'first_poll_time' => 1700000002, 'last_poll_time' => 1700000003,
            'last_status_code' => 'FutureStatus', 'order_id' => '0001', 'order_item_id' => '0002',
            'product_id' => '007', 'provider' => 'provider', 'status' => 'future-state',
            'support_uri' => 'https://example.test/support?x=+%26', 'vhost_names' => []];
        $queue = ['certificate_id' => '0003', 'certificate_pem' => null, 'created_time' => 1700000001,
            'csr' => 'synthetic CSR', 'deleted' => 0, 'domains' => [], 'expired' => 1,
            'first_poll_time' => 1700000002, 'installed' => 0, 'last_poll_time' => 1700000003,
            'last_status_code' => 'FutureStatus', 'last_status_message' => '', 'order_id' => '0001',
            'order_item_id' => '0002', 'originating_certificate_id' => '0004', 'parent_cert_expiry' => 1800000000,
            'product_id' => '007', 'provider' => 'provider', 'status' => 'future-state', 'vhost_names' => ['vhost']];
        return [
            'cancel_pending_ssl_certificate' => ['order_item_id=007%2B%2526%26%C4%87&' . $provider,
                json_encode([$cancel], JSON_THROW_ON_ERROR), [$cancel]],
            'create_shopping_cart_non_ssl' => [$token . '&' . $product . '&url_after_checkout=' . $url,
                '{"checkout_url":"https://example.test/checkout?x=+%26","order_id":"007",'
                . '"order_items_ref":[{"provider_extra":{"values":[null,false,7]},"id":"0001"},{}]}',
                ['checkout_url' => 'https://example.test/checkout?x=+%26', 'order_id' => '007',
                    'order_items_ref' => [['provider_extra' => ['values' => [null, false, 7]], 'id' => '0001'], []]]],
            'get_build_cart_url' => [$product, '{"url":"https://example.test/cpsess-fixture/?x=+%26"}',
                ['url' => 'https://example.test/cpsess-fixture/?x=+%26']],
            'get_license_info' => ['', '{"provider_extra":{"values":[null,false,7]},"license":"007"}',
                ['provider_extra' => ['values' => [null, false, 7]], 'license' => '007']],
            'process_ssl_pending_queue' => ['', json_encode([$queue], JSON_THROW_ON_ERROR), [$queue]],
            'set_status_of_pending_queue_items' => ['order_item_id=007%2B%2526%26%C4%87&' . $provider
                . '&status=future%2B%2526%26%C4%87', '{"error_type":"EntryDoesNotExist","order_item_ids":[0,73]}',
                ['error_type' => 'EntryDoesNotExist', 'order_item_ids' => [0, 73]]],
            'set_url_after_checkout' => [$token . '&order_id=0&' . $provider . '&url_after_checkout=' . $url,
                '{"error_type":null}', ['error_type' => null]],
            'validate_login_token' => ['login_token=token%2B%2526%26%C4%87&' . $provider . '&url_after_login=' . $url,
                '{"access_token":"synthetic access+%26"}', ['access_token' => 'synthetic access+%26']],
        ];
    }

    /** @return iterable<string, array{string, string, MarketCall, string, string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::operationCalls() as $function => $call) {
                [$parameters, $payload, $expected] = self::fixtures()[$function];
                yield "$route $function populated" => [$route, $function, $call, $parameters, $payload, $expected];
                $list = in_array($function, ['cancel_pending_ssl_certificate', 'process_ssl_pending_queue'], true);
                $empty = $list ? '[]' : '{}';
                yield "$route $function empty" => [$route, $function, $call, $parameters, $empty, []];
                if (!$list) {
                    yield "$route $function legacy empty array" => [$route, $function, $call, $parameters, '[]', []];
                } else {
                    yield "$route $function optional row" => [$route, $function, $call, $parameters, '[{}]', [[]]];
                }
                if (in_array($function, ['get_license_info', 'set_status_of_pending_queue_items'], true)) {
                    yield "$route $function explicit null" => [$route, $function, $call, $parameters, 'null', null];
                }
            }
            yield "$route queue certificate available" => [$route, 'process_ssl_pending_queue',
                self::operationCalls()['process_ssl_pending_queue'], '',
                '[{"certificate_pem":"synthetic PEM","deleted":1,"expired":0,"installed":1}]',
                [['certificate_pem' => 'synthetic PEM', 'deleted' => 1, 'expired' => 0, 'installed' => 1]]];
            yield "$route checkout payload error" => [$route, 'set_url_after_checkout',
                self::operationCalls()['set_url_after_checkout'], self::fixtures()['set_url_after_checkout'][0],
                '{"error_type":"ProviderProblem"}', ['error_type' => 'ProviderProblem']];
            foreach ([null, '', 'domain+%26&ć'] as $i => $domain) {
                $suffix = $domain === null ? '' : '&domain=' . ($domain === '' ? '' : 'domain%2B%2526%26%C4%87');
                foreach (['create_shopping_cart_non_ssl', 'get_build_cart_url', 'get_license_info'] as $function) {
                    $call = match ($function) {
                        'create_shopping_cart_non_ssl' => static fn (Market $c): mixed =>
                            $c->createShoppingCartNonSsl('token', 'product', 'url', $domain),
                        'get_build_cart_url' => static fn (Market $c): mixed => $c->getBuildCartUrl('product', $domain),
                        default => static fn (Market $c): mixed => $c->getLicenseInfo($domain),
                    };
                    $parameters = match ($function) {
                        'create_shopping_cart_non_ssl' =>
                            'access_token=token&product_name=product&url_after_checkout=url',
                        'get_build_cart_url' => 'product_name=product',
                        default => '',
                    };
                    $parameters .= $parameters === '' ? ltrim($suffix, '&') : $suffix;
                    yield "$route $function optional domain $i" => [
                        $route, $function, $call, $parameters, '{}', [],
                    ];
                }
            }
            foreach (
                [
                ['007', 'order_item_id=007'],
                [['007'], 'order_item_id=007'],
                [
                ['007', 'a+%26&ć', '007', ''],
                    'order_item_id=007&order_item_id=a%2B%2526%26%C4%87&order_item_id=007&order_item_id='],
                ['', 'order_item_id='],
                ] as $i => [$ids, $fields]
            ) {
                yield "$route repeated ids $i" => [$route, 'set_status_of_pending_queue_items',
                    static fn (Market $c): mixed => $c->setStatusOfPendingQueueItems($ids, 'provider', 'confirmed'),
                    $fields . '&provider=provider&status=confirmed', 'null', null];
            }
        }
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
            'cancel_pending_ssl_certificate' => ['[{"created_time":"1"}]', '[{"csr":null}]',
                '[{"domains":[1]}]', '[{"expired":"0"}]', '[{"expired":false}]', '[{"expired":2}]',
                '[{"first_poll_time":true}]', '[{"last_poll_time":1.5}]', '[{"last_status_code":1}]',
                '[{"order_id":1}]', '[{"order_item_id":1}]', '[{"product_id":1}]', '[{"provider":null}]',
                '[{"status":false}]', '[{"support_uri":false}]', '[{"vhost_names":[null]}]'],
            'create_shopping_cart_non_ssl' => ['{"checkout_url":null}', '{"order_id":7}',
                '{"order_items_ref":{}}', '{"order_items_ref":[1]}', '{"order_items_ref":[null]}'],
            'get_build_cart_url' => ['{"url":false}'],
            'get_license_info' => [],
            'process_ssl_pending_queue' => ['[{"certificate_id":7}]', '[{"certificate_pem":false}]',
                '[{"created_time":"1"}]', '[{"csr":null}]', '[{"deleted":"0"}]', '[{"deleted":false}]',
                '[{"deleted":2}]', '[{"expired":"1"}]', '[{"installed":true}]', '[{"installed":"0"}]',
                '[{"domains":[1]}]', '[{"first_poll_time":false}]', '[{"last_poll_time":"1"}]',
                '[{"last_status_code":null}]', '[{"last_status_message":null}]', '[{"order_id":1}]',
                '[{"order_item_id":1}]', '[{"originating_certificate_id":1}]', '[{"parent_cert_expiry":"1"}]',
                '[{"product_id":1}]', '[{"provider":false}]', '[{"status":null}]', '[{"vhost_names":[1]}]'],
            'set_status_of_pending_queue_items' => ['{"error_type":null}', '{"order_item_ids":["73"]}',
                '{"order_item_ids":[true]}', '{"order_item_ids":[1.5]}', '{"order_item_ids":{}}'],
            'set_url_after_checkout' => ['{"error_type":1}'],
            'validate_login_token' => ['{"access_token":null}', '{"access_token":7}'],
        ];
        foreach (self::routes() as [$route]) {
            foreach (self::operationCalls() as $function => $call) {
                $bad = [null, 'true', '1', '1.5', '"text"'];
                if (!in_array($function, ['get_license_info', 'set_status_of_pending_queue_items'], true)) {
                    $bad[] = 'null';
                }
                $list = in_array($function, ['cancel_pending_ssl_certificate', 'process_ssl_pending_queue'], true);
                array_push($bad, ...($list ? ['{}', '[null]', '[1]', '{"0":{}}'] : ['[{}]']), ...$fields[$function]);
                foreach ($bad as $i => $payload) {
                    yield "$route $function $i" => [$route, $call, $payload];
                }
            }
        }
    }

    /** @param MarketCall $call */
    #[DataProvider('invalidArguments')]
    public function testNativeTypeErrorsPreserveMetadataBeforeIo(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->market()->getProvidersList();
        $meta = $account->responseMeta();
        try {
            $call($account->market());
            self::fail('Native argument binding must reject invalid types.');
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
                yield "$route cancel $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->cancelPendingSslCertificate($bad, 'provider');
                }];
                yield "$route cart $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->createShoppingCartNonSsl($bad, 'product', 'url');
                }];
                yield "$route build $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getBuildCartUrl($bad);
                }];
                yield "$route login $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->validateLoginToken($bad, 'provider', 'url');
                }];
            }
            foreach ([null, false, 7, 1.5] as $i => $bad) {
                yield "$route collection native $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->setStatusOfPendingQueueItems($bad, 'provider', 'confirmed');
                }];
            }
            foreach (['7', null, false, [], 7.0] as $i => $bad) {
                yield "$route checkout integer $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->setUrlAfterCheckout('token', $bad, 'provider', 'url');
                }];
            }
            foreach ([1, false, []] as $i => $bad) {
                yield "$route license optional string $i" => [$route, static function (Market $c) use ($bad): mixed {
                    // @phpstan-ignore argument.type
                    return $c->getLicenseInfo($bad);
                }];
            }
        }
    }

    /** @param array<array-key, mixed> $ids */
    #[DataProvider('invalidCollections')]
    public function testInvalidCollectionsClearMetadataWithoutIo(
        string $route,
        #[SensitiveParameter] array $ids,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->market()->getProvidersList();
        self::assertNotNull($account->responseMeta());
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            // @phpstan-ignore argument.type
            $account->market()->setStatusOfPendingQueueItems($ids, 'provider', 'confirmed');
            self::fail('Invalid repeated fields must fail locally.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($account->responseMeta());
            self::assertStringNotContainsString('private-id', $error->getMessage());
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    $seen = [];
                    self::assertFalse(
                        self::hasSecret($frame['args'] ?? [], 'private-id', $seen),
                        $frame['class'] . '::' . $frame['function'],
                    );
                }
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidCollections(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (
                [
                [], [1 => 'private-id'], ['key' => 'private-id'], [['private-id']],
                ['private-id', null], ['private-id', 7], ['private-id', false], ['private-id', 1.5]] as $i => $ids
            ) {
                yield "$route invalid collection $i" => [$route, $ids];
            }
        }
    }

    public function testPublicSignaturesAndRedaction(): void
    {
        $signatures = [
            'cancelPendingSslCertificate' => [['orderItemId', 'provider'], ['string', 'string'], 'array'],
            'createShoppingCartNonSsl' => [
        ['accessToken', 'productName', 'urlAfterCheckout', 'domain'],
                ['string', 'string', 'string', '?string'], 'array'],
            'getBuildCartUrl' => [['productName', 'domain'], ['string', '?string'], 'array'],
            'getLicenseInfo' => [['domain'], ['?string'], '?array'],
            'processSslPendingQueue' => [[], [], 'array'],
            'setStatusOfPendingQueueItems' => [
            ['orderItemId', 'provider', 'status'],
                ['array|string', 'string', 'string'], '?array'],
            'setUrlAfterCheckout' => [
            ['accessToken', 'orderId', 'provider', 'urlAfterCheckout'],
                ['string', 'int', 'string', 'string'], 'array'],
            'validateLoginToken' => [
            ['loginToken', 'provider', 'urlAfterLogin'],
                ['string', 'string', 'string'], 'array'],
        ];
        foreach ($signatures as $method => [$names, $types, $return]) {
            $reflection = new ReflectionMethod(Market::class, $method);
            self::assertSame($return, (string) $reflection->getReturnType());
            self::assertSame($names, array_map(static fn ($p): string => $p->getName(), $reflection->getParameters()));
            self::assertSame(
                $types,
                array_map(static fn ($p): string => (string) $p->getType(), $reflection->getParameters()),
            );
            foreach ($reflection->getParameters() as $parameter) {
                if (
                    in_array($parameter->getName(), ['accessToken', 'loginToken', 'orderItemId'], true)
                    && $method !== 'cancelPendingSslCertificate'
                ) {
                    self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                }
                if ($parameter->getName() === 'domain') {
                    self::assertTrue($parameter->isDefaultValueAvailable());
                    self::assertNull($parameter->getDefaultValue());
                }
            }
        }
    }

    #[DataProvider('sensitiveFailures')]
    public function testTokensCollectionsAndResponsesAreRedactedFromValueBearingFrames(
        string $route,
        string $method,
        string $failure,
    ): void {
        $secret = 'private-market-marker';
        [$client, $http] = self::client($route);
        $market = $client->uapi('npr')->market();
        $payload = match ($method) {
            'createShoppingCartNonSsl' => '{"order_id":false,"checkout_url":"' . $secret . '"}',
            'validateLoginToken' => '{"access_token":[' . '"' . $secret . '"]}',
            'setUrlAfterCheckout' => '{"error_type":[' . '"' . $secret . '"]}',
            default => '{"error_type":false,"order_item_ids":["' . $secret . '"]}',
        };
        $http->addResponse(match ($failure) {
            'http' => new Response(503, [], $secret),
            'json' => new Response(200, [], $secret),
            'api' => new Response(200, [], self::envelope($route, $payload, 0)),
            default => new Response(200, [], self::envelope($route, $payload)),
        });
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            match ($method) {
                'createShoppingCartNonSsl' => $market->createShoppingCartNonSsl($secret, 'product', 'url'),
                'validateLoginToken' => $market->validateLoginToken($secret, 'provider', 'url'),
                'setUrlAfterCheckout' => $market->setUrlAfterCheckout($secret, 1, 'provider', 'url'),
                default => $market->setStatusOfPendingQueueItems([$secret], 'provider', 'confirmed'),
            };
            self::fail('The synthetic failure must throw.');
        } catch (ApiException $error) {
            $libraryFrames = 0;
            foreach ($error->getTrace() as $frame) {
                if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    continue;
                }
                $libraryFrames++;
                $seen = [];
                self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
            }
            self::assertGreaterThan(0, $libraryFrames);
            self::assertStringNotContainsString($secret, $error->getMessage());
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function sensitiveFailures(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (
                ['createShoppingCartNonSsl', 'validateLoginToken', 'setUrlAfterCheckout',
                'setStatusOfPendingQueueItems'] as $method
            ) {
                foreach (['http', 'json', 'api', 'payload'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }
    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, string $secret, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $secret);
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (str_contains((string) $key, $secret) || self::hasSecret($item, $secret, $seen)) {
                    return true;
                }
            }
        }
        return false;
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
            $uri,
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
