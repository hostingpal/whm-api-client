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
 */
final readonly class ResponseMetadata
{
    /** @var array<string, mixed>|null */
    public ?array $whm;

    /** @var array<string, mixed>|null */
    public ?array $uapi;

    /**
     * Copy available family metadata into an independent response snapshot.
     *
     * Null indicates unavailable family metadata. Nested array references are
     * detached; objects and non-JSON values are rejected to preserve immutability.
     *
     * @param array<string, mixed>|null $whm
     * @param array<string, mixed>|null $uapi
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
