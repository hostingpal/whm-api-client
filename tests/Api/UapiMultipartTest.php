<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

final class UapiMultipartTest extends TestCase
{
    #[DataProvider('routes')]
    public function testMultipartPreservesBytesAndKeepsRoutingOutsideBody(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route)));
        $key = "private\x00\xff\r\nvalue\n\"&=+--candidate-boundary";
        $account = $client->uapi('exampleuser');
        $run = new ReflectionMethod($account, 'run');
        self::assertNull($run->invoke($account, self::spec(), ['key' => $key, 'friendlyName' => '']));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $contentType = $request->getHeaderLine('Content-Type');
        self::assertMatchesRegularExpression('/\Amultipart\/form-data; boundary=[A-Za-z0-9_-]{20,70}\z/', $contentType);
        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));
        self::assertStringNotContainsString($boundary, $key);
        self::assertSame(
            '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"key\"\r\n\r\n" . $key
            . "\r\n--" . $boundary . "\r\nContent-Disposition: form-data; name=\"friendly_name\"\r\n\r\n"
            . "\r\n--" . $boundary . "--\r\n",
            (string) $request->getBody(),
        );
        self::assertSame('POST', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=SSL&cpanel.function=upload_key'
            : 'https://api.example.test:2083/execute/SSL/upload_key', (string) $request->getUri());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testMalformedMultipartValuesClearMetadataBeforeIo(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route)));
        $account = $client->uapi('exampleuser');
        $account->call('SSL', 'fixture');
        self::assertNotNull($account->responseMeta());
        try {
            (new ReflectionMethod($account, 'run'))->invoke($account, self::spec(), ['key' => 123]);
            self::fail('Multipart requires string fields.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('123', $error->getMessage());
            self::assertNull($account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidSpecs')]
    public function testRejectsUnsupportedMultipartSpecs(array $changes): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(array_replace(self::spec(), $changes));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSpecs(): iterable
    {
        yield 'unknown body' => [['body' => 'json']];
        yield 'null body' => [['body' => null]];
        yield 'GET body' => [['method' => 'GET']];
        yield 'list fields' => [['params' => ['key' => ['name' => 'key', 'encoding' => 'repeat']]]];
    }

    public function testMultipartDoesNotBecomeAGenericCallOption(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct')));
        $client->uapi()->call('SSL', 'upload_key', ['key' => 'fixture']);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('key=fixture', (string) $request->getBody());
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return ['module' => 'SSL', 'function' => 'upload_key', 'method' => 'POST', 'outcome' => 'flag',
            'body' => 'multipart', 'params' => [
                'key' => ['name' => 'key', 'encoding' => 'scalar'],
                'friendlyName' => ['name' => 'friendly_name', 'encoding' => 'scalar'],
            ], 'returns' => null];
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    private static function envelope(string $route): string
    {
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":null}}}'
            : '{"result":{"status":1,"data":null}}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
