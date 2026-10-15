<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use LogicException;

/**
 * Check a whole generated operation spec before any request is sent.
 *
 * OperationRunner::run() calls check() before it encodes arguments, so a defective
 * spec raises LogicException without I/O, whichever arguments a call supplies and
 * whichever payload the server would return. The check covers the operation keys
 * described on OperationSpec, every parameter spec (including those of arguments a
 * call omits), and the complete ShapeValidator type grammar recursively: every list
 * item, map value, object field and union branch, and the type of nullable specs,
 * which the validator never inspects for a null payload. It also rejects duplicate
 * wire names across parameters (the second would silently overwrite the first),
 * "unwrap" on a nullable object result (a null payload could never be unwrapped),
 * and "required" entries that are not strings naming declared fields (the validator
 * compares required names as strings). Keys this runtime does not read are ignored,
 * as the runner and validator ignore them.
 *
 * Specs are plain constant arrays and have no identity, so successful checks are
 * cached per process under a hash of the serialized spec: a repeated call costs one
 * serialize() and one xxh128 hash, the cache grows only with distinct sound specs
 * (one per generated operation), and a defective spec is rechecked and rejected on
 * every call.
 *
 * @internal
 */
final class SpecChecker
{
    private const ENCODINGS = ['scalar', 'bool', 'repeat', 'numbered'];

    /** Accepted wire variants per type; types not listed accept none. */
    private const ACCEPT = [
        'int' => ['numeric-string'],
        'number' => ['numeric-string'],
        'bool' => ['int-flag', 'string-flag'],
        'flag' => ['string-flag', 'bool'],
    ];

    private const TYPES = ['string', 'int', 'number', 'bool', 'flag', 'mixed', 'list', 'map', 'object', 'union'];

    /** @var array<string, true> Hashes of specs that passed the check. */
    private static array $sound = [];

    /**
     * Reject a defective operation spec; sound specs are remembered.
     *
     * @param array<mixed> $spec
     * @throws LogicException If any part of the spec is defective.
     */
    public static function check(array $spec): void
    {
        $key = hash('xxh128', serialize($spec));
        if (isset(self::$sound[$key])) {
            return;
        }

        self::operation($spec);
        self::$sound[$key] = true;
    }

    /**
     * Check one type spec and everything nested in it.
     *
     * @throws LogicException If the type spec is defective.
     */
    public static function type(mixed $type): void
    {
        if (!is_array($type) || !in_array($type['type'] ?? null, self::TYPES, true)) {
            throw new LogicException('Unsupported type spec.');
        }

        $kind = $type['type'];
        self::flag($type, 'nullable');
        $accept = $type['accept'] ?? [];
        if (!is_array($accept) || !array_is_list($accept)) {
            throw new LogicException('Invalid "accept" type spec.');
        }

        foreach ($accept as $variant) {
            if (!in_array($variant, self::ACCEPT[$kind] ?? [], true)) {
                throw new LogicException('Unsupported accepted variant for a ' . $kind . ' type spec.');
            }
        }

        if (isset($type['preserve']) && ($kind !== 'number' || !is_bool($type['preserve']))) {
            throw new LogicException('Invalid "preserve" type spec.');
        }

        match ($kind) {
            'list' => self::type($type['items'] ?? null),
            'map' => self::type($type['values'] ?? null),
            'object' => self::object($type),
            'union' => self::union($type),
            default => null,
        };
    }

    /**
     * Check the operation-level keys, the parameters and the return spec.
     *
     * @param array<mixed> $spec
     */
    private static function operation(array $spec): void
    {
        OperationSpec::text($spec, 'function');
        OperationSpec::method($spec);
        OperationSpec::outcome($spec);
        if (array_key_exists('module', $spec)) {
            OperationSpec::text($spec, 'module');
        }

        self::params($spec['params'] ?? null);

        if (!array_key_exists('returns', $spec)) {
            throw new LogicException('Operation spec is missing "returns".');
        }

        $returns = $spec['returns'];
        if ($returns !== null) {
            self::type($returns);
        }

        self::flag($spec, 'optional');
        if (($spec['optional'] ?? false) === true && $returns === null) {
            throw new LogicException('Invalid optional spec.');
        }

        $unwrap = $spec['unwrap'] ?? null;
        if ($unwrap === null) {
            return;
        }

        $fields = is_array($returns) ? ($returns['fields'] ?? null) : null;
        if (
            !is_string($unwrap)
            || !is_array($returns)
            || $returns['type'] !== 'object'
            || ($returns['nullable'] ?? false) === true
            || !is_array($fields)
            || !array_key_exists($unwrap, $fields)
        ) {
            throw new LogicException('Invalid unwrap spec.');
        }
    }

    /** Check every parameter spec, whether or not a call supplies its argument. */
    private static function params(mixed $params): void
    {
        if (!is_array($params)) {
            throw new LogicException('Operation spec is missing "params".');
        }

        $wires = [];
        foreach ($params as $name => $param) {
            $wire = is_array($param) ? ($param['name'] ?? null) : null;
            if (
                !is_string($name)
                || !is_string($wire)
                || $wire === ''
                || !in_array($param['encoding'] ?? null, self::ENCODINGS, true)
            ) {
                throw new LogicException('Invalid parameter spec for ' . $name . '.');
            }

            if (isset($wires[$wire])) {
                throw new LogicException('Duplicate wire name in the parameter specs: ' . $wire);
            }

            $wires[$wire] = true;
        }
    }

    /**
     * Check declared fields and that every required name is a declared field.
     *
     * @param array<mixed> $type
     */
    private static function object(array $type): void
    {
        $fields = $type['fields'] ?? [];
        $required = $type['required'] ?? [];
        if (!is_array($fields) || !is_array($required) || !array_is_list($required)) {
            throw new LogicException('Invalid object type spec.');
        }

        foreach ($fields as $field) {
            self::type($field);
        }

        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $fields)) {
                throw new LogicException('Required names must be strings naming declared fields.');
            }
        }
    }

    /**
     * Check that a union has at least one branch and that every branch is sound.
     *
     * @param array<mixed> $type
     */
    private static function union(array $type): void
    {
        $options = $type['anyOf'] ?? null;
        if (!is_array($options) || $options === [] || !array_is_list($options)) {
            throw new LogicException('Invalid union type spec.');
        }

        foreach ($options as $option) {
            self::type($option);
        }
    }

    /**
     * Require an optional boolean key to be a boolean when present; null means absent.
     *
     * @param array<mixed> $spec
     */
    private static function flag(array $spec, string $key): void
    {
        if (!is_bool($spec[$key] ?? false)) {
            throw new LogicException('Invalid "' . $key . '" spec.');
        }
    }
}
