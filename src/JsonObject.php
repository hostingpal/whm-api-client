<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use SensitiveParameter;

/**
 * Explicitly choose an object where a reviewed request schema permits arbitrary JSON.
 *
 * Factories tag input without validating its contents. The operation checks member
 * names, values, duplicates and depth after clearing response metadata, before I/O.
 * Nested unrestricted containers require JsonObject or JsonList too. Contents may
 * contain secrets; do not log this descriptor. Readonly does not create a deep
 * snapshot: PHP references in the supplied array retain their normal semantics.
 */
final readonly class JsonObject
{
    /** @param array<mixed> $input */
    private function __construct(#[SensitiveParameter] private array $input, private bool $entryList)
    {
    }

    /**
     * Tag a map of string member names as a JSON object, including an empty object.
     *
     * Integer keys (including PHP-coerced numeric strings) fail during encoding.
     * Use fromEntries() to represent numeric JSON member names without key coercion.
     *
     * @param array<string, mixed> $members Intended member map; checked by the operation.
     */
    public static function fromArray(#[SensitiveParameter] array $members): self
    {
        return new self($members, false);
    }

    /**
     * Tag ordered name/value records as a JSON object without PHP key coercion.
     *
     * Each record must have exactly name and value; names must be unique UTF-8
     * strings. Numeric and leading-NUL names are preserved. Checks occur during
     * encoding, so malformed contents still enter the operation's metadata reset.
     * An empty entry list represents an empty object.
     *
     * @param list<array{name: string, value: mixed}> $entries Intended records; checked by the operation.
     */
    public static function fromEntries(#[SensitiveParameter] array $entries): self
    {
        return new self($entries, true);
    }

    /**
     * Return the tagged input for the request encoder, without validating or copying references.
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

    /**
     * Whether the input uses ordered name/value records rather than map keys.
     *
     * @internal Used only to interpret input during request encoding.
     */
    public function isEntryList(): bool
    {
        return $this->entryList;
    }
}
