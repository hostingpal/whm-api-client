<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\UserTasksGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\UserTasksRecipe;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class UserTasksGuardTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    private const TASK = '00000000/5a9ec8dd4c345d';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wltasks&cpanel.module=Tokens'
        . '&cpanel.function=';

    private const REFUSED = 'Request outside the user task fixture boundary.';

    /** @var array<string, string|false> */
    private array $environment = [];

    /** @var list<RequestInterface> */
    private array $sent = [];

    private UserTasksRecipe $recipe;

    private static ?int $expiresAt = null;

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wltasks',
                'WHM_API_LIVE_DOMAIN' => 'wltasks.example.com'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        $this->recipe = new UserTasksRecipe(self::ENTROPY, new DisposableAccount(
            UserTasksRecipe::ACCOUNT,
            UserTasksRecipe::DOMAIN,
            UserTasksRecipe::TOKEN_NAME,
            'tasks-' . self::ENTROPY . '@' . UserTasksRecipe::DOMAIN,
            self::expiresAt(),
            false,
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testPermitsEveryExactReadControlPostAndAuthorizedMutation(): void
    {
        $guard = $this->guard(true);
        foreach ($this->approvedReads() as $request) {
            $guard->sendRequest($request);
        }
        foreach ($this->approvedControlPosts() as $request) {
            $guard->sendRequest($request);
        }
        $guard->authorizeCreate('source');
        $guard->sendRequest($this->createRequest($this->recipe->createBody('source')));
        $guard->authorizeCreate('clone');
        $guard->sendRequest($this->createRequest($this->recipe->createBody('clone')));
        $guard->authorizeDelete(self::TASK);
        $guard->sendRequest($this->deleteRequest('id=00000000%2F5a9ec8dd4c345d'));
        self::assertCount(15, $this->sent);
    }

    /** Each control mutation can pass this guard once, so nothing below it can retry one. */
    public function testEveryControlMutationIsSingleUse(): void
    {
        foreach ($this->approvedControlPosts() as $request) {
            $guard = $this->guard(true);
            $guard->sendRequest($request);
            $this->assertRefused($guard, $request);
        }
    }

    #[DataProvider('environmentRefusals')]
    public function testEveryRequestRechecksTheExactOptInsAndTarget(string $key, string $value): void
    {
        $guard = $this->guard(true);
        putenv($key . '=' . $value);
        $refused = false;
        try {
            $guard->sendRequest($this->approvedReads()[0]);
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertSame([], $this->sent);
    }

    /** @return iterable<string, array{string, string}> */
    public static function environmentRefusals(): iterable
    {
        yield 'live disabled' => ['WHM_API_LIVE', '0'];
        yield 'mutations disabled' => ['WHM_API_LIVE_MUTATIONS', '0'];
        yield 'npr account' => ['WHM_API_LIVE_ACCOUNT', 'npr'];
        yield 'npr domain' => ['WHM_API_LIVE_DOMAIN', 'npr.rs'];
        yield 'similar account' => ['WHM_API_LIVE_ACCOUNT', 'wltasks2'];
    }

    /** A create needs its own authorization and exact body; an authorization is spent even when refused. */
    public function testCreatesNeedAnExactSingleUseAuthorization(): void
    {
        $guard = $this->guard(true);
        $source = $this->createRequest($this->recipe->createBody('source'));
        $this->assertRefused($guard, $source);
        $guard->authorizeCreate('clone');
        $this->assertRefused($guard, $source);
        $this->assertRefused($guard, $this->createRequest($this->recipe->createBody('clone')));
        $guard->authorizeCreate('source');
        $this->assertRefused($guard, $this->createRequest($this->recipe->createBody('source') . '&x=1'));
        $this->assertRefusedAuthorization(static fn () => $guard->authorizeCreate('source'));
        $this->assertRefusedAuthorization(static fn () => $guard->authorizeCreate('clone'));
        self::assertSame([], $this->sent);
    }

    /** One delete only, of one queue element, with the literal body: never another entry, never a second try. */
    public function testDeleteNeedsAnExactSingleAuthorizationOfAQueueElement(): void
    {
        $guard = $this->guard(true);
        $request = $this->deleteRequest('id=00000000%2F5a9ec8dd4c345d');
        $this->assertRefused($guard, $request);
        $refused = false;
        try {
            $guard->authorizeDelete('00000000\\/5a9ec8dd4c345d');
        } catch (InvalidArgumentException) {
            $refused = true;
        }
        self::assertTrue($refused);
        $guard->authorizeDelete(self::TASK);
        $this->assertRefused($guard, $this->deleteRequest('id=00000000%2F5a9ec8dd4c345e'));
        $this->assertRefusedAuthorization(static fn () => $guard->authorizeDelete(self::TASK));
        $this->assertRefusedAuthorization(static fn () => $guard->authorizeDelete('00000000/5a9ec8dd4c345e'));
        $this->assertRefused($guard, $request);
        self::assertSame([], $this->sent);
    }

    /** A pending mutation authorization is never spent on a read, nor a read admitted while one is pending. */
    public function testPendingAuthorizationAdmitsNothingElse(): void
    {
        $guard = $this->guard(true);
        $guard->authorizeCreate('source');
        $this->assertRefused($guard, $this->approvedReads()[0]);
        $this->assertRefused($guard, $this->createRequest($this->recipe->createBody('source')));
        $guard->authorizeDelete(self::TASK);
        $this->assertRefused($guard, $this->approvedControlPosts()[0]);
        self::assertSame([], $this->sent);
    }

    #[DataProvider('refusedRequests')]
    public function testRefusesEverythingOutsideTheRecipe(string $method, string $uri, string $body, bool $direct): void
    {
        $guard = $this->guard(true);
        $headers = $direct ? self::directHeaders() : self::whmHeaders();
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $this->assertRefused($guard, new Request($method, $uri, $headers, $body));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function refusedRequests(): iterable
    {
        $direct = 'https://test.oblak.host:2083/execute/';
        yield 'npr bridge' => ['GET', self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=npr'
            . '&cpanel.module=UserTasks&cpanel.function=retrieve', '', false];
        yield 'bridged delete' => ['POST', self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wltasks'
            . '&cpanel.module=UserTasks&cpanel.function=delete', 'id=00000000%2F5a9ec8dd4c345d', false];
        yield 'repository delete' => ['POST', $direct . 'VersionControl/delete', 'repository_root=%2Fhome', true];
        yield 'repository list' => ['POST', $direct . 'VersionControl/retrieve', '', true];
        yield 'deployment' => ['POST', $direct . 'VersionControlDeployment/create', 'repository_root=%2Fhome', true];
        yield 'read with body' => ['GET', $direct . 'UserTasks/retrieve', 'x=1', true];
        yield 'read with query' => ['GET', $direct . 'UserTasks/retrieve?x=1', '', true];
        yield 'home of another field' => ['GET', $direct . 'Variables/get_user_information?name=domain', '', true];
        yield 'put' => ['PUT', $direct . 'UserTasks/retrieve', '', true];
        yield 'other host' => ['GET', 'https://example.test:2083/execute/UserTasks/retrieve', '', true];
    }

    public function testDirectRequestsNeedTheBoundTokenAndExactHeaders(): void
    {
        $unbound = $this->guard(false);
        $this->assertRefused($unbound, new Request('GET', $this->recipe->tasksUri(false), self::directHeaders()));
        $guard = $this->guard(true);
        $this->assertRefused($guard, new Request(
            'GET',
            $this->recipe->tasksUri(false),
            [...self::directHeaders(), 'Authorization' => 'cpanel npr:TEMPORARY'],
        ));
        $this->assertRefused($guard, new Request(
            'GET',
            $this->recipe->tasksUri(false),
            [...self::directHeaders(), 'X-Extra' => '1'],
        ));
        $this->assertRefused($guard, new Request('GET', $this->recipe->tasksUri(true), self::directHeaders()));
        $refused = false;
        try {
            $guard->bindToken('ANOTHER');
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
    }

    /** @return list<RequestInterface> */
    private function approvedReads(): array
    {
        $requests = [];
        foreach (
            ['version?api.version=1', 'listaccts?api.version=1&want=user%2Cdomain%2Cemail', 'listzones?api.version=1',
                'get_domain_info?api.version=1'] as $path
        ) {
            $requests[] = new Request('GET', self::WHM . $path, self::whmHeaders());
        }
        $requests[] = new Request('GET', self::BRIDGE . 'list', self::whmHeaders());
        $requests[] = new Request('GET', $this->recipe->tasksUri(true), self::whmHeaders());
        $requests[] = new Request('GET', $this->recipe->homeUri(), self::directHeaders());
        $requests[] = new Request('GET', $this->recipe->tasksUri(false), self::directHeaders());

        return $requests;
    }

    /** @return list<RequestInterface> */
    private function approvedControlPosts(): array
    {
        $form = self::whmHeaders() + ['Content-Type' => 'application/x-www-form-urlencoded'];
        $create = 'username=wltasks&domain=wltasks.example.com&contactemail=tasks-' . self::ENTROPY
            . '%40wltasks.example.com&quota=64&forcedns=0&hasshell=0&ip=n';

        return [
            new Request('POST', self::WHM . 'createacct?api.version=1', $form, $create),
            new Request('POST', self::WHM . 'removeacct?api.version=1', $form, 'username=wltasks&keepdns=0'),
            new Request(
                'POST',
                self::BRIDGE . 'create_full_access',
                $form,
                'name=wltasks-fixture&expires_at=' . self::expiresAt(),
            ),
            new Request('POST', self::BRIDGE . 'revoke', $form, 'name=wltasks-fixture'),
        ];
    }

    private function createRequest(string $body): RequestInterface
    {
        return new Request('POST', $this->recipe->createUri(), [
            ...self::directHeaders(), 'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);
    }

    private function deleteRequest(string $body): RequestInterface
    {
        return new Request('POST', $this->recipe->deleteUri(), [
            ...self::directHeaders(), 'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);
    }

    private function guard(bool $bound): UserTasksGuard
    {
        $test = $this;
        $http = new class ($test) implements ClientInterface {
            public function __construct(private readonly UserTasksGuardTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->record($request);

                return new Response(200);
            }
        };
        $guard = new UserTasksGuard($http, 'WHMTOKEN', $this->recipe);
        if ($bound) {
            $guard->bindToken('TEMPORARY');
        }

        return $guard;
    }

    public function record(RequestInterface $request): void
    {
        $this->sent[] = $request;
    }

    private function assertRefused(UserTasksGuard $guard, RequestInterface $request): void
    {
        $sent = count($this->sent);
        $message = null;
        try {
            $guard->sendRequest($request);
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
        }
        self::assertSame(self::REFUSED, $message);
        self::assertCount($sent, $this->sent);
    }

    /** @param callable(): void $authorize */
    private function assertRefusedAuthorization(callable $authorize): void
    {
        $message = null;
        try {
            $authorize();
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
        }
        self::assertSame(self::REFUSED, $message);
    }

    /** One expiry for the whole class, as providers run first and the binding enforces the token bound. */
    private static function expiresAt(): int
    {
        return self::$expiresAt ??= time() + 1800;
    }

    /** @return array<string, string> */
    private static function whmHeaders(): array
    {
        return ['Host' => 'test.oblak.host:2087', 'Authorization' => 'whm root:WHMTOKEN',
            'Accept' => 'application/json'];
    }

    /** @return array<string, string> */
    private static function directHeaders(): array
    {
        return ['Host' => 'test.oblak.host:2083', 'Authorization' => 'cpanel wltasks:TEMPORARY',
            'Accept' => 'application/json'];
    }
}
