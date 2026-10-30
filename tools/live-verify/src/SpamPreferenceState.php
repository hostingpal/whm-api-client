<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use SensitiveParameter;
use stdClass;

/**
 * Private original-response snapshot; values and dynamic names never enter persisted evidence.
 *
 * Only absence or one finite decimal string can be restored exactly using the single-value setter.
 * Other preference values remain opaque: equality preserves scalar types and list order while ignoring
 * object key order recursively. Original decoded floats (including overflow integers) fail closed
 * because their raw precision cannot be proven. No unrelated preference is ever rewritten.
 */
final readonly class SpamPreferenceState
{
    private ?string $original;

    private stdClass $preferences;

    public function __construct(#[SensitiveParameter] string $raw, string $route)
    {
        $this->preferences = self::payload($raw, $route);
        self::requireExactNumbers($this->preferences);
        $original = null;
        if (property_exists($this->preferences, 'required_score')) {
            $values = $this->preferences->required_score;
            if (
                !is_array($values) || !array_is_list($values) || count($values) !== 1
                || !is_string($values[0]) || strlen($values[0]) > 64
                || preg_match('/\A[+-]?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $values[0]) !== 1
                || !is_finite((float) $values[0])
            ) {
                throw new LogicException('Preference snapshot cannot be restored exactly.');
            }
            $original = $values[0];
        }
        $this->original = $original;
    }

    /** Null means original absence; it must omit value rather than substitute a default. */
    public function original(): ?string
    {
        return $this->original;
    }

    /** Choose a changed value, retaining the exact original separately. */
    public function target(): string
    {
        return $this->original !== null && (float) $this->original === 5.1 ? '6' : '5.1';
    }

    /** Compare the entire original or deliberately changed object from the same captured response. */
    public function matches(#[SensitiveParameter] string $raw, string $route, bool $changed = false): bool
    {
        $expected = clone $this->preferences;
        if ($changed) {
            $expected->required_score = [$this->target()];
        }

        return self::canonical($expected) === self::canonical(self::payload($raw, $route));
    }

    private static function payload(#[SensitiveParameter] string $raw, string $route): stdClass
    {
        $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!$document instanceof stdClass) {
            throw new LogicException('Preference response is not an object.');
        }
        $envelope = $route === 'direct-uapi'
            ? (property_exists($document, 'result') ? $document->result : $document)
            : (($document->data ?? null) instanceof stdClass ? ($document->data->uapi ?? null) : null);
        if (!$envelope instanceof stdClass || !($envelope->data ?? null) instanceof stdClass) {
            throw new LogicException('Preference payload is not an object.');
        }

        return $envelope->data;
    }

    /** Reject every floating-point leaf before mutation; PHP JSON decoding can lose source precision. */
    private static function requireExactNumbers(#[SensitiveParameter] mixed $value): void
    {
        if (is_float($value)) {
            throw new LogicException('Preference snapshot numeric precision is not established.');
        }
        $children = $value instanceof stdClass ? get_object_vars($value) : $value;
        if (is_array($children)) {
            foreach ($children as $child) {
                self::requireExactNumbers($child);
            }
        }
    }

    private static function canonical(#[SensitiveParameter] mixed $value): string
    {
        return json_encode(self::ordered($value), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Recursively preserve object/list and scalar distinctions; sort only object keys. */
    private static function ordered(#[SensitiveParameter] mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $result = new stdClass();
            foreach ($fields as $key => $field) {
                $result->{(string) $key} = self::ordered($field);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map(self::ordered(...), $value);
        }

        return $value;
    }
}
