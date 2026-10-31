<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\StructuredJsonEncoder;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;

final class StructuredJsonEncoderTest extends TestCase
{
    private const INT = ['type' => 'int'];

    private const STRING = ['type' => 'string'];

    private const NUMBER = ['type' => 'number'];

    private const EMPTY_OBJECT = ['type' => 'object', 'fields' => []];

    public function testSchemaDeterminesEmptyObjectAndListKinds(): void
    {
        self::assertSame('{}', StructuredJsonEncoder::encode([], self::EMPTY_OBJECT));
        self::assertSame('[]', StructuredJsonEncoder::encode([], ['type' => 'list', 'items' => self::STRING]));
        $schema = ['type' => 'object', 'fields' => [
            'object' => self::EMPTY_OBJECT,
            'list' => ['type' => 'list', 'items' => self::EMPTY_OBJECT],
        ]];
        $json = StructuredJsonEncoder::encode(['object' => [], 'list' => [[], []]], $schema);
        self::assertSame('{"object":{},"list":[{},{}]}', $json);
        $tree = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertEquals((object) ['object' => (object) [], 'list' => [(object) [], (object) []]], $tree);
    }

    public function testPresenceNullAndStrictFalseZeroEmptyValuesRemainDistinct(): void
    {
        $schema = ['type' => 'object', 'fields' => [
            'absent' => self::STRING,
            'null' => ['type' => 'string', 'nullable' => true],
            'false' => ['type' => 'bool'],
            'flag' => ['type' => 'flag'],
            'zero' => self::INT,
            'text' => self::STRING,
            'empty' => self::STRING,
        ], 'required' => ['null', 'false', 'flag', 'zero', 'text', 'empty']];
        self::assertSame(
            '{"null":null,"false":false,"flag":0,"zero":0,"text":"0","empty":""}',
            StructuredJsonEncoder::encode(
                [
                'null' => null, 'false' => false, 'flag' => false, 'zero' => 0, 'text' => '0', 'empty' => '',
                ],
                $schema,
            ),
        );
        self::assertSame('null', StructuredJsonEncoder::encode(null, ['type' => 'null']));
        self::assertSame('null', StructuredJsonEncoder::encode(null, self::EMPTY_OBJECT + ['nullable' => true]));
        self::assertSame('1', StructuredJsonEncoder::encode(true, ['type' => 'flag']));
    }

    public function testKnownAndAdditionalFieldsPreserveOrderSpellingAndStringContents(): void
    {
        $schema = ['type' => 'object', 'fields' => ['known' => self::STRING], 'values' => self::STRING];
        $value = ["\0prefix" => 'nul-key', '01' => 'numeric spelling', ' leading ' => ' space ', 'known' => 'last'];
        $json = StructuredJsonEncoder::encode($value, $schema);
        $expected = <<<'JSON'
{"\u0000prefix":"nul-key","01":"numeric spelling"," leading ":" space ","known":"last"}
JSON;
        self::assertSame($expected, $json);
        self::assertSame($value, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testEncodingSensitiveStringsKeepUnicodeAndEmbeddedJsonBytes(): void
    {
        $value = "é\r\n\t\0\\\"/&=%{\"embedded\":true}é";
        $expected = <<<'JSON'
"\u00e9\r\n\t\u0000\\\"\/&=%{\"embedded\":true}e\u0301"
JSON;
        $json = StructuredJsonEncoder::encode($value, self::STRING);
        self::assertSame($expected, $json);
        self::assertSame($value, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testListsKeepOrderAndDuplicates(): void
    {
        self::assertSame('["z","a","z",""]', StructuredJsonEncoder::encode(
            ['z', 'a', 'z', ''],
            ['type' => 'list', 'items' => self::STRING],
        ));
    }

    /** @param array<mixed> $schema */
    #[DataProvider('invalidValues')]
    public function testInvalidValuesFailWithoutCoercion(mixed $value, array $schema): void
    {
        $this->expectException(InvalidArgumentException::class);
        StructuredJsonEncoder::encode($value, $schema);
    }

    /** @return iterable<string, array{mixed, array<mixed>}> */
    public static function invalidValues(): iterable
    {
        foreach (
            [
            ['int', '1'], ['int', 1.0], ['int', true], ['int', false], ['number', '1.5'],
            ['number', true], ['bool', 0], ['bool', 1], ['bool', '0'], ['flag', '1'], ['flag', 1.0],
            ['flag', 0], ['flag', 1], ['flag', 2], ['flag', -1], ['string', 0], ['string', false], ['null', 'null'],
            ['int', null], ['number', INF], ['number', -INF], ['number', NAN], ['string', "bad\xff"],
            ] as $i => [$type, $value]
        ) {
            yield 'scalar ' . $i => [$value, ['type' => $type]];
        }
        yield 'missing required' => [[], ['type' => 'object', 'fields' => ['x' => self::INT], 'required' => ['x']]];
        yield 'required nonnullable' => [
            ['x' => null], ['type' => 'object', 'fields' => ['x' => self::INT], 'required' => ['x']],
        ];
        yield 'unknown field' => [['x' => 1], self::EMPTY_OBJECT];
        yield 'invalid additional field late' => [
            ['ok' => 1, 'bad' => false], ['type' => 'object', 'fields' => [], 'values' => self::INT],
        ];
        yield 'integer object key' => [[0 => 'x'], ['type' => 'object', 'fields' => [], 'values' => self::STRING]];
        yield 'converted numeric string key' => [
            ['1' => 'x'], ['type' => 'object', 'fields' => [], 'values' => self::STRING],
        ];
        yield 'bad utf8 key' => [["bad\xff" => 'x'], ['type' => 'object', 'fields' => [], 'values' => self::STRING]];
        yield 'sparse list' => [[0 => 'a', 2 => 'b'], ['type' => 'list', 'items' => self::STRING]];
        yield 'associative list' => [['x' => 'a'], ['type' => 'list', 'items' => self::STRING]];
        yield 'invalid last list item' => [[1, 2, '3'], ['type' => 'list', 'items' => self::INT]];
        yield 'object scalar' => [(object) ['x' => 'secret'], self::STRING];
        yield 'object container' => [(object) [], self::EMPTY_OBJECT];
        yield 'array scalar' => [[], self::STRING];
        yield 'scalar container' => ['{}', self::EMPTY_OBJECT];
    }

    public function testObjectsAndResourcesAreRejectedWithoutCallingUserCode(): void
    {
        $object = new class implements JsonSerializable {
            public bool $called = false;

            public function jsonSerialize(): mixed
            {
                $this->called = true;
                return ['callback' => 'private'];
            }
        };
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        try {
            foreach ([$object, $resource] as $value) {
                try {
                    StructuredJsonEncoder::encode(
                        ['x' => $value],
                        ['type' => 'object', 'fields' => [], 'values' => self::STRING],
                    );
                    self::fail('Non-array objects and resources must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertFalse($object->called);
                }
            }
        } finally {
            fclose($resource);
        }
    }

    public function testUnambiguousScalarAlternativesUseStrictNativeTypes(): void
    {
        $schema = ['type' => 'union', 'anyOf' => [self::INT, self::STRING, ['type' => 'null']]];
        self::assertSame('1', StructuredJsonEncoder::encode(1, $schema));
        self::assertSame('"1"', StructuredJsonEncoder::encode('1', $schema));
        self::assertSame('null', StructuredJsonEncoder::encode(null, $schema));
        $this->expectException(InvalidArgumentException::class);
        StructuredJsonEncoder::encode(false, $schema);
    }

    /** @param array<mixed> $schema */
    #[DataProvider('invalidSchemas')]
    public function testWholeSchemaIsCheckedBeforeInspectingInput(array $schema): void
    {
        $this->expectException(LogicException::class);
        StructuredJsonEncoder::encode(null, $schema);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidSchemas(): iterable
    {
        foreach (
            [
            [], ['type' => 'mixed'], ['type' => 'map'], ['type' => 1],
            ['type' => 'int', 'accept' => ['numeric-string']],
            ['type' => 'number', 'preserve' => true], ['type' => 'int', 'nullable' => null],
            ['type' => 'int', 'nullable' => 1],
            ['type' => 'int', 'typo' => true], ['type' => 'string', 'fields' => []], ['type' => 'list'],
            ['type' => 'list', 'items' => false], ['type' => 'object'],
            ['type' => 'object', 'fields' => ['x' => false]],
            ['type' => 'object', 'fields' => [1 => self::INT]],
            ['type' => 'object', 'fields' => ["bad\xff" => self::INT]],
            ['type' => 'object', 'fields' => [], 'required' => ['missing']],
            ['type' => 'object', 'fields' => ['x' => self::INT], 'required' => ['x', 'x']],
            ['type' => 'object', 'fields' => [], 'required' => [1]],
            ['type' => 'object', 'fields' => [], 'values' => null],
            ['type' => 'object', 'fields' => ['x' => ['type' => 'mixed']], 'nullable' => true],
            ['type' => 'union', 'anyOf' => []], ['type' => 'union', 'anyOf' => [self::INT]],
            ['type' => 'union', 'anyOf' => [self::INT, self::INT]],
            ['type' => 'union', 'anyOf' => [self::INT, self::NUMBER]],
            ['type' => 'union', 'anyOf' => [['type' => 'bool'], ['type' => 'flag']]],
            ['type' => 'union', 'anyOf' => [['type' => 'string', 'nullable' => true], ['type' => 'null']]],
            ['type' => 'union', 'anyOf' => [self::EMPTY_OBJECT, ['type' => 'list', 'items' => self::INT]]],
            ['type' => 'union', 'anyOf' => [self::STRING, self::EMPTY_OBJECT]],
            ['type' => 'union', 'anyOf' => ['x' => self::INT, 'y' => self::STRING]],
            ] as $i => $schema
        ) {
            yield 'schema ' . $i => [$schema];
        }
    }

    public function testUnavailableFloatEncodingSettingFailsClosed(): void
    {
        $code = <<<'PHP_CODE'
require 'vendor/autoload.php';
try {
    HostingPal\WhmApiClient\Api\Engine\StructuredJsonEncoder::encode(1.2345678901234567, ['type' => 'number']);
    echo 'unexpected success';
} catch (LogicException $error) {
    echo 'safe failure';
}
PHP_CODE;
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=ini_set', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr === false ? '' : $stderr);
        self::assertSame('safe failure', $stdout);
        self::assertSame('', $stderr);
    }

    public function testFiniteFloatsPreserveValuesAndKindsUnderHostPrecisionAndLocale(): void
    {
        $serializePrecision = ini_get('serialize_precision');
        $precision = ini_get('precision');
        $locale = setlocale(LC_NUMERIC, '0');
        self::assertIsString($serializePrecision);
        self::assertIsString($locale);
        try {
            foreach (['C', 'de_DE.utf8', 'fr_FR.utf8'] as $candidate) {
                if (setlocale(LC_NUMERIC, $candidate) === false) {
                    continue;
                }
                foreach (['3', '17', '100'] as $setting) {
                    ini_set('serialize_precision', $setting);
                    ini_set('precision', '3');
                    $json = StructuredJsonEncoder::encode(
                        [1.0, 1.2345678901234567, -0.0, PHP_FLOAT_MIN, PHP_FLOAT_MAX, 5.0e-324],
                        ['type' => 'list', 'items' => self::NUMBER],
                    );
                    $expected = '[1.0,1.2345678901234567,-0.0,'
                        . '2.2250738585072014e-308,1.7976931348623157e+308,5.0e-324]';
                    self::assertSame($expected, $json);
                    self::assertSame(
                        [1.0, 1.2345678901234567, -0.0, PHP_FLOAT_MIN, PHP_FLOAT_MAX, 5.0e-324],
                        json_decode($json, true, 512, JSON_THROW_ON_ERROR),
                    );
                    self::assertSame($setting, ini_get('serialize_precision'));
                    self::assertSame('3', ini_get('precision'));
                    self::assertSame($candidate, setlocale(LC_NUMERIC, '0'));
                    foreach ([INF, "invalid\xff"] as $invalid) {
                        try {
                            StructuredJsonEncoder::encode(
                                ['first' => 1.2345678901234567, 'last' => $invalid],
                                ['type' => 'object', 'fields' => [], 'values' => ['type' => 'union',
                                'anyOf' => [
                                self::NUMBER,
                                self::STRING]]],
                            );
                            self::fail('Late invalid input must fail.');
                        } catch (InvalidArgumentException) {
                            self::assertSame($setting, ini_get('serialize_precision'));
                            self::assertSame('3', ini_get('precision'));
                            self::assertSame($candidate, setlocale(LC_NUMERIC, '0'));
                        }
                    }
                }
            }
        } finally {
            ini_set('serialize_precision', $serializePrecision);
            ini_set('precision', $precision);
            setlocale(LC_NUMERIC, $locale);
        }
    }

    public function testDepthBudgetMatchesNativeValidationForListsAndObjects(): void
    {
        foreach (['list', 'object'] as $kind) {
            foreach ([511, 512, 513] as $nesting) {
                foreach ([false, true] as $emptyLeaf) {
                    $schema = $emptyLeaf ? self::EMPTY_OBJECT : self::INT;
                    $value = $emptyLeaf ? [] : 7;
                    $wrappers = $emptyLeaf ? $nesting - 1 : $nesting;
                    for ($depth = 0; $depth < $wrappers; $depth++) {
                        if ($kind === 'list') {
                            $schema = ['type' => 'list', 'items' => $schema];
                            $value = [$value];
                        } else {
                            $schema = ['type' => 'object', 'fields' => ['x' => $schema], 'required' => ['x']];
                            $value = ['x' => $value];
                        }
                    }
                    $prefix = $kind === 'list' ? '[' : '{"x":';
                    $suffix = $kind === 'list' ? ']' : '}';
                    $literal = str_repeat($prefix, $wrappers) . ($emptyLeaf ? '{}' : '7')
                        . str_repeat($suffix, $wrappers);
                    self::assertSame($nesting === 511, json_validate($literal, 512));
                    if ($nesting === 511) {
                        self::assertSame($literal, StructuredJsonEncoder::encode($value, $schema));
                        continue;
                    }
                    try {
                        StructuredJsonEncoder::encode($value, $schema);
                        self::fail('Over-budget schemas must fail, even for an empty deepest container.');
                    } catch (LogicException $error) {
                        self::assertNull($error->getPrevious());
                    }
                }
            }
        }
    }

    public function testNativeEncoderAndValidatorHaveDifferentDepthCounting(): void
    {
        foreach ([511, 512, 513] as $nesting) {
            $literal = str_repeat('[', $nesting) . '7' . str_repeat(']', $nesting);
            $native = 7;
            for ($i = 0; $i < $nesting; $i++) {
                $native = [$native];
            }
            self::assertSame($nesting === 511, json_validate($literal, 512));
            if ($nesting <= 512) {
                self::assertSame($literal, json_encode($native, JSON_THROW_ON_ERROR, 512));
            } else {
                $this->expectException(JsonException::class);
                json_encode($native, JSON_THROW_ON_ERROR, 512);
            }
        }
    }

    public function testScalarAlternativesDoNotConsumeContainerBudget(): void
    {
        $schema = ['type' => 'union', 'anyOf' => [self::INT, self::STRING]];
        $value = 7;
        for ($depth = 0; $depth < 511; $depth++) {
            $schema = ['type' => 'list', 'items' => $schema];
            $value = [$value];
        }
        self::assertSame(
            str_repeat('[', 511) . '7' . str_repeat(']', 511),
            StructuredJsonEncoder::encode($value, $schema),
        );
    }

    public function testCyclicValuesAndSchemasTerminateSafely(): void
    {
        $value = [];
        $value['self'] = &$value;
        $schema = ['type' => 'object', 'fields' => [], 'values' => self::STRING];
        try {
            StructuredJsonEncoder::encode($value, $schema);
            self::fail('Cyclic input must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($error->getPrevious());
        }
        $schema = ['type' => 'list'];
        $schema['items'] = &$schema;
        $this->expectException(LogicException::class);
        StructuredJsonEncoder::encode([], $schema);
    }

    public function testSensitiveFramesAndDiagnosticsDoNotExposeDynamicKeysOrValues(): void
    {
        $old = ini_get('zend.exception_ignore_args');
        self::assertIsString($old);
        ini_set('zend.exception_ignore_args', '0');
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        try {
            $cases = [
                [['private-key' => 'private-value'], self::EMPTY_OBJECT],
                [
            ['private-key' => "private-value\xff"],
                    ['type' => 'object', 'fields' => [], 'values' => self::STRING]],
                [
                ['private-key' => 'private-value'],
                    ['type' => 'object', 'fields' => ['private-schema' => ['type' => 'unsupported']]]],
                [
                ['private-key' => ['nested' => 'private-value']],
                    ['type' => 'object', 'fields' => [], 'values' => ['type' => 'list', 'items' => self::INT]]],
            ];
            foreach ($cases as [$value, $schema]) {
                try {
                    StructuredJsonEncoder::encode($value, $schema);
                    self::fail('Invalid synthetic secret input must fail.');
                } catch (InvalidArgumentException | LogicException $error) {
                    self::assertNull($error->getPrevious());
                    self::assertStringNotContainsString('private-', $error->getMessage());
                    $frames = 0;
                    foreach ($error->getTrace() as $frame) {
                        $class = $frame['class'] ?? '';
                        if (!str_starts_with($class, 'HostingPal\\WhmApiClient\\Api\\Engine\\')) {
                            continue;
                        }
                        $frames++;
                        foreach ($frame['args'] ?? [] as $argument) {
                            if ($argument instanceof SensitiveParameterValue) {
                                continue;
                            }
                            self::assertStringNotContainsString('private-', serialize($argument));
                        }
                    }
                    self::assertGreaterThan(0, $frames);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $old);
        }
    }
}
