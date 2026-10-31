<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/** @internal A structured JSON value inside one ordinary parameter, separate from a whole JSON body. */
final class JsonFieldEncoder
{
    /** @return array{required: bool, schema: array<mixed>} */
    public static function check(mixed $descriptor): array
    {
        if (
            !is_array($descriptor) || count($descriptor) !== 2
            || !is_bool($descriptor['required'] ?? null) || !is_array($descriptor['schema'] ?? null)
        ) {
            throw new LogicException('Invalid JSON field descriptor.');
        }
        $schema = StructuredJsonSchema::from($descriptor['schema']);
        if (!$descriptor['required'] && self::acceptsNull($schema)) {
            throw new LogicException('Optional nullable JSON fields are unsupported.');
        }
        return $descriptor;
    }

    /**
     * A required null is present JSON null when allowed; optional nonnullable null is absence.
     *
     */
    public static function encode(
        mixed $descriptor,
        bool $present,
        #[SensitiveParameter] mixed $value,
    ): ?string {
        $checked = self::check($descriptor);
        if (!$present && $checked['required']) {
            throw new InvalidArgumentException('Required JSON field is missing.');
        }
        if (!$checked['required'] && $value === null) {
            return null;
        }
        return StructuredJsonEncoder::encode($value, $checked['schema']);
    }

    private static function acceptsNull(StructuredJsonSchema $schema): bool
    {
        if ($schema->nullable || $schema->type === 'null') {
            return true;
        }
        foreach ($schema->alternatives as $branch) {
            if (self::acceptsNull($branch)) {
                return true;
            }
        }
        return false;
    }
}
