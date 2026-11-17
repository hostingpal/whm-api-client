<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use HostingPal\WhmApiClient\Api\SuffixNumberedList;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use stdClass;

/**
 * Encode a generated method's named arguments into wire parameters.
 *
 * An explicit reject_empty policy rejects null, blank/zero strings and invalid
 * string-list targets before omission/encoding; it never changes unflagged inputs.
 * An explicit omit_empty parameter setting also omits exactly an empty list.
 * JSON fields use their explicit requiredness to preserve allowed JSON null.
 * Other unguarded null arguments are omitted, so explicit null and omission send
 * the same request for those parameters. Flags become 0/1; repeated, WHM-numbered
 * and suffix-numbered marker rules expand into OrderedFields without collapsing
 * names or losing their argument origin. Finite float scalars become strings that
 * round-trip exactly (see FloatEncoder), independent of precision, serialize_precision
 * and locale settings. ParameterExpander validates every list before dispatch.
 * A non-finite float has no wire representation and is rejected here. The handles
 * clear metadata before the runner encodes arguments. An argument or encoding the spec does not declare
 * is a generator defect.
 *
 * @internal
 */
final class ArgumentEncoder
{
    /**
     * Encode arguments in spec order under their wire names.
     *
     * Each declared parameter with a non-null argument is emitted under its wire
     * name using its declared encoding; explicit omit_empty on repeat skips [].
     * Explicit reject_empty policies check targets before null omission.
     * Undeclared arguments and duplicate declared names are generator defects.
     * Exact declared names retain ownership even when their arguments are omitted.
     * Maps cannot supply another input's exact name; only actual indexed expansions
     * own indexed names. Expanded collisions are invalid arguments; no partial field set is sent.
     *
     * @param array<mixed> $params Parameter specs keyed by PHP argument name.
     * @param array<string, mixed> $arguments Arguments keyed by PHP argument name.
     * @throws LogicException If arguments and specs disagree.
     * @throws InvalidArgumentException If a float, target, collection or expanded name is invalid.
     */
    public static function encode(array $params, #[SensitiveParameter] array $arguments): OrderedFields
    {
        foreach (array_keys($arguments) as $name) {
            if (!array_key_exists($name, $params)) {
                throw new LogicException('Unknown argument for this operation: ' . $name);
            }
        }

        $encoded = new OrderedFields();
        $wires = [];
        foreach ($params as $name => $param) {
            $wire = is_array($param) ? ($param['name'] ?? null) : null;
            if (is_string($wire)) {
                if (isset($wires[$wire])) {
                    throw new LogicException('Duplicate wire name in the parameter specs.');
                }
                $wires[$wire] = true;
                if (!is_string($name)) {
                    throw new LogicException('Invalid parameter argument name.');
                }
                if (($param['encoding'] ?? null) === 'json') {
                    JsonFieldEncoder::check($param['json'] ?? null);
                }
                if (($param['encoding'] ?? null) === 'records') {
                    RecordEncoder::check($param['records'] ?? null);
                }
                $exact = in_array($param['encoding'] ?? null, ['scalar', 'bool', 'repeat', 'numbered', 'json'], true);
                if (($param['encoding'] ?? null) === 'collection') {
                    $collection = CollectionEncoder::check($param['collection'] ?? null);
                    $exact = $collection['kind'] === 'repeat' || $collection['allow_scalar'];
                }
                if ($exact) {
                    $encoded->reserveExactName($name, $wire);
                }
            }
        }
        foreach ($params as $name => $param) {
            $value = $arguments[$name] ?? null;
            if (is_array($param) && ($param['reject_empty'] ?? false) === true) {
                self::requireTarget($value);
            }
            if (is_array($param) && ($param['encoding'] ?? null) === 'records') {
                RecordEncoder::append($encoded, $name, $param['records'] ?? null, $value);

                continue;
            }
            if (is_array($param) && ($param['encoding'] ?? null) === 'json') {
                $value = JsonFieldEncoder::encode($param['json'], array_key_exists($name, $arguments), $value);
            }
            if ($value === null) {
                continue;
            }

            $wire = is_array($param) ? ($param['name'] ?? null) : null;
            if (!is_array($param) || !is_string($wire)) {
                throw new LogicException('Invalid parameter spec for ' . $name . '.');
            }

            if (($param['omit_empty'] ?? false) === true && $param['encoding'] === 'repeat' && $value === []) {
                continue;
            }

            if ($param['encoding'] === 'collection') {
                CollectionEncoder::append($encoded, $name, $wire, $param['collection'], $value);

                continue;
            }

            $encodedValue = match ($param['encoding'] ?? null) {
                'scalar', 'json' => self::scalar($value),
                'bool' => is_bool($value)
                    ? ($value ? 1 : 0)
                    : throw new LogicException('Flag arguments must be booleans.'),
                'repeat' => new RepeatedList(self::multiple($value)),
                'numbered' => new NumberedList(self::multiple($value)),
                'suffix-numbered' => new SuffixNumberedList($value),
                'suffix-numbered-scalar' => new SuffixNumberedList($value, true),
                default => throw new LogicException('Unsupported parameter encoding for ' . $name . '.'),
            };
            $family = $encodedValue instanceof RepeatedList ? new stdClass() : null;
            foreach (ParameterExpander::expand($wire, $encodedValue) as $field) {
                $encoded->append($name, $field['name'], $field['value'], $family, is_string($encodedValue));
            }
        }

        return $encoded;
    }

    /**
     * Reject an absent, blank or Perl-falsey zero target for explicitly guarded inputs.
     *
     * This policy does not validate DNS names. Unflagged and generic calls retain
     * their existing behavior. Handles clear metadata before this check.
     *
     * @throws InvalidArgumentException If the target could become server-wide scope.
     */
    private static function requireTarget(#[SensitiveParameter] mixed $value): void
    {
        $items = is_array($value) ? $value : [$value];
        if ($items === [] || !array_is_list($items)) {
            throw new InvalidArgumentException('An explicit nonblank, nonzero target is required.');
        }
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '' || trim($item) === '0') {
                throw new InvalidArgumentException('An explicit nonblank, nonzero target is required.');
            }
        }
    }

    private static function scalar(#[SensitiveParameter] mixed $value): string|int
    {
        return match (true) {
            is_string($value), is_int($value) => $value,
            is_float($value) => FloatEncoder::encode($value),
            default => throw new LogicException('Scalar arguments must be strings or numbers.'),
        };
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
