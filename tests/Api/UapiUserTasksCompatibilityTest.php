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
use SensitiveParameterValue;

/** SYNTHETIC source-inferred regression data; no queued execution, capture or path access. */
final class UapiUserTasksCompatibilityTest extends TestCase
{
    #[DataProvider('routes')]
    public function testPreservesNamedWpxIdentificationAndFiltersPrivateImportArguments(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[{"id":"WPX/install/sample-task",'
            . '"action":"install","subsystem":"WPX","exclusive":"wp-install-key",'
            . '"sse_url":"/sse/UserTasks/WPX_install_sample-task/install.log",'
            . '"args":{"domain":"task.example.test","install_id":"1720000000-125-42",'
            . '"log_file":"/home/example/.cpanel/wpx/install.log","title":"Synthetic title",'
            . '"site_settings":"{\\"source_password\\":\\"SYNTHETIC_SOURCE_PASSWORD\\"}","nova":1},'
            . '"unrelated":{"private":"SYNTHETIC_SOURCE_PASSWORD"}}]')));
        $account = $client->uapi('fixture');
        self::assertSame([[
            'action' => 'install',
            'args' => [
                'domain' => 'task.example.test',
                'install_id' => '1720000000-125-42',
                'log_file' => '/home/example/.cpanel/wpx/install.log',
            ],
            'exclusive' => 'wp-install-key',
            'id' => 'WPX/install/sample-task',
            'sse_url' => '/sse/UserTasks/WPX_install_sample-task/install.log',
            'subsystem' => 'WPX',
        ]], $account->userTasks()->listTasks());
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    #[DataProvider('routes')]
    public function testPreservesVersionControlRepositoryAndProgressPaths(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[{"id":"VersionControl/create/sample",'
            . '"action":"create","subsystem":"VersionControl","args":{"repository_root":"/home/example/repo",'
            . '"log_file":"/home/example/clone.log"},'
            . '"sse_url":"/sse/UserTasks/VersionControl_create_sample/clone.log"}]')));
        self::assertSame([[
            'action' => 'create',
            'args' => ['log_file' => '/home/example/clone.log', 'repository_root' => '/home/example/repo'],
            'id' => 'VersionControl/create/sample',
            'sse_url' => '/sse/UserTasks/VersionControl_create_sample/clone.log',
            'subsystem' => 'VersionControl',
        ]], $client->uapi('fixture')->userTasks()->listTasks());
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('compatibilityValues')]
    public function testSourceInferredLeavesPreservePrimitivesAndRejectOtherTypes(
        string $route,
        string $payload,
        bool $valid,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        if ($valid) {
            self::assertSame($expected, $account->userTasks()->listTasks());
        } else {
            try {
                $account->userTasks()->listTasks();
                self::fail('Invalid known source-inferred field must reject the whole list.');
            } catch (PayloadMismatchException $error) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $error->payload);
            }
        }
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, string, bool, mixed}> */
    public static function compatibilityValues(): iterable
    {
        // Native integer extremes test the declared policy, not observed producer values.
        $cases = [
            ['[]', true, []],
            ['[{}]', true, [[]]],
            ['[{"args":{}}]', true, [['args' => []]]],
            ['[{"args":[]}]', true, [['args' => []]]],
            ['[{"args":{"repository_root":"/synthetic/repo","site_settings":"not even JSON"}}]', true,
                [['args' => ['repository_root' => '/synthetic/repo']]]],
        ];
        foreach (
            ['', '0', 'arbitrary/key +% š', '999999999999999999999999999999999999',
            0, 1, -7, PHP_INT_MIN, PHP_INT_MAX] as $value
        ) {
            $cases[] = ['[{"exclusive":' . json_encode($value, JSON_THROW_ON_ERROR) . '}]', true,
                [['exclusive' => $value]]];
        }
        foreach (['null', '1.5', 'false', 'true', '[]', '{}', '["key"]', '{"key":"value"}'] as $wrong) {
            $cases[] = ['[{"exclusive":' . $wrong . '}]', false, null];
        }
        foreach (['sse_url', 'domain', 'install_id', 'log_file'] as $field) {
            foreach (['', 'SYNTHETIC path/key +% š\\/'] as $value) {
                $row = $field === 'sse_url' ? [$field => $value] : ['args' => [$field => $value]];
                $cases[] = [json_encode([$row], JSON_THROW_ON_ERROR), true, [$row]];
            }
            foreach (['null', '0', 'false', 'true', '1.5', '[]', '{}', '["x"]', '{"x":1}'] as $wrong) {
                $leaf = '"' . $field . '":' . $wrong;
                $cases[] = ['[{' . ($field === 'sse_url' ? $leaf : '"args":{' . $leaf . '}') . '}]', false, null];
            }
        }
        foreach (
            ['null', '{}', '0', 'false', '"text"', '[null]', '[1]', '[false]', '["text"]', '[[{}]]',
            '[{"args":null}]', '[{"args":1}]', '[{"args":false}]', '[{"args":"text"}]',
            '[{"args":[{}]}]', '[{"args":{"repository_root":null}}]'] as $wrong
        ) {
            $cases[] = [$wrong, false, null];
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route case $index" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('routes')]
    public function testMismatchRetainsImportPasswordButConcealsMessageAndLibraryFrames(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"exclusive":false,"args":{"site_settings":"{\\"source_password\\":\\"SYNTHETIC_SOURCE_PASSWORD\\"}"}}]',
        )));
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $account->userTasks()->listTasks();
            self::fail('Malformed exclusive must raise a confirmed mismatch without retry.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame([[
                'exclusive' => false,
                'args' => ['site_settings' => '{"source_password":"SYNTHETIC_SOURCE_PASSWORD"}'],
            ]], $error->payload);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertNotNull($error->metadata);
            self::assertArrayNotHasKey('data', $error->metadata->uapi ?? []);
            self::assertStringNotContainsString('SYNTHETIC_SOURCE_PASSWORD', $error->getMessage());
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($class, __NAMESPACE__ . '\\')
                ) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(
                        self::containsSyntheticPassword($frame['args'] ?? [], $seen),
                        $class . '::' . $frame['function'],
                    );
                }
            }
            self::assertGreaterThan(0, $frames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<int, true> $seen */
    private static function containsSyntheticPassword(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'SYNTHETIC_SOURCE_PASSWORD');
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
            foreach ($value as $key => $item) {
                if (
                    str_contains((string) $key, 'SYNTHETIC_SOURCE_PASSWORD')
                    || self::containsSyntheticPassword($item, $seen)
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    private static function envelope(string $route, string $payload): string
    {
        $result = '{"status":1,"data":' . $payload . ',"errors":null,"messages":null,"metadata":{"transformed":1}}';
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
