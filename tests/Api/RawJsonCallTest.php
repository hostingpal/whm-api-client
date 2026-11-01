<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\JsonBody;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
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
use RuntimeException;
use Throwable;

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
        $result = $direct ? $client->uapi()->callJson('Example', 'operation', $json)
            : $client->whm()->callJson('Example/operation', $json);
        self::assertSame(['ok' => true], $result->data);
        self::assertTrue($result->hasData);
        self::assertSame($result->metadata, $handle->responseMeta());
        self::assertCount(1, $http->getRequests());
        $request = self::lastRequest($http);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2443/'
            . ($direct ? 'execute/Example/operation' : 'json-api/Example/operation'),
            (string) $request->getUri(),
        );
        self::assertSame(
            $direct ? 'cpanel alpha:fixture-token' : 'whm root:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $direct ? $json : " \n{\"api.version\":1," . substr($json, 3),
            (string) $request->getBody(),
        );
        self::assertNull(
            $direct ? $client->uapi('alpha')->responseMeta()?->whm : $client->uapi('sibling')->responseMeta(),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function documents(): iterable
    {
        yield 'empty' => ['{}', '{"api.version":1}'];
        yield 'padded empty' => [" \t{ \r\n }\n", " \t{\"api.version\":1 \r\n }\n"];
        yield 'padded populated' => ["\n{ \t\"n\":-0.000e+03 }\r", "\n{\"api.version\":1, \t\"n\":-0.000e+03 }\r"];
        yield 'file prefix remains caller data' => [
            '{"file-name":"literal"}', '{"api.version":1,"file-name":"literal"}',
        ];
        yield 'nested duplicate escaped and numeric' => [
            '{"a":{"api.version":2},"a":[],"\\u0061":1e9999,"b":123456789012345678901234567890}',
            '{"api.version":1,"a":{"api.version":2},"a":[],"\\u0061":1e9999,"b":123456789012345678901234567890}',
        ];
        $nested = str_repeat('{"a":', 510) . '{}' . str_repeat('}', 510);
        yield '511 containers' => [$nested, '{"api.version":1,' . substr($nested, 1)];
        $lists = '{"a":' . str_repeat('[', 510) . '0' . str_repeat(']', 510) . '}';
        yield '511 mixed containers' => [$lists, '{"api.version":1,' . substr($lists, 1)];
    }

    #[DataProvider('documents')]
    public function testObjectBytesHaveOnlyTheOwnedWhmMemberInserted(string $json, string $whm): void
    {
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $http->addResponse(self::success($direct));
            self::call($client, $direct, $json);
            self::assertSame($direct ? $json : $whm, (string) self::lastRequest($http)->getBody());
            self::assertSame('', self::lastRequest($http)->getUri()->getQuery());
        }
    }

    public function testPublicJsonSignaturesHaveNoQueryArgument(): void
    {
        foreach ([\HostingPal\WhmApiClient\WhmClient::class, \HostingPal\WhmApiClient\UapiClient::class] as $class) {
            $names = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new \ReflectionMethod($class, 'callJson'))->getParameters(),
            );
            self::assertSame($class === \HostingPal\WhmApiClient\WhmClient::class
                ? ['operation', 'json', 'outcome'] : ['module', 'operation', 'json', 'outcome'], $names);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDocuments(): iterable
    {
        foreach (
            [
                '[]', '[1]', '"x"', '1', '12', '-0.0e1', 'true', 'false', 'null', '', '  ',
                '\xEF\xBB\xBF{}', '{', '{}x', 'null true', '{"a":"' . "\xff" . '"}', '{"a":"\\ud800"}',
            ] as $index => $json
        ) {
            yield 'syntax ' . $index => [$json === '\xEF\xBB\xBF{}' ? "\xef\xbb\xbf{}" : $json];
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
    public function testInvalidDocumentsSendNothingAndClearOldMetadata(string $json): void
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
                self::assertSame(InvalidArgumentException::class, $exception::class);
                self::assertNull($exception->getPrevious());
                self::assertNull($handle->responseMeta());
                self::assertNotNull($snapshot);
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string, array{bool, string}> */
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
                yield $prefix . ' root null' => [$direct, '{"' . $name . '":null}'];
                yield $prefix . ' root escaped' => [$direct, '{"' . str_replace('.', '\\u002e', $name) . '":null}'];
                yield $prefix . ' root duplicate' => [$direct, '{"' . $name . '":1,"' . $name . '":null}'];
            }
        }
    }

    #[DataProvider('reservedInputs')]
    public function testReservedPresenceRejectsBeforeFiltering(bool $direct, string $json): void
    {
        [$client, $http] = self::client($direct);
        $http->addResponse(self::success($direct));
        self::call($client, $direct, '{}');
        $handle = $direct ? $client->uapi() : $client->whm();
        $http->addResponse(self::success($direct));
        try {
            self::call($client, $direct, $json);
            self::fail('Expected routing collision rejection.');
        } catch (InvalidArgumentException $exception) {
                self::assertSame(InvalidArgumentException::class, $exception::class);
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
        self::assertSame(
            $direct ? $json : '{"api.version":1,' . substr($json, 1),
            (string) self::lastRequest($http)->getBody(),
        );
    }

    #[DataProvider('routes')]
    public function testRoutingPrefixLookalikesRemainLiteralData(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $http->addResponse(self::success($direct));
        $json = '{"Api.version":null,"API.version":null,"api_version":null,"xapi.version":null,'
            . '"ordinary":1,"ordinary":2,"\\u0000key":null,"0":1,"cpanel.User":null,"cpanel.modulex":null}';
        self::call($client, $direct, $json);
        self::assertSame(
            $direct ? $json : '{"api.version":1,' . substr($json, 1),
            (string) self::lastRequest($http)->getBody(),
        );
        self::assertSame('', self::lastRequest($http)->getUri()->getQuery());
    }

    public function testWhmCpanelNamesRemainOrdinaryData(): void
    {
        [$client, $http] = self::client(false);
        $http->addResponse(self::success(false));
        self::call($client, false, '{"cpanel.user":null,"cpanel.module":null,"cpanel.function":null}');
        self::assertSame(
            '{"api.version":1,"cpanel.user":null,"cpanel.module":null,"cpanel.function":null}',
            (string) self::lastRequest($http)->getBody(),
        );
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
        $beta->uapi()->callJson('Example', 'operation', '{}');
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
    public function testInvalidNamesClearMetadataWithoutIo(bool $direct): void
    {
        foreach (['operation', 'module'] as $failure) {
            [$client, $http] = self::client($direct);
            $handle = $direct ? $client->uapi() : $client->whm();
            $http->addResponse(self::success($direct));
            self::call($client, $direct, '{}');
            try {
                if ($direct) {
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
                self::assertSame(InvalidArgumentException::class, $exception::class);
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
            self::call($client, $direct, '{}');
            self::fail('Expected API failure.');
        } catch (ApiErrorException $exception) {
            self::assertSame($exception->metadata, $handle->responseMeta());
        }
        $snapshot = $handle->responseMeta();
        $http->addResponse(new Response(200, [], $body));
        $result = $direct ? $client->uapi()->callJson('Example', 'operation', '{}', outcome: OutcomePolicy::Unchecked)
            : $client->whm()->callJson('operation', '{}', outcome: OutcomePolicy::Unchecked);
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
                self::assertSame($expected, $exception::class);
                self::assertCount(1, $http->getRequests());
                self::assertSame(
                    $direct ? ' {"a":1} ' : ' {"api.version":1,"a":1} ',
                    (string) self::lastRequest($http)->getBody(),
                );
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

    public function testInternalExecutorRejectsBridgeAndClearsMetadata(): void
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

            public function send(ResponseRoute $route): ApiResponse
            {
                return $this->executeJsonPost('/json-api/operation', JsonBody::present('{}'), $route);
            }
        };
        $http->addResponse(self::success(false));
        $snapshot = $client->send(ResponseRoute::Whm)->metadata;
        try {
            $client->send(ResponseRoute::BridgedUapi);
            self::fail('Expected local bridge rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(InvalidArgumentException::class, $exception::class);
            self::assertNull($exception->getPrevious());
            self::assertNull($client->responseMeta());
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $snapshot->whmResult());
        }
    }

    private static function call(Client $client, bool $direct, string $json): void
    {
        if ($direct) {
            $client->uapi()->callJson('Example', 'operation', $json);
        } else {
            $client->whm()->callJson('operation', $json);
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
