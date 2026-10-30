<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShapeValidatorTest extends TestCase
{
    /** @param array<mixed> $type */
    #[DataProvider('validScalars')]
    public function testAcceptsAndNormalizesScalars(string $json, array $type, mixed $expected): void
    {
        self::assertSame($expected, ShapeValidator::value(self::decode($json), $type, self::reader()));
    }

    /** @return iterable<string, array{string, array<mixed>, mixed}> */
    public static function validScalars(): iterable
    {
        yield 'string' => ['"a"', ['type' => 'string'], 'a'];
        yield 'empty string' => ['""', ['type' => 'string'], ''];
        yield 'int' => ['5', ['type' => 'int'], 5];
        yield 'int from numeric string' => ['"42"', ['type' => 'int', 'accept' => ['numeric-string']], 42];
        yield 'negative int from numeric string' => ['"-7"', ['type' => 'int', 'accept' => ['numeric-string']], -7];
        yield 'number int' => ['3', ['type' => 'number'], 3];
        yield 'number float' => ['1.5', ['type' => 'number'], 1.5];
        yield 'number from decimal string' => ['"0.50"', ['type' => 'number', 'accept' => ['numeric-string']], 0.5];
        yield 'number from integer string' => ['"3"', ['type' => 'number', 'accept' => ['numeric-string']], 3];
        yield 'number preserved string' => [
            '"0.50"',
            ['type' => 'number', 'accept' => ['numeric-string'], 'preserve' => true],
            '0.50',
        ];
        yield 'number from zero-padded string' => ['"007"', ['type' => 'number', 'accept' => ['numeric-string']], 7];
        yield 'number from plus-signed string' => ['"+5"', ['type' => 'number', 'accept' => ['numeric-string']], 5];
        yield 'number from negative zero string' => ['"-0"', ['type' => 'number', 'accept' => ['numeric-string']], 0];
        yield 'number from negative decimal string' => [
            '"-0.25"',
            ['type' => 'number', 'accept' => ['numeric-string']],
            -0.25,
        ];
        yield 'number from overflowing integer string' => [
            '"99999999999999999999"',
            ['type' => 'number', 'accept' => ['numeric-string']],
            1.0E+20,
        ];
        yield 'number from overflowing negative integer string' => [
            '"-99999999999999999999"',
            ['type' => 'number', 'accept' => ['numeric-string']],
            -1.0E+20,
        ];
        yield 'number from overflowing decimal string' => [
            '"99999999999999999999.5"',
            ['type' => 'number', 'accept' => ['numeric-string']],
            1.0E+20,
        ];
        yield 'number from overflowing JSON integer' => ['99999999999999999999', ['type' => 'number'], 1.0E+20];
        yield 'number preserved overflow string' => [
            '"99999999999999999999"',
            ['type' => 'number', 'accept' => ['numeric-string'], 'preserve' => true],
            '99999999999999999999',
        ];
        yield 'bool' => ['true', ['type' => 'bool'], true];
        yield 'bool from int flag' => ['1', ['type' => 'bool', 'accept' => ['int-flag']], true];
        yield 'bool from string flag' => ['"0"', ['type' => 'bool', 'accept' => ['string-flag']], false];
        yield 'flag' => ['1', ['type' => 'flag'], 1];
        yield 'flag from string flag' => ['"1"', ['type' => 'flag', 'accept' => ['string-flag']], 1];
        yield 'flag preserves explicit empty string' => ['""', ['type' => 'flag', 'accept' => ['empty-string']], ''];
        yield 'flag from bool' => ['false', ['type' => 'flag', 'accept' => ['bool']], 0];
        yield 'mixed' => ['{"a":[1,{"b":2}]}', ['type' => 'mixed'], ['a' => [1, ['b' => 2]]]];
        yield 'nullable null' => ['null', ['type' => 'string', 'nullable' => true], null];
        yield 'mixed null' => ['null', ['type' => 'mixed'], null];
        yield 'map of mixed with null' => [
            '{"a":null}',
            ['type' => 'map', 'values' => ['type' => 'mixed']],
            ['a' => null],
        ];
        yield 'union with nullable branch accepts null' => [
            'null',
            ['type' => 'union', 'anyOf' => [['type' => 'string', 'nullable' => true], ['type' => 'int']]],
            null,
        ];
        yield 'nullable union accepts null' => [
            'null',
            ['type' => 'union', 'nullable' => true, 'anyOf' => [['type' => 'string'], ['type' => 'int']]],
            null,
        ];
    }

    /** @param array<mixed> $type */
    #[DataProvider('invalidScalars')]
    public function testRejectsInvalidScalarsWithTheResponseSnapshot(string $json, array $type): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            ShapeValidator::value(self::decode($json), $type, new PayloadReader($metadata, 'example payload'));
            self::fail('Expected an invalid payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertSame('Invalid example payload.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidScalars(): iterable
    {
        yield 'int as string' => ['1', ['type' => 'string']];
        yield 'null without nullable' => ['null', ['type' => 'string']];
        yield 'null against union without nullable branch' => [
            'null',
            ['type' => 'union', 'anyOf' => [['type' => 'string'], ['type' => 'int']]],
        ];
        yield 'numeric string without accept' => ['"42"', ['type' => 'int']];
        yield 'leading zero string' => ['"042"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'exponent string' => ['"1e3"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'int overflow string' => ['"99999999999999999999"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'float as int' => ['1.5', ['type' => 'int']];
        yield 'number from text' => ['"abc"', ['type' => 'number', 'accept' => ['numeric-string']]];
        yield 'number exponent string' => ['"1e5"', ['type' => 'number', 'accept' => ['numeric-string']]];
        yield 'number decimal string without digits after the point' => [
            '"5."',
            ['type' => 'number', 'accept' => ['numeric-string']],
        ];
        yield 'number string with surrounding space' => ['" 5"', ['type' => 'number', 'accept' => ['numeric-string']]];
        yield 'bool from string without accept' => ['"1"', ['type' => 'bool']];
        yield 'empty flag string without explicit compatibility' => [
            '""', ['type' => 'flag', 'accept' => ['string-flag']],
        ];
        yield 'flag out of range' => ['2', ['type' => 'flag']];
        yield 'flag string without accept' => ['"1"', ['type' => 'flag']];
        yield 'flag bool without accept' => ['true', ['type' => 'flag']];
    }

    public function testRejectsMalformedSpecsAsGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        ShapeValidator::value('a', ['type' => 'unknown'], self::reader());
    }

    /** @param array<mixed> $type */
    #[DataProvider('validContainers')]
    public function testValidatesContainers(string $json, array $type, mixed $expected): void
    {
        self::assertSame($expected, ShapeValidator::value(self::decode($json), $type, self::reader()));
    }

    /** @return iterable<string, array{string, array<mixed>, mixed}> */
    public static function validContainers(): iterable
    {
        $object = [
            'type' => 'object',
            'fields' => ['a' => ['type' => 'string'], 'b' => ['type' => 'int']],
            'required' => ['a'],
        ];
        yield 'list' => ['[1,2]', ['type' => 'list', 'items' => ['type' => 'int']], [1, 2]];
        yield 'empty list' => ['[]', ['type' => 'list', 'items' => ['type' => 'int']], []];
        yield 'map' => ['{"x.test":0}', ['type' => 'map', 'values' => ['type' => 'flag']], ['x.test' => 0]];
        yield 'object drops unknown and keeps omission' => ['{"extra":true,"a":"x"}', $object, ['a' => 'x']];
        yield 'object keeps declared order' => ['{"b":2,"a":"x"}', $object, ['a' => 'x', 'b' => 2]];
        yield 'nested normalization' => [
            '{"domains":[{"port":"80"}]}',
            [
                'type' => 'object',
                'fields' => ['domains' => ['type' => 'list', 'items' => [
                    'type' => 'object',
                    'fields' => ['port' => ['type' => 'int', 'accept' => ['numeric-string']]],
                ]]],
            ],
            ['domains' => [['port' => 80]]],
        ];
        yield 'union first branch' => [
            '5',
            ['type' => 'union', 'anyOf' => [['type' => 'int'], ['type' => 'string']]],
            5,
        ];
        yield 'union second branch' => [
            '"a"',
            ['type' => 'union', 'anyOf' => [['type' => 'int'], ['type' => 'string']]],
            'a',
        ];
    }

    /** @param array<mixed> $type */
    #[DataProvider('invalidContainers')]
    public function testRejectsInvalidContainers(string $json, array $type): void
    {
        $this->expectException(InvalidResponseException::class);

        ShapeValidator::value(self::decode($json), $type, self::reader());
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidContainers(): iterable
    {
        yield 'object as list' => ['{"a":1}', ['type' => 'list', 'items' => ['type' => 'int']]];
        yield 'list item mismatch' => ['[1,"x"]', ['type' => 'list', 'items' => ['type' => 'int']]];
        yield 'scalar as map' => ['5', ['type' => 'map', 'values' => ['type' => 'int']]];
        yield 'missing required field' => [
            '{"b":1}',
            ['type' => 'object', 'fields' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ];
        yield 'empty array for object with required fields' => [
            '[]',
            ['type' => 'object', 'fields' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ];
        yield 'explicit null in a non-nullable field' => [
            '{"a":null}',
            ['type' => 'object', 'fields' => ['a' => ['type' => 'string']]],
        ];
        yield 'explicit null in a non-nullable required field' => [
            '{"a":null}',
            ['type' => 'object', 'fields' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ];
        yield 'union without match' => [
            'true',
            ['type' => 'union', 'anyOf' => [['type' => 'int'], ['type' => 'string']]],
        ];
    }

    /** @param array<mixed> $type */
    #[DataProvider('unionsWithMalformedBranches')]
    public function testMalformedUnionBranchesPassThroughInsteadOfBeingSwallowed(string $json, array $type): void
    {
        $this->expectException(LogicException::class);

        ShapeValidator::value(self::decode($json), $type, self::reader());
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function unionsWithMalformedBranches(): iterable
    {
        yield 'unknown first branch' => [
            '"a"',
            ['type' => 'union', 'anyOf' => [['type' => 'bogus'], ['type' => 'string']]],
        ];
        yield 'nested unknown type in the first branch' => [
            '{"a":1}',
            ['type' => 'union', 'anyOf' => [
                ['type' => 'object', 'fields' => ['a' => ['type' => 'bogus']]],
                ['type' => 'mixed'],
            ]],
        ];
        yield 'malformed branch after a failing one' => [
            '1',
            ['type' => 'union', 'anyOf' => [['type' => 'string'], ['type' => 'union', 'anyOf' => []]]],
        ];
    }

    public function testEmptyJsonArrayIsAnEmptyObjectOrMap(): void
    {
        $object = ['type' => 'object', 'fields' => ['a' => ['type' => 'string']]];
        $map = ['type' => 'map', 'values' => ['type' => 'int']];

        self::assertSame([], ShapeValidator::value([], $object, self::reader()));
        self::assertSame([], ShapeValidator::value([], $map, self::reader()));
    }

    public function testMapKeepsNumericKeys(): void
    {
        $result = ShapeValidator::value(
            self::decode('{"0":1,"10":0}'),
            ['type' => 'map', 'values' => ['type' => 'flag']],
            self::reader(),
        );

        self::assertSame([0 => 1, 10 => 0], $result);
    }

    public function testUnionFallsThroughDeepFailures(): void
    {
        $type = ['type' => 'union', 'anyOf' => [
            ['type' => 'object', 'fields' => ['a' => ['type' => 'object', 'fields' => [
                'b' => ['type' => 'int'],
            ], 'required' => ['b']]], 'required' => ['a']],
            ['type' => 'mixed'],
        ]];

        self::assertSame(
            ['a' => ['b' => 'x']],
            ShapeValidator::value(self::decode('{"a":{"b":"x"}}'), $type, self::reader()),
        );
    }

    private static function reader(): PayloadReader
    {
        return new PayloadReader(new ResponseMetadata(200), 'example payload');
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }
}
