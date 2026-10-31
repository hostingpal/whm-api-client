<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\SuffixNumberedList;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use SensitiveParameter;
use stdClass;

/**
 * Validate a generic multipart declaration without preparing any file sources.
 *
 * Query null omission belongs only to this entrypoint. Legacy form and JSON
 * parameters retain their existing policies. Explicit part names stay literal.
 *
 * @internal
 */
final readonly class MultipartInput
{
    /**
     * @param list<array{name: string, value: string|Upload}> $parts
     */
    private function __construct(
        #[SensitiveParameter] public array $parts,
        #[SensitiveParameter] public OrderedFields $query,
    ) {
    }

    /**
     * Preserve generated query provenance and declaration collision semantics.
     *
     * @param list<array{name: string, value: string|Upload}> $parts
     */
    public static function generated(
        #[SensitiveParameter] array $parts,
        #[SensitiveParameter] OrderedFields $query,
    ): self {
        $reserved = ['cpanel.user', 'cpanel.module', 'cpanel.function'];
        $validated = self::present($parts, [], $reserved);
        $names = array_fill_keys(array_column($validated->parts, 'name'), true);
        foreach ($query->all() as $field) {
            JsonRouting::assertAllowed($field['name'], $reserved);
            if (isset($names[$field['name']])) {
                throw new InvalidArgumentException('Generated multipart fields overlap.');
            }
        }
        return new self($validated->parts, $query);
    }

    /**
     * Check every declaration and emitted wire name before any source I/O.
     *
     * Query names follow the existing representation; part names use safe tokens.
     * Raw reserved keys reject by presence, including null; only emitted ordinary
     * query fields participate in collisions with intentional repeated parts.
     *
     * @param array<array-key, mixed> $parts
     * @param array<array-key, mixed> $query
     * @param list<string> $reserved
     */
    public static function present(
        #[SensitiveParameter] array $parts,
        #[SensitiveParameter] array $query,
        #[SensitiveParameter] array $reserved,
    ): self {
        try {
            if (!array_is_list($parts)) {
                throw new InvalidArgumentException();
            }
            $names = [];
            $validated = [];
            foreach ($parts as $part) {
                if (
                    !is_array($part) || count($part) !== 2 || !isset($part['name'])
                    || !is_string($part['name']) || preg_match('/\A[A-Za-z0-9_.-]+\z/', $part['name']) !== 1
                    || !isset($part['value']) || (!is_string($part['value']) && !$part['value'] instanceof Upload)
                ) {
                    throw new InvalidArgumentException();
                }
                JsonRouting::assertAllowed($part['name'], $reserved);
                $names[$part['name']] = true;
                $validated[] = ['name' => $part['name'], 'value' => $part['value']];
            }
            $filtered = new OrderedFields();
            $repeatFamily = new stdClass();
            $seen = [];
            $suffixNames = [];
            foreach ($query as $name => $value) {
                if (!is_string($name)) {
                    throw new InvalidArgumentException();
                }
                JsonRouting::assertAllowed($name, $reserved);
                if ($value === null) {
                    continue;
                }
                $suffix = $value instanceof SuffixNumberedList;
                foreach (ParameterExpander::expand($name, $value) as $field) {
                    JsonRouting::assertAllowed($field['name'], $reserved);
                    $key = $field['name'];
                    if (isset($names[$key]) || ($suffix && isset($seen[$key])) || isset($suffixNames[$key])) {
                        throw new InvalidArgumentException();
                    }
                    $seen[$key] = true;
                    if ($suffix) {
                        $suffixNames[$key] = true;
                    }
                    // Generic query fields keep legacy repeated-name behavior;
                    // only suffix-family overlap is forbidden within the query.
                    $filtered->append('query', $key, $field['value'], $repeatFamily);
                }
            }
        } catch (InvalidArgumentException) {
            // Expansion diagnostics in other entrypoints can contain caller names.
            throw new InvalidArgumentException('Invalid multipart parts or query fields.');
        }

        return new self($validated, $filtered);
    }
}
