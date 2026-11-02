<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsRecipe;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class DomainRowsGuardTest extends TestCase
{
    private const MARKER = 'fixture-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@wldomains.example.com';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wldomains&cpanel.module=';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

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
        $guard = self::guard($http);
        $guard->bindToken('private-fixture-token');

        self::assertSame($response, $guard->sendRequest($request));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function approvedRequests(): iterable
    {
        yield 'account inventory' => [self::request('GET', self::WHM
            . 'listaccts?api.version=1&want=user%2Cdomain%2Cemail')];
        yield 'zones' => [self::request('GET', self::WHM . 'listzones?api.version=1')];
        yield 'manual domains' => [self::request('GET', self::WHM . 'get_domain_info?api.version=1')];
        yield 'token inventory' => [self::request('GET', self::BRIDGE . 'Tokens&cpanel.function=list')];
        yield 'version' => [self::request('GET', self::WHM . 'version?api.version=1')];
        yield 'own limits' => [self::request(
            'POST',
            self::WHM . 'modifyacct?api.version=1',
            'user=wldomains&MAXADDON=1&MAXFTP=2&MAXPARK=1&MAXSUB=2',
        )];
        yield 'addon' => [self::request(
            'POST',
            self::DIRECT . 'AddonDomain/addaddondomain',
            'newdomain=wldomains-addon.example.com&subdomain=wldaddon',
            true,
        )];
        yield 'alias' => [self::request('POST', self::DIRECT . 'Park/park', 'domain=' . DomainRowsRecipe::ALIAS, true)];
        yield 'subdomain' => [self::request(
            'POST',
            self::DIRECT . 'SubDomain/addsubdomain',
            'domain=wldsub&rootdomain=wldomains.example.com',
            true,
        )];
        yield 'WHM authority' => [self::request('GET', self::WHM . 'has_local_authority?api.version=1'
            . '&domain=wldomains.example.com&domain=wldomains-addon.example.com')];
        yield 'WHM DS reverse' => [self::request('GET', self::WHM . 'fetch_ds_records_for_domains?api.version=1'
            . '&domain=wldomains-addon.example.com&domain=wldomains.example.com')];
        yield 'direct nameservers' => [self::request('GET', self::DIRECT
            . 'DNS/ensure_domains_use_recognized_nameservers'
            . '?domain=wldomains.example.com&domain=wldomains-addon.example.com', '', true)];
        yield 'bridged residence' => [self::request('GET', self::BRIDGE . 'DNS&cpanel.function='
            . 'ensure_domains_reside_only_locally&domain=wldomains-addon.example.com&domain=wldomains.example.com')];
        yield 'direct domains' => [self::request('GET', self::DIRECT . 'DomainInfo/list_domains', '', true)];
        yield 'bridged list rows' => [self::request('GET', self::BRIDGE . 'DomainInfo&cpanel.function=domains_data'
            . '&format=list&hide_temporary_domains=0&return_https_redirects_status=0')];
        yield 'direct alias row' => [self::request('GET', self::DIRECT . 'DomainInfo/single_domain_data'
            . '?domain=wldomains-alias.example.com&hide_temporary_domains=0&return_https_redirect_status=0', '', true)];
    }

    public function testEveryRecipeReadAndMutationIsAdmitted(): void
    {
        $recipe = self::recipe();
        foreach (DomainRowsRecipe::readUris() as $uri) {
            $http = new Client();
            $guard = new DomainRowsGuard($http, 'private-whm-token', $recipe);
            $guard->bindToken('private-fixture-token');
            $guard->sendRequest(self::request('GET', $uri, '', str_contains($uri, ':2083/')));
            self::assertCount(1, $http->getRequests(), $uri);
        }
        $http = new Client();
        $guard = new DomainRowsGuard($http, 'private-whm-token', $recipe);
        $guard->bindToken('private-fixture-token');
        foreach (DomainRowsRecipe::mutations() as $uri => $body) {
            $guard->sendRequest(self::request('POST', $uri, $body, str_contains($uri, ':2083/')));
        }
        self::assertCount(4, $http->getRequests());
    }

    /** Reads can repeat, as the lifecycle and the identity checks read the same inventories again. */
    public function testReadsCanBeSentAgain(): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->sendRequest(self::request('GET', self::WHM . 'get_domain_info?api.version=1'));
        $guard->sendRequest(self::request('GET', self::WHM . 'get_domain_info?api.version=1'));

        self::assertCount(2, $http->getRequests());
    }

    /** Each mutation can pass this guard once, so nothing below it can retry one. */
    #[DataProvider('mutations')]
    public function testEveryMutationIsSingleUse(RequestInterface $request): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->bindToken('private-fixture-token');
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

    /** @return iterable<string, array{RequestInterface}> */
    public static function mutations(): iterable
    {
        foreach (self::account()->controlPosts() as $uri => $body) {
            yield 'control ' . $uri => [self::request('POST', $uri, $body)];
        }
        foreach (DomainRowsRecipe::mutations() as $uri => $body) {
            yield 'recipe ' . $uri => [self::request('POST', $uri, $body, str_contains($uri, ':2083/'))];
        }
    }

    /** A refused attempt spends the mutation too, as the request may have been the reason to stop. */
    public function testARefusedMutationIsSpentAsWell(): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->bindToken('private-fixture-token');
        $mutation = self::request('POST', self::DIRECT . 'Park/park', 'domain=wldomains-alias.example.com', true);
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

    /** This guard's requests name wldomains, so it refuses a binding for anything else, or a read-only token. */
    #[DataProvider('foreignBindings')]
    public function testRefusesABindingThatIsNotThisRecipesAccount(DisposableAccount $account): void
    {
        $this->expectException(LogicException::class);
        new DomainRowsGuard(new Client(), 'private-whm-token', new DomainRowsRecipe($account));
    }

    /** @return iterable<string, array{DisposableAccount}> */
    public static function foreignBindings(): iterable
    {
        $user = DomainRowsRecipe::ACCOUNT;
        $domain = DomainRowsRecipe::DOMAIN;
        $token = DomainRowsRecipe::TOKEN_NAME;
        yield 'another account' => [DisposableAccount::fresh('other', $domain, $token, 900, false)];
        yield 'another domain' => [DisposableAccount::fresh($user, 'other.example.com', $token, 900, false)];
        yield 'another token' => [DisposableAccount::fresh($user, $domain, 'other-token', 900, false)];
        yield 'a read-only token' => [DisposableAccount::fresh($user, $domain, $token, 900, true)];
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectsUnreviewedTargetsBodiesMethodsAndHeaders(RequestInterface $request): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->bindToken('private-fixture-token');
        try {
            $guard->sendRequest($request);
            $allowed = true;
        } catch (LogicException $error) {
            $allowed = false;
            self::assertStringNotContainsString('private', $error->getMessage());
        }
        self::assertFalse($allowed, 'An unreviewed fixture request was allowed.');
        self::assertCount(0, $http->getRequests());
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function rejectedRequests(): iterable
    {
        $limits = 'user=wldomains&MAXADDON=1&MAXFTP=2&MAXPARK=1&MAXSUB=2';
        foreach (
            [
            'other account limits' => str_replace('user=wldomains', 'user=npr', $limits),
            'unlimited addons' => str_replace('MAXADDON=1', 'MAXADDON=unlimited', $limits),
            'extra limit' => $limits . '&MAXPOP=5',
            'renamed account' => $limits . '&newuser=other',
            'changed domain' => $limits . '&domain=other.example.com',
            'duplicate user' => $limits . '&user=npr',
            ] as $case => $body
        ) {
            yield $case => [self::request('POST', self::WHM . 'modifyacct?api.version=1', $body)];
        }
        yield 'foreign addon' => [self::request(
            'POST',
            self::DIRECT . 'AddonDomain/addaddondomain',
            'newdomain=npr.rs&subdomain=wldaddon',
            true,
        )];
        yield 'forced addon' => [self::request(
            'POST',
            self::DIRECT . 'AddonDomain/addaddondomain',
            'newdomain=wldomains-addon.example.com&subdomain=wldaddon&force=1',
            true,
        )];
        yield 'addon as root' => [self::request(
            'POST',
            self::DIRECT . 'AddonDomain/addaddondomain',
            'newdomain=wldomains-addon.example.com&subdomain=wldaddon',
        )];
        yield 'bridged alias' => [self::request(
            'POST',
            self::BRIDGE . 'Park&cpanel.function=park',
            'domain=wldomains-alias.example.com',
        )];
        $alias = 'domain=' . DomainRowsRecipe::ALIAS;
        yield 'unpark' => [self::request('POST', self::DIRECT . 'Park/unpark', $alias, true)];
        yield 'delete subdomain' => [self::request(
            'POST',
            self::DIRECT . 'SubDomain/delsubdomain',
            'domain=wldsub.wldomains.example.com',
            true,
        )];
        yield 'zone export' => [self::request('GET', self::WHM . 'export_zone_files?api.version=1'
            . '&zone=wldomains.example.com&zone=wldomains-addon.example.com')];
        yield 'one domain' => [self::request('GET', self::WHM . 'has_local_authority?api.version=1'
            . '&domain=wldomains.example.com')];
        yield 'npr domain' => [self::request('GET', self::WHM . 'has_local_authority?api.version=1'
            . '&domain=npr.rs&domain=wldomains-addon.example.com')];
        yield 'read POST' => [self::request('POST', self::DIRECT . 'DomainInfo/list_domains', '', true)];
        yield 'read body' => [self::request('GET', self::DIRECT . 'DomainInfo/list_domains', 'user=other', true)];
        yield 'mutation GET' => [self::request(
            'GET',
            self::DIRECT . 'Park/park?domain=wldomains-alias.example.com',
            '',
            true,
        )];
        $valid = self::request('GET', self::DIRECT . 'DomainInfo/list_domains', '', true);
        yield 'other owner' => [$valid->withHeader('Authorization', 'cpanel npr:private-fixture-token')];
        yield 'wrong token' => [$valid->withHeader('Authorization', 'cpanel wldomains:private-whm-token')];
        yield 'root on direct' => [$valid->withHeader('Authorization', 'whm root:private-whm-token')];
        yield 'cookie' => [$valid->withHeader('Cookie', 'private-cookie')];
        yield 'other bridge account' => [self::request('GET', str_replace('wldomains', 'npr', self::BRIDGE)
            . 'DomainInfo&cpanel.function=list_domains')];
        yield 'other host' => [$valid->withUri((new Request('GET', 'https://other.test:2083/execute/'
            . 'DomainInfo/list_domains'))->getUri())];
    }

    #[DataProvider('requiredEnvironment')]
    public function testEverySendRequiresAllFourExactEnvironmentValues(string $key): void
    {
        $http = new Client();
        $guard = self::guard($http);
        putenv($key . '=other');
        $refused = false;
        try {
            $guard->sendRequest(self::request('GET', self::WHM . 'listzones?api.version=1'));
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertCount(0, $http->getRequests());
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
        $guard = self::guard($http);
        $refused = false;
        try {
            $guard->sendRequest(self::request('GET', self::DIRECT . 'DomainInfo/list_domains', '', true));
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertCount(0, $http->getRequests());
    }

    public function testTokenBindsOnceAndRefusesHeaderControlCharacters(): void
    {
        foreach (['', "private token", "private\ttoken"] as $token) {
            $refused = false;
            try {
                self::guard(new Client())->bindToken($token);
            } catch (LogicException) {
                $refused = true;
            }
            self::assertTrue($refused);
        }
        $guard = self::guard(new Client());
        $guard->bindToken('private-fixture-token');
        $this->expectException(LogicException::class);
        $guard->bindToken('private-fixture-token');
    }

    public function testGuardNeverDumpsOrPersistsItsCredentials(): void
    {
        $guard = self::guard(new Client());
        self::assertSame([], $guard->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($guard);
    }

    private static function guard(Client $http): DomainRowsGuard
    {
        return new DomainRowsGuard($http, 'private-whm-token', self::recipe());
    }

    private static function recipe(): DomainRowsRecipe
    {
        return new DomainRowsRecipe(self::account());
    }

    private static function account(): DisposableAccount
    {
        return new DisposableAccount(
            DomainRowsRecipe::ACCOUNT,
            DomainRowsRecipe::DOMAIN,
            DomainRowsRecipe::TOKEN_NAME,
            self::MARKER,
            self::expiresAt(),
            false,
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
            'WHM_API_LIVE_ACCOUNT' => 'wldomains', 'WHM_API_LIVE_DOMAIN' => 'wldomains.example.com'];
    }

    private static function request(
        string $method,
        string $uri,
        string $body = '',
        bool $direct = false,
    ): RequestInterface {
        $headers = [
            'Authorization' => $direct ? 'cpanel wldomains:private-fixture-token' : 'whm root:private-whm-token',
            'Accept' => 'application/json',
        ];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return new Request($method, $uri, $headers, $body);
    }
}
