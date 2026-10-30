<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UapiEmailPopQuotaTest extends TestCase
{
    /** @return iterable<string, array{string, ?bool, string, int, string}> */
    public static function quotas(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route . ' omitted units and integer zero' => [$route, null, '0', 0, ''];
            yield $route . ' MB string zero' => [$route, false, '"0"', 0, '&as_bytes=0'];
            yield $route . ' MB integer quota' => [$route, false, '32', 32, '&as_bytes=0'];
            yield $route . ' MB string quota' => [$route, false, '"32"', 32, '&as_bytes=0'];
            yield $route . ' bytes string quota' => [$route, true, '"33554432"', 33554432, '&as_bytes=1'];
            yield $route . ' largest canonical integer string' => [
                $route, true, '"' . PHP_INT_MAX . '"', PHP_INT_MAX, '&as_bytes=1',
            ];
        }
    }

    #[DataProvider('quotas')]
    public function testQuotaNormalizesCanonicalIntegerStringsWithoutChangingUnitSelection(
        string $route,
        ?bool $asBytes,
        string $wireValue,
        int $expected,
        string $unitQuery,
    ): void {
        [$client, $http] = MysqlTestSupport::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $wireValue)));

        self::assertSame($expected, $account->email()->getPopQuota('owned', asBytes: $asBytes, domain: 'example.test'));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Email&cpanel.function=get_pop_quota&email=owned'
            : 'https://api.example.test:2083/execute/Email/get_pop_quota?email=owned')
                . $unitQuery . '&domain=example.test', (string) $request->getUri());
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidQuotas(): iterable
    {
        yield 'absent data is not null' => [null];
        yield 'explicit null is not zero' => ['null'];
        yield 'literal unlimited is not a pinned result' => ['"unlimited"'];
        yield 'fractional numeric string' => ['"32.5"'];
        yield 'exponent numeric string' => ['"32e1"'];
        yield 'leading whitespace' => ['" 32"'];
        yield 'trailing whitespace' => ['"32 "'];
        yield 'leading zero' => ['"032"'];
        yield 'positive sign' => ['"+32"'];
        yield 'overflow' => ['"' . PHP_INT_MAX . '0"'];
        yield 'empty string' => ['""'];
        yield 'boolean' => ['false'];
        yield 'float' => ['32.5'];
        yield 'object' => ['{}'];
        yield 'list' => ['[]'];
    }

    #[DataProvider('invalidQuotas')]
    public function testInvalidNoncanonicalNullAndMissingQuotasRemainRejected(?string $wireValue): void
    {
        [$client, $http] = MysqlTestSupport::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', $wireValue)));
        try {
            $account->email()->getPopQuota('owned');
            self::fail('Invalid quota was accepted');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertCount(1, $http->getRequests());
        }
    }

    private static function envelope(string $route, ?string $wireValue): string
    {
        $result = '{"status":1' . ($wireValue === null ? '' : ',"data":' . $wireValue) . '}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }
}
