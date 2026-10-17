<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\MysqlFixtureGuard;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class MysqlFixtureGuardTest extends TestCase
{
    private const BASE = 'https://test.oblak.host:2083/execute/Mysql/';

    private string|false $live;

    private string|false $mutations;

    protected function setUp(): void
    {
        $this->live = getenv('WHM_API_LIVE');
        $this->mutations = getenv('WHM_API_LIVE_MUTATIONS');
        putenv('WHM_API_LIVE=1');
        putenv('WHM_API_LIVE_MUTATIONS=1');
    }

    protected function tearDown(): void
    {
        putenv($this->live === false ? 'WHM_API_LIVE' : 'WHM_API_LIVE=' . $this->live);
        putenv($this->mutations === false ? 'WHM_API_LIVE_MUTATIONS' : 'WHM_API_LIVE_MUTATIONS=' . $this->mutations);
    }

    #[DataProvider('approvedRequests')]
    public function testOnlyTheBoundFixtureRequestsReachTransport(string $method, string $function, string $body): void
    {
        $http = new Client();
        $response = new Response(200, [], '{}');
        $http->addResponse($response);
        $guard = new MysqlFixtureGuard($http, 'private-token', 'a1b2c3', 'private!password');

        self::assertSame($response, $guard->sendRequest(self::request($method, $function, $body)));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function approvedRequests(): iterable
    {
        yield 'database inventory' => ['GET', 'list_databases', ''];
        yield 'user inventory' => ['GET', 'list_users', ''];
        yield 'create linked database' => ['POST', 'create_database', 'name=npr_wva1b2c3d'];
        yield 'create unlinked database' => ['POST', 'create_database', 'name=npr_wva1b2c3e'];
        yield 'create linked user' => ['POST', 'create_user', 'name=npr_wva1b2c3u&password=private%21password'];
        yield 'create unlinked user' => ['POST', 'create_user', 'name=npr_wva1b2c3v&password=private%21password'];
        yield 'link pair' => ['POST', 'set_privileges_on_database',
            'database=npr_wva1b2c3d&user=npr_wva1b2c3u&privileges=SELECT'];
        yield 'delete database' => ['POST', 'delete_database', 'name=npr_wva1b2c3d'];
        yield 'delete user' => ['POST', 'delete_user', 'name=npr_wva1b2c3u'];
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectsEveryBoundaryDifferenceBeforeTransport(RequestInterface $request): void
    {
        $http = new Client();
        $guard = new MysqlFixtureGuard($http, 'private-token', 'a1b2c3', 'private!password');
        try {
            $guard->sendRequest($request);
            self::fail('An unreviewed request reached the fixture boundary.');
        } catch (LogicException $error) {
            self::assertStringNotContainsString('private', $error->getMessage());
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<string, array{RequestInterface}> */
    public static function rejectedRequests(): iterable
    {
        $valid = self::request('POST', 'create_user', 'name=npr_wva1b2c3u&password=private%21password');
        foreach (
            [
            'duplicate' => 'name=npr_wva1b2c3u&name=other&password=private%21password',
            'encoded duplicate' => 'name=npr_wva1b2c3u&%6eame=other&password=private%21password',
            'extra account' => 'name=npr_wva1b2c3u&password=private%21password&cpanel.user=other',
            'bracket name' => 'name[]=npr_wva1b2c3u&password=private%21password',
            'other fixture' => 'name=npr_wvffffffu&password=private%21password',
            'other account' => 'name=other_wva1b2c3u&password=private%21password',
            'wrong password' => 'name=npr_wva1b2c3u&password=other',
            ] as $case => $body
        ) {
            yield $case => [self::request('POST', 'create_user', $body)];
        }
        yield 'mutation GET' => [self::request('GET', 'create_database', '')];
        yield 'inventory POST' => [self::request('POST', 'list_users', '')];
        yield 'inventory body' => [self::request('GET', 'list_users', 'name=other')];
        yield 'other operation' => [self::request('POST', 'rename_user', 'name=npr_wva1b2c3u')];
        yield 'other link' => [self::request(
            'POST',
            'set_privileges_on_database',
            'database=npr_wva1b2c3e&user=npr_wva1b2c3v&privileges=SELECT',
        )];
        yield 'wrong privilege' => [self::request(
            'POST',
            'set_privileges_on_database',
            'database=npr_wva1b2c3d&user=npr_wva1b2c3u&privileges=ALL%20PRIVILEGES',
        )];
        yield 'token owner' => [$valid->withHeader('Authorization', 'cpanel other:private-token')];
        yield 'other token' => [$valid->withHeader('Authorization', 'cpanel npr:other')];
        yield 'duplicate auth' => [$valid->withAddedHeader('Authorization', 'cpanel npr:private-token')];
        yield 'json content' => [$valid->withHeader('Content-Type', 'application/json')];
        yield 'host header' => [$valid->withHeader('Host', 'other.test:2083')];
        yield 'extra header' => [$valid->withHeader('Cookie', 'private=secret')];
        yield 'accept' => [$valid->withHeader('Accept', 'text/plain')];
        foreach (
            [
            'host' => 'https://other.test:2083/execute/Mysql/create_user',
            'port' => 'https://test.oblak.host:2087/execute/Mysql/create_user',
            'scheme' => 'http://test.oblak.host:2083/execute/Mysql/create_user',
            'query' => self::BASE . 'create_user?password=private',
            'fragment' => self::BASE . 'create_user#private',
            'userinfo' => 'https://private@test.oblak.host:2083/execute/Mysql/create_user',
            ] as $case => $uri
        ) {
            yield $case => [$valid->withUri((new Request('POST', $uri))->getUri())];
        }
    }

    #[DataProvider('missingOptIn')]
    public function testChecksBothOptInsOnEverySend(string $variable): void
    {
        $http = new Client();
        $guard = new MysqlFixtureGuard($http, 'private-token', 'a1b2c3', 'private!password');
        putenv($variable . '=0');
        $this->expectException(LogicException::class);
        try {
            $guard->sendRequest(self::request('GET', 'list_users', ''));
        } finally {
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function missingOptIn(): iterable
    {
        yield 'live' => ['WHM_API_LIVE'];
        yield 'mutations' => ['WHM_API_LIVE_MUTATIONS'];
    }

    private static function request(string $method, string $function, string $body): RequestInterface
    {
        $headers = ['Authorization' => 'cpanel npr:private-token', 'Accept' => 'application/json'];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return new Request($method, self::BASE . $function, $headers, $body);
    }
}
