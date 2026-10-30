<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\SpamPreferenceGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\SpamPreferenceRecipe;
use HostingPal\WhmApiClient\Tools\LiveVerify\SpamPreferenceState;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SpamPreferenceGuardTest extends TestCase
{
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

    public function testWritesRequireSnapshotAndConsumeAuthorizationBeforeIo(): void
    {
        $http = new MockClient();
        $recipe = new SpamPreferenceRecipe();
        $guard = new SpamPreferenceGuard($http, ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'], $recipe);
        try {
            $guard->authorize('set_direct');
            self::fail('Unbound snapshot authorized a write.');
        } catch (LogicException) {
            self::assertSame([], $http->getRequests());
        }
        $recipe->bind(new SpamPreferenceState('{"status":1,"data":{"required_score":["5.0"]}}', 'direct-uapi'));
        $guard->authorize('set_direct');
        $http->addResponse(new Response(200));
        $guard->sendRequest(self::request(false));
        foreach (['send', 'authorize'] as $attempt) {
            try {
                if ($attempt === 'send') {
                    $guard->sendRequest(self::request(false));
                } else {
                    $guard->authorize('set_direct');
                }
                self::fail('Consumed write phase replayed.');
            } catch (LogicException) {
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function deviations(): iterable
    {
        foreach (
            ['other-preference', 'other-value', 'duplicate', 'extra-field', 'query-value', 'account',
                'module', 'function', 'method', 'host', 'port', 'authorization', 'extra-header', 'accept'] as $change
        ) {
            yield $change => [$change];
        }
    }

    #[DataProvider('deviations')]
    public function testWireDeviationsCannotReachTheTransportOrReplay(string $change): void
    {
        foreach ([false, true] as $bridge) {
            $http = new MockClient();
            $guard = self::guard($http);
            $phase = $bridge ? 'set_bridge' : 'set_direct';
            $guard->authorize($phase);
            $request = self::request($bridge);
            $uri = $request->getUri();
            $factory = new Psr17Factory();
            $changed = match ($change) {
                'other-preference' => $request->withBody($factory->createStream('preference=whitelist_from&value=5.1')),
                'other-value' => $request->withBody($factory->createStream('preference=required_score&value=9')),
                'duplicate' => $request->withBody($factory->createStream((string) $request->getBody() . '&value=5.1')),
                'extra-field' => $request->withBody($factory->createStream((string) $request->getBody() . '&other=x')),
                'query-value' => $request->withUri($uri->withQuery($uri->getQuery() . '&value=5.1')),
                'account' => $request->withUri($uri->withQuery($uri->getQuery() . '&cpanel.user=other')),
                'module' => $request->withUri($uri->withQuery($uri->getQuery() . '&cpanel.module=Other')),
                'function' => $request->withUri($uri->withPath('/execute/SpamAssassin/clear_spam_box')),
                'method' => $request->withMethod('GET'),
                'host' => $request->withUri($uri->withHost('other.test')),
                'port' => $request->withUri($uri->withPort(443)),
                'authorization' => $request->withHeader('Authorization', 'cpanel other:private-cpanel'),
                'extra-header' => $request->withHeader('X-Extra', 'value'),
                'accept' => $request->withoutHeader('Accept'),
                default => throw new LogicException('Unknown synthetic deviation.'),
            };
            foreach ([$changed, $request] as $attempt) {
                try {
                    $guard->sendRequest($attempt);
                    self::fail('Wire deviation or consumed authorization reached I/O.');
                } catch (LogicException) {
                    self::assertSame([], $http->getRequests());
                }
            }
        }
    }

    public function testRestoreCannotRemoveAnOriginallyConfiguredPreference(): void
    {
        $http = new MockClient();
        $guard = self::guard($http);
        $guard->authorize('restore_direct');
        $request = self::request(false)->withBody((new Psr17Factory())->createStream('preference=required_score'));
        try {
            $guard->sendRequest($request);
            self::fail('Configured original was removed.');
        } catch (LogicException) {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testEachOptInIsRecheckedBeforeEachRequestAndUnknownPhasesFailClosed(): void
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_ACCOUNT', 'WHM_API_LIVE_DOMAIN'] as $key) {
            $original = getenv($key);
            $http = new MockClient();
            $guard = self::guard($http);
            $guard->authorize('set_direct');
            putenv($key . '=invalid');
            try {
                $guard->sendRequest(self::request(false));
                self::fail('Missing exact opt-in reached I/O.');
            } catch (LogicException) {
                self::assertSame([], $http->getRequests());
            } finally {
                putenv($original === false ? $key : $key . '=' . $original);
            }
        }
        $http = new MockClient();
        $guard = self::guard($http);
        foreach (['clear_spam_box', 'set_server', 'enable_all_domains', 'delete_mailbox', 'catalog_019'] as $phase) {
            try {
                $guard->authorize($phase);
                self::fail('Unreviewed phase authorized.');
            } catch (LogicException) {
                self::assertSame([], $http->getRequests());
            }
        }
    }

    private static function guard(MockClient $http): SpamPreferenceGuard
    {
        $recipe = new SpamPreferenceRecipe();
        $recipe->bind(new SpamPreferenceState('{"status":1,"data":{"required_score":["5.0"]}}', 'direct-uapi'));

        return new SpamPreferenceGuard($http, ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'], $recipe);
    }

    private static function request(bool $bridge): RequestInterface
    {
        $uri = $bridge
            ? 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SpamAssassin&cpanel.function=update_user_preference'
            : 'https://test.oblak.host:2083/execute/SpamAssassin/update_user_preference';

        return new Request('POST', $uri, [
            'Authorization' => $bridge ? 'whm root:private-whm' : 'cpanel npr:private-cpanel',
            'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded',
        ], 'preference=required_score&value=5.1');
    }
}
