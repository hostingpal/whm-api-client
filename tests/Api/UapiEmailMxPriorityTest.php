<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UapiEmailMxPriorityTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function priorities(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route . ' integer zero' => [$route, '0', 0];
            yield $route . ' integer priority' => [$route, '15', 15];
            yield $route . ' string zero' => [$route, '"0"', 0];
            yield $route . ' string priority' => [$route, '"15"', 15];
            yield $route . ' largest canonical integer string' => [$route, '"' . PHP_INT_MAX . '"', PHP_INT_MAX];
        }
    }

    #[DataProvider('priorities')]
    public function testMxPriorityNormalizesOnlyTheEvidencedIntegerStringField(
        string $route,
        string $wireValue,
        int $expected,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $wireValue)));
        self::assertSame([['entries' => [['priority' => $expected]]]], $account->email()->listMxs('example.test'));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Email&cpanel.function=list_mxs&domain=example.test'
            : 'https://api.example.test:2083/execute/Email/list_mxs?domain=example.test',
            (string) $request->getUri(),
        );
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPriorities(): iterable
    {
        yield 'fractional numeric string' => ['"1.5"'];
        yield 'exponent numeric string' => ['"1e2"'];
        yield 'leading whitespace' => ['" 15"'];
        yield 'trailing whitespace' => ['"15 "'];
        yield 'leading zero' => ['"015"'];
        yield 'explicit positive sign' => ['"+15"'];
        yield 'overflow' => ['"' . PHP_INT_MAX . '0"'];
        yield 'text' => ['"priority"'];
        yield 'empty string' => ['""'];
        yield 'boolean' => ['true'];
        yield 'float' => ['1.5'];
        yield 'null' => ['null'];
    }

    #[DataProvider('invalidPriorities')]
    public function testNoncanonicalAndNonintegerPriorityValuesStillFail(string $wireValue): void
    {
        [$client, $http] = MysqlTestSupport::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', $wireValue)));
        try {
            $account->email()->listMxs();
            self::fail('Invalid MX priority was accepted');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testMissingPriorityStaysOmittedAndSiblingIntegersAreNotBroadened(): void
    {
        [$client, $http] = MysqlTestSupport::client('direct');
        $http->addResponse(new Response(200, [], '{"status":1,"data":[{"entries":[{}]}]}'));
        self::assertSame([['entries' => [[]]]], $client->uapi()->email()->listMxs());
        $http->addResponse(new Response(
            200,
            [],
            '{"status":1,"data":[{"entries":[{"priority":"15","entrycount":"2"}]}]}',
        ));
        $this->expectException(PayloadMismatchException::class);
        $client->uapi()->email()->listMxs();
    }

    private static function envelope(string $route, string $wireValue): string
    {
        $result = '{"status":1,"data":[{"entries":[{"priority":' . $wireValue . '}]}]}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }
}
