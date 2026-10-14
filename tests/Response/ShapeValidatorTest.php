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
        yield 'flag from bool' => ['false', ['type' => 'flag', 'accept' => ['bool']], 0];
        yield 'mixed' => ['{"a":[1,{"b":2}]}', ['type' => 'mixed'], ['a' => [1, ['b' => 2]]]];
        yield 'nullable null' => ['null', ['type' => 'string', 'nullable' => true], null];
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
        yield 'numeric string without accept' => ['"42"', ['type' => 'int']];
        yield 'leading zero string' => ['"042"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'exponent string' => ['"1e3"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'int overflow string' => ['"99999999999999999999"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'float as int' => ['1.5', ['type' => 'int']];
        yield 'number from text' => ['"abc"', ['type' => 'number', 'accept' => ['numeric-string']]];
        yield 'number exponent string' => ['"1e5"', ['type' => 'number', 'accept' => ['numeric-string']]];
        yield 'number overflow string' => [
            '"99999999999999999999"',
            ['type' => 'number', 'accept' => ['numeric-string']],
        ];
        yield 'bool from string without accept' => ['"1"', ['type' => 'bool']];
        yield 'flag out of range' => ['2', ['type' => 'flag']];
        yield 'flag string without accept' => ['"1"', ['type' => 'flag']];
        yield 'flag bool without accept' => ['true', ['type' => 'flag']];
    }

    public function testRejectsMalformedSpecsAsGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        ShapeValidator::value('a', ['type' => 'unknown'], self::reader());
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
