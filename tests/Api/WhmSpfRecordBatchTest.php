<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Mail;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;
use TypeError;

final class WhmSpfRecordBatchTest extends TestCase
{
    /** @return iterable<string, array{non-empty-list<array{domain: string, record: string}>, string}> */
    public static function requests(): iterable
    {
        yield 'singleton stays suffixed' => [
        [['domain' => 'a.test', 'record' => 'v=spf1 -all']],
            'domain-1=a.test&record-1=v%3Dspf1%20-all'];
        yield 'paired rows preserve duplicates and declared field order' => [[
            ['record' => 'v=spf1 +all', 'domain' => 'same.test'],
            ['domain' => 'same.test', 'record' => 'v=spf1 -all'],
        ], 'domain-1=same.test&record-1=v%3Dspf1%20%2Ball&domain-2=same.test&record-2=v%3Dspf1%20-all'];
        yield 'bytes encoded once' => [
        [
            ['domain' => 'é+&%.test', 'record' => 'v=spf1 +ip4:192.0.2.1 &% ü'],
            ['domain' => '%2B.test', 'record' => 'v%3Dspf1%20%2Ball'],
        ], 'domain-1=%C3%A9%2B%26%25.test&record-1=v%3Dspf1%20%2Bip4%3A192.0.2.1%20%26%25%20%C3%BC'
            . '&domain-2=%252B.test&record-2=v%253Dspf1%2520%252Ball'];
        yield 'empty strings are supplied' => [[['domain' => '', 'record' => '']], 'domain-1=&record-1='];
    }

    /** @param non-empty-list<array{domain: string, record: string}> $records */
    #[DataProvider('requests')]
    public function testLiteralPairedPost(array $records, string $body): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::success('{"payload":[]}')));
        self::assertSame(['payload' => []], $whm->mail()->installSpfRecordBatch($records));
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/install_spf_records?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame($body, (string) $request->getBody());
    }

    public function testScalarSignatureAndWireRemainCompatible(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::success('{"payload":[]}')));
        self::assertSame(['payload' => []], $whm->mail()->installSpfRecords('a.test', 'v=spf1 +all'));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('api.version=1', $request->getUri()->getQuery());
        self::assertSame('domain=a.test&record=v%3Dspf1%20%2Ball', (string) $request->getBody());
        $method = new ReflectionMethod(Mail::class, 'installSpfRecords');
        self::assertSame(
            ['domain', 'record'],
            array_map(static fn ($p): string => $p->getName(), $method->getParameters()),
        );
        foreach ($method->getParameters() as $parameter) {
            self::assertSame('string', (string) $parameter->getType());
            self::assertFalse($parameter->isOptional());
        }
        self::assertSame('array', (string) $method->getReturnType());
        self::assertCount(1, $http->getRequests());
    }

    public function testCompanionHasClosedRecordPhpdocAndSensitiveArgument(): void
    {
        $method = new ReflectionMethod(Mail::class, 'installSpfRecordBatch');
        self::assertCount(1, $method->getParameters());
        $parameter = $method->getParameters()[0];
        self::assertSame('records', $parameter->getName());
        self::assertSame('array', (string) $parameter->getType());
        self::assertFalse($parameter->isOptional());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        self::assertSame('array', (string) $method->getReturnType());
        self::assertStringContainsString(
            '@param non-empty-list<SpfRecordInput> $records',
            $method->getDocComment() ?: '',
        );
        self::assertStringContainsString('@return InstallSpfRecordsResult', $method->getDocComment() ?: '');
        $doc = (new ReflectionClass(Mail::class))->getDocComment() ?: '';
        self::assertStringContainsString('@phpstan-type SpfRecordInput array{', $doc);
        self::assertStringContainsString('domain: string,', $doc);
        self::assertStringContainsString('record: string,', $doc);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidRecords(): iterable
    {
        yield 'empty list' => [[]];
        yield 'associative' => [['row' => ['domain' => 'a', 'record' => 'b']]];
        yield 'sparse' => [[0 => ['domain' => 'a', 'record' => 'b'], 2 => ['domain' => 'c', 'record' => 'd']]];
        yield 'one based' => [[1 => ['domain' => 'a', 'record' => 'b']]];
        yield 'scalar row' => [['a']];
        yield 'null row' => [[null]];
        yield 'object row' => [[(object) ['domain' => 'a', 'record' => 'b']]];
        yield 'empty row' => [[[]]];
        yield 'missing domain' => [[['record' => 'b']]];
        yield 'missing record' => [[['domain' => 'a']]];
        yield 'unknown key' => [[['domain' => 'a', 'record' => 'b', 'extra' => 'c']]];
        $invalid = [null, 0, false, 1.5, ['private-nested-secret'], (object) ['private' => 'private-object-secret']];
        foreach ($invalid as $i => $bad) {
            yield 'domain type ' . $i => [[['domain' => $bad, 'record' => 'b']]];
            yield 'late record type ' . $i => [[
                ['domain' => 'a', 'record' => 'b'], ['domain' => 'c', 'record' => $bad],
            ]];
        }
    }

    /** @param array<array-key, mixed> $records */
    #[DataProvider('invalidRecords')]
    public function testInvalidRecordsClearMetadataBeforeAnyDispatch(array $records): void
    {
        [$whm, $http] = self::client();
        self::prime($whm, $http);
        try {
            (new ReflectionMethod(Mail::class, 'installSpfRecordBatch'))->invoke($whm->mail(), $records);
            self::fail('Invalid whole batch must reject.');
        } catch (InvalidArgumentException) {
            self::assertNull($whm->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testNativeTypeFailuresKeepPreEntryMetadata(): void
    {
        [$whm, $http] = self::client();
        self::prime($whm, $http);
        $before = $whm->responseMeta();
        foreach ([null, 'private-native-secret', 0, false, (object) []] as $invalid) {
            try {
                (new ReflectionMethod(Mail::class, 'installSpfRecordBatch'))->invoke($whm->mail(), $invalid);
                self::fail('Native non-array argument must reject.');
            } catch (TypeError) {
                self::assertSame($before, $whm->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    public function testNestedInvalidValuesStayPrivateInLibraryTraces(): void
    {
        [$whm, $http] = self::client();
        self::prime($whm, $http);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            (new ReflectionMethod(Mail::class, 'installSpfRecordBatch'))->invoke($whm->mail(), [
                ['domain' => 'private-domain-secret', 'record' => 'private-record-secret'],
                ['domain' => 'private-second-secret', 'record' => ['private-nested-secret']],
            ]);
            self::fail('Nested values must reject.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('private-', $error->getMessage());
            self::assertStringNotContainsString('private-', $error->getTraceAsString());
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    self::assertStringNotContainsString('private-', var_export($frame['args'] ?? [], true));
                }
            }
            self::assertNull($whm->responseMeta());
            self::assertCount(1, $http->getRequests());
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function payloads(): iterable
    {
        yield 'omitted payload' => ['{}', []];
        yield 'empty payload' => ['{"payload":[]}', ['payload' => []]];
        yield 'mixed status and optional fields' => [
            '{"payload":[{"domain":"a.test","msg":"installed","status":1},{"status":0,"msg":"failed"},{}]}',
            ['payload' => [
                ['domain' => 'a.test', 'msg' => 'installed', 'status' => 1], ['msg' => 'failed', 'status' => 0], [],
            ]],
        ];
        yield 'unknown fields follow canonical projection' => ['{"extra":"kept","payload":[{"extra":0}]}',
            ['payload' => [[]]]];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('payloads')]
    public function testCanonicalPayloadIsReturnedWithoutUnwrapping(string $payload, array $expected): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::success($payload)));
        self::assertSame($expected, $whm->mail()->installSpfRecordBatch([['domain' => 'a', 'record' => 'b']]));
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    public function testApiFailureRetainsMetadataWithoutRecoveringRowsOrRetrying(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"failed"},'
            . '"data":{"payload":[{"domain":"a","status":1},{"domain":"b","status":0}]}}'));
        try {
            $whm->mail()->installSpfRecordBatch([['domain' => 'a', 'record' => 'b']]);
            self::fail('Failed envelope must throw.');
        } catch (ApiErrorException $error) {
            self::assertSame($error->metadata, $whm->responseMeta());
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(['result' => 0, 'reason' => 'failed'], $error->metadata->whm);
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSuccesses(): iterable
    {
        foreach (
            [
            'missing data' => null, 'null data' => 'null', 'scalar data' => '1', 'null payload' => '{"payload":null}',
            'object payload' => '{"payload":{"domain":"a"}}', 'scalar row' => '{"payload":[1]}',
            'string status' => '{"payload":[{"status":"0"}]}', 'nonflag status' => '{"payload":[{"status":2}]}',
            'boolean status' => '{"payload":[{"status":false}]}', 'null msg' => '{"payload":[{"msg":null}]}',
            'wrong domain' => '{"payload":[{"domain":1}]}',
            ] as $label => $payload
        ) {
            yield $label => [self::success($payload)];
        }
    }

    #[DataProvider('malformedSuccesses')]
    public function testMalformedSuccessKeepsConfirmedOutcomeAndMetadata(string $response): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], $response));
        try {
            $whm->mail()->installSpfRecordBatch([['domain' => 'a', 'record' => 'b']]);
            self::fail('Malformed success must mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($error->metadata, $whm->responseMeta());
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    private static function success(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK"}' . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    private static function prime(WhmClient $whm, MockClient $http): void
    {
        $http->addResponse(new Response(200, [], self::success('{"sni":1}')));
        $whm->mail()->isSniSupported();
        self::assertNotNull($whm->responseMeta());
    }

    /** @return array{WhmClient, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'synthetic-token'], new HttpTransport($http, $factory, $factory));
        return [$client->whm(), $http];
    }
}
