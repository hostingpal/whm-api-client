<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use LogicException;
use SensitiveParameter;
use stdClass;

/**
 * Describe a raw decoded JSON payload without retaining values or private keys.
 *
 * Static field names come only from the checked effective return spec. Unknown
 * objects remain wildcarded at every depth, even if a descendant happens to
 * match a field name elsewhere in the schema. Wire compatibility delegates to
 * the client's validator; observations always describe the original value.
 *
 * @internal Development evidence tooling; never used by the package runtime.
 * @phpstan-type Analysis array{
 *     paths: array<string, list<string>>,
 *     discrepancies: list<array{path: string, kind: string}>
 * }
 */
final class Fingerprint
{
    /** @var array<string, array<string, true>> */
    private array $paths = [];

    /** @var array<string, array{path: string, kind: string}> */
    private array $discrepancies = [];

    /**
     * Analyze one present or omitted payload against its effective wire contract.
     *
     * Omission records missing separately from null and is a required-payload
     * discrepancy. Optional declared fields also record missing, without a
     * discrepancy. Empty containers supply no evidence about their item types.
     * Union selection follows the client's first validating branch; if no branch
     * validates, only a union-level mismatch is asserted and all keys are hidden.
     * No values or arbitrary exception text are included in the returned report.
     *
     * @param array<mixed> $type Generated return type spec, before unwrapping.
     * @return Analysis
     * @throws LogicException If the type spec or decoded JSON input is invalid.
     */
    public static function analyze(#[SensitiveParameter] mixed $payload, bool $present, array $type): array
    {
        SpecChecker::check([
            'function' => 'fingerprint',
            'method' => 'GET',
            'outcome' => 'flag',
            'params' => [],
            'returns' => $type,
        ]);
        $analysis = new self();
        if ($present) {
            $analysis->visit($payload, $type, '$');
        } else {
            $analysis->record('$', 'missing');
            $analysis->discrepancy('$', 'missing_required');
        }

        return $analysis->result();
    }

    /** @return Analysis */
    private function result(): array
    {
        ksort($this->paths, SORT_STRING);
        $paths = [];
        foreach ($this->paths as $path => $observed) {
            $types = array_keys($observed);
            sort($types, SORT_STRING);
            $paths[$path] = $types;
        }

        $discrepancies = array_values($this->discrepancies);
        usort($discrepancies, static fn (array $left, array $right): int =>
            strcmp($left['path'], $right['path']) ?: strcmp($left['kind'], $right['kind']));

        return ['paths' => $paths, 'discrepancies' => $discrepancies];
    }

    /** @param array<mixed> $type */
    private function visit(#[SensitiveParameter] mixed $value, array $type, string $path): void
    {
        if ($type['type'] === 'union' && !($value === null && ($type['nullable'] ?? false) === true)) {
            foreach (self::children($type, 'anyOf') as $option) {
                if (self::compatible($value, $option)) {
                    $this->visit($value, $option, $path);

                    return;
                }
            }

            $this->discrepancy($path, 'type_mismatch');
            $this->opaque($value, $path);

            return;
        }

        $this->record($path, self::observedType($value));
        if ($value === null) {
            if (!self::compatible($value, $type)) {
                $this->discrepancy($path, 'type_mismatch');
            }

            return;
        }

        if (
            $type['type'] === 'object'
            && ($value instanceof stdClass || ($value === [] && ($type['required'] ?? []) === []))
        ) {
            $this->object($value, $type, $path);
        } elseif ($type['type'] === 'list' && is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                $this->visit($item, self::child($type, 'items'), $path . '[]');
            }
        } elseif ($type['type'] === 'map' && ($value instanceof stdClass || $value === [])) {
            foreach ($value === [] ? [] : get_object_vars($value) as $item) {
                $this->visit($item, self::child($type, 'values'), $path . '.*');
            }
        } else {
            if (!self::compatible($value, $type)) {
                $this->discrepancy($path, 'type_mismatch');
            }

            $this->opaqueChildren($value, $path);
        }
    }

    /**
     * @param stdClass|array{} $value
     * @param array<mixed> $type
     */
    private function object(#[SensitiveParameter] stdClass|array $value, array $type, string $path): void
    {
        $fields = self::children($type, 'fields');
        $required = $type['required'] ?? [];
        foreach ($fields as $name => $field) {
            $name = (string) $name;
            $fieldPath = $path . '.' . $name;
            if ($value instanceof stdClass && property_exists($value, $name)) {
                $this->visit($value->{$name}, $field, $fieldPath);
            } else {
                $this->record($fieldPath, 'missing');
                if (is_array($required) && in_array($name, $required, true)) {
                    $this->discrepancy($fieldPath, 'missing_required');
                }
            }
        }

        foreach ($value instanceof stdClass ? get_object_vars($value) : [] as $name => $item) {
            if (!array_key_exists($name, $fields)) {
                $this->discrepancy($path . '.*', 'unknown_field');
                $this->opaque($item, $path . '.*');
            }
        }
    }

    /** Fingerprint unknown structure with no access to schema field names. */
    private function opaque(#[SensitiveParameter] mixed $value, string $path): void
    {
        $this->record($path, self::observedType($value));
        $this->opaqueChildren($value, $path);
    }

    private function opaqueChildren(#[SensitiveParameter] mixed $value, string $path): void
    {
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $item) {
                $this->opaque($item, $path . '.*');
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->opaque($item, $path . '[]');
            }
        }
    }

    private static function observedType(#[SensitiveParameter] mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) && is_finite($value) => 'float',
            is_float($value) => 'nonfinite-number',
            $value === '' => 'empty-string',
            is_string($value) && is_numeric($value) => 'numeric-string',
            is_string($value) => 'string',
            $value instanceof stdClass => get_object_vars($value) === [] ? 'empty-object' : 'object',
            is_array($value) && array_is_list($value) => $value === [] ? 'empty-list' : 'list',
            default => throw new LogicException('Fingerprint input must be decoded JSON.'),
        };
    }

    /** @param array<mixed> $type */
    private static function compatible(#[SensitiveParameter] mixed $value, array $type): bool
    {
        try {
            ShapeValidator::value($value, $type, new PayloadReader(new ResponseMetadata(200), 'evidence payload'));

            return true;
        } catch (InvalidResponseException) {
            return false;
        }
    }

    /**
     * @param array<mixed> $type
     * @return array<mixed>
     */
    private static function child(array $type, string $key): array
    {
        $child = $type[$key] ?? null;
        if (!is_array($child)) {
            throw new LogicException('Missing fingerprint child type.');
        }

        return $child;
    }

    /**
     * @param array<mixed> $type
     * @return array<array-key, array<mixed>>
     */
    private static function children(array $type, string $key): array
    {
        $children = $type[$key] ?? [];
        if (!is_array($children)) {
            throw new LogicException('Invalid fingerprint child types.');
        }

        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new LogicException('Invalid fingerprint child type.');
            }
        }

        return $children;
    }

    private function record(string $path, string $type): void
    {
        $this->paths[$path][$type] = true;
    }

    private function discrepancy(string $path, string $kind): void
    {
        $this->discrepancies[$path . ':' . $kind] = ['path' => $path, 'kind' => $kind];
    }
}
