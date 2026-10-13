<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use ArrayIterator;
use Countable;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use IteratorAggregate;

/**
 * Retain the reviewed WHM server domain inventory as an immutable collection.
 *
 * WHM get_domain_info returns manually created domains and excludes service
 * subdomains and automatically created domains. The optional domains field remains
 * distinct from an empty list: a missing field produces null in the property and
 * an omitted key in toArray(), while both cases count and iterate as empty.
 * Unknown response extensions are ignored.
 *
 * Source: whm.openapi.json.gz, /paths/~1get_domain_info/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:get_domain_info#GET.
 *
 * @phpstan-import-type DomainInfoPayload from DomainInfo
 * @phpstan-type ServerDomainsPayload array{domains?: list<DomainInfoPayload>}
 * @implements IteratorAggregate<int, DomainInfo>
 * @see https://api.docs.cpanel.net/openapi/whm/operation/get_domain_info
 */
final readonly class ServerDomains implements Countable, IteratorAggregate
{
    /**
     * Store detached immutable domain records, retaining field omission as null.
     *
     * @param list<DomainInfo>|null $domains
     */
    private function __construct(public ?array $domains)
    {
    }

    /**
     * Validate the WHM data object and copy each reviewed domain record.
     *
     * JSON objects must remain stdClass instances and the domains field, when
     * present, must be a JSON list of objects. Missing record fields are permitted
     * by the pinned contract. Invalid fields retain this response's metadata in
     * the exception; source objects and array references are never retained.
     *
     * @throws InvalidResponseException If the container or a record is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM server domain listing');
        $payload = $read->object($payload);
        if (!property_exists($payload, 'domains')) {
            return new self(null);
        }

        return new self($read->listOf(
            $payload->domains,
            static fn (mixed $domain): DomainInfo => DomainInfo::fromPayload($domain, $metadata),
        ));
    }

    /**
     * Export the reviewed container and records without adding omitted fields.
     *
     * Mutating the exported array cannot alter this collection or its records.
     *
     * @return ServerDomainsPayload
     */
    public function toArray(): array
    {
        return $this->domains === null ? [] : ['domains' => array_map(
            static fn (DomainInfo $domain): array => $domain->toArray(),
            $this->domains,
        )];
    }

    /** Return the number of supplied domain records, or zero for an omitted list. */
    public function count(): int
    {
        return count($this->domains ?? []);
    }

    /**
     * Iterate immutable records in upstream order, treating omission as empty.
     *
     * @return ArrayIterator<int, DomainInfo>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->domains ?? []);
    }
}
