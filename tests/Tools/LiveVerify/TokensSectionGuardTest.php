<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\TokensSectionGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\TokensSectionRecipe;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class TokensSectionGuardTest extends TestCase
{
    private const DIRECT = 'https://test.oblak.host:2083/execute/Tokens/';

    private const BRIDGE = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
        . '&cpanel.module=Tokens&cpanel.function=';

    /** @var array<string,string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
                'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function phases(): iterable
    {
        $name = 'whmapiclient-live-0a1b2c-';
        yield 'direct list' => ['preflight_direct', 'GET', self::DIRECT . 'list', ''];
        yield 'direct create' => ['create_direct', 'POST', self::DIRECT . 'create_full_access',
            'name=' . $name . 'direct&expires_at=1795080067&readonly=1'];
        yield 'direct rename' => ['rename_direct', 'POST', self::DIRECT . 'rename',
            'name=' . $name . 'direct&new_name=' . $name . 'direct-renamed'];
        yield 'direct revoke' => ['revoke_direct', 'POST', self::DIRECT . 'revoke', 'name=' . $name . 'direct-renamed'];
        yield 'direct cleanup of the original name' => ['cleanup_original_direct', 'POST', self::DIRECT . 'revoke',
            'name=' . $name . 'direct'];
        yield 'bridge list' => ['absent_bridge', 'GET', self::BRIDGE . 'list', ''];
        yield 'bridge create' => ['create_bridge', 'POST', self::BRIDGE . 'create_full_access',
            'name=' . $name . 'bridge&expires_at=1795080067&readonly=1'];
        yield 'bridge second revoke' => ['revoke_again_bridge', 'POST', self::BRIDGE . 'revoke',
            'name=' . $name . 'bridge-renamed'];
        yield 'version' => ['version', 'GET', 'https://test.oblak.host:2087/json-api/version?api.version=1', ''];
    }

    #[DataProvider('phases')]
    public function testEachPhaseAdmitsExactlyItsLiteralRequestOnce(
        string $phase,
        string $method,
        string $uri,
        string $body,
    ): void {
        $http = new MockClient();
        $http->addResponse(new Response(200));
        $guard = self::guard($http);
        $guard->authorize($phase);
        $guard->sendRequest(self::request($method, $uri, $body));
        self::assertCount(1, $http->getRequests());

        foreach (['send', 'authorize'] as $attempt) {
            try {
                if ($attempt === 'send') {
                    $guard->sendRequest(self::request($method, $uri, $body));
                } else {
                    $guard->authorize($phase);
                }
                self::fail('A consumed phase was replayed.');
            } catch (LogicException) {
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string,array{string,string,string,array<string,string>}> */
    public static function outsideRequests(): iterable
    {
        $uri = self::DIRECT . 'revoke';
        $body = 'name=whmapiclient-live-0a1b2c-direct-renamed';
        yield 'another token name' => ['POST', $uri, 'name=existing', []];
        yield 'the original name in the revoke phase' => ['POST', $uri, 'name=whmapiclient-live-0a1b2c-direct', []];
        yield 'an extra field' => ['POST', $uri, $body . '&name=existing', []];
        yield 'the name in the query string' => ['POST', $uri . '?' . $body, '', []];
        yield 'GET instead of POST' => ['GET', $uri . '?' . $body, '', []];
        yield 'another function' => ['POST', self::DIRECT . 'rename', $body, []];
        yield 'the bridged route' => ['POST', self::BRIDGE . 'revoke', $body, []];
        yield 'another account through the bridge' => ['POST', str_replace('user=npr', 'user=other', self::BRIDGE)
            . 'revoke', $body, []];
        yield 'another host' => ['POST', str_replace('test.oblak.host', 'example.test', $uri), $body, []];
        yield 'another credential' => ['POST', $uri, $body, ['Authorization' => 'cpanel npr:other']];
        yield 'an extra header' => ['POST', $uri, $body, ['X-Extra' => '1']];
        yield 'another content type' => ['POST', $uri, $body, ['Content-Type' => 'application/json']];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('outsideRequests')]
    public function testRequestsOutsideThePhaseAreRefusedBeforeIoAndConsumeIt(
        string $method,
        string $uri,
        string $body,
        array $headers,
    ): void {
        $http = new MockClient();
        $guard = self::guard($http);
        $guard->authorize('revoke_direct');
        try {
            $guard->sendRequest(self::request($method, $uri, $body, $headers));
            self::fail('A request outside the phase was sent.');
        } catch (LogicException) {
            self::assertSame([], $http->getRequests());
        }
        // The rejected attempt consumed the phase: the exact request is refused as well.
        $exact = self::request('POST', self::DIRECT . 'revoke', 'name=whmapiclient-live-0a1b2c-direct-renamed');
        try {
            $guard->sendRequest($exact);
            self::fail('A consumed phase was replayed.');
        } catch (LogicException) {
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testUnauthorizedPendingAndOptedOutRequestsAreRefused(): void
    {
        $http = new MockClient();
        $guard = self::guard($http);
        $request = self::request('GET', self::DIRECT . 'list', '');
        try {
            $guard->sendRequest($request);
            self::fail('An unauthorized request was sent.');
        } catch (LogicException) {
            self::assertSame([], $http->getRequests());
        }

        $guard->authorize('preflight_direct');
        try {
            $guard->authorize('create_direct');
            self::fail('A second phase was authorized while one was pending.');
        } catch (LogicException) {
            self::assertCount(0, $http->getRequests());
        }
        $guard->clearPending();
        try {
            $guard->sendRequest($request);
            self::fail('A cleared phase was sent.');
        } catch (LogicException) {
            self::assertCount(0, $http->getRequests());
        }

        $guard->authorize('listed_direct');
        putenv('WHM_API_LIVE_MUTATIONS=0');
        try {
            $guard->sendRequest($request);
            self::fail('A request was sent without the mutation opt-in.');
        } catch (LogicException) {
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testUnknownPhasesInvalidRecipesAndEmptyCredentialsAreRejected(): void
    {
        $guard = self::guard(new MockClient());
        foreach (['create', 'create_other', 'cleanup_direct', 'version_direct', ''] as $phase) {
            try {
                $guard->authorize($phase);
                self::fail('An unknown phase was authorized.');
            } catch (LogicException $error) {
                self::assertSame('Unknown token phase.', $error->getMessage());
            }
        }
        $invalid = [['0A1B2C', 1795080067], ['0a1b2', 1795080067], ['0a1b2c-x', 1795080067], ['0a1b2c', 0]];
        foreach ($invalid as [$suffix, $expiresAt]) {
            try {
                new TokensSectionRecipe($suffix, $expiresAt);
                self::fail('An invalid recipe was built.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('suffix', $error->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        new TokensSectionGuard(
            new MockClient(),
            ['whm' => '', 'cpanel' => 'private-cpanel'],
            new TokensSectionRecipe('0a1b2c', 1795080067),
        );
    }

    private static function guard(MockClient $http): TokensSectionGuard
    {
        return new TokensSectionGuard(
            $http,
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            new TokensSectionRecipe('0a1b2c', 1795080067),
        );
    }

    /** @param array<string,string> $headers */
    private static function request(string $method, string $uri, string $body, array $headers = []): RequestInterface
    {
        $direct = str_contains($uri, ':2083/');
        $defaults = [
            'Host' => parse_url($uri, PHP_URL_HOST) . ':' . parse_url($uri, PHP_URL_PORT),
            'Authorization' => $direct ? 'cpanel npr:private-cpanel' : 'whm root:private-whm',
            'Accept' => 'application/json',
        ];
        if ($method === 'POST') {
            $defaults['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return new Request($method, $uri, [...$defaults, ...$headers], $body);
    }
}
