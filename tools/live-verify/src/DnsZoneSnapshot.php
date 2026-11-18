<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Dns;
use RuntimeException;
use SensitiveParameter;

/**
 * Resolve DNS targets from one typed parseZone() result. All decoded values stay in memory.
 *
 * @phpstan-import-type ParseZoneResult from Dns
 */
final readonly class DnsZoneSnapshot
{
    public int $serial;

    /**
     * Read the sole apex SOA serial. Keep the original rows to compare complete typed snapshots.
     *
     * @param ParseZoneResult $rows
     */
    public function __construct(private string $zone, #[SensitiveParameter] private array $rows)
    {
        $serial = null;
        foreach ($rows as $row) {
            if (($row['type'] ?? null) !== 'record' || ($row['record_type'] ?? null) !== 'SOA') {
                continue;
            }
            $number = self::decode($row['data_b64'][2] ?? null);
            if (
                $serial !== null || $this->owner($row['dname_b64'] ?? null) !== $zone . '.'
                || !ctype_digit($number) || strlen($number) > 10 || (int) $number > 4294967295
            ) {
                throw new RuntimeException('Zone read did not identify a unique apex SOA with a usable serial.');
            }
            $serial = (int) $number;
        }
        $this->serial = $serial ?? throw new RuntimeException('Zone read lacked the apex SOA.');
    }

    public function sameAs(self $other): bool
    {
        return $this->zone === $other->zone && $this->rows === $other->rows;
    }

    /** Check all record types before using a new owner name or confirming removal. */
    public function absent(string $owner): bool
    {
        foreach ($this->rows as $row) {
            if (($row['type'] ?? null) === 'record' && $this->owner($row['dname_b64'] ?? null) === $owner) {
                return false;
            }
        }

        return true;
    }

    /** Return the sole apex IPv4 address used by the omitted-source swap probe. */
    public function apexAddress(): string
    {
        $row = $this->oneA($this->zone . '.');
        $address = self::decode($row['data_b64'][0] ?? null);
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || str_starts_with($address, '127.')) {
            throw new RuntimeException('Swap requires a non-loopback apex IPv4 address.');
        }

        return $address;
    }

    /** Bind the record's identity, value and TTL to the zero-based line in this same read. */
    public function requiredA(string $owner, string $address, ?int $ttl = null): int
    {
        $row = $this->oneA($owner);
        $index = $row['line_index'] ?? null;
        if (
            $index === null || $index < 0 || ($ttl !== null && ($row['ttl'] ?? null) !== $ttl)
            || self::decode($row['data_b64'][0] ?? null) !== $address
        ) {
            throw new RuntimeException('The expected A record did not match its readback.');
        }
        $sameIndex = 0;
        foreach ($this->rows as $line) {
            if (($line['line_index'] ?? null) === $index) {
                $sameIndex++;
            }
        }
        if ($sameIndex !== 1) {
            throw new RuntimeException('The A record line index was ambiguous.');
        }

        return $index;
    }

    /** @return value-of<ParseZoneResult> */
    private function oneA(string $owner): array
    {
        $match = null;
        foreach ($this->rows as $row) {
            if (
                ($row['type'] ?? null) !== 'record' || ($row['record_type'] ?? null) !== 'A'
                || $this->owner($row['dname_b64'] ?? null) !== $owner
            ) {
                continue;
            }
            if ($match !== null || count($row['data_b64'] ?? []) !== 1) {
                throw new RuntimeException('Expected exactly one A record and one address for the owner.');
            }
            $match = $row;
        }

        return $match ?? throw new RuntimeException('Expected A record was missing.');
    }

    private function owner(#[SensitiveParameter] ?string $encoded): string
    {
        $name = strtolower(self::decode($encoded));
        if ($name === '@') {
            return $this->zone . '.';
        }

        return str_ends_with($name, '.') ? $name : $name . '.' . $this->zone . '.';
    }

    private static function decode(#[SensitiveParameter] ?string $encoded): string
    {
        $value = $encoded === null ? false : base64_decode($encoded, true);

        return $value === false ? throw new RuntimeException('Malformed DNS readback field.') : $value;
    }
}
