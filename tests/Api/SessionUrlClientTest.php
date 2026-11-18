<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\SessionUrlClient;
use Http\Mock\Client;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class SessionUrlClientTest extends TestCase
{
    private const PATH = '/cpsess1234567890/execute/Session/create_temp_user';
    private const COOKIE = 'session-private%3aabc';

    /** @return iterable<string, array{int,string}> */
    public static function services(): iterable
    {
        yield 'cPanel' => [2083, 'cpsession'];
        yield 'Webmail' => [2096, 'webmailsession'];
    }

    #[DataProvider('services')]
    public function testPostsOnceWithOnlyTheBoundServiceCookie(int $port, string $name): void
    {
        $origin = 'https://example.test:' . $port;
        $http = new Client();
        $session = new SessionUrlClient($origin, $origin . self::PATH, self::COOKIE, self::transport($http));
        self::assertCount(0, $http->getRequests());
        $http->addResponse(new Response(
            200,
            [],
            '{"result":{"status":1,"data":{"created":1,"session_temp_user":"id"}}}',
        ));
        self::assertSame(['created' => 1, 'session_temp_user' => 'id'], $session->createTempUser());
        self::assertSame(1, $session->responseMeta()?->uapiStatus());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertCount(1, $http->getRequests());
        self::assertSame('POST', $request->getMethod());
        self::assertSame($origin . self::PATH, (string) $request->getUri());
        self::assertSame($name . '=' . self::COOKIE, $request->getHeaderLine('Cookie'));
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $request->getBody());
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"created":0}}'));
        self::assertSame(['created' => 0], $session->createTempUser());
    }

    public function testOriginComparisonAcceptsSchemeAndHostCaseDifferences(): void
    {
        $http = new Client();
        $session = new SessionUrlClient(
            'HTTPS://PANEL.example.test:2083',
            'https://panel.example.test:2083' . self::PATH,
            self::COOKIE,
            self::transport($http),
        );
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"created":0}}'));
        self::assertSame(['created' => 0], $session->createTempUser());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{bool}> */
    public static function exportStates(): iterable
    {
        yield 'newly bound session' => [false];
        yield 'after request and reflected metadata' => [true];
    }

    #[DataProvider('exportStates')]
    public function testObjectExportAndArrayCastHideStoredCredentials(bool $sent): void
    {
        $origin = 'https://example.test:2083';
        $url = $origin . self::PATH;
        $http = new Client();
        $session = new SessionUrlClient($origin, $url, self::COOKIE, self::transport($http));
        if ($sent) {
            $messages = [$url, self::COOKIE];
            $envelope = ['status' => 1, 'data' => ['created' => 0], 'messages' => $messages];
            $body = json_encode($envelope, JSON_THROW_ON_ERROR);
            $http->addResponse(new Response(200, [], $body));
            self::assertSame(['created' => 0], $session->createTempUser());
            self::assertSame($messages, $session->responseMeta()?->uapiMessages());
            self::assertCount(1, $http->getRequests());
        }
        $properties = (array) $session;
        foreach ([$url, self::COOKIE] as $value) {
            self::assertNotContains($value, $properties);
        }
        foreach ([var_export($session, true), var_export($properties, true)] as $export) {
            self::assertStringNotContainsString('cpsess1234567890', $export);
            self::assertStringNotContainsString(self::COOKIE, $export);
        }
    }

    public function testBindingStartsWithoutResponseMetadata(): void
    {
        $origin = 'https://example.test:2083';
        $session = new SessionUrlClient($origin, $origin . self::PATH, self::COOKIE, self::transport(new Client()));
        self::assertNull($session->responseMeta());
    }

    public function testEitherPayloadFieldMayBeOmitted(): void
    {
        $origin = 'https://example.test:2083';
        $http = new Client();
        $session = new SessionUrlClient($origin, $origin . self::PATH, self::COOKIE, self::transport($http));
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"created":1}}'));
        self::assertSame(['created' => 1], $session->createTempUser());
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"session_temp_user":"id"}}'));
        self::assertSame(['session_temp_user' => 'id'], $session->createTempUser());
        $http->addResponse(new Response(200, [], '{"status":1,"data":{}}'));
        self::assertSame([], $session->createTempUser());
    }

    /** @return iterable<string, array{string,string,string}> */
    public static function invalidSessions(): iterable
    {
        $origin = 'https://example.test:2083';
        $badOrigins = [
            'http://example.test:2083',
            'https://example.test:2087',
            'https://user@example.test:2083',
            'https://example.test:2083/path',
            'https://example.test:2083?x',
            'https://example.test:2083#x',
            'https://999.999.999.999:2083',
            'https://[::::]:2083',
            'https://127.1:2083',
            'https://0x7f000001:2083',
            'https://0x7f.0.0.1:2083',
            'https://0177.0.0.1:2083',
            'https://example.test.:2083',
        ];
        foreach ($badOrigins as $bad) {
            yield 'origin ' . $bad => [$bad, $bad . self::PATH, self::COOKIE];
        }
        $badUrls = [
            'https://other.test:2083' . self::PATH,
            'https://example.test:2096' . self::PATH,
            $origin . '/login/?session=x',
            $origin . self::PATH . '?q=x',
            $origin . self::PATH . '#x',
            $origin . '/cpsess12/../execute/Session/create_temp_user',
            $origin . '/cpsess123%2f456/execute/Session/create_temp_user',
            $origin . '/cpsess123/execute/Other/call',
        ];
        foreach ($badUrls as $bad) {
            yield 'url ' . $bad => [$origin, $bad, self::COOKIE];
        }
        foreach (['', 'two values', "x\r\ny", 'x; another=secret', 'x,y', '"x"', 'x\\y'] as $i => $bad) {
            yield 'cookie ' . $i => [$origin, $origin . self::PATH, $bad];
        }
    }

    #[DataProvider('invalidSessions')]
    public function testRejectsUnboundEndpointsAndInvalidCookieBytes(string $origin, string $url, string $cookie): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionUrlClient($origin, $url, $cookie, self::transport(new Client()));
    }

    public function testFailureMetadataAndTransportDiagnostics(): void
    {
        $origin = 'https://example.test:2083';
        $http = new Client();
        $session = new SessionUrlClient($origin, $origin . self::PATH, self::COOKIE, self::transport($http));
        $http->addResponse(new Response(200, [], '{"status":0,"errors":["API refused"]}'));
        try {
            $session->createTempUser();
            self::fail('Expected API failure.');
        } catch (ApiErrorException $e) {
            self::assertSame($e->metadata, $session->responseMeta());
            self::assertSame(0, $session->responseMeta()?->uapiStatus());
        }
        $http->addResponse(new Response(302, ['Location' => 'https://other.test/']));
        try {
            $session->createTempUser();
            self::fail('Expected HTTP failure.');
        } catch (HttpException) {
            self::assertNull($session->responseMeta());
        }
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"created":"invalid"}}'));
        try {
            $session->createTempUser();
            self::fail('Expected payload mismatch.');
        } catch (PayloadMismatchException $e) {
            self::assertTrue($e->outcomeConfirmed);
            self::assertSame($e->metadata, $session->responseMeta());
        }
        $diagnostic = 'cURL error 60: ' . self::COOKIE . self::PATH;
        $http->addException(new class ($diagnostic) extends RuntimeException implements ClientExceptionInterface {
        });
        try {
            $session->createTempUser();
            self::fail('Expected transport failure.');
        } catch (TransportException $e) {
            self::assertNull($e->getPrevious());
            self::assertSame('TLS certificate verification failed.', $e->causeMessage);
            self::assertNull($session->responseMeta());
            self::assertStringNotContainsString(self::COOKIE, $e->causeMessage . $e->getMessage());
            self::assertStringNotContainsString(self::PATH, $e->causeMessage . $e->getMessage());
        }
        self::assertCount(4, $http->getRequests());
        self::assertSame([], $session->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($session);
    }

    private static function transport(Client $http): HttpTransport
    {
        $factory = new Psr17Factory();
        return new HttpTransport($http, $factory, $factory);
    }
}
