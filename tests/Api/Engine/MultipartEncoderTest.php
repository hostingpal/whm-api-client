<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\MultipartEncoder;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

final class MultipartEncoderTest extends TestCase
{
    /** @param array<array-key, mixed> $fields */
    #[DataProvider('invalidFields')]
    public function testRejectsHeaderInjectionAndNonStringFieldsWithoutValuesInErrors(array $fields): void
    {
        try {
            (new ReflectionMethod(MultipartEncoder::class, 'encode'))->invoke(null, $fields);
            self::fail('Invalid multipart fields must fail locally.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Multipart fields require safe names and string values.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidFields(): iterable
    {
        yield 'newline name' => [["key\r\nX-Injected: value" => 'private']];
        yield 'quote name' => [['key"; filename="bad' => 'private']];
        yield 'empty name' => [['' => 'private']];
        yield 'integer value' => [['key' => 123]];
        yield 'list value' => [['key' => ['private']]];
        yield 'object value' => [['key' => (object) ['private' => 'secret']]];
    }

    public function testBoundaryCollisionCheckSeesBinaryAndMiddleOfFieldValues(): void
    {
        $check = new ReflectionMethod(MultipartEncoder::class, 'collides');
        self::assertTrue($check->invoke(null, ['key' => "before\x00chosen-boundary\xffafter"], 'chosen-boundary'));
        self::assertFalse($check->invoke(null, ['key' => "before\x00value\xffafter"], 'chosen-boundary'));
        foreach (['encode', 'collides'] as $method) {
            $parameter = (new ReflectionMethod(MultipartEncoder::class, $method))->getParameters()[0];
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
    }

    public function testOrderedFieldsPreserveLiteralStringBytesAndOrder(): void
    {
        $fields = new OrderedFields();
        $fields->append('key', 'key', "private%20&=\r\nkey\x00");
        $fields->append('empty', 'empty', '');
        [$type, $body] = MultipartEncoder::encode($fields);
        $boundary = substr($type, strlen('multipart/form-data; boundary='));
        self::assertSame('--' . $boundary . "\r\nContent-Disposition: form-data; name=\"key\"\r\n\r\n"
            . "private%20&=\r\nkey\x00\r\n--" . $boundary
            . "\r\nContent-Disposition: form-data; name=\"empty\"\r\n\r\n\r\n--" . $boundary . "--\r\n", $body);
    }

    public function testOrderedMultipartStillRejectsNonStringAndExpandedListFields(): void
    {
        $cases = [['key', 7, true], ["key\r\nunsafe", 'secret', true], ['key', 'secret', false]];
        foreach ($cases as [$name, $value, $allowed]) {
            $fields = new OrderedFields();
            $fields->append('input', $name, $value, null, $allowed);
            try {
                MultipartEncoder::encode($fields);
                self::fail('Multipart restrictions still apply to ordered fields.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testEmptyMultipartEndsWithClosingBoundary(): void
    {
        [$type, $body] = MultipartEncoder::encode([]);
        $boundary = substr($type, strlen('multipart/form-data; boundary='));
        self::assertSame('--' . $boundary . "--\r\n", $body);
    }
}
