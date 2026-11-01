<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Config\ClientConfigFactory;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\EnvelopeReader;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use HostingPal\WhmApiClient\Response\WhmPayloadProjection;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class WhmPayloadProjectionTest extends TestCase
{
    public function testSelectsOnlyPresentFieldsInDeclaredOrderWithoutConvertingValues(): void
    {
        self::assertTrue(class_exists(WhmPayloadProjection::class), 'Missing WHM projection value object.');
        $projection = WhmPayloadProjection::from(['details', 'needs_reboot']);
        $document = (object) ['metadata' => (object) ['result' => 1], 'unselected' => 'private',
            'needs_reboot' => 0, 'details' => (object) ['opaque' => [null, true]]];
        $selected = $projection->select($document);
        self::assertSame(['details', 'needs_reboot'], array_keys(get_object_vars($selected)));
        self::assertSame($document->details, $selected->details);
        self::assertSame(0, $selected->needs_reboot);
        self::assertEquals(new stdClass(), $projection->select(new stdClass()));
        self::assertSame(['details' => null], get_object_vars($projection->select((object) ['details' => null])));
        self::assertSame(['details', 'needs_reboot'], $projection->fields);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedFields(): iterable
    {
        foreach (
            [null, [], false, 'details', ['x' => 'details'], [1 => 'details'], ['details', 'details'],
            [''], [1], ['a-b'], ['a.b'], ['é'], ['metadata'], ['data'], ["x\n"], ['0x']] as $i => $fields
        ) {
            yield (string) $i => [$fields];
        }
    }

    #[DataProvider('malformedFields')]
    public function testRejectsMalformedDescriptors(mixed $fields): void
    {
        self::assertTrue(class_exists(WhmPayloadProjection::class));
        $this->expectException(LogicException::class);
        WhmPayloadProjection::from($fields);
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return ['function' => 'fixture', 'method' => 'GET', 'outcome' => 'flag', 'params' => [],
            'response_fields' => ['details', 'needs_reboot'], 'returns' => ['type' => 'object', 'fields' => [
                'details' => ['type' => 'object'], 'needs_reboot' => ['type' => 'flag']]]];
    }

    public function testSpecDescriptorIsExplicitAndOrdinarySpecsRemainUnselected(): void
    {
        $spec = self::spec();
        SpecChecker::check($spec);
        self::assertSame(['details', 'needs_reboot'], OperationSpec::responseProjection($spec)?->fields);
        unset($spec['response_fields']);
        self::assertNull(OperationSpec::responseProjection($spec));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedSpecs(): iterable
    {
        foreach (self::malformedFields() as $name => [$fields]) {
            yield 'fields ' . $name => [array_replace(self::spec(), ['response_fields' => $fields])];
        }
        foreach (
            [
            ['module' => 'Fixture'], ['module' => null], ['returns' => null], ['optional' => true],
            ['optional' => null], ['returns' => ['type' => 'object', 'nullable' => null,
                'fields' => ['details' => ['type' => 'object'], 'needs_reboot' => ['type' => 'flag']]]],
            ['returns' => ['type' => 'map', 'values' => ['type' => 'mixed']]],
            ['returns' => ['type' => 'object', 'nullable' => true, 'fields' => []]],
            ['returns' => ['type' => 'object', 'fields' => ['details' => ['type' => 'mixed']]]],
            ['returns' => ['type' => 'object', 'fields' => ['details' => ['type' => 'mixed'],
                'needs_reboot' => ['type' => 'flag']], 'values' => ['type' => 'mixed']]],
            ['returns' => ['type' => 'object', 'fields' => ['details' => ['type' => 'mixed'],
                'needs_reboot' => ['type' => 'flag']], 'required' => [null]]]] as $i => $change
        ) {
            yield 'incompatible ' . $i => [array_replace(self::spec(), $change)];
        }
    }

    /** @param array<string, mixed> $spec */
    #[DataProvider('malformedSpecs')]
    public function testMalformedSpecsFailBeforeDispatch(array $spec): void
    {
        $sent = false;
        try {
            OperationRunner::run($spec, [], static function () use (&$sent): never {
                $sent = true;
                throw new LogicException('Dispatch must not run.');
            });
            self::fail('Expected invalid projection spec.');
        } catch (LogicException) {
            self::assertFalse($sent, 'Malformed spec reached dispatch.');
        }
    }

    public function testProjectionRetainsMetadataAndDefaultDataPath(): void
    {
        self::assertTrue(class_exists(WhmPayloadProjection::class));
        $response = new Response(200, [], '{"metadata":{"result":1,"reason":"fixture","future":[0]},'
            . '"details":{},"needs_reboot":0,"data":{"legacy":1},"private":"discard"}');
        $ordinary = EnvelopeReader::read($response, ResponseRoute::Whm);
        $projected = EnvelopeReader::read(
            $response,
            ResponseRoute::Whm,
            OutcomePolicy::Flag,
            WhmPayloadProjection::from(['details', 'needs_reboot']),
        );
        self::assertEquals((object) ['legacy' => 1], $ordinary->data);
        self::assertEquals((object) ['details' => new stdClass(), 'needs_reboot' => 0], $projected->data);
        self::assertEquals($ordinary->metadata, $projected->metadata);
        self::assertTrue($projected->hasData);
    }

    /** @return iterable<string, array{int, string, class-string<\Throwable>}> */
    public static function failures(): iterable
    {
        yield 'HTTP wins' => [503, '{"result":0}', HttpException::class];
        yield 'API wins' => [200, '{"result":0}', ApiErrorException::class];
        yield 'missing result' => [200, '{}', InvalidResponseException::class];
        yield 'malformed result' => [200, '{"result":"1"}', InvalidResponseException::class];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('failures')]
    public function testProjectionNeverBypassesOutcomeChecks(int $status, string $metadata, string $exception): void
    {
        self::assertTrue(class_exists(WhmPayloadProjection::class));
        $this->expectException($exception);
        EnvelopeReader::read(
            new Response($status, [], '{"metadata":' . $metadata
            . ',"details":null,"needs_reboot":0}'),
            ResponseRoute::Whm,
            OutcomePolicy::Flag,
            WhmPayloadProjection::from(['details', 'needs_reboot']),
        );
    }

    public function testProjectionRejectsUapiRoutes(): void
    {
        self::assertTrue(class_exists(WhmPayloadProjection::class));
        $this->expectException(LogicException::class);
        EnvelopeReader::read(
            new Response(200, [], '{"result":{"status":1,"data":{}}}'),
            ResponseRoute::DirectUapi,
            OutcomePolicy::Flag,
            WhmPayloadProjection::from(['details']),
        );
    }

    public function testUncheckedProjectionRetainsFailureMetadataAndUnconfirmedMismatch(): void
    {
        $spec = self::spec();
        $spec['outcome'] = 'unchecked';
        $response = new Response(200, [], '{"metadata":{"result":0,"reason":"unchecked fixture"},'
            . '"details":{},"needs_reboot":0,"unselected":"private"}');
        $accepted = EnvelopeReader::read(
            $response,
            ResponseRoute::Whm,
            OutcomePolicy::Unchecked,
            WhmPayloadProjection::from(['details', 'needs_reboot']),
        );
        self::assertSame(0, $accepted->metadata->whmResult());
        self::assertEquals((object) ['details' => new stdClass(), 'needs_reboot' => 0], $accepted->data);
        $invalid = new Response(200, [], '{"metadata":{"result":0},"needs_reboot":null}');
        try {
            OperationRunner::run($spec, [], static fn () => EnvelopeReader::read(
                $invalid,
                ResponseRoute::Whm,
                OutcomePolicy::Unchecked,
                OperationSpec::responseProjection($spec),
            ));
            self::fail('Expected selected payload validation.');
        } catch (PayloadMismatchException $error) {
            self::assertFalse($error->outcomeConfirmed);
            self::assertSame(['needs_reboot' => null], $error->payload);
            self::assertSame(0, $error->metadata?->whmResult());
        }
    }


    public function testDefaultDirectAndBridgedUapiExtractionIsUnchanged(): void
    {
        $direct = EnvelopeReader::read(new Response(200, [], '{"result":{"status":1,"data":{"value":0}},'
            . '"details":{"private":1},"needs_reboot":1}'), ResponseRoute::DirectUapi);
        $bridged = EnvelopeReader::read(
            new Response(200, [], '{"metadata":{"result":1},'
            . '"data":{"uapi":{"status":1,"data":{"value":0}}},"needs_reboot":1}'),
            ResponseRoute::BridgedUapi,
        );
        self::assertEquals((object) ['value' => 0], $direct->data);
        self::assertEquals($direct->data, $bridged->data);
        self::assertSame(1, $direct->metadata->uapiStatus());
        self::assertSame(1, $bridged->metadata->uapiStatus());
        self::assertSame(1, $bridged->metadata->whmResult());
    }

    public function testNonWhmExecutorProjectionFailsBeforeTransport(): void
    {
        $http = new MockClient();
        $factory = new Psr17Factory();
        $client = new class (new HttpTransport($http, $factory, $factory)) extends AbstractApiClient {
            public function __construct(HttpTransport $transport)
            {
                parent::__construct(ClientConfigFactory::fromArray([
                    'host' => 'api.example.test', 'authType' => 'whm', 'token' => 'fixture-token',
                ]), $transport);
            }

            public function projected(ResponseRoute $route): ApiResponse
            {
                return $this->executeGet('/fixture', [], $route, projection: WhmPayloadProjection::from(['details']));
            }
        };
        foreach ([ResponseRoute::DirectUapi, ResponseRoute::BridgedUapi] as $route) {
            try {
                $client->projected($route);
                self::fail('Non-WHM projection accepted.');
            } catch (LogicException) {
                self::assertNull($client->responseMeta());
                self::assertCount(0, $http->getRequests());
            }
        }
    }
}
