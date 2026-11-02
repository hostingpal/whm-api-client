<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionRecipe;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WebmailSessionGuardTest extends TestCase
{
    private const PASSWORD = 'Ws!9PRIVATEpassword0123456789';

    private const TOKEN = 'PRIVATESESSIONTOKEN';

    private const MUTATIONS = ['create_mailbox', 'self_direct', 'mail_user_direct', 'check_password_direct',
        'self_bridged', 'mail_user_bridged', 'check_password_bridged'];

    /** @var array<string, string|false> */
    private array $environment = [];

    private MockClient $http;

    protected function setUp(): void
    {
        $this->http = new MockClient();
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlsession',
                'WHM_API_LIVE_DOMAIN' => 'wlsession.example.com'] as $key => $value
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

    public function testEveryRecipeRequestPassesAndEachMutationOnlyOnce(): void
    {
        $recipe = self::recipe();
        $guard = $this->guard($recipe);
        $guard->bindToken(self::TOKEN);
        foreach (WebmailSessionRecipe::PHASES as $phase) {
            $guard->sendRequest(self::request($recipe, $phase));
        }
        self::assertCount(count(WebmailSessionRecipe::PHASES), $this->http->getRequests());
        // Reads repeat freely; every mutation was consumed by its one admitted request.
        $guard->sendRequest(self::request($recipe, 'sessions_final_bridged'));
        foreach (self::MUTATIONS as $phase) {
            $this->assertRefused($guard, self::request($recipe, $phase));
        }
        self::assertCount(count(WebmailSessionRecipe::PHASES) + 1, $this->http->getRequests());
    }

    public function testTheLifecycleControlRequestsPass(): void
    {
        $recipe = self::recipe();
        $guard = $this->guard($recipe);
        foreach ($recipe->account->controlReads() as $uri) {
            $guard->sendRequest(self::root('GET', $uri));
        }
        $posts = $recipe->account->controlPosts();
        foreach ($posts as $uri => $body) {
            $guard->sendRequest(self::root('POST', $uri, $body));
        }
        foreach ($posts as $uri => $body) {
            $this->assertRefused($guard, self::root('POST', $uri, $body));
        }
        self::assertCount(count($recipe->account->controlReads()) + count($posts), $this->http->getRequests());
    }

    /** A mutation that does not match exactly consumes nothing; one that matched is never admitted again. */
    public function testAnInexactMutationIsRefusedWithoutConsumingTheExactOne(): void
    {
        $recipe = self::recipe();
        $guard = $this->guard($recipe);
        $guard->bindToken(self::TOKEN);
        $exact = self::request($recipe, 'check_password_bridged');
        $this->assertRefused($guard, $exact->withBody(Stream::create(
            'domain=wlsession.example.com&login=' . $recipe->mailbox . '&password=wrong&remote_address=192.0.2.10',
        )));
        $guard->sendRequest($exact);
        self::assertCount(1, $this->http->getRequests());
    }

    /** @param callable(WebmailSessionRecipe): RequestInterface $build */
    #[DataProvider('refusedRequests')]
    public function testEverythingElseIsRefusedBeforeTheTransport(callable $build): void
    {
        $recipe = self::recipe();
        $guard = $this->guard($recipe);
        $guard->bindToken(self::TOKEN);
        $this->assertRefused($guard, $build($recipe));
        self::assertSame([], $this->http->getRequests());
    }

    /** @return iterable<string, array{callable(WebmailSessionRecipe): RequestInterface}> */
    public static function refusedRequests(): iterable
    {
        yield 'a Webmail login with returned credentials' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => (new Request('POST', 'https://test.oblak.host:2096/PRIVATETOKEN/login', [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], 'session=PRIVATESESSION'))];
        yield 'a returned URL fetch' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => new Request('GET', 'https://test.oblak.host:2096/cpsess0000000000/webmail/jupiter/index.html')];
        yield 'a creator without the address' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'self_bridged')->withBody(Stream::create(''))];
        yield 'a creator naming another mailbox' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'mail_user_direct')->withBody(Stream::create(
                'domain=wlsession.example.com&login=other&remote_address=192.0.2.10',
            ))];
        yield 'a creator on another account' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::root('POST', str_replace('cpanel.user=wlsession', 'cpanel.user=npr', $recipe->request(
                'self_bridged',
            )['uri']), 'remote_address=192.0.2.10')];
        yield 'a mailbox with welcome mail' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'create_mailbox')->withBody(Stream::create(str_replace(
                'send_welcome_email=0',
                'send_welcome_email=1',
                $recipe->request('create_mailbox')['body'],
            )))];
        yield 'a read with a body' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'sessions_initial_direct')->withBody(Stream::create('x=1'))];
        yield 'a mutation as GET' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'self_direct')->withMethod('GET')->withBody(Stream::create(''))];
        yield 'another method' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'sessions_initial_direct')->withMethod('DELETE')];
        yield 'an extra header' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'self_direct')->withHeader('Cookie', 'cpsession=private')];
        yield 'a direct call with root credentials' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'self_direct')->withHeader('Authorization', 'whm root:PRIVATEWHM')];
        yield 'a bridged call with the account token' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::request($recipe, 'self_bridged')->withHeader('Authorization', 'cpanel wlsession:' . self::TOKEN)];
        yield 'another account removal' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::root(
                'POST',
                'https://test.oblak.host:2087/json-api/removeacct?api.version=1',
                'username=npr&keepdns=0',
            )];
        yield 'a mailbox deletion' => [static fn (WebmailSessionRecipe $recipe): RequestInterface
            => self::direct('POST', 'https://test.oblak.host:2083/execute/Email/delete_pop', 'email=x')];
    }

    public function testDirectRequestsNeedTheBoundToken(): void
    {
        $recipe = self::recipe();
        $guard = $this->guard($recipe);
        $this->assertRefused($guard, self::request($recipe, 'sessions_initial_direct'));
        $guard->bindToken(self::TOKEN);
        $this->expectException(LogicException::class);
        $guard->bindToken(self::TOKEN);
    }

    public function testAMissingOptInRefusesEvenAnExactRequest(): void
    {
        $recipe = self::recipe();
        $guard = $this->guard($recipe);
        putenv('WHM_API_LIVE_MUTATIONS=0');
        $this->assertRefused($guard, self::request($recipe, 'version'));
        self::assertSame([], $this->http->getRequests());
    }

    public function testNoGuardStateShowsThePasswordOrTokens(): void
    {
        $recipe = self::recipe();
        $guard = $this->guard($recipe);
        $guard->bindToken(self::TOKEN);
        $guard->sendRequest(self::request($recipe, 'check_password_direct'));
        foreach (['PRIVATEpassword', 'PRIVATEWHM', self::TOKEN] as $secret) {
            self::assertStringNotContainsString($secret, print_r($guard, true));
        }
        self::assertSame([], $guard->__debugInfo());
    }

    private function assertRefused(WebmailSessionGuard $guard, RequestInterface $request): void
    {
        try {
            $guard->sendRequest($request);
        } catch (LogicException $refusal) {
            self::assertSame('Request outside the Webmail session fixture boundary.', $refusal->getMessage());

            return;
        }
        self::fail('The request was admitted.');
    }

    private function guard(WebmailSessionRecipe $recipe): WebmailSessionGuard
    {
        $this->http->setDefaultResponse(new Response(200, [], '{}'));

        return new WebmailSessionGuard($this->http, 'PRIVATEWHM', $recipe);
    }

    private static function request(WebmailSessionRecipe $recipe, string $phase): RequestInterface
    {
        $request = $recipe->request($phase);

        return WebmailSessionRecipe::route($phase) === 'direct-uapi'
            ? self::direct($request['method'], $request['uri'], $request['body'])
            : self::root($request['method'], $request['uri'], $request['body']);
    }

    private static function root(string $method, string $uri, string $body = ''): RequestInterface
    {
        $headers = ['Authorization' => 'whm root:PRIVATEWHM', 'Accept' => 'application/json'];

        return new Request($method, $uri, $method === 'POST'
            ? [...$headers, 'Content-Type' => 'application/x-www-form-urlencoded'] : $headers, $body);
    }

    private static function direct(string $method, string $uri, string $body = ''): RequestInterface
    {
        $headers = ['Authorization' => 'cpanel wlsession:' . self::TOKEN, 'Accept' => 'application/json'];

        return new Request($method, $uri, $method === 'POST'
            ? [...$headers, 'Content-Type' => 'application/x-www-form-urlencoded'] : $headers, $body);
    }

    private static function recipe(): WebmailSessionRecipe
    {
        return new WebmailSessionRecipe(str_repeat('0123456789abcdef', 2), self::PASSWORD, DisposableAccount::fresh(
            WebmailSessionRecipe::ACCOUNT,
            WebmailSessionRecipe::DOMAIN,
            WebmailSessionRecipe::TOKEN_NAME,
            900,
            false,
        ));
    }
}
