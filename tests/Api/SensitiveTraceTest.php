<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
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

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
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

    private static function assertSecretAbsent(Throwable $exception): void
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $seen = [];
            self::assertFalse(
                self::contains($current->getTrace(), $seen),
                'A trace argument of ' . $current::class . ' contains the secret.',
            );
            self::assertStringNotContainsString(self::SECRET, $current->getTraceAsString());
            self::assertStringNotContainsString(self::SECRET, $current->getMessage());
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
