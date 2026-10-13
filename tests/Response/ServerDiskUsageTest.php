<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\DiskPartition;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ServerDiskUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ServerDiskUsageTest extends TestCase
{
    public function testHydratesRecordsInOrderAndExposesCountableIterablePartitions(): void
    {
        $usage = ServerDiskUsage::fromPayload((object) [
            'partition' => [(object) ['device' => '/dev/vda1'], (object) ['device' => '/dev/vdb1']],
            'extension' => true,
        ], new ResponseMetadata(200));

        self::assertNotNull($usage->partitions);
        self::assertCount(2, $usage);
        self::assertInstanceOf(DiskPartition::class, $usage->partitions[0]);
        self::assertSame('/dev/vda1', $usage->partitions[0]->device);
        self::assertSame('/dev/vdb1', $usage->partitions[1]->device);
        self::assertSame($usage->partitions, iterator_to_array($usage));
        self::assertSame(['partition' => [
            ['device' => '/dev/vda1'],
            ['device' => '/dev/vdb1'],
        ]], $usage->toArray());
    }

    public function testOmittedAndEmptyPartitionListsRemainDistinct(): void
    {
        $metadata = new ResponseMetadata(200);
        $omitted = ServerDiskUsage::fromPayload(new stdClass(), $metadata);
        $empty = ServerDiskUsage::fromPayload((object) ['partition' => []], $metadata);
        $emptyRecord = ServerDiskUsage::fromPayload((object) ['partition' => [new stdClass()]], $metadata);

        self::assertNull($omitted->partitions);
        self::assertSame([], $omitted->toArray());
        self::assertCount(0, $omitted);
        self::assertSame([], iterator_to_array($omitted));
        self::assertSame([], $empty->partitions);
        self::assertSame(['partition' => []], $empty->toArray());
        self::assertCount(0, $empty);
        self::assertSame([], iterator_to_array($empty));
        self::assertCount(1, $emptyRecord);
        self::assertSame(['partition' => [[]]], $emptyRecord->toArray());
    }

    public function testInputObjectsListReferencesAndExportsCannotAlterTheSnapshot(): void
    {
        $device = '/dev/vda1';
        $record = (object) ['device' => &$device];
        $records = [&$record];
        $payload = (object) ['partition' => &$records];
        $usage = ServerDiskUsage::fromPayload($payload, new ResponseMetadata(200));

        $device = '/dev/vdb1';
        $record->mount = '/changed';
        $record = new stdClass();
        $records[] = (object) ['device' => '/dev/vdc1'];
        $payload->partition = [];
        $exported = $usage->toArray();
        $exported['partition'][0]['device'] = '/dev/vdd1';
        $iterated = iterator_to_array($usage);
        $iterated[] = DiskPartition::fromPayload(new stdClass(), new ResponseMetadata(200));

        self::assertCount(1, $usage);
        self::assertSame('/dev/vda1', $usage->partitions[0]->device ?? null);
        self::assertSame(['partition' => [['device' => '/dev/vda1']]], $usage->toArray());
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedContainersAndRecordsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            ServerDiskUsage::fromPayload($payload, $metadata);
            self::fail('Malformed disk usage must not produce a collection.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'null payload' => [null];
        yield 'scalar payload' => ['partition'];
        yield 'list payload' => [[]];
        yield 'associative array payload' => [['partition' => []]];
        yield 'non-JSON payload' => [new ResponseMetadata(200)];
        yield 'null partition' => [(object) ['partition' => null]];
        yield 'scalar partition' => [(object) ['partition' => '/dev/vda1']];
        yield 'object partition' => [(object) ['partition' => new stdClass()]];
        yield 'associative partition' => [(object) ['partition' => ['key' => new stdClass()]]];
        yield 'sparse partition' => [(object) ['partition' => [1 => new stdClass()]]];
        yield 'null record' => [(object) ['partition' => [null]]];
        yield 'scalar record' => [(object) ['partition' => ['/dev/vda1']]];
        yield 'list record' => [(object) ['partition' => [[]]]];
        yield 'associative array record' => [(object) ['partition' => [['device' => '/dev/vda1']]]];
        yield 'invalid second record' => [(object) ['partition' => [new stdClass(), (object) ['available' => '1']]]];
    }
}
