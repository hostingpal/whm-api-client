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
use ReflectionParameter;
use SensitiveParameter;

/** Literal contracts from the pinned DirectoryIndexes, DirectoryPrivacy and DirectoryProtection sources. */
final class UapiDirectoryAccessTest extends TestCase
{
    /** @param array<array-key, mixed>|string|null $expected */
    #[DataProvider('successfulPayloads')]
    public function testExactRequestsAndPayloads(
        string $route,
        string $module,
        string $function,
        string $payload,
        array|string|null $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $module, $function));
        self::assertRequest($http, $route, $module, $function);
        self::assertCount(1, $http->getRequests());

        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, string, array<array-key, mixed>|string|null}> */
    public static function successfulPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                foreach (self::validCases($module, $function) as $index => [$payload, $expected]) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload, $expected];
                }
            }
        }
    }

    /** @param array{auth_name?: string|null, auth_type?: string|null, passwd_file?: string, protected: int} $expected */
    #[DataProvider('disabledProtectionPayloads')]
    public function testDisablingProtectionPreservesNullAuthenticationFields(
        string $route,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $privacy = $account->directoryPrivacy();
        $http->addResponse(new Response(200, [], self::envelope($route, '{"protected":1}')));
        $privacy->isDirectoryProtected(dir: '/home/npr/public_html/example');
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        try {
            $actual = $privacy->configureDirectoryProtection(
                dir: '/home/npr/public_html/example',
                enabled: false,
            );
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::fail('A successful disable must accept null auth_name and auth_type fields.');
        }
        self::assertSame($expected, $actual);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertNotSame($previous, $metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(2, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('dir=%2Fhome%2Fnpr%2Fpublic_html%2Fexample&enabled=0', (string) $request->getBody());
    }

    /** @return iterable<string, array{string, string, array{auth_name?: string|null, auth_type?: string|null, passwd_file?: string, protected: int}}> */
    public static function disabledProtectionPayloads(): iterable
    {
        // Independent literals: PR #34 review 5717732052 observed both null fields on disable.
        $cases = [
            'both null' => ['{"auth_name":null,"auth_type":null,"passwd_file":"","protected":0}',
                ['auth_name' => null, 'auth_type' => null, 'passwd_file' => '', 'protected' => 0]],
            'name null and type omitted' => ['{"auth_name":null,"protected":0}',
                ['auth_name' => null, 'protected' => 0]],
            'type null and name omitted' => ['{"auth_type":null,"protected":0}',
                ['auth_type' => null, 'protected' => 0]],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$payload, $expected]) {
                yield "$route $name" => [$route, $payload, $expected];
            }
        }
    }

    /** @return list<array{string, array<array-key, mixed>|string|null}> */
    private static function validCases(string $module, string $function): array
    {
        if ($function === 'get_indexing' || $function === 'set_indexing') {
            return [
            ['"standard"', 'standard'], ['"disabled"', 'disabled'], ['"inherit"', 'inherit'],
                ['"fancy"', 'fancy'], ['""', ''], ['"future"', 'future']];
        }
        if ($function === 'add_user' || $function === 'delete_user') {
            return [
            ['null', null], ['{}', []], ['[]', []],
                ['{"opaque":{"zero":0,"empty":[],"none":null}}',
                    ['opaque' => ['zero' => 0, 'empty' => [], 'none' => null]]]];
        }
        if ($function === 'list_users') {
            return [
            ['[]', []], ['["example1","example2","", "0","example1"]',
                ['example1', 'example2', '', '0', 'example1']]];
        }
        $privacy = ['auth_name' => "Protected 'example.com'", 'auth_type' => 'Basic',
            'passwd_file' => '/home/example/.htpasswds/example.com/passwd', 'protected' => 1];
        if ($function === 'configure_directory_protection' || $function === 'is_directory_protected') {
            return [
            [json_encode($privacy, JSON_THROW_ON_ERROR), $privacy], ['{}', []],
                ['{"protected":0,"auth_name":"","auth_type":"None","passwd_file":"","future":true}',
                    ['auth_name' => '', 'auth_type' => 'None', 'passwd_file' => '', 'protected' => 0]],
                ['{"protected":1}', ['protected' => 1]]];
        }
        $state = match ($module) {
            'DirectoryIndexes' => ['index_type' => 'inherit'],
            'DirectoryPrivacy' => $privacy,
            default => ['has_leech_protection' => 1],
        };
        $row = ['path' => '/home/example/example.com', 'state' => $state];
        $full = ['children' => [['path' => '/home/example/example.com/cgi-bin', 'state' => $state]],
            'current' => $row, 'home' => ['path' => '/home/example', 'state' => $state],
            'parent' => ['path' => '/home/example', 'state' => $state]];
        $zeroState = match ($module) {
            'DirectoryIndexes' => ['index_type' => ''],
            'DirectoryPrivacy' => ['auth_name' => '', 'auth_type' => 'None', 'passwd_file' => '', 'protected' => 0],
            default => ['has_leech_protection' => 0],
        };
        $zero = ['children' => [], 'current' => ['path' => '', 'state' => $zeroState]];
        return [
        [json_encode($full, JSON_THROW_ON_ERROR), $full], [json_encode($zero, JSON_THROW_ON_ERROR), $zero],
            ['{}', []], ['{"children":[{}, {"state":{}}],"parent":{},"home":{"path":""}}',
                ['children' => [[], ['state' => []]], 'home' => ['path' => ''], 'parent' => []]]];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidOrMissingPayloadRetainsConfirmedOutcome(
        string $route,
        string $module,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validCases($module, $function)[0][0])));
        self::invoke($account, $module, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $module, $function);
            self::fail('Invalid directory payload must be rejected.');
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
        self::assertCount(2, $http->getRequests());
        self::assertRequest($http, $route, $module, $function);
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                $cases = [null, 'false', '1', '1.5'];
                if ($function !== 'add_user' && $function !== 'delete_user') {
                    $cases[] = 'null';
                }
                if ($function === 'get_indexing' || $function === 'set_indexing') {
                    array_push($cases, '{}', '[]');
                } elseif ($function === 'list_users') {
                    array_push($cases, '{}', '"example"', '[0]', '[null]', '[false]', '[{}]');
                } else {
                    array_push($cases, '"text"', '[{}]');
                    if ($function === 'list_directories') {
                        array_push($cases, '{"children":null}', '{"children":{}}', '{"children":[null]}');
                        foreach (['children', 'current', 'home', 'parent'] as $field) {
                            $prefix = $field === 'children' ? '{"children":[{' : '{"' . $field . '":{';
                            $suffix = $field === 'children' ? '}]}' : '}}';
                            foreach (['null', '0', 'true', '[]'] as $bad) {
                                $cases[] = $prefix . '"path":' . $bad . $suffix;
                                if ($bad !== '[]') {
                                    $cases[] = $prefix . '"state":' . $bad . $suffix;
                                }
                            }
                            $fields = match ($module) {
                                'DirectoryIndexes' => ['index_type'],
                                'DirectoryPrivacy' => ['auth_name', 'auth_type', 'passwd_file', 'protected'],
                                default => ['has_leech_protection'],
                            };
                            foreach ($fields as $leaf) {
                                $badValues = in_array($leaf, ['protected', 'has_leech_protection'], true)
                                    ? ['null', '"0"', '"1"', '2', '-1', '1.0', 'true', '[]']
                                    : ['null', '0', 'true', '[]'];
                                foreach ($badValues as $bad) {
                                    $cases[] = $prefix . '"state":{"' . $leaf . '":' . $bad . '}' . $suffix;
                                }
                            }
                        }
                    } elseif (
                        $function === 'configure_directory_protection' || $function === 'is_directory_protected'
                    ) {
                        foreach (['auth_name', 'auth_type', 'passwd_file', 'protected'] as $field) {
                            $badValues = $field === 'protected'
                                ? ['null', '"0"', '"1"', '2', '-1', '1.0', 'true', '[]']
                                : ['null', '0', '1.5', 'true', 'false', '[]', '{}', '[null]'];
                            foreach ($badValues as $bad) {
                                if (
                                    $function === 'configure_directory_protection'
                                    && in_array($field, ['auth_name', 'auth_type'], true)
                                    && $bad === 'null'
                                ) {
                                    continue;
                                }
                                $cases[] = '{"' . $field . '":' . $bad . '}';
                            }
                        }
                    }
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('operationRoutes')]
    public function testOrdinaryApiFailurePrecedesPayloadValidation(
        string $route,
        string $module,
        string $function,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        $account = $client->uapi('npr');
        try {
            self::invoke($account, $module, $function);
            self::fail('Status zero is a failure, including is_directory_protected.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                yield "$route $module/$function" => [$route, $module, $function];
            }
        }
    }

    /** @param array<array-key, mixed>|string|null $expected */
    #[DataProvider('pinnedLeafExamples')]
    public function testAllPinnedLeafExamplesThroughRealOperationValidation(
        string $route,
        string $module,
        string $function,
        array|string|null $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, json_encode($expected, JSON_THROW_ON_ERROR))));
        self::assertSame($expected, self::invoke($client->uapi('npr'), $module, $function));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>|string|null}> */
    public static function pinnedLeafExamples(): iterable
    {
        $bytes = file_get_contents(__DIR__ . '/../Fixtures/DirectoryAccess/pinned-leaf-examples.json');
        self::assertIsString($bytes);
        /** @var list<array{module: string, function: string, payload: array<array-key, mixed>|string|null}> $fixtures */
        $fixtures = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(10, $fixtures);
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($fixtures as $fixture) {
                yield $route . ' ' . $fixture['module'] . '/' . $fixture['function'] =>
                    [$route, $fixture['module'], $fixture['function'], $fixture['payload']];
            }
        }
    }

    #[DataProvider('routes')]
    public function testConditionalAuthnameOmissionAndRelativePaths(string $route): void
    {
        [$client, $http] = self::client($route);
        $privacy = $client->uapi('npr')->directoryPrivacy();
        foreach ([false, true] as $enabled) {
            $http->addResponse(new Response(200, [], self::envelope($route, '{"protected":0}')));
            $privacy->configureDirectoryProtection(dir: './public_html', enabled: $enabled);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame(
                'dir=.%2Fpublic_html&enabled=' . ($enabled ? '1' : '0'),
                (string) $request->getBody(),
            );
        }
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $privacy->configureDirectoryProtection(dir: '.', enabled: true, authname: 'a +&=%/雪');
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'dir=.&enabled=1&authname=a%20%2B%26%3D%25%2F%E9%9B%AA',
            (string) $request->getBody(),
        );
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $client->uapi('npr')->directoryProtection()->listDirectories(dir: './public_html');
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertStringEndsWith('dir=.%2Fpublic_html', (string) $request->getUri());
        self::assertCount(4, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    public function testSelectorsCachePerAccountAndPasswordIsSensitive(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($first->directoryIndexes(), $first->directoryIndexes());
        self::assertSame($first->directoryPrivacy(), $first->directoryPrivacy());
        self::assertSame($first->directoryProtection(), $first->directoryProtection());
        self::assertNotSame($first->directoryIndexes(), $other->directoryIndexes());
        self::assertNotSame($first->directoryPrivacy(), $other->directoryPrivacy());
        self::assertNotSame($first->directoryProtection(), $other->directoryProtection());
        self::assertSame([], $http->getRequests());
        $password = new ReflectionParameter([$first->directoryPrivacy(), 'addUser'], 'password');
        self::assertCount(1, $password->getAttributes(SensitiveParameter::class));
        $http->addResponse(new Response(200, [], self::envelope('bridge', '"inherit"')));
        $first->directoryIndexes()->getIndexing('/home/npr/public_html');
        $metadata = $first->responseMeta();
        $first->directoryPrivacy();
        $first->directoryProtection();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @return list<array{string, string}> */
    private static function operations(): array
    {
        return [
        ['DirectoryIndexes', 'get_indexing'], ['DirectoryIndexes', 'list_directories'],
            ['DirectoryIndexes', 'set_indexing'], ['DirectoryPrivacy', 'add_user'],
            ['DirectoryPrivacy', 'configure_directory_protection'], ['DirectoryPrivacy', 'delete_user'],
            ['DirectoryPrivacy', 'is_directory_protected'], ['DirectoryPrivacy', 'list_directories'],
            ['DirectoryPrivacy', 'list_users'], ['DirectoryProtection', 'list_directories']];
    }

    private static function invoke(UapiClient $account, string $module, string $function): mixed
    {
        $dir = '/home/npr/public_html/a +&=%/雪';
        return match ($module . '/' . $function) {
            'DirectoryIndexes/get_indexing' => $account->directoryIndexes()->getIndexing(dir: $dir),
            'DirectoryIndexes/list_directories' => $account->directoryIndexes()->listDirectories(dir: $dir),
            'DirectoryIndexes/set_indexing' => $account->directoryIndexes()->setIndexing(dir: $dir, type: 'inherit'),
            'DirectoryPrivacy/add_user' => $account->directoryPrivacy()->addUser(
                dir: $dir,
                user: 'example1', password: 'p a+&=%/雪',
            ),
            'DirectoryPrivacy/delete_user' => $account->directoryPrivacy()->deleteUser(dir: $dir, user: 'example1'),
            'DirectoryPrivacy/configure_directory_protection' =>
                $account->directoryPrivacy()->configureDirectoryProtection(
                    dir: $dir,
                    enabled: false, authname: '',
                ),
            'DirectoryPrivacy/is_directory_protected' => $account->directoryPrivacy()->isDirectoryProtected(dir: $dir),
            'DirectoryPrivacy/list_directories' => $account->directoryPrivacy()->listDirectories(dir: $dir),
            'DirectoryPrivacy/list_users' => $account->directoryPrivacy()->listUsers(dir: $dir),
            default => $account->directoryProtection()->listDirectories(dir: $dir),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $module, string $function): void
    {
        $dir = 'dir=%2Fhome%2Fnpr%2Fpublic_html%2Fa%20%2B%26%3D%25%2F%E9%9B%AA';
        $parameters = match ($function) {
            'set_indexing' => $dir . '&type=inherit',
            'add_user' => $dir . '&user=example1&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'delete_user' => $dir . '&user=example1',
            'configure_directory_protection' => $dir . '&enabled=0&authname=',
            default => $dir,
        };
        $post = in_array(
            $function,
            ['set_indexing', 'add_user', 'delete_user', 'configure_directory_protection'],
            true,
        );
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        if (!$post) {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($post ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($post) {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]');
        $result .= ',"messages":null,"warnings":null,"metadata":{}}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
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
