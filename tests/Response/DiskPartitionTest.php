<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\DiskPartition;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DiskPartitionTest extends TestCase
{
    public function testMapsEveryReviewedFieldAndIgnoresUnknownExtensions(): void
    {
        $partition = DiskPartition::fromPayload((object) [
            'available' => 377856,
            'device' => '/dev/vda1',
            'disk' => 'vda1',
            'filesystem' => '/',
            'inodes_available' => 20575847,
            'inodes_ipercentage' => 2,
            'inodes_total' => 20970944,
            'inodes_used' => 395097,
            'mount' => '/boot',
            'percentage' => 20,
            'total' => 495844,
            'used' => 92388,
            'extension' => new stdClass(),
        ], new ResponseMetadata(200));

        self::assertSame(377856, $partition->available);
        self::assertSame('/dev/vda1', $partition->device);
        self::assertSame('vda1', $partition->disk);
        self::assertSame('/', $partition->filesystem);
        self::assertSame(20575847, $partition->inodesAvailable);
        self::assertSame(2, $partition->inodesIpercentage);
        self::assertSame(20970944, $partition->inodesTotal);
        self::assertSame(395097, $partition->inodesUsed);
        self::assertSame('/boot', $partition->mount);
        self::assertSame(20, $partition->percentage);
        self::assertSame(495844, $partition->total);
        self::assertSame(92388, $partition->used);
        self::assertSame([
            'available' => 377856,
            'device' => '/dev/vda1',
            'disk' => 'vda1',
            'filesystem' => '/',
            'inodes_available' => 20575847,
            'inodes_ipercentage' => 2,
            'inodes_total' => 20970944,
            'inodes_used' => 395097,
            'mount' => '/boot',
            'percentage' => 20,
            'total' => 495844,
            'used' => 92388,
        ], $partition->toArray());
    }

    public function testOmittedFieldsRemainNullAndAbsentFromTheExport(): void
    {
        $partition = DiskPartition::fromPayload(new stdClass(), new ResponseMetadata(200));

        self::assertNull($partition->available);
        self::assertNull($partition->device);
        self::assertNull($partition->disk);
        self::assertNull($partition->filesystem);
        self::assertNull($partition->inodesAvailable);
        self::assertNull($partition->inodesIpercentage);
        self::assertNull($partition->inodesTotal);
        self::assertNull($partition->inodesUsed);
        self::assertNull($partition->mount);
        self::assertNull($partition->percentage);
        self::assertNull($partition->total);
        self::assertNull($partition->used);
        self::assertSame([], $partition->toArray());
    }

    public function testRetainsDocumentedMinimaAndBlankStringsWithoutCoercion(): void
    {
        $partition = DiskPartition::fromPayload((object) [
            'available' => 1,
            'device' => '',
            'disk' => '',
            'filesystem' => '',
            'inodes_available' => 1,
            'inodes_ipercentage' => 0,
            'inodes_total' => 1,
            'inodes_used' => 1,
            'mount' => '',
            'percentage' => 1,
            'total' => 1,
            'used' => 1,
        ], new ResponseMetadata(200));

        self::assertSame([
            'available' => 1,
            'device' => '',
            'disk' => '',
            'filesystem' => '',
            'inodes_available' => 1,
            'inodes_ipercentage' => 0,
            'inodes_total' => 1,
            'inodes_used' => 1,
            'mount' => '',
            'percentage' => 1,
            'total' => 1,
            'used' => 1,
        ], $partition->toArray());
    }

    public function testDoesNotInventPercentageUpperBoundsOrCrossFieldConstraints(): void
    {
        $partition = DiskPartition::fromPayload((object) [
            'available' => 10,
            'inodes_available' => 20,
            'inodes_ipercentage' => 101,
            'inodes_total' => 1,
            'inodes_used' => 30,
            'percentage' => 102,
            'total' => 1,
            'used' => 40,
        ], new ResponseMetadata(200));

        self::assertSame([
            'available' => 10,
            'inodes_available' => 20,
            'inodes_ipercentage' => 101,
            'inodes_total' => 1,
            'inodes_used' => 30,
            'percentage' => 102,
            'total' => 1,
            'used' => 40,
        ], $partition->toArray());
    }

    public function testPreservesObservedZeroDiskPercentage(): void
    {
        // Reduced from authorized runtime evidence recorded in docs/api/source-audit.md.
        $partition = DiskPartition::fromPayload((object) ['percentage' => 0], new ResponseMetadata(200));

        self::assertSame(0, $partition->percentage);
        self::assertSame(['percentage' => 0], $partition->toArray());
    }

    public function testPreservesObservedExplicitNullInodeFieldsSeparatelyFromOmission(): void
    {
        // The live response supplied all four inode keys with explicit null values.
        $available = null;
        $payload = (object) [
            'inodes_available' => &$available,
            'inodes_ipercentage' => null,
            'inodes_total' => null,
            'inodes_used' => null,
        ];
        $metadata = new ResponseMetadata(200);
        $partition = DiskPartition::fromPayload($payload, $metadata);
        $omitted = DiskPartition::fromPayload(new stdClass(), $metadata);
        $available = 10;
        $payload->inodes_ipercentage = 20;

        self::assertNull($partition->inodesAvailable);
        self::assertNull($partition->inodesIpercentage);
        self::assertNull($partition->inodesTotal);
        self::assertNull($partition->inodesUsed);
        self::assertSame([
            'inodes_available' => null,
            'inodes_ipercentage' => null,
            'inodes_total' => null,
            'inodes_used' => null,
        ], $partition->toArray());
        self::assertSame([], $omitted->toArray());
    }

    public function testDetachesMutableInputReferencesAndExportedArrays(): void
    {
        $device = '/dev/vda1';
        $available = 377856;
        $percentage = 0;
        $payload = (object) [
            'available' => &$available,
            'device' => &$device,
            'inodes_ipercentage' => &$percentage,
        ];
        $partition = DiskPartition::fromPayload($payload, new ResponseMetadata(200));

        $device = '/dev/vdb1';
        $available = 1;
        $percentage = 50;
        $payload->mount = '/changed';
        $exported = $partition->toArray();
        $exported['device'] = '/dev/vdc1';

        self::assertSame('/dev/vda1', $partition->device);
        self::assertSame(377856, $partition->available);
        self::assertSame(0, $partition->inodesIpercentage);
        self::assertSame([
            'available' => 377856,
            'device' => '/dev/vda1',
            'inodes_ipercentage' => 0,
        ], $partition->toArray());
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedRecordsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            DiskPartition::fromPayload($payload, $metadata);
            self::fail('Malformed records must not produce typed disk partition information.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertStringNotContainsString('sensitive-response-value', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'null' => [null];
        yield 'scalar' => ['sensitive-response-value'];
        yield 'list' => [[]];
        yield 'associative array' => [['device' => '/dev/vda1']];
        yield 'non-JSON object' => [new ResponseMetadata(200)];
        foreach (['device', 'disk', 'filesystem', 'mount'] as $field) {
            foreach ([null, false, 1, 1.0, new stdClass(), []] as $index => $value) {
                yield $field . ' invalid string ' . $index => [(object) [$field => $value]];
            }
        }
        foreach (
            ['available', 'inodes_available', 'inodes_ipercentage', 'inodes_total',
                'inodes_used', 'percentage', 'total', 'used'] as $field
        ) {
            foreach ([false, 'sensitive-response-value', '1', 1.0, new stdClass(), [], -1] as $index => $value) {
                yield $field . ' invalid integer ' . $index => [(object) [$field => $value]];
            }
            if (!in_array($field, ['inodes_available', 'inodes_ipercentage', 'inodes_total', 'inodes_used'], true)) {
                yield $field . ' null' => [(object) [$field => null]];
            }
            if ($field !== 'inodes_ipercentage' && $field !== 'percentage') {
                yield $field . ' below minimum' => [(object) [$field => 0]];
            }
        }
    }
}
