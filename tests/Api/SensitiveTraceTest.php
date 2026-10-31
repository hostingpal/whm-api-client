<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Api\Whm\Integrations;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

/**
 * Prove that secret-bearing arguments never reach exception trace arguments.
 *
 * PHP records call arguments in exception traces unless zend.exception_ignore_args
 * is enabled, which is not the default without a php.ini. Every library frame between
 * a public method and the PSR-18 client that receives a secret must therefore mark
 * it with #[SensitiveParameter]. These tests force argument capture on, trigger
 * every API failure type for secret-bearing calls on all three routes, and search
 * every frame argument recursively, including nested arrays, list markers and
 * private object properties. A client double that throws from inside sendRequest()
 * puts HttpTransport::send() on the stack, covering its URI and body marks; only
 * that client's own frame, which necessarily holds the request with the token and
 * any GET query, is left out of the search, because the library cannot redact it.
 */
final class SensitiveTraceTest extends TestCase
{
    private const SECRET = 'S3cr3t-Pw';

    private const TOTP = 528112;

    private const STRING_TOTP = '029817';

    private const BUILDER_SECRETS = [
        'private+&=%',
        'dynamic-map-name-secret',
        'cHJvbXB0+&=%',
        'https://panel.test/route#x',
        'https://user:pass@builder.test/archive+&.zip',
        'owner+other@example.test',
        'owner@example.test',
        'https://panel.test/cpsess123/index.html#x',
        'Example & Co',
        '{"Attribute1":9,"text":"+&=%"}',
        'a note',
        '000-123',
        '1 Main St',
    ];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function generatedLocalFailures(): iterable
    {
        foreach (['whm', 'direct', 'bridged'] as $route) {
            foreach (['GET', 'POST'] as $method) {
                foreach (['static collision', 'static reserved', 'invalid list', 'runtime reserved'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
            if ($route !== 'whm') {
                yield "$route multipart runtime reserved" => [$route, 'multipart', 'runtime reserved'];
            }
        }
    }

    #[DataProvider('generatedLocalFailures')]
    public function testGeneratedLocalFailuresRedactEveryReachableLibraryFrame(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route === 'direct');
        $http->addResponse(new Response(200, [], match ($route) {
            'whm' => '{"metadata":{"result":1}}',
            'direct' => '{"status":1,"data":null}',
            default => '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":null}}}',
        }));
        $handle = $route === 'whm' ? $client->whm() : $client->uapi($route === 'direct' ? null : 'npr');
        $route === 'whm' ? $client->whm()->call('probe') : $client->uapi($route === 'direct' ? null : 'npr')
            ->call('Email', 'probe');
        self::assertNotNull($handle->responseMeta());
        $spec = ['function' => 'probe', 'method' => $method === 'multipart' ? 'POST' : $method,
            'outcome' => 'flag', 'returns' => null,
            'params' => ['values' => ['name' => 'value', 'encoding' => 'numbered']]];
        if ($route !== 'whm') {
            $spec['module'] = 'Email';
        }
        if ($method === 'multipart') {
            $spec['body'] = 'multipart';
        }
        if ($failure === 'static collision') {
            $spec['params']['fixed'] = ['name' => 'value-1', 'encoding' => 'scalar'];
        } elseif ($failure === 'static reserved') {
            $spec['params']['fixed'] = ['name' => 'api.filter.enable', 'encoding' => 'scalar'];
        }
        try {
            if ($failure === 'runtime reserved') {
                // A future expansion can reach this defense. Today's fixed specs cannot:
                // SpecChecker rejects every statically knowable reservation first.
                (new ReflectionMethod($handle, 'beginRequest'))->getClosure($handle)();
                $fields = new OrderedFields();
                $fields->append('map', 'dynamic-map-name-secret', self::SECRET);
                $fields->append('map', 'api.dynamic-map-name-secret', self::SECRET);
                (new ReflectionMethod($handle, 'dispatch'))->getClosure($handle)($spec, $fields);
            } else {
                self::invokeGeneratedRun(
                    $handle,
                    $spec,
                    ['values' => $failure === 'invalid list' ? [self::SECRET, null] : [self::SECRET]],
                );
            }
            self::fail('The local failure must precede I/O.');
        } catch (LogicException $error) {
            self::assertSame(str_starts_with($failure, 'static') ? LogicException::class
                : InvalidArgumentException::class, $error::class);
            self::assertSecretAbsent($error);
            $frames = array_filter($error->getTrace(), static fn (array $frame): bool =>
                str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')
                && !str_contains($frame['class'], '\\Tests\\'));
            $functions = array_column($frames, 'function');
            if ($failure === 'runtime reserved') {
                self::assertContains('assertNoReserved', $functions);
                self::assertContains('request', $functions);
                self::assertContains('dispatch', $functions);
                self::assertNotContains('run', $functions);
            } else {
                self::assertContains('run', $functions);
                self::assertNotContains('dispatch', $functions);
                self::assertSame($failure === 'invalid list', in_array('encode', $functions, true));
                self::assertSame($failure === 'invalid list', in_array(
                    ArgumentEncoder::class,
                    array_column($frames, 'class'),
                    true,
                ));
                self::assertContains(OperationRunner::class, array_column($frames, 'class'));
            }
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests(), 'The priming request must remain the only I/O.');
        }
    }

    /**
     * Enter the actual handle callback without adding ReflectionMethod::invoke arguments to the trace.
     *
     * @param array<mixed> $spec
     * @param array<string, mixed> $arguments
     * @throws LogicException The malformed spec or invalid collection fails locally.
     */
    private static function invokeGeneratedRun(
        WhmClient|UapiClient $handle,
        array $spec,
        #[SensitiveParameter] array $arguments,
    ): void {
        (new ReflectionMethod($handle, 'run'))->getClosure($handle)($spec, $arguments);
    }

    public function testDynamicFieldCollisionRedactsNamesAndValuesRecursively(): void
    {
        $fields = new OrderedFields();
        $fields->append('dynamic-map-name-secret', 'dynamic-map-name-secret', self::SECRET);
        try {
            $fields->append('another origin', 'dynamic-map-name-secret', self::SECRET);
            self::fail('Two origins cannot own the same field.');
        } catch (InvalidArgumentException $error) {
            self::assertSecretAbsent($error);
        }
    }

    public function testTraceSearchDetectsUnmarkedDynamicFieldsAndNames(): void
    {
        $fields = new OrderedFields();
        $fields->append('map', 'dynamic-map-name-secret', self::SECRET);
        $leak = static function (mixed $fields): never {
            throw new RuntimeException('Control failure.');
        };
        foreach ([$fields, ['dynamic-map-name-secret' => 'ordinary'], 'dynamic-map-name-secret'] as $value) {
            try {
                $leak($value);
            } catch (RuntimeException $error) {
                $seen = [];
                self::assertTrue(self::contains($error->getTrace(), $seen));
            }
        }
    }

    #[DataProvider('privateLogResponseRoutes')]
    public function testPrivateLogContentStaysOutOfPayloadFailureTrace(string $route): void
    {
        [$client, $http] = self::client($route !== 'bridged');
        $payload = '{"log_content":["' . self::SECRET . '"],"size":false}';
        $result = '{"status":1,"data":' . $payload . '}';
        $body = match ($route) {
            'wrapped' => '{"result":' . $result . '}',
            'bare' => $result,
            default => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
        };
        $http->addResponse(new Response(200, [], $body));
        $account = $client->uapi($route === 'bridged' ? 'npr' : null);
        try {
            $account->logManager()->viewErrorLog('npr.rs', 'docroot_error_log');
            self::fail('Invalid sibling must fail without exposing the private log line.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $exception->metadata);
            self::assertSame(1, $exception->metadata?->uapiStatus());
            self::assertSame($route === 'bridged' ? 1 : null, $exception->metadata->whmResult());
            self::assertSame(['log_content' => [self::SECRET], 'size' => false], $exception->payload);
            self::assertSecretAbsent($exception);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function privateLogResponseRoutes(): iterable
    {
        yield 'wrapped direct' => ['wrapped'];
        yield 'bare direct' => ['bare'];
        yield 'WHM bridge' => ['bridged'];
    }

    public function testTheTraceSearchDetectsUnmarkedArguments(): void
    {
        $leak = static function (string $secret): never {
            throw new RuntimeException('Control failure.');
        };

        try {
            $leak(self::SECRET);
        } catch (RuntimeException $exception) {
            $seen = [];
            self::assertTrue(self::contains($exception->getTrace(), $seen));
        }
    }

    #[DataProvider('builderContactControls')]
    public function testSearchDetectsEveryCreateWebsiteContactValue(string $secret): void
    {
        $leak = static function (string $value): never {
            throw new RuntimeException('Control failure.');
        };
        try {
            $leak($secret);
        } catch (RuntimeException $exception) {
            $seen = [];
            self::assertSame([$secret], $exception->getTrace()[0]['args'] ?? []);
            self::assertTrue(self::contains($exception->getTrace()[0], $seen));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function builderContactControls(): iterable
    {
        yield 'company' => ['Example & Co'];
        yield 'street' => ['1 Main St'];
        yield 'phone' => ['000-123'];
        yield 'note' => ['a note'];
        yield 'metadata' => ['{"Attribute1":9,"text":"+&=%"}'];
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function builderFailures(): iterable
    {
        foreach (['direct', 'bare', 'bridged'] as $route) {
            foreach (
                ['Nova' => ['add_api_token', 'ensure_account', 'get_sso_link', 'publish'],
                'Sitejet' => ['add_api_token', 'create_website', 'get_sso_link']] as $group => $functions
            ) {
                foreach ($functions as $function) {
                    foreach (['api', 'http', 'transport', 'invalid', 'payload'] as $failure) {
                        yield "$route $group $function $failure" => [$route, $group, $function, $failure];
                    }
                }
            }
        }
    }

    #[DataProvider('builderFailures')]
    public function testBuilderFailurePipelinesKeepReviewedSensitiveValuesOutOfTraces(
        string $route,
        string $group,
        string $function,
        string $failure,
    ): void {
        [$client, $http] = self::client($route !== 'bridged');
        if ($failure === 'transport') {
            $http->addException(self::networkFailure());
        } elseif ($failure === 'http') {
            $http->addResponse(new Response(503, [], '{}'));
        } elseif ($failure === 'invalid') {
            $http->addResponse(new Response(200, [], 'invalid json'));
        } else {
            $result = '{"status":' . ($failure === 'api' ? '0' : '1') . ',"data":false}';
            $body = match ($route) {
                'direct' => '{"result":' . $result . '}',
                'bare' => $result,
                default => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            };
            $http->addResponse(new Response(200, [], $body));
        }
        [$method, $arguments] = $group === 'Nova'
            ? UapiNovaTest::fixture($function) : UapiSitejetTest::fixture($function);
        $account = $client->uapi($route === 'bridged' ? 'npr' : null);
        $builder = $group === 'Nova' ? $account->nova() : $account->sitejet();
        try {
            $builder->$method(...$arguments);
            self::fail('Expected builder failure.');
        } catch (ApiException $exception) {
            $expected = match ($failure) {
                'api' => ApiErrorException::class,
                'http' => HttpException::class,
                'transport' => TransportException::class,
                'payload' => PayloadMismatchException::class,
                default => InvalidResponseException::class,
            };
            self::assertInstanceOf($expected, $exception);
            self::assertSecretAbsent($exception);
            self::assertCount(1, $http->getRequests());
            if ($exception instanceof PayloadMismatchException) {
                self::assertTrue($exception->outcomeConfirmed);
                self::assertFalse($exception->payload);
                self::assertSame($account->responseMeta(), $exception->metadata);
            }
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        foreach (self::BUILDER_SECRETS as $secret) {
            self::assertStringNotContainsString($secret, (string) $request->getUri());
        }
    }

    public function testTheTraceSearchDetectsUnmarkedIntegerTotpArguments(): void
    {
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $leak = static function (int $token): never {
            throw new RuntimeException('Control failure.');
        };

        try {
            $leak(self::TOTP);
        } catch (RuntimeException $exception) {
            $seen = [];
            self::assertTrue(self::contains($exception->getTrace(), $seen));
        }
    }

    public function testTheTraceSearchDetectsUnmarkedResponseBodies(): void
    {
        $leak = static function (mixed $response): never {
            throw new RuntimeException('Control failure.');
        };

        try {
            $leak(new Response(200, [], self::SECRET));
        } catch (RuntimeException $exception) {
            $seen = [];
            self::assertTrue(self::contains($exception->getTrace(), $seen));
        }
    }

    /**
     * @param 'whm'|'direct'|'bridged' $route
     * @param Closure(Client): mixed $call
     * @param 'api'|'http'|'transport'|'invalid' $failure
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('failingSecretCalls')]
    public function testFailuresKeepSecretsOutOfTraceArguments(
        string $route,
        Closure $call,
        string $failure,
        string $expected,
    ): void {
        [$client, $http] = self::client($route === 'direct');
        if ($failure === 'transport') {
            $http->addException(self::networkFailure());
        } else {
            $http->addResponse(self::failedResponse($route, $failure));
        }

        try {
            $call($client);
            self::fail('Expected ' . $expected . '.');
        } catch (Throwable $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{'whm'|'direct'|'bridged', Closure(Client): mixed, string, class-string<Throwable>}> */
    public static function failingSecretCalls(): iterable
    {
        $failures = [
            'api' => ApiErrorException::class,
            'http' => HttpException::class,
            'transport' => TransportException::class,
            'invalid' => InvalidResponseException::class,
        ];
        foreach (self::secretCalls() as $name => [$route, $call]) {
            foreach ($failures as $failure => $expected) {
                yield $name . ', ' . $failure => [$route, $call, $failure, $expected];
            }
        }
    }

    /** @return iterable<string, array{RequestMethod}> */
    public static function transportMethods(): iterable
    {
        yield 'POST body' => [RequestMethod::Post];
        yield 'GET query' => [RequestMethod::Get];
    }

    /**
     * A PSR-18 client may throw any Throwable from sendRequest(); it then propagates
     * unwrapped with HttpTransport::send() on the stack, holding the URI and body.
     */
    #[DataProvider('transportMethods')]
    public function testTransportFramesStayRedactedWhenTheClientThrowsInsideSendRequest(RequestMethod $method): void
    {
        $factory = new Psr17Factory();
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Client failure.');
            }
        };
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );

        try {
            $client->whm()->call('passwd', ['user' => 'bob', 'password' => self::SECRET], $method);
            self::fail('Expected the client failure to propagate.');
        } catch (RuntimeException $exception) {
            $frames = $exception->getTrace();
            self::assertSame([$http::class, 'sendRequest'], [$frames[0]['class'] ?? null, $frames[0]['function']]);
            self::assertSame([HttpTransport::class, 'send'], [$frames[1]['class'] ?? null, $frames[1]['function']]);

            // The client's own frame necessarily holds the request it was handed; the
            // library cannot redact it, so only that argument is left out of the search.
            unset($frames[0]['args']);
            $seen = [];
            self::assertFalse(self::contains($frames, $seen), 'A library frame argument contains the secret.');
            self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
        }
    }

    public function testRawJsonTransportFramesStayRedactedWhenClientThrows(): void
    {
        $factory = new Psr17Factory();
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Client failure.');
            }
        };
        foreach (['whm', 'cpanel'] as $type) {
            $client = new Client([
                'host' => 'api.example.test', 'authType' => $type, 'username' => 'npr', 'token' => 'fixture-token',
            ], new HttpTransport($http, $factory, $factory));
            try {
                if ($type === 'whm') {
                    $client->whm()->callJson(
                        'operation',
                        '{"secret":"' . self::SECRET . '"}',
                    );
                } else {
                    $client->uapi()->callJson(
                        'Example',
                        'operation',
                        '{"secret":"' . self::SECRET . '"}',
                    );
                }
                self::fail('Expected client failure.');
            } catch (RuntimeException $exception) {
                $frames = $exception->getTrace();
                self::assertSame([$http::class, 'sendRequest'], [$frames[0]['class'] ?? null, $frames[0]['function']]);
                unset($frames[0]['args']);
                $seen = [];
                self::assertFalse(
                    self::contains($frames, $seen),
                    'A library frame contains raw JSON secrets.',
                );
            }
        }
    }

    public function testTypedJsonPayloadMismatchRedactsInputAndResponse(): void
    {
        [$client, $http] = self::client(true);
        $http->addResponse(new Response(200, [], '{"status":1,"data":[{"domain":"' . self::SECRET
            . '","analyzers":[{"name":"' . self::SECRET . '","enabled_by_user":"1"}]}]}'));
        $caught = null;
        try {
            $client->uapi()->statsManager()->saveConfiguration([
                'changes' => [['domain' => self::SECRET, 'analyzers' => [['name' => self::SECRET, 'enabled' => true]]]],
            ]);
        } catch (Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught);
        self::assertSame(PayloadMismatchException::class, $caught::class);
        self::assertInstanceOf(PayloadMismatchException::class, $caught);
        self::assertSecretAbsent($caught);
        self::assertCount(1, $http->getRequests());
        self::assertSame($client->uapi()->responseMeta(), $caught->metadata);
        self::assertTrue($caught->outcomeConfirmed);
    }

    public function testTypedJsonTransportFramesStayRedactedWhenClientThrows(): void
    {
        $factory = new Psr17Factory();
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Client failure.');
            }
        };
        $client = new Client([
            'host' => 'api.example.test', 'authType' => 'cpanel', 'username' => 'npr', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory));
        $caught = null;
        try {
            $client->uapi()->statsManager()->saveConfiguration([
                'changes' => [[
                    'domain' => self::SECRET, 'analyzers' => [['name' => self::SECRET, 'enabled' => false]],
                ]],
            ]);
        } catch (Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught);
        self::assertSame(RuntimeException::class, $caught::class);
        $frames = $caught->getTrace();
        self::assertSame([$http::class, 'sendRequest'], [$frames[0]['class'] ?? null, $frames[0]['function']]);
        // Only the external client's frame necessarily holds the request it receives.
        unset($frames[0]['args']);
        $seen = [];
        self::assertFalse(self::contains($frames, $seen), 'A library frame contains typed JSON secrets.');
        self::assertNull($client->uapi()->responseMeta());
    }

    public function testGuardedTargetsStayOutOfLocalValidationTraceArguments(): void
    {
        try {
            \HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder::encode(
                ['domains' => ['name' => 'domain', 'encoding' => 'repeat', 'reject_empty' => true]],
                ['domains' => [self::SECRET, '']],
            );
            self::fail('Expected a target-policy failure.');
        } catch (InvalidArgumentException $exception) {
            self::assertSecretAbsent($exception);
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function testPayloadFailuresKeepSecretsOutOfTraceArguments(): void
    {
        [$client, $http] = self::client(false);
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"app":"not a list"}}'));

        try {
            $client->whm()->accounts()->changePassword('bob', self::SECRET);
            self::fail('Expected an invalid payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSecretAbsent($exception);
        }
    }

    #[DataProvider('mysqlRoutes')]
    public function testMysqlWritePayloadFailuresKeepPasswordsOutOfTraceArguments(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $http->addResponse(new Response(200, [], $direct
            ? '{"status":1,"data":false}'
            : '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":false}}}'));

        try {
            $client->uapi($direct ? null : 'npr')->mysql()->createUser('npr_writer', self::SECRET);
            self::fail('Expected an invalid payload after the confirmed MySQL write.');
        } catch (InvalidResponseException $exception) {
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    #[DataProvider('mysqlRoutes')]
    public function testReturnedMysqlPasswordIsRetainedOnlyInExplicitPayloadAfterMismatch(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $result = '{"status":1,"data":{"database_user_password":"' . self::SECRET . '","port":3306}}';
        $http->addResponse(new Response(200, [], $direct ? $result
            : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'));
        try {
            $client->uapi($direct ? null : 'npr')->mysql()->setupDbAndUser(prefix: 'app');
            self::fail('An integer port must fail the pinned string contract.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame(['database_user_password' => self::SECRET, 'port' => 3306], $exception->payload);
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @param class-string<Throwable> $expected */
    #[DataProvider('returnedMysqlPasswordFailures')]
    public function testReturnedMysqlPasswordCannotLeakFromEnvelopeFailures(
        bool $direct,
        int $status,
        string $body,
        string $expected,
    ): void {
        [$client, $http] = self::client($direct);
        $http->addResponse(new Response($status, [], str_replace('returned-password', self::SECRET, $body)));
        try {
            $client->uapi($direct ? null : 'npr')->mysql()->setupDbAndUser(prefix: 'app');
            self::fail('Expected the returned-password response to fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{bool, int, string, class-string<Throwable>}> */
    public static function returnedMysqlPasswordFailures(): iterable
    {
        $data = '{"database_user_password":"returned-password"}';
        yield 'string status' => [true, 200, '{"status":"1","data":' . $data . '}', InvalidResponseException::class];
        yield 'failed status with data' => [true, 200, '{"status":0,"data":' . $data . '}', ApiErrorException::class];
        yield 'missing status' => [true, 200, '{"data":' . $data . '}', InvalidResponseException::class];
        yield 'truncated JSON' => [true, 200, '{"data":' . $data, InvalidResponseException::class];
        yield 'outer WHM failure' => [false, 200,
            '{"metadata":{"result":0},"data":{"uapi":{"status":1,"data":' . $data . '}}}', ApiErrorException::class];
        yield 'bridged UAPI failure' => [false, 200,
            '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":' . $data . '}}}', ApiErrorException::class];
        yield 'HTTP failure' => [true, 500, '{"status":1,"data":' . $data . '}', HttpException::class];
    }

    /**
     * @param 'direct'|'bridged' $route
     * @param Closure(Client): mixed $call
     */
    #[DataProvider('ftpSecretCalls')]
    public function testFtpWritePayloadFailuresKeepPasswordsAndHashesOutOfTraceArguments(
        string $route,
        Closure $call,
    ): void {
        [$client, $http] = self::client($route === 'direct');
        $http->addResponse(new Response(200, [], $route === 'direct'
            ? '{"status":1,"data":false}'
            : '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":false}}}'));

        try {
            $call($client);
            self::fail('Expected an invalid payload after the confirmed FTP write.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{'direct'|'bridged', Closure(Client): mixed}> */
    public static function ftpSecretCalls(): iterable
    {
        foreach (['direct', 'bridged'] as $route) {
            yield $route . ' FTP add password' => [
                $route,
                static fn (Client $client): mixed => $client->uapi($route === 'direct' ? null : 'npr')->ftp()->addFtp(
                    user: 'writer',
                    password: self::SECRET,
                ),
            ];
            yield $route . ' FTP add password hash' => [
                $route,
                static fn (Client $client): mixed => $client->uapi($route === 'direct' ? null : 'npr')->ftp()->addFtp(
                    user: 'writer',
                    passHash: self::SECRET,
                ),
            ];
            yield $route . ' FTP change password' => [
                $route,
                static fn (Client $client): mixed =>
                    $client->uapi($route === 'direct' ? null : 'npr')->ftp()->changePassword('writer', self::SECRET),
            ];
        }
    }


    /**
     * @param 'direct'|'bridged' $route
     * @param Closure(Client): mixed $call
     */
    #[DataProvider('postgresqlPasswordCalls')]
    public function testPostgresqlPayloadFailuresKeepPasswordsOutOfTraceArguments(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route === 'direct');
        $http->addResponse(new Response(200, [], $route === 'direct'
            ? '{"status":1,"data":false}'
            : '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":false}}}'));

        try {
            $call($client);
            self::fail('Expected an invalid payload after the confirmed PostgreSQL write.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame(1, $exception->metadata?->uapiStatus());
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    #[DataProvider('postgresqlResponseRoutes')]
    public function testPostgresqlCreateUserEchoedPasswordStaysOutOfPayloadFailureTrace(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $result = '{"status":1,"data":{"password":"' . self::SECRET . '"}}';
        $http->addResponse(new Response(200, [], $direct
            ? $result
            : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'));

        try {
            $client->uapi($direct ? null : 'npr')->postgresql()->createUser('npr_writer', self::SECRET);
            self::fail('Expected an invalid integer payload after the confirmed PostgreSQL write.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame(1, $exception->metadata?->uapiStatus());
            self::assertSame(['password' => self::SECRET], $exception->payload);
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @param class-string<ApiException> $expected */
    #[DataProvider('postgresqlEchoedOutcomeFailures')]
    public function testPostgresqlEchoedOutcomeSecretsStayOutOfTraceArguments(
        bool $direct,
        string $field,
        string $expected,
    ): void {
        [$client, $http] = self::client($direct);
        $result = ['status' => 0, 'data' => null];
        $whm = ['result' => 1];
        if ($field === 'reason') {
            $whm = ['result' => 0, 'reason' => self::SECRET];
        } else {
            $result[$field] = $field === 'status' ? self::SECRET : [self::SECRET];
            if ($field === 'messages') {
                $result['status'] = 'invalid';
            }
        }
        $document = $direct ? $result : ['metadata' => $whm, 'data' => ['uapi' => $result]];
        $http->addResponse(new Response(200, [], json_encode($document, JSON_THROW_ON_ERROR)));
        $account = $client->uapi($direct ? null : 'npr');

        try {
            $account->postgresql()->createUser('npr_writer', self::SECRET);
            self::fail('Expected the failed or invalid PostgreSQL outcome to be rejected.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertCount(1, $http->getRequests());
            self::assertSame($account->responseMeta(), $exception->metadata);
            $metadata = $field === 'reason' ? $exception->metadata?->whm : $exception->metadata?->uapi;
            self::assertSame(
                $field === 'status' || $field === 'reason' ? self::SECRET : [self::SECRET],
                $metadata[$field] ?? null,
            );
            self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{bool, string, class-string<ApiException>}> */
    public static function postgresqlEchoedOutcomeFailures(): iterable
    {
        yield 'direct errors' => [true, 'errors', ApiErrorException::class];
        yield 'direct messages with invalid status' => [true, 'messages', InvalidResponseException::class];
        yield 'direct secret status' => [true, 'status', InvalidResponseException::class];
        yield 'bridged errors' => [false, 'errors', ApiErrorException::class];
        yield 'bridged outer WHM reason' => [false, 'reason', ApiErrorException::class];
    }

    /** @return iterable<string, array{bool}> */
    public static function postgresqlResponseRoutes(): iterable
    {
        yield 'direct' => [true];
        yield 'bridged' => [false];
    }

    /** @param class-string<Throwable> $expected */
    #[DataProvider('postgresqlEnvelopeFailures')]
    public function testPostgresqlEnvelopeFailuresKeepRawResponsesOutOfLibraryTraceArguments(
        string $route,
        string $failure,
        string $expected,
    ): void {
        [$client, $http] = self::client($route !== 'bridged');
        $status = match ($failure) {
            'malformed' => '"1"',
            'uapi' => '0',
            default => '1',
        };
        $result = '{"status":' . $status . ',"data":{"password":"' . self::SECRET . '"}}';
        $body = match ($route) {
            'direct' => '{"result":' . $result . '}',
            'bare' => $result,
            default => '{"metadata":{"result":' . ($failure === 'whm' ? '0' : '1')
                . '},"data":{"uapi":' . $result . '}}',
        };
        $http->addResponse(new Response(200, [], $body));

        try {
            $client->uapi($route === 'bridged' ? 'npr' : null)->postgresql()->createUser('npr_writer', self::SECRET);
            self::fail('Expected the malformed or failed PostgreSQL envelope to be rejected.');
        } catch (Throwable $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertCount(1, $http->getRequests());
            foreach ($exception->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    !str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    || str_starts_with($class, 'HostingPal\\WhmApiClient\\Tests\\')
                ) {
                    continue;
                }
                $seen = [];
                self::assertFalse(
                    self::containsResponse($frame['args'] ?? [], $seen),
                    $class . '::' . $frame['function'] . ' retains a raw HTTP response in its trace arguments.',
                );
            }
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{string, string, class-string<Throwable>}> */
    public static function postgresqlEnvelopeFailures(): iterable
    {
        foreach (['direct', 'bare', 'bridged'] as $route) {
            yield $route . ' string UAPI status' => [$route, 'malformed', InvalidResponseException::class];
            yield $route . ' failed UAPI status' => [$route, 'uapi', ApiErrorException::class];
        }
        yield 'bridged failed outer WHM result' => ['bridged', 'whm', ApiErrorException::class];
    }

    /** @return iterable<string, array{'direct'|'bridged', Closure(Client): mixed}> */
    public static function postgresqlPasswordCalls(): iterable
    {
        foreach (['direct', 'bridged'] as $route) {
            $account = $route === 'direct' ? null : 'npr';
            yield $route . ' PostgreSQL create user' => [
                $route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->postgresql()->createUser(name: 'npr_writer', password: self::SECRET),
            ];
            yield $route . ' PostgreSQL rename user' => [
                $route,
                static fn (Client $client): mixed => $client->uapi($account)->postgresql()->renameUser(
                    oldName: 'npr_writer',
                    newName: 'npr_reader',
                    password: self::SECRET,
                ),
            ];
            yield $route . ' PostgreSQL set password' => [
                $route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->postgresql()->setPassword(user: 'npr_writer', password: self::SECRET),
            ];
        }
    }

    /**
     * A response can retain the secret in a resource-backed stream that object-property searches cannot read.
     * Library trace frames must redact the response itself, including responses nested in containers.
     *
     * @param array<int, true> $seen
     */
    private static function containsResponse(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue) {
            return false;
        }
        if ($value instanceof ResponseInterface) {
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
                if (self::containsResponse($item, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    #[DataProvider('iniResponseRoutes')]
    public function testIniContentEchoedSecretStaysOutOfPayloadFailureTrace(bool $direct): void
    {
        [$client, $http] = self::client($direct);
        $result = '{"status":1,"data":["' . self::SECRET . '"]}';
        $http->addResponse(new Response(200, [], $direct
            ? $result
            : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'));

        try {
            $client->uapi($direct ? null : 'npr')->langPhp()->phpIniGetUserContent(type: 'home');
            self::fail('Expected an invalid object payload for INI content.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame(1, $exception->metadata?->uapiStatus());
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function iniResponseRoutes(): iterable
    {
        yield 'direct' => [true];
        yield 'bridged' => [false];
    }

    /** @param class-string<Throwable> $expected */
    #[DataProvider('iniEnvelopeFailures')]
    public function testIniEnvelopeFailuresKeepRawResponsesOutOfLibraryTraceArguments(
        string $route,
        string $failure,
        string $expected,
    ): void {
        [$client, $http] = self::client($route !== 'bridged');
        $status = match ($failure) {
            'malformed' => '"1"',
            'uapi' => '0',
            default => '1',
        };
        $result = '{"status":' . $status . ',"data":{"password":"' . self::SECRET . '"}}';
        $body = match ($route) {
            'direct' => '{"result":' . $result . '}',
            'bare' => $result,
            default => '{"metadata":{"result":' . ($failure === 'whm' ? '0' : '1')
                . '},"data":{"uapi":' . $result . '}}',
        };
        $http->addResponse(new Response(200, [], $body));

        try {
            $client->uapi($route === 'bridged' ? 'npr' : null)->langPhp()->phpIniGetUserContent(type: 'home');
            self::fail('Expected the malformed or failed INI envelope to be rejected.');
        } catch (Throwable $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertCount(1, $http->getRequests());
            foreach ($exception->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    !str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    || str_starts_with($class, 'HostingPal\\WhmApiClient\\Tests\\')
                ) {
                    continue;
                }
                $seen = [];
                self::assertFalse(
                    self::containsResponse($frame['args'] ?? [], $seen),
                    $class . '::' . $frame['function'] . ' retains a raw HTTP response in its trace arguments.',
                );
            }
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{string, string, class-string<Throwable>}> */
    public static function iniEnvelopeFailures(): iterable
    {
        foreach (['direct', 'bare', 'bridged'] as $route) {
            yield $route . ' string UAPI status' => [$route, 'malformed', InvalidResponseException::class];
            yield $route . ' failed UAPI status' => [$route, 'uapi', ApiErrorException::class];
        }
        yield 'bridged failed outer WHM result' => ['bridged', 'whm', ApiErrorException::class];
    }

    /** @return iterable<string, array{bool}> */
    public static function mysqlRoutes(): iterable
    {
        yield 'direct' => [true];
        yield 'bridged' => [false];
    }

    /**
     * @param 'whm'|'direct'|'bridged' $route
     * @param Closure(Client): mixed $call
     */
    #[DataProvider('invalidSecretCalls')]
    public function testLocalValidationFailuresKeepSecretsOutOfTraceArguments(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route === 'direct');
        try {
            $call($client);
            self::fail('Expected a local validation failure.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame([], $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{'whm'|'direct'|'bridged', Closure(Client): mixed}> */
    public static function invalidSecretCalls(): iterable
    {
        yield 'WHM raw JSON invalid syntax' => [
            'whm',
            static fn (Client $client): mixed => $client->whm()->callJson('operation', '{"secret":"' . self::SECRET),
        ];
        yield 'direct raw JSON reserved root' => [
            'direct',
            static fn (Client $client): mixed => $client->uapi()->callJson(
                'Example',
                'operation',
                '{"cpanel.user":null,"secret":"' . self::SECRET . '"}',
            ),
        ];
        foreach (['whm', 'direct'] as $route) {
            yield $route . ' raw JSON reserved prefix root' => [
                $route,
                static fn (Client $client): mixed => $route === 'whm'
                    ? $client->whm()->callJson('operation', '{"api\\u002e' . self::SECRET . '":null}')
                    : $client->uapi()->callJson('Example', 'operation', '{"api\\u002e' . self::SECRET . '":null}'),
            ];
        }
        foreach (['whm', 'direct'] as $route) {
            yield $route . ' raw JSON non-object root' => [
                $route,
                static fn (Client $client): mixed => $route === 'whm'
                    ? $client->whm()->callJson('operation', '"' . self::SECRET . '"')
                    : $client->uapi()->callJson('Example', 'operation', '"' . self::SECRET . '"'),
            ];
        }
        yield 'direct raw JSON invalid name' => [
            'direct',
            static fn (Client $client): mixed => $client->uapi()->callJson(
                '../bad',
                'operation',
                '{"secret":"' . self::SECRET . '"}',
            ),
        ];
        yield 'bridged raw JSON rejected route' => [
            'bridged',
            static fn (Client $client): mixed => $client->uapi('npr')->callJson(
                'Example',
                'operation',
                '{"secret":"' . self::SECRET . '"}',
            ),
        ];
        foreach ([RequestMethod::Get, RequestMethod::Post] as $method) {
            $suffix = ' ' . $method->value;
            yield 'WHM call numbered list' . $suffix => [
                'whm',
                static fn (Client $client): mixed => $client->whm()->call('passwd', [
                    'user' => 'bob',
                    'password' => new NumberedList([self::SECRET, 5]),
                ], $method),
            ];
            yield 'WHM call repeated list' . $suffix => [
                'whm',
                static fn (Client $client): mixed => $client->whm()->call('passwd', [
                    'password' => new RepeatedList([self::SECRET, null]),
                ], $method),
            ];
            yield 'direct UAPI call numbered list' . $suffix => [
                'direct',
                static fn (Client $client): mixed => $client->uapi()->call('Email', 'passwd_pop', [
                    'password' => new NumberedList([self::SECRET, []]),
                ], $method),
            ];
            yield 'bridged UAPI call repeated list' . $suffix => [
                'bridged',
                static fn (Client $client): mixed => $client->uapi('npr')->call('Email', 'passwd_pop', [
                    'password' => new RepeatedList([self::SECRET, false]),
                ], $method),
            ];
        }
    }

    /** @return iterable<string, array{'whm'|'direct'|'bridged', Closure(Client): mixed}> */
    private static function secretCalls(): iterable
    {
        yield 'direct typed StatsManager JSON body' => [
            'direct',
            static fn (Client $client): mixed => $client->uapi()->statsManager()->saveConfiguration([
                'changes' => [['domain' => self::SECRET, 'analyzers' => [['name' => self::SECRET, 'enabled' => true]]]],
            ]),
        ];
        yield 'WHM raw JSON body' => [
            'whm',
            static fn (Client $client): mixed => $client->whm()->callJson(
                'operation',
                '{"secret":"' . self::SECRET . '"}',
            ),
        ];
        yield 'direct raw JSON body' => [
            'direct',
            static fn (Client $client): mixed => $client->uapi()->callJson(
                'Example',
                'operation',
                '{"secret":"' . self::SECRET . '"}',
            ),
        ];
        yield 'WHM nonroot transfer password with required true' => [
            'whm',
            static fn (Client $client): mixed => $client->whm()->transfers()->createRemoteUserTransferSession(
                host: 'remote.test',
                password: self::SECRET,
                unrestrictedRestore: true,
            ),
        ];

        foreach (['direct', 'bridged'] as $route) {
            foreach (['cert', 'cabundle'] as $field) {
                yield $route . ' SSL install ' . $field => [
                    $route,
                    static fn (Client $client): mixed => $client->uapi($route === 'direct' ? null : 'npr')->ssl()
                        ->installSsl(...['cert' => 'fixture cert', 'domain' => 'npr.rs', $field => self::SECRET]),
                ];
            }
        }

        foreach (['direct', 'bridged'] as $route) {
            $account = $route === 'direct' ? null : 'npr';
            yield $route . ' INI content' => [
                $route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->langPhp()->phpIniSetUserContent(self::SECRET, 'vhost', 'npr.rs'),
            ];
        }

        yield from self::accountSecuritySecretCalls();

        yield from self::ftpSecretCalls();
        yield from self::postgresqlPasswordCalls();

        yield 'direct MySQL create user' => [
            'direct',
            static fn (Client $client): mixed =>
                $client->uapi()->mysql()->createUser(name: 'npr_writer', password: self::SECRET),
        ];
        yield 'bridged MySQL create user' => [
            'bridged',
            static fn (Client $client): mixed =>
                $client->uapi('npr')->mysql()->createUser(name: 'npr_writer', password: self::SECRET),
        ];
        yield 'direct MySQL set password' => [
            'direct',
            static fn (Client $client): mixed =>
                $client->uapi()->mysql()->setPassword(user: 'npr_writer', password: self::SECRET),
        ];
        yield 'bridged MySQL set password' => [
            'bridged',
            static fn (Client $client): mixed =>
                $client->uapi('npr')->mysql()->setPassword(user: 'npr_writer', password: self::SECRET),
        ];
        yield 'WHM passwd' => [
            'whm',
            static fn (Client $client): mixed => $client->whm()->accounts()->changePassword('bob', self::SECRET),
        ];
        yield 'WHM createacct password' => [
            'whm',
            static fn (Client $client): mixed => $client->whm()->accounts()->createAccount(
                username: 'bob',
                domain: 'bob.example.test',
                password: self::SECRET,
            ),
        ];
        yield 'WHM createacct pass' => [
            'whm',
            static fn (Client $client): mixed => $client->whm()->accounts()->createAccount(
                username: 'bob',
                domain: 'bob.example.test',
                pass: self::SECRET,
            ),
        ];
        yield 'WHM modifyacct Pushbullet token' => [
            'whm',
            static fn (Client $client): mixed => $client->whm()->accounts()->modifyAccount(
                'bob',
                pushbulletAccessToken: self::SECRET,
            ),
        ];

        foreach ([RequestMethod::Get, RequestMethod::Post] as $method) {
            $suffix = ' ' . $method->value;
            yield 'WHM call' . $suffix => [
                'whm',
                static fn (Client $client): mixed => $client->whm()->call(
                    'passwd',
                    ['user' => 'bob', 'password' => self::SECRET],
                    $method,
                ),
            ];
            yield 'WHM call with lists' . $suffix => [
                'whm',
                static fn (Client $client): mixed => $client->whm()->call('passwd', [
                    'user' => 'bob',
                    'password' => [self::SECRET],
                    'repeated' => new RepeatedList([self::SECRET]),
                    'numbered' => new NumberedList(['first', self::SECRET]),
                ], $method),
            ];
            yield 'direct UAPI call' . $suffix => [
                'direct',
                static fn (Client $client): mixed => $client->uapi()->call(
                    'Email',
                    'passwd_pop',
                    ['email' => 'box', 'password' => self::SECRET],
                    $method,
                ),
            ];
            yield 'bridged UAPI call' . $suffix => [
                'bridged',
                static fn (Client $client): mixed => $client->uapi('npr')->call(
                    'Email',
                    'passwd_pop',
                    ['email' => 'box', 'password' => self::SECRET, 'list' => [self::SECRET]],
                    $method,
                ),
            ];
        }
    }

    /** @return iterable<string, array{'direct'|'bridged', Closure(Client): mixed}> */
    public static function accountSecuritySecretCalls(): iterable
    {
        foreach (['direct', 'bridged'] as $route) {
            $account = $route === 'direct' ? null : 'npr';
            yield $route . ' GPG export passphrase' => [$route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->gpg()->exportSecretKey('fixture-key', self::SECRET)];
            yield $route . ' GPG import key' => [$route,
                static fn (Client $client): mixed => $client->uapi($account)->gpg()->importKey(self::SECRET)];
            yield $route . ' GPG generate passphrase' => [$route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->gpg()->generateKey('fixture@example.test', 'Fixture', self::SECRET)];
            yield $route . ' two-factor secret' => [$route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->twoFactorAuth()->setUserConfiguration(self::SECRET, 123456)];
            yield $route . ' two-factor integer token' => [$route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->twoFactorAuth()->setUserConfiguration('fixture-secret', self::TOTP)];
            yield $route . ' two-factor string token' => [$route,
                static fn (Client $client): mixed =>
                    $client->uapi($account)->twoFactorAuth()->setUserConfiguration(
                        'fixture-secret',
                        self::STRING_TOTP,
                    )];
        }
    }

    /**
     * @param 'direct'|'bridged' $route
     * @param Closure(Client): mixed $call
     */
    #[DataProvider('accountSecuritySecretCalls')]
    public function testAccountSecurityPayloadFailuresKeepSecretsOutOfTraceArguments(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route === 'direct');
        $http->addResponse(new Response(200, [], $route === 'direct'
            ? '{"status":1,"data":false}'
            : '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":false}}}'));
        try {
            $call($client);
            self::fail('Expected a payload mismatch after the confirmed account-security operation.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    #[DataProvider('mysqlRoutes')]
    public function testReturnedAccountSecuritySecretsStayOutOfFailureTraceArguments(bool $direct): void
    {
        foreach (['export', 'generate'] as $operation) {
            foreach ([0, 1] as $status) {
                [$client, $http] = self::client($direct);
                $result = '{"status":' . $status . ',"data":["' . self::SECRET . '"]}';
                $http->addResponse(new Response(200, [], $direct ? $result
                    : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'));
                try {
                    $account = $client->uapi($direct ? null : 'npr');
                    if ($operation === 'export') {
                        $account->gpg()->exportSecretKey('fixture-key');
                    } else {
                        $account->twoFactorAuth()->generateUserConfiguration();
                    }
                    self::fail('Expected the secret-bearing response to fail.');
                } catch (ApiErrorException | PayloadMismatchException $exception) {
                    self::assertCount(1, $http->getRequests());
                    self::assertSecretAbsent($exception);
                    if ($status === 1) {
                        self::assertInstanceOf(PayloadMismatchException::class, $exception);
                        self::assertSame([self::SECRET], $exception->payload);
                    } else {
                        self::assertInstanceOf(ApiErrorException::class, $exception);
                    }
                }
            }
        }
    }

    /** @return iterable<string, array{Closure(Integrations): mixed, string}> */
    public static function secretFailures(): iterable
    {
        yield 'create subscriber ID' => [static function (Integrations $g): mixed {
            $g->createIntegrationLink('app', 'billing', '', self::SECRET, '', 'alice', url: 'https://x.test/');
            return null;
        }, '{"metadata":{"result":0,"reason":"Denied"}}'];
        yield 'create fallback URL' => [static function (Integrations $g): mixed {
            $g->createIntegrationLink('app', 'billing', '', '', '', 'alice', url: self::SECRET);
            return null;
        }, '{"metadata":{"result":0,"reason":"Denied"}}'];
        yield 'create token' => [static function (Integrations $g): mixed {
            $g->createIntegrationLink('app', 'billing', '', '', self::SECRET, 'alice', url: 'https://x.test/');
            return null;
        }, '{"metadata":{"result":0,"reason":"Denied"}}'];
        yield 'create autologin URL' => [static function (Integrations $g): mixed {
            $g->createIntegrationLink('app', 'billing', '', '', '', 'alice', autologinTokenUrl: self::SECRET);
            return null;
        }, '{"metadata":{"result":0,"reason":"Denied"}}'];
        yield 'update token' => [
            static fn (Integrations $g): mixed => $g->updateIntegrationLinkToken('app', self::SECRET, 'alice'),
            '{"metadata":{"result":0,"reason":"Denied"}}'];
        foreach (['hook', 'check', 'rollback'] as $field) {
            yield 'edit ' . $field => [static function (Integrations $g) use ($field): mixed {
                $g->editHook('hook-id', ...[$field => self::SECRET]);
                return null;
            }, '{"metadata":{"result":0,"reason":"Denied"}}'];
        }
        yield 'returned config URL on payload mismatch' => [
            static fn (Integrations $g): mixed => $g->getIntegrationLinkUserConfig('app', 'alice'),
            '{"metadata":{"result":1},"data":{"userconfig":{"autologin_token_url":"'
                . '[fixture-secret]' . '","user":false}}}'];
    }

    /** @param Closure(Integrations): mixed $call */
    #[DataProvider('secretFailures')]
    public function testInputAndReturnedIntegrationSecretsStayOutOfExceptionTraces(
        Closure $call,
        string $response,
    ): void {
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $fixtureResponse = str_replace('[fixture-secret]', self::SECRET, $response);
        [$client, $http] = self::client(false);
        $http->addResponse(new Response(200, [], $fixtureResponse));
        try {
            $call($client->whm()->integrations());
            self::fail('Expected fixture failure.');
        } catch (ApiErrorException | PayloadMismatchException $exception) {
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    /** @return iterable<string, array{Closure(Client): mixed, string}> */
    public static function commerceMarketSecrets(): iterable
    {
        yield 'attribute provider first' => [static function (Client $client): mixed {
            $client->whm()->market()->setProductAttribute('provider', 'product', 'attribute', self::SECRET);
            return null;
        }, '{"metadata":{"result":0}}'];
        yield 'storefront URL' => [static function (Client $client): mixed {
            $client->whm()->commerce()->setCommerce(false, self::SECRET);
            return null;
        }, '{"metadata":{"result":0}}'];
        yield 'token input' => [static fn (Client $client): mixed =>
            $client->whm()->commerce()->validateLoginToken(self::SECRET, 'provider', 'https://example.test'),
            '{"metadata":{"result":0}}'];
        yield 'token redirect' => [static fn (Client $client): mixed =>
            $client->whm()->commerce()->validateLoginToken('token', 'provider', self::SECRET),
            '{"metadata":{"result":0}}'];
        yield 'returned token' => [static fn (Client $client): mixed =>
            $client->whm()->commerce()->validateLoginToken('token', 'provider', 'https://example.test'),
            '{"metadata":{"result":1},"data":{"payload":[{"access_token":"[fixture-secret]",'
                . '"refresh_token":false}]}}'];
    }

    /** @param Closure(Client): mixed $call */
    #[DataProvider('commerceMarketSecrets')]
    public function testCommerceAndMarketSecretsStayOutOfExceptionTraces(Closure $call, string $response): void
    {
        [$client, $http] = self::client(false);
        $http->addResponse(new Response(200, [], str_replace('[fixture-secret]', self::SECRET, $response)));
        try {
            $call($client);
            self::fail('Expected fixture failure.');
        } catch (ApiErrorException | PayloadMismatchException $exception) {
            self::assertCount(1, $http->getRequests());
            self::assertSecretAbsent($exception);
        }
    }

    private static function assertSecretAbsent(Throwable $exception): void
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $seen = [];
            self::assertFalse(
                self::contains($current->getTrace(), $seen),
                'A trace argument of ' . $current::class . ' contains the secret.',
            );
            foreach ([self::SECRET, (string) self::TOTP, self::STRING_TOTP, ...self::BUILDER_SECRETS] as $secret) {
                self::assertStringNotContainsString($secret, $current->getTraceAsString());
                self::assertStringNotContainsString($secret, $current->getMessage());
            }
        }
    }

    /**
     * Search a value recursively; objects are searched through all of their properties.
     *
     * PHP replaces marked arguments with SensitiveParameterValue, whose wrapped value
     * is only reachable through an explicit getValue() call, so it counts as redacted.
     * The mock PSR-18 client is skipped: test frames pass the Client facade, which
     * reaches the double that records every request it received, and the request
     * handed to the PSR-18 client necessarily carries the secret.
     *
     * @param array<int, true> $seen
     */
    private static function contains(mixed $value, array &$seen): bool
    {
        if (is_int($value)) {
            return $value === self::TOTP;
        }

        if (is_string($value)) {
            foreach ([self::SECRET, (string) self::TOTP, self::STRING_TOTP, ...self::BUILDER_SECRETS] as $secret) {
                if (str_contains($value, $secret)) {
                    return true;
                }
            }
            return false;
        }

        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }

        if ($value instanceof MessageInterface && self::contains((string) $value->getBody(), $seen)) {
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
                if (self::contains((string) $key, $seen) || self::contains($item, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the route's failed response for one API failure type.
     *
     * @param 'whm'|'direct'|'bridged' $route
     */
    private static function failedResponse(string $route, string $failure): Response
    {
        $failed = [
            'whm' => '{"metadata":{"result":0,"reason":"Denied."}}',
            'direct' => '{"result":{"status":0,"errors":["Denied."],"data":null}}',
            'bridged' => '{"metadata":{"result":1,"reason":"OK"},"data":{"uapi":{"status":0,"errors":["Denied."]}}}',
        ];

        return match ($failure) {
            'api' => new Response(200, [], $failed[$route]),
            'http' => new Response(500, [], '{}'),
            default => new Response(200, [], 'not json'),
        };
    }

    private static function networkFailure(): RuntimeException&NetworkExceptionInterface
    {
        return new class ('Connection refused.') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('POST', 'https://api.example.test/');
            }
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(bool $cpanel): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = $cpanel
            ? ['host' => 'api.example.test', 'authType' => 'cpanel', 'username' => 'npr', 'token' => 'fixture-token']
            : ['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token'];

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
