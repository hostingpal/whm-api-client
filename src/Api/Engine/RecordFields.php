<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use LogicException;
use SensitiveParameter;

/**
 * Validate scalar record fields and encode their values in declaration order.
 *
 * Form-record alternatives and indexed record rows share native type, requiredness, null and unknown-key rules.
 *
 * @phpstan-type Field array{type: 'string'|'int'|'bool'|'string|int', required: bool}
 * @internal
 */
final class RecordFields
{
    /** @phpstan-assert-if-true string $name */
    public static function identifier(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1;
    }

    /** @return Field */
    public static function descriptor(mixed $field): array
    {
        if (
            !is_array($field) || !in_array($field['type'] ?? null, ['string', 'int', 'bool', 'string|int'], true)
            || !is_bool($field['required'] ?? null) || array_diff(array_keys($field), ['type', 'required']) !== []
        ) {
            throw new LogicException('Invalid record field descriptor.');
        }
        return ['type' => $field['type'], 'required' => $field['required']];
    }

    /**
     * Return null if any supplied key or native value is invalid, or a required field is absent.
     *
     * @param array<string, array{type: string, required: bool}> $fields
     * @param array<mixed> $record
     * @return array<string, int|string>|null
     */
    public static function values(array $fields, #[SensitiveParameter] array $record): ?array
    {
        if (array_diff_key($record, $fields) !== []) {
            return null;
        }
        $values = [];
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $record) && !$field['required']) {
                continue;
            }
            $value = $record[$name] ?? null;
            $encoded = match (true) {
                $field['type'] === 'bool' && is_bool($value) => (int) $value,
                in_array($field['type'], ['string', 'string|int'], true) && is_string($value) => $value,
                in_array($field['type'], ['int', 'string|int'], true) && is_int($value) => $value,
                default => null,
            };
            if ($encoded === null) {
                return null;
            }
            $values[$name] = $encoded;
        }
        return $values;
    }
}
