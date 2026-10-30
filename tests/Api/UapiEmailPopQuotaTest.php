<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Email;
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
            yield $route . ' MB exact zero fraction' => [
                $route, false, '"32.000000000000000000"', 32, '&as_bytes=0',
            ];
            yield $route . ' unlimited-sized zero remains zero' => [$route, false, '"0.000"', 0, '&as_bytes=0'];
            yield $route . ' bytes exact zero fraction' => [
                $route, true, '"33554432.000000000"', 33554432, '&as_bytes=1',
            ];
            yield $route . ' largest exact zero fraction' => [
                $route, true, '"' . PHP_INT_MAX . '.000"', PHP_INT_MAX, '&as_bytes=1',
            ];
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
        yield 'nonzero fraction beyond float precision' => ['"32.000000000000000001"'];
        yield 'negative zero fixed point' => ['"-0.000"'];
        yield 'positive sign fixed point' => ['"+32.000"'];
        yield 'leading zero fixed point' => ['"032.000"'];
        yield 'leading whitespace fixed point' => ['" 32.000"'];
        yield 'trailing whitespace fixed point' => ['"32.000 "'];
        yield 'fixed point exponent' => ['"32.0e0"'];
        yield 'missing integer part' => ['".000"'];
        yield 'missing fraction' => ['"32."'];
        yield 'fixed point overflow' => ['"' . PHP_INT_MAX . '0.000"'];
        yield 'native integral float' => ['32.0'];
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

    public function testOtherEmailIntegerResultsDoNotInheritTheFixedPointOptIn(): void
    {
        $cases = [
            [static fn (Email $email): mixed => $email->countPops(), '"7.000"'],
            [static fn (Email $email): mixed => $email->getMaxEmailQuota(), '"32.000"'],
            [static fn (Email $email): mixed => $email->listMxs(), '[{"entries":[{"priority":"15.000"}]}]'],
        ];
        foreach ($cases as [$call, $payload]) {
            [$client, $http] = MysqlTestSupport::client('direct');
            $http->addResponse(new Response(200, [], self::envelope('direct', $payload)));
            try {
                $call($client->uapi()->email());
                self::fail('An unrelated Email result inherited fixed-point compatibility');
            } catch (PayloadMismatchException $error) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertCount(1, $http->getRequests());
            }
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
