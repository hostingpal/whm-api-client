<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\FiniteQuotaGuard;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class FiniteQuotaGuardTest extends TestCase
{
    private const MARKER = 'finite-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@wqfinite.example.com';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wqfinite&cpanel.module=';

    private const DIRECT = 'https://test.oblak.host:2083/execute/Quota/';

    private static ?int $expiresAt = null;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::environment() as $key => $value) {
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

    #[DataProvider('approvedRequests')]
    public function testOnlyExactBoundRequestsReachTransport(RequestInterface $request): void
    {
        $http = new Client();
        $response = new Response(200, [], '{}');
        $http->addResponse($response);
        $guard = new FiniteQuotaGuard($http, 'private-whm-token', self::account());
        $guard->bindToken('private-fixture-token');

        self::assertSame($response, $guard->sendRequest($request));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function approvedRequests(): iterable
    {
        yield 'version' => [self::request('GET', self::WHM . 'version?api.version=1')];
        yield 'account inventory' => [self::request('GET', self::WHM
            . 'listaccts?api.version=1&want=user%2Cdomain%2Cemail')];
        yield 'zones' => [self::request('GET', self::WHM . 'listzones?api.version=1')];
        yield 'manual domains' => [self::request('GET', self::WHM . 'get_domain_info?api.version=1')];
        yield 'create account' => [self::request(
            'POST',
            self::WHM . 'createacct?api.version=1',
            'username=wqfinite&domain=wqfinite.example.com'
            . '&contactemail=finite-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa%40wqfinite.example.com'
            . '&quota=64&forcedns=0&hasshell=0&ip=n',
        )];
        yield 'remove account' => [self::request(
            'POST',
            self::WHM . 'removeacct?api.version=1',
            'username=wqfinite&keepdns=0',
        )];
        yield 'token inventory' => [self::request('GET', self::BRIDGE . 'Tokens&cpanel.function=list')];
        yield 'create readonly token' => [self::request(
            'POST',
            self::BRIDGE . 'Tokens&cpanel.function=create_full_access',
            'name=wqfinite-quota-readonly&expires_at=' . self::expiresAt() . '&readonly=1',
        )];
        yield 'revoke fixture token' => [self::request(
            'POST',
            self::BRIDGE . 'Tokens&cpanel.function=revoke',
            'name=wqfinite-quota-readonly',
        )];
        foreach (['get_quota_info', 'get_local_quota_info'] as $function) {
            yield $function . ' direct' => [self::request('GET', self::DIRECT . $function, '', true)];
            yield $function . ' bridge' => [self::request('GET', self::BRIDGE . 'Quota&cpanel.function=' . $function)];
        }
    }

    /** Each mutation can pass this guard once, so nothing below it can retry one. */
    #[DataProvider('controlMutations')]
    public function testEveryControlMutationIsSingleUse(RequestInterface $request): void
    {
        $http = new Client();
        $guard = new FiniteQuotaGuard($http, 'private-whm-token', self::account());
        $guard->sendRequest($request);
        $refused = false;
        try {
            $guard->sendRequest($request);
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertCount(1, $http->getRequests());
    }

    /** A refused attempt spends the mutation too, as the request may have been the reason to stop. */
    public function testARefusedMutationIsSpentAsWell(): void
    {
        $http = new Client();
        $guard = new FiniteQuotaGuard($http, 'private-whm-token', self::account());
        $mutation = self::request('POST', self::WHM . 'removeacct?api.version=1', 'username=wqfinite&keepdns=0');
        $refused = 0;
        foreach ([$mutation->withHeader('Cookie', 'private-cookie'), $mutation] as $attempt) {
            try {
                $guard->sendRequest($attempt);
            } catch (LogicException) {
                ++$refused;
            }
        }
        self::assertSame(2, $refused);
        self::assertCount(0, $http->getRequests());
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function controlMutations(): iterable
    {
        foreach (self::account()->controlPosts() as $uri => $body) {
            yield $uri => [self::request('POST', $uri, $body)];
        }
    }

    /** This guard's reads name wqfinite, so it refuses a binding for anything else, or a token that can write. */
    #[DataProvider('foreignBindings')]
    public function testRefusesABindingThatIsNotThisRecipesAccount(DisposableAccount $account): void
    {
        $this->expectException(LogicException::class);
        new FiniteQuotaGuard(new Client(), 'private-whm-token', $account);
    }

    /** @return iterable<string, array{DisposableAccount}> */
    public static function foreignBindings(): iterable
    {
        $user = FiniteQuotaGuard::ACCOUNT;
        $domain = FiniteQuotaGuard::DOMAIN;
        $token = FiniteQuotaGuard::TOKEN_NAME;
        yield 'another account' => [DisposableAccount::fresh('other', $domain, $token, 900, true)];
        yield 'another domain' => [DisposableAccount::fresh($user, 'other.example.com', $token, 900, true)];
        yield 'another token' => [DisposableAccount::fresh($user, $domain, 'other-token', 900, true)];
        yield 'a writable token' => [DisposableAccount::fresh($user, $domain, $token, 900, false)];
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectsUnreviewedTargetsBodiesMethodsAndHeaders(RequestInterface $request): void
    {
        $http = new Client();
        $guard = new FiniteQuotaGuard($http, 'private-whm-token', self::account());
        $guard->bindToken('private-fixture-token');
        try {
            $guard->sendRequest($request);
            self::fail('An unreviewed fixture request was allowed.');
        } catch (LogicException $error) {
            self::assertStringNotContainsString('private', $error->getMessage());
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function rejectedRequests(): iterable
    {
        $create = 'username=wqfinite&domain=wqfinite.example.com'
            . '&contactemail=finite-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa%40wqfinite.example.com'
            . '&quota=64&forcedns=0&hasshell=0&ip=n';
        foreach (
            [
            'other account' => str_replace('username=wqfinite', 'username=npr', $create),
            'other domain' => str_replace('wqfinite.example.com', 'npr.rs', $create),
            'unlimited quota' => str_replace('quota=64', 'quota=0', $create),
            'DNS overwrite' => str_replace('forcedns=0', 'forcedns=1', $create),
            'shell' => str_replace('hasshell=0', 'hasshell=1', $create),
            'dedicated IP' => str_replace('ip=n', 'ip=y', $create),
            'package override' => $create . '&plan=default',
            'other run marker' => str_replace('finite-aaaaaaaa', 'finite-bbbbbbbb', $create),
            'missing marker' => str_replace(
                '&contactemail=finite-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa%40wqfinite.example.com',
                '',
                $create,
            ),
            'duplicate marker' => $create . '&contactemail=private-other@example.com',
            'duplicate name' => $create . '&username=other',
            'encoded duplicate' => $create . '&%75sername=other',
            'alias' => $create . '&user=other',
            'bracket target' => str_replace('username=', 'username[]=', $create),
            ] as $case => $body
        ) {
            yield $case => [self::request('POST', self::WHM . 'createacct?api.version=1', $body)];
        }
        yield 'mutation GET' => [self::request('GET', self::WHM . 'removeacct?api.version=1&username=wqfinite')];
        yield 'filtered account lookup' => [self::request('GET', self::WHM
            . 'listaccts?api.version=1&search=wqfinite&searchtype=user&searchmethod=exact&want=user%2Cdomain%2Cemail')];
        yield 'extra account columns' => [self::request('GET', self::WHM
            . 'listaccts?api.version=1&want=user%2Cdomain%2Cemail%2Cowner')];
        yield 'read POST' => [self::request('POST', self::DIRECT . 'get_quota_info', '', true)];
        yield 'read body' => [self::request('GET', self::DIRECT . 'get_quota_info', 'user=other', true)];
        yield 'keep DNS' => [self::request(
            'POST',
            self::WHM . 'removeacct?api.version=1',
            'username=wqfinite&keepdns=1',
        )];
        yield 'control token revoke' => [self::request(
            'POST',
            self::WHM . 'api_token_revoke?api.version=1',
            'name=private-whm-token',
        )];
        yield 'other token revoke' => [self::request(
            'POST',
            self::BRIDGE . 'Tokens&cpanel.function=revoke',
            'name=other',
        )];
        yield 'write token' => [self::request(
            'POST',
            self::BRIDGE . 'Tokens&cpanel.function=create_full_access',
            'name=wqfinite-quota-readonly&expires_at=' . self::expiresAt() . '&readonly=0',
        )];
        yield 'missing expiry' => [self::request(
            'POST',
            self::BRIDGE . 'Tokens&cpanel.function=create_full_access',
            'name=wqfinite-quota-readonly&readonly=1',
        )];
        yield 'other expiry' => [self::request(
            'POST',
            self::BRIDGE . 'Tokens&cpanel.function=create_full_access',
            'name=wqfinite-quota-readonly&expires_at=' . (self::expiresAt() + 1) . '&readonly=1',
        )];
        $valid = self::request('GET', self::DIRECT . 'get_quota_info', '', true);
        yield 'other owner' => [$valid->withHeader('Authorization', 'cpanel npr:private-fixture-token')];
        yield 'wrong token' => [$valid->withHeader('Authorization', 'cpanel wqfinite:private-whm-token')];
        yield 'duplicate auth' => [$valid->withAddedHeader('Authorization', 'cpanel wqfinite:private-fixture-token')];
        yield 'host header' => [$valid->withHeader('Host', 'other.test:2083')];
        yield 'cookie' => [$valid->withHeader('Cookie', 'private-cookie')];
        yield 'accept' => [$valid->withHeader('Accept', 'text/plain')];
        foreach (
            [
            'other host' => 'https://other.test:2083/execute/Quota/get_quota_info',
            'wrong port' => 'https://test.oblak.host:2087/execute/Quota/get_quota_info',
            'http' => 'http://test.oblak.host:2083/execute/Quota/get_quota_info',
            'query' => self::DIRECT . 'get_quota_info?user=other',
            'fragment' => self::DIRECT . 'get_quota_info#private',
            'userinfo' => 'https://private@test.oblak.host:2083/execute/Quota/get_quota_info',
            ] as $case => $uri
        ) {
            yield $case => [$valid->withUri((new Request('GET', $uri))->getUri())];
        }
        yield 'other bridge account' => [self::request('GET', str_replace('wqfinite', 'npr', self::BRIDGE)
            . 'Quota&cpanel.function=get_quota_info')];
    }

    #[DataProvider('requiredEnvironment')]
    public function testEverySendRequiresAllFourExactEnvironmentValues(string $key): void
    {
        $http = new Client();
        $guard = new FiniteQuotaGuard($http, 'private-whm-token', self::account());
        putenv($key . '=other');
        $this->expectException(LogicException::class);
        try {
            $guard->sendRequest(self::request('GET', self::WHM . 'listzones?api.version=1'));
        } finally {
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function requiredEnvironment(): iterable
    {
        foreach (array_keys(self::environment()) as $key) {
            yield $key => [$key];
        }
    }

    public function testDirectAccessIsDeniedBeforeTheFixtureTokenIsBound(): void
    {
        $http = new Client();
        $guard = new FiniteQuotaGuard($http, 'private-whm-token', self::account());
        $this->expectException(LogicException::class);
        try {
            $guard->sendRequest(self::request('GET', self::DIRECT . 'get_quota_info', '', true));
        } finally {
            self::assertCount(0, $http->getRequests());
        }
    }

    private static function account(): DisposableAccount
    {
        return new DisposableAccount(
            FiniteQuotaGuard::ACCOUNT,
            FiniteQuotaGuard::DOMAIN,
            FiniteQuotaGuard::TOKEN_NAME,
            self::MARKER,
            self::expiresAt(),
            true,
        );
    }

    /** One expiry for the whole class, as providers run first and the binding enforces the token bound. */
    private static function expiresAt(): int
    {
        return self::$expiresAt ??= time() + 1800;
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        return ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'wqfinite', 'WHM_API_LIVE_DOMAIN' => 'wqfinite.example.com'];
    }

    private static function request(
        string $method,
        string $uri,
        string $body = '',
        bool $direct = false,
    ): RequestInterface {
        $headers = ['Authorization' => $direct ? 'cpanel wqfinite:private-fixture-token' : 'whm root:private-whm-token',
            'Accept' => 'application/json'];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return new Request($method, $uri, $headers, $body);
    }
}
