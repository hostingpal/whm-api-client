<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\Whm\Server;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameterValue;

/** Offline literal fixtures; this test never contacts or reboots a server. */
final class WhmRebootStatusTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function successes(): iterable
    {
        // Only the no-reboot shape was observed by external review5727236239.
        yield 'reviewed no reboot' => ['{"details":[],"needs_reboot":0}', ['details' => [], 'needs_reboot' => 0]];
        // Populated reasons are synthetic handler/source cases, not live observations.
        yield 'all source reasons' => ['{"details":{"kernel":{"boot_version":"next","running_version":"now"},'
            . '"needs_cloudlinux":1,"quota":1,"securetmp_size_update":1,'
            . '"updates":{"package":"v2","future":[false,null,{"n":2}]}},"needs_reboot":1}',
            ['details' => ['kernel' => ['boot_version' => 'next', 'running_version' => 'now'],
                'needs_cloudlinux' => 1, 'quota' => 1, 'securetmp_size_update' => 1,
                'updates' => ['package' => 'v2', 'future' => [false, null, ['n' => 2]]]], 'needs_reboot' => 1]];
        yield 'omitted optional fields' => ['{}', []];
        yield 'empty root array compatibility' => ['[]', []];
        yield 'details only' => ['{"details":{}}', ['details' => []]];
        yield 'empty nested object compatibility' => ['{"details":{"kernel":[],"updates":[]}}',
            ['details' => ['kernel' => [], 'updates' => []]]];
        yield 'optional kernel strings' => ['{"details":{"kernel":{"running_version":"now"}}}',
            ['details' => ['kernel' => ['running_version' => 'now']]]];
        yield 'quota ordinary int' => ['{"details":{"quota":2}}', ['details' => ['quota' => 2]]];
        yield 'cloudlinux alone' => ['{"details":{"needs_cloudlinux":1}}', ['details' => ['needs_cloudlinux' => 1]]];
        yield 'securetmp alone' => ['{"details":{"securetmp_size_update":1}}',
            ['details' => ['securetmp_size_update' => 1]]];
        yield 'both ordinary ints' => ['{"details":{"needs_cloudlinux":2,"securetmp_size_update":0}}',
            ['details' => ['needs_cloudlinux' => 2, 'securetmp_size_update' => 0]]];
    }

    /** @param array<string, mixed> $want */
    #[DataProvider('successes')]
    public function testPublicMethodReturnsOrdinaryDataWithOneEmptyFormPost(string $data, array $want): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('"data":' . $data)));
        self::assertSame($want, $client->whm()->server()->systemNeedsReboot());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/system_needs_reboot?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        foreach (['null', '"0"', '"1"', 'true', 'false', '2', '-1', '0.0', '{}', '[]'] as $wire) {
            yield 'flag ' . $wire => ['"needs_reboot":' . $wire];
        }
        foreach (['null', '1', 'false', '"text"', '[1]'] as $wire) {
            yield 'details ' . $wire => ['"details":' . $wire];
        }
        yield 'null kernel' => ['"details":{"kernel":null}'];
        yield 'invalid boot version' => ['"details":{"kernel":{"boot_version":1}}'];
        yield 'invalid running version' => ['"details":{"kernel":{"running_version":false}}'];
        yield 'invalid quota' => ['"details":{"quota":"1"}'];
        yield 'null updates' => ['"details":{"updates":null}'];
        yield 'scalar updates' => ['"details":{"updates":true}'];
        foreach (['needs_cloudlinux', 'securetmp_size_update'] as $reason) {
            foreach (['null', '"1"', 'true', 'false', '1.0'] as $wire) {
                yield $reason . ' ' . $wire => ['"details":{"' . $reason . '":' . $wire . '}'];
            }
        }
    }

    #[DataProvider('malformed')]
    public function testMalformedDataRetainsOutcomeAndKeepsEnvelopeMaterialPrivate(string $fields): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('"data":{' . $fields . '},"private":"discard-me"')));
        try {
            $client->whm()->server()->systemNeedsReboot();
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            $payload = json_encode($exception->payload, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('discard-me', $payload);
            self::assertStringNotContainsString('metadata', $payload);
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testGenericCallKeepsItsExistingDataOnlyResult(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('"needs_reboot":1,"data":{"legacy":0}')));
        self::assertSame(['legacy' => 0], $client->whm()->call('system_needs_reboot')->data);
    }


    /** @return iterable<string, array{int, string, class-string<ApiException>, bool}> */
    public static function failures(): iterable
    {
        yield 'HTTP before API and payload' => [503,
            '{"metadata":{"result":0},"data":{"needs_reboot":null}}', HttpException::class, true];
        yield 'API before payload' => [200,
            '{"metadata":{"result":0},"data":{"needs_reboot":null}}', ApiErrorException::class, true];
        yield 'API despite valid payload' => [200,
            '{"metadata":{"result":0,"reason":"synthetic failed sub-check"},"data":{"needs_reboot":0}}',
            ApiErrorException::class, true];
        yield 'invalid outcome' => [200,
            '{"metadata":{"result":"1"},"data":{"needs_reboot":0}}', InvalidResponseException::class, true];
        yield 'missing outcome' => [200,
            '{"metadata":{},"data":{"needs_reboot":0}}', InvalidResponseException::class, true];
        yield 'no metadata' => [200, '{"data":{"needs_reboot":0}}', InvalidResponseException::class, false];
        yield 'malformed JSON' => [200, '{broken', InvalidResponseException::class, false];
        yield 'payload after accepted outcome' => [200,
            '{"metadata":{"result":1},"data":{"needs_reboot":null}}', PayloadMismatchException::class, true];
    }

    /** @param class-string<ApiException> $exception */
    #[DataProvider('failures')]
    public function testPublicFailurePrecedenceMetadataResetAndSnapshotPreservation(
        int $status,
        string $body,
        string $exception,
        bool $hasMetadata,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('"data":{"needs_reboot":0}')));
        $client->whm()->server()->systemNeedsReboot();
        $old = $client->whm()->responseMeta();
        $raw = $old?->whm;
        $http->addResponse(new Response($status, [], $body));
        try {
            $client->whm()->server()->systemNeedsReboot();
            self::fail('Expected request failure.');
        } catch (ApiException $error) {
            self::assertInstanceOf($exception, $error);
            self::assertSame($hasMetadata, $client->whm()->responseMeta() !== null);
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertNotSame($old, $error->metadata);
            if ($error instanceof PayloadMismatchException) {
                self::assertTrue($error->outcomeConfirmed);
            }
        }
        self::assertSame($raw, $old?->whm);
        self::assertSame(1, $old?->whmResult());
        self::assertCount(2, $http->getRequests());
    }

    public function testTransportFailureClearsEarlierMetadataWithoutRetry(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('"data":{"needs_reboot":0}')));
        $client->whm()->server()->systemNeedsReboot();
        $old = $client->whm()->responseMeta();
        $http->addException(new class extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new RuntimeException('No request inspection needed.');
            }
        });
        try {
            $client->whm()->server()->systemNeedsReboot();
            self::fail('Expected transport failure.');
        } catch (TransportException $error) {
            self::assertNull($client->whm()->responseMeta());
            self::assertNull($error->metadata);
            self::assertNull($error->getPrevious());
        }
        self::assertSame(1, $old?->whmResult());
        self::assertCount(2, $http->getRequests());
    }

    public function testRawResponseFrameIsSensitiveAndEnvelopeMaterialIsAbsentFromMismatchTrace(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], '{"metadata":{"result":0},'
                . '"data":{"needs_reboot":0},"private":"raw-secret-marker"}'));
            try {
                $client->whm()->server()->systemNeedsReboot();
                self::fail('Expected API failure.');
            } catch (ApiErrorException $error) {
                $seen = false;
                foreach ($error->getTrace() as $frame) {
                    if (($frame['class'] ?? '') === 'HostingPal\\WhmApiClient\\Response\\EnvelopeReader') {
                        self::assertInstanceOf(SensitiveParameterValue::class, ($frame['args'] ?? [])[0] ?? null);
                        $seen = true;
                    }
                }
                self::assertTrue($seen);
                self::assertStringNotContainsString('raw-secret-marker', $error->getTraceAsString());
            }
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},'
                . '"data":{"needs_reboot":null},"private":"raw-secret-marker"}'));
            try {
                $client->whm()->server()->systemNeedsReboot();
                self::fail('Expected payload failure.');
            } catch (PayloadMismatchException $error) {
                self::assertSame(['needs_reboot' => null], $error->payload);
                self::assertStringNotContainsString('raw-secret-marker', $error->getTraceAsString());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testPublicMethodRemainsFlagAndHasNoParameters(): void
    {
        $method = new ReflectionMethod(Server::class, 'systemNeedsReboot');
        self::assertSame([], $method->getParameters());
        self::assertSame('array', (string) $method->getReturnType());
        self::assertSame(OutcomePolicy::Flag, OperationSpec::outcome(ServerSpec::SYSTEM_NEEDS_REBOOT));
    }


    public function testEveryGenericEntryPointRetainsDataOnlyExtraction(): void
    {
        foreach (['form', 'json', 'multipart'] as $format) {
            foreach (['"needs_reboot":1', '"needs_reboot":1,"data":{"legacy":0}'] as $fields) {
                [$client, $http] = self::client();
                $http->addResponse(new Response(200, [], self::envelope($fields)));
                $whm = $client->whm();
                $result = match ($format) {
                    'form' => $whm->call('system_needs_reboot'),
                    'json' => $whm->callJson('system_needs_reboot', '{}'),
                    'multipart' => $whm->callMultipart('system_needs_reboot', []),
                };
                self::assertSame(str_contains($fields, 'legacy') ? ['legacy' => 0] : null, $result->data);
                self::assertSame(str_contains($fields, 'legacy'), $result->hasData);
                self::assertSame($result->metadata, $whm->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function missingOrInvalidData(): iterable
    {
        yield 'metadata only' => [''];
        yield 'root siblings are not a fallback' => ['"needs_reboot":0,"details":[]'];
        foreach (['null', 'true', '0', '"text"', '[1]'] as $wire) {
            yield 'data ' . $wire => ['"data":' . $wire];
        }
    }

    #[DataProvider('missingOrInvalidData')]
    public function testRequiresAnOrdinaryNonnullableDataObject(string $fields): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($fields)));
        $this->expectException(PayloadMismatchException::class);
        $client->whm()->server()->systemNeedsReboot();
    }

    private static function envelope(string $fields): string
    {
        return '{"metadata":{"command":"system_needs_reboot","result":1,"reason":"fixture",'
            . '"version":1,"private":"metadata-secret"}' . ($fields === '' ? '' : ',' . $fields) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $http = new MockClient();
        $factory = new Psr17Factory();
        return [new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory)), $http];
    }
}
