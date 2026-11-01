<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\ModSecurity;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use SensitiveParameter;
use TypeError;

/** Literal offline representation fixtures; server ID validity is not inferred. */
final class WhmModSecurityReportInputTest extends TestCase
{
    #[DataProvider('rowIds')]
    public function testReportRowIdsPreserveCallerBytesInOneFormPost(string|int $rowIds, bool $send, string $wire): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"report":[]}')));

        self::assertSame(['report' => []], $client->whm()->modSecurity()->reportRule(
            'a@example.test',
            'a +&=%',
            $rowIds,
            $send,
            'false positive',
        ));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/modsec_report_rule?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('email=a%40example.test&message=a%20%2B%26%3D%25&row_ids=' . $wire
            . '&send=' . ($send ? '1' : '0') . '&type=false%20positive', (string) $request->getBody());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame('modsec_report_rule', $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string|int, bool, string}> */
    public static function rowIds(): iterable
    {
        yield 'source multiple example' => ['794828, 794829, 794820', false, '794828%2C%20794829%2C%20794820'];
        yield 'integer compatibility' => [123, true, '123'];
        yield 'leading zeros' => ['00123', false, '00123'];
        yield 'repeated IDs' => ['123,123', true, '123%2C123'];
        yield 'untrimmed whitespace' => [" \t123, 456\n", false, '%20%09123%2C%20456%0A'];
        yield 'empty text' => ['', false, ''];
        yield 'string zero' => ['0', true, '0'];
        yield 'native zero' => [0, false, '0'];
        yield 'encoding sentinels' => ['123+,%&456', false, '123%2B%2C%25%26456'];
    }

    #[DataProvider('invalidRowIds')]
    public function testStrictInvalidRowIdsFailBeforeDispatchOrMetadataReset(string $method, mixed $rowIds): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"report":[]}')));
        $group = $client->whm()->modSecurity();
        $group->reportRule('a@example.test', '', 123, false, 'freeform');
        $previous = $client->whm()->responseMeta();
        self::assertNotNull($previous);
        $caught = null;
        try {
            $group->{$method}('a@example.test', '', $rowIds, false, 'freeform');
        } catch (TypeError $error) {
            $caught = $error;
        }

        self::assertInstanceOf(TypeError::class, $caught);
        self::assertSame($previous, $client->whm()->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidRowIds(): iterable
    {
        yield 'list' => ['reportRule', [123, 456]];
        yield 'true' => ['reportRule', true];
        yield 'false' => ['reportRule', false];
        yield 'fractional float' => ['reportRule', 1.5];
        yield 'integral float' => ['reportRule', 123.0];
        yield 'null' => ['reportRule', null];
    }

    public function testMultiIdApiFailureRetainsSnapshotAndDoesNotRetry(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('false', 0)));
        $caught = null;
        try {
            $client->whm()->modSecurity()->reportRule('a@example.test', '', '123, 456', true, 'freeform');
        } catch (ApiErrorException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(ApiErrorException::class, $caught);
        self::assertSame($client->whm()->responseMeta(), $caught->metadata);
        self::assertNotNull($caught->metadata);
        self::assertSame(200, $caught->metadata->httpStatus);
        self::assertSame(0, $caught->metadata->whmResult());
        self::assertSame(['Synthetic'], $caught->errors());
        self::assertCount(1, $http->getRequests());
    }

    public function testMultiIdMalformedSuccessRetainsConfirmedOutcomeAndDoesNotRetry(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"report":false}')));
        $caught = null;
        try {
            $client->whm()->modSecurity()->reportRule('a@example.test', '', '123, 456', false, 'freeform');
        } catch (PayloadMismatchException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(PayloadMismatchException::class, $caught);
        self::assertTrue($caught->outcomeConfirmed);
        self::assertSame(['report' => false], $caught->payload);
        self::assertSame($client->whm()->responseMeta(), $caught->metadata);
        self::assertSame(1, $caught->metadata?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    public function testReportSignaturePreservesRequiredNamedArgumentsAndSensitivity(): void
    {
        $method = new ReflectionMethod(ModSecurity::class, 'reportRule');
        self::assertSame(5, $method->getNumberOfRequiredParameters());
        self::assertSame(
            ['email', 'message', 'rowIds', 'send', 'type'],
            array_map(
                static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters(),
            ),
        );
        $rowIdsType = $method->getParameters()[2]->getType();
        self::assertInstanceOf(ReflectionUnionType::class, $rowIdsType);
        $types = [];
        foreach ($rowIdsType->getTypes() as $type) {
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            $types[] = $type->getName();
        }
        sort($types);
        self::assertSame(['int', 'string'], $types);
        self::assertFalse($rowIdsType->allowsNull());
        self::assertSame('array', (string) $method->getReturnType());
        foreach ($method->getParameters() as $parameter) {
            self::assertFalse($parameter->isOptional());
            self::assertCount(
                in_array($parameter->getName(), ['email', 'message', 'type'], true) ? 1 : 0,
                $parameter->getAttributes(SensitiveParameter::class),
            );
        }
    }

    private static function envelope(string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"modsec_report_rule","result":' . $result
            . ',"reason":"Synthetic"},"data":' . $payload . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory)), $http];
    }
}
