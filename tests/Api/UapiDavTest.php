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

/** Independent pinned DAV response and wire contracts; no DAV URLs are fetched. */
final class UapiDavTest extends TestCase
{
    /** @param array<array-key, mixed> $expected */
    #[DataProvider('validPayloads')]
    public function testExactWireAndPayload(string $route, string $function, string $payload, array $expected): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        $post = in_array($function, ['enable_shared_global_addressbook', 'disable_shared_global_addressbook'], true);
        $parameters = match ($function) {
            'enable_shared_global_addressbook', 'disable_shared_global_addressbook',
            'has_shared_global_addressbook' => 'name=owner%2Btag%40example.test',
            'get_calendar_contacts_config' => 'user=owner%2Btag%40example.test',
            default => '',
        };
        self::assertRequest($http, $route, $function, $post ? 'POST' : 'GET', $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>}> */
    public static function validPayloads(): iterable
    {
        foreach (self::operations() as $label => [$route, $function]) {
            $cases = [['{}', []], ['[]', []], ['{"future":"ignored"}', []]];
            if ($function === 'get_calendar_contacts_config') {
                $config = self::config();
                $cases[] = [json_encode($config, JSON_THROW_ON_ERROR), $config];
                $cases[] = ['{"activesync":[],"no_ssl":{},"ssl":{"calendars":[],"contacts":[{}]}}',
                    ['activesync' => [], 'no_ssl' => [], 'ssl' => ['calendars' => [], 'contacts' => [[]]]]];
                $cases[] = ['{"user":"","activesync":{"user":"","server":"","port":0,"enabled":0},'
                    . '"ssl":{"is_self_signed":0}}', ['activesync' => ['enabled' => 0, 'port' => 0,
                        'server' => '', 'user' => ''], 'ssl' => ['is_self_signed' => 0], 'user' => '']];
            } else {
                $key = str_starts_with($function, 'is_') ? 'enabled' : 'shared';
                foreach ($function === 'is_horde_enabled' ? [0, 1, -1] : [0, 1] as $flag) {
                    $cases[] = ['{"' . $key . '":' . $flag . '}', [$key => $flag]];
                }
            }
            foreach ($cases as $index => [$payload, $expected]) {
                yield "$label $index" => [$route, $function, $payload, $expected];
            }
        }
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $calendar = ['description' => 'Private calendar description', 'name' => 'Personal',
            'path' => '/calendars/owner/calendar:one',
            'url' => 'https://dav.example.test:2080/calendars/owner/calendar:one'];
        $contact = ['description' => 'Private contacts description', 'name' => 'Contacts',
            'path' => '/addressbooks/owner/contacts:one',
            'url' => 'https://dav.example.test:2080/addressbooks/owner/contacts:one'];
        $connection = ['calendars' => [$calendar], 'contacts' => [$contact],
            'free_busy' => 'http://dav.example.test:2079/kronolith/fb.php?u=owner',
            'full_server' => 'http://dav.example.test:2079/rpc/principal/owner',
            'port' => 2079, 'server' => 'http://dav.example.test:2079'];
        $secure = ['calendars' => [$calendar], 'contacts' => [$contact],
            'free_busy' => 'http://dav.example.test:2079/kronolith/fb.php?u=owner',
            'full_server' => 'http://dav.example.test:2079/rpc/principal/owner',
            'is_self_signed' => 1, 'port' => 2080, 'server' => 'https://dav.example.test:2080'];
        return ['activesync' => ['enabled' => 1, 'port' => 2091, 'server' => 'dav.example.test',
            'user' => 'owner@example.test'], 'no_ssl' => $connection, 'ssl' => $secure, 'user' => 'owner@example.test'];
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedAndAbsentPayloadsRetainConfirmedFreshMetadata(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Malformed DAV payload must be rejected.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
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
        foreach (self::operations() as $label => [$route, $function]) {
            $cases = [null, 'null', 'false', '1', '1.5', '"text"', '[{}]'];
            if ($function === 'get_calendar_contacts_config') {
                foreach (['activesync', 'no_ssl', 'ssl'] as $field) {
                    foreach (['null', '1', 'false', '[{}]'] as $bad) {
                        $cases[] = '{"' . $field . '":' . $bad . '}';
                    }
                }
                foreach (['no_ssl', 'ssl'] as $section) {
                    foreach (['calendars', 'contacts'] as $list) {
                        foreach (['null', '{}', '[null]', '[1]'] as $bad) {
                            $cases[] = '{"' . $section . '":{"' . $list . '":' . $bad . '}}';
                        }
                        foreach (['description', 'name', 'path', 'url'] as $field) {
                            foreach (['null', '1', 'false', '[]'] as $bad) {
                                $cases[] = '{"' . $section . '":{"' . $list . '":[{"' . $field . '":' . $bad . '}]}}';
                            }
                        }
                    }
                    foreach (['free_busy', 'full_server', 'server'] as $field) {
                        $cases[] = '{"' . $section . '":{"' . $field . '":null}}';
                        $cases[] = '{"' . $section . '":{"' . $field . '":1}}';
                    }
                    foreach (['null', '"02080"', '2080.5', 'false'] as $bad) {
                        $cases[] = '{"' . $section . '":{"port":' . $bad . '}}';
                    }
                }
                foreach (['user', 'server'] as $field) {
                    $cases[] = '{"activesync":{"' . $field . '":null}}';
                    $cases[] = '{"activesync":{"' . $field . '":1}}';
                }
                foreach (['null', '"02091"', '2091.5', 'false'] as $bad) {
                    $cases[] = '{"activesync":{"port":' . $bad . '}}';
                }
                foreach (['null', '"0"', 'false', '2', '0.5'] as $bad) {
                    $cases[] = '{"activesync":{"enabled":' . $bad . '}}';
                    $cases[] = '{"ssl":{"is_self_signed":' . $bad . '}}';
                }
                array_push($cases, '{"user":null}', '{"user":1}');
            } else {
                $key = str_starts_with($function, 'is_') ? 'enabled' : 'shared';
                foreach (
                    $function === 'is_horde_enabled'
                    ? ['null', '"0"', 'false', '0.5', '[]', '{}']
                    : ['null', '"0"', 'false', '2', '-1', '0.5', '[]', '{}'] as $bad
                ) {
                    $cases[] = '{"' . $key . '":' . $bad . '}';
                }
            }
            foreach ($cases as $index => $payload) {
                yield "$label $index" => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('routes')]
    public function testOptionalSelectorsPreserveDefaultOmissionAndEmptyStrings(string $route): void
    {
        [$client, $http] = self::client($route);
        $dav = $client->uapi('npr')->dav();
        foreach (
            ['enable_shared_global_addressbook', 'disable_shared_global_addressbook',
            'has_shared_global_addressbook', 'get_calendar_contacts_config'] as $function
        ) {
            foreach ([null, ''] as $selector) {
                $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
                match ($function) {
                    'enable_shared_global_addressbook' => $dav->enableSharedGlobalAddressbook(name: $selector),
                    'disable_shared_global_addressbook' => $dav->disableSharedGlobalAddressbook(name: $selector),
                    'has_shared_global_addressbook' => $dav->hasSharedGlobalAddressbook(name: $selector),
                    default => $dav->getCalendarContactsConfig(user: $selector),
                };
                $post = str_starts_with($function, 'enable_') || str_starts_with($function, 'disable_');
                self::assertRequest(
                    $http,
                    $route,
                    $function,
                    $post ? 'POST' : 'GET',
                    $selector === null ? '' : ($function === 'get_calendar_contacts_config' ? 'user=' : 'name='),
                );
            }
        }
        self::assertCount(8, $http->getRequests());
    }

    #[DataProvider('operations')]
    public function testApiAndOuterFailuresAreNotRetried(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $body = self::envelope($route, null, 0);
        $http->addResponse(new Response(200, [], $body));
        try {
            self::invoke($account, $function);
            self::fail('UAPI failure must precede payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
            self::assertNotSame($previous, $account->responseMeta());
        }
        if ($route === 'bridge') {
            $http->addResponse(new Response(200, [], str_replace(
                '"result":1',
                '"result":0,"reason":"Outer failure"',
                self::envelope($route, '{}'),
            )));
            try {
                self::invoke($account, $function);
                self::fail('Outer WHM failure must precede UAPI success.');
            } catch (ApiErrorException $error) {
                self::assertSame(0, $error->metadata?->whmResult());
            }
        }
        self::assertCount($route === 'bridge' ? 3 : 2, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testPrivateConfigTextIsAbsentFromLibraryTraceArguments(string $route): void
    {
        $prior = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            [$client, $http] = self::client($route);
            $http->addResponse(new Response(200, [], self::envelope(
                $route,
                '{"user":"Private-DAV-configuration-997","ssl":{"port":"wrong"}}',
            )));
            try {
                $client->uapi('npr')->dav()->getCalendarContactsConfig();
                self::fail('A malformed sibling must fail without leaking private response text.');
            } catch (PayloadMismatchException $error) {
                foreach ($error->getTrace() as $frame) {
                    $class = $frame['class'] ?? '';
                    if (
                        str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                        && !str_contains($class, '\\Tests\\')
                    ) {
                        self::assertFalse(self::containsPrivateText($frame['args'] ?? []));
                    }
                }
            }
            self::assertCount(1, $http->getRequests());
        } finally {
            if ($prior !== false) {
                ini_set('zend.exception_ignore_args', $prior);
            }
        }
    }

    private static function containsPrivateText(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, 'Private-DAV-configuration-997');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsPrivateText($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (
                ['disable_shared_global_addressbook', 'enable_shared_global_addressbook',
                'get_calendar_contacts_config', 'has_shared_global_addressbook',
                'is_dav_service_enabled', 'is_horde_enabled'] as $function
            ) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        return match ($function) {
            'disable_shared_global_addressbook' => $account->dav()->disableSharedGlobalAddressbook(
                name: 'owner+tag@example.test',
            ),
            'enable_shared_global_addressbook' => $account->dav()->enableSharedGlobalAddressbook(
                name: 'owner+tag@example.test',
            ),
            'get_calendar_contacts_config' => $account->dav()->getCalendarContactsConfig(
                user: 'owner+tag@example.test',
            ),
            'has_shared_global_addressbook' => $account->dav()->hasSharedGlobalAddressbook(
                name: 'owner+tag@example.test',
            ),
            'is_dav_service_enabled' => $account->dav()->isDavServiceEnabled(),
            default => $account->dav()->isHordeEnabled(),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
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

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"messages":null,"warnings":null,"metadata":{}}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
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
                . '&cpanel.module=DAV&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/DAV/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }
}
