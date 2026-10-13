<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;

/**
 * Retain one drive partition from WHM getdiskusage as an immutable snapshot.
 *
 * All reviewed fields are optional. Convenience properties use null for omission;
 * toArray() retains original presence. Disk space values are kilobytes, and the
 * pinned schema requires positive integers except inodes_ipercentage, which may
 * be zero. Authorized runtime evidence additionally permits zero percentage and
 * explicit null in all four inode fields; see docs/api/source-audit.md for these
 * source discrepancies. No percentage maximum or relationship between counters
 * is imposed. Unknown response extensions are ignored.
 *
 * Source: whm.openapi.json.gz, /paths/~1getdiskusage/get, pinned under
 * docs/api/sources/2026-10-13; API-map identity whm1:getdiskusage#GET.
 *
 * @phpstan-type DiskPartitionPayload array{
 *     available?: positive-int,
 *     device?: string,
 *     disk?: string,
 *     filesystem?: string,
 *     inodes_available?: positive-int|null,
 *     inodes_ipercentage?: int<0, max>|null,
 *     inodes_total?: positive-int|null,
 *     inodes_used?: positive-int|null,
 *     mount?: string,
 *     percentage?: int<0, max>,
 *     total?: positive-int,
 *     used?: positive-int,
 * }
 * @see https://api.docs.cpanel.net/openapi/whm/operation/getdiskusage
 */
final readonly class DiskPartition
{
    /** @var positive-int|null Unused disk space in kilobytes, or null when omitted. */
    public ?int $available;

    /** The partition's device name, or null when omitted. */
    public ?string $device;

    /** The partition's label, or null when omitted. */
    public ?string $disk;

    /** The partition's absolute directory path, or null when omitted. */
    public ?string $filesystem;

    /** @var positive-int|null Unused inodes, or null when omitted or explicitly null. */
    public ?int $inodesAvailable;

    /** @var int<0, max>|null The inode percentage value, or null when omitted or explicitly null. */
    public ?int $inodesIpercentage;

    /** @var positive-int|null Permitted inodes, or null when omitted or explicitly null. */
    public ?int $inodesTotal;

    /** @var positive-int|null Inodes used, or null when omitted or explicitly null. */
    public ?int $inodesUsed;

    /** The partition's mount point, or null when omitted. */
    public ?string $mount;

    /** @var int<0, max>|null Disk space percentage used, including observed zero, or null when omitted. */
    public ?int $percentage;

    /** @var positive-int|null Total allocated disk space in kilobytes, or null when omitted. */
    public ?int $total;

    /** @var positive-int|null Disk space used in kilobytes, or null when omitted. */
    public ?int $used;

    /**
     * Initialize properties from the validated, detached partition record.
     *
     * @param DiskPartitionPayload $payload
     */
    private function __construct(private array $payload)
    {
        $this->available = $payload['available'] ?? null;
        $this->device = $payload['device'] ?? null;
        $this->disk = $payload['disk'] ?? null;
        $this->filesystem = $payload['filesystem'] ?? null;
        $this->inodesAvailable = $payload['inodes_available'] ?? null;
        $this->inodesIpercentage = $payload['inodes_ipercentage'] ?? null;
        $this->inodesTotal = $payload['inodes_total'] ?? null;
        $this->inodesUsed = $payload['inodes_used'] ?? null;
        $this->mount = $payload['mount'] ?? null;
        $this->percentage = $payload['percentage'] ?? null;
        $this->total = $payload['total'] ?? null;
        $this->used = $payload['used'] ?? null;
    }

    /**
     * Validate and copy a decoded WHM disk partition record.
     *
     * JSON objects must remain stdClass instances. Only the four inode fields
     * accept observed explicit null; omitted fields stay absent from toArray().
     * Integers retain the pinned minima except the observed zero disk percentage,
     * without coercion; strings retain their contents, including empty values.
     * Invalid records carry the supplied response snapshot in the exception.
     * Source objects and scalar references cannot mutate the resulting record.
     *
     * @throws InvalidResponseException If the record or a known field violates the reviewed payload contract.
     */
    public static function fromPayload(mixed $payload, ResponseMetadata $metadata): self
    {
        $read = new PayloadReader($metadata, 'WHM disk partition record');
        $payload = $read->object($payload);
        $values = [];
        if (property_exists($payload, 'available')) {
            $values['available'] = $read->positiveInt($payload->available);
        }

        if (property_exists($payload, 'device')) {
            $values['device'] = $read->string($payload->device);
        }

        if (property_exists($payload, 'disk')) {
            $values['disk'] = $read->string($payload->disk);
        }

        if (property_exists($payload, 'filesystem')) {
            $values['filesystem'] = $read->string($payload->filesystem);
        }

        if (property_exists($payload, 'inodes_available')) {
            $values['inodes_available'] = $read->nullable($payload->inodes_available, $read->positiveInt(...));
        }

        if (property_exists($payload, 'inodes_ipercentage')) {
            $values['inodes_ipercentage'] = $read->nullable(
                $payload->inodes_ipercentage,
                $read->nonNegativeInt(...),
            );
        }

        if (property_exists($payload, 'inodes_total')) {
            $values['inodes_total'] = $read->nullable($payload->inodes_total, $read->positiveInt(...));
        }

        if (property_exists($payload, 'inodes_used')) {
            $values['inodes_used'] = $read->nullable($payload->inodes_used, $read->positiveInt(...));
        }

        if (property_exists($payload, 'mount')) {
            $values['mount'] = $read->string($payload->mount);
        }

        if (property_exists($payload, 'percentage')) {
            $values['percentage'] = $read->nonNegativeInt($payload->percentage);
        }

        if (property_exists($payload, 'total')) {
            $values['total'] = $read->positiveInt($payload->total);
        }

        if (property_exists($payload, 'used')) {
            $values['used'] = $read->positiveInt($payload->used);
        }

        return new self($values);
    }

    /**
     * Export reviewed fields with their original presence and scalar values.
     *
     * Mutating the exported array cannot alter the record.
     *
     * @return DiskPartitionPayload
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
