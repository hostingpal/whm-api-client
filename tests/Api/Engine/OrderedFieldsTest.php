<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;
use stdClass;

final class OrderedFieldsTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function distinctOrigins(): iterable
    {
        foreach (['indexedValues', 'namedValues', 'records[1].state', 'fixed'] as $origin) {
            foreach ([false, true] as $reverse) {
                yield $origin . (int) $reverse => [$origin, 'repeat', $reverse];
            }
        }
    }

    #[DataProvider('distinctOrigins')]
    public function testDifferentOriginsCannotBorrowARepeatFamily(string $origin, string $other, bool $reverse): void
    {
        $fields = new OrderedFields();
        $family = new stdClass();
        $first = $reverse ? $other : $origin;
        $second = $reverse ? $origin : $other;
        $fields->append($first, 'dynamic-secret', 'value-secret', $family);
        try {
            $fields->append($second, 'dynamic-secret', 'value-secret', $family);
            self::fail('Shared family identity cannot authorize a different origin.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('dynamic-secret', $error->getMessage());
            self::assertStringNotContainsString('value-secret', $error->getMessage());
        }
    }

    public function testTwoRepeatFamiliesFromTheSameOriginCannotShareNames(): void
    {
        $fields = new OrderedFields();
        $fields->append('items', 'same', 'equal', new stdClass());
        $this->expectException(InvalidArgumentException::class);
        $fields->append('items', 'same', 'equal', new stdClass());
    }

    public function testNonRepeatedFieldsCannotRepeatEvenWithinOneOrigin(): void
    {
        $fields = new OrderedFields();
        $fields->append('records', 'same', 'equal');
        $this->expectException(InvalidArgumentException::class);
        $fields->append('records', 'same', 'equal');
    }

    public function testRoutingPreservesProvenanceOrderEmptyZeroAndRepeatIdentity(): void
    {
        $fields = new OrderedFields();
        $family = new stdClass();
        $fields->append('namedValues', 'x_a.b-%20&=', 'a%20&=');
        $fields->append('indexedValues', 'field-0', '');
        $fields->append('records', 'state2', 0);
        $fields->append('items', 'repeat', 'equal', $family);
        $fields->append('items', 'repeat', 'equal', $family);
        $routed = $fields->withRouting(['api.version' => 1]);
        self::assertSame(
            'api.version=1&x_a.b-%2520%26%3D=a%2520%26%3D&field-0=&state2=0&repeat=equal&repeat=equal',
            $routed->encode(),
        );
        self::assertSame($fields->all(), array_slice($routed->all(), 1));
        self::assertSame('namedValues', $routed->all()[1]['origin']);
        self::assertSame($family, $routed->all()[4]['repeatFamily']);
    }

    public function testReservationsCompareLiteralNamesBeforePercentEncoding(): void
    {
        $fields = new OrderedFields();
        $fields->append('map', 'cpanel.user', 'secret');
        $this->expectException(InvalidArgumentException::class);
        $fields->withRouting(['cpanel.user' => 'npr']);
    }

    public function testCollisionTracesHideDynamicNamesAndValues(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $fields = new OrderedFields();
            $fields->append('first', 'dynamic-name-secret', 'input-value-secret', new stdClass());
            try {
                $fields->append('second', 'dynamic-name-secret', 'input-value-secret', new stdClass());
                self::fail('Independent repeat families must collide.');
            } catch (InvalidArgumentException $error) {
                foreach ($error->getTrace() as $frame) {
                    if (($frame['class'] ?? '') === OrderedFields::class) {
                        self::assertStringNotContainsString('dynamic-name-secret', print_r($frame['args'] ?? [], true));
                        self::assertStringNotContainsString('input-value-secret', print_r($frame['args'] ?? [], true));
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testAllExpandedFieldArgumentsAreSensitive(): void
    {
        foreach (['append', 'withRouting', 'assertNoReserved'] as $method) {
            foreach ((new ReflectionMethod(OrderedFields::class, $method))->getParameters() as $parameter) {
                if ($parameter->getName() !== 'multipart') {
                    self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                }
            }
        }
    }
}
