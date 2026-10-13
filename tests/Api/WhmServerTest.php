<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class WhmServerTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function operations(): iterable
    {
        yield 'hostname' => ['hostname', 'gethostname', '{"hostname":"hostname.example.test"}'];
        yield 'load average' => ['loadAverage', 'systemloadavg', '{"one":0,"five":0.18,"fifteen":0.19}'];
        yield 'disk usage' => ['diskUsage', 'getdiskusage', '{"partition":[{"device":"/dev/vda1",'
            . '"disk":"vda1","filesystem":"/","mount":"/","available":377856,"total":495844,'
            . '"used":92388,"percentage":20,"inodes_available":20575847,"inodes_total":20970944,'
            . '"inodes_used":395097,"inodes_ipercentage":2}]}'];
    }

    #[DataProvider('operations')]
    public function testServerHealthOperationsAuthenticateAndExecuteExactlyOnce(
        string $method,
        string $operation,
        string $data,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $group = $whm->server();
        self::assertSame($group, $whm->server());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        $payload = match ($method) {
            'hostname' => $group->hostname(),
            'loadAverage' => $group->loadAverage()->toArray(),
            default => $group->diskUsage()->toArray(),
        };

        self::assertSame(match ($method) {
            'hostname' => 'hostname.example.test',
            'loadAverage' => ['one' => 0, 'five' => 0.18, 'fifteen' => 0.19],
            default => ['partition' => [[
                'available' => 377856,
                'device' => '/dev/vda1',
                'disk' => 'vda1',
                'filesystem' => '/',
                'inodes_available' => 20575847,
                'inodes_ipercentage' => 2,
                'inodes_total' => 20970944,
                'inodes_used' => 395097,
                'mount' => '/',
                'percentage' => 20,
                'total' => 495844,
                'used' => 92388,
            ]]],
        }, $payload);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
        self::assertSame(200, $whm->responseMeta()?->httpStatus);
        self::assertSame([
            'command' => $operation,
            'reason' => 'OK',
            'result' => 1,
            'version' => 1,
        ], $whm->responseMeta()->whm);
        self::assertNull($whm->responseMeta()->uapi);
        self::assertNull($client->uapi('account')->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    public function testSuccessiveServerOperationsReplaceOnlyTheirOwningMetadata(): void
    {
        [$client, $http] = $this->client();
        [$other] = $this->client();
        $whm = $client->whm();
        $snapshots = [];
        foreach (self::operations() as [$method, $operation, $data]) {
            $http->addResponse(new Response(200, [], self::response($operation, $data)));
            $whm->server()->{$method}();
            $snapshots[] = $whm->responseMeta();
        }

        self::assertSame('gethostname', $snapshots[0]?->whm['command'] ?? null);
        self::assertSame('systemloadavg', $snapshots[1]?->whm['command'] ?? null);
        self::assertSame('getdiskusage', $snapshots[2]?->whm['command'] ?? null);
        self::assertSame($snapshots[2], $whm->responseMeta());
        self::assertNull($other->whm()->responseMeta());
        self::assertNull($client->uapi('account')->responseMeta());
        self::assertCount(3, $http->getRequests());
    }

    public function testLoadAveragePreservesObservedDecimalStringResponse(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response(
            'systemloadavg',
            '{"one":"1.20","five":"1.19","fifteen":"1.23"}',
        )));

        $load = $client->whm()->server()->loadAverage();

        self::assertSame(['one' => '1.20', 'five' => '1.19', 'fifteen' => '1.23'], $load->toArray());
        self::assertSame(200, $client->whm()->responseMeta()?->httpStatus);
        self::assertSame(1, $client->whm()->responseMeta()?->whm['result'] ?? null);
    }

    public function testDiskUsagePreservesObservedZeroUsageAndUnavailableInodes(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response(
            'getdiskusage',
            '{"partition":[{"percentage":0},{"inodes_available":null,"inodes_ipercentage":null,'
                . '"inodes_total":null,"inodes_used":null}]}',
        )));

        $usage = $client->whm()->server()->diskUsage();

        self::assertSame(['partition' => [
            ['percentage' => 0],
            ['inodes_available' => null, 'inodes_ipercentage' => null, 'inodes_total' => null, 'inodes_used' => null],
        ]], $usage->toArray());
        self::assertSame(200, $client->whm()->responseMeta()?->httpStatus);
        self::assertSame(1, $client->whm()->responseMeta()?->whm['result'] ?? null);
    }

    #[DataProvider('invalidHostnames')]
    public function testHostnameRequiresAStringAndRetainsFailureMetadata(string $data): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response('gethostname', $data)));

        try {
            $client->whm()->server()->hostname();
            self::fail('Expected an invalid hostname payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            self::assertSame('gethostname', $exception->metadata?->whm['command'] ?? null);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHostnames(): iterable
    {
        foreach (
            ['null', '[]', '"text"', '{}', '{"hostname":null}', '{"hostname":123}',
            '{"hostname":[]}', '{"hostname":{}}', '{"hostname":true}'] as $data
        ) {
            yield $data => [$data];
        }
    }

    public function testHostnamePreservesTheUpstreamString(): void
    {
        [$client, $http] = $this->client();
        foreach (['', 'HOSTNAME.example.test.'] as $hostname) {
            $http->addResponse(new Response(200, [], self::response(
                'gethostname',
                json_encode(['hostname' => $hostname], JSON_THROW_ON_ERROR),
            )));
            self::assertSame($hostname, $client->whm()->server()->hostname());
        }
    }

    /** @param class-string<ApiException> $exceptionClass */
    #[DataProvider('failedResponses')]
    public function testFailuresReplaceStaleMetadataWithoutRetry(
        string $method,
        int $status,
        string $body,
        string $exceptionClass,
        int|string|null $result,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::response('gethostname', '{"hostname":"example.test"}')));
        $whm->server()->hostname();
        $retained = $whm->responseMeta();
        $http->addResponse(new Response($status, [], $body));

        try {
            $whm->server()->{$method}();
            self::fail('Expected a server operation failure.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($whm->responseMeta(), $exception->metadata);
            self::assertNotSame($retained, $whm->responseMeta());
            self::assertSame($result, $exception->metadata?->whm['result'] ?? null);
            self::assertSame('gethostname', $retained?->whm['command'] ?? null);
            if ($exception instanceof HttpException) {
                self::assertSame($status, $exception->httpStatus);
            }
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, int, string, class-string<ApiException>, int|string|null}> */
    public static function failedResponses(): iterable
    {
        foreach (['hostname', 'loadAverage', 'diskUsage'] as $method) {
            yield $method . ' API error' => [$method, 200, '{"metadata":{"result":0,"reason":"fixture-token"}}',
                ApiErrorException::class, 0];
            yield $method . ' missing data' => [$method, 200, '{"metadata":{"result":1}}',
                InvalidResponseException::class, 1];
            yield $method . ' invalid outcome' => [$method, 200, '{"metadata":{"result":"1"},"data":{}}',
                InvalidResponseException::class, '1'];
            yield $method . ' HTTP error' => [$method, 403, '{"metadata":{"result":0}}', HttpException::class, 0];
            yield $method . ' redirect' => [$method, 302, '', HttpException::class, null];
            yield $method . ' malformed JSON' => [$method, 200, '{invalid', InvalidResponseException::class, null];
        }
        yield 'bad load window' => ['loadAverage', 200, '{"metadata":{"result":1},"data":{"five":"0.18oops"}}',
            InvalidResponseException::class, 1];
        yield 'bad disk record' => ['diskUsage', 200, '{"metadata":{"result":1},"data":{"partition":[false]}}',
            InvalidResponseException::class, 1];
    }

    #[DataProvider('operations')]
    public function testTransportFailureClearsMetadataWithoutRetryOrCredentialLeak(
        string $method,
        string $operation,
        string $data,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::response($operation, $data)));
        $whm->server()->{$method}();
        $retained = $whm->responseMeta();
        $http->addException(new class ('whm root:fixture-token') extends RuntimeException implements
            ClientExceptionInterface
        {
        });

        try {
            $whm->server()->{$method}();
            self::fail('Expected a transport failure.');
        } catch (TransportException $exception) {
            self::assertNull($whm->responseMeta());
            self::assertNull($exception->metadata);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            self::assertSame($operation, $retained?->whm['command'] ?? null);
        }
        self::assertCount(2, $http->getRequests());
    }

    private static function response(string $operation, string $data): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"OK","result":1,"version":1},'
            . '"data":' . $data . '}';
    }

    /** @return array{Client, MockClient} */
    private function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'username' => 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
