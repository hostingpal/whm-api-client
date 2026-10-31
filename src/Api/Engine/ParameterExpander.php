<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use HostingPal\WhmApiClient\Api\SuffixNumberedList;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Expand existing scalar and list markers into ordered literal fields.
 *
 * Generic and generated requests share these byte-preserving legacy conventions.
 * Collision policy belongs to the caller: generated fields require provenance,
 * while generic calls retain their existing suffix-only collision protection.
 *
 * @internal
 */
final class ParameterExpander
{
    /**
     * Validate a complete value before returning its literal expansion.
     *
     * @return non-empty-list<array{name: string, value: string|int}>
     * @throws InvalidArgumentException If a scalar, list or suffix pattern is invalid.
     */
    public static function expand(
        #[SensitiveParameter] string $name,
        #[SensitiveParameter] mixed $value,
        bool $generic = false,
    ): array {
        if ($value instanceof SuffixNumberedList) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\*$/D', $name) !== 1) {
                throw new InvalidArgumentException('Suffix-numbered parameters require one terminal wildcard.');
            }
            $allowIntegers = $value->allowIntegers;
            $entries = is_array($value->values) ? $value->values : [$value->values];
            if (!array_is_list($entries) || $entries === []) {
                throw new InvalidArgumentException('Suffix-numbered parameters require a nonempty sequential list.');
            }
            $parts = [];
            foreach ($entries as $index => $entry) {
                if (!is_string($entry) && !($allowIntegers && is_int($entry))) {
                    throw new InvalidArgumentException('Suffix-numbered parameters contain an unsupported value.');
                }
                $key = substr($name, 0, -1) . ($index + 1);
                $parts[] = ['name' => $key, 'value' => $entry];
            }

            return $parts;
        }

        $numbered = $value instanceof NumberedList;
        if ($numbered || $value instanceof RepeatedList) {
            $value = $value->values;
        }
        if (is_string($value) || is_int($value)) {
            return [['name' => $name, 'value' => $value]];
        }
        // List markers hold a string or an array, so only plain values reach this branch.
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                $generic ? 'Parameter "' . $name . '" must be a string, an integer or a list of strings.'
                    : 'Parameters must be strings, integers or lists of strings.',
            );
        }
        if (!array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('Multi-value parameters require a nonempty list of strings.');
        }
        $parts = [];
        foreach ($value as $index => $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Multi-value parameters require string values.');
            }
            $key = $numbered && $index > 0 ? $name . '-' . $index : $name;
            $parts[] = ['name' => $key, 'value' => $entry];
        }

        return $parts;
    }
}
