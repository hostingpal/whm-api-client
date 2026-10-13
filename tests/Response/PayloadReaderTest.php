<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use Closure;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PayloadReaderTest extends TestCase
{
    public function testReturnsValidValuesUnchanged(): void
    {
        $read = self::reader();
        $object = (object) ['name' => 'value', 'nothing' => null];

        self::assertSame($object, $read->object($object));
        self::assertSame('value', $read->field($object, 'name'));
        self::assertNull($read->field($object, 'nothing'));
        self::assertSame('', $read->string(''));
        self::assertNull($read->nullableString(null));
        self::assertSame('text', $read->nullableString('text'));
        self::assertSame(0, $read->flag(0));
        self::assertSame(1, $read->flag(1));
        self::assertSame(1, $read->positiveInt(1));
        self::assertSame(0, $read->nonNegativeInt(0));
        self::assertNull($read->nullable(null, $read->positiveInt(...)));
        self::assertSame(5, $read->nullable(5, $read->positiveInt(...)));
        self::assertSame([], $read->strings([]));
        self::assertSame(['a', 'a', ''], $read->strings(['a', 'a', '']));
        self::assertSame([2, 4], $read->listOf([1, 2], static fn (mixed $item): int => is_int($item) ? $item * 2 : 0));
        self::assertSame('0', $read->stringFlag('0'));
        self::assertSame(1, $read->stringFlag(1));
        self::assertTrue($read->boolFlag(true));
        self::assertSame(0, $read->boolFlag(0));
    }

    public function testListsAreDetachedFromReferencedSourceValues(): void
    {
        $value = 'original';
        $source = [&$value];

        $copy = self::reader()->strings($source);
        $value = 'changed';

        self::assertSame(['original'], $copy);
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValuesWithTheResponseSnapshot(Closure $read): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            $read(new PayloadReader($metadata, 'example payload'));
            self::fail('Expected an invalid payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertSame('Invalid example payload.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{Closure(PayloadReader): mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'list as object' => [static fn (PayloadReader $read): mixed => $read->object([])];
        yield 'missing field' => [static fn (PayloadReader $read): mixed => $read->field(new stdClass(), 'name')];
        yield 'field of non-object' => [static fn (PayloadReader $read): mixed => $read->field(null, 'name')];
        yield 'integer as string' => [static fn (PayloadReader $read): mixed => $read->string(1)];
        yield 'null as string' => [static fn (PayloadReader $read): mixed => $read->string(null)];
        yield 'integer as nullable string' => [static fn (PayloadReader $read): mixed => $read->nullableString(0)];
        yield 'string flag' => [static fn (PayloadReader $read): mixed => $read->flag('1')];
        yield 'boolean flag' => [static fn (PayloadReader $read): mixed => $read->flag(true)];
        yield 'flag out of range' => [static fn (PayloadReader $read): mixed => $read->flag(2)];
        yield 'zero as positive' => [static fn (PayloadReader $read): mixed => $read->positiveInt(0)];
        yield 'numeric string as positive' => [static fn (PayloadReader $read): mixed => $read->positiveInt('5')];
        yield 'negative as nonnegative' => [static fn (PayloadReader $read): mixed => $read->nonNegativeInt(-1)];
        yield 'nullable inner failure' => [
            static fn (PayloadReader $read): mixed => $read->nullable(0, $read->positiveInt(...)),
        ];
        yield 'padded string flag' => [static fn (PayloadReader $read): mixed => $read->stringFlag(' 1')];
        yield 'boolean as string flag' => [static fn (PayloadReader $read): mixed => $read->stringFlag(true)];
        yield 'string as boolean flag' => [static fn (PayloadReader $read): mixed => $read->boolFlag('1')];
        yield 'object as strings' => [static fn (PayloadReader $read): mixed => $read->strings(new stdClass())];
        yield 'keyed strings' => [static fn (PayloadReader $read): mixed => $read->strings(['a' => 'b'])];
        yield 'non-string item' => [static fn (PayloadReader $read): mixed => $read->strings(['a', 1])];
        yield 'keyed list' => [
            static fn (PayloadReader $read): mixed => $read->listOf(
                [1 => 'a'],
                static fn (mixed $item): mixed => $item,
            ),
        ];
    }

    public function testFailRaisesTheSubjectError(): void
    {
        $metadata = new ResponseMetadata(200);
        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('Invalid example payload.');

        (new PayloadReader($metadata, 'example payload'))->fail();
    }

    private static function reader(): PayloadReader
    {
        return new PayloadReader(new ResponseMetadata(200), 'example payload');
    }
}
