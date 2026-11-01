<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Shared finite-float decimal encoding for form scalars and JSON number tokens.
 *
 * @internal
 */
final class FloatEncoder
{
    /**
     * Encode a finite float as a decimal string that parses back to the same float.
     *
     * The string uses the first precision from 1 to 16 significant digits whose
     * correctly rounded form round-trips, and 17 digits otherwise, which always
     * round-trips; it is computed without reading the precision, serialize_precision
     * or locale settings. Fixed notation is used for decimal exponents from -4 to 16
     * and E notation otherwise, and an integral value gets ".0", so an integral
     * float stays visibly a float ("1.0", "-0.0", "1.0E+20") rather than becoming
     * an integer string. The digit count is not always the minimum (2 ** -1017,
     * for example, uses 17 digits where 16 would do), so the output can differ from
     * var_export() in its last digits while denoting the same float.
     *
     * @throws InvalidArgumentException If the float is NAN or infinite.
     */
    public static function encode(#[SensitiveParameter] float $value): string
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
}
