<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use InvalidArgumentException;

/**
 * Retain an immutable snapshot of HTTP and API envelope metadata.
 *
 * WHM and UAPI metadata stay separate for bridge responses. These are raw maps
 * with operation-dependent fields, not endpoint payload schemas or normalized
 * success flags. Endpoint readers must document their concrete response shapes.
 * Typed accessors read the documented envelope fields (WHM result and reason;
 * UAPI status, errors, warnings, and messages) without coercion: absent or
 * non-conforming values yield null or an empty list, and non-string entries of
 * a message list are skipped.
 */
final readonly class ResponseMetadata
{
    /** @var array<array-key, mixed>|null */
    public ?array $whm;

    /** @var array<array-key, mixed>|null */
    public ?array $uapi;

    /**
     * Copy available family metadata into an independent response snapshot.
     *
     * Null indicates unavailable family metadata. Nested array references are
     * detached; objects and non-JSON values are rejected to preserve immutability.
     *
     * Numeric JSON object keys become integer PHP array keys at this opaque boundary.
     *
     * @param array<array-key, mixed>|null $whm
     * @param array<array-key, mixed>|null $uapi
     * @throws InvalidArgumentException If status or metadata values are invalid.
     */
    public function __construct(public int $httpStatus, ?array $whm = null, ?array $uapi = null)
    {
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new InvalidArgumentException('HTTP status must be between 100 and 599.');
        }

        $this->whm = $whm === null ? null : self::snapshot($whm);
        $this->uapi = $uapi === null ? null : self::snapshot($uapi);
    }

    /**
     * Return the WHM envelope outcome flag, or null when absent or not an integer.
     *
     * The implemented operations use 1 for success and 0 for failure. The raw
     * value remains available in $whm for any other representation.
     */
    public function whmResult(): ?int
    {
        $result = $this->whm['result'] ?? null;

        return is_int($result) ? $result : null;
    }

    /**
     * Return the WHM envelope reason text, or null when absent or not a string.
     *
     * Successful calls usually report "OK"; failures describe the upstream error.
     */
    public function whmReason(): ?string
    {
        $reason = $this->whm['reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * Return the UAPI result status, or null when absent or not an integer.
     *
     * For bridged calls this is the inner UAPI status, independent of whmResult().
     */
    public function uapiStatus(): ?int
    {
        $status = $this->uapi['status'] ?? null;

        return is_int($status) ? $status : null;
    }

    /**
     * Return the UAPI error strings; non-list values yield [] and non-strings are skipped.
     *
     * @return list<string>
     */
    public function uapiErrors(): array
    {
        return self::strings($this->uapi['errors'] ?? null);
    }

    /**
     * Return the UAPI warning strings; non-list values yield [] and non-strings are skipped.
     *
     * @return list<string>
     */
    public function uapiWarnings(): array
    {
        return self::strings($this->uapi['warnings'] ?? null);
    }

    /**
     * Return the UAPI message strings; non-list values yield [] and non-strings are skipped.
     *
     * @return list<string>
     */
    public function uapiMessages(): array
    {
        return self::strings($this->uapi['messages'] ?? null);
    }

    /**
     * Return the upstream error descriptions for this response, in envelope order.
     *
     * Includes the WHM reason only when whmResult() reports failure (0), so a
     * successful outer bridge reason such as "OK" is never presented as an error,
     * followed by the UAPI errors. Values can name accounts or domains; log them
     * deliberately. An empty list means no conforming error text was returned.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];
        $reason = $this->whmReason();
        if ($this->whmResult() === 0 && $reason !== null) {
            $errors[] = $reason;
        }

        return [...$errors, ...$this->uapiErrors()];
    }

    /**
     * Keep only the string entries of a JSON list.
     *
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * Copy an array tree without retaining references to mutable values.
     *
     * Nesting is limited to match the default JSON decoding depth and reject
     * recursive arrays. Scalars retain their original null, numeric, or string type.
     *
     * @template TKey of array-key
     * @param array<TKey, mixed> $values
     * @return array<TKey, mixed>
     * @throws InvalidArgumentException If values are unsupported or nested too deeply.
     */
    private static function snapshot(array $values, int $depth = 0): array
    {
        if ($depth >= 512) {
            throw new InvalidArgumentException('Metadata nesting exceeds the JSON depth limit.');
        }

        $copy = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $copy[$key] = self::snapshot($value, $depth + 1);
            } elseif (
                $value === null || is_string($value) || is_int($value) || is_bool($value)
                || (is_float($value) && is_finite($value))
            ) {
                $copy[$key] = $value;
            } else {
                throw new InvalidArgumentException(
                    'Metadata must contain only JSON-compatible scalar values or arrays.',
                );
            }
        }

        return $copy;
    }
}
