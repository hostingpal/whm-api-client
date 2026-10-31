<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Independent payload regressions for PR44 review 5720388941; every request uses a local double. */
final class UapiSecurityReviewTest extends TestCase
{
    /** @param array{domain: string, enabled: int, exception?: string|null} $row */
    #[DataProvider('modSecuritySuccesses')]
    public function testSuccessfulModSecurityWritesPreserveNullAbsentAndStringExceptions(
        string $route,
        string $function,
        array $row,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, json_encode([$row], JSON_THROW_ON_ERROR))));
        try {
            $actual = self::writeModSecurity($account, $function);
        } catch (PayloadMismatchException) {
            self::fail('A successful ModSecurity write must preserve null exception without a false payload failure.');
        } finally {
            self::assertCount(1, $http->getRequests());
        }
        self::assertSame([$row], $actual);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertRequest($http, $route, 'ModSecurity', $function, 'POST');
    }

    /** @return iterable<string, array{string, string, array{domain: string, enabled: int, exception?: string|null}}> */
    public static function modSecuritySuccesses(): iterable
    {
        foreach (self::writeRoutes() as [$route, $function]) {
            foreach (
                [
                ['domain' => 'fixture.example', 'enabled' => 1],
                    ['domain' => 'fixture.example', 'enabled' => 1, 'exception' => null],
                    ['domain' => 'fixture.example', 'enabled' => 1, 'exception' => ''],
                    ['domain' => 'fixture.example', 'enabled' => 1,
                        'exception' => 'Synthetic domain diagnostic']] as $index => $row
            ) {
                yield "$route $function $index" => [$route, $function, $row];
            }
        }
    }

    #[DataProvider('invalidModSecurityPayloads')]
    public function testModSecurityNullCorrectionKeepsOtherTypesAndPayloadPresenceStrict(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::writeModSecurity($account, $function);
            self::fail('Unrelated field types and required payload presence must remain strict.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function invalidModSecurityPayloads(): iterable
    {
        foreach (self::writeRoutes() as [$route, $function]) {
            foreach (
                [null, 'null', '[{"exception":false}]', '[{"exception":0}]', '[{"exception":[]}]',
                '[{"exception":{}}]', '[{"enabled":null}]', '[{"enabled":"1"}]', '[{"enabled":true}]',
                '[{"enabled":2}]', '[{"domain":null}]', '[{"dependencies":"fixture.example"}]'] as $index => $payload
            ) {
                yield "$route $function $index" => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('writeRoutes')]
    public function testPartialModSecurityFailureRaisesApiErrorWithoutRetryOrAutomaticRead(
        string $route,
        string $function,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"domain":"fixture.example","enabled":1,"exception":null},'
                . '{"domain":"other.example","enabled":0,"exception":"Synthetic update failure"}]',
            0,
        )));
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"domain":"fixture.example","enabled":1},{"domain":"other.example","enabled":0}]',
        )));
        try {
            self::writeModSecurity($account, $function);
            self::fail('Per-domain rows do not waive the failed API outcome.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
        }
        self::assertCount(1, $http->getRequests());
        self::assertRequest($http, $route, 'ModSecurity', $function, 'POST');
        self::assertSame([
            ['domain' => 'fixture.example', 'enabled' => 1],
            ['domain' => 'other.example', 'enabled' => 0],
        ], $account->modSecurity()->listDomains());
        self::assertCount(2, $http->getRequests());
        self::assertRequest($http, $route, 'ModSecurity', 'list_domains', 'GET');
    }

    /** @return iterable<string, array{string, string}> */
    public static function writeRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['enable_domains', 'disable_domains', 'enable_all_domains', 'disable_all_domains'] as $function) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    #[DataProvider('scanTimestamps')]
    public function testScanTimestampPreservesIntegersAndStringsLosslessly(string $route, int|string $timestamp): void
    {
        [$account, $http] = self::account($route);
        $row = ['current_file' => '', 'infected_files' => [], 'scan_complete' => 0,
            'scanned_file_count' => 0, 'scanned_file_size' => 0, 'time_started' => $timestamp,
            'total_file_count' => 0, 'total_file_size_MiB' => 0];
        $http->addResponse(new Response(200, [], self::envelope($route, json_encode($row, JSON_THROW_ON_ERROR))));
        try {
            $actual = $account->clamScanner()->getScanStatus();
        } catch (PayloadMismatchException) {
            self::fail('Scan time_started must preserve both the documented string and observed integer.');
        } finally {
            self::assertCount(1, $http->getRequests());
        }
        self::assertSame($row, $actual);
        self::assertRequest($http, $route, 'ClamScanner', 'get_scan_status', 'GET');
    }

    /** @return iterable<string, array{string, int|string}> */
    public static function scanTimestamps(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                [0, 1608593571, PHP_INT_MAX, '', '1608593571', '001608593571',
                '123456789012345678901', 'opaque source string'] as $index => $timestamp
            ) {
                yield "$route $index" => [$route, $timestamp];
            }
        }
    }

    #[DataProvider('invalidScanFields')]
    public function testScanTimestampWideningDoesNotWeakenOtherFields(string $route, string $payload): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $account->clamScanner()->getScanStatus();
            self::fail('Only the reviewed timestamp integer alternative is added.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidScanFields(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['{"time_started":null}', '{"time_started":false}', '{"time_started":1.5}',
                '{"time_started":[]}', '{"time_started":{}}', '{"scan_complete":true}',
                '{"scanned_file_count":"1"}', '{"infected_files":[0]}'] as $index => $payload
            ) {
                yield "$route $index" => [$route, $payload];
            }
        }
    }

    private static function writeModSecurity(UapiClient $account, string $function): mixed
    {
        return match ($function) {
            'enable_domains' => $account->modSecurity()->enableDomains(domains: 'fixture.example,other.example'),
            'disable_domains' => $account->modSecurity()->disableDomains(domains: 'fixture.example,other.example'),
            'enable_all_domains' => $account->modSecurity()->enableAllDomains(),
            default => $account->modSecurity()->disableAllDomains(),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $module,
        string $function,
        string $method,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame(in_array($function, ['enable_domains', 'disable_domains'], true)
            ? 'domains=fixture.example%2Cother.example' : '', (string) $request->getBody());
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic API failure"]') . '}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }

    /** @return array{UapiClient, MockClient} */
    private static function account(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory));

        return [$client->uapi('npr'), $http];
    }
}
