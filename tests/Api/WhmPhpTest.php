<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class WhmPhpTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, mixed, string}> */
    public static function operations(): iterable
    {
        yield 'versions' => ['phpGetInstalledVersions', 'php_get_installed_versions',
            '{"versions":["ea-php82","custom-php-8.4+vendor","ea-php82"]}',
            ['ea-php82', 'custom-php-8.4+vendor', 'ea-php82'], ''];
        yield 'system default' => ['phpGetSystemDefaultVersion', 'php_get_system_default_version',
            '{"version":"custom-php-8.4+vendor"}', 'custom-php-8.4+vendor', ''];
        yield 'handlers' => ['phpGetHandlers', 'php_get_handlers',
            '{"version_handlers":[{"available_handlers":["cgi","none","suphp","dso"],'
                . '"current_handler":"suphp","version":"ea-php82"}]}',
            [['available_handlers' => ['cgi', 'none', 'suphp', 'dso'],
                'current_handler' => 'suphp', 'version' => 'ea-php82']], ''];
        yield 'new account FPM' => ['phpGetDefaultAccountsToFpm', 'php_get_default_accounts_to_fpm',
            '{"default_accounts_to_fpm":0}', 0, ''];
        yield 'old FPM flag' => ['phpGetOldFpmFlag', 'php_get_old_fpm_flag', '{"old_fpm_flag":2}', 2, ''];
        yield 'conversion progress' => ['isConversionInProgress', 'is_conversion_in_progress',
            '{"inProgress":0}', 0, ''];
        yield 'virtual hosts' => ['phpGetVhostsByVersion', 'php_get_vhosts_by_version',
            '{"vhosts":["example.test","sub.example.test"]}',
            ['example.test', 'sub.example.test'], '&version=custom-php%2Bvendor'];
    }

    #[DataProvider('operations')]
    public function testOperationsAuthenticateAndExecuteOnceWithCurrentMetadata(
        string $method,
        string $operation,
        string $data,
        mixed $expected,
        string $query,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $webServer = $whm->webServer();
        self::assertSame($webServer, $whm->webServer());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        self::assertSame($expected, self::invoke($client, $method));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1' . $query,
            (string) $request->getUri(),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
        self::assertSame(200, $whm->responseMeta()?->httpStatus);
        self::assertSame(
            ['command' => $operation, 'reason' => 'OK', 'result' => 1, 'version' => 1],
            $whm->responseMeta()->whm,
        );
        self::assertNull($whm->responseMeta()->uapi);
        self::assertNull($client->uapi('account')->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    public function testHandlersPreserveOptionalFieldsAndVersionParameterPresence(): void
    {
        [$client, $http] = self::client();
        foreach ([null, '', 'inherit', 'custom-php+vendor'] as $version) {
            $http->addResponse(new Response(200, [], self::response(
                'php_get_handlers',
                '{"version_handlers":[{}, {"available_handlers":[]}, {"current_handler":"none"},'
                . '{"version":"custom-php+vendor"}]}',
            )));

            self::assertSame(
                [[], ['available_handlers' => []], ['current_handler' => 'none'], ['version' => 'custom-php+vendor']],
                $client->whm()->webServer()->phpGetHandlers($version),
            );
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame(
                'api.version=1' . ($version === null ? '' : '&version=' . rawurlencode($version)),
                $request->getUri()->getQuery(),
            );
        }
        self::assertCount(4, $http->getRequests());
    }

    public function testHandlersPreserveObservedLsapiCompatibility(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response(
            'php_get_handlers',
            '{"version_handlers":[{"available_handlers":["cgi","lsapi","none","suphp"],'
                . '"current_handler":"lsapi","version":"ea-php84"}]}',
        )));

        self::assertSame([[
            'available_handlers' => ['cgi', 'lsapi', 'none', 'suphp'],
            'current_handler' => 'lsapi',
            'version' => 'ea-php84',
        ]], $client->whm()->webServer()->phpGetHandlers());
        self::assertSame(200, $client->whm()->responseMeta()?->httpStatus);
        self::assertSame(1, $client->whm()->responseMeta()->whm['result'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function boundaryPayloads(): iterable
    {
        yield 'empty versions' => ['phpGetInstalledVersions', 'php_get_installed_versions', '{"versions":[]}', []];
        yield 'empty default version' => ['phpGetSystemDefaultVersion', 'php_get_system_default_version',
            '{"version":""}', ''];
        yield 'empty handlers' => ['phpGetHandlers', 'php_get_handlers', '{"version_handlers":[]}', []];
        yield 'empty vhosts' => ['phpGetVhostsByVersion', 'php_get_vhosts_by_version', '{"vhosts":[]}', []];
        foreach ([0, 1] as $flag) {
            yield 'new account flag ' . $flag => ['phpGetDefaultAccountsToFpm', 'php_get_default_accounts_to_fpm',
                '{"default_accounts_to_fpm":' . $flag . '}', $flag];
            yield 'conversion flag ' . $flag => ['isConversionInProgress', 'is_conversion_in_progress',
                '{"inProgress":' . $flag . '}', $flag];
        }
        foreach ([0, 1, 2] as $flag) {
            yield 'old flag ' . $flag => [
                'phpGetOldFpmFlag', 'php_get_old_fpm_flag', '{"old_fpm_flag":' . $flag . '}', $flag,
            ];
        }
    }

    #[DataProvider('boundaryPayloads')]
    public function testEmptyListsStringsAndAllPinnedFlagValuesAreRetained(
        string $method,
        string $operation,
        string $data,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response($operation, $data)));
        self::assertSame($expected, self::invoke($client, $method));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPayloads(): iterable
    {
        $fields = [
            ['phpGetInstalledVersions', 'php_get_installed_versions', 'versions'],
            ['phpGetSystemDefaultVersion', 'php_get_system_default_version', 'version'],
            ['phpGetHandlers', 'php_get_handlers', 'version_handlers'],
            ['phpGetDefaultAccountsToFpm', 'php_get_default_accounts_to_fpm', 'default_accounts_to_fpm'],
            ['phpGetOldFpmFlag', 'php_get_old_fpm_flag', 'old_fpm_flag'],
            ['isConversionInProgress', 'is_conversion_in_progress', 'inProgress'],
            ['phpGetVhostsByVersion', 'php_get_vhosts_by_version', 'vhosts'],
        ];
        foreach ($fields as [$method, $operation, $field]) {
            foreach (['null', '[]', '"payload"', '{}', '{"' . $field . '":null}'] as $data) {
                yield $method . ' data ' . $data => [$method, $operation, $data];
            }
            $invalid = match ($method) {
                'phpGetInstalledVersions', 'phpGetVhostsByVersion' => [
                    '{}', '"value"', '[1]', '[null]', '[false]', '[[]]',
                ],
                'phpGetSystemDefaultVersion' => ['1', 'true', '[]', '{}'],
                'phpGetHandlers' => ['{}', '"value"', '[false]', '[null]', '["suphp"]',
                    '[{"available_handlers":null}]', '[{"available_handlers":{}}]',
                    '[{"available_handlers":[1]}]', '[{"available_handlers":[null]}]',
                    '[{"current_handler":1}]', '[{"current_handler":null}]',
                    '[{"version":42}]', '[{"version":null}]'],
                'phpGetOldFpmFlag' => ['"1"', '1.0', 'true', 'null', '[]', '{}'],
                default => ['-1', '2', '"1"', '1.0', 'true', '[]', '{}'],
            };
            foreach ($invalid as $value) {
                yield $method . ' field ' . $value => [$method, $operation, '{"' . $field . '":' . $value . '}'];
            }
        }
    }

    public function testEmptyJsonArrayHandlerRecordIsAnEmptyObject(): void
    {
        // The engine reads an empty JSON array as an empty object when no field is required.
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response('php_get_handlers', '{"version_handlers":[[]]}')));

        self::assertSame([[]], $client->whm()->webServer()->phpGetHandlers());
    }

    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function deferredRangeChecks(): iterable
    {
        yield 'old FPM flag below the pinned enum' => [
            'phpGetOldFpmFlag', 'php_get_old_fpm_flag', '{"old_fpm_flag":-1}', -1,
        ];
        yield 'old FPM flag above the pinned enum' => [
            'phpGetOldFpmFlag', 'php_get_old_fpm_flag', '{"old_fpm_flag":3}', 3,
        ];
        yield 'available handler outside the pinned enum' => [
            'phpGetHandlers', 'php_get_handlers', '{"version_handlers":[{"available_handlers":["fpm"]}]}',
            [['available_handlers' => ['fpm']]],
        ];
        yield 'current handler outside the pinned enum' => [
            'phpGetHandlers', 'php_get_handlers', '{"version_handlers":[{"current_handler":"fpm"}]}',
            [['current_handler' => 'fpm']],
        ];
    }

    /**
     * The deleted hand-written group rejected these values. Minimum/range enforcement is deferred to bead
     * whm-api-client-8x0.18, so the generated engine validates only their types.
     */
    #[DataProvider('deferredRangeChecks')]
    public function testRangeAndEnumChecksAreDeferredToTypeValidation(
        string $method,
        string $operation,
        string $data,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        self::assertSame($expected, self::invoke($client, $method));
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsRejectWithoutRetryAndRetainTheirMetadata(
        string $method,
        string $operation,
        string $data,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response($operation, $data)));
        try {
            self::invoke($client, $method);
            self::fail('Expected an invalid PHP payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            self::assertSame($operation, $exception->metadata?->whm['command'] ?? null);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, int, string, class-string<ApiException>, int|string|null}> */
    public static function failedResponses(): iterable
    {
        foreach (self::operations() as [$method]) {
            yield $method . ' API failure' => [$method, 200, '{"metadata":{"result":0,"reason":"fixture-token"}}',
                ApiErrorException::class, 0];
            yield $method . ' missing data' => [$method, 200, '{"metadata":{"result":1}}',
                InvalidResponseException::class, 1];
            yield $method . ' invalid status' => [$method, 200, '{"metadata":{"result":"1"},"data":{}}',
                InvalidResponseException::class, '1'];
            yield $method . ' HTTP failure' => [$method, 403, '{"metadata":{"result":0}}', HttpException::class, 0];
            yield $method . ' redirect' => [$method, 302, '', HttpException::class, null];
            yield $method . ' malformed JSON' => [$method, 200, '{broken', InvalidResponseException::class, null];
        }
    }

    /** @param class-string<ApiException> $exceptionClass */
    #[DataProvider('failedResponses')]
    public function testFailuresReplaceOldMetadataWithoutRetries(
        string $method,
        int $status,
        string $body,
        string $exceptionClass,
        int|string|null $result,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response('php_get_installed_versions', '{"versions":[]}')));
        $client->whm()->webServer()->phpGetInstalledVersions();
        $retained = $client->whm()->responseMeta();
        $http->addResponse(new Response($status, [], $body));
        try {
            self::invoke($client, $method);
            self::fail('Expected an API failure.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            self::assertNotSame($retained, $exception->metadata);
            self::assertSame($result, $exception->metadata?->whm['result'] ?? null);
            self::assertSame('php_get_installed_versions', $retained?->whm['command'] ?? null);
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            if ($exception instanceof HttpException) {
                self::assertSame($status, $exception->httpStatus);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('operations')]
    public function testTransportFailureClearsMetadataWithoutRetry(
        string $method,
        string $operation,
        string $data,
        mixed $expected,
        string $query,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response($operation, $data)));
        self::assertSame($expected, self::invoke($client, $method));
        $retained = $client->whm()->responseMeta();
        $http->addException(new class ('fixture-token') extends RuntimeException implements ClientExceptionInterface {
        });
        try {
            self::invoke($client, $method);
            self::fail('Expected a transport failure.');
        } catch (TransportException $exception) {
            self::assertNull($client->whm()->responseMeta());
            self::assertNull($exception->metadata);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            self::assertSame($operation, $retained?->whm['command'] ?? null);
        }
        self::assertCount(2, $http->getRequests());
    }

    private static function invoke(Client $client, string $method): mixed
    {
        return $method === 'phpGetVhostsByVersion'
            ? $client->whm()->webServer()->phpGetVhostsByVersion('custom-php+vendor')
            : $client->whm()->webServer()->{$method}();
    }

    private static function response(string $operation, string $data): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"OK","result":1,"version":1},'
            . '"data":' . $data . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'username' => 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
