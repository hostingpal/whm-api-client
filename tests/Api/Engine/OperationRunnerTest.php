<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
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
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertSame('Invalid primary_domain payload.', $exception->getMessage());
        }
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
        yield 'unknown return type on a POST' => [['method' => 'POST'] + self::returning(['type' => 'bogus'])];
        yield 'return spec without a type' => [self::returning([])];
        yield 'unknown type of a nullable spec' => [self::returning(['type' => 'bogus', 'nullable' => true])];
        yield 'list items of an empty list' => [self::returning(self::list(['type' => 'bogus']))];
        yield 'list without items' => [self::returning(['type' => 'list'])];
        yield 'map without values' => [self::returning(['type' => 'map'])];
        yield 'later union branch' => [
            self::returning(['type' => 'union', 'anyOf' => [['type' => 'string'], ['type' => 'bogus']]]),
        ];
        yield 'union without branches' => [self::returning(['type' => 'union', 'anyOf' => []])];
        yield 'keyed union branches' => [self::returning(['type' => 'union', 'anyOf' => ['a' => ['type' => 'int']]])];
        yield 'nested object field' => [
            self::returning(self::object(['a' => self::object(['b' => ['type' => 'bogus']])])),
        ];
        yield 'field spec not an array' => [self::returning(self::object(['a' => 'string']))];
        yield 'required undeclared field' => [self::returning(self::object(['a' => ['type' => 'string']], ['b']))];
        yield 'required integer name' => [self::returning(self::object(['0' => ['type' => 'string']], [0]))];
        yield 'required not a list' => [self::returning(self::object(['a' => ['type' => 'string']], 'a'))];
        yield 'non-boolean nullable' => [self::returning(['type' => 'string', 'nullable' => 1])];
        yield 'variant the type cannot accept' => [
            self::returning(['type' => 'string', 'accept' => ['numeric-string']]),
        ];
        yield 'unknown accepted variant' => [self::returning(['type' => 'int', 'accept' => ['hex-string']])];
        yield 'preserve on a non-number' => [self::returning(['type' => 'int', 'preserve' => true])];
        yield 'unwrap of a nullable object' => [
            ['returns' => ['nullable' => true] + self::SPEC['returns'], 'unwrap' => 'primary_domain'] + self::SPEC,
        ];
        yield 'duplicate wire names' => [
            ['params' => [
                'first' => ['name' => 'domain', 'encoding' => 'scalar'],
                'second' => ['name' => 'domain', 'encoding' => 'repeat'],
            ]] + self::SPEC,
        ];
        yield 'parameter of an omitted argument without a wire name' => [
            ['params' => ['omitted' => ['encoding' => 'scalar']]] + self::SPEC,
        ];
        yield 'parameter of an omitted argument with an unknown encoding' => [
            ['params' => ['omitted' => ['name' => 'omitted', 'encoding' => 'json']]] + self::SPEC,
        ];
        yield 'empty wire name' => [['params' => ['omitted' => ['name' => '', 'encoding' => 'scalar']]] + self::SPEC];
        yield 'missing params' => [array_diff_key(self::SPEC, ['params' => true])];
        yield 'unsupported method' => [['method' => 'PATCH'] + self::SPEC];
        yield 'unsupported outcome' => [['outcome' => 'status'] + self::SPEC];
        yield 'non-string module' => [['module' => 7] + self::SPEC];
    }

    /** @param array<mixed> $spec */
    #[DataProvider('defectiveSpecs')]
    public function testDefectiveSpecsAreRejectedOnEveryCall(array $spec): void
    {
        $rejected = 0;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                SpecChecker::check($spec);
            } catch (LogicException) {
                $rejected++;
            }
        }

        self::assertSame(2, $rejected);
    }

    public function testSoundSpecsPassTheCheckRepeatedly(): void
    {
        $sound = [
            self::SPEC,
            ['returns' => null] + self::SPEC,
            ['optional' => true, 'unwrap' => 'primary_domain'] + self::SPEC,
            ['returns' => self::object(['0' => ['type' => 'int', 'accept' => ['numeric-string']]], ['0'])] + self::SPEC,
            ['returns' => ['type' => 'number', 'accept' => ['numeric-string'], 'preserve' => true]] + self::SPEC,
            ['returns' => ['type' => 'union', 'nullable' => true, 'anyOf' => [
                ['type' => 'flag', 'accept' => ['string-flag', 'bool']],
                ['type' => 'bool', 'accept' => ['int-flag', 'string-flag']],
                ['type' => 'map', 'values' => ['type' => 'mixed']],
            ]]] + self::SPEC,
        ];

        foreach ($sound as $spec) {
            SpecChecker::check($spec);
            SpecChecker::check($spec);
        }

        $this->expectNotToPerformAssertions();
    }

    public function testAnUndeclaredArgumentNeverDispatches(): void
    {
        $calls = 0;

        try {
            OperationRunner::run(
                ['method' => 'POST'] + self::SPEC,
                ['hideTemporaryDomains' => true, 'undeclared' => 'x'],
                /**
                 * @param array<mixed> $spec
                 * @param Parameters $parameters
                 */
                static function (array $spec, array $parameters) use (&$calls): ApiResponse {
                    $calls++;

                    return self::response(new stdClass());
                },
            );
            self::fail('Expected a rejected argument.');
        } catch (LogicException) {
            self::assertSame(0, $calls);
        }
    }

    /**
     * @param array<mixed> $returns
     * @return array<mixed>
     */
    private static function returning(array $returns): array
    {
        return ['returns' => $returns] + self::SPEC;
    }

    /**
     * @param array<mixed> $items
     * @return array<mixed>
     */
    private static function list(array $items): array
    {
        return ['type' => 'list', 'items' => $items];
    }

    /**
     * @param array<mixed> $fields
     * @return array<mixed>
     */
    private static function object(array $fields, mixed $required = []): array
    {
        return ['type' => 'object', 'fields' => $fields, 'required' => $required];
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
