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
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use SensitiveParameterValue;
use TypeError;

/** Independent pinned LogManager contracts; no log content or filesystem is read. */
final class UapiLogManagerTest extends TestCase
{
    private const SECRET = 'fixture-private-log-content-91';

    public function testAccessorIsCachedAndDoesNotDispatch(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        self::assertContains('logManager', get_class_methods($account));
        self::assertSame($account->logManager(), $account->logManager());
        self::assertNotSame($account->logManager(), $client->uapi('other')->logManager());
        self::assertSame([], $http->getRequests());
    }

    #[DataProvider('validPayloads')]
    public function testIndependentPayloadAndExactWire(
        string $route,
        string $function,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        self::assertWire($http, $route, $function, match ($function) {
            'delete_archive' => 'file=domain%20%2B%26%3D%25%2F%E9%9B%AA.gz',
            'list_error_logs' => 'domain=domain%20%2B%26%3D%25%2F%E9%9B%AA',
            'view_error_log' => 'domain=domain%20%2B%26%3D%25%2F%E9%9B%AA&file_id=docroot_error_log&limit=5001',
            'set_settings' => 'archive_logs=0&prune_archive=1&retention_days=0',
            default => '',
        });
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function validPayloads(): iterable
    {
        $cases = [
            'get_settings' => [
                ['{"archive_logs":1,"prune_archive":0,"retention_days":30,"using_default":1}',
                    ['archive_logs' => 1, 'prune_archive' => 0, 'retention_days' => 30, 'using_default' => 1]],
                ['{"archive_logs":0,"prune_archive":1,"retention_days":0,"using_default":0}',
                    ['archive_logs' => 0, 'prune_archive' => 1, 'retention_days' => 0, 'using_default' => 0]],
                ['{}', []], ['[]', []], ['{"future":true}', []],
            ],
            'list_archives' => [
                ['[{"file":"domain.com-May-2019.gz","mtime":1557835866,"path":"/home/user/logs/archive.gz"},{}]',
                    [['file' => 'domain.com-May-2019.gz', 'mtime' => 1557835866,
                        'path' => '/home/user/logs/archive.gz'], []]], ['[]', []],
            ],
            'list_error_logs' => [
                ['[{"error":null,"file_id":"php_fpm_error_log","modified_today":1,"mtime":1778112083,'
                    . '"path":"/home/cpuser/logs/error.log","size":4096},'
                    . '{"error":"unavailable","file_id":"homedir_error_log","modified_today":0,'
                    . '"mtime":null,"path":"","size":null},{}]',
                    [
            ['error' => null, 'file_id' => 'php_fpm_error_log', 'modified_today' => 1,
                        'mtime' => 1778112083, 'path' => '/home/cpuser/logs/error.log', 'size' => 4096],
                        ['error' => 'unavailable', 'file_id' => 'homedir_error_log', 'modified_today' => 0,
                            'mtime' => null, 'path' => '', 'size' => null], []]], ['[]', []],
            ],
            'view_error_log' => [
                ['{"file_id":"docroot_error_log","log_content":["oldest","newest"],"lines_read":2,'
                    . '"modified_today":1,"mtime":1778112083,"partial":0,"path":"/home/user/error.log","size":4096}',
                    ['file_id' => 'docroot_error_log', 'lines_read' => 2, 'log_content' => ['oldest', 'newest'],
                        'modified_today' => 1, 'mtime' => 1778112083, 'partial' => 0,
                        'path' => '/home/user/error.log', 'size' => 4096]],
                ['{"log_content":[],"lines_read":0,"modified_today":0,"partial":1,"size":0}',
                    ['lines_read' => 0, 'log_content' => [], 'modified_today' => 0, 'partial' => 1, 'size' => 0]],
                ['{}', []], ['[]', []],
            ],
        ];
        // Operation-specific live compatibility: public results remain native integers.
        foreach (['archive_logs', 'prune_archive'] as $field) {
            foreach (['0', '1'] as $value) {
                $cases['get_settings'][] = ['{"' . $field . '":"' . $value . '"}', [$field => (int) $value]];
            }
        }
        foreach (['0', '30', (string) PHP_INT_MAX, (string) PHP_INT_MIN] as $value) {
            $cases['get_settings'][] = ['{"retention_days":"' . $value . '"}', ['retention_days' => (int) $value]];
        }
        foreach (['delete_archive', 'set_settings'] as $function) {
            $cases[$function] = [
            ['null', null], ['false', false], ['0', 0], ['1.25', 1.25], ['""', ''],
                ['[]', []], ['{"opaque":{"zero":0,"empty":[],"none":null}}',
                    ['opaque' => ['zero' => 0, 'empty' => [], 'none' => null]]]];
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $function => $payloads) {
                foreach ($payloads as $index => [$payload, $expected]) {
                    yield "$route $function $index" => [$route, $function, $payload, $expected];
                }
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadReplacesMetadataWithoutRetry(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->logManager()->getSettings();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Malformed payload must fail after confirming API success.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [
            'get_settings' => [null, 'null', 'false', '0', '""', '[{}]',
                '{"archive_logs":true}', '{"prune_archive":2}',
                '{"retention_days":1.5}', '{"using_default":null}'],
            'list_archives' => [null, 'null', 'false', '{}', '0', '""', '[null]', '[{"file":0}]',
                '[{"mtime":"1557835866"}]', '[{"mtime":null}]', '[{"path":false}]',
                '[{"file":"valid"},{"mtime":true}]'],
            'list_error_logs' => [null, 'null', 'false', '{}', '0', '""', '[null]', '[{"error":false}]',
                '[{"file_id":1}]', '[{"modified_today":"1"}]', '[{"modified_today":2}]',
                '[{"mtime":"1778112083"}]', '[{"size":1.5}]', '[{"path":null}]',
                '[{"size":4096},{"size":false}]'],
            'view_error_log' => [null, 'null', 'false', '0', '""', '[{}]', '{"file_id":null}',
                '{"log_content":null}', '{"log_content":{}}', '{"log_content":["valid",1]}',
                '{"lines_read":"2"}', '{"modified_today":true}', '{"mtime":null}', '{"partial":2}',
                '{"path":false}', '{"size":"4096"}'],
            'delete_archive' => [null], 'set_settings' => [null],
        ];
        foreach (['archive_logs', 'prune_archive'] as $field) {
            foreach (
                ['null', 'true', 'false', '1.0', '2', '"2"', '"01"', '"1.0"', '"-0"',
                '"true"', '""', '[]', '{}'] as $value
            ) {
                $cases['get_settings'][] = '{"' . $field . '":' . $value . '}';
            }
        }
        foreach (
            ['null', 'true', 'false', '1.0', '"00"', '"+1"', '"-0"', '" 1"', '"1 "',
            '"1e2"', '"1.0"', '"1.5"', '"' . PHP_INT_MAX . '0"', '"' . PHP_INT_MIN . '0"',
            '[]', '{}'] as $value
        ) {
            $cases['get_settings'][] = '{"retention_days":' . $value . '}';
        }
        // Acceptance on the reviewed fields must not leak into siblings.
        $cases['get_settings'][] = '{"archive_logs":"1","using_default":"1"}';
        $cases['get_settings'][] = '{"prune_archive":"0","using_default":"0"}';
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $function => $payloads) {
                foreach ($payloads as $index => $payload) {
                    yield "$route $function $index" => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailureReplacesMetadataWithoutRetry(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->logManager()->getSettings();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $function);
            self::fail('Failed API outcome must fail, including destructive calls.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('bridgeFunctions')]
    public function testOuterWhmFailurePrecedesSuccessfulInnerPayload(string $function): void
    {
        [$client, $http] = self::client('bridge');
        $body = str_replace(
            '"metadata":{"result":1}',
            '"metadata":{"result":0,"reason":"Outer failure"}',
            self::envelope('bridge', '{}'),
        );
        $http->addResponse(new Response(200, [], $body));
        try {
            self::invoke($client->uapi('npr'), $function);
            self::fail('WHM failure must be checked before the inner UAPI result.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($client->uapi('npr')->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function bridgeFunctions(): iterable
    {
        foreach (
            ['delete_archive', 'get_settings', 'list_archives', 'list_error_logs',
            'set_settings', 'view_error_log'] as $function
        ) {
            yield $function => [$function];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                ['delete_archive', 'get_settings', 'list_archives', 'list_error_logs',
                'set_settings', 'view_error_log'] as $function
            ) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    #[DataProvider('routes')]
    public function testDefaultsAreOmittedAndExplicitZeroAndFalseAreSent(string $route): void
    {
        [$client, $http] = self::client($route);
        $group = $client->uapi('npr')->logManager();
        foreach ([null, -1, 0, 30] as $days) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
            $group->setSettings(retentionDays: $days);
            self::assertWire($http, $route, 'set_settings', $days === null ? '' : 'retention_days=' . $days);
        }
        foreach ([false, true] as $flag) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
            $group->setSettings(archiveLogs: $flag, pruneArchive: $flag);
            self::assertWire(
                $http,
                $route,
                'set_settings',
                'archive_logs=' . (int) $flag . '&prune_archive=' . (int) $flag,
            );
        }
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $group->viewErrorLog('npr.rs', 'homedir_error_log');
        self::assertWire($http, $route, 'view_error_log', 'domain=npr.rs&file_id=homedir_error_log');
        self::assertCount(7, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testRequiredEmptyStringsAndPathsAreForwardedUnchanged(string $route): void
    {
        [$client, $http] = self::client($route);
        $group = $client->uapi('npr')->logManager();
        foreach (['', '../literal.gz'] as $file) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
            $group->deleteArchive($file);
            self::assertWire($http, $route, 'delete_archive', 'file=' . rawurlencode($file));
        }
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $group->listErrorLogs('');
        self::assertWire($http, $route, 'list_error_logs', 'domain=');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::invokeArguments($client->uapi('npr'), 'viewErrorLog', ['', '']);
        self::assertWire($http, $route, 'view_error_log', 'domain=&file_id=');
    }

    #[DataProvider('routes')]
    public function testNativeArgumentBoundariesDoNotDispatch(string $route): void
    {
        [$client, $http] = self::client($route);
        foreach (
            [
            ['deleteArchive', [1]], ['listErrorLogs', [false]], ['viewErrorLog', ['npr.rs', 1]],
            ['viewErrorLog', ['npr.rs', 'docroot_error_log', '100']], ['setSettings', ['archiveLogs' => 0]],
            ['setSettings', ['pruneArchive' => '1']], ['setSettings', ['retentionDays' => '30']],
            ] as [$method, $arguments]
        ) {
            try {
                self::invokeArguments($client->uapi('npr'), $method, $arguments);
                self::fail('Native argument types must reject this value.');
            } catch (TypeError $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        self::assertSame([], $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testPrivateLogContentStaysOutOfPayloadFailureTrace(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"log_content":["' . self::SECRET . '",0]}',
        )));
        try {
            $client->uapi('npr')->logManager()->viewErrorLog('npr.rs', 'docroot_error_log');
            self::fail('Invalid log line must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
            self::assertStringNotContainsString(self::SECRET, $error->getTraceAsString());
            $seen = [];
            self::assertFalse(self::containsSecret($error->getTrace(), $seen));
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<int, true> $seen */
    private static function containsSecret(mixed $value, array &$seen): bool
    {
        if (is_string($value)) {
            return str_contains($value, self::SECRET);
        }
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if ($value instanceof MessageInterface && str_contains((string) $value->getBody(), self::SECRET)) {
            return true;
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsSecret($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<int|string, mixed> $arguments */
    private static function invokeArguments(UapiClient $account, string $method, array $arguments): mixed
    {
        return $account->logManager()->$method(...$arguments);
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        $group = $account->logManager();
        return match ($function) {
            'delete_archive' => $group->deleteArchive('domain +&=%/雪.gz'),
            'get_settings' => $group->getSettings(),
            'list_archives' => $group->listArchives(),
            'list_error_logs' => $group->listErrorLogs('domain +&=%/雪'),
            'set_settings' => $group->setSettings(archiveLogs: false, pruneArchive: true, retentionDays: 0),
            default => $group->viewErrorLog('domain +&=%/雪', 'docroot_error_log', 5001),
        };
    }

    private static function assertWire(MockClient $http, string $route, string $function, string $parameters): void
    {
        $post = in_array($function, ['delete_archive', 'set_settings'], true);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LogManager&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/LogManager/' . $function;
        if (!$post && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($post ? $parameters : '', (string) $request->getBody());
        self::assertSame($post ? 'application/x-www-form-urlencoded' : '', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]') . ',"metadata":{}}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
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
