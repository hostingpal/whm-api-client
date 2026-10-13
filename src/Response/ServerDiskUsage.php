<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use ArrayIterator;
use Countable;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use IteratorAggregate;

/**
 * Retain the WHM server's drive partition information as an immutable collection.
 *
 * The optional upstream partition field is exposed as partitions. An omitted list
 * produces null in that property and an omitted key in toArray(); an empty list
 * remains present. Both cases count and iterate as empty. Unknown extensions are
 * ignored, and records retain the reviewed fields and their original presence.
 *
 * Source: whm.openapi.json.gz, /paths/~1getdiskusage/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:getdiskusage#GET.
 *
 * @phpstan-import-type DiskPartitionPayload from DiskPartition
 * @phpstan-type ServerDiskUsagePayload array{partition?: list<DiskPartitionPayload>}
 * @implements IteratorAggregate<int, DiskPartition>
 * @see https://api.docs.cpanel.net/openapi/whm/operation/getdiskusage
 */
final readonly class ServerDiskUsage implements Countable, IteratorAggregate
{
    /**
     * Store detached immutable partition records, retaining list omission as null.
     *
     * @param list<DiskPartition>|null $partitions
     */
    private function __construct(public ?array $partitions)
    {
    }

    /**
     * Validate the WHM data object and copy each reviewed disk partition record.
     *
     * JSON objects must remain stdClass instances and the partition field, when
     * present, must be a JSON list of objects. Omitted record fields are permitted
     * by the pinned contract. Invalid fields retain this response's metadata in
     * the exception; source objects and array references are never retained.
     *
     * @throws InvalidResponseException If the container or a record is malformed.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM server disk usage');
        $payload = $read->object($payload);
        if (!property_exists($payload, 'partition')) {
            return new self(null);
        }

        return new self($read->listOf(
            $payload->partition,
            static fn (mixed $partition): DiskPartition => DiskPartition::fromPayload($partition, $metadata),
        ));
    }

    /**
     * Export records under the upstream partition key without adding omissions.
     *
     * Mutating the exported array cannot alter this collection or its records.
     *
     * @return ServerDiskUsagePayload
     */
    public function toArray(): array
    {
        return $this->partitions === null ? [] : ['partition' => array_map(
            static fn (DiskPartition $partition): array => $partition->toArray(),
            $this->partitions,
        )];
    }

    /** Return the number of supplied partition records, or zero for an omitted list. */
    public function count(): int
    {
        return count($this->partitions ?? []);
    }

    /**
     * Iterate immutable records in upstream order, treating omission as empty.
     *
     * @return ArrayIterator<int, DiskPartition>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->partitions ?? []);
    }
}
