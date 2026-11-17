<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use stdClass;

/**
 * Explicit strict indexed/repeated list and named-map grammar, independent of legacy markers.
 *
 * @phpstan-type CollectionSpec array{
 *     kind: 'repeat', item_type: 'string'|'int'|'bool'|'string|int', allow_scalar: bool, empty: 'reject'
 * }|array{
 *     kind: 'indexed', item_type: 'string'|'int'|'bool'|'string|int', allow_scalar: bool, empty: 'reject',
 *     prefix: string, separator: ''|'-', start: int<0, 2147483647>
 * }|array{
 *     kind: 'map', item_type: 'string'|'int'|'bool'|'string|int', allow_scalar: false,
 *     empty: 'allow'|'reject', prefix: string, reject_prefixed_keys?: true
 * }
 * @internal
 */
final class CollectionEncoder
{
    /**
     * Validate every declared fact, even when the argument will be omitted.
     *
     * @return CollectionSpec
     * @throws LogicException If the descriptor is incomplete or unsupported.
     */
    public static function check(mixed $spec): array
    {
        if (
            !is_array($spec)
            || !in_array($spec['kind'] ?? null, ['indexed', 'repeat', 'map'], true)
            || !in_array($spec['item_type'] ?? null, ['string', 'int', 'bool', 'string|int'], true)
            || !is_bool($spec['allow_scalar'] ?? null)
            || !in_array($spec['empty'] ?? null, $spec['kind'] === 'map' ? ['allow', 'reject'] : ['reject'], true)
        ) {
            throw new LogicException('Invalid collection descriptor.');
        }
        $keys = ['kind', 'item_type', 'allow_scalar', 'empty'];
        if ($spec['kind'] === 'map') {
            if (
                $spec['allow_scalar'] !== false
                || !is_string($spec['prefix'] ?? null)
                || preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)?$/D', $spec['prefix']) !== 1
            ) {
                throw new LogicException('Invalid named-map rules.');
            }
            $keys[] = 'prefix';
            if (array_key_exists('reject_prefixed_keys', $spec)) {
                if ($spec['reject_prefixed_keys'] !== true || $spec['prefix'] === '') {
                    throw new LogicException('Invalid named-map prefix rejection policy.');
                }
                $keys[] = 'reject_prefixed_keys';
            }
        }
        if ($spec['kind'] === 'indexed') {
            if (
                !is_string($spec['prefix'] ?? null)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $spec['prefix']) !== 1
                || !in_array($spec['separator'] ?? null, ['', '-'], true)
                || !is_int($spec['start'] ?? null)
                || $spec['start'] < 0
                || $spec['start'] > 2147483647
            ) {
                throw new LogicException('Invalid collection index rules.');
            }
            array_push($keys, 'prefix', 'separator', 'start');
        }
        if (array_diff(array_keys($spec), $keys) !== []) {
            throw new LogicException('Unsupported collection descriptor fields.');
        }
        if ($spec['kind'] === 'map') {
            return ['kind' => 'map', 'item_type' => $spec['item_type'], 'allow_scalar' => false,
                'empty' => $spec['empty'], 'prefix' => $spec['prefix']]
                + (isset($spec['reject_prefixed_keys']) ? ['reject_prefixed_keys' => true] : []);
        }
        if ($spec['kind'] === 'indexed') {
            return ['kind' => 'indexed', 'item_type' => $spec['item_type'], 'allow_scalar' => $spec['allow_scalar'],
                'empty' => 'reject', 'prefix' => $spec['prefix'], 'separator' => $spec['separator'],
                'start' => $spec['start']];
        }

        return ['kind' => 'repeat', 'item_type' => $spec['item_type'], 'allow_scalar' => $spec['allow_scalar'],
            'empty' => 'reject'];
    }

    /**
     * Append every strict item in original order, retaining argument provenance.
     *
     * For list kinds, a scalar uses the original unsuffixed name; arrays always
     * use the list pattern. No empty, sparse or keyed array becomes a valid list.
     * Maps use the explicit literal prefix and preserve every valid caller key.
     *
     * @throws InvalidArgumentException If the input does not match its descriptor.
     */
    public static function append(
        #[SensitiveParameter] OrderedFields $fields,
        #[SensitiveParameter] string $origin,
        #[SensitiveParameter] string $wire,
        mixed $spec,
        #[SensitiveParameter] mixed $value,
    ): void {
        $spec = self::check($spec);
        if ($spec['kind'] === 'map') {
            self::appendMap($fields, $origin, $spec, $value);

            return;
        }
        if (!is_array($value)) {
            if ($spec['allow_scalar'] !== true) {
                throw new InvalidArgumentException('This argument requires a nonempty sequential list.');
            }
            $fields->append($origin, $wire, self::item($spec['item_type'], $value), null, false);

            return;
        }
        if ($value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('Collection arguments require a nonempty sequential list.');
        }
        $family = $spec['kind'] === 'repeat' ? new stdClass() : null;
        foreach ($value as $position => $item) {
            $name = $wire;
            if ($spec['kind'] === 'indexed') {
                if ($position > PHP_INT_MAX - $spec['start']) {
                    throw new InvalidArgumentException('Collection index exceeds the integer range.');
                }
                $name = $spec['prefix'] . $spec['separator'] . ($spec['start'] + $position);
            }
            $fields->append($origin, $name, self::item($spec['item_type'], $item), $family, false);
        }
    }

    /**
     * Append literal caller keys without normalization, retaining ownership.
     *
     * @param array{kind: 'map', item_type: 'string'|'int'|'bool'|'string|int',
     *     allow_scalar: false, empty: 'allow'|'reject', prefix: string, reject_prefixed_keys?: true} $spec
     */
    private static function appendMap(
        #[SensitiveParameter] OrderedFields $fields,
        #[SensitiveParameter] string $origin,
        array $spec,
        #[SensitiveParameter] mixed $value,
    ): void {
        if (!is_array($value) || ($value === [] && $spec['empty'] === 'reject')) {
            throw new InvalidArgumentException('This argument requires a map with the declared empty policy.');
        }
        foreach ($value as $key => $item) {
            if (
                !is_string($key) || $key === '' || preg_match('//u', $key) !== 1
                || preg_match('/[\\x00-\\x1F\\x7F]/', $key) !== 0
            ) {
                throw new InvalidArgumentException('Map keys must be nonempty UTF-8 strings without control bytes.');
            }
            if (($spec['reject_prefixed_keys'] ?? false) && str_starts_with($key, $spec['prefix'])) {
                throw new InvalidArgumentException('Map keys must omit the declared wire prefix.');
            }
            $fields->appendMap($origin, $spec['prefix'] . $key, self::item($spec['item_type'], $item));
        }
    }

    private static function item(string $type, #[SensitiveParameter] mixed $value): string|int
    {
        return match (true) {
            $type === 'bool' && is_bool($value) => (int) $value,
            ($type === 'string' || $type === 'string|int') && is_string($value) => $value,
            ($type === 'int' || $type === 'string|int') && is_int($value) => $value,
            default => throw new InvalidArgumentException('Collection item does not match its declared strict type.'),
        };
    }
}
