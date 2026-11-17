<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Mailboxes;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
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

/** @phpstan-type MailboxesCall Closure(Mailboxes): mixed */
final class UapiMailboxesTest extends TestCase
{
    public function testMailboxGroupIsDiscoverable(): void
    {
        [$client] = self::client('direct');
        self::assertInstanceOf(Mailboxes::class, $client->uapi()->mailboxes());
    }

    /** @return array<string, MailboxesCall> */
    private static function calls(): array
    {
        return [
            'expunge_mailbox_messages' => static fn (Mailboxes $c): mixed =>
                $c->expungeMailboxMessages('acct+%26&ć', 'INBOX.*+%26&雪', 'TEXT "private +%26&雪"'),
            'expunge_messages_for_mailbox_guid' => static fn (Mailboxes $c): mixed =>
                $c->expungeMessagesForMailboxGuid('acct+%26&ć', 'guid+%26&雪', 'TEXT "private +%26&雪"'),
            'get_mailbox_status_list' => static fn (Mailboxes $c): mixed => $c->getMailboxStatusList('acct+%26&ć'),
            'has_utf8_mailbox_names' => static fn (Mailboxes $c): mixed => $c->hasUtf8MailboxNames(),
            'set_utf8_mailbox_names' => static fn (Mailboxes $c): mixed => $c->setUtf8MailboxNames(false),
        ];
    }

    /** @return array<string, array{string, string, string}> */
    private static function wireFixtures(): array
    {
        $account = 'account=acct%2B%2526%26%C4%87';
        $query = '&query=TEXT%20%22private%20%2B%2526%26%E9%9B%AA%22';
        return [
            'expunge_mailbox_messages' => ['POST', $account . '&mailbox=INBOX.%2A%2B%2526%26%E9%9B%AA' . $query,
                '{"provider":{"arbitrary":[null,false,7]}}'],
            'expunge_messages_for_mailbox_guid' => ['POST',
                $account . '&mailbox_guid=guid%2B%2526%26%E9%9B%AA' . $query,
                '{"provider":{"arbitrary":[null,false,7]}}'],
            'get_mailbox_status_list' => ['GET', $account,
                '[{"guid":"0001","mailbox":"INBOX.雪","messages":0,"vsize":1052299},{}]'],
            'has_utf8_mailbox_names' => ['GET', '', '{"enabled":0}'],
            'set_utf8_mailbox_names' => ['POST', 'enabled=0', '{"success":0}'],
        ];
    }

    /** @return iterable<string, array{string, string, MailboxesCall, string, string, string, mixed}> */
    public static function successes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::calls() as $function => $call) {
                [$method, $wire, $populated] = self::wireFixtures()[$function];
                $payloads = [$populated, '[]'];
                if ($function !== 'get_mailbox_status_list') {
                    $payloads[] = '{}';
                } else {
                    $payloads[] = '[{"messages":0,"vsize":0}]';
                }
                if ($function === 'expunge_mailbox_messages') {
                    array_push($payloads, 'null', 'false', '0', '1.5', '"text"', '[null,true,7]');
                }
                if ($function === 'has_utf8_mailbox_names') {
                    $payloads[] = '{"enabled":1}';
                }
                if ($function === 'set_utf8_mailbox_names') {
                    $payloads[] = '{"success":1}';
                }
                foreach ($payloads as $i => $payload) {
                    yield "$route $function $i" => [$route, $function, $call, $method, $wire, $payload,
                        json_decode($payload, true, 512, JSON_THROW_ON_ERROR)];
                }
            }
            foreach ([null, '', 'user+%26&雪'] as $i => $user) {
                yield "$route optional user $i" => [$route, 'has_utf8_mailbox_names',
                    static fn (Mailboxes $c): mixed => $c->hasUtf8MailboxNames($user), 'GET',
                    $user === null ? '' : 'user=' . rawurlencode($user), '{}', []];
            }
            yield "$route true" => [$route, 'set_utf8_mailbox_names',
                static fn (Mailboxes $c): mixed => $c->setUtf8MailboxNames(true), 'POST', 'enabled=1', '{}', []];
            yield "$route empty expunge strings" => [$route, 'expunge_mailbox_messages',
                static fn (Mailboxes $c): mixed => $c->expungeMailboxMessages('', '', ''), 'POST',
                'account=&mailbox=&query=', 'null', null];
            yield "$route mainaccount alias" => [$route, 'expunge_messages_for_mailbox_guid',
                static fn (Mailboxes $c): mixed =>
                    $c->expungeMessagesForMailboxGuid('_mainaccount@example.test', '', ''), 'POST',
                'account=_mainaccount%40example.test&mailbox_guid=&query=', '{}', []];
        }
    }

    /** @param MailboxesCall $call */
    #[DataProvider('successes')]
    public function testExactSingleRequestAndSourcePayload(
        string $route,
        string $function,
        Closure $call,
        string $method,
        string $wire,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $call($account->mailboxes()));
        self::assertRequest($http, $route, $function, $method, $wire);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Fixture warning'], $metadata->uapiWarnings());
    }

    /** @return iterable<string, array{string, MailboxesCall, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::calls() as $function => $call) {
                $bad = [null]; // An absent data member differs even from mixed/null.
                if ($function !== 'expunge_mailbox_messages') {
                    if ($function !== 'expunge_messages_for_mailbox_guid') {
                        $bad[] = 'null';
                    }
                    array_push($bad, 'true', '1', '1.5', '"text"');
                    if ($function === 'get_mailbox_status_list') {
                        array_push($bad, '{}', '[null]', '[1]', '{"0":{}}');
                        foreach (['guid', 'mailbox', 'messages', 'vsize'] as $field) {
                            $values = in_array($field, ['guid', 'mailbox'], true)
                                ? ['null', '1', 'false'] : ['null', '"01"', 'false', '1.5'];
                            foreach ($values as $value) {
                                $bad[] = '[{"' . $field . '":' . $value . '}]';
                            }
                        }
                    } else {
                        $bad[] = '[{}]';
                        $field = match ($function) {
                            'has_utf8_mailbox_names' => 'enabled',
                            'set_utf8_mailbox_names' => 'success',
                            default => null,
                        };
                        if ($field !== null) {
                            foreach (['null', 'true', '"0"', '2', '-1', '0.0'] as $value) {
                                $bad[] = '{"' . $field . '":' . $value . '}';
                            }
                        }
                    }
                }
                foreach ($bad as $i => $payload) {
                    yield "$route $function $i" => [$route, $call, $payload];
                }
            }
        }
    }

    /** @param MailboxesCall $call */
    #[DataProvider('invalidPayloads')]
    public function testMissingNullWrongRootsAndKnownFieldsRejectWithFreshMetadata(
        string $route,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->mailboxes()->hasUtf8MailboxNames();
        $before = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account->mailboxes());
            self::fail('A contract mismatch must throw.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertNotSame($before, $account->responseMeta());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, MailboxesCall, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::calls() as $function => $call) {
                foreach (['api', 'http', 'json', 'transport', ...($route === 'bridge' ? ['outer'] : [])] as $failure) {
                    yield "$route $function $failure" => [$route, $call, $failure];
                }
            }
        }
    }

    /** @param MailboxesCall $call */
    #[DataProvider('failures')]
    public function testFailuresPrecedePayloadAndNeverRetry(string $route, Closure $call, string $failure): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->mailboxes()->hasUtf8MailboxNames();
        $before = $account->responseMeta();
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $http->addResponse(match ($failure) {
                'api' => new Response(200, [], self::envelope($route, 'null', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"Outer failure"},'
                    . '"data":{"uapi":{"status":1,"data":null}}}'),
                'http' => new Response(503, [], 'not json'),
                default => new Response(200, [], 'not json'),
            });
        }
        try {
            $call($account->mailboxes());
            self::fail('The failed request must throw.');
        } catch (ApiException $error) {
            self::assertNotInstanceOf(PayloadMismatchException::class, $error);
            self::assertSame($failure === 'transport', $error instanceof TransportException);
            if (in_array($failure, ['api', 'outer'], true)) {
                self::assertInstanceOf(ApiErrorException::class, $error);
                self::assertNotSame($before, $error->metadata);
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame($failure === 'api' ? 0 : 1, $error->metadata?->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' ? 1 : null),
                    $error->metadata->whmResult(),
                );
            } else {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<mixed>}> */
    public static function invalidArguments(): iterable
    {
        $signatures = [
            'expungeMailboxMessages' => ['account', 'mailbox', 'query'],
            'expungeMessagesForMailboxGuid' => ['account', 'guid', 'query'],
            'getMailboxStatusList' => ['account'],
            'hasUtf8MailboxNames' => ['user'],
            'setUtf8MailboxNames' => [false],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($signatures as $method => $args) {
                if ($method !== 'hasUtf8MailboxNames') {
                    yield "$route $method missing argument" => [$route, $method, array_slice($args, 0, -1)];
                }
                foreach (array_keys($args) as $position) {
                    $badValues = $method === 'setUtf8MailboxNames' ? [null, 0, 1, '1', []] : [false, 7, 1.5, []];
                    if ($method !== 'hasUtf8MailboxNames') {
                        $badValues[] = null;
                    }
                    foreach ($badValues as $i => $bad) {
                        $invalid = $args;
                        $invalid[$position] = $bad;
                        yield "$route $method $position $i" => [$route, $method, array_values($invalid)];
                    }
                }
            }
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function testNativeWrongTypesFailBeforeMetadataReset(string $route, string $method, array $arguments): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->mailboxes()->hasUtf8MailboxNames();
        $before = $account->responseMeta();
        try {
            $account->mailboxes()->$method(...$arguments);
            self::fail('Strict native arguments must reject wrong types.');
        } catch (TypeError) {
            self::assertSame($before, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testPublicSignaturesAndQuerySensitivity(): void
    {
        foreach (
            [
            'expungeMailboxMessages' => [['account', 'mailbox', 'query'], ['string', 'string', 'string'], 'mixed'],
            'expungeMessagesForMailboxGuid' => [
                ['account', 'mailboxGuid', 'query'], ['string', 'string', 'string'], '?array'],
            'getMailboxStatusList' => [['account'], ['string'], 'array'],
            'hasUtf8MailboxNames' => [['user'], ['?string'], 'array'],
            'setUtf8MailboxNames' => [['enabled'], ['bool'], 'array'],
            ] as $method => [$names, $types, $return]
        ) {
            $reflection = new ReflectionMethod(Mailboxes::class, $method);
            self::assertSame($return, (string) $reflection->getReturnType());
            self::assertSame($names, array_map(static fn ($p): string => $p->getName(), $reflection->getParameters()));
            self::assertSame(
                $types,
                array_map(static fn ($p): string => (string) $p->getType(), $reflection->getParameters()),
            );
            foreach ($reflection->getParameters() as $parameter) {
                if ($parameter->getName() === 'query') {
                    self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                }
                if ($parameter->getName() === 'user') {
                    self::assertNull($parameter->getDefaultValue());
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function sensitiveFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['expungeMailboxMessages', 'expungeMessagesForMailboxGuid'] as $method) {
                foreach (['api', 'json', 'http', 'payload'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    #[DataProvider('sensitiveFailures')]
    public function testQueryAndResponseTraceRedaction(string $route, string $method, string $failure): void
    {
        $secret = 'private-mail-search-marker';
        [$client, $http] = self::client($route);
        $http->addResponse(match ($failure) {
            'http' => new Response(503, [], $secret),
            'json' => new Response(200, [], $secret),
            'api' => new Response(200, [], self::envelope($route, '"' . $secret . '"', 0)),
            // Mixed expunge accepts all present data: use missing data for its payload failure.
            default => new Response(200, [], self::envelope($route, null)),
        });
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $client->uapi('npr')->mailboxes()->$method('account', 'mailbox', $secret);
            self::fail('The synthetic failure must throw.');
        } catch (ApiException $error) {
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
                }
            }
            self::assertGreaterThan(0, $frames);
            self::assertStringNotContainsString($secret, $error->getMessage());
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, string $secret, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $secret);
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
                if (str_contains((string) $key, $secret) || self::hasSecret($item, $secret, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Mailboxes&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Mailboxes/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        self::assertSame(
            $uri,
            (string) $request->getUri(),
        );
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Fixture warning"],"messages":null,"metadata":{}}';
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
