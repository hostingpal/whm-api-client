<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\CollectionEncoder;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DelimitedCollectionInputTest extends TestCase
{
    private const RULES = ['kind' => 'delimited', 'item_type' => 'string', 'allow_scalar' => true,
        'empty' => 'reject', 'delimiter' => ','];

    /** @return iterable<array{string, mixed, string}> */
    public static function literals(): iterable
    {
        foreach ([',', '|'] as $delimiter) {
            foreach ([[''], ['', 'a', ''], ['b', 'a', 'b'], [" a%20&=+:/?é\x00\xff ", '']] as $items) {
                yield [$delimiter, $items, 'value=' . rawurlencode(implode($delimiter, $items))];
            }
            foreach (['', ' ', "a,b|c%20&=é\x00\xff"] as $scalar) {
                yield [$delimiter, $scalar, 'value=' . rawurlencode($scalar)];
            }
        }
    }

    #[DataProvider('literals')]
    public function testOneFieldPreservesExactBytes(string $delimiter, mixed $value, string $expected): void
    {
        $params = self::params(['delimiter' => $delimiter]);
        SpecChecker::check(self::spec($params));
        $fields = ArgumentEncoder::encode($params, ['value' => $value]);
        self::assertCount(1, $fields->all());
        self::assertSame($expected, $fields->encode());
    }

    /** @return iterable<array{mixed}> */
    public static function invalidValues(): iterable
    {
        foreach (
            [
            [], [1 => 'a'], ['key' => 'a'], ['ok', null], ['ok', 0], ['ok', false],
            ['ok', 1.0], ['first-secret', 'last,secret'], 0, false, 1.0] as $value
        ) {
            yield [$value];
        }
    }

    #[DataProvider('invalidValues')]
    public function testInvalidLateItemsNeverAppendAndRemainPrivate(mixed $value): void
    {
        $fields = new OrderedFields();
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            CollectionEncoder::append($fields, 'value', 'value', self::RULES, $value);
            self::fail('Invalid input accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame([], $fields->all());
            self::assertStringNotContainsString('secret', $error->getMessage());
            foreach ($error->getTrace() as $frame) {
                if (($frame['class'] ?? '') === CollectionEncoder::class) {
                    self::assertStringNotContainsString('secret', print_r($frame['args'] ?? [], true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testArrayOnlyAndOmissionHaveDistinctPolicies(): void
    {
        $params = self::params(['allow_scalar' => false]);
        self::assertSame('', ArgumentEncoder::encode($params, [])->encode());
        self::assertSame('', ArgumentEncoder::encode($params, ['value' => null])->encode());
        self::assertSame('value=', ArgumentEncoder::encode($params, ['value' => ['']])->encode());
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode($params, ['value' => 'a,b']);
    }

    /** @return iterable<array{array<string, mixed>}> */
    public static function invalidDescriptors(): iterable
    {
        foreach (array_keys(self::RULES) as $key) {
            $copy = self::RULES;
            unset($copy[$key]);
            yield [$copy];
            yield [[$key => null] + self::RULES];
        }
        foreach (
            ['kind' => 'other', 'delimiter' => ';', 'item_type' => 'int', 'empty' => 'allow',
            'allow_scalar' => 1, 'prefix' => '', 'separator' => '-', 'start' => 0, 'extra' => true] as $key => $value
        ) {
            yield [[$key => $value] + self::RULES];
        }
        yield [['delimiter' => false] + self::RULES];
        foreach (
            [
            ['kind' => 'repeat', 'item_type' => 'string', 'allow_scalar' => false, 'empty' => 'reject'],
            ['kind' => 'indexed', 'item_type' => 'string', 'allow_scalar' => false, 'empty' => 'reject',
                'prefix' => 'x', 'separator' => '-', 'start' => 0],
            ['kind' => 'map', 'item_type' => 'string', 'allow_scalar' => false,
                'empty' => 'allow', 'prefix' => '']] as $old
        ) {
            yield [$old + ['delimiter' => ',']];
            yield [$old + ['delimiter' => null]];
        }
    }

    /** @param array<string, mixed> $descriptor */
    #[DataProvider('invalidDescriptors')]
    public function testInvalidDescriptorFailsWithoutAnArgument(array $descriptor): void
    {
        $this->expectException(LogicException::class);
        ArgumentEncoder::encode(['value' => ['name' => 'value', 'encoding' => 'collection',
            'collection' => $descriptor]], []);
    }

    /** @param array<string, mixed> $descriptor */
    #[DataProvider('invalidDescriptors')]
    public function testWholeSpecChecksInvalidDescriptorWithoutValues(array $descriptor): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(self::spec(['value' => ['name' => 'value', 'encoding' => 'collection',
            'collection' => $descriptor]]));
    }

    public function testArrayOnlyReservationRejectsMapInBothOrdersEvenWhenOmitted(): void
    {
        foreach ([false, true] as $reverse) {
            $params = self::params(['allow_scalar' => false]) + ['map' => ['name' => 'placeholder',
                'encoding' => 'collection', 'collection' => ['kind' => 'map', 'item_type' => 'string',
                    'allow_scalar' => false, 'empty' => 'allow', 'prefix' => '']]];
            try {
                ArgumentEncoder::encode(
                    $reverse ? array_reverse($params, true) : $params,
                    ['map' => ['value' => 'secret']],
                );
                self::fail('Map claimed an omitted delimited input.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testFirstUnsuffixedCollisionsFailWithoutValuesInBothOrders(): void
    {
        foreach (['value', 'value-1'] as $wire) {
            foreach ([false, true] as $reverse) {
                $params = ['list' => ['name' => $wire, 'encoding' => 'collection',
                    'collection' => ['allow_scalar' => false] + self::RULES],
                    'rows' => ['name' => 'rows', 'encoding' => 'records', 'records' => [
                        'start' => 0, 'separator' => '-', 'first_unsuffixed' => true,
                        'fields' => ['field' => ['prefix' => 'value', 'type' => 'string', 'required' => true]]]]];
                try {
                    SpecChecker::check(self::spec($reverse ? array_reverse($params, true) : $params));
                    self::fail('Record overlap accepted.');
                } catch (LogicException $error) {
                    self::assertStringContainsString('Overlapping', $error->getMessage());
                }
            }
        }
    }

    public function testOmittedDelimitedNameCannotOverlapIndexedFamilies(): void
    {
        foreach ([false, true] as $reverse) {
            foreach (['value-1', 'value-0', 'other'] as $wire) {
                $params = ['joined' => ['name' => $wire, 'encoding' => 'collection',
                    'collection' => ['allow_scalar' => false] + self::RULES],
                    'indexed' => ['name' => 'list', 'encoding' => 'collection', 'collection' => [
                        'kind' => 'indexed', 'item_type' => 'string', 'allow_scalar' => false,
                        'empty' => 'reject', 'prefix' => 'value', 'separator' => '-', 'start' => 1]]];
                try {
                    SpecChecker::check(self::spec($reverse ? array_reverse($params, true) : $params));
                    self::assertNotSame('value-1', $wire, 'Omitted delimited name still owns its wire.');
                } catch (LogicException $error) {
                    self::assertSame('value-1', $wire);
                    self::assertStringContainsString('Overlapping', $error->getMessage());
                }
            }
        }
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function params(array $changes = []): array
    {
        return ['value' => ['name' => 'value', 'encoding' => 'collection', 'collection' => $changes + self::RULES]];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function spec(array $params): array
    {
        return ['function' => 'fixture', 'method' => 'GET', 'outcome' => 'flag',
            'params' => $params, 'returns' => null];
    }
}
