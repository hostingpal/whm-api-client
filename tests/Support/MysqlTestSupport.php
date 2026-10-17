<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

/** Shared MySQL test mechanics; endpoint arguments, expected requests and payloads stay in the test suites. */
final class MysqlTestSupport
{
    /** Assert literal request expectations supplied by the independent endpoint tests. */
    public static function assertRequest(
        MockClient $http,
        string $method,
        string $uri,
        string $body,
        string $authorization,
        ?string $contentType = null,
    ): void {
        $request = $http->getLastRequest();
        Assert::assertInstanceOf(RequestInterface::class, $request);
        Assert::assertSame($uri, (string) $request->getUri());
        Assert::assertSame($method, $request->getMethod());
        Assert::assertSame($body, (string) $request->getBody());
        Assert::assertSame($authorization, $request->getHeaderLine('Authorization'));
        if ($contentType !== null) {
            Assert::assertSame($contentType, $request->getHeaderLine('Content-Type'));
        }
    }

    public static function envelope(string $route, string $function, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic MySQL failure"]')
            . ',"messages":null,"warnings":["Synthetic MySQL notice"],"metadata":{"transformed":1}}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Mysql","func":"' . $function . '","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    public static function client(string $route, ?string $defaultCpanelUser = null): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = [
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ];
        if ($defaultCpanelUser !== null) {
            $options['defaultCpanelUser'] = $defaultCpanelUser;
        }

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
