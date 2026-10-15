<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Encode a generated method's named arguments into wire parameters.
 *
 * Null arguments are omitted, so an explicit null and an omitted argument send
 * the same request. Flags become 0/1, repeated and numbered lists become their
 * marker objects, and finite float scalars are sent as strings in their shortest
 * round-trip form (see float()), independent of the precision, serialize_precision
 * and locale settings. List contents are not validated here: AbstractApiClient
 * validates them after clearing metadata. A non-finite float is caller input that
 * no wire form represents and is rejected here; the handles clear metadata before
 * the runner encodes arguments. An argument or encoding the spec does not declare
 * is a generator defect.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 * @internal
 */
final class ArgumentEncoder
{
    /**
     * Encode arguments in spec order under their wire names.
     *
     * Each declared parameter with a non-null argument is emitted under its wire
     * name using its declared encoding; undeclared arguments are rejected.
     *
     * @param array<mixed> $params Parameter specs keyed by PHP argument name.
     * @param array<string, mixed> $arguments Arguments keyed by PHP argument name.
     * @return Parameters
     * @throws LogicException If arguments and specs disagree.
     * @throws InvalidArgumentException If a float argument is NAN or infinite.
     */
    public static function encode(array $params, #[SensitiveParameter] array $arguments): array
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
            if (!is_array($param) || !is_string($wire)) {
                throw new LogicException('Invalid parameter spec for ' . $name . '.');
            }

            $encoded[$wire] = match ($param['encoding'] ?? null) {
                'scalar' => self::scalar($value),
                'bool' => is_bool($value)
                    ? ($value ? 1 : 0)
                    : throw new LogicException('Flag arguments must be booleans.'),
                'repeat' => new RepeatedList(self::multiple($value)),
                'numbered' => new NumberedList(self::multiple($value)),
                default => throw new LogicException('Unsupported parameter encoding for ' . $name . '.'),
            };
        }

        return $encoded;
    }

    private static function scalar(#[SensitiveParameter] mixed $value): string|int
    {
        return match (true) {
            is_string($value), is_int($value) => $value,
            is_float($value) => self::float($value),
            default => throw new LogicException('Scalar arguments must be strings or numbers.'),
        };
    }

    /**
     * Encode a finite float in its shortest round-trip decimal form.
     *
     * The result is what var_export() produces with the default serialize_precision
     * of -1, computed without reading any ini or locale setting: the fewest
     * significant digits (at most 17) that parse back to the same float, fixed
     * notation for decimal exponents from -4 to 16 and E notation otherwise, and
     * ".0" on an integral value. An integral float therefore stays visibly a float
     * ("1.0", "-0.0", "1.0E+20") rather than becoming an integer string.
     *
     * @throws InvalidArgumentException If the float is NAN or infinite.
     */
    private static function float(#[SensitiveParameter] float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Float arguments must be finite.');
        }

        // %H is the locale-independent form of %G: E notation from the precision on.
        $text = sprintf('%.17H', $value);
        for ($digits = 1; $digits < 17; $digits++) {
            $candidate = sprintf('%.' . $digits . 'H', $value);
            if ((float) $candidate === $value) {
                $text = $candidate;

                break;
            }
        }

        $marker = strpos($text, 'E');
        if ($marker === false) {
            return str_contains($text, '.') ? $text : $text . '.0';
        }

        $mantissa = substr($text, 0, $marker);
        $exponent = (int) substr($text, $marker + 1);
        if ($exponent < -4 || $exponent >= 17) {
            $mantissa = str_contains($mantissa, '.') ? $mantissa : $mantissa . '.0';

            return $mantissa . 'E' . ($exponent < 0 ? '-' : '+') . abs($exponent);
        }

        // %H chose E notation because the exponent reached the digit count, so the
        // value is integral: write its digits out in fixed notation.
        $sign = str_starts_with($mantissa, '-') ? '-' : '';
        $significant = rtrim(str_replace(['-', '.'], '', $mantissa), '0');

        return $sign . str_pad($significant === '' ? '0' : $significant, $exponent + 1, '0') . '.0';
    }

    /** @return string|array<array-key, mixed> */
    private static function multiple(#[SensitiveParameter] mixed $value): string|array
    {
        if (is_string($value) || is_array($value)) {
            return $value;
        }

        throw new LogicException('Multi-value arguments must be strings or lists.');
    }
}
