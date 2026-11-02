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
use Psr\Http\Message\RequestInterface;

/** Literal synthetic regressions for attributed source review; no provider behavior is live-verified. */
final class UapiSiteQualityCompatibilityTest extends TestCase
{
    /** @param array<string, mixed> $expected */
    #[DataProvider('successes')]
    public function testSourceResponseValuesSurviveGeneratedCalls(
        string $route,
        string $method,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $actual = $method === 'createProject'
            ? $account->siteQuality()->createProject('Fixture', 'https://example.test', false)
            : $account->siteQuality()->createSiteQualityUser('fixture@example.test');
        self::assertSame($expected, $actual);
        self::assertNotSame([], $actual);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame($method === 'createProject'
            ? 'name=Fixture&url=https%3A%2F%2Fexample.test&standard_alerting=0'
            : 'email=fixture%40example.test', (string) $request->getBody());
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function successes(): iterable
    {
        $cases = [
            'native disabled' => ['createSiteQualityUser',
                '{"app_token":"fixture-token","enabled":0,"username":"fixture@example.test"}',
                ['app_token' => 'fixture-token', 'enabled' => false, 'username' => 'fixture@example.test']],
            'native enabled bounded compatibility' => ['createSiteQualityUser', '{"enabled":1}', ['enabled' => true]],
            'pinned false' => ['createSiteQualityUser', '{"enabled":false}', ['enabled' => false]],
            'pinned true' => ['createSiteQualityUser', '{"enabled":true}', ['enabled' => true]],
            'pinned null' => ['createSiteQualityUser', '{"enabled":null}', ['enabled' => null]],
            'omitted enabled' => ['createSiteQualityUser', '{"username":"fixture"}', ['username' => 'fixture']],
            'normal opaque provider wrapper' => ['createProject',
                '{"project":{"id":"opaque-id","role":false,"systems":[{"id":null}],'
                . '"extra":{"ratio":1.25,"enabled":true,"nil":null,"list":[1,"x",false,{}]}},'
                . '"component":{"data":{"component":{"id":"component-id","unknown":[null,2.5]}},'
                . '"meta":{"ok":true},"extra":"retained"},"unknown_wrapper":"filtered"}',
                ['component' => ['data' => ['component' => ['id' => 'component-id', 'unknown' => [null, 2.5]]],
                    'meta' => ['ok' => true], 'extra' => 'retained'],
                    'project' => ['id' => 'opaque-id', 'role' => false, 'systems' => [['id' => null]],
                        'extra' => ['ratio' => 1.25, 'enabled' => true, 'nil' => null,
                            'list' => [1, 'x', false, []]]]]],
            'reuse null project complete component envelope' => ['createProject',
                '{"project":null,"component":{"data":{"component":{"provider":true}},"status":"ok"}}',
                ['component' => ['data' => ['component' => ['provider' => true]], 'status' => 'ok'],
                    'project' => null]],
            'empty provider body integer one' => ['createProject', '{"project":{"opaque":"row"},"component":1}',
                ['component' => 1, 'project' => ['opaque' => 'row']]],
            'empty project object and null component' => ['createProject', '{"project":{},"component":null}',
                ['component' => null, 'project' => []]],
            'inherited empty project array' => ['createProject', '{"project":[],"component":[]}',
                ['component' => [], 'project' => []]],
            'component object' => ['createProject', '{"project":null,"component":{}}',
                ['component' => [], 'project' => null]],
            'component list' => ['createProject', '{"project":null,"component":[{"x":false},null,"opaque"]}',
                ['component' => [['x' => false], null, 'opaque'], 'project' => null]],
            'component string' => ['createProject', '{"project":null,"component":"opaque"}',
                ['component' => 'opaque', 'project' => null]],
            'component float' => ['createProject', '{"project":null,"component":1.25}',
                ['component' => 1.25, 'project' => null]],
            'component false' => ['createProject', '{"project":null,"component":false}',
                ['component' => false, 'project' => null]],
            'component true' => ['createProject', '{"project":null,"component":true}',
                ['component' => true, 'project' => null]],
            'component zero' => ['createProject', '{"project":null,"component":0}',
                ['component' => 0, 'project' => null]],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => $case) {
                yield "$route $name" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('mismatches')]
    public function testMalformedResponsePreservesConfirmedOutcomeAndOriginalPayload(
        string $route,
        string $method,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            if ($method === 'createProject') {
                $account->siteQuality()->createProject('Fixture', 'https://example.test');
            } else {
                $account->siteQuality()->createSiteQualityUser('fixture@example.test');
            }
            self::fail('Malformed success payload must remain a confirmed-outcome mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function mismatches(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['"0"', '"1"', '"true"', '0.0', '1.0', '-1', '2', '[]', '[0]', '{}'] as $wrong) {
                yield "$route enabled $wrong" => [$route, 'createSiteQualityUser', '{"enabled":' . $wrong . '}'];
            }
            foreach (
                [null, 'null', '0', 'false', '"root"', '[]', '[{}]', '{}',
                    '{"component":null}', '{"project":null}'] as $index => $wrong
            ) {
                yield "$route wrapper $index" => [$route, 'createProject', $wrong];
            }
            foreach (['[{}]', '[0]', '1', '1.25', 'false', '"row"'] as $wrong) {
                yield "$route project $wrong" => [
                    $route, 'createProject', '{"project":' . $wrong . ',"component":null}',
                ];
            }
        }
    }

    private static function envelope(string $route, ?string $payload): string
    {
        $result = '{"status":1,"errors":null,"messages":null' . ($payload === null ? '' : ',"data":' . $payload) . '}';
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
