# Generated Client, Plan 1: Runtime Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the hand-written runtime engine that generated operation groups will call: argument encoding, response shape validation and normalization, unwrap/void handling, and a single `run()` entry point.

**Architecture:** Generated group methods pass a compact per-operation spec (a PHP class constant) and their named arguments to `OperationRunner::run()`. The runner encodes arguments into wire parameters, hands them to a handle-supplied dispatch closure (routing, authentication and the existing request lifecycle), and validates the returned payload against the spec with `ShapeValidator`. Nothing in this plan generates code or changes public groups; plan 2 builds the generator and plan 3 switches the handles over.

**Tech Stack:** PHP 8.3, PHPUnit 12, PHPStan (max level), PHPCS (PSR-12 + selected Slevomat rules), php-http/mock-client and nyholm/psr7 for HTTP doubles.

**Spec:** `docs/superpowers/specs/2026-10-14-generated-client-design.md` (sections "Generated PHP and the runtime engine", "Public API surface → Parameters / Returns", "Testing and quality gates" item 1).

**Prerequisite:** Pull request #1 (`feat/account-lifecycle-slice`: form-encoded POST, `OutcomePolicy`, `call()`, `PayloadReader::json()`) is merged into `master`. Work on a branch `feat/generated-client-engine` created from up-to-date `master`.

## Global Constraints

- PHP `^8.3`, `declare(strict_types=1);` in every PHP file, PSR-12 plus the selected Slevomat rules in `phpcs.xml.dist`.
- PHPStan maximum level must pass with no ignores and no baseline entries.
- The core stays framework-independent: PSR-18/PSR-17 only, no new runtime dependencies.
- Routine tests stay offline; no live server calls in this plan.
- The repository is public: no credentials, tokens or live payload values in code, tests or fixtures.
- Request lifecycle rule (unchanged): metadata is cleared before any parameter validation or I/O; groups and the engine never validate caller input before the dispatch closure runs. Only generator defects (spec/argument mismatches) may throw before dispatch, and they throw `LogicException`.
- Returns are plain arrays and scalars; no DTO classes.
- Every new production class and public method gets PHPDoc (summary plus description); internal classes are marked `@internal`.
- Until plan 3 retires it, every new public PHP symbol must be classified in `docs/api/coverage/registry.json` and `composer api-map:check` must pass.

## Review Focus

- **Empty JSON array for an object or map.** cPanel often encodes an empty object as `[]`. A reader expects an empty object/map to validate as `[]`, not fail. Pinned in Task 2 (`testEmptyJsonArrayIsAnEmptyObjectOrMap`).
- **Numeric-looking map keys.** `{"0": 1, "10": 0}` decodes with integer PHP keys; the map must keep them, not fail or renumber. Pinned in Task 2 (`testMapKeepsNumericKeys`).
- **Integers beyond `PHP_INT_MAX` sent as strings.** A `numeric-string` accept rule must reject `"99999999999999999999"` instead of silently saturating. Pinned in Task 1 (`invalidScalars` row `int overflow string`).
- **Union branch that fails deep inside.** A union whose first branch matches the outer object but fails on a nested field must fall through to the next branch with no partial result. Pinned in Task 2 (`testUnionFallsThroughDeepFailures`).
- **Explicit `null` versus omitted arguments.** Passing `null` explicitly must produce exactly the same wire parameters as omitting the argument. Pinned in Task 3 (`testExplicitNullEqualsOmission`).
- **Omitted versus explicit-null payload.** When a payload is declared, a response without a `data` member is invalid even if the spec is nullable; only an explicit `null` is accepted. Pinned in Task 4 (`testOmittedPayloadIsInvalidEvenWhenNullable`).

---

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Api/RepeatedList.php` (create) | Marker for repeated-key multi-value parameters, validated by the shared encoder after the metadata reset (mirrors `NumberedList`). |
| `src/Api/AbstractApiClient.php` (modify) | Shared encoder accepts `RepeatedList`; `Parameters` alias gains it. |
| `src/Response/ShapeValidator.php` (create) | Validate and normalize a decoded JSON value against a type spec. |
| `src/Api/Engine/ArgumentEncoder.php` (create) | Turn generated-method arguments into wire `Parameters`. |
| `src/Api/Engine/OperationSpec.php` (create) | Document the generated spec format; map spec strings to `RequestMethod` / `OutcomePolicy`. |
| `src/Api/Engine/OperationRunner.php` (create) | `run()`: encode → dispatch → result; `result()`: void, presence, validation, unwrap. |
| `tests/Support/WhmQueryClient.php` (modify) | Accept `RepeatedList` in its test query. |
| `tests/Api/QueryEncodingTest.php` (modify) | `RepeatedList` encoding and post-reset validation. |
| `tests/Response/ShapeValidatorTest.php` (create) | Validator unit tests. |
| `tests/Api/Engine/ArgumentEncoderTest.php` (create) | Encoder unit tests. |
| `tests/Api/Engine/OperationRunnerTest.php` (create) | Runner and spec helper tests. |
| `docs/api/coverage/registry.json`, `symbols.json`, `report.json` (modify) | Classify the new public symbols (temporary until plan 3). |

### Spec format (shared contract with plan 2)

An operation spec is a constant array:

```php
[
    'function' => 'list_domains',          // wire function name (WHM may contain "/")
    'module' => 'DomainInfo',              // UAPI only
    'method' => 'GET',                     // 'GET' | 'POST'
    'outcome' => 'flag',                   // 'flag' | 'unchecked'
    'params' => [                          // keyed by PHP argument name, in signature order
        'hideTemporaryDomains' => ['name' => 'hide_temporary_domains', 'encoding' => 'bool'],
    ],
    'returns' => [...type spec...],        // null means the method returns void
    'unwrap' => 'primary_domain',          // optional: return this field of the validated object
]
```

Parameter encodings: `scalar` (string|int|float; float sent as string), `bool` (`true`→`1`, `false`→`0`), `repeat` (`list<string>` as repeated keys via `RepeatedList`), `numbered` (`string|list<string>` via `NumberedList`).

A type spec is an array with a `type` key and optional modifiers:

| `type` | Extra keys | Valid input → output |
| --- | --- | --- |
| `string` | — | string → string |
| `int` | `accept: ['numeric-string']` | int; canonical decimal string within int range → int |
| `number` | `accept: ['numeric-string']`, `preserve: true` | int or finite float; decimal string → int/float, or kept as string when `preserve` |
| `bool` | `accept: ['int-flag', 'string-flag']` | bool; `0`/`1`; `'0'`/`'1'` → bool |
| `flag` | `accept: ['string-flag', 'bool']` | `0`/`1`; `'0'`/`'1'`; bool → `0`/`1` |
| `mixed` | — | any JSON → arrays/scalars (`PayloadReader::json()`) |
| `list` | `items` | JSON list → list |
| `map` | `values` | JSON object (or `[]`) → `array<array-key, T>` |
| `object` | `fields` (name → spec, declared order), `required` (names) | JSON object (or `[]` when nothing is required) → array of present declared fields; unknown fields dropped |
| `union` | `anyOf` (list of specs) | first matching branch |

Any spec may carry `nullable: true`, which accepts JSON `null`.

---

### Task 1: ShapeValidator scalars

**Files:**
- Create: `src/Response/ShapeValidator.php`
- Test: `tests/Response/ShapeValidatorTest.php`

**Interfaces:**
- Consumes: `PayloadReader` (`fail(): never`, `string(mixed): string`, `json(mixed): mixed`, `object(mixed): stdClass`, `listOf(mixed, callable): list`), `InvalidResponseException`.
- Produces: `ShapeValidator::value(mixed $value, array $type, PayloadReader $read): mixed` where `$type` is `array<mixed>`; throws `InvalidResponseException` (via `$read->fail()`) on mismatch and `LogicException` on a malformed spec.

- [ ] **Step 1: Write the failing test**

Create `tests/Response/ShapeValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShapeValidatorTest extends TestCase
{
    /** @param array<mixed> $type */
    #[DataProvider('validScalars')]
    public function testAcceptsAndNormalizesScalars(string $json, array $type, mixed $expected): void
    {
        self::assertSame($expected, ShapeValidator::value(self::decode($json), $type, self::reader()));
    }

    /** @return iterable<string, array{string, array<mixed>, mixed}> */
    public static function validScalars(): iterable
    {
        yield 'string' => ['"a"', ['type' => 'string'], 'a'];
        yield 'empty string' => ['""', ['type' => 'string'], ''];
        yield 'int' => ['5', ['type' => 'int'], 5];
        yield 'int from numeric string' => ['"42"', ['type' => 'int', 'accept' => ['numeric-string']], 42];
        yield 'negative int from numeric string' => ['"-7"', ['type' => 'int', 'accept' => ['numeric-string']], -7];
        yield 'number int' => ['3', ['type' => 'number'], 3];
        yield 'number float' => ['1.5', ['type' => 'number'], 1.5];
        yield 'number from decimal string' => ['"0.50"', ['type' => 'number', 'accept' => ['numeric-string']], 0.5];
        yield 'number from integer string' => ['"3"', ['type' => 'number', 'accept' => ['numeric-string']], 3];
        yield 'number preserved string' => [
            '"0.50"',
            ['type' => 'number', 'accept' => ['numeric-string'], 'preserve' => true],
            '0.50',
        ];
        yield 'bool' => ['true', ['type' => 'bool'], true];
        yield 'bool from int flag' => ['1', ['type' => 'bool', 'accept' => ['int-flag']], true];
        yield 'bool from string flag' => ['"0"', ['type' => 'bool', 'accept' => ['string-flag']], false];
        yield 'flag' => ['1', ['type' => 'flag'], 1];
        yield 'flag from string flag' => ['"1"', ['type' => 'flag', 'accept' => ['string-flag']], 1];
        yield 'flag from bool' => ['false', ['type' => 'flag', 'accept' => ['bool']], 0];
        yield 'mixed' => ['{"a":[1,{"b":2}]}', ['type' => 'mixed'], ['a' => [1, ['b' => 2]]]];
        yield 'nullable null' => ['null', ['type' => 'string', 'nullable' => true], null];
    }

    /** @param array<mixed> $type */
    #[DataProvider('invalidScalars')]
    public function testRejectsInvalidScalarsWithTheResponseSnapshot(string $json, array $type): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            ShapeValidator::value(self::decode($json), $type, new PayloadReader($metadata, 'example payload'));
            self::fail('Expected an invalid payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertSame('Invalid example payload.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidScalars(): iterable
    {
        yield 'int as string' => ['1', ['type' => 'string']];
        yield 'null without nullable' => ['null', ['type' => 'string']];
        yield 'numeric string without accept' => ['"42"', ['type' => 'int']];
        yield 'leading zero string' => ['"042"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'exponent string' => ['"1e3"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'int overflow string' => ['"99999999999999999999"', ['type' => 'int', 'accept' => ['numeric-string']]];
        yield 'float as int' => ['1.5', ['type' => 'int']];
        yield 'number from text' => ['"abc"', ['type' => 'number', 'accept' => ['numeric-string']]];
        yield 'number exponent string' => ['"1e5"', ['type' => 'number', 'accept' => ['numeric-string']]];
        yield 'bool from string without accept' => ['"1"', ['type' => 'bool']];
        yield 'flag out of range' => ['2', ['type' => 'flag']];
        yield 'flag string without accept' => ['"1"', ['type' => 'flag']];
        yield 'flag bool without accept' => ['true', ['type' => 'flag']];
    }

    public function testRejectsMalformedSpecsAsGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        ShapeValidator::value('a', ['type' => 'unknown'], self::reader());
    }

    private static function reader(): PayloadReader
    {
        return new PayloadReader(new ResponseMetadata(200), 'example payload');
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ShapeValidatorTest`
Expected: FAIL with `Error: Class "HostingPal\WhmApiClient\Response\ShapeValidator" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Response/ShapeValidator.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use LogicException;

/**
 * Validate and normalize a decoded JSON payload against a generated type spec.
 *
 * Type specs are generated by tools/api-map from the pinned schema plus the
 * evidence-bound overlay (see Api\Engine\OperationSpec for the format). Values
 * are validated without guessing: an observed wire variant is accepted only when
 * the spec lists it in "accept", and it is then normalized to the declared type
 * when that is lossless. Every mismatch raises InvalidResponseException through
 * the supplied reader, so the response snapshot travels with the failure. A
 * malformed spec is a generator defect and raises LogicException.
 *
 * @internal
 */
final class ShapeValidator
{
    /**
     * Validate one value and return its normalized form.
     *
     * @param array<mixed> $type
     * @throws InvalidResponseException If the value does not satisfy the spec.
     * @throws LogicException If the spec itself is malformed.
     */
    public static function value(mixed $value, array $type, PayloadReader $read): mixed
    {
        if ($value === null) {
            return ($type['nullable'] ?? false) === true ? null : $read->fail();
        }

        $accept = self::accept($type);

        return match ($type['type'] ?? null) {
            'string' => $read->string($value),
            'int' => self::int($value, $accept, $read),
            'number' => self::number($value, $accept, ($type['preserve'] ?? false) === true, $read),
            'bool' => self::bool($value, $accept, $read),
            'flag' => self::flag($value, $accept, $read),
            'mixed' => $read->json($value),
            default => throw new LogicException('Unsupported type spec.'),
        };
    }

    /**
     * Read the accepted wire variants of a spec.
     *
     * @param array<mixed> $type
     * @return list<string>
     */
    private static function accept(array $type): array
    {
        $accept = $type['accept'] ?? [];

        return is_array($accept) ? array_values(array_filter($accept, is_string(...))) : [];
    }

    /** @param list<string> $accept */
    private static function int(mixed $value, array $accept, PayloadReader $read): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (
            in_array('numeric-string', $accept, true)
            && is_string($value)
            && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        return $read->fail();
    }

    /** @param list<string> $accept */
    private static function number(mixed $value, array $accept, bool $preserve, PayloadReader $read): int|float|string
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }

        if (
            in_array('numeric-string', $accept, true)
            && is_string($value)
            && preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/', $value) === 1
            && is_finite((float) $value)
        ) {
            if ($preserve) {
                return $value;
            }

            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $read->fail();
    }

    /** @param list<string> $accept */
    private static function bool(mixed $value, array $accept, PayloadReader $read): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array('int-flag', $accept, true) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (in_array('string-flag', $accept, true) && ($value === '0' || $value === '1')) {
            return $value === '1';
        }

        return $read->fail();
    }

    /**
     * Normalize an integer flag from its accepted wire variants.
     *
     * @param list<string> $accept
     * @return 0|1
     */
    private static function flag(mixed $value, array $accept, PayloadReader $read): int
    {
        if ($value === 0 || $value === 1) {
            return $value;
        }

        if (in_array('string-flag', $accept, true) && ($value === '0' || $value === '1')) {
            return $value === '1' ? 1 : 0;
        }

        if (in_array('bool', $accept, true) && is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $read->fail();
    }
}
```

The `InvalidResponseException` import is used by the `@throws` tag; keep it.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ShapeValidatorTest`
Expected: PASS (all scalar rows, invalid rows and the malformed-spec test).

- [ ] **Step 5: Run static checks**

Run: `vendor/bin/phpstan analyse --memory-limit=512M --no-progress src/Response/ShapeValidator.php tests/Response/ShapeValidatorTest.php && vendor/bin/phpcs -q src/Response/ShapeValidator.php tests/Response/ShapeValidatorTest.php`
Expected: `[OK] No errors` and no PHPCS output. Fix reported issues before continuing (wrap lines over 120 characters; do not add ignores).

- [ ] **Step 6: Commit**

```bash
git add src/Response/ShapeValidator.php tests/Response/ShapeValidatorTest.php
git commit -m "feat: add response shape validator for scalar type specs"
```

### Task 2: ShapeValidator containers

**Files:**
- Modify: `src/Response/ShapeValidator.php`
- Test: `tests/Response/ShapeValidatorTest.php`

**Interfaces:**
- Consumes: Task 1's `ShapeValidator::value()`.
- Produces: `list`, `map`, `object` and `union` support in `ShapeValidator::value()` with the semantics in the spec-format table.

- [ ] **Step 1: Write the failing tests**

Add to `ShapeValidatorTest`:

```php
    /** @param array<mixed> $type */
    #[DataProvider('validContainers')]
    public function testValidatesContainers(string $json, array $type, mixed $expected): void
    {
        self::assertSame($expected, ShapeValidator::value(self::decode($json), $type, self::reader()));
    }

    /** @return iterable<string, array{string, array<mixed>, mixed}> */
    public static function validContainers(): iterable
    {
        $object = [
            'type' => 'object',
            'fields' => ['a' => ['type' => 'string'], 'b' => ['type' => 'int']],
            'required' => ['a'],
        ];
        yield 'list' => ['[1,2]', ['type' => 'list', 'items' => ['type' => 'int']], [1, 2]];
        yield 'empty list' => ['[]', ['type' => 'list', 'items' => ['type' => 'int']], []];
        yield 'map' => ['{"x.test":0}', ['type' => 'map', 'values' => ['type' => 'flag']], ['x.test' => 0]];
        yield 'object drops unknown and keeps omission' => ['{"extra":true,"a":"x"}', $object, ['a' => 'x']];
        yield 'object keeps declared order' => ['{"b":2,"a":"x"}', $object, ['a' => 'x', 'b' => 2]];
        yield 'nested normalization' => [
            '{"domains":[{"port":"80"}]}',
            [
                'type' => 'object',
                'fields' => ['domains' => ['type' => 'list', 'items' => [
                    'type' => 'object',
                    'fields' => ['port' => ['type' => 'int', 'accept' => ['numeric-string']]],
                ]]],
            ],
            ['domains' => [['port' => 80]]],
        ];
        yield 'union first branch' => [
            '5',
            ['type' => 'union', 'anyOf' => [['type' => 'int'], ['type' => 'string']]],
            5,
        ];
        yield 'union second branch' => [
            '"a"',
            ['type' => 'union', 'anyOf' => [['type' => 'int'], ['type' => 'string']]],
            'a',
        ];
    }

    /** @param array<mixed> $type */
    #[DataProvider('invalidContainers')]
    public function testRejectsInvalidContainers(string $json, array $type): void
    {
        $this->expectException(InvalidResponseException::class);

        ShapeValidator::value(self::decode($json), $type, self::reader());
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidContainers(): iterable
    {
        yield 'object as list' => ['{"a":1}', ['type' => 'list', 'items' => ['type' => 'int']]];
        yield 'list item mismatch' => ['[1,"x"]', ['type' => 'list', 'items' => ['type' => 'int']]];
        yield 'scalar as map' => ['5', ['type' => 'map', 'values' => ['type' => 'int']]];
        yield 'missing required field' => [
            '{"b":1}',
            ['type' => 'object', 'fields' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ];
        yield 'empty array for object with required fields' => [
            '[]',
            ['type' => 'object', 'fields' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ];
        yield 'union without match' => ['true', ['type' => 'union', 'anyOf' => [['type' => 'int'], ['type' => 'string']]]];
    }

    public function testEmptyJsonArrayIsAnEmptyObjectOrMap(): void
    {
        $object = ['type' => 'object', 'fields' => ['a' => ['type' => 'string']]];
        $map = ['type' => 'map', 'values' => ['type' => 'int']];

        self::assertSame([], ShapeValidator::value([], $object, self::reader()));
        self::assertSame([], ShapeValidator::value([], $map, self::reader()));
    }

    public function testMapKeepsNumericKeys(): void
    {
        $result = ShapeValidator::value(
            self::decode('{"0":1,"10":0}'),
            ['type' => 'map', 'values' => ['type' => 'flag']],
            self::reader(),
        );

        self::assertSame([0 => 1, 10 => 0], $result);
    }

    public function testUnionFallsThroughDeepFailures(): void
    {
        $type = ['type' => 'union', 'anyOf' => [
            ['type' => 'object', 'fields' => ['a' => ['type' => 'object', 'fields' => [
                'b' => ['type' => 'int'],
            ], 'required' => ['b']]], 'required' => ['a']],
            ['type' => 'mixed'],
        ]];

        self::assertSame(['a' => ['b' => 'x']], ShapeValidator::value(self::decode('{"a":{"b":"x"}}'), $type, self::reader()));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ShapeValidatorTest`
Expected: FAIL; container rows raise `LogicException: Unsupported type spec.`

- [ ] **Step 3: Implement containers**

In `ShapeValidator::value()` add four arms to the `match` before `default`:

```php
            'list' => $read->listOf(
                $value,
                static fn (mixed $item): mixed => self::value($item, self::child($type, 'items'), $read),
            ),
            'map' => self::map($value, self::child($type, 'values'), $read),
            'object' => self::object($value, $type, $read),
            'union' => self::union($value, $type, $read),
```

Add these private methods:

```php
    /**
     * Read a nested spec, failing loudly when the generator omitted it.
     *
     * @param array<mixed> $type
     * @return array<mixed>
     */
    private static function child(array $type, string $key): array
    {
        $child = $type[$key] ?? null;
        if (!is_array($child)) {
            throw new LogicException('Type spec is missing "' . $key . '".');
        }

        return $child;
    }

    /**
     * Validate a JSON object map; an empty JSON array is an empty map.
     *
     * @param array<mixed> $valueType
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value, array $valueType, PayloadReader $read): array
    {
        if ($value === []) {
            return [];
        }

        $result = [];
        foreach (get_object_vars($read->object($value)) as $key => $item) {
            $result[$key] = self::value($item, $valueType, $read);
        }

        return $result;
    }

    /**
     * Validate declared fields in declared order and drop unknown fields.
     *
     * @param array<mixed> $type
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $type, PayloadReader $read): array
    {
        $fields = $type['fields'] ?? [];
        $required = $type['required'] ?? [];
        if (!is_array($fields) || !is_array($required)) {
            throw new LogicException('Invalid object type spec.');
        }

        if ($value === [] && $required === []) {
            return [];
        }

        $object = $read->object($value);
        $result = [];
        foreach ($fields as $name => $fieldType) {
            if (!is_array($fieldType)) {
                throw new LogicException('Invalid field type spec.');
            }

            $name = (string) $name;
            if (property_exists($object, $name)) {
                $result[$name] = self::value($object->{$name}, $fieldType, $read);
            } elseif (in_array($name, $required, true)) {
                $read->fail();
            }
        }

        return $result;
    }

    /**
     * Return the first branch that validates; partial branch results are discarded.
     *
     * @param array<mixed> $type
     */
    private static function union(mixed $value, array $type, PayloadReader $read): mixed
    {
        $options = $type['anyOf'] ?? null;
        if (!is_array($options) || $options === []) {
            throw new LogicException('Invalid union type spec.');
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                throw new LogicException('Invalid union type spec.');
            }

            try {
                return self::value($value, $option, $read);
            } catch (InvalidResponseException) {
                continue;
            }
        }

        return $read->fail();
    }
```

Note: a JSON object with numeric keys decodes to `stdClass`; `get_object_vars()` returns integer keys for canonical numeric names, which `map()` keeps as integer PHP keys.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ShapeValidatorTest`
Expected: PASS.

- [ ] **Step 5: Run static checks**

Run: `vendor/bin/phpstan analyse --memory-limit=512M --no-progress src/Response/ShapeValidator.php tests/Response/ShapeValidatorTest.php && vendor/bin/phpcs -q src/Response/ShapeValidator.php tests/Response/ShapeValidatorTest.php`
Expected: no errors; wrap any line over 120 characters across multiple lines.

- [ ] **Step 6: Commit**

```bash
git add src/Response/ShapeValidator.php tests/Response/ShapeValidatorTest.php
git commit -m "feat: validate list, map, object and union type specs"
```

### Task 3: RepeatedList and ArgumentEncoder

**Files:**
- Create: `src/Api/RepeatedList.php`, `src/Api/Engine/ArgumentEncoder.php`
- Modify: `src/Api/AbstractApiClient.php` (the `Parameters` alias and `queryValues()`), `tests/Support/WhmQueryClient.php`
- Test: `tests/Api/QueryEncodingTest.php`, `tests/Api/Engine/ArgumentEncoderTest.php`

**Interfaces:**
- Consumes: `NumberedList`, `AbstractApiClient::queryValues()` encoding rules.
- Produces: `RepeatedList(public string|array $values)`; `Parameters` = `array<string, string|int|list<string>|NumberedList|RepeatedList>`; `ArgumentEncoder::encode(array $params, array $arguments): array` returning `Parameters`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Api/QueryEncodingTest.php` (it already has `whmClient()` and imports for `MockClient`, `Psr17Factory`, `Response`, `InvalidArgumentException`):

```php
    public function testRepeatedListsRepeatTheLiteralName(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $client = $this->whmClient($http, $factory);

        $client->get(['domain' => new RepeatedList(['a.test', 'b&b.test'])]);

        self::assertSame(
            'api.version=1&domain=a.test&domain=b%26b.test',
            $http->getRequests()[0]->getUri()->getQuery(),
        );
    }

    public function testInvalidRepeatedListsClearMetadataBeforeIo(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $client = $this->whmClient($http, $factory);
        $client->get([]);
        self::assertNotNull($client->responseMeta());

        try {
            $client->get(['domain' => new RepeatedList([])]);
            self::fail('Expected the repeated list to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }
```

Add `use HostingPal\WhmApiClient\Api\RepeatedList;` to the imports.

Create `tests/Api/Engine/ArgumentEncoderTest.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ArgumentEncoderTest extends TestCase
{
    private const PARAMS = [
        'domain' => ['name' => 'domain', 'encoding' => 'scalar'],
        'hideTemporary' => ['name' => 'hide_temporary_domains', 'encoding' => 'bool'],
        'domains' => ['name' => 'domain', 'encoding' => 'repeat'],
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

        self::assertSame(['domain', 'hide_temporary_domains', 'user', 'ratio'], array_keys($encoded));
        self::assertSame('example.test', $encoded['domain']);
        self::assertSame(0, $encoded['hide_temporary_domains']);
        self::assertInstanceOf(NumberedList::class, $encoded['user']);
        self::assertSame(['alice', 'bob'], $encoded['user']->values);
        self::assertSame('1.5', $encoded['ratio']);
    }

    public function testRepeatedArgumentsBecomeRepeatedLists(): void
    {
        $encoded = ArgumentEncoder::encode(self::PARAMS, ['domains' => ['a.test', 'b.test']]);

        self::assertInstanceOf(RepeatedList::class, $encoded['domain']);
        self::assertSame(['a.test', 'b.test'], $encoded['domain']->values);
    }

    public function testExplicitNullEqualsOmission(): void
    {
        self::assertSame(
            ArgumentEncoder::encode(self::PARAMS, ['domain' => 'example.test']),
            ArgumentEncoder::encode(self::PARAMS, ['domain' => 'example.test', 'hideTemporary' => null, 'users' => null]),
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
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'ArgumentEncoderTest|QueryEncodingTest'`
Expected: FAIL with `Class "HostingPal\WhmApiClient\Api\RepeatedList" not found` / `ArgumentEncoder` not found.

- [ ] **Step 3: Add RepeatedList and teach the shared encoder**

Create `src/Api/RepeatedList.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

/**
 * Mark a query or body value for repeated-key multi-value encoding.
 *
 * One string encodes as name=value; a list encodes as name=a&name=b in order.
 * Contents are validated by AbstractApiClient after it clears response metadata,
 * so the engine never validates caller input before the request lifecycle starts.
 *
 * @internal Built by the engine for documented repeated parameters.
 */
final readonly class RepeatedList
{
    /**
     * Wrap caller input without validating it yet.
     *
     * @param string|array<array-key, mixed> $values One value or a nonempty list of strings.
     */
    public function __construct(public string|array $values)
    {
    }
}
```

In `src/Api/AbstractApiClient.php`:

1. Change the alias to: `@phpstan-type Parameters array<string, string|int|list<string>|NumberedList|RepeatedList>`
2. Add `use HostingPal\WhmApiClient\Api\RepeatedList;` is not needed (same namespace); confirm the class docblock's description of multi-value parameters mentions `RepeatedList`: "List values and RepeatedList values repeat their literal name; NumberedList values use WHM's name, name-1, ... convention."
3. In `queryValues()`, unwrap a `RepeatedList` the same way as a plain list. Replace its first two lines:

```php
        $numbered = $value instanceof NumberedList;
        $value = $numbered ? $value->values : $value;
```

with:

```php
        $numbered = $value instanceof NumberedList;
        if ($numbered || $value instanceof RepeatedList) {
            $value = $value->values;
        }
```

Update `tests/Support/WhmQueryClient.php`: change both `@param array<string, NumberedList> $query` / `$body` annotations to `@param array<string, NumberedList|RepeatedList> $query` (and `$body`), and import `HostingPal\WhmApiClient\Api\RepeatedList`.

- [ ] **Step 4: Create the encoder**

Create `src/Api/Engine/ArgumentEncoder.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use LogicException;

/**
 * Encode a generated method's named arguments into wire parameters.
 *
 * Null arguments are omitted, so an explicit null and an omitted argument send
 * the same request. Flags become 0/1, repeated and numbered lists become their
 * marker objects, and float scalars are sent as strings. List contents are not
 * validated here: AbstractApiClient validates them after clearing metadata. An
 * argument or encoding the spec does not declare is a generator defect.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 * @internal
 */
final class ArgumentEncoder
{
    /**
     * Encode arguments in spec order under their wire names.
     *
     * @param array<mixed> $params Parameter specs keyed by PHP argument name.
     * @param array<string, mixed> $arguments Arguments keyed by PHP argument name.
     * @return Parameters
     * @throws LogicException If arguments and specs disagree.
     */
    public static function encode(array $params, array $arguments): array
    {
        foreach (array_keys($arguments) as $name) {
            if (!array_key_exists($name, $params)) {
                throw new LogicException('Unknown argument for this operation: ' . $name);
            }
        }

        $encoded = [];
        foreach ($params as $name => $param) {
            $value = $arguments[$name] ?? null;
            if ($value === null) {
                continue;
            }

            $wire = is_array($param) ? ($param['name'] ?? null) : null;
            if (!is_string($wire)) {
                throw new LogicException('Invalid parameter spec for ' . $name . '.');
            }

            $encoded[$wire] = match ($param['encoding'] ?? null) {
                'scalar' => self::scalar($value),
                'bool' => is_bool($value) ? ($value ? 1 : 0) : throw new LogicException('Flag arguments must be booleans.'),
                'repeat' => new RepeatedList(self::multiple($value)),
                'numbered' => new NumberedList(self::multiple($value)),
                default => throw new LogicException('Unsupported parameter encoding for ' . $name . '.'),
            };
        }

        return $encoded;
    }

    private static function scalar(mixed $value): string|int
    {
        return match (true) {
            is_string($value), is_int($value) => $value,
            is_float($value) => (string) $value,
            default => throw new LogicException('Scalar arguments must be strings or numbers.'),
        };
    }

    /** @return string|array<array-key, mixed> */
    private static function multiple(mixed $value): string|array
    {
        if (is_string($value) || is_array($value)) {
            return $value;
        }

        throw new LogicException('Multi-value arguments must be strings or lists.');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'ArgumentEncoderTest|QueryEncodingTest'`
Expected: PASS.

- [ ] **Step 6: Run the full suite and static checks**

Run: `composer check`
Expected: PHPCS clean, PHPStan `[OK] No errors`, PHPUnit OK.

- [ ] **Step 7: Commit**

```bash
git add src/Api/RepeatedList.php src/Api/AbstractApiClient.php src/Api/Engine/ArgumentEncoder.php tests/Support/WhmQueryClient.php tests/Api/QueryEncodingTest.php tests/Api/Engine/ArgumentEncoderTest.php
git commit -m "feat: encode generated arguments with repeated and numbered lists"
```

### Task 4: OperationSpec helpers and OperationRunner

**Files:**
- Create: `src/Api/Engine/OperationSpec.php`, `src/Api/Engine/OperationRunner.php`
- Test: `tests/Api/Engine/OperationRunnerTest.php`

**Interfaces:**
- Consumes: `ArgumentEncoder::encode()`, `ShapeValidator::value()`, `ApiResponse` (`data`, `metadata`, `hasData`), `PayloadReader`, `RequestMethod`, `OutcomePolicy`.
- Produces:
  - `OperationSpec::method(array $spec): RequestMethod`, `OperationSpec::outcome(array $spec): OutcomePolicy`, `OperationSpec::text(array $spec, string $key): string` (for `function`/`module`).
  - `OperationRunner::run(array $spec, array $arguments, Closure $dispatch): mixed` where `$dispatch` is `Closure(array<mixed>, Parameters): ApiResponse`.
  - `OperationRunner::result(array $spec, ApiResponse $response): mixed`.
  Plan 3's handles implement the dispatch closure; plan 2's generated groups call the handle's run closure.

- [ ] **Step 1: Write the failing test**

Create `tests/Api/Engine/OperationRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class OperationRunnerTest extends TestCase
{
    private const SPEC = [
        'function' => 'primary_domain',
        'module' => 'DomainInfo',
        'method' => 'GET',
        'outcome' => 'flag',
        'params' => ['hideTemporaryDomains' => ['name' => 'hide_temporary_domains', 'encoding' => 'bool']],
        'returns' => [
            'type' => 'object',
            'fields' => ['primary_domain' => ['type' => 'string', 'nullable' => true]],
            'required' => ['primary_domain'],
        ],
    ];

    public function testRunEncodesDispatchesAndValidates(): void
    {
        $seen = [];
        $result = OperationRunner::run(
            self::SPEC,
            ['hideTemporaryDomains' => true],
            static function (array $spec, array $parameters) use (&$seen): ApiResponse {
                $seen = [$spec['function'], $parameters];

                return self::response((object) ['primary_domain' => 'example.test', 'extra' => 1]);
            },
        );

        self::assertSame(['primary_domain', ['hide_temporary_domains' => 1]], $seen);
        self::assertSame(['primary_domain' => 'example.test'], $result);
    }

    public function testUnwrapReturnsTheSelectedField(): void
    {
        $spec = self::SPEC + ['unwrap' => 'primary_domain'];

        self::assertSame('example.test', OperationRunner::result($spec, self::response((object) ['primary_domain' => 'example.test'])));
        self::assertNull(OperationRunner::result($spec, self::response((object) ['primary_domain' => null])));
    }

    public function testUnwrapRequiresTheSelectedField(): void
    {
        $spec = ['returns' => ['type' => 'object', 'fields' => ['a' => ['type' => 'string']]], 'unwrap' => 'a']
            + self::SPEC;
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            OperationRunner::result($spec, new ApiResponse(new stdClass(), $metadata));
            self::fail('Expected an invalid payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertSame('Invalid primary_domain payload.', $exception->getMessage());
        }
    }

    public function testVoidOperationsIgnoreThePayload(): void
    {
        $spec = ['returns' => null] + self::SPEC;

        self::assertNull(OperationRunner::result($spec, self::response((object) ['anything' => true])));
        self::assertNull(OperationRunner::result($spec, new ApiResponse(null, new ResponseMetadata(200), false)));
    }

    public function testOmittedPayloadIsInvalidEvenWhenNullable(): void
    {
        $nullable = ['returns' => ['type' => 'list', 'items' => ['type' => 'string'], 'nullable' => true]] + self::SPEC;
        self::assertNull(OperationRunner::result($nullable, new ApiResponse(null, new ResponseMetadata(200), true)));

        $this->expectException(InvalidResponseException::class);
        OperationRunner::result($nullable, new ApiResponse(null, new ResponseMetadata(200), false));
    }

    public function testSpecHelpersMapWireValues(): void
    {
        self::assertSame(RequestMethod::Get, OperationSpec::method(self::SPEC));
        self::assertSame(RequestMethod::Post, OperationSpec::method(['method' => 'POST'] + self::SPEC));
        self::assertSame(OutcomePolicy::Flag, OperationSpec::outcome(self::SPEC));
        self::assertSame(OutcomePolicy::Unchecked, OperationSpec::outcome(['outcome' => 'unchecked'] + self::SPEC));
        self::assertSame('DomainInfo', OperationSpec::text(self::SPEC, 'module'));
    }

    public function testMalformedSpecsAreGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        OperationSpec::method(['method' => 'PATCH'] + self::SPEC);
    }

    private static function response(stdClass $data): ApiResponse
    {
        return new ApiResponse($data, new ResponseMetadata(200, ['result' => 1]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter OperationRunnerTest`
Expected: FAIL with `Class "HostingPal\WhmApiClient\Api\Engine\OperationRunner" not found`.

- [ ] **Step 3: Write OperationSpec**

Create `src/Api/Engine/OperationSpec.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;
use LogicException;

/**
 * Describe and read the generated per-operation spec format.
 *
 * tools/api-map generates one spec constant per operation in the group's Spec
 * class. Keys: function (wire name), module (UAPI only), method ('GET' or
 * 'POST'), outcome ('flag' or 'unchecked'), params (PHP argument name =>
 * array{name: wire name, encoding: 'scalar'|'bool'|'repeat'|'numbered'}),
 * returns (a type spec, or null for void), and optional unwrap (a field of the
 * validated object to return). Type specs are documented on ShapeValidator's
 * callers in the generated-client design. A malformed spec is a generator defect.
 *
 * @internal
 */
final class OperationSpec
{
    /**
     * Map the spec's HTTP method to the request method.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the method is not GET or POST.
     */
    public static function method(array $spec): RequestMethod
    {
        return RequestMethod::tryFrom(self::text($spec, 'method'))
            ?? throw new LogicException('Unsupported operation method.');
    }

    /**
     * Map the spec's outcome policy name to the policy.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the policy is unknown.
     */
    public static function outcome(array $spec): OutcomePolicy
    {
        return match (self::text($spec, 'outcome')) {
            'flag' => OutcomePolicy::Flag,
            'unchecked' => OutcomePolicy::Unchecked,
            default => throw new LogicException('Unsupported outcome policy.'),
        };
    }

    /**
     * Read a required string field of the spec.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the field is missing or not a string.
     */
    public static function text(array $spec, string $key): string
    {
        $value = $spec[$key] ?? null;

        return is_string($value) ? $value : throw new LogicException('Operation spec is missing "' . $key . '".');
    }
}
```

- [ ] **Step 4: Write OperationRunner**

Create `src/Api/Engine/OperationRunner.php`:

```php
<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use Closure;
use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use LogicException;

/**
 * Execute one generated operation: encode, dispatch, validate, unwrap.
 *
 * The dispatch closure belongs to the owning handle: it routes the request
 * (WHM path, direct UAPI or the bridge), applies the spec's method and outcome
 * policy, and runs the shared request lifecycle, so metadata is cleared before
 * any parameter validation or I/O. This class adds no retries and no state.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 * @internal
 */
final class OperationRunner
{
    /**
     * Run an operation and return its validated result.
     *
     * @param array<mixed> $spec Generated operation spec.
     * @param array<string, mixed> $arguments Named arguments of the generated method.
     * @param Closure(array<mixed>, Parameters): ApiResponse $dispatch
     * @throws ApiException If transport, HTTP, the outcome, or the payload fails.
     * @throws LogicException If the spec and arguments disagree.
     */
    public static function run(array $spec, array $arguments, Closure $dispatch): mixed
    {
        $params = $spec['params'] ?? null;
        if (!is_array($params)) {
            throw new LogicException('Operation spec is missing "params".');
        }

        return self::result($spec, $dispatch($spec, ArgumentEncoder::encode($params, $arguments)));
    }

    /**
     * Validate the payload against the spec, then apply void or unwrap rules.
     *
     * Void operations return null and ignore any payload. When a payload is
     * declared, an omitted data member is invalid; a nullable spec accepts only an
     * explicit null. An unwrapped field must be present in the validated object.
     *
     * @param array<mixed> $spec
     * @throws InvalidResponseException If the payload does not satisfy the spec.
     * @throws LogicException If the spec is malformed.
     */
    public static function result(array $spec, ApiResponse $response): mixed
    {
        $returns = $spec['returns'] ?? null;
        if ($returns === null) {
            return null;
        }

        if (!is_array($returns)) {
            throw new LogicException('Invalid return spec.');
        }

        $read = new PayloadReader($response->metadata, OperationSpec::text($spec, 'function') . ' payload');
        if (!$response->hasData) {
            return $read->fail();
        }

        $value = ShapeValidator::value($response->data, $returns, $read);
        $unwrap = $spec['unwrap'] ?? null;
        if ($unwrap === null) {
            return $value;
        }

        if (!is_string($unwrap)) {
            throw new LogicException('Invalid unwrap spec.');
        }

        return is_array($value) && array_key_exists($unwrap, $value) ? $value[$unwrap] : $read->fail();
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter OperationRunnerTest`
Expected: PASS.

- [ ] **Step 6: Run the full gate**

Run: `composer check`
Expected: PHPCS clean, PHPStan `[OK] No errors`, PHPUnit OK.

- [ ] **Step 7: Commit**

```bash
git add src/Api/Engine/OperationSpec.php src/Api/Engine/OperationRunner.php tests/Api/Engine/OperationRunnerTest.php
git commit -m "feat: run generated operation specs with void and unwrap handling"
```

### Task 5: Classify the new symbols and verify the toolchain

The parity registry still requires every public symbol to be classified until plan 3 retires it.

**Files:**
- Modify: `docs/api/coverage/symbols.json`, `docs/api/coverage/registry.json`, `docs/api/coverage/report.json`, `docs/api/coverage/report.md`
- Modify: `tests/Tooling/SymbolInventoryTest.php` only if its pinned counts change (they cover `HttpTransport` and `HttpTransportTest` only, so they should not).

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: a valid registry with the engine classified as infrastructure; `composer api-map:check` green.

- [ ] **Step 1: Export symbols and see the failure**

Run:
```bash
php tools/php-symbols/export.php --root . --output docs/api/coverage/symbols.json
composer api-map:check
```
Expected: FAIL (exit 1) listing unclassified symbols such as `HostingPal\WhmApiClient\Api\Engine\OperationRunner::run`.

- [ ] **Step 2: Add infrastructure entries**

Add these objects to the `infrastructure` array of `docs/api/coverage/registry.json`, keep the array sorted by `symbol`, keep two-space indentation and the trailing newline:

```json
{"symbol": "HostingPal\\WhmApiClient\\Api\\Engine\\ArgumentEncoder::encode", "reason": "Encodes generated-method arguments into wire parameters; generic engine, no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Api\\Engine\\OperationRunner::result", "reason": "Validates a payload against a generated spec and applies void/unwrap rules; generic engine, no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Api\\Engine\\OperationRunner::run", "reason": "Runs a generated operation spec through a handle dispatch closure; generic engine, no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Api\\Engine\\OperationSpec::method", "reason": "Reads the HTTP method of a generated spec; generic engine, no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Api\\Engine\\OperationSpec::outcome", "reason": "Reads the outcome policy of a generated spec; generic engine, no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Api\\Engine\\OperationSpec::text", "reason": "Reads a required string field of a generated spec; generic engine, no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Api\\RepeatedList::__construct", "reason": "Marks repeated-key multi-value input for the shared encoder, which validates it after the metadata reset; no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Response\\ShapeValidator::value", "reason": "Validates and normalizes decoded payloads against generated type specs; generic engine, no endpoint operation."},
{"symbol": "HostingPal\\WhmApiClient\\Tests\\Response\\ShapeValidatorTest::invalidContainers", "reason": "Supplies offline PHPUnit cases; a data provider does not implement an endpoint or establish a passing test outcome."},
{"symbol": "HostingPal\\WhmApiClient\\Tests\\Response\\ShapeValidatorTest::invalidScalars", "reason": "Supplies offline PHPUnit cases; a data provider does not implement an endpoint or establish a passing test outcome."},
{"symbol": "HostingPal\\WhmApiClient\\Tests\\Response\\ShapeValidatorTest::validContainers", "reason": "Supplies offline PHPUnit cases; a data provider does not implement an endpoint or establish a passing test outcome."},
{"symbol": "HostingPal\\WhmApiClient\\Tests\\Response\\ShapeValidatorTest::validScalars", "reason": "Supplies offline PHPUnit cases; a data provider does not implement an endpoint or establish a passing test outcome."}
```

If `api-map:check` lists any other unclassified symbol, classify it with the same reason style.

- [ ] **Step 3: Regenerate the parity report and run the toolchain check**

Run (with `GOCACHE`, `GOMODCACHE`, `GOPROXY=off`, `GOSUMDB=off` set as in AGENTS.md):
```bash
go -C tools/api-map run ./cmd/api-map map build --manifest ../../docs/api/sources/2026-10-13/manifest.json --annotations ../../docs/api/annotations.json --output ../../.cache/api-map/map.json
go -C tools/api-map run ./cmd/api-map parity report --map ../../.cache/api-map/map.json --registry ../../docs/api/coverage/registry.json --symbols ../../docs/api/coverage/symbols.json --output ../../docs/api/coverage/report.json --markdown ../../docs/api/coverage/report.md
composer api-map:check
go -C tools/api-map test ./...
```
Expected: `Offline API map checks passed; baseline unchanged.` and all Go packages `ok`.

- [ ] **Step 4: Final gate**

Run: `composer validate && composer check`
Expected: valid; PHPCS, PHPStan and PHPUnit pass.

- [ ] **Step 5: Commit, push and open the pull request**

```bash
git add docs/api/coverage
git commit -m "chore: classify generated-client engine symbols"
git push -u origin HEAD
gh pr create --base master --title "feat: generated-client runtime engine" --body "Implements plan 1 of docs/superpowers/specs/2026-10-14-generated-client-design.md: ShapeValidator, ArgumentEncoder, RepeatedList, OperationSpec and OperationRunner. No public API change; the engine is wired to the handles in plan 3."
bd dolt push
```
