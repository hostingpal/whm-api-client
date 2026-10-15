<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @phpstan-import-type Parameters from AbstractApiClient
 */
final class OperationRunnerTest extends TestCase
{
    private const SPEC = [
        'function' => 'primary_domain',
        'module' => 'DomainInfo',
        'method' => 'GET',
        'outcome' => 'flag',
        'params' => ['hideTemporaryDomains' => ['name' => 'hide_temporary_domains', 'encoding' => 'bool']],
        'returns' => [
            'type' => 'object',
            'fields' => ['primary_domain' => ['type' => 'string', 'nullable' => true]],
            'required' => ['primary_domain'],
        ],
    ];

    public function testRunEncodesDispatchesAndValidates(): void
    {
        $seen = [];
        $result = OperationRunner::run(
            self::SPEC,
            ['hideTemporaryDomains' => true],
            /**
             * @param array<mixed> $spec
             * @param Parameters $parameters
             */
            static function (array $spec, array $parameters) use (&$seen): ApiResponse {
                $seen = [$spec['function'], $parameters];

                return self::response((object) ['primary_domain' => 'example.test', 'extra' => 1]);
            },
        );

        self::assertSame(['primary_domain', ['hide_temporary_domains' => 1]], $seen);
        self::assertSame(['primary_domain' => 'example.test'], $result);
    }

    public function testUnwrapReturnsTheSelectedField(): void
    {
        $spec = self::SPEC + ['unwrap' => 'primary_domain'];

        self::assertSame(
            'example.test',
            OperationRunner::result($spec, self::response((object) ['primary_domain' => 'example.test'])),
        );
        self::assertNull(OperationRunner::result($spec, self::response((object) ['primary_domain' => null])));
    }

    public function testUnwrapRequiresTheSelectedField(): void
    {
        $spec = ['returns' => ['type' => 'object', 'fields' => ['a' => ['type' => 'string']]], 'unwrap' => 'a']
            + self::SPEC;
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            OperationRunner::result($spec, new ApiResponse(new stdClass(), $metadata));
            self::fail('Expected an invalid payload.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertSame('Invalid primary_domain payload.', $exception->getMessage());
            self::assertSame([], $exception->payload);
        }
    }

    public function testPayloadMismatchAfterAConfirmedOutcomeCarriesAnUnvalidatedCopy(): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);
        $data = (object) ['primary_domain' => 1, 'nested' => (object) ['list' => [1, (object) ['a' => 'b']]]];
        $expected = ['primary_domain' => 1, 'nested' => ['list' => [1, ['a' => 'b']]]];

        try {
            OperationRunner::result(self::SPEC, new ApiResponse($data, $metadata));
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertInstanceOf(InvalidResponseException::class, $exception);
            self::assertSame('Invalid primary_domain payload.', $exception->getMessage());
            self::assertSame($metadata, $exception->metadata);
            self::assertTrue($exception->outcomeConfirmed);

            $data->nested->list[1]->a = 'changed';
            self::assertSame($expected, $exception->payload, 'The payload is a detached copy.');
        }
    }

    public function testPayloadMismatchKeepsThePayloadOutOfDebugOutput(): void
    {
        $exception = new PayloadMismatchException(
            'Invalid createacct payload.',
            new ResponseMetadata(200, ['result' => 1]),
            true,
            ['token' => 'fixture-secret'],
        );

        self::assertSame(['token' => 'fixture-secret'], $exception->payload);
        self::assertStringNotContainsString('fixture-secret', print_r($exception, true));
        ob_start();
        var_dump($exception);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('fixture-secret', $dump);
        self::assertStringContainsString('Invalid createacct payload.', $dump);
        self::assertStringContainsString('outcomeConfirmed', $dump);
    }

    public function testPayloadMismatchUnderAnUncheckedOutcomeIsNotConfirmed(): void
    {
        try {
            OperationRunner::result(
                ['outcome' => 'unchecked'] + self::SPEC,
                self::response((object) ['primary_domain' => 1]),
            );
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertFalse($exception->outcomeConfirmed);
            self::assertSame(['primary_domain' => 1], $exception->payload);
        }
    }

    public function testOmittedDataIsAPayloadMismatchWithoutAPayload(): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            OperationRunner::result(self::SPEC, new ApiResponse(null, $metadata, false));
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertTrue($exception->outcomeConfirmed);
            self::assertNull($exception->payload);
        }
    }

    public function testSpecDefectsFoundDuringValidationAreNotPayloadMismatches(): void
    {
        $spec = ['returns' => ['type' => 'object', 'fields' => ['a' => ['type' => 'bogus']]]] + self::SPEC;

        $this->expectException(LogicException::class);

        OperationRunner::result($spec, self::response((object) ['a' => 1]));
    }

    public function testVoidOperationsIgnoreThePayload(): void
    {
        $spec = ['returns' => null] + self::SPEC;

        self::assertNull(OperationRunner::result($spec, self::response((object) ['anything' => true])));
        self::assertNull(OperationRunner::result($spec, new ApiResponse(null, new ResponseMetadata(200), false)));
    }

    public function testOmittedPayloadIsInvalidEvenWhenNullable(): void
    {
        $nullable = ['returns' => ['type' => 'list', 'items' => ['type' => 'string'], 'nullable' => true]]
            + self::SPEC;
        self::assertNull(OperationRunner::result($nullable, new ApiResponse(null, new ResponseMetadata(200), true)));

        $this->expectException(InvalidResponseException::class);
        OperationRunner::result($nullable, new ApiResponse(null, new ResponseMetadata(200), false));
    }

    public function testOptionalPayloadReturnsNullForOmittedOrNullDataAndValidatesTheRest(): void
    {
        $spec = ['optional' => true] + self::SPEC;
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        self::assertNull(OperationRunner::result($spec, new ApiResponse(null, $metadata, false)));
        self::assertNull(OperationRunner::result($spec, new ApiResponse(null, $metadata, true)));
        self::assertSame(
            ['primary_domain' => 'example.test'],
            OperationRunner::result($spec, self::response((object) ['primary_domain' => 'example.test'])),
        );
        self::assertNull(OperationRunner::result(
            ['unwrap' => 'primary_domain'] + $spec,
            new ApiResponse(null, $metadata, false),
        ));

        foreach ([[], new stdClass(), (object) ['primary_domain' => 1], 'text'] as $data) {
            try {
                OperationRunner::result($spec, new ApiResponse($data, $metadata));
                self::fail('Expected an invalid payload.');
            } catch (InvalidResponseException $exception) {
                self::assertSame($metadata, $exception->metadata);
            }
        }
    }

    public function testOptionalFalseKeepsTheRequiredPayload(): void
    {
        $this->expectException(InvalidResponseException::class);

        OperationRunner::result(['optional' => false] + self::SPEC, new ApiResponse(null, new ResponseMetadata(200)));
    }

    public function testMissingReturnsIsAGeneratorDefect(): void
    {
        $this->expectException(LogicException::class);

        OperationRunner::result(array_diff_key(self::SPEC, ['returns' => true]), self::response(new stdClass()));
    }

    /** @param array<mixed> $spec */
    #[DataProvider('defectiveSpecs')]
    public function testDefectiveSpecsFailBeforeDispatch(array $spec): void
    {
        $calls = 0;

        try {
            OperationRunner::run(
                $spec,
                [],
                /**
                 * @param array<mixed> $spec
                 * @param Parameters $parameters
                 */
                static function (array $spec, array $parameters) use (&$calls): ApiResponse {
                    $calls++;

                    return self::response(new stdClass());
                },
            );
            self::fail('Expected a generator defect.');
        } catch (LogicException) {
            self::assertSame(0, $calls);
        }
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function defectiveSpecs(): iterable
    {
        yield 'missing returns' => [array_diff_key(self::SPEC, ['returns' => true])];
        yield 'scalar returns' => [['returns' => 'string'] + self::SPEC];
        yield 'non-string function' => [['function' => 5] + self::SPEC];
        yield 'non-string unwrap' => [['unwrap' => 5] + self::SPEC];
        yield 'unwrap with non-object returns' => [
            ['returns' => ['type' => 'list', 'items' => ['type' => 'string']], 'unwrap' => 'a'] + self::SPEC,
        ];
        yield 'unwrap with void returns' => [['returns' => null, 'unwrap' => 'a'] + self::SPEC];
        yield 'unwrap of undeclared field' => [['unwrap' => 'missing'] + self::SPEC];
        yield 'non-boolean optional' => [['optional' => 1] + self::SPEC];
        yield 'optional void returns' => [['returns' => null, 'optional' => true] + self::SPEC];
    }

    public function testSpecHelpersMapWireValues(): void
    {
        self::assertSame(RequestMethod::Get, OperationSpec::method(self::SPEC));
        self::assertSame(RequestMethod::Post, OperationSpec::method(['method' => 'POST'] + self::SPEC));
        self::assertSame(OutcomePolicy::Flag, OperationSpec::outcome(self::SPEC));
        self::assertSame(OutcomePolicy::Unchecked, OperationSpec::outcome(['outcome' => 'unchecked'] + self::SPEC));
        self::assertSame('DomainInfo', OperationSpec::text(self::SPEC, 'module'));
    }

    public function testMalformedSpecsAreGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        OperationSpec::method(['method' => 'PATCH'] + self::SPEC);
    }

    private static function response(stdClass $data): ApiResponse
    {
        return new ApiResponse($data, new ResponseMetadata(200, ['result' => 1]));
    }
}
