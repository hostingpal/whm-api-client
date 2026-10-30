<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\FtpSectionGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\FtpSectionRecipe;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class FtpSectionGuardTest extends TestCase
{
    /** @var array<string, string|false> */
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

    public function testExactDirectoryPreservingDeleteIsAuthorizedOnlyOnce(): void
    {
        $http = new Client();
        $response = new Response(200, [], '{}');
        $http->addResponse($response);
        $guard = self::guard($http);
        $guard->authorize('cleanup_account', 'direct-uapi');
        self::assertSame($response, $guard->sendRequest(self::delete()));
        $this->expectException(LogicException::class);
        try {
            $guard->authorize('cleanup_account', 'direct-uapi');
        } finally {
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('invalidDelete')]
    public function testRejectsUnsafeOrNonCanonicalDeletionBeforeTransport(RequestInterface $request): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->authorize('cleanup_account', 'direct-uapi');
        try {
            $guard->sendRequest($request);
            self::fail('Unsafe deletion was accepted.');
        } catch (LogicException $error) {
            self::assertStringNotContainsString('private', $error->getMessage());
            self::assertCount(0, $http->getRequests());
        }
        $this->expectException(LogicException::class);
        $guard->authorize('cleanup_account', 'direct-uapi');
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function invalidDelete(): iterable
    {
        foreach (['destroy=1', 'destroy=0&destroy=1', 'destroy=0&%64estroy=1', 'destroy[]=0', ''] as $destroy) {
            yield 'destroy ' . $destroy => [self::delete('user=wftp0123456789abcdef&' . $destroy . '&domain=npr.rs')];
        }
        yield 'other identity' => [self::delete('user=other&destroy=0&domain=npr.rs')];
        yield 'other domain' => [self::delete('user=wftp0123456789abcdef&destroy=0&domain=other')];
        yield 'other route identity' => [self::delete('user=wftpfedcba9876543210&destroy=0&domain=npr.rs')];
        yield 'extra parameter' => [self::delete('user=wftp0123456789abcdef&destroy=0&domain=npr.rs&cpanel.user=root')];
        yield 'GET' => [self::delete()->withMethod('GET')];
        yield 'extra query' => [self::delete()->withUri(self::delete()->getUri()->withQuery('destroy=1'))];
        yield 'other host' => [self::delete()->withUri(self::delete()->getUri()->withHost('other.test'))];
        yield 'other auth' => [self::delete()->withHeader('Authorization', 'cpanel other:private-cpanel')];
        yield 'duplicate auth' => [self::delete()->withAddedHeader('Authorization', 'cpanel npr:private-cpanel')];
        yield 'other content' => [self::delete()->withHeader('Content-Type', 'application/json')];
        yield 'extra header' => [self::delete()->withHeader('Cookie', 'private=secret')];
    }

    public function testFailedTransportConsumesPhaseAndPendingAuthorizationCannotBeOverwritten(): void
    {
        $http = new Client();
        $http->addException(new RuntimeException('private transport'));
        $guard = self::guard($http);
        $guard->authorize('cleanup_account', 'direct-uapi');
        try {
            $guard->authorize('create_account', 'direct-uapi');
            self::fail('Pending authorization was replaced.');
        } catch (LogicException) {
            self::assertCount(0, $http->getRequests());
        }
        try {
            $guard->sendRequest(self::delete());
            self::fail('Transport should fail.');
        } catch (RuntimeException) {
            self::assertCount(1, $http->getRequests());
        }
        $this->expectException(LogicException::class);
        $guard->sendRequest(self::delete());
    }

    #[DataProvider('missingEnvironment')]
    public function testChecksExactOptInsAtDispatch(string $key): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->authorize('cleanup_account', 'direct-uapi');
        putenv($key . '=wrong');
        $this->expectException(LogicException::class);
        try {
            $guard->sendRequest(self::delete());
        } finally {
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<array{string}> */
    public static function missingEnvironment(): iterable
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN'] as $key) {
            yield [$key];
        }
    }

    public function testUnreviewedSessionAndSettingsPhasesHaveNoAuthorization(): void
    {
        $http = new Client();
        $guard = self::guard($http);
        foreach (['kill_session', 'set_anonymous_ftp', 'set_anonymous_ftp_incoming', 'set_welcome_message'] as $phase) {
            try {
                $guard->authorize($phase, 'direct-uapi');
                self::fail('Unreviewed phase was authorized.');
            } catch (LogicException) {
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    private static function guard(Client $http): FtpSectionGuard
    {
        return new FtpSectionGuard(
            $http,
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            new FtpSectionRecipe(
                ['direct-uapi' => '0123456789abcdef', 'bridged-uapi' => 'fedcba9876543210'],
                'private!password',
            ),
        );
    }

    private static function delete(string $body = 'user=wftp0123456789abcdef&destroy=0&domain=npr.rs'): RequestInterface
    {
        return new Request(
            'POST',
            'https://test.oblak.host:2083/execute/Ftp/delete_ftp',
            ['Authorization' => 'cpanel npr:private-cpanel', 'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'],
            $body,
        );
    }
}
