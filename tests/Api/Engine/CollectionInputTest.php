<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\CollectionEncoder;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectionInputTest extends TestCase
{
    private const INDEXED = ['kind' => 'indexed', 'item_type' => 'string', 'allow_scalar' => true,
        'empty' => 'reject', 'prefix' => 'domain', 'separator' => '-', 'start' => 0];

    /** @return iterable<string, array{array<string, mixed>, mixed, string}> */
    public static function literals(): iterable
    {
        yield 'false scalar' => [['item_type' => 'bool'] + self::INDEXED, false, 'domain=0'];
        yield 'zero scalar' => [['item_type' => 'int'] + self::INDEXED, 0, 'domain=0'];
        yield 'scalar' => [self::INDEXED, 'a', 'domain=a'];
        yield 'singleton' => [self::INDEXED, ['a'], 'domain-0=a'];
        yield 'order and duplicates' => [self::INDEXED, ['b', 'a', 'b'], 'domain-0=b&domain-1=a&domain-2=b'];
        yield 'bytes' => [self::INDEXED, ['', 'a%20&='], 'domain-0=&domain-1=a%2520%26%3D'];
        yield 'one-based' => [
            ['prefix' => 'token_name', 'start' => 1] + self::INDEXED,
            ['a', 'b'], 'token_name-1=a&token_name-2=b'];
        yield 'suffix ints' => [['prefix' => 'setting_id', 'separator' => '', 'start' => 1,
            'item_type' => 'int'] + self::INDEXED, [0, 8], 'setting_id1=0&setting_id2=8'];
        yield 'bool flags' => [['item_type' => 'bool'] + self::INDEXED, [false, true], 'domain-0=0&domain-1=1'];
        yield 'mixed' => [
            ['item_type' => 'string|int'] + self::INDEXED, ['', 0, '0'], 'domain-0=&domain-1=0&domain-2=0',
        ];
        yield 'typed repeat' => [
            ['kind' => 'repeat', 'item_type' => 'int', 'allow_scalar' => false, 'empty' => 'reject'],
            [3, 8, 3], 'domain=3&domain=8&domain=3'];
    }

    /** @param array<string, mixed> $collection */
    #[DataProvider('literals')]
    public function testExactLiteralExpansion(array $collection, mixed $input, string $expected): void
    {
        $params = ['domains' => ['name' => 'domain', 'encoding' => 'collection', 'collection' => $collection]];
        SpecChecker::check(self::spec($params));
        self::assertSame($expected, ArgumentEncoder::encode($params, ['domains' => $input])->encode());
    }

    /** @return iterable<array{string, mixed}> */
    public static function invalidInputs(): iterable
    {
        $inputs = [[], [1 => 'a'], [-1 => 'a'], ['key' => 'a'], ['a', null], ['a', 3], ['a', false], ['a', 1.5]];
        foreach ($inputs as $input) {
            yield ['string', $input];
        }
        foreach ([['3'], [3, '8'], [3, true], [3, null], [3, 8.0]] as $input) {
            yield ['int', $input];
        }
        foreach ([[0], ['0'], [false, null], [true, 1.0]] as $input) {
            yield ['bool', $input];
        }
        yield ['string', 0];
        yield ['string', false];
        yield ['int', '3'];
        yield ['int', true];
        yield ['int', 3.0];
        yield ['bool', 0];
        yield ['bool', '0'];
        yield ['string|int', [false]];
        yield ['string|int', [3.0]];
    }

    #[DataProvider('invalidInputs')]
    public function testStrictItemsAndSequentialNonemptyLists(string $type, mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode(['domains' => ['name' => 'domain', 'encoding' => 'collection',
            'collection' => ['item_type' => $type] + self::INDEXED]], ['domains' => $input]);
    }

    public function testDisallowedScalarAndNullOmissionAreIndependent(): void
    {
        $params = ['domains' => ['name' => 'domain', 'encoding' => 'collection',
            'collection' => ['allow_scalar' => false] + self::INDEXED]];
        self::assertSame('', ArgumentEncoder::encode($params, ['domains' => null])->encode());
        self::assertSame('', ArgumentEncoder::encode($params, [])->encode());
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode($params, ['domains' => 'a']);
    }

    /** @return iterable<array{array<string, mixed>}> */
    public static function malformedDescriptors(): iterable
    {
        foreach (array_keys(self::INDEXED) as $key) {
            $value = self::INDEXED;
            unset($value[$key]);
            yield [$value];
        }
        foreach (
            ['kind' => 'numbered', 'item_type' => 'float', 'allow_scalar' => 1, 'empty' => 'omit',
            'prefix' => '', 'separator' => '.', 'start' => -1, 'unknown' => true] as $key => $bad
        ) {
            yield [[$key => $bad] + self::INDEXED];
        }
        yield [['start' => 1.0] + self::INDEXED];
        yield [['start' => '0'] + self::INDEXED];
        yield [['prefix' => 'api.version'] + self::INDEXED];
        yield [['kind' => 'repeat'] + self::INDEXED];
    }

    /** @param array<string, mixed> $collection */
    #[DataProvider('malformedDescriptors')]
    public function testWholeSpecChecksDescriptorEvenWhenArgumentOmitted(array $collection): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(self::spec(['domains' => ['name' => 'domain', 'encoding' => 'collection',
            'collection' => $collection]]));
    }

    public function testLegacyOmissionPolicyCannotApplyToNewCollection(): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(self::spec(['domains' => ['name' => 'domain', 'encoding' => 'collection',
            'collection' => self::INDEXED, 'omit_empty' => true]]));
    }

    public function testDescriptorOnLegacyEncodingIsRejected(): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(self::spec(['domains' => ['name' => 'domain', 'encoding' => 'repeat',
            'collection' => self::INDEXED]]));
    }

    public function testLegacyFirstUnsuffixedNumberingIsUnchanged(): void
    {
        self::assertSame('domain=a&domain-1=b', ArgumentEncoder::encode(
            ['domains' => ['name' => 'domain', 'encoding' => 'numbered']],
            ['domains' => ['a', 'b']],
        )->encode());
    }

    public function testInvalidItemTraceRedactsEntireCollectionAndExpandedFields(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            ArgumentEncoder::encode(['domains' => ['name' => 'domain', 'encoding' => 'collection',
                'collection' => self::INDEXED]], ['domains' => ['first-secret', 'last-secret', 8]]);
            self::fail('Invalid late item must fail locally.');
        } catch (InvalidArgumentException $error) {
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                if (in_array($frame['class'] ?? '', [ArgumentEncoder::class, CollectionEncoder::class], true)) {
                    ++$frames;
                    self::assertStringNotContainsString('first-secret', print_r($frame['args'] ?? [], true));
                    self::assertStringNotContainsString('last-secret', print_r($frame['args'] ?? [], true));
                }
            }
            self::assertGreaterThanOrEqual(3, $frames);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @param array<mixed> $params
     * @return array<mixed>
     */
    private static function spec(array $params): array
    {
        return ['function' => 'fixture', 'method' => 'GET', 'outcome' => 'flag',
            'params' => $params, 'returns' => null];
    }
}
