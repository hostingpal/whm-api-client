<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DnsZoneSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DnsZoneSnapshotTest extends TestCase
{
    public function testResolvesRecordIdentityAndSerialFromSameZoneRead(): void
    {
        $zone = 'example.test';
        $soa = [
            'type' => 'record', 'dname_b64' => base64_encode('@'), 'record_type' => 'SOA',
            'data_b64' => array_map(base64_encode(...), ['ns.', 'hostmaster.', '2026111801']),
            'line_index' => 4,
        ];
        $a = [
            'type' => 'record', 'dname_b64' => base64_encode('exercise'), 'record_type' => 'A',
            'data_b64' => [base64_encode('192.0.2.51')], 'ttl' => 300, 'line_index' => 7,
        ];
        $read = new DnsZoneSnapshot($zone, [$soa, $a]);
        self::assertSame(2026111801, $read->serial);
        self::assertSame(7, $read->requiredA('exercise.example.test.', '192.0.2.51', 300));
        self::assertTrue($read->absent('other.example.test.'));

        $this->expectException(RuntimeException::class);
        (new DnsZoneSnapshot($zone, [$soa, $a, [...$a, 'line_index' => 9]]))
            ->requiredA('exercise.example.test.', '192.0.2.51', 300);
    }

    public function testForeignSoaCannotSupplyASerial(): void
    {
        $this->expectException(RuntimeException::class);
        new DnsZoneSnapshot('example.test', [[
            'type' => 'record', 'dname_b64' => base64_encode('elsewhere.test.'),
            'record_type' => 'SOA', 'data_b64' => array_map(base64_encode(...), ['ns.', 'contact.', '123']),
            'line_index' => 1,
        ]]);
    }
}
