<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use stdClass;

/**
 * Validate decoded payload values for one response without coercing them.
 *
 * Operation groups and DTOs compose these primitives with their reviewed field
 * lists. Every failure raises InvalidResponseException carrying this response's
 * metadata snapshot and a message naming only the payload subject, never values.
 * Values are returned unchanged; lists are copied so array references in the
 * decoded source cannot alter the result. Compatibility primitives such as
 * stringFlag() widen a pinned type only where an endpoint opts in per field
 * from reviewed runtime evidence; one-off rules (numeric-string ports) stay local.
 *
 * @internal
 */
final readonly class PayloadReader
{
    /**
     * Bind validation failures to one response snapshot.
     *
     * @param string $subject Safe description used as "Invalid {subject}."
     */
    public function __construct(private ResponseMetadata $metadata, private string $subject)
    {
    }

    /** Raise the subject's invalid-payload error with the response snapshot. */
    public function fail(): never
    {
        throw new InvalidResponseException('Invalid ' . $this->subject . '.', $this->metadata);
    }

    /** Require a decoded JSON object. */
    public function object(mixed $value): stdClass
    {
        return $value instanceof stdClass ? $value : $this->fail();
    }

    /** Require an object member to be present, returning its unvalidated value. */
    public function field(mixed $object, string $name): mixed
    {
        $object = $this->object($object);

        return property_exists($object, $name) ? $object->{$name} : $this->fail();
    }

    /** Require a string without restricting its contents. */
    public function string(mixed $value): string
    {
        return is_string($value) ? $value : $this->fail();
    }

    /** Require a string or explicit null. */
    public function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->string($value);
    }

    /**
     * Require an integer 0 or 1, rejecting booleans and numeric strings.
     *
     * @return 0|1
     */
    public function flag(mixed $value): int
    {
        return $value === 0 || $value === 1 ? $value : $this->fail();
    }

    /**
     * Accept an integer flag or its exact string form, returning the wire value.
     *
     * Use only for fields whose string form has reviewed runtime evidence.
     *
     * @return 0|1|'0'|'1'
     */
    public function stringFlag(mixed $value): int|string
    {
        return $value === '0' || $value === '1' ? $value : $this->flag($value);
    }

    /**
     * Accept an integer flag or a boolean, returning the wire value.
     *
     * Use only for fields whose boolean form has reviewed runtime evidence.
     *
     * @return 0|1|bool
     */
    public function boolFlag(mixed $value): int|bool
    {
        return is_bool($value) ? $value : $this->flag($value);
    }

    /**
     * Require an integer of at least one, without an upper bound.
     *
     * @return positive-int
     */
    public function positiveInt(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : $this->fail();
    }

    /**
     * Require a nonnegative integer.
     *
     * @return int<0, max>
     */
    public function nonNegativeInt(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : $this->fail();
    }

    /**
     * Allow an explicit null, otherwise apply the given validator.
     *
     * @template T
     * @param callable(mixed): T $read
     * @return T|null
     */
    public function nullable(mixed $value, callable $read): mixed
    {
        return $value === null ? null : $read($value);
    }

    /**
     * Require a JSON list of strings, keeping order, duplicates, and empty values.
     *
     * @return list<string>
     */
    public function strings(mixed $value): array
    {
        return $this->listOf($value, $this->string(...));
    }

    /**
     * Require a JSON list and validate each item in order.
     *
     * @template T
     * @param callable(mixed): T $item
     * @return list<T>
     */
    public function listOf(mixed $value, callable $item): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail();
        }

        $items = [];
        foreach ($value as $entry) {
            $items[] = $item($entry);
        }

        return $items;
    }

    /**
     * Copy a decoded JSON value, converting objects to associative arrays.
     *
     * Lists stay lists, scalars and null are unchanged, and the result holds no
     * reference to the decoded source objects. Numeric object keys become integer
     * array keys. No schema is applied; use this for opaque or generic payloads.
     */
    public function json(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = [];
            foreach (get_object_vars($value) as $key => $item) {
                $copy[$key] = $this->json($item);
            }

            return $copy;
        }

        if (is_array($value)) {
            return array_map($this->json(...), $value);
        }

        return $value;
    }
}
