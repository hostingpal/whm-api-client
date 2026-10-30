<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\PostgresqlSectionGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\PostgresqlSectionRecipe;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PostgresqlSectionGuardTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $settings = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'];
        foreach ($settings as $key => $value) {
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

    #[DataProvider('alteredRequests')]
    public function testEveryBoundaryDifferenceIsRejectedBeforeTransport(RequestInterface $request): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->authorize('create_user', 'bridged-uapi');
        try {
            $guard->sendRequest($request);
            self::fail('Unexpected request reached transport.');
        } catch (LogicException $error) {
            self::assertStringNotContainsString('private', $error->getMessage());
            self::assertSame([], $http->getRequests());
        }
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function alteredRequests(): iterable
    {
        $request = self::request();
        foreach (
            ['Authorization' => 'whm other:private-whm', 'Host' => 'other.test:2087',
            'Accept' => 'text/html', 'Content-Type' => 'application/json', 'Cookie' => 'private=value',
            ] as $header => $value
        ) {
            yield $header => [$request->withHeader($header, $value)];
        }
        yield 'duplicate authorization' => [$request->withAddedHeader('Authorization', 'whm root:private-whm')];
        yield 'GET secret' => [$request->withMethod('GET')];
        foreach (
            [
            'scheme' => 'http://test.oblak.host:2087', 'hostname' => 'https://other.test:2087',
            'port' => 'https://test.oblak.host:2083', 'userinfo' => 'https://private@test.oblak.host:2087',
            ] as $name => $base
        ) {
            yield $name => [$request->withUri((new Request('POST', $base
                . '/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Postgresql&cpanel.function=create_user'))->getUri())];
        }
        foreach (['&cpanel.user=other', '&password=private', '#fragment'] as $extra) {
            yield $extra => [$request->withUri((new Request('POST', (string) $request->getUri() . $extra))->getUri())];
        }
        foreach (
            [
            'duplicate' => 'name=npr_wpa1b2c3w&name=other&password=private%21password',
            'alias' => '%6eame=npr_wpa1b2c3w&password=private%21password',
            'other account' => 'name=other_wsuser&password=private%21password',
            'other route pair' => 'name=npr_wpa1b2c3u&password=private%21password',
            'array value' => 'name[]=npr_wpa1b2c3w&password=private%21password',
            'password' => 'name=npr_wpa1b2c3w&password=other',
            ] as $name => $body
        ) {
            yield $name => [new Request('POST', $request->getUri(), $request->getHeaders(), $body)];
        }
    }

    public function testPhaseAuthorizationIsConsumedAndCannotBeReplayed(): void
    {
        $http = new Client();
        $http->addResponse(new Response());
        $guard = self::guard($http);
        $guard->authorize('create_user', 'bridged-uapi');
        $guard->sendRequest(self::request());
        self::assertCount(1, $http->getRequests());
        try {
            $guard->sendRequest(self::request());
            self::fail('Phase was replayed.');
        } catch (LogicException) {
            self::assertCount(1, $http->getRequests());
        }
        $this->expectException(LogicException::class);
        $guard->authorize('create_user', 'bridged-uapi');
    }

    #[DataProvider('optIns')]
    public function testOptInsAreCheckedAgainAtSendTime(string $key): void
    {
        $http = new Client();
        $guard = self::guard($http);
        $guard->authorize('create_user', 'bridged-uapi');
        putenv($key . '=other');
        try {
            $guard->sendRequest(self::request());
            self::fail('Invalid opt-in was accepted.');
        } catch (LogicException) {
            self::assertSame([], $http->getRequests());
        }
    }

    /** @return iterable<array{string}> */
    public static function optIns(): iterable
    {
        yield ['WHM_API_LIVE'];
        yield ['WHM_API_LIVE_MUTATIONS'];
        yield ['WHM_API_LIVE_ACCOUNT'];
        yield ['WHM_API_LIVE_DOMAIN'];
    }

    private static function guard(Client $http): PostgresqlSectionGuard
    {
        return new PostgresqlSectionGuard(
            $http,
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
            new PostgresqlSectionRecipe('a1b2c3', 'private!password'),
        );
    }

    private static function request(): RequestInterface
    {
        return new Request('POST', 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
            . '&cpanel.user=npr&cpanel.module=Postgresql&cpanel.function=create_user', [
                'Authorization' => 'whm root:private-whm', 'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], 'name=npr_wpa1b2c3w&password=private%21password');
    }
}
