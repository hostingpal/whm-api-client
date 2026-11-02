<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use LogicException;
use SensitiveParameter;

/**
 * A checked request schema, separate from response normalization rules.
 *
 * Descriptors use "type" (string, int, number, bool, flag, null, object, list or
 * union) and optional boolean "nullable". Objects require "fields" (string names
 * to descriptors), optionally "required" (a unique list of declared names) and
 * "values" (the descriptor for additional members). Lists require "items" and
 * optionally accept boolean "nonempty" (default false); strings accept the same
 * policy. True rejects exactly [] or the zero-byte string; no trimming, falsey
 * string or format policy is inferred. Other descriptor types reject this key.
 * Unions require at least two scalar "anyOf" branches with disjoint PHP input
 * types. A flag accepts native bool only, emitting integer 0/1. Numbers accept
 * int or finite float; no response-side accepted variants apply to requests.
 *
 * Unknown descriptor keys, opaque/mixed nodes, container alternatives and
 * overlapping scalar alternatives are rejected, including omitted branches.
 * No enum, range or format validation is inferred. The schema may describe up
 * to 511 nested containers under json_validate(..., 512); scalar leaves and
 * scalar alternative descriptors do not consume a container level.
 * Descriptor recursion is separately bounded, including recursive unions.
 *
 * @internal
 */
final readonly class StructuredJsonSchema
{
    public const MAX_DEPTH = 512;

    /**
     * @param array<string, self> $fields
     * @param list<string> $required
     * @param list<self> $alternatives
     */
    private function __construct(
        public string $type,
        public bool $nullable,
        #[SensitiveParameter] public array $fields = [],
        #[SensitiveParameter] public array $required = [],
        #[SensitiveParameter] public ?self $items = null,
        #[SensitiveParameter] public ?self $values = null,
        #[SensitiveParameter] public array $alternatives = [],
        public bool $nonempty = false,
    ) {
    }

    /**
     * Validate every descriptor before processing caller input.
     *
     * @throws LogicException If any descriptor is malformed, ambiguous or too deep.
     */
    public static function from(#[SensitiveParameter] mixed $schema): self
    {
        return self::node($schema, 0, 0);
    }

    /** Whether this root accepts explicit JSON null, including a scalar alternative. */
    public function acceptsNull(): bool
    {
        if ($this->nullable || $this->type === 'null') {
            return true;
        }
        foreach ($this->alternatives as $branch) {
            if ($branch->acceptsNull()) {
                return true;
            }
        }
        return false;
    }

    private static function node(#[SensitiveParameter] mixed $schema, int $containers, int $descriptors): self
    {
        if ($descriptors > self::MAX_DEPTH || !is_array($schema)) {
            throw new LogicException('Invalid structured JSON schema.');
        }
        $type = $schema['type'] ?? null;
        $nullable = $schema['nullable'] ?? false;
        if (
            !in_array($type, ['string', 'int', 'number', 'bool', 'flag', 'null', 'object', 'list', 'union'], true)
            || !is_bool($nullable) || (array_key_exists('nullable', $schema) && $schema['nullable'] === null)
        ) {
            throw new LogicException('Invalid structured JSON schema.');
        }
        $allowed = match ($type) {
            'object' => ['type', 'nullable', 'fields', 'required', 'values'],
            'list' => ['type', 'nullable', 'items', 'nonempty'],
            'string' => ['type', 'nullable', 'nonempty'],
            'union' => ['type', 'nullable', 'anyOf'],
            default => ['type', 'nullable'],
        };
        foreach ($schema as $key => $unused) {
            if (!in_array($key, $allowed, true)) {
                throw new LogicException('Unsupported structured JSON schema member.');
            }
        }
        if (($type === 'object' || $type === 'list') && $containers >= self::MAX_DEPTH - 1) {
            throw new LogicException('Structured JSON schema exceeds the nesting limit.');
        }
        if ($type === 'object') {
            return self::object($schema, $nullable, $containers + 1, $descriptors + 1);
        }
        if ($type === 'list' || $type === 'string') {
            $nonempty = array_key_exists('nonempty', $schema) ? $schema['nonempty'] : false;
            if (!is_bool($nonempty)) {
                throw new LogicException('Invalid structured JSON nonempty policy.');
            }
            if ($type === 'string') {
                return new self($type, $nullable, nonempty: $nonempty);
            }
            $items = self::node($schema['items'] ?? null, $containers + 1, $descriptors + 1);
            return new self($type, $nullable, items: $items, nonempty: $nonempty);
        }
        if ($type === 'union') {
            return self::union($schema, $nullable, $containers, $descriptors + 1);
        }
        return new self($type, $nullable);
    }

    /** @param array<mixed> $schema */
    private static function object(
        #[SensitiveParameter] array $schema,
        bool $nullable,
        int $containers,
        int $descriptors,
    ): self {
        if (!isset($schema['fields']) || !is_array($schema['fields'])) {
            throw new LogicException('Structured JSON objects require field schemas.');
        }
        $fields = [];
        foreach ($schema['fields'] as $name => $field) {
            if (!is_string($name) || preg_match('//u', $name) !== 1) {
                throw new LogicException('Structured JSON field names require UTF-8 strings.');
            }
            $fields[$name] = self::node($field, $containers, $descriptors);
        }
        $required = $schema['required'] ?? [];
        if (
            !is_array($required) || !array_is_list($required)
            || (array_key_exists('required', $schema) && $schema['required'] === null)
        ) {
            throw new LogicException('Invalid structured JSON required fields.');
        }
        $seen = [];
        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $fields) || isset($seen[$name])) {
                throw new LogicException('Invalid structured JSON required fields.');
            }
            $seen[$name] = true;
        }
        $values = array_key_exists('values', $schema)
            ? self::node($schema['values'], $containers, $descriptors) : null;
        return new self('object', $nullable, $fields, array_keys($seen), values: $values);
    }

    /** @param array<mixed> $schema */
    private static function union(
        #[SensitiveParameter] array $schema,
        bool $nullable,
        int $containers,
        int $descriptors,
    ): self {
        $branches = $schema['anyOf'] ?? null;
        if (!is_array($branches) || !array_is_list($branches) || count($branches) < 2) {
            throw new LogicException('Structured JSON alternatives require scalar branches.');
        }
        $alternatives = [];
        $seen = [];
        foreach ($branches as $branch) {
            $checked = self::node($branch, $containers, $descriptors);
            $domains = match ($checked->type) {
                'string' => ['string'],
                'int' => ['int'],
                'number' => ['int', 'float'],
                'bool', 'flag' => ['bool'],
                'null' => ['null'],
                default => throw new LogicException('Structured JSON alternatives require scalar branches.'),
            };
            if ($checked->nullable && $checked->type !== 'null') {
                $domains[] = 'null';
            }
            foreach ($domains as $domain) {
                if (isset($seen[$domain])) {
                    throw new LogicException('Ambiguous structured JSON scalar alternatives.');
                }
                $seen[$domain] = true;
            }
            $alternatives[] = $checked;
        }
        return new self('union', $nullable, alternatives: $alternatives);
    }
}
