<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Tokens;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameterValue;

/**
 * Offline contract of uapi()->tokens(), written from the wire layout observed on cPanel 11.138.0.24.
 *
 * Envelopes are spelled here by hand in the observed layout: direct UAPI answers without a result wrapper, mutations
 * carry an empty JSON array as metadata and list carries metadata.transformed. Token secrets are synthetic.
 */
final class UapiTokensTest extends TestCase
{
    private const SECRET = 'PRIVATEFIXTURESECRET0123456789AB';

    public function testSelectorIsLazyCachedAndAccountIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');

        self::assertInstanceOf(Tokens::class, $account->tokens());
        self::assertSame($account->tokens(), $account->tokens());
        self::assertNotSame($account->tokens(), $client->uapi('other')->tokens());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    public function testSignaturesKeepThePinnedArgumentsAndResultKinds(): void
    {
        $signatures = [];
        foreach (['createFullAccess', 'listTokens', 'renameToken', 'revokeToken'] as $name) {
            $method = new ReflectionMethod(Tokens::class, $name);
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = $parameter->getType() . ' ' . $parameter->getName()
                    . ($parameter->isOptional() ? '?' : '');
            }
            $signatures[$name] = implode(', ', $parameters) . ': ' . $method->getReturnType();
        }

        self::assertSame([
            'createFullAccess' => 'string name, ?int expiresAt?, ?bool readonly?: array',
            'listTokens' => ': array',
            'renameToken' => 'string name, string newName: ?array',
            'revokeToken' => 'string name: int',
        ], $signatures);
    }

    #[DataProvider('routes')]
    public function testCreateSendsOneFormPostAndReturnsTheSecretOnce(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"create_time":1794993667,"whitelist_ips":null,"token":"' . self::SECRET . '","unreviewed":"x"}',
        )));

        $created = $account->tokens()->createFullAccess('deploy-key_1', 1795080067, true);

        self::assertSame(
            ['create_time' => 1794993667, 'token' => self::SECRET, 'whitelist_ips' => null],
            $created,
        );
        $request = self::onlyRequest($http);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::uri($route, 'create_full_access'), (string) $request->getUri());
        self::assertSame('name=deploy-key_1&expires_at=1795080067&readonly=1', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    #[DataProvider('createBodies')]
    public function testCreateOmitsNullOptionsAndSendsAnExplicitWritableFlag(
        ?int $expiresAt,
        ?bool $readonly,
        string $body,
    ): void {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '{"create_time":1,"token":"T"}')));

        self::assertSame(
            ['create_time' => 1, 'token' => 'T'],
            $client->uapi()->tokens()->createFullAccess('n', $expiresAt, $readonly),
        );
        self::assertSame($body, (string) self::onlyRequest($http)->getBody());
    }

    /** @return iterable<string, array{?int, ?bool, string}> */
    public static function createBodies(): iterable
    {
        yield 'name only' => [null, null, 'name=n'];
        yield 'explicit writable' => [null, false, 'name=n&readonly=0'];
        yield 'zero expiry is sent, not omitted' => [0, null, 'name=n&expires_at=0'];
        yield 'expiry and read-only' => [1795080067, true, 'name=n&expires_at=1795080067&readonly=1'];
    }

    public function testCreateMismatchKeepsTheSecretInThePayloadAndOutOfLibraryFrames(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope(
            'bridge',
            '{"create_time":"1794993667","token":"' . self::SECRET . '"}',
        )));
        $old = ini_set('zend.exception_ignore_args', '0');
        $error = null;
        try {
            $account->tokens()->createFullAccess('deploy');
        } catch (PayloadMismatchException $caught) {
            $error = $caught;
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }

        self::assertInstanceOf(PayloadMismatchException::class, $error);
        // The API accepted the call, so the token exists: the caller must not retry and still has the only copy.
        self::assertTrue($error->outcomeConfirmed);
        self::assertSame(['create_time' => '1794993667', 'token' => self::SECRET], $error->payload);
        self::assertSame($account->responseMeta(), $error->metadata);
        self::assertStringNotContainsString(self::SECRET, $error->getMessage());
        $frames = 0;
        foreach ($error->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'HostingPal\\WhmApiClient\\') && !str_contains($class, '\\Tests\\')) {
                $frames++;
                self::assertFalse(self::holdsSecret($frame['args'] ?? []), $class . '::' . $frame['function']);
            }
        }
        self::assertGreaterThan(0, $frames);
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testListReadsWithGetAndReturnsObservedRowsWithIntegerExpiry(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"readonly":1,"has_full_access":1,"create_time":1794993667,"expires_at":"1795080067",'
                . '"features":[],"whitelist_ips":null,"name":"expiring","unreviewed":true},'
                . '{"name":"permanent","has_full_access":1,"features":[],"create_time":1791912500,'
                . '"expires_at":null,"whitelist_ips":["192.0.2.10","198.51.100.0/24"],"readonly":0},'
                . '{"name":"documented-integer","expires_at":1795080067},'
                . '{}]',
            '{"transformed":1}',
        )));

        $rows = $account->tokens()->listTokens();

        self::assertSame([
            ['create_time' => 1794993667, 'expires_at' => 1795080067, 'features' => [], 'has_full_access' => 1,
                'name' => 'expiring', 'readonly' => 1, 'whitelist_ips' => null],
            ['create_time' => 1791912500, 'expires_at' => null, 'features' => [], 'has_full_access' => 1,
                'name' => 'permanent', 'readonly' => 0, 'whitelist_ips' => ['192.0.2.10', '198.51.100.0/24']],
            ['expires_at' => 1795080067, 'name' => 'documented-integer'],
            [],
        ], $rows);
        $request = self::onlyRequest($http);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::uri($route, 'list'), (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(['transformed' => 1], $account->responseMeta()?->uapi['metadata'] ?? null);
    }

    public function testListReturnsAnEmptyListForAnAccountWithoutTokens(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '[]', '{"transformed":1}')));

        self::assertSame([], $client->uapi()->tokens()->listTokens());
    }

    #[DataProvider('listMismatches')]
    public function testListRejectsRowsOutsideTheContract(string $payload): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', $payload)));
        $error = null;
        try {
            $account->tokens()->listTokens();
        } catch (PayloadMismatchException $caught) {
            $error = $caught;
        }

        self::assertInstanceOf(PayloadMismatchException::class, $error);
        self::assertTrue($error->outcomeConfirmed);
        self::assertSame($account->responseMeta(), $error->metadata);
    }

    /** @return iterable<string, array{string}> */
    public static function listMismatches(): iterable
    {
        yield 'null root' => ['null'];
        yield 'object root' => ['{"name":"x"}'];
        yield 'expiry text' => ['[{"expires_at":"tomorrow"}]'];
        yield 'expiry fraction string' => ['[{"expires_at":"1795080067.5"}]'];
        yield 'expiry boolean' => ['[{"expires_at":false}]'];
        yield 'creation time string' => ['[{"create_time":"1794993667"}]'];
        yield 'read-only outside 0 and 1' => ['[{"readonly":2}]'];
        yield 'full access boolean' => ['[{"has_full_access":true}]'];
        yield 'name null' => ['[{"name":null}]'];
        yield 'features null' => ['[{"features":null}]'];
        yield 'allowed addresses scalar' => ['[{"whitelist_ips":"192.0.2.10"}]'];
        yield 'allowed address integer' => ['[{"whitelist_ips":[1]}]'];
    }

    #[DataProvider('routes')]
    public function testRenameSendsBothNamesInTheBodyAndReturnsTheObservedNull(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));

        self::assertNull($account->tokens()->renameToken('old-name', 'new_name-2'));

        $request = self::onlyRequest($http);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::uri($route, 'rename'), (string) $request->getUri());
        self::assertSame('name=old-name&new_name=new_name-2', (string) $request->getBody());
    }

    #[DataProvider('routes')]
    public function testRevokeReturnsOneForARemovedTokenAndZeroWhenNoneHadThatName(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '1')));
        $http->addResponse(new Response(200, [], self::envelope($route, '0')));

        self::assertSame(1, $account->tokens()->revokeToken('doomed'));
        $request = self::onlyRequest($http);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::uri($route, 'revoke'), (string) $request->getUri());
        self::assertSame('name=doomed', (string) $request->getBody());

        // The second call is a successful API call too: nothing is thrown for a token that was already gone.
        self::assertSame(0, $account->tokens()->revokeToken('doomed'));
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame([], $account->responseMeta()->errors());
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('revokeMismatches')]
    public function testRevokeNeverReadsTheResultFromMetadataOrGuessesIt(?string $payload, string $metadata): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', $payload, $metadata)));
        $error = null;
        try {
            $account->tokens()->revokeToken('doomed');
        } catch (PayloadMismatchException $caught) {
            $error = $caught;
        }

        self::assertInstanceOf(PayloadMismatchException::class, $error);
        // The call was accepted, so the token may be gone; the raw metadata stays available to the caller.
        self::assertTrue($error->outcomeConfirmed);
        self::assertSame(json_decode($metadata, true), $error->metadata?->uapi['metadata'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{?string, string}> */
    public static function revokeMismatches(): iterable
    {
        yield 'value only in metadata, as the pinned prose describes' => [null, '{"data":1}'];
        yield 'null data with a metadata value' => ['null', '{"data":1}'];
        yield 'boolean' => ['true', '[]'];
        yield 'outside 0 and 1' => ['2', '[]'];
        yield 'text' => ['"removed"', '[]'];
        yield 'object' => ['{"revoked":1}', '[]'];
    }

    #[DataProvider('apiFailures')]
    public function testApiFailuresRaiseWithoutAPayloadAndAreNotRetried(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::failure($route)));
        $error = null;
        try {
            match ($method) {
                'createFullAccess' => $account->tokens()->createFullAccess('taken'),
                'listTokens' => $account->tokens()->listTokens(),
                'renameToken' => $account->tokens()->renameToken('missing', 'other'),
                default => $account->tokens()->revokeToken('missing'),
            };
        } catch (ApiErrorException $caught) {
            $error = $caught;
        }

        self::assertInstanceOf(ApiErrorException::class, $error);
        self::assertSame(0, $error->metadata?->uapiStatus());
        self::assertSame(['The API token “missing” does not exist.'], $error->metadata->uapiErrors());
        self::assertSame($account->responseMeta(), $error->metadata);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function apiFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['createFullAccess', 'listTokens', 'renameToken', 'revokeToken'] as $method) {
                yield "$route $method" => [$route, $method];
            }
        }
    }

    private static function holdsSecret(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient || $depth > 8) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, self::SECRET);
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::holdsSecret($item, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function onlyRequest(MockClient $http): RequestInterface
    {
        $requests = $http->getRequests();
        self::assertCount(1, $requests);

        return $requests[0];
    }

    private static function uri(string $route, string $function): string
    {
        return $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=Tokens&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Tokens/' . $function;
    }

    /** A null payload omits the data member; the string "null" sends an explicit JSON null. */
    private static function envelope(string $route, ?string $payload, string $metadata = '[]'): string
    {
        $result = '{"messages":null,"status":1,"metadata":' . $metadata . ',"errors":null'
            . ($payload === null ? '' : ',"data":' . $payload) . ',"warnings":null}';

        return self::route($route, $result);
    }

    private static function failure(string $route): string
    {
        return self::route($route, '{"status":0,"messages":null,"metadata":[],"data":null,'
            . '"errors":["The API token “missing” does not exist."],"warnings":null}');
    }

    private static function route(string $route, string $result): string
    {
        return $route === 'bridge'
            ? '{"data":{"uapi":' . $result . '},'
                . '"metadata":{"version":1,"result":1,"reason":"OK","command":"uapi_cpanel"}}'
            : $result;
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
