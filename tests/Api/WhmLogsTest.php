<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Account-wide WHM retention inventory, independently pinned from WebLogRetention. */
final class WhmLogsTest extends TestCase
{
    public function testAccessorIsCachedWithoutDispatch(): void
    {
        [$client, $http] = self::client();
        self::assertContains('logs', get_class_methods($client->whm()));
        self::assertSame($client->whm()->logs(), $client->whm()->logs());
        self::assertSame([], $http->getRequests());
    }

    #[DataProvider('validPayloads')]
    public function testAccountInventoryAndExactWhmWire(string $payload, mixed $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, $client->whm()->logs()->listAccountsRetention());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/list_accounts_retention?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('', (string) $request->getBody());
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertNull($client->whm()->responseMeta()->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function validPayloads(): iterable
    {
        yield 'populated' => ['{"default_retention":14,"accounts":[{"user":"alice","domain":"example.com",'
            . '"retention_days":30,"using_default":false},{"user":"bob","domain":"other.test",'
            . '"retention_days":null,"using_default":true},{}]}',
            ['accounts' => [
                ['domain' => 'example.com', 'retention_days' => 30, 'user' => 'alice', 'using_default' => false],
                ['domain' => 'other.test', 'retention_days' => null, 'user' => 'bob', 'using_default' => true],
                [],
            ], 'default_retention' => 14]];
        yield 'indefinite' => ['{"default_retention":0,"accounts":[{"retention_days":0}]}',
            ['accounts' => [['retention_days' => 0]], 'default_retention' => 0]];
        yield 'empty accounts' => ['{"accounts":[]}', ['accounts' => []]];
        yield 'omitted fields' => ['{}', []];
        yield 'legacy empty object' => ['[]', []];
        yield 'unknown ignored' => ['{"future":1}', []];
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedInventoryReplacesMetadataWithoutRetry(?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        $client->whm()->logs()->listAccountsRetention();
        $previous = $client->whm()->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $client->whm()->logs()->listAccountsRetention();
            self::fail('Malformed retention inventory must be rejected.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (
            [null, 'null', 'false', '0', '""', '[{}]', '{"default_retention":"14"}',
            '{"default_retention":null}', '{"default_retention":1.5}', '{"accounts":{}}',
            '{"accounts":null}', '{"accounts":[null]}', '{"accounts":[{"user":1}]}',
            '{"accounts":[{"domain":null}]}', '{"accounts":[{"retention_days":"30"}]}',
            '{"accounts":[{"retention_days":false}]}', '{"accounts":[{"using_default":1}]}',
            '{"accounts":[{"using_default":"true"}]}', '{"accounts":[{"using_default":null}]}',
            '{"accounts":[{"using_default":true},{"using_default":0}]}'] as $index => $payload
        ) {
            yield (string) $index => [$payload];
        }
    }

    public function testApiFailureReplacesMetadataWithoutRetry(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        $client->whm()->logs()->listAccountsRetention();
        $previous = $client->whm()->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Denied"}}'));
        try {
            $client->whm()->logs()->listAccountsRetention();
            self::fail('WHM failure must precede payload parsing.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
        }
        self::assertCount(2, $http->getRequests());
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK"}' . ($payload === null ? '' : ',"data":' . $payload) . '}';
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
