<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;
use LogicException;

/**
 * Describe and read the generated per-operation spec format.
 *
 * tools/api-map generates one spec constant per operation in the group's Spec
 * class. Keys: function (wire name), module (UAPI only), method ('GET' or
 * 'POST'), outcome ('flag' or 'unchecked'), params (PHP argument name =>
 * array{name: wire name, encoding: 'scalar'|'bool'|'repeat'|'numbered'}),
 * returns (a type spec, or null for void), and optional unwrap (a field of the
 * validated object to return). Type specs are documented on ShapeValidator's
 * callers in the generated-client design. A malformed spec is a generator defect.
 *
 * @internal
 */
final class OperationSpec
{
    /**
     * Map the spec's HTTP method to the request method.
     *
     * The spec's method must be exactly GET or POST; any other value means the
     * generator emitted a spec this runtime does not support.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the method is not GET or POST.
     */
    public static function method(array $spec): RequestMethod
    {
        return RequestMethod::tryFrom(self::text($spec, 'method'))
            ?? throw new LogicException('Unsupported operation method.');
    }

    /**
     * Map the spec's outcome policy name to the policy.
     *
     * Only 'flag' and 'unchecked' are generated; both correspond to the
     * OutcomePolicy cases of the same meaning.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the policy is unknown.
     */
    public static function outcome(array $spec): OutcomePolicy
    {
        return match (self::text($spec, 'outcome')) {
            'flag' => OutcomePolicy::Flag,
            'unchecked' => OutcomePolicy::Unchecked,
            default => throw new LogicException('Unsupported outcome policy.'),
        };
    }

    /**
     * Read a required string field of the spec.
     *
     * Used for the wire names and policy keys that the generated spec must
     * declare as strings; a missing or non-string value is a generator defect.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the field is missing or not a string.
     */
    public static function text(array $spec, string $key): string
    {
        $value = $spec[$key] ?? null;

        return is_string($value) ? $value : throw new LogicException('Operation spec is missing "' . $key . '".');
    }
}
