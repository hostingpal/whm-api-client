<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestGuardTest extends TestCase
{
    private const URI = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
        . '&cpanel.module=DomainInfo&cpanel.function=list_domains';

    public function testPassesExactlyApprovedRequestOnce(): void
    {
        $underlying = new Client();
        $response = new Response(200, [], 'approved');
        $underlying->addResponse($response);
        $request = new Request('GET', self::URI);

        self::assertSame($response, (new RequestGuard($underlying, self::URI))->sendRequest($request));
        self::assertSame($request, $underlying->getLastRequest());
        self::assertCount(1, $underlying->getRequests());
    }

    #[DataProvider('outsideScope')]
    public function testRejectsDifferentMethodUriOrBodyBeforeSending(string $method, string $uri, string $body): void
    {
        $underlying = new Client();
        $guard = new RequestGuard($underlying, self::URI);

        try {
            $guard->sendRequest(new Request($method, $uri, [], $body));
            self::fail('Request outside the exact recipe must be rejected.');
        } catch (LogicException $exception) {
            self::assertStringNotContainsString('private', $exception->getMessage());
        }

        self::assertCount(0, $underlying->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function outsideScope(): iterable
    {
        yield 'mutation' => ['POST', self::URI, ''];
        yield 'body' => ['GET', self::URI, 'private=secret'];
        yield 'account' => ['GET', str_replace('user=npr', 'user=private', self::URI), ''];
        yield 'argument' => ['GET', self::URI . '&private=secret', ''];
        yield 'host' => ['GET', str_replace('test.oblak.host', 'private.test', self::URI), ''];
        yield 'fragment' => ['GET', self::URI . '#private', ''];
    }
}
