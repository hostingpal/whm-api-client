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

final class NamedMapInputTest extends TestCase
{
    private const MAP = ['kind' => 'map', 'item_type' => 'string', 'allow_scalar' => false,
        'empty' => 'allow', 'prefix' => ''];

    /** @return iterable<string, array{array<string, mixed>, mixed, string}> */
    public static function literals(): iterable
    {
        yield 'feature' => [['item_type' => 'bool'], ['changemx' => true], 'changemx=1'];
        yield 'greylist' => [['item_type' => 'bool'], ['google' => false], 'google=0'];
        yield 'MX' => [['prefix' => 'MXCHECK-', 'item_type' => 'int'], ['example.com' => 10], 'MXCHECK-example.com=10'];
        yield 'database' => [['prefix' => 'copymysqldb-'], ['source' => 'target'], 'copymysqldb-source=target'];
        yield 'literal prefix' => [['prefix' => 'x_'], ['x_Case' => 'value'], 'x_x_Case=value'];
        yield 'bytes and order' => [
            [], ['Case.-%&=' => 'a%20&=', '01' => '', 'é' => '€'],
            'Case.-%25%26%3D=a%2520%26%3D&01=&%C3%A9=%E2%82%AC'];
        yield 'union zero' => [['item_type' => 'string|int'], ['first' => 0, 'second' => '0'], 'first=0&second=0'];
        yield 'binary value bytes' => [[], ['key' => "\x00\xFF\r\n"], 'key=%00%FF%0D%0A'];
        yield 'empty allowed' => [[], [], ''];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('literals')]
    public function testLiteralNamedMapExpansion(array $overrides, mixed $input, string $expected): void
    {
        $params = self::params($overrides + self::MAP);
        SpecChecker::check(['function' => 'fixture', 'method' => 'GET', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
        self::assertSame($expected, ArgumentEncoder::encode($params, ['values' => $input])->encode());
    }

    /** @return iterable<array{array<string, mixed>, mixed}> */
    public static function invalidInputs(): iterable
    {
        foreach (
            ['', 'scalar', true, 1, 1.0, ['' => 'v'], [1 => 'v'], ['1' => 'v'], [0 => 'v'],
            ["\xFF" => 'v'], ['key' => null], ['key' => false], ['key' => 1], ['key' => 1.0]] as $input
        ) {
            yield [self::MAP, $input];
        }
        for ($control = 0; $control <= 31; ++$control) {
            yield [self::MAP, ['private' . chr($control) . 'key' => 'v']];
        }
        yield [self::MAP, ["private\x7Fkey" => 'v']];
        yield [['empty' => 'reject'] + self::MAP, []];
        foreach (
            ['int' => ['1', false, 1.0, null], 'bool' => [0, '0', 1.0, null],
            'string|int' => [true, 1.0, null]] as $type => $values
        ) {
            foreach ($values as $value) {
                yield [['item_type' => $type] + self::MAP, ['private-key' => $value]];
            }
        }
    }

    /** @param array<string, mixed> $descriptor */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidKeysAndStrictValues(array $descriptor, mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode(self::params($descriptor), ['values' => $input]);
    }

    /** @return iterable<array{array<string, mixed>}> */
    public static function malformed(): iterable
    {
        foreach (array_keys(self::MAP) as $key) {
            $spec = self::MAP;
            unset($spec[$key]);
            yield [$spec];
        }
        foreach (
            ['allow_scalar' => true, 'empty' => 'omit', 'prefix' => 'api.version',
            'item_type' => 'float', 'separator' => '-', 'start' => 0, 'unknown' => true] as $key => $value
        ) {
            yield [[$key => $value] + self::MAP];
        }
    }

    /** @param array<string, mixed> $descriptor */
    #[DataProvider('malformed')]
    public function testMalformedSpecsFailEvenWhenOmitted(array $descriptor): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'fixture', 'method' => 'GET', 'outcome' => 'flag',
            'params' => self::params($descriptor), 'returns' => null]);
    }

    public function testMapCollisionsAgainstEveryOriginInBothOrders(): void
    {
        foreach (
            [
                ['name' => 'x_key', 'encoding' => 'scalar'],
            ['name' => 'other', 'encoding' => 'collection', 'collection' => ['prefix' => 'x_'] + self::MAP],
            ['name' => 'x_key', 'encoding' => 'repeat'],
            ['name' => 'x_key*', 'encoding' => 'suffix-numbered'],
            ['name' => 'other', 'encoding' => 'collection', 'collection' => ['kind' => 'indexed',
                'item_type' => 'string', 'allow_scalar' => false, 'empty' => 'reject',
                'prefix' => 'x_key', 'separator' => '', 'start' => 1]],
            ] as $other
        ) {
            foreach ([false, true] as $reverse) {
                $suffix = in_array($other['encoding'], ['suffix-numbered', 'collection'], true)
                    && ($other['collection']['kind'] ?? '') !== 'map' ? '1' : '';
                $params = self::params(['prefix' => 'x_'] + self::MAP) + ['other' => $other];
                $value = $other['encoding'] === 'scalar' ? 'private-value'
                    : (($other['collection']['kind'] ?? '') === 'map' ? ['key' => 'private-value'] : ['private-value']);
                try {
                    ArgumentEncoder::encode(
                        $reverse ? array_reverse($params, true) : $params,
                        ['values' => ['key' . $suffix => 'private-value'], 'other' => $value],
                    );
                    self::fail('Every expanded origin must retain ownership.');
                } catch (InvalidArgumentException $error) {
                    self::assertStringNotContainsString('key', $error->getMessage());
                    self::assertStringNotContainsString('private-value', $error->getMessage());
                }
            }
        }
    }

    public function testLateInvalidValueTraceRedactsMapKeysValuesAndFields(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            ArgumentEncoder::encode(
                self::params(self::MAP),
                ['values' => ['private-key' => 'private-value', 'late-key' => null]],
            );
            self::fail('Late invalid map entry must fail.');
        } catch (InvalidArgumentException $error) {
            foreach ($error->getTrace() as $frame) {
                if (in_array($frame['class'] ?? '', [ArgumentEncoder::class, CollectionEncoder::class], true)) {
                    $text = print_r($frame['args'] ?? [], true);
                    self::assertStringNotContainsString('private-key', $text);
                    self::assertStringNotContainsString('private-value', $text);
                    self::assertStringNotContainsString('late-key', $text);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private static function params(array $descriptor): array
    {
        return ['values' => ['name' => 'placeholder*', 'encoding' => 'collection', 'collection' => $descriptor]];
    }
}
