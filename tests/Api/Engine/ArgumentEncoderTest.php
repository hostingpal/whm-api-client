<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

final class ArgumentEncoderTest extends TestCase
{
    private const PARAMS = [
        'domain' => ['name' => 'domain', 'encoding' => 'scalar'],
        'hideTemporary' => ['name' => 'hide_temporary_domains', 'encoding' => 'bool'],
        'domains' => ['name' => 'domains', 'encoding' => 'repeat'],
        'users' => ['name' => 'user', 'encoding' => 'numbered'],
        'ratio' => ['name' => 'ratio', 'encoding' => 'scalar'],
    ];

    public function testEncodesEachKindInSpecOrderUnderWireNames(): void
    {
        $encoded = ArgumentEncoder::encode(self::PARAMS, [
            'users' => ['alice', 'bob'],
            'hideTemporary' => false,
            'domain' => 'example.test',
            'ratio' => 1.5,
        ]);

        self::assertSame(
            'domain=example.test&hide_temporary_domains=0&user=alice&user-1=bob&ratio=1.5',
            $encoded->encode(),
        );
    }

    public function testRepeatedArgumentsPreserveTheirOrder(): void
    {
        $encoded = ArgumentEncoder::encode(self::PARAMS, ['domains' => ['a.test', 'b.test']]);

        self::assertSame('domains=a.test&domains=b.test', $encoded->encode());
    }

    public function testExplicitNullEqualsOmission(): void
    {
        self::assertSame(
            ArgumentEncoder::encode(self::PARAMS, ['domain' => 'example.test'])->encode(),
            ArgumentEncoder::encode(
                self::PARAMS,
                ['domain' => 'example.test', 'hideTemporary' => null, 'users' => null],
            )->encode(),
        );
    }

    public function testUnknownArgumentsAreGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        ArgumentEncoder::encode(self::PARAMS, ['unknown' => 'x']);
    }

    public function testNonBooleanFlagsAreGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        ArgumentEncoder::encode(self::PARAMS, ['hideTemporary' => 1]);
    }

    public function testFloatsEncodeIndependentlyOfThePrecisionSetting(): void
    {
        $encoded = ArgumentEncoder::encode(self::PARAMS, ['ratio' => 1234567890.123456]);
        self::assertSame('1234567890.123456', $encoded->all()[0]['value']);

        $previous = ini_set('precision', '6');
        try {
            $encoded = ArgumentEncoder::encode(self::PARAMS, ['ratio' => 1234567.5]);
            self::assertSame('1234567.5', $encoded->all()[0]['value']);
        } finally {
            ini_set('precision', $previous === false ? '14' : $previous);
        }
    }

    /** @return iterable<string, array{float, string}> */
    public static function floats(): iterable
    {
        yield 'integral' => [1.0, '1.0'];
        yield 'negative zero' => [-0.0, '-0.0'];
        yield 'fraction' => [0.1, '0.1'];
        yield 'negative fraction' => [-2.5, '-2.5'];
        yield 'largest fixed exponent' => [1e16, '10000000000000000.0'];
        yield 'smallest E exponent' => [1e17, '1.0E+17'];
        yield 'large' => [1e20, '1.0E+20'];
        yield 'smallest fixed exponent' => [0.0001, '0.0001'];
        yield 'small' => [0.00001, '1.0E-5'];
        yield 'seventeen digits' => [0.30000000000000004, '0.30000000000000004'];
        yield 'maximum' => [PHP_FLOAT_MAX, '1.7976931348623157E+308'];
        yield 'subnormal' => [5e-324, '5.0E-324'];
    }

    #[DataProvider('floats')]
    public function testFloatsUseRoundTripFormIndependentOfSettings(float $value, string $expected): void
    {
        $serialize = ini_get('serialize_precision');
        $precision = ini_get('precision');
        $locale = setlocale(LC_NUMERIC, '0');

        try {
            foreach (['-1', '17', '5'] as $setting) {
                ini_set('serialize_precision', $setting);
                ini_set('precision', $setting === '-1' ? '14' : $setting);
                setlocale(LC_NUMERIC, ['de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'C']);
                $encoded = ArgumentEncoder::encode(self::PARAMS, ['ratio' => $value]);

                self::assertSame($expected, $encoded->all()[0]['value'], 'serialize_precision ' . $setting);
                self::assertSame(pack('d', $value), pack('d', (float) $expected));
            }
        } finally {
            ini_set('serialize_precision', $serialize);
            ini_set('precision', $precision);
            setlocale(LC_NUMERIC, $locale === false ? 'C' : $locale);
        }
    }

    public function testEveryPowerOfTwoRoundTripsExactly(): void
    {
        $mismatches = [];
        for ($exponent = -1074; $exponent <= 1023; $exponent++) {
            foreach ([2.0 ** $exponent, -(2.0 ** $exponent)] as $value) {
                $encoded = ArgumentEncoder::encode(self::PARAMS, ['ratio' => $value])->all()[0]['value'];
                if (!is_string($encoded) || (float) $encoded !== $value) {
                    $mismatches[] = ($value < 0 ? '-' : '') . '2 ** ' . $exponent;
                }
            }
        }

        self::assertSame([], $mismatches);
    }

    /** @return iterable<string, array{float}> */
    public static function nonFiniteFloats(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield '-INF' => [-INF];
    }

    #[DataProvider('nonFiniteFloats')]
    public function testNonFiniteFloatsAreRejected(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Float arguments must be finite.');

        ArgumentEncoder::encode(self::PARAMS, ['ratio' => $value]);
    }

    public function testArgumentsAreMarkedSensitiveOnEngineFrames(): void
    {
        foreach ([[ArgumentEncoder::class, 'encode'], [OperationRunner::class, 'run']] as [$class, $method]) {
            $parameter = (new ReflectionMethod($class, $method))->getParameters()[1];

            self::assertSame('arguments', $parameter->getName());
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
    }
}
