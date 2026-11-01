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

    /** @param array{string, string, string, string, int} $arguments */
    #[DataProvider('invalidBindings')]
    public function testUnsafeIdentitiesAreRefusedBeforeAnyUse(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DisposableAccount(...[...$arguments, false]);
    }

    /** @return iterable<string, array{array{string, string, string, string, int}}> */
    public static function invalidBindings(): iterable
    {
        $user = DisposableAccountServer::USER;
        $domain = DisposableAccountServer::DOMAIN;
        $token = DisposableAccountServer::TOKEN_NAME;
        yield 'empty user' => [['', $domain, $token, self::MARKER, 2000000000]];
        yield 'uppercase user' => [['Wdisposable', $domain, $token, self::MARKER, 2000000000]];
        yield 'leading digit' => [['1account', $domain, $token, self::MARKER, 2000000000]];
        yield 'shell text in user' => [['a;id', $domain, $token, self::MARKER, 2000000000]];
        yield 'seventeen character user' => [[str_repeat('a', 17), $domain, $token, self::MARKER, 2000000000]];
        yield 'trailing newline in user' => [[$user . "\n", $domain, $token, self::MARKER, 2000000000]];
        yield 'bare domain' => [[$user, 'localhost', $token, self::MARKER, 2000000000]];
        yield 'domain with a slash' => [[$user, 'a.example.com/x', $token, self::MARKER, 2000000000]];
        yield 'token with a space' => [[$user, $domain, 'a b', self::MARKER, 2000000000]];
        yield 'marker on another domain' => [[$user, $domain, $token,
            'fixture-0123456789abcdef0123456789abcdef@other.example.com', 2000000000]];
        yield 'marker too short to be random' => [[$user, $domain, $token,
            'fixture-0123@wdisposable.example.com', 2000000000]];
        yield 'marker in uppercase' => [[$user, $domain, $token,
            'fixture-0123456789ABCDEF0123456789ABCDEF@wdisposable.example.com', 2000000000]];
        yield 'no expiry' => [[$user, $domain, $token, self::MARKER, 0]];
    }

    #[DataProvider('invalidLifetimes')]
    public function testFreshTokensMustBeShortLived(int $lifetime): void
    {
        $this->expectException(InvalidArgumentException::class);
        DisposableAccount::fresh(
            DisposableAccountServer::USER,
            DisposableAccountServer::DOMAIN,
            DisposableAccountServer::TOKEN_NAME,
            $lifetime,
            readOnlyToken: false,
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLifetimes(): iterable
    {
        yield 'expired on arrival' => [0];
        yield 'negative' => [-1];
        yield 'longer than an hour' => [3601];
    }

    public function testMutationsAreTheFourExactFormPosts(): void
    {
        self::assertSame([
            self::WHM . 'createacct?api.version=1' => 'username=wdisposable&domain=wdisposable.example.com'
                . '&contactemail=fixture-0123456789abcdef0123456789abcdef%40wdisposable.example.com'
                . '&quota=64&forcedns=0&hasshell=0&ip=n',
            self::WHM . 'removeacct?api.version=1' => 'username=wdisposable&keepdns=0',
            self::BRIDGE . 'create_full_access' => 'name=wdisposable-fixture&expires_at=2000000000',
            self::BRIDGE . 'revoke' => 'name=wdisposable-fixture',
        ], self::bound(false)->controlPosts());
    }

    public function testReadOnlyTokensAskForReadOnlyAccess(): void
    {
        self::assertSame(
            'name=wdisposable-fixture&expires_at=2000000000&readonly=1',
            self::bound(true)->controlPosts()[self::BRIDGE . 'create_full_access'],
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

    private static function bound(bool $readOnlyToken): DisposableAccount
    {
        return new DisposableAccount(
            DisposableAccountServer::USER,
            DisposableAccountServer::DOMAIN,
            DisposableAccountServer::TOKEN_NAME,
            self::MARKER,
            2000000000,
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
