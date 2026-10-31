<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use JsonException;
use LogicException;
use SensitiveParameter;

/**
 * Serialize a present, schema-declared structured JSON value without I/O.
 *
 * Only ordinary arrays and strict declared scalar types are accepted. No caller
 * object, resource or serialization callback is used. Containers are emitted
 * explicitly: PHP object casting followed by json_encode silently discards
 * leading-NUL member names, while array encoding guesses the wrong kind for
 * empty objects. Native throwing JSON encoding handles keys and non-float scalar tokens;
 * fixed container punctuation preserves all string keys and insertion order.
 * The native validation depth budget is 512: at most 511 nested object/list
 * boundaries, counting the root container as one and scalar leaves as zero.
 * Native json_encode(..., 512) alone permits one extra container, so the complete
 * result is also checked with json_validate(..., 512). No partial output escapes.
 *
 * @internal
 */
final class StructuredJsonEncoder
{
    /**
     * Return a complete JSON string; absence is a separate dispatch decision.
     *
     * Present null yields the four-byte string "null" when the schema allows it.
     * Missing optional object members remain absent; requiredness and nullability
     * are independent. Additional members require an explicit "values" schema.
     * No decoding, string repair, Unicode normalization or response coercion occurs.
     *
     * Finite floats retain their value and float kind using shared round-trip
     * decimal encoding, without reading or modifying precision, serialize_precision
     * or locale settings. See FloatEncoder for the non-minimal digit policy.
     * Returned strings may contain secrets and must not be logged.
     *
     * @param array<mixed> $schema See StructuredJsonSchema for the request grammar.
     * @throws InvalidArgumentException If any caller value does not match the checked schema.
     * @throws LogicException If the schema is invalid or encoded syntax validation fails.
     */
    public static function encode(
        #[SensitiveParameter] mixed $value,
        #[SensitiveParameter] array $schema,
    ): string {
        $checked = StructuredJsonSchema::from($schema);
        $json = self::value($value, $checked, 0);
        if (!json_validate($json, StructuredJsonSchema::MAX_DEPTH)) {
            throw new LogicException('Structured JSON encoding failed syntax validation.');
        }
        return $json;
    }

    private static function value(
        #[SensitiveParameter] mixed $value,
        #[SensitiveParameter] StructuredJsonSchema $schema,
        int $depth,
    ): string {
        if ($value === null && ($schema->nullable || $schema->type === 'null')) {
            return 'null';
        }
        if ($schema->type === 'union') {
            foreach ($schema->alternatives as $branch) {
                if (self::matches($value, $branch)) {
                    return self::value($value, $branch, $depth);
                }
            }
            throw new InvalidArgumentException('Structured JSON value does not match its schema.');
        }
        if (!self::matches($value, $schema)) {
            throw new InvalidArgumentException('Structured JSON value does not match its schema.');
        }
        if (is_array($value)) {
            // Defensive: the checked schema already bounds every traversable container.
            if ($depth >= StructuredJsonSchema::MAX_DEPTH - 1) {
                throw new InvalidArgumentException('Structured JSON value exceeds the nesting limit.');
            }
            return self::container($value, $schema, $depth + 1);
        }
        return self::token($schema->type === 'flag' ? ($value === true ? 1 : 0) : $value);
    }

    private static function matches(
        #[SensitiveParameter] mixed $value,
        #[SensitiveParameter] StructuredJsonSchema $schema,
    ): bool {
        if ($value === null) {
            return $schema->nullable || $schema->type === 'null';
        }
        return match ($schema->type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'bool', 'flag' => is_bool($value),
            'object', 'list' => is_array($value),
            default => false,
        };
    }

    /** @param array<mixed> $value */
    private static function container(
        #[SensitiveParameter] array $value,
        #[SensitiveParameter] StructuredJsonSchema $schema,
        int $depth,
    ): string {
        $parts = [];
        if ($schema->type === 'list') {
            if ($schema->nonempty && $value === []) {
                throw new InvalidArgumentException('Structured JSON list must not be empty.');
            }
            if (!array_is_list($value)) {
                throw new InvalidArgumentException('Structured JSON lists require sequential arrays.');
            }
            $items = $schema->items ?? throw new LogicException('Missing structured JSON item schema.');
            foreach ($value as $item) {
                $parts[] = self::value($item, $items, $depth);
            }
            return '[' . implode(',', $parts) . ']';
        }
        foreach ($schema->required as $name) {
            if (!array_key_exists($name, $value)) {
                throw new InvalidArgumentException('Structured JSON object is missing a required member.');
            }
        }
        foreach ($value as $name => $item) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Structured JSON objects require string member names.');
            }
            $field = $schema->fields[$name] ?? $schema->values;
            if ($field === null) {
                throw new InvalidArgumentException('Structured JSON object contains an undeclared member.');
            }
            $parts[] = self::token($name) . ':' . self::value($item, $field, $depth);
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function token(#[SensitiveParameter] mixed $value): string
    {
        if (is_float($value)) {
            return FloatEncoder::encode($value);
        }
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                StructuredJsonSchema::MAX_DEPTH,
            );
        } catch (JsonException) {
            // Discard the native exception: its captured arguments can contain secrets.
            throw new InvalidArgumentException('Structured JSON contains an unencodable value.');
        }
    }
}
