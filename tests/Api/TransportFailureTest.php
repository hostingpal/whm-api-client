<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Exception\TransportFailure;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class TransportFailureTest extends TestCase
{
    #[DataProvider('failures')]
    public function testClassifiesFailuresWithoutChainingTheCause(
        RuntimeException&ClientExceptionInterface $cause,
        TransportFailure $expected,
    ): void {
        [$client, $http] = $this->client();
        $http->addException($cause);

        try {
            $client->whm()->server()->version();
            self::fail('Expected transport failure.');
        } catch (TransportException $exception) {
            self::assertSame($expected, $exception->failure);
            self::assertSame('RuntimeException@anonymous', $exception->causeClass);
            self::assertStringNotContainsString("\0", $exception->getMessage());
            self::assertStringNotContainsString(__FILE__, $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertNull($exception->metadata);
            self::assertStringContainsString($expected->value, $exception->getMessage());
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{RuntimeException&ClientExceptionInterface, TransportFailure}> */
    public static function failures(): iterable
    {
        $request = (new Psr17Factory())->createRequest('GET', 'https://api.example.test:2087/json-api/version');

        yield 'network' => [self::networkException('Connection refused', $request), TransportFailure::Network];
        yield 'request' => [self::requestException('Malformed request', $request), TransportFailure::Request];
        yield 'other client failure' => [
            new class ('Client failed') extends RuntimeException implements ClientExceptionInterface {
            },
            TransportFailure::Other,
        ];
    }

    public function testRetainsTheCauseMessageWithCredentialsRedacted(): void
    {
        [$client, $http] = $this->client();
        $request = (new Psr17Factory())->createRequest('GET', 'https://api.example.test:2087/json-api/version');
        $http->addException(self::networkException(
            'SSL certificate problem: self-signed certificate; sent whm root:fixture-token',
            $request,
        ));

        try {
            $client->whm()->server()->version();
            self::fail('Expected transport failure.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('SSL certificate problem', $exception->causeMessage);
            self::assertStringNotContainsString('fixture-token', $exception->causeMessage);
            self::assertStringContainsString('[REDACTED]', $exception->causeMessage);
            self::assertStringNotContainsString('SSL certificate problem', $exception->getMessage());
        }
    }

    private static function networkException(
        string $message,
        RequestInterface $request,
    ): RuntimeException&NetworkExceptionInterface {
        return new class ($message, $request) extends RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }

    private static function requestException(
        string $message,
        RequestInterface $request,
    ): RuntimeException&RequestExceptionInterface {
        return new class ($message, $request) extends RuntimeException implements RequestExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }

    /** @return array{Client, MockClient} */
    private function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
