<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use LogicException;
use SensitiveParameter;
use stdClass;

/** Select only source-declared WHM envelope siblings, without retaining the envelope. @internal */
final readonly class WhmPayloadProjection
{
    /** @param non-empty-list<string> $fields */
    private function __construct(public array $fields)
    {
    }

    /** Validate generated field names before any request or response handling. */
    public static function from(mixed $fields): self
    {
        if (!is_array($fields) || $fields === [] || !array_is_list($fields)) {
            throw new LogicException('WHM response fields require a nonempty sequential list.');
        }
        $seen = [];
        foreach ($fields as $field) {
            if (
                !is_string($field) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $field) !== 1
                || in_array($field, ['metadata', 'data'], true) || isset($seen[$field])
            ) {
                throw new LogicException('Invalid or duplicate WHM response field name.');
            }
            $seen[$field] = true;
        }

        return new self($fields);
    }

    /** Copy present selected fields in declaration order, retaining explicit null. */
    public function select(#[SensitiveParameter] stdClass $document): stdClass
    {
        $selected = new stdClass();
        foreach ($this->fields as $field) {
            if (property_exists($document, $field)) {
                $selected->{$field} = $document->{$field};
            }
        }

        return $selected;
    }
}
