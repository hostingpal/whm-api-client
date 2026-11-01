<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisposableAccountTest extends TestCase
{
    private const MARKER = 'fixture-0123456789abcdef0123456789abcdef@wdisposable.example.com';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wdisposable&cpanel.module=Tokens'
        . '&cpanel.function=';

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::liveEnvironment() as $key => $value) {
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

    public function testFreshBindingsCarryUnguessableMarkersAndAnExactExpiry(): void
    {
        $before = time();
        $first = self::fresh();
        $second = self::fresh();

        self::assertMatchesRegularExpression(
            '/\Afixture-[0-9a-f]{32}@wdisposable\.example\.com\z/',
            $first->contactEmail,
        );
        self::assertNotSame($first->contactEmail, $second->contactEmail);
        self::assertGreaterThanOrEqual($before + 900, $first->tokenExpiresAt);
        self::assertLessThanOrEqual(time() + 900, $first->tokenExpiresAt);
        self::assertFalse($first->readOnlyToken);
    }

    /** @param array{string, string, string, string} $identities */
    #[DataProvider('invalidBindings')]
    public function testUnsafeIdentitiesAreRefusedBeforeAnyUse(array $identities): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DisposableAccount(...[...$identities, time() + 900, false]);
    }

    /** @return iterable<string, array{array{string, string, string, string}}> */
    public static function invalidBindings(): iterable
    {
        $user = DisposableAccountServer::USER;
        $domain = DisposableAccountServer::DOMAIN;
        $token = DisposableAccountServer::TOKEN_NAME;
        yield 'empty user' => [['', $domain, $token, self::MARKER]];
        yield 'uppercase user' => [['Wdisposable', $domain, $token, self::MARKER]];
        yield 'leading digit' => [['1account', $domain, $token, self::MARKER]];
        yield 'shell text in user' => [['a;id', $domain, $token, self::MARKER]];
        yield 'seventeen character user' => [[str_repeat('a', 17), $domain, $token, self::MARKER]];
        yield 'trailing newline in user' => [[$user . "\n", $domain, $token, self::MARKER]];
        yield 'bare domain' => [[$user, 'localhost', $token, self::MARKER]];
        yield 'domain with a slash' => [[$user, 'a.example.com/x', $token, self::MARKER]];
        yield 'trailing newline in domain' => [[$user, $domain . "\n", $token, self::MARKER]];
        yield 'token with a space' => [[$user, $domain, 'a b', self::MARKER]];
        yield 'marker on another domain' => [[$user, $domain, $token,
            'fixture-0123456789abcdef0123456789abcdef@other.example.com']];
        yield 'marker too short to be random' => [[$user, $domain, $token,
            'fixture-0123@wdisposable.example.com']];
        yield 'marker in uppercase' => [[$user, $domain, $token,
            'fixture-0123456789ABCDEF0123456789ABCDEF@wdisposable.example.com']];
    }

    /** The bound holds however a binding is built, so a recipe cannot reach a long-lived token by skipping fresh(). */
    #[DataProvider('tokenLifetimes')]
    public function testTheConstructorEnforcesTheTokenBound(int $lifetime, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(InvalidArgumentException::class);
        }
        $account = self::bound(false, time() + $lifetime);
        self::assertSame(DisposableAccountServer::USER, $account->user);
    }

    #[DataProvider('tokenLifetimes')]
    public function testFreshTokensObeyTheSameBound(int $lifetime, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(InvalidArgumentException::class);
        }
        $account = DisposableAccount::fresh(
            DisposableAccountServer::USER,
            DisposableAccountServer::DOMAIN,
            DisposableAccountServer::TOKEN_NAME,
            $lifetime,
            readOnlyToken: false,
        );
        self::assertSame(DisposableAccountServer::USER, $account->user);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function tokenLifetimes(): iterable
    {
        yield 'expired on arrival' => [0, false];
        yield 'already expired' => [-1, false];
        yield 'one minute' => [60, true];
        yield 'exactly the bound' => [DisposableAccount::MAXIMUM_TOKEN_LIFETIME, true];
        // Past the bound by enough that the clock moving during a test cannot make this pass.
        yield 'past the bound' => [DisposableAccount::MAXIMUM_TOKEN_LIFETIME + 30, false];
        yield 'a long-lived token' => [86400, false];
    }

    public function testMutationsAreTheFourExactFormPosts(): void
    {
        $expiresAt = time() + 900;
        self::assertSame([
            self::WHM . 'createacct?api.version=1' => 'username=wdisposable&domain=wdisposable.example.com'
                . '&contactemail=fixture-0123456789abcdef0123456789abcdef%40wdisposable.example.com'
                . '&quota=64&forcedns=0&hasshell=0&ip=n',
            self::WHM . 'removeacct?api.version=1' => 'username=wdisposable&keepdns=0',
            self::BRIDGE . 'create_full_access' => 'name=wdisposable-fixture&expires_at=' . $expiresAt,
            self::BRIDGE . 'revoke' => 'name=wdisposable-fixture',
        ], self::bound(false, $expiresAt)->controlPosts());
    }

    public function testReadOnlyTokensAskForReadOnlyAccess(): void
    {
        $expiresAt = time() + 900;
        self::assertSame(
            'name=wdisposable-fixture&expires_at=' . $expiresAt . '&readonly=1',
            self::bound(true, $expiresAt)->controlPosts()[self::BRIDGE . 'create_full_access'],
        );
    }

    public function testReadsAreTheFourExactIdentityInventories(): void
    {
        self::assertSame([
            self::WHM . 'listaccts?api.version=1&want=user%2Cdomain%2Cemail',
            self::WHM . 'listzones?api.version=1',
            self::WHM . 'get_domain_info?api.version=1',
            self::BRIDGE . 'list',
        ], self::bound(false)->controlReads());
    }

    public function testExplicitLiveOptInsAndTheExactTargetPass(): void
    {
        self::bound(false)->requireLiveOptIn();
        self::expectNotToPerformAssertions();
    }

    #[DataProvider('refusedEnvironments')]
    public function testMissingOptInOrAnotherTargetIsRefused(string $key): void
    {
        putenv($key . '=wrong');
        $this->expectException(LogicException::class);
        self::bound(false)->requireLiveOptIn();
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEnvironments(): iterable
    {
        foreach (array_keys(self::liveEnvironment()) as $key) {
            yield $key => [$key];
        }
    }

    public function testBindingNeverPrintsOrPersistsItsMarker(): void
    {
        $account = self::bound(false);
        self::assertSame([], $account->__debugInfo());
        self::assertStringNotContainsString('0123456789abcdef', print_r($account, true));
        $this->expectException(LogicException::class);
        serialize($account);
    }

    private static function fresh(): DisposableAccount
    {
        return DisposableAccount::fresh(
            DisposableAccountServer::USER,
            DisposableAccountServer::DOMAIN,
            DisposableAccountServer::TOKEN_NAME,
            900,
            readOnlyToken: false,
        );
    }

    private static function bound(bool $readOnlyToken, ?int $expiresAt = null): DisposableAccount
    {
        return new DisposableAccount(
            DisposableAccountServer::USER,
            DisposableAccountServer::DOMAIN,
            DisposableAccountServer::TOKEN_NAME,
            self::MARKER,
            $expiresAt ?? time() + 900,
            $readOnlyToken,
        );
    }

    /** @return array<string, string> */
    private static function liveEnvironment(): array
    {
        return [
            'WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => DisposableAccountServer::USER,
            'WHM_API_LIVE_DOMAIN' => DisposableAccountServer::DOMAIN,
        ];
    }
}
