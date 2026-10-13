<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use stdClass;

/**
 * Retain the reviewed domain listing for one cPanel account.
 *
 * Direct and WHM-routed UAPI DomainInfo/list_domains calls share this payload.
 * Every upstream field is optional. Convenience properties use null for omitted
 * fields; toArray() also distinguishes an omitted main domain from explicit null.
 * Empty strings, lists, and integer temporary flags retain their original values.
 * Unknown response extensions are outside this DTO's reviewed contract.
 *
 * Upstream userdata permission problems can produce blank values without an API
 * error. A successful response therefore does not establish a complete listing.
 * Source: cpanel.openapi.json.gz, /paths/~1DomainInfo~1list_domains/get, pinned
 * under docs/api/sources/2026-10-13; API-map identity uapi:DomainInfo/list_domains#GET.
 *
 * @phpstan-type DomainPayload array{
 *     main_domain?: string|null,
 *     addon_domains?: list<string>,
 *     parked_domains?: list<string>,
 *     sub_domains?: list<string>,
 *     is_temporary?: array<string, 0|1>
 * }
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/list_domains
 */
final readonly class AccountDomains
{
    /** The account's main domain; null also represents an omitted field. */
    public ?string $mainDomain;

    /** @var list<string>|null Addon domains, or null when the field was omitted. */
    public ?array $addonDomains;

    /** @var list<string>|null Parked domains, or null when the field was omitted. */
    public ?array $parkedDomains;

    /** @var list<string>|null Subdomains, or null when the field was omitted. */
    public ?array $subDomains;

    /** @var array<string, 0|1>|null Domain temporary flags, or null when omitted. */
    public ?array $isTemporary;

    /**
     * Initialize convenience properties from a validated, detached payload.
     *
     * The stored shape retains field presence independently of these properties.
     *
     * @param DomainPayload $payload
     */
    private function __construct(private array $payload)
    {
        $this->mainDomain = $payload['main_domain'] ?? null;
        $this->addonDomains = $payload['addon_domains'] ?? null;
        $this->parkedDomains = $payload['parked_domains'] ?? null;
        $this->subDomains = $payload['sub_domains'] ?? null;
        $this->isTemporary = $payload['is_temporary'] ?? null;
    }

    /**
     * Validate and copy the account-scoped UAPI domain payload.
     *
     * Pass decoded JSON with objects retained as stdClass. Known present fields
     * must match their documented types; omission, main-domain null, empty lists,
     * and empty temporary-domain objects are preserved. Unknown fields are ignored.
     * Any invalid known value retains the response metadata on the exception.
     *
     * @throws InvalidResponseException If the payload or a known field has an invalid type.
     * @see https://api.docs.cpanel.net/openapi/cpanel/operation/list_domains
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        if (!$payload instanceof stdClass) {
            throw new InvalidResponseException('Invalid domain listing payload.', $metadata);
        }

        $values = [];
        if (property_exists($payload, 'main_domain')) {
            $mainDomain = $payload->main_domain;
            if ($mainDomain !== null && !is_string($mainDomain)) {
                throw new InvalidResponseException('Invalid domain listing payload.', $metadata);
            }

            $values['main_domain'] = $mainDomain;
        }

        foreach (['addon_domains', 'parked_domains', 'sub_domains'] as $field) {
            if (property_exists($payload, $field)) {
                $values[$field] = self::domainList($payload->{$field}, $metadata);
            }
        }

        if (property_exists($payload, 'is_temporary')) {
            $values['is_temporary'] = self::temporaryFlags($payload->is_temporary, $metadata);
        }

        return new self($values);
    }

    /**
     * Export the reviewed fields with their original presence and scalar values.
     *
     * The temporary-domain object is represented as an array map, including an
     * empty array for an empty object. Changes to the result cannot alter this DTO.
     *
     * @return DomainPayload
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * Validate a JSON list and detach any references to its string values.
     *
     * @return list<string>
     * @throws InvalidResponseException If a list or one of its items has an invalid type.
     */
    private static function domainList(mixed $value, ResponseMetadata $metadata): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidResponseException('Invalid domain listing payload.', $metadata);
        }

        $copy = [];
        foreach ($value as $domain) {
            if (!is_string($domain)) {
                throw new InvalidResponseException('Invalid domain listing payload.', $metadata);
            }

            $copy[] = $domain;
        }

        return $copy;
    }

    /**
     * Validate an object of integer temporary flags and copy it into an array map.
     *
     * @return array<string, 0|1>
     * @throws InvalidResponseException If the object or a flag has an invalid type.
     */
    private static function temporaryFlags(mixed $value, ResponseMetadata $metadata): array
    {
        if (!$value instanceof stdClass) {
            throw new InvalidResponseException('Invalid domain listing payload.', $metadata);
        }

        $copy = [];
        foreach (get_object_vars($value) as $domain => $flag) {
            if (!is_string($domain) || ($flag !== 0 && $flag !== 1)) {
                throw new InvalidResponseException('Invalid domain listing payload.', $metadata);
            }

            $copy[$domain] = $flag;
        }

        return $copy;
    }
}
