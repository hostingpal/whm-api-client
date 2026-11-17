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

/**
 * Independent synthetic response shapes attributed to review 5725906568 at O/11.138.0.19.
 * These are not reconstructed lexical wire captures or new live observations.
 */
final class UapiUserDataMixedResponseTest extends TestCase
{
    /** @param array<array-key, mixed> $expected */
    #[DataProvider('responses')]
    public function testSuccessfulWriteAndReadEachAcceptMixedMapping(
        string $route,
        string $stage,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $actual = $stage === 'write'
                ? $account->userData()->setScopedUserdata('prefs', '{"t":true,"n":null,"f":1.5,"l":[1,2],"o":{"a":1}}')
                : $account->userData()->getScopedUserdata('prefs');
            self::assertSame($expected, $actual);
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->uapiStatus());
            throw $error;
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
            self::assertSame(['fixture warning'], $account->responseMeta()->uapiWarnings());
        }
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>}> */
    public static function responses(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            yield "$route write" => [
                $route, 'write', '{"t":1,"n":null,"f":1.5,"l":["1","2"],"o":{"a":"1"}}',
                ['t' => 1, 'n' => null, 'f' => 1.5, 'l' => ['1', '2'], 'o' => ['a' => '1']],
            ];
            yield "$route read" => [
                $route, 'read', '{"t":"1","n":null,"f":"1.5","l":["1","2"],"o":{"a":"1"}}',
                ['t' => '1', 'n' => null, 'f' => '1.5', 'l' => ['1', '2'], 'o' => ['a' => '1']],
            ];
        }
    }

    #[DataProvider('routes')]
    public function testExplicitCallerWriteThenReadUsesTwoRequestsWithoutImplicitReadback(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(
            200,
            [],
            self::envelope($route, '{"t":1,"n":null,"f":1.5,"l":["1","2"],"o":{"a":"1"}}'),
        ));
        $http->addResponse(new Response(
            200,
            [],
            self::envelope($route, '{"t":"1","n":null,"f":"1.5","l":["1","2"],"o":{"a":"1"}}'),
        ));
        self::assertSame(
            ['t' => 1, 'n' => null, 'f' => 1.5, 'l' => ['1', '2'], 'o' => ['a' => '1']],
            $account->userData()->setScopedUserdata(
                scope: 'prefs',
                json: '{"t":true,"n":null,"f":1.5,"l":[1,2],"o":{"a":1}}',
            ),
        );
        self::assertCount(1, $http->getRequests());
        $write = $http->getRequests()[0];
        self::assertSame(
            'scope=prefs&json=%7B%22t%22%3Atrue%2C%22n%22%3Anull%2C%22f%22%3A1.5%2C%22l%22%3A%5B1%2C2%5D'
                . '%2C%22o%22%3A%7B%22a%22%3A1%7D%7D',
            (string) $write->getBody(),
        );
        $before = $account->responseMeta();
        self::assertSame(
            ['t' => '1', 'n' => null, 'f' => '1.5', 'l' => ['1', '2'], 'o' => ['a' => '1']],
            $account->userData()->getScopedUserdata(scope: 'prefs'),
        );
        self::assertCount(2, $http->getRequests());
        self::assertNotSame($before, $account->responseMeta());
        self::assertSame(1, $before?->uapiStatus());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        foreach ($http->getRequests() as $index => $request) {
            $function = $index === 0 ? 'set_scoped_userdata' : 'get_scoped_userdata';
            self::assertSame('POST', $request->getMethod());
            self::assertSame($route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                    . '&cpanel.module=UserData&cpanel.function=' . $function
                : 'https://api.example.test:2083/execute/UserData/' . $function, (string) $request->getUri());
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            self::assertSame(
                $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
                $request->getHeaderLine('Authorization'),
            );
        }
        self::assertSame('scope=prefs', (string) $http->getRequests()[1]->getBody());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    private static function envelope(string $route, string $payload): string
    {
        $result = '{"status":1,"data":' . $payload
            . ',"errors":null,"messages":null,"warnings":["fixture warning"],"metadata":{"fixture":1}}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'fixture',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
