<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegralFixedPointStringTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function exactIntegers(): iterable
    {
        yield 'zero' => ['0.0', 0];
        yield 'positive' => ['32.000000000000000000', 32];
        yield 'negative' => ['-7.00', -7];
        yield 'maximum' => [PHP_INT_MAX . '.000', PHP_INT_MAX];
        yield 'minimum' => [PHP_INT_MIN . '.000', PHP_INT_MIN];
        yield 'long zero fraction' => ['32.' . str_repeat('0', 1024), 32];
        if (PHP_INT_SIZE === 8) {
            yield 'beyond binary64 exact range' => ['9007199254740993.000000', 9007199254740993];
        }
    }

    #[DataProvider('exactIntegers')]
    public function testOptInNormalizesExactlyWithoutFloatConversion(string $wire, int $expected): void
    {
        self::assertSame($expected, ShapeValidator::value(
            $wire,
            ['type' => 'int', 'accept' => ['integral-fixed-point-string']],
            self::reader(),
        ));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidValues(): iterable
    {
        foreach (
            ['32.000001', '-7.01', '32.0e0', '+32.0', '032.0', ' 32.0', '32.0 ', '-0.0',
            '-00.0', '+0.0', '.0', '32.', '32', '', 'unlimited'] as $wire
        ) {
            yield $wire === '' ? 'empty' : $wire => [$wire];
        }
        yield 'positive overflow' => [PHP_INT_SIZE === 8 ? '9223372036854775808.0' : '2147483648.0'];
        yield 'negative overflow' => [PHP_INT_SIZE === 8 ? '-9223372036854775809.0' : '-2147483649.0'];
        yield 'native float' => [32.0];
        yield 'boolean' => [true];
        yield 'null' => [null];
    }

    #[DataProvider('invalidValues')]
    public function testOptInRejectsLossyNoncanonicalOrWrongTypeValues(mixed $wire): void
    {
        $this->expectException(InvalidResponseException::class);
        ShapeValidator::value($wire, ['type' => 'int', 'accept' => ['integral-fixed-point-string']], self::reader());
    }

    public function testTheExistingIntegerStringOptInDoesNotImplicitlyAdmitFixedPoint(): void
    {
        $this->expectException(InvalidResponseException::class);
        ShapeValidator::value('32.000', ['type' => 'int', 'accept' => ['numeric-string']], self::reader());
    }

    public function testBothIntegerWireFormsRequireTheirOwnDeclaredOptIns(): void
    {
        $spec = ['type' => 'int', 'accept' => ['numeric-string', 'integral-fixed-point-string']];
        self::assertSame(32, ShapeValidator::value('32', $spec, self::reader()));
        self::assertSame(32, ShapeValidator::value('32.000', $spec, self::reader()));
        self::assertSame(32, ShapeValidator::value(32, $spec, self::reader()));
    }

    private static function reader(): PayloadReader
    {
        return new PayloadReader(new ResponseMetadata(200, ['result' => 1]), 'integer payload');
    }
}
