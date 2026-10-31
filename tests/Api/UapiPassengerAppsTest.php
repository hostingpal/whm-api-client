<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\PassengerApps;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameterValue;

/** Literal request and response fixtures independently derived from the pinned PassengerApps source. */
final class UapiPassengerAppsTest extends TestCase
{
    private const SECRET = 'Passenger-Secret+&=%';

    public function testPassengerAppsSelectorBindsAccountsWithoutIoOrMetadataReset(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        self::assertSame($first->passengerApps(), $first->passengerApps());
        self::assertNotSame($first->passengerApps(), $second->passengerApps());
        self::assertNull($first->responseMeta());
        self::assertSame([], $http->getRequests());

        $http->addResponse(new Response(200, [], self::envelope('bridge', 'list_applications', '{}')));
        self::assertSame([], $first->passengerApps()->listApplications());
        $metadata = $first->responseMeta();
        $first->passengerApps();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($second->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'disable_application', 'null')));
        self::assertNull($second->passengerApps()->disableApplication('Ruby App'));
        self::assertRequest($http, 'bridge', 'disable_application', 'name=Ruby%20App', 'other');
        self::assertSame($metadata, $first->responseMeta());
        self::assertNotSame($metadata, $second->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    /** @param array<array-key, mixed>|null $expected */
    #[DataProvider('successfulPayloads')]
    public function testPinnedPayloadsAndExactRequestsAcrossEveryRoute(
        string $route,
        string $function,
        string $payload,
        ?array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));

        self::assertSame($expected, self::invoke($account->passengerApps(), $function));

        self::assertRequest($http, $route, $function, self::body($function));
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic Passenger notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>|null}> */
    public static function successfulPayloads(): iterable
    {
        $cases = [
            ['list_applications', '{}', []],
            ['list_applications', '[]', []],
            ['list_applications', '{"Ruby App":{"base_uri":"/ruby","deployment_mode":"production",'
                . '"deps":{"gem":"0","npm":"npm install","pip":"0","extra":"ignored"},'
                . '"domain":"example.test","enabled":1,"envvars":{"TOKEN":"opaque","N":0,"NULL":null},'
                . '"name":"Ruby App","nodejs":"/usr/bin/node","path":"/home/exampleuser/app",'
                . '"python":"/usr/bin/python","ruby":"/usr/bin/ruby","future":"ignored"},"Second":{}}', [
                    'Ruby App' => ['base_uri' => '/ruby', 'deployment_mode' => 'production',
                        'deps' => ['gem' => '0', 'npm' => 'npm install', 'pip' => '0'],
                        'domain' => 'example.test', 'enabled' => 1,
                        'envvars' => ['TOKEN' => 'opaque', 'N' => 0, 'NULL' => null], 'name' => 'Ruby App',
                        'nodejs' => '/usr/bin/node', 'path' => '/home/exampleuser/app',
                        'python' => '/usr/bin/python', 'ruby' => '/usr/bin/ruby'],
                    'Second' => [],
                ]],
            ['list_applications', '{"nullable":{"envvars":null},"empty":{"envvars":{}},"omitted":{}}',
                ['nullable' => ['envvars' => null], 'empty' => ['envvars' => []], 'omitted' => []]],
            ['list_applications', '{"opaque":{"enabled":{"future":[false,0,null]},'
                . '"envvars":{"TREE":{"nested":["x",0,false]},"LIST":[]}}}',
                ['opaque' => ['enabled' => ['future' => [false, 0, null]],
                    'envvars' => ['TREE' => ['nested' => ['x', 0, false]], 'LIST' => []]]]],
            ['list_applications', '{"off":{"enabled":0},"quoted":{"enabled":"1"},'
                . '"boolean":{"enabled":false},"unknown":{"enabled":null}}',
                ['off' => ['enabled' => 0], 'quoted' => ['enabled' => '1'],
                    'boolean' => ['enabled' => false], 'unknown' => ['enabled' => null]]],
            ['ensure_deps', '{"sse_url":"/sse/UserTasks/task/ensure_deps.log","task_id":"task/id",'
                . '"complete":true}', ['sse_url' => '/sse/UserTasks/task/ensure_deps.log', 'task_id' => 'task/id']],
            ['ensure_deps', '{"task_id":"0"}', ['task_id' => '0']],
            ['ensure_deps', '{}', []],
        ];
        foreach (['edit_application', 'register_application'] as $function) {
            $cases[] = [$function, '{}', []];
            $cases[] = [$function, '{"Ruby App":{"base_uri":"/ruby","deployment_mode":"development",'
                . '"domain":"example.test","enabled":0,"envvars":{"TEXT":"0","N":0,"NONE":null,'
                . '"TREE":{"values":[true,"x"]}},"name":"Ruby App","path":"/home/exampleuser/app",'
                . '"future":"ignored"},"Empty":{}}', [
                    'Ruby App' => ['base_uri' => '/ruby', 'deployment_mode' => 'development',
                        'domain' => 'example.test', 'enabled' => 0, 'envvars' => ['TEXT' => '0', 'N' => 0,
                            'NONE' => null, 'TREE' => ['values' => [true, 'x']]],
                        'name' => 'Ruby App', 'path' => '/home/exampleuser/app'], 'Empty' => [],
                ]];
            $cases[] = [$function, '{"Ruby App":{"enabled":1,"envvars":{}},"0":{"path":""}}',
                ['Ruby App' => ['enabled' => 1, 'envvars' => []], 0 => ['path' => '']]];
        }
        foreach (['disable_application', 'enable_application', 'unregister_application'] as $function) {
            $cases[] = [$function, 'null', null];
            $cases[] = [$function, '{}', []];
            $cases[] = [$function, '[]', []];
            $cases[] = [$function, '{"future":{"nested":[0,false,null]},"value":"0"}',
                ['future' => ['nested' => [0, false, null]], 'value' => '0']];
        }
        foreach (self::routes() as [$route]) {
            foreach ($cases as $index => [$function, $payload, $expected]) {
                yield $route . ' ' . $function . ' ' . $index => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testBadOrMissingPayloadPreservesConfirmedOutcomeWithoutRetry(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, '{}')));
        self::invoke($account->passengerApps(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        try {
            self::invoke($account->passengerApps(), $function);
            self::fail('Invalid or absent Passenger payload must be rejected.');
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
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        foreach (self::functions() as [$function]) {
            foreach ([null, 'false', '0', '"unexpected"', '[{}]'] as $payload) {
                $cases[] = [$function, $payload];
            }
        }
        foreach (['edit_application', 'register_application', 'list_applications'] as $function) {
            foreach (['null', '{"app":null}', '{"app":"bad"}', '{"app":[1]}'] as $payload) {
                $cases[] = [$function, $payload];
            }
            foreach (['base_uri', 'deployment_mode', 'domain', 'name', 'path'] as $field) {
                foreach (['null', '0', 'false', '[]'] as $value) {
                    $cases[] = [$function, '{"app":{"' . $field . '":' . $value . '}}'];
                }
            }
            foreach (['0', 'false', '"bad"', '[1]'] as $value) {
                $cases[] = [$function, '{"app":{"envvars":' . $value . '}}'];
            }
        }
        foreach (['edit_application', 'register_application'] as $function) {
            $cases[] = [$function, '{"app":{"envvars":null}}'];
            foreach (['"0"', '"1"', 'false', 'true', 'null', '2', '1.0', '[]'] as $value) {
                $cases[] = [$function, '{"app":{"enabled":' . $value . '}}'];
            }
        }
        foreach (['nodejs', 'python', 'ruby'] as $field) {
            foreach (['null', '0', 'false', '[]'] as $value) {
                $cases[] = ['list_applications', '{"app":{"' . $field . '":' . $value . '}}'];
            }
        }
        foreach (['gem', 'npm', 'pip'] as $field) {
            foreach (['null', '0', 'false', '[]'] as $value) {
                $cases[] = ['list_applications', '{"app":{"deps":{"' . $field . '":' . $value . '}}}'];
            }
        }
        foreach (['null', 'false', '"bad"', '[1]'] as $value) {
            $cases[] = ['list_applications', '{"app":{"deps":' . $value . '}}'];
        }
        $cases[] = ['ensure_deps', 'null'];
        foreach (['sse_url', 'task_id'] as $field) {
            foreach (['null', '0', 'false', '[]'] as $value) {
                $cases[] = ['ensure_deps', '{"' . $field . '":' . $value . '}'];
            }
        }
        foreach (self::routes() as [$route]) {
            foreach ($cases as [$function, $payload]) {
                yield $route . ' ' . $function . ' ' . ($payload ?? 'missing') => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('operations')]
    public function testApiAndHttpFailuresReplaceMetadataWithoutRetrying(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, '{}')));
        self::invoke($account->passengerApps(), $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, null, 0)));
        try {
            self::invoke($account->passengerApps(), $function);
            self::fail('API failure precedes payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(['Synthetic Passenger failure'], $error->errors());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
        }
        $http->addResponse(new Response(503, [], 'Unavailable'));
        try {
            self::invoke($account->passengerApps(), $function);
            self::fail('HTTP failure clears metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertSame(1, $previous?->uapiStatus());
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('functions')]
    public function testOuterWhmFailurePrecedesInnerPassengerSuccess(string $function): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Denied"},'
            . '"data":{"uapi":{"status":1,"data":{}}}}'));
        try {
            self::invoke($account->passengerApps(), $function);
            self::fail('Outer WHM failure must win.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['Denied'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testOmittedOptionsStayAbsentAndExplicitFalseAndZeroStayPresent(string $route): void
    {
        [$client, $http] = self::client($route);
        $apps = $client->uapi('exampleuser')->passengerApps();
        foreach (['register_application', 'edit_application'] as $function) {
            $http->addResponse(new Response(200, [], self::envelope($route, $function, '{}')));
            if ($function === 'register_application') {
                $apps->registerApplication('example.test', 'Ruby App', '/app');
                self::assertRequest($http, $route, $function, 'domain=example.test&name=Ruby%20App&path=%2Fapp');
            } else {
                $apps->editApplication('Ruby App');
                self::assertRequest($http, $route, $function, 'name=Ruby%20App');
            }
        }
        $http->addResponse(new Response(200, [], self::envelope($route, 'edit_application', '{}')));
        $apps->editApplication(
            'Ruby App',
            clearEnvvars: false,
            enabled: false,
            envvarNames: 'COUNT',
            envvarValues: '0',
        );
        self::assertRequest(
            $http,
            $route,
            'edit_application',
            'name=Ruby%20App&clear_envvars=0&enabled=0&envvar_name=COUNT&envvar_value=0',
        );
        $http->addResponse(new Response(200, [], self::envelope($route, 'edit_application', '{}')));
        $apps->editApplication('Ruby App', clearEnvvars: true, enabled: true);
        self::assertRequest($http, $route, 'edit_application', 'name=Ruby%20App&clear_envvars=1&enabled=1');
        self::assertCount(4, $http->getRequests());
    }

    /** @param array<mixed> $values */
    #[DataProvider('invalidEnvironmentLists')]
    public function testInvalidEnvironmentListsFailBeforeDispatchAndClearMetadata(
        string $method,
        string $parameter,
        array $values,
    ): void {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', 'list_applications', '{}')));
        $account->passengerApps()->listApplications();
        self::assertNotNull($account->responseMeta());
        $arguments = $method === 'registerApplication'
            ? ['domain' => 'example.test', 'name' => 'Ruby App', 'path' => '/app'] : ['name' => 'Ruby App'];
        $arguments[$parameter] = $values;
        try {
            (new ReflectionMethod(PassengerApps::class, $method))->invokeArgs($account->passengerApps(), $arguments);
            self::fail('Malformed repeated environment values must not be dispatched.');
        } catch (InvalidArgumentException) {
            self::assertNull($account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<mixed>}> */
    public static function invalidEnvironmentLists(): iterable
    {
        foreach (['registerApplication', 'editApplication'] as $method) {
            foreach (['envvarNames', 'envvarValues'] as $parameter) {
                $invalid = [[], [1 => 'value'], ['key' => 'value'], [0], [false], [null], [['nested']]];
                foreach ($invalid as $i => $values) {
                    yield $method . ' ' . $parameter . ' ' . $i => [$method, $parameter, $values];
                }
            }
        }
    }

    #[DataProvider('secretFailures')]
    public function testEnvironmentSecretsStayOutOfExceptionTraces(
        string $route,
        string $function,
        bool $payloadFailure,
    ): void {
        [$client, $http] = self::client($route);
        $apps = $client->uapi('exampleuser')->passengerApps();
        $payload = $payloadFailure ? '{"app":{"envvars":{"TOKEN":"' . self::SECRET . '"},"enabled":2}}' : null;
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload, $payloadFailure ? 1 : 0)));
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                if ($function === 'register_application') {
                    $apps->registerApplication(
                        'example.test',
                        'Ruby App',
                        '/app',
                        envvarNames: ['TOKEN', 'EMPTY'],
                        envvarValues: [self::SECRET, ''],
                    );
                } else {
                    $apps->editApplication(
                        'Ruby App',
                        envvarNames: ['TOKEN', 'EMPTY'],
                        envvarValues: [self::SECRET, ''],
                    );
                }
                self::fail('The selected synthetic failure must throw.');
            } catch (ApiErrorException | PayloadMismatchException $error) {
                self::assertInstanceOf(
                    $payloadFailure ? PayloadMismatchException::class : ApiErrorException::class,
                    $error,
                );
                for ($current = $error; $current !== null; $current = $current->getPrevious()) {
                    $seen = [];
                    self::assertFalse(self::containsSecret($current->getTrace(), $seen));
                    self::assertStringNotContainsString(self::SECRET, $current->getTraceAsString());
                    self::assertStringNotContainsString(self::SECRET, $current->getMessage());
                }
            }
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertStringNotContainsString(self::SECRET, (string) $request->getUri());
        self::assertStringContainsString(
            'envvar_value=Passenger-Secret%2B%26%3D%25&envvar_value=',
            (string) $request->getBody(),
        );
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function secretFailures(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (['register_application', 'edit_application'] as $function) {
                foreach ([false, true] as $payloadFailure) {
                    yield $route . ' ' . $function . ' ' . (int) $payloadFailure => [
                        $route, $function, $payloadFailure,
                    ];
                }
            }
        }
    }

    public function testDirectDefaultAccountAndExplicitWhmTarget(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', 'list_applications', '{}')));
        self::assertSame([], $client->uapi()->passengerApps()->listApplications());
        self::assertRequest($http, 'direct', 'list_applications', '');
        [$whm, $whmHttp] = self::client('bridge');
        try {
            $whm->uapi()->passengerApps()->listApplications();
            self::fail('WHM requires an explicit selected cPanel account.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $whmHttp->getRequests());
        }
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
            foreach ($value as $key => $item) {
                if (self::containsSecret((string) $key, $seen) || self::containsSecret($item, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::functions() as [$function]) {
                yield $route . ' ' . $function => [$route, $function];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function functions(): iterable
    {
        foreach (
            ['disable_application', 'edit_application', 'enable_application', 'ensure_deps',
            'list_applications', 'register_application', 'unregister_application'] as $function
        ) {
            yield $function => [$function];
        }
    }

    /** @return array<array-key, mixed>|null */
    private static function invoke(PassengerApps $apps, string $function): ?array
    {
        return match ($function) {
            'disable_application' => $apps->disableApplication('Ruby App'),
            'edit_application' => $apps->editApplication('Ruby App', clearEnvvars: false,
                deploymentMode: 'development', domain: 'new.example.test', enabled: false,
                envvarNames: ['Z_LAST', 'A_FIRST'], envvarValues: ['p a+&=%', '0'],
                newName: 'New App', path: '/home/exampleuser/new app'),
            'enable_application' => $apps->enableApplication('Ruby App'),
            'ensure_deps' => $apps->ensureDeps('/home/exampleuser/app path/', 'npm'),
            'list_applications' => $apps->listApplications(),
            'register_application' => $apps->registerApplication('example.test', 'Ruby App', '/app path',
                baseUri: '/ruby', deploymentMode: 'development', enabled: false,
                envvarNames: ['Z_LAST', 'A_FIRST'], envvarValues: ['p a+&=%', '0']),
            'unregister_application' => $apps->unregisterApplication('Ruby App'),
            default => throw new LogicException('Unknown Passenger function.'),
        };
    }

    private static function body(string $function): string
    {
        return match ($function) {
            'disable_application', 'enable_application', 'unregister_application' => 'name=Ruby%20App',
            'edit_application' => 'name=Ruby%20App&clear_envvars=0&deployment_mode=development'
                . '&domain=new.example.test&enabled=0&envvar_name=Z_LAST&envvar_name=A_FIRST'
                . '&envvar_value=p%20a%2B%26%3D%25&envvar_value=0&new_name=New%20App'
                . '&path=%2Fhome%2Fexampleuser%2Fnew%20app',
            'ensure_deps' => 'app_path=%2Fhome%2Fexampleuser%2Fapp%20path%2F&type=npm',
            'list_applications' => '',
            'register_application' => 'domain=example.test&name=Ruby%20App&path=%2Fapp%20path&base_uri=%2Fruby'
                . '&deployment_mode=development&enabled=0&envvar_name=Z_LAST&envvar_name=A_FIRST'
                . '&envvar_value=p%20a%2B%26%3D%25&envvar_value=0',
            default => throw new LogicException('Unknown Passenger body.'),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $body,
        string $account = 'exampleuser',
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($function === 'list_applications' ? 'GET' : 'POST', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                . '&cpanel.module=PassengerApps&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/PassengerApps/' . $function, (string) $request->getUri());
        self::assertSame($body, (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($function !== 'list_applications') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, string $function, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic Passenger failure"]')
            . ',"messages":null,"warnings":["Synthetic Passenger notice"],"metadata":{"transformed":1}}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"PassengerApps","func":"' . $function . '","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser', 'token' => 'fixture-token'];

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
