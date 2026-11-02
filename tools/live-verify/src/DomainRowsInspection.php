<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use JsonException;
use LogicException;
use SensitiveParameter;
use stdClass;

/**
 * Name the keys a response carries beyond its effective contract, with their JSON types and never their values.
 *
 * This is the private inspection step that precedes typing a field: the strict review masks undeclared keys, so
 * only a reading of the original response can say what they are called. It walks one payload against its effective
 * return spec as Fingerprint does, choosing a union's first validating branch, and lists each undeclared key's path
 * and observed type, and each declared path whose value does not fit. A key's name is printed only where the reviewed
 * contract declares named fields and only when it is a plain identifier: an undeclared key in such an object. Every
 * key beneath an undeclared key, in an object that declares no fields, or at a map position is shown as `<key>` or
 * `*`, as such keys can be data, such as domain, user or token names. Values, upstream text and raw bodies never
 * appear in the result, which a caller may print but never persists.
 *
 * @internal Development evidence tooling; never used by the package runtime.
 */
final class DomainRowsInspection
{
    private const NAME = '/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/';

    /** @var array<string, true> */
    private array $lines = [];

    private function __construct()
    {
    }

    /**
     * Describe one original response body from its route's payload location, as sorted unique lines.
     *
     * @param array<mixed> $type The effective return spec of the call that produced the body; empty for a void one.
     * @return list<string>
     */
    public static function undeclared(string $route, #[SensitiveParameter] ?string $raw, array $type): array
    {
        if ($raw === null) {
            return ['unreadable'];
        }
        try {
            $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['unreadable'];
        }
        $envelope = match ($route) {
            'direct-uapi' => $document instanceof stdClass && property_exists($document, 'result')
                ? $document->result : $document,
            'bridged-uapi' => $document instanceof stdClass && ($document->data ?? null) instanceof stdClass
                ? ($document->data->uapi ?? null) : null,
            default => $document,
        };
        if (!$envelope instanceof stdClass || !property_exists($envelope, 'data')) {
            return ['no payload'];
        }
        if (!is_string($type['type'] ?? null)) {
            return ['no return contract: ' . self::kind($envelope->data)];
        }
        $walk = new self();
        $walk->visit($envelope->data, $type, '$');
        $lines = array_keys($walk->lines);
        sort($lines, SORT_STRING);

        return $lines;
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
            $this->lines['mismatch ' . $path . ': ' . self::kind($value)] = true;

            return;
        }
        if ($type['type'] === 'object' && ($value instanceof stdClass || $value === [])) {
            $fields = self::children($type, 'fields');
            foreach ($value === [] ? [] : get_object_vars($value) as $name => $item) {
                $name = (string) $name;
                if (array_key_exists($name, $fields)) {
                    $this->visit($item, $fields[$name], $path . '.' . $name);
                } elseif (is_array($type['values'] ?? null)) {
                    $this->visit($item, $type['values'], $path . '.*');
                } else {
                    $this->describe($item, $path . '.' . ($fields === [] ? '<key>' : self::name($name)));
                }
            }

            return;
        }
        $items = $type['items'] ?? null;
        if ($type['type'] === 'list' && is_array($value) && array_is_list($value) && is_array($items)) {
            foreach ($value as $item) {
                $this->visit($item, $items, $path . '[]');
            }

            return;
        }
        $values = $type['values'] ?? null;
        if ($type['type'] === 'map' && ($value instanceof stdClass || $value === []) && is_array($values)) {
            foreach ($value === [] ? [] : get_object_vars($value) as $item) {
                $this->visit($item, $values, $path . '.*');
            }

            return;
        }
        if (!self::compatible($value, $type)) {
            $this->lines['mismatch ' . $path . ': ' . self::kind($value)] = true;
        }
    }

    /** List an undeclared subtree by type only; nothing beneath an undeclared key is a declared name. */
    private function describe(#[SensitiveParameter] mixed $value, string $path): void
    {
        $this->lines['undeclared ' . $path . ': ' . self::kind($value)] = true;
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $item) {
                $this->describe($item, $path . '.<key>');
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->describe($item, $path . '[]');
            }
        }
    }

    private static function name(string $name): string
    {
        return preg_match(self::NAME, $name) === 1 ? $name : '<key>';
    }

    private static function kind(#[SensitiveParameter] mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            $value === '' => 'empty-string',
            is_string($value) && is_numeric($value) => 'numeric-string',
            is_string($value) => 'string',
            $value instanceof stdClass => get_object_vars($value) === [] ? 'empty-object' : 'object',
            is_array($value) && array_is_list($value) => $value === [] ? 'empty-list' : 'list',
            default => throw new LogicException('Inspection input must be decoded JSON.'),
        };
    }

    /** @param array<mixed> $type */
    private static function compatible(#[SensitiveParameter] mixed $value, array $type): bool
    {
        try {
            ShapeValidator::value($value, $type, new PayloadReader(new ResponseMetadata(200), 'inspection payload'));

            return true;
        } catch (InvalidResponseException) {
            return false;
        }
    }

    /**
     * @param array<mixed> $type
     * @return array<array-key, array<mixed>>
     */
    private static function children(array $type, string $key): array
    {
        $children = $type[$key] ?? [];
        if (!is_array($children)) {
            throw new LogicException('Invalid inspection child types.');
        }
        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new LogicException('Invalid inspection child type.');
            }
        }

        return $children;
    }
}
