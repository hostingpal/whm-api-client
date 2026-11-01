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

/** Literal HTTP-boundary regressions, independent of generated response schemas; no remote state is accessed. */
final class UapiTrashCompatibilityTest extends TestCase
{
    /** @param array<string, int> $expected */
    #[DataProvider('observedResponses')]
    public function testObservedNativeRemovalAndCanonicalItemsResponses(
        string $route,
        string $operation,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $actual = $operation === 'usage' ? $account->trash()->getUsage()
                : $account->trash()->removeItem(path: '/.trash/synthetic');
            self::assertSame($expected, $actual);
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
    }

    /** @return iterable<string, array{string, string, string, array<string, int>}> */
    public static function observedResponses(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            yield "$route native removed" => [$route, 'remove', '{"removed":1}', ['removed' => 1]];
            yield "$route native already gone" => [$route, 'remove', '{"removed":1,"alreadyGone":1}',
                ['alreadyGone' => 1, 'removed' => 1]];
            yield "$route canonical items" => [$route, 'usage', '{"bytes":16429,"exists":1,"items":"5"}',
                ['bytes' => 16429, 'exists' => 1, 'items' => 5]];
        }
    }

    /** @param array<string, int>|null $expected */
    #[DataProvider('compatibilityBounds')]
    public function testBoundedCompatibilityAndUnchangedFields(
        string $route,
        string $operation,
        string $payload,
        ?array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, warning: true)));
        try {
            $actual = $operation === 'usage' ? $account->trash()->getUsage()
                : $account->trash()->removeItem('/.trash/synthetic');
            self::assertNotNull($expected, 'Malformed corrected leaves must fail validation.');
            self::assertSame($expected, $actual);
        } catch (PayloadMismatchException $error) {
            self::assertNull($expected, 'Valid compatibility input must return its integer result.');
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
            self::assertSame(['Synthetic compatibility warning'], $account->responseMeta()->uapiWarnings());
        }
    }

    /** @return iterable<string, array{string, string, string, array<string, int>|null}> */
    public static function compatibilityBounds(): iterable
    {
        // Literals catch overbroad flag coercion, dropped optional fields and output bool regressions.
        $removal = [
            ['{}', []],
            ['{"removed":0}', ['removed' => 0]],
            ['{"alreadyGone":0}', ['alreadyGone' => 0]],
            ['{"alreadyGone":1}', ['alreadyGone' => 1]],
            ['{"removed":0,"alreadyGone":0}', ['alreadyGone' => 0, 'removed' => 0]],
            ['{"removed":0,"alreadyGone":1}', ['alreadyGone' => 1, 'removed' => 0]],
            ['{"removed":1,"alreadyGone":0}', ['alreadyGone' => 0, 'removed' => 1]],
            ['{"removed":true,"alreadyGone":0}', ['alreadyGone' => 0, 'removed' => 1]],
            ['{"removed":false,"alreadyGone":1}', ['alreadyGone' => 1, 'removed' => 0]],
            ['{"removed":0,"alreadyGone":true}', ['alreadyGone' => 1, 'removed' => 0]],
            ['{"removed":1,"alreadyGone":false}', ['alreadyGone' => 0, 'removed' => 1]],
            ['{"removed":true,"alreadyGone":1}', ['alreadyGone' => 1, 'removed' => 1]],
            ['{"removed":false,"alreadyGone":0}', ['alreadyGone' => 0, 'removed' => 0]],
            ['{"removed":1,"alreadyGone":true}', ['alreadyGone' => 1, 'removed' => 1]],
            ['{"removed":0,"alreadyGone":false}', ['alreadyGone' => 0, 'removed' => 0]],
            ['{"removed":0,"future":{"PRIVATE":[null]}}', ['removed' => 0]],
        ];
        // Signed/boundary strings are compatibility policy, not observed count-producer values.
        $usage = [
            ['{"items":"0"}', ['items' => 0]],
            ['{"items":"4096"}', ['items' => 4096]],
            ['{"items":"-1"}', ['items' => -1]],
            ['{"items":"-4096"}', ['items' => -4096]],
            ['{"items":"9223372036854775807"}', ['items' => PHP_INT_MAX]],
            ['{"items":"-9223372036854775808"}', ['items' => PHP_INT_MIN]],
            ['{"items":-9223372036854775808}', ['items' => PHP_INT_MIN]],
            ['{"bytes":-1,"exists":7,"items":"5","future":"PRIVATE"}',
                ['bytes' => -1, 'exists' => 7, 'items' => 5]],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($removal as $i => [$payload, $expected]) {
                yield "$route removal policy $i" => [$route, 'remove', $payload, $expected];
            }
            foreach ($usage as $i => [$payload, $expected]) {
                yield "$route count policy $i" => [$route, 'usage', $payload, $expected];
            }
            foreach (['removed', 'alreadyGone'] as $field) {
                foreach (
                    ['null', '2', '-1', '9223372036854775807', '0.0', '1.0', '1.5',
                    '"0"', '"1"', '"true"', '"false"', '[]', '{}'] as $wrong
                ) {
                    yield "$route malformed $field $wrong" => [$route, 'remove',
                        '{"' . $field . '":' . $wrong . '}', null];
                }
            }
            foreach (
                ['""', '"-0"', '"+5"', '"05"', '"00"', '"-05"', '" 5"', '"5 "',
                '"5\\n"', '"5e0"', '"5E0"', '"5.0"', '"5.5"', '"0x5"', '"five"',
                '"9223372036854775808"', '"-9223372036854775809"', 'null', 'true', 'false',
                '1.0', '1.5', '[]', '{}'] as $wrong
            ) {
                yield "$route malformed items $wrong" => [$route, 'usage', '{"items":' . $wrong . '}', null];
            }
            foreach (['bytes', 'exists'] as $field) {
                foreach (['"0"', '"5"', 'true', 'false'] as $wrong) {
                    yield "$route unchanged $field $wrong" => [$route, 'usage',
                        '{"' . $field . '":' . $wrong . ',"items":"5"}', null];
                }
            }
        }
    }

    #[DataProvider('statusZeroResponses')]
    public function testStatusZeroRejectsOtherwiseValidCorrectedPayload(
        string $route,
        string $operation,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 0, true)));
        try {
            if ($operation === 'usage') {
                $account->trash()->getUsage();
            } else {
                $account->trash()->removeItem('/.trash/synthetic');
            }
            self::fail('API status zero must fail even when corrected payload leaves are valid.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic compatibility warning'], $error->metadata->uapiWarnings());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function statusZeroResponses(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            yield "$route zero removal" => [$route, 'remove', '{"removed":0,"alreadyGone":0}'];
            yield "$route zero usage" => [$route, 'usage', '{"bytes":0,"exists":0,"items":"0"}'];
        }
    }

    private static function envelope(string $route, string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ',"data":' . $payload . ',"errors":null,"messages":null,"warnings":'
            . ($warning ? '["Synthetic compatibility warning"]' : 'null') . '}';
        return $route === 'bridge' ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'fixture', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
