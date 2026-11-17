<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\LangPhpMutationGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\LangPhpMutationRecipe;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class LangPhpMutationGuardTest extends TestCase
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wlphpset&cpanel.module=LangPHP'
        . '&cpanel.function=';

    private const DIRECT = 'https://test.oblak.host:2083/execute/LangPHP/';

    /** @var array<string, string|false> */
    private array $environment = [];

    /** @var list<RequestInterface> */
    private array $sent = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlphpset',
                'WHM_API_LIVE_DOMAIN' => 'wlphpset.example.com'] as $key => $value
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

    public function testEveryReviewedReadPassesOnItsRouteWithExactHeaders(): void
    {
        [$guard] = $this->guard();
        $guard->bindToken('PRIVATEPHPTOKEN');
        foreach ([self::WHM . 'version?api.version=1', ...LangPhpMutationRecipe::readUris()] as $uri) {
            $guard->sendRequest(self::get($uri));
        }
        self::assertCount(11, $this->sent);
    }

    /** @param array<string, string> $headers */
    #[DataProvider('refusedReads')]
    public function testUnreviewedReadsAndHeadersAreRefusedBeforeIo(string $uri, array $headers): void
    {
        [$guard] = $this->guard();
        $guard->bindToken('PRIVATEPHPTOKEN');
        $refused = false;
        try {
            $guard->sendRequest(self::get($uri, $headers));
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertSame([], $this->sent);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function refusedReads(): iterable
    {
        yield 'vhost content scope' => [self::DIRECT . 'php_ini_get_user_content?type=vhost', []];
        yield 'extra argument' => [self::DIRECT . 'php_ini_get_user_content?type=home&vhost=x', []];
        yield 'another account' => [self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=npr&cpanel.module=LangPHP'
            . '&cpanel.function=php_get_installed_versions', []];
        yield 'another domain' => [self::DIRECT . 'php_get_vhost_versions?vhost=npr.rs', []];
        yield 'paths read' => [self::DIRECT . 'php_ini_get_user_paths', []];
        yield 'bridged paths read' => [self::BRIDGE . 'php_ini_get_user_paths', []];
        yield 'extra header' => [self::DIRECT . 'php_get_installed_versions', ['X-Extra' => '1']];
        yield 'wrong token' => [self::DIRECT . 'php_get_installed_versions',
            ['Authorization' => 'cpanel wlphpset:OTHER']];
    }

    public function testDirectReadNeedsTheBoundToken(): void
    {
        [$guard] = $this->guard();
        $this->expectException(LogicException::class);
        $guard->sendRequest(self::get(self::DIRECT . 'php_get_installed_versions'));
    }

    public function testEachMutationNeedsItsOwnSingleUseAuthorization(): void
    {
        [$guard, $recipe] = $this->guard();
        $guard->bindToken('PRIVATEPHPTOKEN');
        $content = $recipe->mutation('set_content_host_bridged');
        $refused = false;
        try {
            $guard->sendRequest(self::post($content['uri'], $content['body']));
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused, 'An unauthorized mutation reached the transport.');

        foreach (LangPhpMutationRecipe::MUTATIONS as $phase => [$function, $route]) {
            $version = str_starts_with($phase, 'set_version_') ? 'ea-php84' : null;
            $guard->authorize($phase, $version);
            $expected = $recipe->mutation($phase, $version);
            self::assertStringContainsString($function, $expected['uri']);
            self::assertSame($route === 'direct-uapi', str_starts_with($expected['uri'], self::DIRECT));
            $guard->sendRequest(self::post($expected['uri'], $expected['body']));
        }
        self::assertCount(8, $this->sent);

        $repeated = 0;
        foreach (array_keys(LangPhpMutationRecipe::MUTATIONS) as $phase) {
            try {
                $guard->authorize($phase, str_starts_with($phase, 'set_version_') ? 'ea-php84' : null);
            } catch (LogicException) {
                ++$repeated;
            }
        }
        self::assertSame(8, $repeated);
    }

    public function testMismatchedBodyIsRefusedAndStillConsumesThePhase(): void
    {
        [$guard, $recipe] = $this->guard();
        $guard->bindToken('PRIVATEPHPTOKEN');
        $guard->authorize('set_directives_direct');
        $expected = $recipe->mutation('set_directives_direct');
        $refused = false;
        try {
            $guard->sendRequest(self::post($expected['uri'], $expected['body'] . '&vhost=x'));
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertSame([], $this->sent);
        $this->expectException(LogicException::class);
        $guard->authorize('set_directives_direct');
    }

    public function testPendingMutationRefusesAnyOtherRequest(): void
    {
        [$guard] = $this->guard();
        $guard->bindToken('PRIVATEPHPTOKEN');
        $guard->authorize('set_content_direct');
        $this->expectException(LogicException::class);
        try {
            $guard->sendRequest(self::get(self::DIRECT . 'php_ini_get_user_content?type=home'));
        } finally {
            self::assertSame([], $this->sent);
        }
    }

    public function testClearedAuthorizationCannotBeUsedOrRenewed(): void
    {
        [$guard, $recipe] = $this->guard();
        $guard->bindToken('PRIVATEPHPTOKEN');
        $guard->authorize('set_content_direct');
        $guard->clearPending();
        $expected = $recipe->mutation('set_content_direct');
        $refused = 0;
        try {
            $guard->sendRequest(self::post($expected['uri'], $expected['body']));
        } catch (LogicException) {
            ++$refused;
        }
        try {
            $guard->authorize('set_content_direct');
        } catch (LogicException) {
            ++$refused;
        }
        self::assertSame(2, $refused);
        self::assertSame([], $this->sent);
    }

    public function testVersionAuthorizationNeedsASafeVersionOnlyForVersionPhases(): void
    {
        [$guard] = $this->guard();
        $refused = 0;
        foreach (
            [
            ['set_version_direct', null], ['set_version_bridged', 'ea-php84&vhost=x'], ['set_content_direct',
            'ea-php84'], ['set_unknown', null]] as [$phase, $version]
        ) {
            try {
                $guard->authorize($phase, $version);
            } catch (LogicException | InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(4, $refused);
    }

    public function testControlMutationsAreAdmittedOnceEach(): void
    {
        [$guard, , $account] = $this->guard();
        $posts = $account->controlPosts();
        foreach ($posts as $uri => $body) {
            $guard->sendRequest(self::post($uri, $body, self::WHM));
        }
        self::assertCount(4, $this->sent);
        $refused = 0;
        foreach ($posts as $uri => $body) {
            try {
                $guard->sendRequest(self::post($uri, $body, self::WHM));
            } catch (LogicException) {
                ++$refused;
            }
        }
        self::assertSame(4, $refused);
    }

    #[DataProvider('missingOptIns')]
    public function testEveryRequestRechecksTheOptIns(string $key, string $value): void
    {
        [$guard] = $this->guard();
        putenv($key . '=' . $value);
        $this->expectException(LogicException::class);
        try {
            $guard->sendRequest(self::get(self::WHM . 'version?api.version=1'));
        } finally {
            self::assertSame([], $this->sent);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingOptIns(): iterable
    {
        yield 'live' => ['WHM_API_LIVE', '0'];
        yield 'mutations' => ['WHM_API_LIVE_MUTATIONS', '0'];
        yield 'account' => ['WHM_API_LIVE_ACCOUNT', 'npr'];
        yield 'domain' => ['WHM_API_LIVE_DOMAIN', 'npr.rs'];
    }

    public function testTokenBindsOnceAndRefusesHeaderCharacters(): void
    {
        [$guard] = $this->guard();
        $refused = 0;
        foreach (['', "PRIVATE\nTOKEN", 'PRIVATE TOKEN'] as $token) {
            try {
                $guard->bindToken($token);
            } catch (LogicException) {
                ++$refused;
            }
        }
        $guard->bindToken('PRIVATEPHPTOKEN');
        try {
            $guard->bindToken('PRIVATEPHPTOKEN');
        } catch (LogicException) {
            ++$refused;
        }
        self::assertSame(4, $refused);
    }

    public function testGuardKeepsNoMarkerAndCannotBeSerialized(): void
    {
        [$guard, , $account] = $this->guard();
        $marker = '';
        parse_str($account->controlPosts()[self::WHM . 'createacct?api.version=1'], $fields);
        if (is_string($fields['contactemail'] ?? null)) {
            $marker = $fields['contactemail'];
        }
        self::assertNotSame('', $marker);
        MarkerExposure::assertNeverExposed($marker, $guard, $this->client());
        $this->expectException(LogicException::class);
        serialize($guard);
    }

    /** @return array{LangPhpMutationGuard, LangPhpMutationRecipe, DisposableAccount} */
    private function guard(): array
    {
        $account = DisposableAccount::fresh(
            LangPhpMutationRecipe::ACCOUNT,
            LangPhpMutationRecipe::DOMAIN,
            LangPhpMutationRecipe::TOKEN_NAME,
            900,
            false,
        );
        $recipe = new LangPhpMutationRecipe(str_repeat('ab', 16), $account);

        return [new LangPhpMutationGuard($this->client(), 'WHMTOKEN', $recipe), $recipe, $account];
    }

    private function client(): ClientInterface
    {
        return new class (function (RequestInterface $request): void {
            $this->sent[] = $request;
        }) implements ClientInterface {
            /** @param Closure(RequestInterface): void $record */
            public function __construct(private readonly Closure $record)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ($this->record)($request);

                return new Response(200, [], '{}');
            }
        };
    }

    /** @param array<string, string> $headers */
    private static function get(string $uri, array $headers = []): RequestInterface
    {
        $direct = str_starts_with($uri, self::DIRECT);

        return new Request('GET', $uri, $headers + [
            'Host' => 'test.oblak.host:' . ($direct ? '2083' : '2087'),
            'Authorization' => $direct ? 'cpanel wlphpset:PRIVATEPHPTOKEN' : 'whm root:WHMTOKEN',
            'Accept' => 'application/json',
        ]);
    }

    private static function post(string $uri, string $body, string $prefix = ''): RequestInterface
    {
        $direct = str_starts_with($uri, self::DIRECT);
        self::assertTrue($prefix === '' || str_starts_with($uri, $prefix));

        return new Request('POST', $uri, [
            'Host' => 'test.oblak.host:' . ($direct ? '2083' : '2087'),
            'Authorization' => $direct ? 'cpanel wlphpset:PRIVATEPHPTOKEN' : 'whm root:WHMTOKEN',
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);
    }
}
