<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use stdClass;

/**
 * Retain the server's one-, five-, and fifteen-minute load averages.
 *
 * Pinned systemloadavg fields are optional numbers. Properties use null for
 * omission; properties and toArray() preserve integers, floats, and the finite
 * decimal strings observed over WHM HTTPS. Strings retain precision and trailing
 * zeros; no CPU-count normalization or percentage conversion is performed.
 * See docs/api/source-audit.md for this runtime schema discrepancy.
 * Unknown extensions are ignored. Source: /systemloadavg in
 * docs/api/sources/2026-10-13/whm.openapi.json.gz; whm1:systemloadavg#GET.
 *
 * @phpstan-type ServerLoadAveragePayload array{
 *     one?: int|float|numeric-string,
 *     five?: int|float|numeric-string,
 *     fifteen?: int|float|numeric-string,
 * }
 * @see https://api.docs.cpanel.net/openapi/whm/operation/systemloadavg
 */
final readonly class ServerLoadAverage
{
    /** @var int|float|numeric-string|null The previous minute's raw load average, or null when omitted. */
    public int|float|string|null $one;

    /** @var int|float|numeric-string|null The previous five minutes' raw load average, or null when omitted. */
    public int|float|string|null $five;

    /** @var int|float|numeric-string|null The previous fifteen minutes' raw load average, or null when omitted. */
    public int|float|string|null $fifteen;

    /** @param ServerLoadAveragePayload $payload Validated, detached load values. */
    private function __construct(private array $payload)
    {
        $this->one = $payload['one'] ?? null;
        $this->five = $payload['five'] ?? null;
        $this->fifteen = $payload['fifteen'] ?? null;
    }

    /**
     * Validate and copy the decoded load-average object.
     *
     * Present fields accept finite JSON numbers or the observed decimal-string
     * representation (optional sign, digits, optional decimal fraction). Strings
     * with whitespace or exponents, explicit null, and nonfinite values are
     * rejected. The reviewed schema imposes no numeric range bounds.
     *
     * @throws InvalidResponseException If the object or a known field is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        if (!$payload instanceof stdClass) {
            throw new InvalidResponseException('Invalid WHM load-average payload.', $metadata);
        }

        $values = [];
        if (property_exists($payload, 'one')) {
            $values['one'] = self::number($payload->one, $metadata);
        }
        if (property_exists($payload, 'five')) {
            $values['five'] = self::number($payload->five, $metadata);
        }
        if (property_exists($payload, 'fifteen')) {
            $values['fifteen'] = self::number($payload->fifteen, $metadata);
        }

        return new self($values);
    }

    /**
     * Export reviewed fields, preserving omitted fields and numeric types.
     *
     * @return ServerLoadAveragePayload
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * Accept finite JSON numbers and observed decimal strings without coercion.
     *
     * @return int|float|numeric-string
     */
    private static function number(mixed $value, ResponseMetadata $metadata): int|float|string
    {
        if (
            is_string($value) && is_numeric($value)
            && preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/', $value) === 1
            && is_finite((float) $value)
        ) {
            return $value;
        }

        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new InvalidResponseException('Invalid WHM load-average value.', $metadata);
        }

        return $value;
    }
}
