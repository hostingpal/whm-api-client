<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\FeatureCatalog;
use HostingPal\WhmApiClient\Response\FeatureMetadata;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class FeatureMetadataTest extends TestCase
{
    /** @param 0|1|'0'|'1' $wire */
    #[DataProvider('pluginFlags')]
    public function testNormalizesPluginFlagWhileExportingTheWireValue(int|string $wire, int $normalized): void
    {
        $feature = FeatureMetadata::fromPayload(
            (object) ['id' => 'ssl', 'name' => 'SSL', 'is_plugin' => $wire, 'extension' => true],
            new ResponseMetadata(200),
        );

        self::assertSame('ssl', $feature->id);
        self::assertSame('SSL', $feature->name);
        self::assertSame($normalized, $feature->isPlugin);
        self::assertSame(['id' => 'ssl', 'name' => 'SSL', 'is_plugin' => $wire], $feature->toArray());
    }

    /** @return iterable<string, array{0|1|'0'|'1', 0|1}> */
    public static function pluginFlags(): iterable
    {
        yield 'integer zero' => [0, 0];
        yield 'integer one' => [1, 1];
        yield 'observed string zero' => ['0', 0];
        yield 'observed string one' => ['1', 1];
    }

    public function testOmittedFieldsAreNullAndAbsentFromTheExport(): void
    {
        $feature = FeatureMetadata::fromPayload(new stdClass(), new ResponseMetadata(200));

        self::assertNull($feature->id);
        self::assertNull($feature->name);
        self::assertNull($feature->isPlugin);
        self::assertSame([], $feature->toArray());
    }

    #[DataProvider('invalidRecords')]
    public function testRejectsInvalidRecordsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            FeatureMetadata::fromPayload($payload, $metadata);
            self::fail('Expected an invalid record.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRecords(): iterable
    {
        yield 'list' => [[]];
        yield 'integer id' => [(object) ['id' => 1]];
        yield 'boolean plugin flag' => [(object) ['is_plugin' => true]];
        yield 'out-of-range plugin flag' => [(object) ['is_plugin' => '2']];
    }

    public function testCatalogKeepsOmittedAndEmptyListsDistinct(): void
    {
        $metadata = new ResponseMetadata(200);
        $omitted = FeatureCatalog::fromPayload(new stdClass(), $metadata);
        $empty = FeatureCatalog::fromPayload((object) ['features' => []], $metadata);
        $catalog = FeatureCatalog::fromPayload(
            (object) ['features' => [(object) ['id' => 'ssl', 'is_plugin' => '1'], new stdClass()]],
            $metadata,
        );

        self::assertNull($omitted->features);
        self::assertSame([], $omitted->toArray());
        self::assertCount(0, $omitted);
        self::assertSame([], $empty->features);
        self::assertSame(['features' => []], $empty->toArray());
        self::assertCount(2, $catalog);
        self::assertSame($catalog->features, iterator_to_array($catalog));
        self::assertSame(1, $catalog->features[0]->isPlugin ?? null);
        self::assertSame(['features' => [['id' => 'ssl', 'is_plugin' => '1'], []]], $catalog->toArray());
    }

    public function testCatalogRejectsMalformedListsWithTheResponseSnapshot(): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            FeatureCatalog::fromPayload((object) ['features' => ['ssl']], $metadata);
            self::fail('Expected an invalid catalog.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }
}
