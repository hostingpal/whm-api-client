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
        yield 'hostname' => ['getHostname', 'gethostname', '{"hostname":"hostname.example.test"}'];
        yield 'load average' => ['getLoadAverage', 'systemloadavg', '{"one":0,"five":0.18,"fifteen":0.19}'];
        yield 'disk usage' => ['getDiskUsage', 'getdiskusage', '{"partition":[{"device":"/dev/vda1",'
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
            'getHostname' => $group->getHostname(),
            'getLoadAverage' => $group->getLoadAverage(),
            default => $group->getDiskUsage(),
        };

        self::assertSame(match ($method) {
            'getHostname' => 'hostname.example.test',
            'getLoadAverage' => ['fifteen' => 0.19, 'five' => 0.18, 'one' => 0],
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

        $load = $client->whm()->server()->getLoadAverage();

        self::assertSame(['fifteen' => '1.23', 'five' => '1.19', 'one' => '1.20'], $load);
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

        $usage = $client->whm()->server()->getDiskUsage();

        self::assertSame(['partition' => [
            ['percentage' => 0],
            ['inodes_available' => null, 'inodes_ipercentage' => null, 'inodes_total' => null, 'inodes_used' => null],
        ]], $usage);
        self::assertSame(200, $client->whm()->responseMeta()?->httpStatus);
        self::assertSame(1, $client->whm()->responseMeta()?->whm['result'] ?? null);
    }

    #[DataProvider('invalidHostnames')]
    public function testHostnameRequiresAStringAndRetainsFailureMetadata(string $data): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response('gethostname', $data)));

        try {
            $client->whm()->server()->getHostname();
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
            self::assertSame($hostname, $client->whm()->server()->getHostname());
        }
    }

    public function testLoadAverageKeepsNumbersAndOmissionWithoutCoercionOrBounds(): void
    {
        self::assertSame(
            ['fifteen' => 125.5, 'five' => 0.18, 'one' => 0],
            $this->execute('getLoadAverage', '{"one":0,"five":0.18,"fifteen":125.5,"extension":"ignored"}'),
        );
        self::assertSame(['one' => -1], $this->execute('getLoadAverage', '{"one":-1}'));
        self::assertSame([], $this->execute('getLoadAverage', '{}'));
        self::assertSame(
            ['fifteen' => '1.230000000000000001', 'five' => '0.00', 'one' => '1.20'],
            $this->execute('getLoadAverage', '{"one":"1.20","five":"0.00","fifteen":"1.230000000000000001"}'),
        );
        self::assertSame(
            ['fifteen' => '00.25', 'five' => '+1', 'one' => '-0.25'],
            $this->execute('getLoadAverage', '{"one":"-0.25","five":"+1","fifteen":"00.25"}'),
        );
    }

    public function testDiskUsageMapsEveryReviewedFieldAndKeepsOmittedEmptyAndBlankValuesDistinct(): void
    {
        self::assertSame(['partition' => [[
            'available' => 377856,
            'device' => '/dev/vda1',
            'disk' => 'vda1',
            'filesystem' => '/',
            'inodes_available' => 20575847,
            'inodes_ipercentage' => 2,
            'inodes_total' => 20970944,
            'inodes_used' => 395097,
            'mount' => '/boot',
            'percentage' => 20,
            'total' => 495844,
            'used' => 92388,
        ]]], $this->execute('getDiskUsage', '{"partition":[{"available":377856,"device":"/dev/vda1",'
            . '"disk":"vda1","filesystem":"/","inodes_available":20575847,"inodes_ipercentage":2,'
            . '"inodes_total":20970944,"inodes_used":395097,"mount":"/boot","percentage":20,"total":495844,'
            . '"used":92388,"extension":{}}],"extension":true}'));
        self::assertSame([], $this->execute('getDiskUsage', '{}'));
        self::assertSame(['partition' => []], $this->execute('getDiskUsage', '{"partition":[]}'));
        self::assertSame(['partition' => [[]]], $this->execute('getDiskUsage', '{"partition":[{}]}'));
        self::assertSame(
            ['partition' => [['device' => '/dev/vda1'], ['device' => '/dev/vdb1']]],
            $this->execute('getDiskUsage', '{"partition":[{"device":"/dev/vda1"},{"device":"/dev/vdb1"}]}'),
        );
        $minima = ['available' => 1, 'device' => '', 'disk' => '', 'filesystem' => '', 'inodes_available' => 1,
            'inodes_ipercentage' => 0, 'inodes_total' => 1, 'inodes_used' => 1, 'mount' => '', 'percentage' => 1,
            'total' => 1, 'used' => 1];
        self::assertSame(['partition' => [$minima]], $this->execute(
            'getDiskUsage',
            json_encode(['partition' => [$minima]], JSON_THROW_ON_ERROR),
        ));
        $unbounded = ['available' => 10, 'inodes_available' => 20, 'inodes_ipercentage' => 101,
            'inodes_total' => 1, 'inodes_used' => 30, 'percentage' => 102, 'total' => 1, 'used' => 40];
        self::assertSame(['partition' => [$unbounded]], $this->execute(
            'getDiskUsage',
            json_encode(['partition' => [$unbounded]], JSON_THROW_ON_ERROR),
        ));
    }

    public function testEmptyJsonArraysAreEmptyObjects(): void
    {
        // The engine reads an empty JSON array as an empty object when no field is required.
        self::assertSame([], $this->execute('getLoadAverage', '[]'));
        self::assertSame([], $this->execute('getDiskUsage', '[]'));
        self::assertSame(['partition' => [[]]], $this->execute('getDiskUsage', '{"partition":[[]]}'));
    }

    /** @return iterable<string, array{string}> */
    public static function deferredDiskMinima(): iterable
    {
        foreach (
            ['available', 'inodes_available', 'inodes_ipercentage', 'inodes_total', 'inodes_used', 'percentage',
                'total', 'used'] as $field
        ) {
            yield $field . ' negative' => [$field, -1];
            if ($field !== 'inodes_ipercentage' && $field !== 'percentage') {
                yield $field . ' below minimum' => [$field, 0];
            }
        }
    }

    /**
     * The deleted DiskPartition DTO rejected these values. Minimum/range enforcement is deferred to bead
     * whm-api-client-8x0.18, so the generated engine validates only their integer type.
     */
    #[DataProvider('deferredDiskMinima')]
    public function testDiskMinimaAreDeferredToTypeValidation(string $field, int $value): void
    {
        self::assertSame(['partition' => [[$field => $value]]], $this->execute(
            'getDiskUsage',
            json_encode(['partition' => [[$field => $value]]], JSON_THROW_ON_ERROR),
        ));
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsRejectWithTheResponseSnapshot(string $method, string $data): void
    {
        [$client, $http] = $this->client();
        $operation = $method === 'getLoadAverage' ? 'systemloadavg' : 'getdiskusage';
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        try {
            $client->whm()->server()->{$method}();
            self::fail('Expected an invalid server payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            self::assertSame($operation, $exception->metadata?->whm['command'] ?? null);
            self::assertStringNotContainsString('sensitive-response-value', $exception->getMessage());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPayloads(): iterable
    {
        $partition = static fn (array $record): string => json_encode(['partition' => [$record]], JSON_THROW_ON_ERROR);
        foreach (['null', '"sensitive-response-value"', '1', 'true'] as $data) {
            yield 'load average payload ' . $data => ['getLoadAverage', $data];
            yield 'disk usage payload ' . $data => ['getDiskUsage', $data];
        }
        foreach (['one', 'five', 'fifteen'] as $field) {
            foreach (
                [null, 'invalid', true, [], (object) [], '', ' 1.20', "1.20\n", '1e2', 'NaN', 'INF', '.', '1.2x',
                    str_repeat('9', 400)] as $index => $value
            ) {
                yield 'load ' . $field . ' ' . $index => [
                    'getLoadAverage',
                    json_encode([$field => $value], JSON_THROW_ON_ERROR),
                ];
            }
        }
        yield 'null partition' => ['getDiskUsage', '{"partition":null}'];
        yield 'scalar partition' => ['getDiskUsage', '{"partition":"/dev/vda1"}'];
        yield 'object partition' => ['getDiskUsage', '{"partition":{}}'];
        yield 'keyed partition' => ['getDiskUsage', '{"partition":{"1":{}}}'];
        yield 'null record' => ['getDiskUsage', '{"partition":[null]}'];
        yield 'scalar record' => ['getDiskUsage', '{"partition":["/dev/vda1"]}'];
        yield 'invalid second record' => ['getDiskUsage', '{"partition":[{},{"available":"1"}]}'];
        foreach (['device', 'disk', 'filesystem', 'mount'] as $field) {
            foreach ([null, false, 1, (object) [], [1]] as $index => $value) {
                yield $field . ' invalid string ' . $index => ['getDiskUsage', $partition([$field => $value])];
            }
            yield $field . ' float' => ['getDiskUsage', '{"partition":[{"' . $field . '":1.0}]}'];
        }
        foreach (
            ['available', 'inodes_available', 'inodes_ipercentage', 'inodes_total', 'inodes_used', 'percentage',
                'total', 'used'] as $field
        ) {
            foreach ([false, 'sensitive-response-value', '1', (object) [], [1]] as $index => $value) {
                yield $field . ' invalid integer ' . $index => ['getDiskUsage', $partition([$field => $value])];
            }
            yield $field . ' float' => ['getDiskUsage', '{"partition":[{"' . $field . '":1.0}]}'];
            if (!str_starts_with($field, 'inodes_')) {
                yield $field . ' null' => ['getDiskUsage', $partition([$field => null])];
            }
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
        $whm->server()->getHostname();
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
        foreach (['getHostname', 'getLoadAverage', 'getDiskUsage'] as $method) {
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
        yield 'bad load window' => ['getLoadAverage', 200, '{"metadata":{"result":1},"data":{"five":"0.18oops"}}',
            InvalidResponseException::class, 1];
        yield 'bad disk record' => ['getDiskUsage', 200, '{"metadata":{"result":1},"data":{"partition":[false]}}',
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

    /**
     * Execute one parameterless server operation against a successful envelope carrying the payload.
     *
     * @return array<string, mixed>
     */
    private function execute(string $method, string $data): array
    {
        [$client, $http] = $this->client();
        $operation = $method === 'getLoadAverage' ? 'systemloadavg' : 'getdiskusage';
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        return $method === 'getLoadAverage'
            ? $client->whm()->server()->getLoadAverage()
            : $client->whm()->server()->getDiskUsage();
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
