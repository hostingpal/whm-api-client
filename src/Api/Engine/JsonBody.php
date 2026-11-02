<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Retain validated JSON bytes independently of the operation's field representation.
 *
 * Native null means an absent body (no bytes or Content-Type); the string 'null'
 * is a present JSON document. Raw entrypoints always supply a present document.
 * Future typed dispatch resolves omission before selecting either constructor.
 * No decoded value ever supplies outgoing content.
 *
 * @internal
 */
final readonly class JsonBody
{
    private function __construct(#[SensitiveParameter] public ?string $content)
    {
    }

    /** Represent an omitted JSON body without a Content-Type. */
    public static function absent(): self
    {
        return new self(null);
    }

    /** Validate strict UTF-8 JSON at the shared depth boundary, preserving every byte. */
    public static function present(#[SensitiveParameter] string $json): self
    {
        if (!json_validate($json, 512)) {
            throw new InvalidArgumentException(
                'The JSON body must be a valid UTF-8 JSON document within the depth limit.',
            );
        }

        return new self($json);
    }

    /**
     * Require an object root and reject reserved member names by presence.
     *
     * Associative decoding avoids stdClass's leading-NUL property restriction.
     * Numeric-looking keys and duplicate ordinary members are allowed. Only names
     * are inspected; decoded values never affect bytes or endpoint validation.
     *
     * @param list<string> $reserved Additional exact names reserved by the route.
     */
    public function assertNoReservedRoot(array $reserved): void
    {
        if ($this->content === null) {
            return;
        }
        if (!str_starts_with(ltrim($this->content, " \t\r\n"), '{')) {
            throw new InvalidArgumentException('The JSON body must have an object root.');
        }
        $members = json_decode($this->content, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($members)) {
            throw new InvalidArgumentException('The JSON body must have an object root.');
        }
        foreach (array_keys($members) as $name) {
            JsonRouting::assertAllowed((string) $name, $reserved);
        }
    }

    /**
     * Insert library-owned WHM routing before every caller member without re-encoding.
     *
     * The shared JSON executor has checked caller root reservations. Whitespace,
     * duplicate ordinary members, escaped names and numeric tokens remain literal.
     * An absent body stays absent; typed WHM dispatch can reuse this insertion.
     */
    public function withWhmVersion(): self
    {
        $this->assertNoReservedRoot([]);
        if ($this->content === null) {
            return $this;
        }
        $offset = strspn($this->content, " \t\r\n") + 1;
        $rest = substr($this->content, $offset);
        $separator = str_starts_with(ltrim($rest, " \t\r\n"), '}') ? '' : ',';

        return new self(substr($this->content, 0, $offset) . '"api.version":1' . $separator . $rest);
    }
}
