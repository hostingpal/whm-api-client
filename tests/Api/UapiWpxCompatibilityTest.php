<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Wpx;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independent synthetic fixtures: progress wire review and separately source-only PHP diagnostics. */
final class UapiWpxCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function progress(): iterable
    {
        $cases = [
            'quoted twenty' => ['{"message":"Creating database","percentage":"20","status":"running"}',
                ['message' => 'Creating database', 'percentage' => 20, 'status' => 'running']],
            'quoted ten' => ['{"percentage":"10","status":"running"}', ['percentage' => 10, 'status' => 'running']],
            'failed forty' => ['{"percentage":"40","status":"failed"}', ['percentage' => 40, 'status' => 'failed']],
            'no log' => ['{"percentage":0,"status":"unknown"}', ['percentage' => 0, 'status' => 'unknown']],
            'complete' => ['{"percentage":100,"status":"complete"}', ['percentage' => 100, 'status' => 'complete']],
            'integer twenty' => ['{"percentage":20}', ['percentage' => 20]],
            'omitted' => ['{}', []],
            'zero string' => ['{"percentage":"0"}', ['percentage' => 0]],
            'negative canonical' => ['{"percentage":"-1"}', ['percentage' => -1]],
            'no new range' => ['{"percentage":"101"}', ['percentage' => 101]],
            'maximum' => ['{"percentage":"9223372036854775807"}', ['percentage' => PHP_INT_MAX]],
            'minimum' => ['{"percentage":"-9223372036854775808"}', ['percentage' => PHP_INT_MIN]],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => [$payload, $expected]) {
                yield "$route $name" => [$route, $payload, $expected];
            }
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('progress')]
    public function testProgressConvertsOnlyCanonicalBoundedIntegerStrings(
        string $route,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        self::assertSame($expected, $account->wpx()->getTaskProgress('synthetic-run'));
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function creators(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['createWebsite', 'createNovaForWordPressWebsite', 'importWebsite'] as $method) {
                yield "$route $method" => [$route, $method];
            }
        }
    }

    #[DataProvider('creators')]
    public function testSourceOnlyRefusalPreservesEveryDiagnosticWithoutInventingATask(
        string $route,
        string $method,
    ): void {
        [$client, $http] = self::client($route);
        // Source-only fixture, not a captured unsupported_php wire response or real install.
        $payload = '{"blocked_by":["system_default","domain"],"compatible":0,"domain":"a.example",'
            . '"domain_package":"ea-php73","domain_version":"7.3","domain_version_source":"domain",'
            . '"domain_version_vhost":"a.example","effective_version":"7.3","is_fallback":0,'
            . '"required_version":"7.4","status":"unsupported_php","system_default_package":"ea-php73",'
            . '"system_default_version":"7.3","unknown_neighbor":"discard"}';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        $actual = self::create($account->wpx(), $method);
        self::assertSame([
            'blocked_by' => ['system_default', 'domain'], 'compatible' => 0, 'domain' => 'a.example',
            'domain_package' => 'ea-php73', 'domain_version' => '7.3', 'domain_version_source' => 'domain',
            'domain_version_vhost' => 'a.example', 'effective_version' => '7.3', 'is_fallback' => 0,
            'required_version' => '7.4', 'status' => 'unsupported_php', 'system_default_package' => 'ea-php73',
            'system_default_version' => '7.3',
        ], $actual);
        self::assertArrayNotHasKey('install_id', $actual);
        self::assertArrayNotHasKey('task_id', $actual);
        self::assertArrayNotHasKey('magic_link', $actual);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('creators')]
    public function testDiagnosticsPreserveOmissionNullAndOpenStringLists(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $group = $client->uapi('npr')->wpx();
        foreach (['{}', '{"domain":"a.example","status":"collision"}'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
            self::assertSame(json_decode($payload, true), self::create($group, $method));
        }
        $payload = '{"blocked_by":[],"compatible":1,"domain_package":null,"domain_version":null,'
            . '"domain_version_source":null,"domain_version_vhost":null,"effective_version":null,'
            . '"is_fallback":1,"required_version":"7.4","system_default_package":null,"system_default_version":null}';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame([
            'blocked_by' => [], 'compatible' => 1, 'domain_package' => null, 'domain_version' => null,
            'domain_version_source' => null, 'domain_version_vhost' => null, 'effective_version' => null,
            'is_fallback' => 1, 'required_version' => '7.4', 'system_default_package' => null,
            'system_default_version' => null,
        ], self::create($group, $method));
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"blocked_by":["future-cause"],"domain_version_source":"future-source",'
                . '"required_version":"future"}',
        )));
        self::assertSame([
            'blocked_by' => ['future-cause'], 'domain_version_source' => 'future-source',
            'required_version' => 'future',
        ], self::create($group, $method));
        self::assertCount(4, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformed(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                [
                '"01"', '"+1"', '"-0"', '"1.0"', '"1e1"', '" 20"', '"20 "', '"twenty"',
                '"9223372036854775808"', '"-9223372036854775809"', '20.0', 'true', 'null', '[]', '{}',
                ] as $value
            ) {
                yield "$route progress $value" => [$route, 'getTaskProgress', '{"percentage":' . $value . '}'];
            }
            foreach (['null', '"bad"', '{"status":null}', '{"message":20}'] as $payload) {
                yield "$route progress root/neighbor $payload" => [$route, 'getTaskProgress', $payload];
            }
            foreach (['createWebsite', 'createNovaForWordPressWebsite', 'importWebsite'] as $method) {
                $bad = ['null', '"bad"', '{"domain":null}', '{"status":0}', '{"required_version":null}',
                    '{"required_version":74}', '{"blocked_by":null}', '{"blocked_by":{}}', '{"blocked_by":[1]}'];
                foreach (['compatible', 'is_fallback'] as $flag) {
                    foreach (['"0"', 'true', '2', '0.0', 'null'] as $value) {
                        $bad[] = '{"' . $flag . '":' . $value . '}';
                    }
                }
                foreach (
                    ['effective_version', 'system_default_package', 'system_default_version',
                    'domain_package', 'domain_version', 'domain_version_source', 'domain_version_vhost'] as $field
                ) {
                    foreach (['0', 'false', '[]'] as $value) {
                        $bad[] = '{"' . $field . '":' . $value . '}';
                    }
                }
                foreach ($bad as $payload) {
                    yield "$route $method $payload" => [$route, $method, $payload];
                }
            }
        }
    }

    #[DataProvider('malformed')]
    public function testStrictBoundariesKeepConfirmedRawPayloadFreshMetadataAndOneAttempt(
        string $route,
        string $method,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->wpx()->hasReachedQuota();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $caught = null;
        try {
            if ($method === 'getTaskProgress') {
                $account->wpx()->getTaskProgress('synthetic-run');
            } else {
                self::create($account->wpx(), $method);
            }
        } catch (PayloadMismatchException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(PayloadMismatchException::class, $caught);
        self::assertTrue($caught->outcomeConfirmed);
        self::assertSame(json_decode($payload, true), $caught->payload);
        self::assertNotSame($previous, $account->responseMeta());
        self::assertSame($caught->metadata, $account->responseMeta());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(2, $http->getRequests());
    }

    /** @return array<string, mixed> */
    private static function create(Wpx $group, string $method): array
    {
        return match ($method) {
            'createWebsite' => $group->createWebsite('a.example'),
            'createNovaForWordPressWebsite' => $group->createNovaForWordPressWebsite('a.example'),
            default => $group->importWebsite(
                'https://source.example',
                'synthetic-user',
                'synthetic-password',
                'a.example',
            ),
        };
    }

    private static function envelope(string $route, string $payload): string
    {
        $result = '{"status":1,"data":' . $payload . ',"errors":null,"warnings":["current"]}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : '{"result":' . $result . '}';
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
