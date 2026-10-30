<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EmailSectionGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\EmailSectionRecipe;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class EmailSectionGuardTest extends TestCase
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

    public function testExactMailboxCreationCannotSendWelcomeMailAndIsOneShot(): void
    {
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            $http = new MockClient(new Psr17Factory());
            $http->addResponse(new Response(200, [], '{}'));
            $guard = new EmailSectionGuard(
                $http,
                ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
                new EmailSectionRecipe('a1b2c3', 'private-password'),
            );
            $guard->authorize('create_mailbox', $route);
            $request = self::request($route);
            self::assertSame(200, $guard->sendRequest($request)->getStatusCode());
            self::assertCount(1, $http->getRequests());
            try {
                $guard->sendRequest($request);
                self::fail('Consumed authorization was replayed');
            } catch (LogicException) {
                self::assertCount(1, $http->getRequests());
            }
            try {
                $guard->authorize('create_mailbox', $route);
                self::fail('Consumed phase was authorized again');
            } catch (LogicException) {
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function deviations(): iterable
    {
        foreach (
            ['welcome', 'password-query', 'other-mailbox', 'account', 'function', 'module',
            'method', 'host', 'duplicate', 'extra-header', 'authorization', 'missing-accept'] as $change
        ) {
            yield $change => [$change];
        }
    }

    #[DataProvider('deviations')]
    public function testAnyWireDeviationFailsBeforeIoAndConsumesAuthorization(string $change): void
    {
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            $http = new MockClient(new Psr17Factory());
            $guard = new EmailSectionGuard(
                $http,
                ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
                new EmailSectionRecipe('a1b2c3', 'private-password'),
            );
            $guard->authorize('create_mailbox', $route);
            $request = self::request($route);
            $uri = $request->getUri();
            $body = (string) $request->getBody();
            $factory = new Psr17Factory();
            $changed = match ($change) {
                'welcome' => $request->withBody($factory->createStream(
                    str_replace('send_welcome_email=0', 'send_welcome_email=1', $body),
                )),
                'password-query' => $request->withUri($uri->withQuery($uri->getQuery() . '&password=private-password')),
                'other-mailbox' => $request->withBody($factory->createStream(
                    str_replace('wema1b2c3', 'unrelated', $body),
                )),
                'account' => $request->withUri($uri->withQuery($uri->getQuery() . '&cpanel.user=other')),
                'function' => $request->withUri($uri->withPath('/execute/Email/dispatch_client_settings')),
                'module' => $request->withUri($uri->withQuery($uri->getQuery() . '&cpanel.module=Other')),
                'method' => $request->withMethod('GET'),
                'host' => $request->withUri($uri->withHost('other.test')),
                'duplicate' => $request->withBody($factory->createStream($body . '&send_welcome_email=0')),
                'extra-header' => $request->withHeader('X-Extra', 'value'),
                'authorization' => $request->withHeader('Authorization', 'cpanel other:private-cpanel'),
                'missing-accept' => $request->withoutHeader('Accept'),
                default => throw new LogicException('Unknown test change.'),
            };
            try {
                $guard->sendRequest($changed);
                self::fail('Deviation reached upstream I/O');
            } catch (LogicException) {
                // Also attempt replay below, then prove neither request reached upstream.
            }
            try {
                $guard->sendRequest($request);
                self::fail('Rejected authorization was replayed');
            } catch (LogicException) {
                self::assertSame([], $http->getRequests());
            }
        }
    }

    public function testUnknownOrOutboundPhasesAndMissingOptInsNeverReachIo(): void
    {
        $http = new MockClient(new Psr17Factory());
        $guard = new EmailSectionGuard(
            $http,
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            new EmailSectionRecipe('a1b2c3', 'private-password'),
        );
        foreach (
            ['release_outgoing', 'dispatch_client_settings', 'send_welcome_email', 'add_auto_responder'] as $phase
        ) {
            try {
                $guard->authorize($phase, 'direct-uapi');
                self::fail('Outbound phase was accepted');
            } catch (LogicException) {
                self::assertSame([], $http->getRequests());
            }
        }
        $guard->authorize('create_mailbox', 'direct-uapi');
        putenv('WHM_API_LIVE_MUTATIONS=0');
        $this->expectException(LogicException::class);
        $guard->sendRequest(self::request('direct-uapi'));
    }

    private static function request(string $route): RequestInterface
    {
        $bridge = $route === 'bridged-uapi';
        $uri = $bridge
            ? 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Email&cpanel.function=add_pop'
            : 'https://test.oblak.host:2083/execute/Email/add_pop';

        return new Request('POST', $uri, [
            'Authorization' => $bridge ? 'whm root:private-whm' : 'cpanel npr:private-cpanel',
            'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded',
        ], 'email=wema1b2c3' . ($bridge ? 'b' : 'd')
            . '&password=private-password&domain=npr.rs&quota=32&send_welcome_email=0');
    }
}
