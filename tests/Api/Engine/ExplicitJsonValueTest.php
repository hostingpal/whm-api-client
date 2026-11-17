<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\JsonFieldEncoder;
use HostingPal\WhmApiClient\Api\Engine\StructuredJsonEncoder;
use HostingPal\WhmApiClient\Api\Engine\StructuredJsonSchema;
use HostingPal\WhmApiClient\JsonList;
use HostingPal\WhmApiClient\JsonObject;
use HostingPal\WhmApiClient\Tests\Fixtures\StrictJsonCaller;
use HostingPal\WhmApiClient\Tests\Fixtures\WeakJsonCaller;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use TypeError;

final class ExplicitJsonValueTest extends TestCase
{
    private const JSON = ['type' => 'json'];

    private const OPEN = ['type' => 'object', 'fields' => ['known' => ['type' => 'int']],
        'required' => ['known'], 'values' => self::JSON];

    public function testExplicitKindsPreserveValuesNamesAndOrder(): void
    {
        $value = JsonObject::fromEntries([
            ['name' => '0', 'value' => JsonObject::fromArray([])],
            ['name' => '01', 'value' => JsonList::fromArray([])],
            ['name' => "\0name", 'value' => JsonList::fromArray([null, false, 0, 1.0, '', '{"a":1}'])],
            ['name' => '', 'value' => JsonObject::fromArray(['z' => 'last', 'a' => 'first'])],
        ]);
        self::assertSame(
            '{"0":{},"01":[],"\\u0000name":[null,false,0,1.0,"","{\\"a\\":1}"],"":{"z":"last","a":"first"}}',
            StructuredJsonEncoder::encode($value, self::JSON),
        );
        self::assertSame('null', StructuredJsonEncoder::encode(null, self::JSON));
        self::assertTrue(StructuredJsonSchema::from(self::JSON)->acceptsNull());
        self::assertSame('-0.0', StructuredJsonEncoder::encode(-0.0, self::JSON));
        self::assertSame('1.2345678901234567', StructuredJsonEncoder::encode(1.2345678901234567, self::JSON));
    }

    public function testOpenObjectRetainsKnownFieldsAndAcceptsExplicitNumericNames(): void
    {
        self::assertSame('{"known":7,"x":[]}', StructuredJsonEncoder::encode(
            ['known' => 7, 'x' => JsonList::fromArray([])],
            self::OPEN,
        ));
        self::assertSame('{"0":{},"known":7}', StructuredJsonEncoder::encode(JsonObject::fromEntries([
            ['name' => '0', 'value' => JsonObject::fromArray([])], ['name' => 'known', 'value' => 7],
        ]), self::OPEN));
        foreach (
            [JsonObject::fromArray([]), JsonObject::fromArray(['known' => '7']),
            JsonObject::fromArray(['known' => null]), JsonObject::fromArray(['known' => 7, 'x' => []])] as $bad
        ) {
            $this->assertInvalid($bad, self::OPEN);
        }
        foreach (
            [
            ['type' => 'object', 'fields' => []],
            ['type' => 'object', 'fields' => [], 'values' => ['type' => 'int']],
            ['type' => 'list', 'items' => self::JSON]] as $schema
        ) {
            $this->assertInvalid(JsonObject::fromArray([]), $schema);
            $this->assertInvalid(JsonList::fromArray([]), $schema);
        }
    }

    public function testFactoriesDeferMalformedContentToEncodingAndRetainNativeArrayBinding(): void
    {
        foreach (
            [
                StrictJsonCaller::factory(JsonObject::class, 'fromArray', [0 => 'x']),
                StrictJsonCaller::factory(JsonObject::class, 'fromEntries', ['x' => ['name' => 'a', 'value' => 1]]),
                StrictJsonCaller::factory(JsonObject::class, 'fromEntries', [['name' => 'x']]),
                StrictJsonCaller::factory(JsonObject::class, 'fromEntries', [['value' => 1]]),
                JsonObject::fromEntries([['name' => 'x', 'value' => 1, 'extra' => true]]),
                StrictJsonCaller::factory(JsonObject::class, 'fromEntries', [['name' => 0, 'value' => 1]]),
                StrictJsonCaller::factory(JsonObject::class, 'fromEntries', [false]),
                JsonObject::fromEntries([['name' => 'x', 'value' => 1], ['name' => 'x', 'value' => 2]]),
                StrictJsonCaller::factory(JsonList::class, 'fromArray', [1 => 'x']),
                StrictJsonCaller::factory(JsonList::class, 'fromArray', ['x' => 1]),
            ] as $bad
        ) {
            $this->assertInvalid($bad, self::JSON);
        }
        foreach ([StrictJsonCaller::class, WeakJsonCaller::class] as $caller) {
            foreach (
                [
                [JsonObject::class, 'fromArray'], [JsonObject::class, 'fromEntries'],
                [JsonList::class, 'fromArray']] as [$class, $method]
            ) {
                $caught = null;
                try {
                    $caller::factory($class, $method, 'not-array');
                } catch (TypeError $error) {
                    $caught = $error;
                }
                self::assertNotNull($caught, 'Factories must retain native array type checks in both caller modes.');
            }
        }
    }

    public function testUnrestrictedValuesRejectBareArraysInvalidTextAndCallbacks(): void
    {
        $object = new class implements JsonSerializable {
            public bool $called = false;

            public function jsonSerialize(): mixed
            {
                $this->called = true;
                return [];
            }
        };
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        try {
            foreach (
                [
                [], ['x' => 1], $object, (object) [], $resource, INF, -INF, NAN, "secret-value\xff",
                JsonObject::fromArray(["secret-name\xff" => 1]), JsonList::fromArray([[]])] as $bad
            ) {
                $this->assertInvalid($bad, self::JSON);
            }
            self::assertFalse($object->called);
        } finally {
            fclose($resource);
        }
    }

    public function testExplicitAndKnownContainersShareOneDepthBudget(): void
    {
        foreach ([510, 511, 512] as $depth) {
            foreach ([false, true] as $knownOuter) {
                $value = 7;
                $literal = '7';
                for ($i = 0; $i < $depth - ($knownOuter ? 1 : 0); $i++) {
                    $value = $i % 2 === 0 ? JsonList::fromArray([$value]) : JsonObject::fromArray(['x' => $value]);
                    $literal = $i % 2 === 0 ? '[' . $literal . ']' : '{"x":' . $literal . '}';
                }
                $schema = self::JSON;
                if ($knownOuter) {
                    $value = [$value];
                    $schema = ['type' => 'list', 'items' => self::JSON];
                    $literal = '[' . $literal . ']';
                }
                if ($depth <= 511) {
                    self::assertSame($literal, StructuredJsonEncoder::encode($value, $schema));
                } else {
                    $this->assertInvalid($value, $schema);
                }
            }
        }
    }


    public function testEmptyExplicitContainersConsumeDepthIncludingKnownOuterObjects(): void
    {
        foreach ([JsonObject::fromArray([]), JsonList::fromArray([])] as $leaf) {
            foreach ([510, 511, 512] as $depth) {
                $value = $leaf;
                $literal = $leaf instanceof JsonObject ? '{}' : '[]';
                for ($i = 0; $i < $depth - 2; $i++) {
                    $value = JsonList::fromArray([$value]);
                    $literal = '[' . $literal . ']';
                }
                $schema = ['type' => 'object', 'fields' => ['value' => self::JSON]];
                if ($depth <= 511) {
                    self::assertSame(
                        '{"value":' . $literal . '}',
                        StructuredJsonEncoder::encode(['value' => $value], $schema),
                    );
                } else {
                    $this->assertInvalid(['value' => $value], $schema);
                }
            }
        }
    }

    public function testExternalReferencesAndCyclesAreBoundedWithoutSnapshotClaims(): void
    {
        $leaf = 1;
        $value = JsonList::fromArray([&$leaf]);
        $leaf = 2;
        self::assertSame('[2]', StructuredJsonEncoder::encode($value, self::JSON));
        $cycle = null;
        $cycle = JsonList::fromArray([&$cycle]);
        $this->assertInvalid($cycle, self::JSON);
        $external = [];
        $wrapped = JsonObject::fromArray(['next' => &$external]);
        $external['next'] = $wrapped;
        $this->assertInvalid($wrapped, self::JSON);
        $entry = ['name' => 'next', 'value' => null];
        $entry['value'] = JsonObject::fromEntries([&$entry]);
        $this->assertInvalid($entry['value'], self::JSON);
        $array = [];
        $array['self'] = &$array;
        $this->assertInvalid(JsonObject::fromArray($array), self::JSON);
    }


    public function testIntrinsicNullRetainsFieldOmissionAmbiguityChecks(): void
    {
        foreach ([false, true] as $nullable) {
            self::assertSame('null', JsonFieldEncoder::encode([
                'required' => true, 'schema' => ['type' => 'json', 'nullable' => $nullable],
            ], true, null));
            try {
                JsonFieldEncoder::check(['required' => false, 'schema' => ['type' => 'json', 'nullable' => $nullable]]);
                self::fail('Intrinsic JSON null must conflict with optional null omission.');
            } catch (LogicException $error) {
                self::assertSame(LogicException::class, $error::class);
            }
        }
        self::assertSame('{}', JsonFieldEncoder::encode([
            'required' => false, 'schema' => ['type' => 'object', 'fields' => [], 'values' => self::JSON],
        ], true, JsonObject::fromArray([])));
        self::assertNull(JsonFieldEncoder::encode([
            'required' => false, 'schema' => ['type' => 'object', 'fields' => [], 'values' => self::JSON],
        ], true, null));
    }

    public function testJsonSchemaIsStrictAndCannotJoinScalarUnions(): void
    {
        foreach (
            [
            ['type' => 'json', 'items' => self::JSON], ['type' => 'json', 'nonempty' => true],
            ['type' => 'union', 'anyOf' => [self::JSON, ['type' => 'int']]],
            ['type' => 'union', 'anyOf' => [['type' => 'null'], self::JSON]]] as $schema
        ) {
            try {
                StructuredJsonEncoder::encode(null, $schema);
                self::fail('Invalid descriptor accepted.');
            } catch (LogicException $error) {
                self::assertSame(LogicException::class, $error::class);
            }
        }
    }

    public function testFailedNestedValuesAndNamesAreSensitiveInEveryLibraryFrame(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (
                [JsonObject::fromEntries([['name' => 'secret-name', 'value' => "secret-value\xff"]]),
                JsonObject::fromEntries([['name' => "secret-name\xff", 'value' => 'secret-value']]),
                JsonList::fromArray([JsonObject::fromArray(['secret-name' => ['secret-value']])])] as $bad
            ) {
                try {
                    StructuredJsonEncoder::encode($bad, self::JSON);
                    self::fail('Invalid secret input accepted.');
                } catch (InvalidArgumentException $error) {
                    self::assertStringNotContainsString('secret-', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    foreach ($error->getTrace() as $frame) {
                        if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                            continue;
                        }
                        foreach ($frame['args'] ?? [] as $argument) {
                            if (!$argument instanceof SensitiveParameterValue) {
                                self::assertStringNotContainsString('secret-', serialize($argument));
                            }
                        }
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /** @param array<mixed> $schema */
    private function assertInvalid(mixed $value, array $schema): void
    {
        try {
            StructuredJsonEncoder::encode($value, $schema);
            self::fail('Invalid JSON value accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($error->getPrevious());
        }
    }
}
