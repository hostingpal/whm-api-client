<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\JsonBody;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RepeatedList;
use HostingPal\WhmApiClient\Api\SuffixNumberedList;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Config\ClientConfigFactory;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use TypeError;

final class RawJsonCallTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function routes(): iterable
    {
        yield 'WHM' => [false];
        yield 'direct UAPI' => [true];
    }

    #[DataProvider('routes')]
    public function testLiteralRequestKeepsRawBytesAndRouting(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $handle = $direct ? $client->uapi() : $client->whm();
        $http->addResponse(self::success($direct));
        $json = " \n{\"duplicate\":1,\"duplicate\":2,\"big\":123456789012345678901234567890,\"decimal\":1.000,"
            . '"escaped":"a\\u0000\\/b","\\u0000start":null,"0":0,"01":1,"nested":{"api.version":null}}' . "\t\n";
        $query = [
            'space &/%' => 'a +%/', 'repeat' => new RepeatedList(['one', 'two']),
            'domain' => new NumberedList(['first', 'second']), 'action*' => new SuffixNumberedList(['a', 'b']),
        ];
        $result = $direct ? $client->uapi()->callJson('Example', 'operation', $json, $query)
            : $client->whm()->callJson('Example/operation', $json, $query);
        self::assertSame(['ok' => true], $result->data);
        self::assertTrue($result->hasData);
        self::assertSame($result->metadata, $handle->responseMeta());
        self::assertCount(1, $http->getRequests());
        $request = self::lastRequest($http);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2443/'
            . ($direct ? 'execute/Example/operation?' : 'json-api/Example/operation?api.version=1&')
            . 'space%20%26%2F%25=a%20%2B%25%2F&repeat=one&repeat=two&domain=first&domain-1=second&action1=a&action2=b',
            (string) $request->getUri(),
        );
        self::assertSame(
            $direct ? 'cpanel alpha:fixture-token' : 'whm root:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame($json, (string) $request->getBody());
        self::assertNull(
            $direct ? $client->uapi('alpha')->responseMeta()?->whm : $client->uapi('sibling')->responseMeta(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        foreach (
            [
            '{}', '[]', '"a\\u0000b"', '123456789012345678901234567890',
            '1e9999', '-0.000', 'true', 'false', 'null',
            ] as $json
        ) {
            yield $json => [$json];
        }
        yield '511 lists' => [str_repeat('[', 511) . str_repeat(']', 511)];
        yield '511 objects' => [
            str_repeat('{"a":', 510) . '{}' . str_repeat('}', 510),
        ];
    }

    #[DataProvider('documents')]
    public function testEveryValidRootAndDepthKeepsItsBytes(string $json): void
    {
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $http->addResponse(self::success($direct));
            self::call($client, $direct, $json);
            self::assertSame($json, (string) self::lastRequest($http)->getBody());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDocuments(): iterable
    {
        foreach (['', '  ', '\xEF\xBB\xBF{}', '{', '{}x', 'null true', '{"a":"' . "\xff" . '"}'] as $index => $json) {
            yield 'syntax ' . $index => [$index === 2 ? "\xef\xbb\xbf{}" : $json];
        }
        foreach ([512, 513] as $depth) {
            yield $depth . ' empty lists' => [str_repeat('[', $depth) . str_repeat(']', $depth)];
            yield $depth . ' scalar lists' => [str_repeat('[', $depth) . '0' . str_repeat(']', $depth)];
            yield $depth . ' empty objects' => [
                str_repeat('{"a":', $depth - 1) . '{}' . str_repeat('}', $depth - 1),
            ];
        }
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidSyntaxSendsNothingAndClearsOldMetadata(string $json): void
    {
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $http->addResponse(self::success($direct));
            self::call($client, $direct, '{}');
            $handle = $direct ? $client->uapi() : $client->whm();
            $snapshot = $handle->responseMeta();
            try {
                self::call($client, $direct, $json);
                self::fail('Expected invalid JSON to fail.');
            } catch (InvalidArgumentException $exception) {
                self::assertNull($exception->getPrevious());
                self::assertNull($handle->responseMeta());
                self::assertNotNull($snapshot);
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string, array{bool, string, array<string, mixed>}> */
    public static function reservedInputs(): iterable
    {
        foreach ([false, true] as $direct) {
            $names = [
                'api.version', 'api.filter', 'api.sort', 'api.paginate', 'api.columns', 'api.', 'api.future_control',
            ];
            if ($direct) {
                $names = [...$names, 'cpanel.user', 'cpanel.module', 'cpanel.function'];
            }
            foreach ($names as $name) {
                $prefix = ($direct ? 'direct ' : 'WHM ') . $name;
                yield $prefix . ' query null' => [$direct, '{}', [$name => null]];
                yield $prefix . ' query scalar' => [$direct, '{}', [$name => 'override']];
                yield $prefix . ' query plain list' => [$direct, '{}', [$name => ['override']]];
                yield $prefix . ' query list' => [$direct, '{}', [$name => new NumberedList(['override', 'second'])]];
                yield $prefix . ' query repeat' => [$direct, '{}', [$name => new RepeatedList('override')]];
                yield $prefix . ' root null' => [$direct, '{"' . $name . '":null}', []];
                yield $prefix . ' root escaped' => [$direct, '{"' . str_replace('.', '\\u002e', $name) . '":null}', []];
                yield $prefix . ' root duplicate' => [$direct, '{"' . $name . '":1,"' . $name . '":null}', []];
            }
        }
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('reservedInputs')]
    public function testReservedPresenceRejectsBeforeFiltering(bool $direct, string $json, array $query): void
    {
        [$client, $http] = self::client($direct);
        $http->addResponse(self::success($direct));
        self::call($client, $direct, '{}');
        $handle = $direct ? $client->uapi() : $client->whm();
        $http->addResponse(self::success($direct));
        try {
            self::call($client, $direct, $json, $query);
            self::fail('Expected routing collision rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
            self::assertStringNotContainsString('override', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    #[DataProvider('routes')]
    public function testNestedReservedNamesAreOrdinaryData(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $http->addResponse(self::success($direct));
        $json = '{"nested":{"api.":null,"api.future_control":1,"api.version":null,'
            . '"cpanel.user":null,"cpanel.module":null,"cpanel.function":null}}';
        self::call($client, $direct, $json);
        self::assertSame($json, (string) self::lastRequest($http)->getBody());
    }

    #[DataProvider('routes')]
    public function testRoutingPrefixLookalikesRemainLiteralData(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $http->addResponse(self::success($direct));
        $json = '{"Api.version":null,"API.version":null,"api_version":null,"xapi.version":null,'
            . '"ordinary":1,"ordinary":2,"\\u0000key":null,"0":1}';
        self::call($client, $direct, $json, [
            'Api.version' => 'a', 'API.version' => 'b', 'api_version' => 'c', 'xapi.version' => 'd',
        ]);
        self::assertSame($json, (string) self::lastRequest($http)->getBody());
        self::assertSame(
            ($direct ? '' : 'api.version=1&') . 'Api.version=a&API.version=b&api_version=c&xapi.version=d',
            self::lastRequest($http)->getUri()->getQuery(),
        );
    }

    public function testWhmCpanelNamesRemainOrdinaryData(): void
    {
        [$client, $http] = self::client(false);
        $http->addResponse(self::success(false));
        $json = '{"cpanel.user":null,"cpanel.module":null,"cpanel.function":null}';
        self::call($client, false, $json, [
            'cpanel.user' => 'a', 'cpanel.module' => 'b', 'cpanel.function' => 'c',
        ]);
        self::assertSame($json, (string) self::lastRequest($http)->getBody());
        self::assertSame(
            'api.version=1&cpanel.user=a&cpanel.module=b&cpanel.function=c',
            self::lastRequest($http)->getUri()->getQuery(),
        );
    }

    #[DataProvider('routes')]
    public function testOrderedPrefixNamesRejectBeforeRouting(bool $direct): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new class (new HttpTransport($http, $factory, $factory)) extends AbstractApiClient {
            public function __construct(HttpTransport $transport)
            {
                parent::__construct(ClientConfigFactory::fromArray([
                    'host' => 'api.example.test', 'authType' => 'whm', 'token' => 'fixture-token',
                ]), $transport);
            }

            public function send(#[SensitiveParameter] OrderedFields $query, bool $direct): void
            {
                $this->beginRequest();
                $this->validateJsonQuery($query, $direct ? ['cpanel.user', 'cpanel.module', 'cpanel.function'] : []);
                $this->executeJsonPost(
                    '/operation',
                    $direct ? $query : $query->withRouting(['api.version' => 1]),
                    JsonBody::present('{}'),
                    $direct ? ResponseRoute::DirectUapi : ResponseRoute::Whm,
                );
            }
        };
        $names = [
            'api.version', 'api.filter', 'api.sort', 'api.paginate', 'api.columns', 'api.', 'api.future_control',
        ];
        foreach ($names as $name) {
            $http->addResponse(self::success($direct));
            $client->send(new OrderedFields(), $direct);
            self::assertNotNull($client->responseMeta());
            $requestCount = count($http->getRequests());
            $query = new OrderedFields();
            $query->append('caller', $name, 'override');
            $http->addResponse(self::success($direct));
            try {
                $client->send($query, $direct);
                self::fail('Expected emitted routing collision rejection before dispatch.');
            } catch (InvalidArgumentException $exception) {
                self::assertNull($client->responseMeta());
                self::assertCount($requestCount, $http->getRequests());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public function testSeparateDirectAccountsRetainTheirOwnCredentialsAndMetadata(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $transport = new HttpTransport($http, $factory, $factory);
        $alpha = new Client([
            'host' => 'alpha.example.test', 'authType' => 'cpanel', 'username' => 'alpha', 'token' => 'alpha-token',
        ], $transport);
        $beta = new Client([
            'host' => 'beta.example.test', 'authType' => 'cpanel', 'username' => 'beta', 'token' => 'beta-token',
        ], $transport);
        $http->addResponse(self::success(true));
        $snapshot = $alpha->uapi()->callJson('Example', 'operation', '{}')->metadata;
        self::assertNull($beta->uapi()->responseMeta());
        $http->addResponse(self::success(true));
        $beta->uapi()->callJson('Example', 'operation', 'null');
        [$first, $second] = $http->getRequests();
        self::assertSame('https://alpha.example.test:2083/execute/Example/operation', (string) $first->getUri());
        self::assertSame('cpanel alpha:alpha-token', $first->getHeaderLine('Authorization'));
        self::assertSame('https://beta.example.test:2083/execute/Example/operation', (string) $second->getUri());
        self::assertSame('cpanel beta:beta-token', $second->getHeaderLine('Authorization'));
        self::assertSame($snapshot, $alpha->uapi()->responseMeta());
        self::assertNotSame($snapshot, $beta->uapi()->responseMeta());
    }

    public function testBridgeRejectionClearsOnlyOwningMetadataBeforeIo(): void
    {
        [$client, $http] = self::client(false);
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":[]}}}'));
        $handle = $client->uapi('alpha');
        $handle->call('Example', 'operation');
        $snapshot = $handle->responseMeta();
        try {
            $handle->callJson('Example', 'operation', '{}');
            self::fail('Expected bridge rejection.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertNotNull($snapshot);
            self::assertNull($client->uapi('beta')->responseMeta());
            self::assertNull($client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('routes')]
    public function testLateInvalidQueryAndNamesClearMetadataWithoutIo(bool $direct): void
    {
        foreach (['query', 'operation', 'module'] as $failure) {
            [$client, $http] = self::client($direct);
            $handle = $direct ? $client->uapi() : $client->whm();
            $http->addResponse(self::success($direct));
            self::call($client, $direct, '{}');
            try {
                $query = $failure === 'query' ? ['first' => 'valid', 'secret-key' => false] : [];
                if ($failure === 'query') {
                    self::call($client, $direct, '{}', $query);
                } elseif ($direct) {
                    $client->uapi()->callJson(
                        $failure === 'module' ? '../bad' : 'Example',
                        $failure === 'operation' ? '../bad' : 'operation',
                        '{}',
                    );
                } else {
                    $client->whm()->callJson('../bad', '{}');
                }
                self::fail('Expected invalid input.');
            } catch (InvalidArgumentException $exception) {
                self::assertNull($handle->responseMeta());
                self::assertCount(1, $http->getRequests());
                self::assertStringNotContainsString('secret-key', $exception->getMessage());
            }
        }
    }

    #[DataProvider('routes')]
    public function testOutcomePoliciesKeepCallResultAndApiFailureMetadata(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $handle = $direct ? $client->uapi() : $client->whm();
        $body = $direct ? '{"status":0,"data":null}' : '{"metadata":{"result":0},"data":null}';
        $http->addResponse(new Response(200, [], $body));
        try {
            self::call($client, $direct, 'null');
            self::fail('Expected API failure.');
        } catch (ApiErrorException $exception) {
            self::assertSame($exception->metadata, $handle->responseMeta());
        }
        $snapshot = $handle->responseMeta();
        $http->addResponse(new Response(200, [], $body));
        $result = $direct ? $client->uapi()->callJson('Example', 'operation', 'null', outcome: OutcomePolicy::Unchecked)
            : $client->whm()->callJson('operation', 'null', outcome: OutcomePolicy::Unchecked);
        self::assertTrue($result->hasData);
        self::assertNull($result->data);
        self::assertNotSame($snapshot, $result->metadata);
        self::assertSame($result->metadata, $handle->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{int, string, class-string<Throwable>}> */
    public static function failures(): iterable
    {
        yield 'HTTP' => [500, '{"metadata":{"result":1},"status":1}', HttpException::class];
        yield 'redirect' => [302, '{"metadata":{"result":1},"status":1}', HttpException::class];
        yield 'invalid JSON' => [200, 'broken', InvalidResponseException::class];
        yield 'invalid envelope' => [200, '{}', InvalidResponseException::class];
    }

    /** @param class-string<Throwable> $expected */
    #[DataProvider('failures')]
    public function testFailuresNeverRetryOrChangeBodyEncoding(int $status, string $response, string $expected): void
    {
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $http->addResponse(new Response($status, ['Location' => 'https://other.example.test'], $response));
            try {
                self::call($client, $direct, ' {"a":1} ');
                self::fail('Expected failure.');
            } catch (Throwable $exception) {
                self::assertInstanceOf($expected, $exception);
                self::assertCount(1, $http->getRequests());
                self::assertSame(' {"a":1} ', (string) self::lastRequest($http)->getBody());
                self::assertSame('application/json', self::lastRequest($http)->getHeaderLine('Content-Type'));
            }
        }
    }

    #[DataProvider('routes')]
    public function testTransportFailureSendsOnceAndClearsMetadata(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $http->addResponse(self::success($direct));
        self::call($client, $direct, '{}');
        $http->addException(new class extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new \Nyholm\Psr7\Request('POST', 'https://api.example.test');
            }
        });
        try {
            self::call($client, $direct, '{}');
            self::fail('Expected transport failure.');
        } catch (TransportException) {
            self::assertCount(2, $http->getRequests());
            self::assertNull(($direct ? $client->uapi() : $client->whm())->responseMeta());
        }
    }

    public function testInternalAbsentBodyDiffersFromPresentNull(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new class (new HttpTransport($http, $factory, $factory)) extends AbstractApiClient {
            public function __construct(HttpTransport $transport)
            {
                parent::__construct(ClientConfigFactory::fromArray([
                    'host' => 'api.example.test', 'authType' => 'whm', 'token' => 'fixture-token',
                ]), $transport);
            }

            public function checkQuery(#[SensitiveParameter] OrderedFields $query): void
            {
                $this->beginRequest();
                $this->validateJsonQuery($query, ['api.version']);
            }

            /** @param array<string, string|int>|OrderedFields $query */
            public function send(
                #[SensitiveParameter] JsonBody $body,
                ResponseRoute $route = ResponseRoute::Whm,
                #[SensitiveParameter] array|OrderedFields $query = ['api.version' => 1],
            ): ApiResponse {
                return $this->executeJsonPost('/json-api/operation', $query, $body, $route);
            }
        };
        foreach ([JsonBody::absent(), JsonBody::present('null')] as $body) {
            $http->addResponse(self::success(false));
            $client->send($body);
        }
        [$absent, $present] = $http->getRequests();
        self::assertFalse($absent->hasHeader('Content-Type'));
        self::assertSame('', (string) $absent->getBody());
        self::assertSame('application/json', $present->getHeaderLine('Content-Type'));
        self::assertSame('null', (string) $present->getBody());
        $query = new OrderedFields();
        $query->append('filter', 'filter', 'a +%/');
        $client->checkQuery($query);
        $http->addResponse(self::success(false));
        try {
            $client->send(JsonBody::present('{}'), query: $query->withRouting(['api.version' => 1]));
        } catch (TypeError) {
            self::fail('The JSON executor must accept generated ordered query fields.');
        }
        self::assertSame('api.version=1&filter=a%20%2B%25%2F', self::lastRequest($http)->getUri()->getQuery());
        self::assertSame('{}', (string) self::lastRequest($http)->getBody());
        $collision = new OrderedFields();
        $collision->append('caller', 'api.version', 'override');
        try {
            $client->checkQuery($collision);
            self::fail('Generated emitted routing fields must reject before adding routing.');
        } catch (TypeError) {
            self::fail('The shared JSON query guard must accept generated ordered fields.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->responseMeta());
            self::assertCount(3, $http->getRequests());
        }


        $http->addResponse(self::success(false));
        $snapshot = $client->send(JsonBody::present('{}'))->metadata;
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":[]}}}',
        ));
        try {
            $client->send(JsonBody::present('{}'), ResponseRoute::BridgedUapi);
            self::fail('The shared JSON executor must reject bridged routes.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->responseMeta());
            self::assertCount(4, $http->getRequests());
            self::assertSame(1, $snapshot->whmResult());
        }
    }

    /** @param array<string, mixed> $query */
    private static function call(Client $client, bool $direct, string $json, array $query = []): void
    {
        if ($direct) {
            $handle = $client->uapi();
            (new ReflectionMethod($handle, 'callJson'))->invoke($handle, 'Example', 'operation', $json, $query);
        } else {
            $handle = $client->whm();
            (new ReflectionMethod($handle, 'callJson'))->invoke($handle, 'operation', $json, $query);
        }
    }

    private static function lastRequest(MockClient $http): RequestInterface
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    private static function success(bool $direct): Response
    {
        return new Response(200, [], $direct ? '{"status":1,"data":{"ok":true}}'
            : '{"metadata":{"result":1},"data":{"ok":true}}');
    }

    /** @return array{Client, MockClient} */
    private static function client(bool $direct): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test', 'port' => 2443, 'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'alpha' : 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
