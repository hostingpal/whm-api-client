<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Http;

use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\CommonPsr17ClassesStrategy;
use Http\Discovery\Strategy\MockClientStrategy;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class HttpTransportTest extends TestCase
{
    /** @var list<string> */
    private array $discoveryStrategies;

    protected function setUp(): void
    {
        $this->discoveryStrategies = array_values([...Psr18ClientDiscovery::getStrategies()]);
        Psr18ClientDiscovery::setStrategies([]);
    }

    protected function tearDown(): void
    {
        Psr18ClientDiscovery::setStrategies($this->discoveryStrategies);
    }

    #[DataProvider('requestBodies')]
    public function testBuildsRequestsWithInjectedImplementations(?string $body, string $expectedBody): void
    {
        $factory = new Psr17Factory();
        $client = new MockClient($factory);
        $response = new Response(202, ['X-Response' => 'preserved'], 'accepted');
        $client->addResponse($response);
        $transport = new HttpTransport($client, $factory, $factory);

        $result = $transport->send(
            'POST',
            new Uri('https://example.test/resource?name=a%20b'),
            ['Content-Type' => 'application/json', 'X-Values' => ['first', 'second']],
            $body,
        );

        self::assertSame($response, $result);
        $request = $client->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.test/resource?name=a%20b', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['first', 'second'], $request->getHeader('X-Values'));
        self::assertSame($expectedBody, (string) $request->getBody());
        self::assertCount(1, $client->getRequests());
    }

    /** @return iterable<string, array{?string, string}> */
    public static function requestBodies(): iterable
    {
        yield 'absent' => [null, ''];
        yield 'empty' => ['', ''];
        yield 'zero' => ['0', '0'];
        yield 'json' => ['{"name":"example"}', '{"name":"example"}'];
    }

    public function testDiscoversClientAndFactoriesWithoutMakingNetworkRequests(): void
    {
        Psr18ClientDiscovery::setStrategies([MockClientStrategy::class, CommonPsr17ClassesStrategy::class]);

        $response = (new HttpTransport())->send('GET', 'https://example.test/resource');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDiscoversOmittedFactoriesAndPreservesTheInjectedClient(): void
    {
        Psr18ClientDiscovery::setStrategies([CommonPsr17ClassesStrategy::class]);
        $client = new MockClient(new Psr17Factory());
        $client->addResponse(new Response(201));

        $response = (new HttpTransport($client))->send('POST', 'https://example.test/resource', [], 'payload');

        self::assertSame(201, $response->getStatusCode());
        $request = $client->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('payload', (string) $request->getBody());
    }

    public function testReportsMissingHttpClient(): void
    {
        $factory = new Psr17Factory();
        $this->expectException(NotFoundException::class);

        new HttpTransport(null, $factory, $factory);
    }

    public function testReportsMissingRequestFactory(): void
    {
        $factory = new Psr17Factory();
        $this->expectException(NotFoundException::class);

        new HttpTransport(new MockClient($factory), null, $factory);
    }

    public function testReportsMissingStreamFactory(): void
    {
        $factory = new Psr17Factory();
        $this->expectException(NotFoundException::class);

        new HttpTransport(new MockClient($factory), $factory);
    }

    #[DataProvider('errorStatuses')]
    public function testReturnsHttpErrorResponsesForLaterApiHandling(int $status): void
    {
        $factory = new Psr17Factory();
        $client = new MockClient($factory);
        $response = new Response($status, [], 'error response');
        $client->addResponse($response);

        $result = (new HttpTransport($client, $factory, $factory))->send('GET', 'https://example.test/resource');

        self::assertSame($response, $result);
    }

    /** @return iterable<string, array{int}> */
    public static function errorStatuses(): iterable
    {
        yield 'client error' => [404];
        yield 'server error' => [500];
    }

    public function testPreservesTransportExceptions(): void
    {
        $factory = new Psr17Factory();
        $client = new MockClient($factory);
        $exception = new class ('Connection failed') extends RuntimeException implements ClientExceptionInterface {
        };
        $client->addException($exception);
        $this->expectExceptionObject($exception);

        (new HttpTransport($client, $factory, $factory))->send('GET', 'https://example.test/resource');
    }
}
