<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Quota;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UapiQuotaTest extends TestCase
{
    public function testQuotaSelectorBindsTheAccountWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');

        self::assertSame($account->quota(), $account->quota());
        self::assertNotSame($account->quota(), $client->uapi('other')->quota());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    /** @param array<string, int|float> $expected */
    #[DataProvider('successfulPayloads')]
    public function testQuotaUsesAnAccountBoundGetAndPreservesDocumentedValues(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame($expected, self::invoke($account->quota(), $function));

        self::assertRequest($http, $route, $function);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic quota notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertSame($account->quota(), $account->quota());
        self::assertSame($metadata, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<string, int|float>}> */
    public static function successfulPayloads(): iterable
    {
        // Expectations come from the pinned Quota operation fields, not generated specs or live values.
        $cases = [
            'total inodes and fractional megabytes' => ['get_quota_info',
                '{"inodes_used":7,"inode_limit":100,"inodes_remain":93,"megabytes_used":1.25,'
                . '"megabyte_limit":1000,"megabytes_remain":998.75,"under_inode_limit":1,'
                . '"under_megabyte_limit":0,"under_quota_overall":0,"future":"ignored"}',
                [
                    'inode_limit' => 100, 'inodes_remain' => 93, 'inodes_used' => 7,
                    'megabyte_limit' => 1000, 'megabytes_remain' => 998.75, 'megabytes_used' => 1.25,
                    'under_inode_limit' => 1, 'under_megabyte_limit' => 0, 'under_quota_overall' => 0,
                ]],
            'zero limits and usage stay numeric' => ['get_quota_info',
                '{"inode_limit":0,"inodes_remain":0,"inodes_used":0,"megabyte_limit":0.0,'
                . '"megabytes_remain":0,"megabytes_used":0.0,"under_inode_limit":0,'
                . '"under_megabyte_limit":1,"under_quota_overall":1}',
                [
                    'inode_limit' => 0, 'inodes_remain' => 0, 'inodes_used' => 0,
                    'megabyte_limit' => 0.0, 'megabytes_remain' => 0, 'megabytes_used' => 0.0,
                    'under_inode_limit' => 0, 'under_megabyte_limit' => 1, 'under_quota_overall' => 1,
                ]],
            'omitted total fields stay absent' => ['get_quota_info', '{"inodes_used":0}', ['inodes_used' => 0]],
            'local bytes retain their unit' => ['get_local_quota_info',
                '{"inodes_used":7,"bytes_used":1048577,"byte_limit":8388608,"inode_limit":100,'
                . '"future":"ignored"}',
                ['byte_limit' => 8388608, 'bytes_used' => 1048577, 'inode_limit' => 100, 'inodes_used' => 7]],
            'local zero limits and usage' => ['get_local_quota_info',
                '{"byte_limit":0,"bytes_used":0,"inode_limit":0,"inodes_used":0}',
                ['byte_limit' => 0, 'bytes_used' => 0, 'inode_limit' => 0, 'inodes_used' => 0]],
            'omitted local fields stay absent' => ['get_local_quota_info', '{"bytes_used":0}', ['bytes_used' => 0]],
        ];
        foreach (['get_quota_info', 'get_local_quota_info'] as $function) {
            $cases[$function . ' empty object'] = [$function, '{}', []];
            $cases[$function . ' empty array object compatibility'] = [$function, '[]', []];
            $cases[$function . ' unknown field'] = [$function, '{"future":{"private":"ignored"}}', []];
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$function, $payload, $expected]) {
                yield $route . ' ' . $name => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testIncompatibleQuotaPayloadKeepsTheNewConfirmedOutcome(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"inodes_used":7}')));
        $account->quota()->getQuotaInfo();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        try {
            self::invoke($account->quota(), $function);
            self::fail('Missing or malformed quota data must fail validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertSame(1, $previous?->uapiStatus());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        foreach (['get_quota_info', 'get_local_quota_info'] as $function) {
            foreach ([null, 'null', '[0]', '"0"', 'true'] as $payload) {
                $cases[] = [$function, $payload];
            }
            $integers = $function === 'get_quota_info'
                ? ['inode_limit', 'inodes_remain', 'inodes_used']
                : ['byte_limit', 'bytes_used', 'inode_limit', 'inodes_used'];
            foreach ($integers as $field) {
                foreach (['null', '"7"', '1.5'] as $value) {
                    $cases[] = [$function, '{"' . $field . '":' . $value . '}'];
                }
            }
        }
        foreach (['megabyte_limit', 'megabytes_remain', 'megabytes_used'] as $field) {
            foreach (['null', '"1.25"', 'true'] as $value) {
                $cases[] = ['get_quota_info', '{"' . $field . '":' . $value . '}'];
            }
        }
        foreach (['under_inode_limit', 'under_megabyte_limit', 'under_quota_overall'] as $field) {
            foreach (['null', '"1"', 'true', '2', '0.5'] as $value) {
                $cases[] = ['get_quota_info', '{"' . $field . '":' . $value . '}'];
            }
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as [$function, $payload]) {
                yield $route . ' ' . $function . ' ' . ($payload ?? 'missing') => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('operations')]
    public function testApiAndHttpFailuresReplaceQuotaMetadataWithoutRetrying(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"inodes_used":7}')));
        self::invoke($account->quota(), $function);
        $successful = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));

        try {
            self::invoke($account->quota(), $function);
            self::fail('An unsuccessful UAPI outcome must fail before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($successful, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(['Synthetic quota failure'], $error->errors());
        }

        $http->addResponse(new Response(503, [], 'Synthetic unavailable service'));
        try {
            self::invoke($account->quota(), $function);
            self::fail('An unsuccessful HTTP response must clear the previous metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertSame(1, $successful?->uapiStatus());
        self::assertCount(3, $http->getRequests());
    }

    public function testBridgedQuotaGroupsKeepAccountsAndMetadataIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"inodes_used":7}')));
        self::assertSame(['inodes_used' => 7], $first->quota()->getQuotaInfo());
        self::assertRequest($http, 'bridge', 'get_quota_info');
        $firstMetadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"bytes_used":1024}')));

        self::assertSame(['bytes_used' => 1024], $second->quota()->getLocalQuotaInfo());

        self::assertRequest($http, 'bridge', 'get_local_quota_info', 'other');
        self::assertSame($firstMetadata, $first->responseMeta());
        self::assertNotSame($firstMetadata, $second->responseMeta());
        self::assertNull($client->whm()->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('quotaFunctions')]
    public function testOuterWhmFailureTakesPrecedenceOverInnerQuotaSuccess(string $function): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{}')));
        self::invoke($account->quota(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic denied"},'
            . '"data":{"uapi":{"status":1,"data":{"inodes_used":7}}}}'));

        try {
            self::invoke($account->quota(), $function);
            self::fail('An unsuccessful WHM bridge must fail even when the inner UAPI status is successful.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame(['Synthetic denied'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['get_quota_info', 'get_local_quota_info'] as $function) {
                yield $route . ' ' . $function => [$route, $function];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function quotaFunctions(): iterable
    {
        yield 'total quota' => ['get_quota_info'];
        yield 'local quota' => ['get_local_quota_info'];
    }

    /** @return array<string, int|float> */
    private static function invoke(Quota $quota, string $function): array
    {
        return match ($function) {
            'get_quota_info' => $quota->getQuotaInfo(),
            'get_local_quota_info' => $quota->getLocalQuotaInfo(),
            default => throw new LogicException('Unknown quota test function.'),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $account = 'exampleuser',
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                    . '&cpanel.module=Quota&cpanel.function=' . $function
                : 'https://api.example.test:2083/execute/Quota/' . $function,
            (string) $request->getUri(),
        );
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic quota failure"]')
            . ',"warnings":["Synthetic quota notice"]}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Quota","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
