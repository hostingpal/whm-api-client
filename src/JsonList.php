<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use SensitiveParameter;

/**
 * Explicitly choose a list where a reviewed request schema permits arbitrary JSON.
 *
 * Factories only tag input. The operation checks sequential keys, values and depth
 * after clearing response metadata, before I/O. Contents may contain secrets; do
 * not log this descriptor. Readonly does not provide a deep snapshot: references
 * in the supplied array retain normal PHP semantics.
 */
final readonly class JsonList
{
    /** @param array<mixed> $input */
    private function __construct(#[SensitiveParameter] private array $input)
    {
    }

    /**
     * Tag a sequential list as JSON, including an empty list.
     *
     * Nested unrestricted containers require JsonObject or JsonList too. Malformed
     * arrays are retained until the operation validates them after metadata reset.
     *
     * @param list<mixed> $items Intended sequential items; checked by the operation.
     */
    public static function fromArray(#[SensitiveParameter] array $items): self
    {
        return new self($items);
    }

    /**
     * Return the tagged input for the request encoder without validating or copying references.
     *
     * The returned array may contain secrets and has not been checked.
     *
     * @return array<mixed>
     * @internal
     */
    public function input(): array
    {
        return $this->input;
    }
}
