<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Accumulate expanded generated fields without losing order or provenance.
 *
 * Only one explicitly identified repeat family from one origin may repeat a wire
 * name. ParameterExpander and CollectionEncoder append expanded fields here rather
 * than merging associative maps. Names stay literal until encode(); diagnostics contain no data.
 * Multipart eligibility retains the original scalar-only body restriction.
 *
 * @phpstan-type Field array{
 *     origin: string, name: string, value: string|int, repeatFamily: object|null, multipart: bool,
 * }
 * @internal
 */
final class OrderedFields
{
    /** @var list<Field> */
    private array $fields = [];

    /** @var array<string, array{origin: string, repeatFamily: object|null}> */
    private array $seen = [];

    /**
     * Append one literal field, rejecting overlap before it can overwrite data.
     *
     * Reuse the same family object only for intentional repeats of one parameter.
     * Indexed, named and related-record fields leave the family null.
     *
     * @throws InvalidArgumentException If another field already owns the name.
     */
    public function append(
        #[SensitiveParameter] string $origin,
        #[SensitiveParameter] string $name,
        #[SensitiveParameter] string|int $value,
        #[SensitiveParameter] ?object $repeatFamily = null,
        bool $multipart = true,
    ): void {
        $previous = $this->seen[$name] ?? null;
        if (
            $previous !== null
            && ($repeatFamily === null
                || $previous['repeatFamily'] !== $repeatFamily
                || $previous['origin'] !== $origin)
        ) {
            throw new InvalidArgumentException('Generated parameters overlap another wire name.');
        }
        $this->seen[$name] = ['origin' => $origin, 'repeatFamily' => $repeatFamily];
        $this->fields[] = [
            'origin' => $origin, 'name' => $name, 'value' => $value,
            'repeatFamily' => $repeatFamily, 'multipart' => $multipart,
        ];
    }

    /** @return list<Field> Expanded fields in original argument and collection order. */
    public function all(): array
    {
        return $this->fields;
    }

    /** Encode each literal name and value exactly once, preserving repeats. */
    public function encode(): string
    {
        $parts = [];
        foreach ($this->fields as $field) {
            $parts[] = rawurlencode($field['name']) . '=' . rawurlencode((string) $field['value']);
        }
        return implode('&', $parts);
    }

    /**
     * Reject the case-sensitive api. prefix and exact route reservations before filtering.
     *
     * @param list<string> $reserved
     * @throws InvalidArgumentException If a generated name is reserved.
     */
    public function assertNoReserved(#[SensitiveParameter] array $reserved): void
    {
        foreach ($this->fields as $field) {
            if (str_starts_with($field['name'], 'api.') || in_array($field['name'], $reserved, true)) {
                throw new InvalidArgumentException('Generated parameters overlap a reserved routing field.');
            }
        }
    }

    /**
     * Return routing fields followed by the original fields with provenance intact.
     *
     * @param array<string, string|int> $routing
     * @throws InvalidArgumentException If a generated name is reserved.
     */
    public function withRouting(#[SensitiveParameter] array $routing): self
    {
        $this->assertNoReserved(array_keys($routing));
        $result = new self();
        foreach ($routing as $name => $value) {
            $result->append("\0routing", $name, $value);
        }
        foreach ($this->fields as $field) {
            $result->append(
                $field['origin'],
                $field['name'],
                $field['value'],
                $field['repeatFamily'],
                $field['multipart'],
            );
        }
        return $result;
    }
}
