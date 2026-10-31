<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\ActiveSessions;
use HostingPal\WhmApiClient\Api\Uapi\Session;
use HostingPal\WhmApiClient\Api\Uapi\WebmailApps;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Independent literal fixtures from the pinned source, never from generated specs. */
final class UapiWebmailSessionsTest extends TestCase
{
    public function testCachedAccessors(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            self::assertContains('session', get_class_methods($account));
            self::assertSame($account->session(), $account->session());
            self::assertSame($account->activeSessions(), $account->activeSessions());
            self::assertSame($account->webmailApps(), $account->webmailApps());
            self::assertCount(0, $http->getRequests());
            self::assertNotContains('createTempUser', get_class_methods($account->session()));
        }
    }

    /** @return array<string, array{string, string, string, string, list<mixed>, string, string}> */
    private static function operations(): array
    {
        return [
            'mail user' => ['session', 'Session', 'create_webmail_session_for_mail_user',
                'createWebmailSessionForMailUser', ['MiXeD.雪+%.test', 'Lo+&=%雪', 'EN+%', '2001:DB8::1%eth+0'],
                'domain=MiXeD.%E9%9B%AA%2B%25.test&login=Lo%2B%26%3D%25%E9%9B%AA&locale=EN%2B%25'
                    . '&remote_address=2001%3ADB8%3A%3A1%25eth%2B0',
                '{"hostname":null,"session":"opaque:雪+%=&/","token":"/cpsess+%雪"}'],
            'password' => ['session', 'Session', 'create_webmail_session_for_mail_user_check_password',
                'createWebmailSessionForMailUserCheckPassword',
                ['MiXeD.雪+%.test', 'Lo+&=%雪', 'Pa+&=%雪', 'EN+%', '2001:DB8::1%eth+0'],
                'domain=MiXeD.%E9%9B%AA%2B%25.test&login=Lo%2B%26%3D%25%E9%9B%AA&password=Pa%2B%26%3D%25%E9%9B%AA'
                    . '&locale=EN%2B%25&remote_address=2001%3ADB8%3A%3A1%25eth%2B0',
                '{"hostname":"MiXeD.雪+%.test","session":"opaque:雪+%=&/","token":"/cpsess+%雪"}'],
            'self' => ['session', 'Session', 'create_webmail_session_for_self', 'createWebmailSessionForSelf',
                ['EN+%', '2001:DB8::1%eth+0'], 'locale=EN%2B%25&remote_address=2001%3ADB8%3A%3A1%25eth%2B0',
                '{"hostname":"","session":"","token":""}'],
            'active' => ['activeSessions', 'ActiveSessions', 'list_active_sessions', 'listActiveSessions', [], '',
                '[{"ip_address":"","login_theme":"Future+%雪","last_active":0,"is_current":1,'
                    . '"is_possessed":0,"tfa_verified":0},{"ip_address":"2001:DB8::1","last_active":-1,'
                    . '"is_current":0,"is_possessed":1,"tfa_verified":1},{}]'],
            'apps' => ['webmailApps', 'WebmailApps', 'list_webmail_apps', 'listWebmailApps', ['MiXeD+%雪'],
                'theme=MiXeD%2B%25%E9%9B%AA', '[{"displayname":"Future 雪","icon":"../+%雪/icon",'
                    . '"id":"unlisted","url":"https://never-fetch.test:2096/cpsess+%雪/?a=%2F+"},{}]'],
        ];
    }

    /** @return iterable<string, array{string, string, list<mixed>, string, string}> */
    public static function successes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::operations() as $key => [$accessor, , , , $args, $wire, $payload]) {
                yield "$route $key populated" => [$route, $key, $args, $wire, $payload];
                yield "$route $key empty" => [$route, $key, $args, $wire, $accessor === 'session' ? '{}' : '[]'];
                if ($accessor === 'session') {
                    yield "$route $key null hostname" => [$route, $key, $args, $wire, '{"hostname":null}'];
                    yield "$route $key omitted hostname" => [$route, $key, $args, $wire, '{"session":"x","token":"y"}'];
                    yield "$route $key empty credentials" => [$route, $key, $args, $wire,
                        '{"hostname":"","session":"","token":""}'];
                }
            }
            foreach (
                [
                ['mail user', ['D', 'L'], 'domain=D&login=L'],
                ['mail user', ['D', 'L', null, null], 'domain=D&login=L'],
                ['mail user', ['', '', '', ''], 'domain=&login=&locale=&remote_address='],
                ['password', ['D', 'L', 'P'], 'domain=D&login=L&password=P'],
                ['password', ['D', 'L', 'P', null, null], 'domain=D&login=L&password=P'],
                ['password', ['', '', '', '', ''], 'domain=&login=&password=&locale=&remote_address='],
                ['self', [], ''], ['self', [null, null], ''], ['self', ['', ''], 'locale=&remote_address='],
                ['apps', [], ''], ['apps', [null], ''], ['apps', [''], 'theme='],
                ] as $i => [$key, $args, $wire]
            ) {
                yield "$route optional $i" => [$route, $key, $args, $wire, $key === 'apps' ? '[]' : '{}'];
            }
        }
    }

    /** @param list<mixed> $args */
    #[DataProvider('successes')]
    public function testLiteralRequestsAndExactPayloads(
        string $route,
        string $key,
        #[SensitiveParameter] array $args,
        string $wire,
        #[SensitiveParameter] string $payload,
    ): void {
        [$client, $http] = self::client($route);
        [$accessor, $module, $function] = self::operations()[$key];
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $result = self::invoke($client, $key, $args);
        self::assertSame(
            self::ordered(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)),
            self::ordered($result),
        );
        self::assertSame(1, $client->uapi('npr')->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
        $method = $accessor === 'session' ? 'POST' : 'GET';
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        if ($method === 'GET' && $wire !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $wire;
        }
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function mismatches(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::operations() as $key => [$accessor]) {
                $payloads = [null, 'null', 'true', '0', '"bad"'];
                if ($accessor === 'session') {
                    $payloads = [...$payloads, '[{}]', '{"hostname":0}', '{"session":null}', '{"token":false}'];
                } else {
                    $payloads = [...$payloads, '{}', '[null]', '[[1]]', '[0]'];
                    $fields = $key === 'active'
                        ? ['ip_address', 'login_theme', 'last_active', 'is_current', 'is_possessed', 'tfa_verified']
                        : ['displayname', 'icon', 'id', 'url'];
                    foreach ($fields as $field) {
                        $invalids = in_array($field, ['is_current', 'is_possessed', 'tfa_verified'], true)
                            ? ['null', 'true', '"1"', '2', '0.0']
                            : ($field === 'last_active'
                                ? ['null', '"1"', '1.5', 'true'] : ['null', '0', 'false', '[]']);
                        foreach ($invalids as $invalid) {
                            $payloads[] = '[{"' . $field . '":' . $invalid . '}]';
                        }
                    }
                }
                foreach ($payloads as $i => $payload) {
                    yield "$route $key $i" => [$route, $key, $payload];
                }
            }
        }
    }

    #[DataProvider('mismatches')]
    public function testStrictShapesAndAbsentPayload(
        string $route,
        string $key,
        #[SensitiveParameter] ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($client, $key, self::operations()[$key][4]);
            self::fail('Invalid source shape must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $client->uapi('npr')->responseMeta()?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testInheritedEmptyObjectArrayCompatibility(): void
    {
        // Associative JSON decoding conflates these empty object positions; see 8x0.2.50.
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            foreach (self::operations() as $key => [$accessor]) {
                $payload = $accessor === 'session' ? '[]' : '[[]]';
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                self::assertSame(
                    $accessor === 'session' ? [] : [[]],
                    self::invoke($client, $key, self::operations()[$key][4]),
                );
            }
            self::assertCount(5, $http->getRequests());
        }
    }

    public function testNamedArguments(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            foreach (['{}', '{}', '{}', '[]', '[]'] as $payload) {
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
            }
            self::assertSame([], $account->session()->createWebmailSessionForMailUser(
                remoteAddress: '',
                locale: '',
                login: '',
                domain: '',
            ));
            self::assertSame([], $account->session()->createWebmailSessionForMailUserCheckPassword(
                remoteAddress: '',
                locale: '',
                password: '',
                login: '',
                domain: '',
            ));
            self::assertSame([], $account->session()->createWebmailSessionForSelf(remoteAddress: '', locale: ''));
            self::assertSame([], $account->activeSessions()->listActiveSessions());
            self::assertSame([], $account->webmailApps()->listWebmailApps(theme: ''));
            self::assertSame([
                'domain=&login=&locale=&remote_address=',
                'domain=&login=&password=&locale=&remote_address=',
                'locale=&remote_address=', '', '',
            ], array_map(static fn (RequestInterface $r): string => (string) $r->getBody(), $http->getRequests()));
            self::assertCount(5, $http->getRequests());
        }
    }

    public function testUnknownSiblingsAreFiltered(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            foreach (self::operations() as $key => [$accessor]) {
                $payload = $accessor === 'session' ? '{"extra":"private","token":"x"}' : '[{"extra":"private"}]';
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                self::assertSame(
                    $accessor === 'session' ? ['token' => 'x'] : [[]],
                    self::invoke($client, $key, self::operations()[$key][4]),
                );
            }
            self::assertCount(5, $http->getRequests());
        }
    }

    public function testSignaturesAndPrivacyAnnotations(): void
    {
        $expected = [
            [Session::class, 'createWebmailSessionForMailUser',
                ['domain' => 'string', 'login' => 'string', 'locale' => '?string', 'remoteAddress' => '?string']],
            [Session::class, 'createWebmailSessionForMailUserCheckPassword',
                ['domain' => 'string', 'login' => 'string', 'password' => 'string', 'locale' => '?string',
                    'remoteAddress' => '?string']],
            [Session::class, 'createWebmailSessionForSelf', ['locale' => '?string', 'remoteAddress' => '?string']],
            [ActiveSessions::class, 'listActiveSessions', []],
            [WebmailApps::class, 'listWebmailApps', ['theme' => '?string']],
        ];
        foreach ($expected as [$class, $method, $parameters]) {
            $reflection = new ReflectionMethod($class, $method);
            self::assertSame('array', (string) $reflection->getReturnType());
            self::assertSame(array_keys($parameters), array_map(
                static fn ($p): string => $p->getName(),
                $reflection->getParameters(),
            ));
            foreach ($reflection->getParameters() as $parameter) {
                $name = $parameter->getName();
                $type = $parameters[$name] ?? null;
                self::assertNotNull($type);
                self::assertSame($type, (string) $parameter->getType());
                self::assertSame(str_starts_with($type, '?'), $parameter->isOptional());
                if ($parameter->isOptional()) {
                    self::assertNull($parameter->getDefaultValue());
                }
                self::assertCount(
                    in_array($name, ['login', 'password', 'remoteAddress'], true) ? 1 : 0,
                    $parameter->getAttributes(SensitiveParameter::class),
                );
            }
        }
    }

    public function testNativeErrorsPrecedeEntryAndRetainMetadata(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
            $account = $client->uapi('npr');
            $account->activeSessions()->listActiveSessions();
            $meta = $account->responseMeta();
            foreach (
                [
                ['mail user', []], ['mail user', ['D']], ['mail user', [0, 'L']],
                ['mail user', ['D', false]], ['mail user', ['D', 'L', 0]], ['mail user', ['D', 'L', null, 0]],
                ['password', ['D', 'L']], ['password', ['D', 'L', 0]],
                ['self', [false]], ['self', [null, 1]], ['apps', [0]],
                ] as [$key, $args]
            ) {
                try {
                    self::invoke($client, $key, $args);
                    self::fail('Native signature failure must precede method entry.');
                } catch (TypeError) {
                    self::assertSame($meta, $account->responseMeta());
                }
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (array_keys(self::operations()) as $key) {
                foreach (['api', 'http', 'json', 'transport', 'payload'] as $failure) {
                    yield "$route $key $failure" => [$route, $key, $failure];
                }
                if ($route === 'bridge') {
                    yield "$route $key outer" => [$route, $key, 'outer'];
                }
            }
        }
    }

    #[DataProvider('failures')]
    public function testFailureOrderingResetSnapshotsAndPrivacy(string $route, string $key, string $failure): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account = $client->uapi('npr');
        $account->activeSessions()->listActiveSessions();
        $previous = $account->responseMeta();
        $marker = 'synthetic-private-response+%雪';
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $payload = json_encode($marker, JSON_THROW_ON_ERROR);
            $body = match ($failure) {
                'api' => self::envelope($route, self::operations()[$key][6], 0),
                'http' => self::envelope($route, $payload, 0),
                'json' => $marker,
                'outer' => '{"metadata":{"result":0,"reason":"Synthetic failure"},'
                    . '"data":{"uapi":{"status":0,"data":"' . $marker . '"}}}',
                default => self::envelope($route, $payload),
            };
            $http->addResponse(new Response($failure === 'http' ? 503 : 200, [], $body));
        }
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($client, $key, self::operations()[$key][4]);
            self::fail('Synthetic failure must throw.');
        } catch (ApiException $error) {
            self::assertInstanceOf(match ($failure) {
                'api', 'outer' => ApiErrorException::class, 'http' => HttpException::class,
                'json' => InvalidResponseException::class, 'payload' => PayloadMismatchException::class,
                default => TransportException::class,
            }, $error);
            self::assertNotSame($previous, $account->responseMeta());
            if (in_array($failure, ['json', 'transport'], true)) {
                self::assertNull($account->responseMeta());
            } elseif ($failure === 'outer') {
                self::assertSame(0, $account->responseMeta()?->whmResult());
            } else {
                self::assertSame($failure === 'payload' ? 1 : 0, $account->responseMeta()?->uapiStatus());
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertSame($marker, $error->payload);
                self::assertTrue($error->outcomeConfirmed);
            }
            $frames = [];
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (!str_starts_with($class, 'HostingPal\\WhmApiClient\\') || $class === self::class) {
                    continue;
                }
                $frames[] = $frame;
                foreach ($frame['args'] ?? [] as $argument) {
                    if ($argument instanceof SensitiveParameterValue || is_object($argument)) {
                        continue;
                    }
                    $printed = print_r($argument, true);
                    foreach (
                        [$marker, 'opaque:雪+%=&/', '/cpsess+%雪', 'Lo+&=%雪', 'Pa+&=%雪',
                        '2001:DB8::1%eth+0'] as $private
                    ) {
                        self::assertStringNotContainsString($private, $printed);
                    }
                }
            }
            self::assertNotEmpty($frames);
            $operationFrames = array_values(array_filter(
                $frames,
                static fn (array $f): bool => ($f['class'] ?? '') === match ($key) {
                    'active' => ActiveSessions::class, 'apps' => WebmailApps::class, default => Session::class,
                },
            ));
            self::assertCount(1, $operationFrames);
            if ($key !== 'active' && $key !== 'apps') {
                $args = $operationFrames[0]['args'] ?? [];
                $indices = match ($key) {
                    'mail user' => [1, 3], 'password' => [1, 2, 4], default => [1]
                };
                foreach ($indices as $i) {
                    self::assertInstanceOf(SensitiveParameterValue::class, $args[$i]);
                }
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param list<mixed> $arguments */
    private static function invoke(Client $client, string $key, #[SensitiveParameter] array $arguments): mixed
    {
        [$accessor, , , $method] = self::operations()[$key];
        return $client->uapi('npr')->$accessor()->$method(...$arguments);
    }

    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::ordered(...), $value);
    }

    private static function envelope(string $route, #[SensitiveParameter] ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Fixture warning"],"messages":null}';
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
