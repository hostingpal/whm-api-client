<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonFieldInputTest extends TestCase
{
    public function testExplicitNullAndOmissionAreDistinct(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json',
            'json' => ['required' => true, 'schema' => ['type' => 'string', 'nullable' => true]]]];
        self::assertSame('configuration=null', ArgumentEncoder::encode($params, ['value' => null])->encode());
        self::assertSame('configuration=%22null%22', ArgumentEncoder::encode($params, ['value' => 'null'])->encode());
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode($params, []);
    }

    public function testFieldRootRoutingNamesAreData(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json', 'json' => ['required' => true,
            'schema' => ['type' => 'object', 'fields' => ['api.version' => ['type' => 'string']],
                'required' => ['api.version']]]]];
        SpecChecker::check(['function' => 'example', 'method' => 'POST', 'body' => 'form', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
        self::assertSame(
            'configuration=%7B%22api.version%22%3A%22data%22%7D',
            ArgumentEncoder::encode($params, ['value' => ['api.version' => 'data']])->encode(),
        );
    }

    public function testOptionalNonnullableFieldOmission(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json',
            'json' => ['required' => false, 'schema' => ['type' => 'object', 'fields' => []]]]];
        self::assertSame('', ArgumentEncoder::encode($params, [])->encode());
        self::assertSame('', ArgumentEncoder::encode($params, ['value' => null])->encode());
        self::assertSame('configuration=%7B%7D', ArgumentEncoder::encode($params, ['value' => []])->encode());
    }

    public function testSerializedValueStaysSensitiveAtCollisionFailure(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json', 'json' => ['required' => true,
            'schema' => ['type' => 'object', 'fields' => ['label' => ['type' => 'string']]]]],
            'mapped' => ['name' => 'mapped', 'encoding' => 'collection', 'collection' => [
                'kind' => 'map', 'item_type' => 'string', 'allow_scalar' => false, 'empty' => 'allow', 'prefix' => '',
            ]]];
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            ArgumentEncoder::encode($params, ['mapped' => ['configuration' => 'secret-value'],
                'value' => ['label' => 'secret-value']]);
            self::fail('Colliding field accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret-value', (string) $exception);
            self::assertNull($exception->getPrevious());
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /** @param array<mixed> $descriptor */
    #[DataProvider('malformedDescriptors')]
    public function testMalformedDescriptorsFailWithoutArguments(array $descriptor): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json', 'json' => $descriptor]];
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'example', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
    }

    /** @return iterable<array{array<mixed>}> */
    public static function malformedDescriptors(): iterable
    {
        yield [[]];
        yield [['required' => true, 'schema' => ['type' => 'mixed']]];
        yield [['required' => false, 'schema' => ['type' => 'string', 'nullable' => true]]];
        yield [['required' => 'yes', 'schema' => ['type' => 'string']]];
        yield [['required' => true, 'schema' => ['type' => 'string'], 'routes' => 'direct']];
    }
}
