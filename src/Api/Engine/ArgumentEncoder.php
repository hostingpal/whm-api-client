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
     * Each declared parameter with a non-null argument is emitted under its wire
     * name using its declared encoding; undeclared arguments are rejected.
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
