<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use stdClass;

/**
 * Retain one domain record from WHM get_domain_info as an immutable snapshot.
 *
 * Every reviewed field is optional. Convenience properties use null for omission;
 * toArray() retains original presence, including explicitly nullable IPv6. Domain
 * types remain open strings; integer flags, zero, and blank strings retain their
 * values. The pinned contract declares integer ports; the observed WHM response
 * on 2026-10-13 returned decimal strings. Canonical nonnegative decimal strings
 * within PHP's integer range are also accepted: public port properties normalize
 * them to integers while toArray() preserves the original wire types. Unknown
 * response extensions are ignored.
 *
 * Source: whm.openapi.json.gz, /paths/~1get_domain_info/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:get_domain_info#GET.
 *
 * @phpstan-type DomainInfoPayload array{
 *     docroot?: string,
 *     domain?: string,
 *     domain_type?: string,
 *     ipv4?: string,
 *     ipv4_ssl?: string,
 *     ipv6?: string|null,
 *     ipv6_is_dedicated?: 0|1,
 *     modsecurity_enabled?: 0|1,
 *     parent_domain?: string,
 *     php_version?: string,
 *     port?: int|numeric-string,
 *     port_ssl?: int|numeric-string,
 *     user?: string,
 *     user_owner?: string,
 * }
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_domain_info
 */
final readonly class DomainInfo
{
    /** The addon domain's document root, or null when omitted. */
    public ?string $docroot;

    /** The domain value returned by WHM, or null when omitted. */
    public ?string $domain;

    /** The upstream domain type string, without a closed enum, or null when omitted. */
    public ?string $domainType;

    /** The domain's IPv4 address, or null when omitted. */
    public ?string $ipv4;

    /** The domain's secure IPv4 address, or null when omitted. */
    public ?string $ipv4Ssl;

    /** The IPv6 address; null means absent or explicitly unavailable. */
    public ?string $ipv6;

    /** @var 0|1|null The documented IPv6 flag, or null when omitted. */
    public ?int $ipv6IsDedicated;

    /** @var 0|1|null The documented ModSecurity flag, or null when omitted. */
    public ?int $modsecurityEnabled;

    /** The parent domain, or null when omitted. */
    public ?string $parentDomain;

    /** The installed PHP version value, or null when omitted. */
    public ?string $phpVersion;

    /** The server port normalized to an integer, or null when omitted. */
    public ?int $port;

    /** The secure server port normalized to an integer, or null when omitted. */
    public ?int $portSsl;

    /** The cPanel account or reseller username, or null when omitted. */
    public ?string $user;

    /** The domain owner, or null when omitted. */
    public ?string $userOwner;

    /**
     * Initialize properties from the validated, detached record.
     *
     * @param DomainInfoPayload $payload
     */
    private function __construct(private array $payload)
    {
        $this->docroot = $payload['docroot'] ?? null;
        $this->domain = $payload['domain'] ?? null;
        $this->domainType = $payload['domain_type'] ?? null;
        $this->ipv4 = $payload['ipv4'] ?? null;
        $this->ipv4Ssl = $payload['ipv4_ssl'] ?? null;
        $this->ipv6 = $payload['ipv6'] ?? null;
        $this->ipv6IsDedicated = $payload['ipv6_is_dedicated'] ?? null;
        $this->modsecurityEnabled = $payload['modsecurity_enabled'] ?? null;
        $this->parentDomain = $payload['parent_domain'] ?? null;
        $this->phpVersion = $payload['php_version'] ?? null;
        $this->port = isset($payload['port']) ? (int) $payload['port'] : null;
        $this->portSsl = isset($payload['port_ssl']) ? (int) $payload['port_ssl'] : null;
        $this->user = $payload['user'] ?? null;
        $this->userOwner = $payload['user_owner'] ?? null;
    }

    /**
     * Validate and copy a decoded WHM domain record.
     *
     * JSON objects must remain stdClass instances. Only IPv6 accepts explicit
     * null; omitted fields are retained as omissions in toArray(). Flags require
     * integer 0 or 1. Ports accept the pinned integer type and canonical decimal
     * strings observed at runtime, without adding a network port range limit.
     * String ports must be nonnegative and fit PHP's integer range; they normalize
     * only in the public properties, retaining their wire representation in toArray().
     * Source objects and scalar references cannot mutate the resulting snapshot.
     *
     * @throws InvalidResponseException If the record or a known field has an invalid type.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        if (!$payload instanceof stdClass) {
            throw new InvalidResponseException('Invalid WHM domain record.', $metadata);
        }

        $values = [];
        if (property_exists($payload, 'docroot')) {
            $values['docroot'] = self::stringValue($payload->docroot, $metadata);
        }

        if (property_exists($payload, 'domain')) {
            $values['domain'] = self::stringValue($payload->domain, $metadata);
        }

        if (property_exists($payload, 'domain_type')) {
            $values['domain_type'] = self::stringValue($payload->domain_type, $metadata);
        }

        if (property_exists($payload, 'ipv4')) {
            $values['ipv4'] = self::stringValue($payload->ipv4, $metadata);
        }

        if (property_exists($payload, 'ipv4_ssl')) {
            $values['ipv4_ssl'] = self::stringValue($payload->ipv4_ssl, $metadata);
        }

        if (property_exists($payload, 'ipv6')) {
            $values['ipv6'] = self::nullableStringValue($payload->ipv6, $metadata);
        }

        if (property_exists($payload, 'ipv6_is_dedicated')) {
            $values['ipv6_is_dedicated'] = self::flagValue($payload->ipv6_is_dedicated, $metadata);
        }

        if (property_exists($payload, 'modsecurity_enabled')) {
            $values['modsecurity_enabled'] = self::flagValue($payload->modsecurity_enabled, $metadata);
        }

        if (property_exists($payload, 'parent_domain')) {
            $values['parent_domain'] = self::stringValue($payload->parent_domain, $metadata);
        }

        if (property_exists($payload, 'php_version')) {
            $values['php_version'] = self::stringValue($payload->php_version, $metadata);
        }

        if (property_exists($payload, 'port')) {
            $values['port'] = self::portValue($payload->port, $metadata);
        }

        if (property_exists($payload, 'port_ssl')) {
            $values['port_ssl'] = self::portValue($payload->port_ssl, $metadata);
        }

        if (property_exists($payload, 'user')) {
            $values['user'] = self::stringValue($payload->user, $metadata);
        }

        if (property_exists($payload, 'user_owner')) {
            $values['user_owner'] = self::stringValue($payload->user_owner, $metadata);
        }

        return new self($values);
    }

    /**
     * Export reviewed fields with their original presence and scalar values.
     *
     * Port values retain their original integer or canonical decimal string type.
     * Mutating the exported array cannot alter the record.
     *
     * @return DomainInfoPayload
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /** Validate a present non-null string without narrowing its contents. */
    private static function stringValue(mixed $value, ResponseMetadata $metadata): string
    {
        if (!is_string($value)) {
            throw new InvalidResponseException('Invalid WHM domain record.', $metadata);
        }

        return $value;
    }

    /** Preserve the IPv6 field's explicitly nullable string contract. */
    private static function nullableStringValue(mixed $value, ResponseMetadata $metadata): ?string
    {
        return $value === null ? null : self::stringValue($value, $metadata);
    }

    /**
     * Retain an integer flag without accepting booleans or numeric strings.
     *
     * @return 0|1
     */
    private static function flagValue(mixed $value, ResponseMetadata $metadata): int
    {
        if ($value !== 0 && $value !== 1) {
            throw new InvalidResponseException('Invalid WHM domain record.', $metadata);
        }

        return $value;
    }

    /**
     * Accept pinned integer ports and the bounded decimal-string runtime form.
     *
     * Round-tripping the string through an integer rejects overflow without
     * imposing a network port range. Validation never changes the stored value.
     *
     * @return int|numeric-string
     */
    private static function portValue(mixed $value, ResponseMetadata $metadata): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        if (
            !is_string($value) || !is_numeric($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1
            || (string) (int) $value !== $value
        ) {
            throw new InvalidResponseException('Invalid WHM domain record.', $metadata);
        }

        return $value;
    }
}
