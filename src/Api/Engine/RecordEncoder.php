<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Expand strict associative records in original row and declared field order.
 *
 * @phpstan-type RecordSpec array{start: int, separator: string,
 *     fields: array<string, array{prefix: string, type: string, required: bool}>}
 * @internal
 */
final class RecordEncoder
{
    /** @return RecordSpec */
    public static function check(mixed $spec): array
    {
        if (
            !is_array($spec) || !is_int($spec['start'] ?? null)
            || $spec['start'] < 0 || $spec['start'] > 2147483647
            || !in_array($spec['separator'] ?? null, ['', '-'], true)
            || !is_array($spec['fields'] ?? null) || $spec['fields'] === []
            || array_diff(array_keys($spec), ['start', 'separator', 'fields']) !== []
        ) {
            throw new LogicException('Invalid record descriptor.');
        }
        $fields = [];
        foreach ($spec['fields'] as $key => $field) {
            if (
                !is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1
                || !is_array($field) || !is_string($field['prefix'] ?? null)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field['prefix']) !== 1
                || !in_array($field['type'] ?? null, ['string', 'int', 'bool', 'string|int'], true)
                || !is_bool($field['required'] ?? null)
                || array_diff(array_keys($field), ['prefix', 'type', 'required']) !== []
            ) {
                throw new LogicException('Invalid record field descriptor.');
            }
            foreach ($fields as $other) {
                $a = $field['prefix'] . $spec['separator'];
                $b = $other['prefix'] . $spec['separator'];
                if (
                    $a === $b || preg_match('/^' . preg_quote($a, '/') . '[1-9][0-9]*$/D', $b) === 1
                    || preg_match('/^' . preg_quote($b, '/') . '[1-9][0-9]*$/D', $a) === 1
                ) {
                    throw new LogicException('Overlapping record field prefixes.');
                }
            }
            $fields[$key] = ['prefix' => $field['prefix'], 'type' => $field['type'], 'required' => $field['required']];
        }

        return ['start' => $spec['start'], 'separator' => $spec['separator'], 'fields' => $fields];
    }

    public static function append(
        #[SensitiveParameter] OrderedFields $fields,
        #[SensitiveParameter] string $origin,
        mixed $spec,
        #[SensitiveParameter] mixed $value,
    ): void {
        $spec = self::check($spec);
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('Record input requires a nonempty sequential list.');
        }
        foreach ($value as $position => $row) {
            if (
                !is_array($row) || $row === [] || array_is_list($row)
                || array_diff(array_keys($row), array_keys($spec['fields'])) !== []
            ) {
                throw new InvalidArgumentException('Record rows require only declared named fields.');
            }
            if ($position > PHP_INT_MAX - $spec['start']) {
                throw new InvalidArgumentException('Record index exceeds the integer range.');
            }
            foreach ($spec['fields'] as $key => $field) {
                if (!array_key_exists($key, $row)) {
                    if ($field['required']) {
                        throw new InvalidArgumentException('Record row is missing a required field.');
                    }
                    continue;
                }
                $item = $row[$key];
                $encoded = match (true) {
                    $field['type'] === 'bool' && is_bool($item) => (int) $item,
                    in_array($field['type'], ['string', 'string|int'], true) && is_string($item) => $item,
                    in_array($field['type'], ['int', 'string|int'], true) && is_int($item) => $item,
                    default => throw new InvalidArgumentException('Record field does not match its strict type.'),
                };
                $fields->append(
                    $origin,
                    $field['prefix'] . $spec['separator'] . ($spec['start'] + $position),
                    $encoded,
                    null,
                    false,
                );
            }
        }
    }
}
