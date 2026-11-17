<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\WebApp;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independent synthetic wire oracles; reviewer observations are not replayed. */
final class UapiWebAppCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, int}> */
    public static function nativeFlags(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['configure', 'configureStructured', 'stage', 'list'] as $method) {
                foreach (['deployed', 'env_editable', 'staged'] as $field) {
                    foreach ([0, 1] as $value) {
                        yield "$route $method $field $value" => [$route, $method, $field,
                            '{"' . $field . '":' . $value . '}', $value];
                    }
                }
            }
        }
    }

    #[DataProvider('nativeFlags')]
    public function testNativeIntegerFlagsIndependently(
        string $route,
        string $method,
        string $field,
        string $row,
        int $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->webApp()->hasFeature();
        $prior = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, self::payload($method, $row))));
        $result = self::call($account->webApp(), $method);
        self::assertIsArray($result);
        $record = $method === 'list' ? $result[0] : $result;
        self::assertIsArray($record);
        self::assertSame([$field => $expected], $record);
        self::assertCount(2, $http->getRequests());
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertNotSame($prior, $meta);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
    }

    /** @return iterable<string, array{string, string}> */
    public static function routesAndMethods(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['configure', 'configureStructured', 'stage', 'list'] as $method) {
                yield "$route $method" => [$route, $method];
            }
        }
    }

    #[DataProvider('routesAndMethods')]
    public function testIndependentPopulatedRowAndOptionalOmission(string $route, string $method): void
    {
        // Literal synthetic row preserves known neighboring types, rather than deriving a fixture from specs.
        $row = '{"build_command":null,"category":"static","container_name":null,"deployed":0,'
            . '"domain":"wrvapp.example.test","env":{"EMPTY":"","FALSE":"0","N":"5"},"env_editable":1,'
            . '"env_status":"ok","framework":null,"last_deploy":null,"mode":null,"name":"wrvapp",'
            . '"package_manager":"npm","runtime":"nodejs","runtime_tag":"22",'
            . '"source":{"branch":null,"type":"zip","url":null},"staged":1,"startup_command":null,'
            . '"status":"created","url":"https://wrvapp.example.test","use_existing_domain":0}';
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->webApp()->hasFeature();
        $prior = $account->responseMeta();
        $unknown = substr($row, 0, -1) . ',"unreviewed":{"private":"ignored"}}';
        $http->addResponse(new Response(200, [], self::envelope($route, self::payload($method, $unknown))));
        $result = self::call($account->webApp(), $method);
        self::assertSame(json_decode(self::payload($method, $row), true, 512, JSON_THROW_ON_ERROR), $result);
        self::assertCount(2, $http->getRequests());
        self::assertNotSame($prior, $account->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope($route, self::payload($method, '{}'))));
        self::assertSame($method === 'list' ? [[]] : [], self::call($account->webApp(), $method));
        self::assertCount(3, $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            self::payload($method, '{"env":[],"last_deploy":null}'),
        )));
        $emptyMap = ['env' => [], 'last_deploy' => null];
        self::assertSame($method === 'list' ? [$emptyMap] : $emptyMap, self::call($account->webApp(), $method));
        self::assertCount(4, $http->getRequests());
    }

    #[DataProvider('routesAndMethods')]
    public function testPinnedBooleanCompatibilityNormalizesSeparately(string $route, string $method): void
    {
        foreach (
            [
            ['{"deployed":false,"env_editable":true,"staged":false}',
                ['deployed' => 0, 'env_editable' => 1, 'staged' => 0]],
            ['{"deployed":true,"env_editable":false,"staged":true}',
                ['deployed' => 1, 'env_editable' => 0, 'staged' => 1]],
            ] as [$row, $expected]
        ) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->webApp()->hasFeature();
            $prior = $account->responseMeta();
            // Booleans are pinned formal compatibility, not reported wire observations.
            $http->addResponse(new Response(200, [], self::envelope($route, self::payload($method, $row))));
            self::assertSame($method === 'list' ? [$expected] : $expected, self::call($account->webApp(), $method));
            self::assertCount(2, $http->getRequests());
            self::assertNotSame($prior, $account->responseMeta());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejectedRows(): iterable
    {
        foreach (self::routesAndMethods() as $label => [$route, $method]) {
            foreach (['deployed', 'env_editable', 'staged'] as $field) {
                // Literal JSON keeps 0.0/1.0 as floats throughout decoding.
                foreach (['2', '-1', '"0"', '"1"', '0.0', '1.0', 'null', '[]', '{}'] as $bad) {
                    yield "$label $field $bad" => [$route, $method, '{"' . $field . '":' . $bad . '}'];
                }
            }
            foreach (
                ['{"name":1}', '{"domain":false}', '{"env":{"A":1}}', '{"env":{"A":null}}',
                '{"env":[7]}', '{"use_existing_domain":"0"}', '{"use_existing_domain":0.0}',
                '{"last_deploy":false}', '{"last_deploy":{"timestamp":null}}',
                '{"source":{"branch":0}}', '{"source":null}', '{"container_name":false}',
                ] as $i => $bad
            ) {
                yield "$label neighbor $i" => [$route, $method, $bad];
            }
        }
    }

    #[DataProvider('rejectedRows')]
    public function testMalformedLeavesKeepConfirmedOutcomeAndRawPayload(
        string $route,
        string $method,
        string $row,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->webApp()->hasFeature();
        $prior = $account->responseMeta();
        $payload = self::payload($method, $row);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $failure = null;
        try {
            self::call($account->webApp(), $method);
        } catch (PayloadMismatchException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(PayloadMismatchException::class, $failure);
        self::assertTrue($failure->outcomeConfirmed);
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $failure->payload);
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertNotSame($prior, $meta);
        self::assertSame($meta, $failure->metadata);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('routesAndMethods')]
    public function testApiFailurePrecedesMalformedFlag(string $route, string $method): void
    {
        foreach ($route === 'bridge' ? ['inner', 'outer'] : ['inner'] as $failureKind) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->webApp()->hasFeature();
            $prior = $account->responseMeta();
            $http->addResponse(new Response(200, [], self::envelope(
                $route,
                self::payload($method, '{"staged":2}'),
                $failureKind === 'inner' ? 0 : 1,
                $failureKind === 'outer' ? 0 : 1,
            )));
            $failure = null;
            try {
                self::call($account->webApp(), $method);
            } catch (ApiErrorException $error) {
                $failure = $error;
            }
            self::assertInstanceOf(ApiErrorException::class, $failure);
            $meta = $account->responseMeta();
            self::assertNotNull($meta);
            self::assertNotSame($prior, $meta);
            self::assertSame($meta, $failure->metadata);
            self::assertSame($failureKind === 'inner' ? 0 : 1, $meta->uapiStatus());
            self::assertSame($route === 'bridge' ? ($failureKind === 'outer' ? 0 : 1) : null, $meta->whmResult());
            self::assertCount(2, $http->getRequests());
        }
    }

    #[DataProvider('routesAndMethods')]
    public function testLastDeployCategoryRemainsListOnly(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->webApp()->hasFeature();
        $prior = $account->responseMeta();
        $row = '{"last_deploy":{"error_category":{"opaque":[null,false,7]}}}';
        $http->addResponse(new Response(200, [], self::envelope($route, self::payload($method, $row))));
        $expected = $method === 'list'
            ? [['last_deploy' => ['error_category' => ['opaque' => [null, false, 7]]]]]
            : ['last_deploy' => []];
        self::assertSame($expected, self::call($account->webApp(), $method));
        self::assertCount(2, $http->getRequests());
        self::assertNotSame($prior, $account->responseMeta());
    }

    private static function call(WebApp $group, string $method): mixed
    {
        return match ($method) {
            'configure' => $group->configure('wrvapp'),
            'configureStructured' => $group->configureStructured('wrvapp', env: ['N' => 5, 'FALSE' => false]),
            'stage' => $group->stage('wrvapp', 'uploads/local.zip', 'zip'),
            default => $group->listApplications(),
        };
    }

    private static function payload(string $method, string $row): string
    {
        return $method === 'list' ? '[' . $row . ']' : $row;
    }

    private static function envelope(string $route, string $payload, int $status = 1, int $outer = 1): string
    {
        $inner = '{"status":' . $status . ',"data":' . $payload . ',"errors":'
            . ($status === 1 ? 'null' : '["Synthetic failure"]') . '}';
        return $route === 'bridge'
            ? '{"metadata":{"result":' . $outer . '},"data":{"uapi":' . $inner . '}}'
            : '{"result":' . $inner . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
