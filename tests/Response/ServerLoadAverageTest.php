<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ServerLoadAverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ServerLoadAverageTest extends TestCase
{
    public function testMapsLoadWindowsWithoutCoercingNumbersOrAddingBounds(): void
    {
        $load = ServerLoadAverage::fromPayload((object) [
            'one' => 0,
            'five' => 0.18,
            'fifteen' => 125.5,
            'extension' => 'ignored',
        ], new ResponseMetadata(200));

        self::assertSame(0, $load->one);
        self::assertSame(0.18, $load->five);
        self::assertSame(125.5, $load->fifteen);
        self::assertSame(['one' => 0, 'five' => 0.18, 'fifteen' => 125.5], $load->toArray());
        self::assertSame(['one' => -1], ServerLoadAverage::fromPayload(
            (object) ['one' => -1],
            new ResponseMetadata(200),
        )->toArray());
    }

    public function testOmittedWindowsRemainAbsentInsteadOfBecomingZeros(): void
    {
        $load = ServerLoadAverage::fromPayload(new stdClass(), new ResponseMetadata(200));

        self::assertNull($load->one);
        self::assertNull($load->five);
        self::assertNull($load->fifteen);
        self::assertSame([], $load->toArray());
    }

    public function testPreservesObservedDecimalStringsWithoutLosingPrecisionOrTrailingZeros(): void
    {
        $load = ServerLoadAverage::fromPayload((object) [
            'one' => '1.20',
            'five' => '0.00',
            'fifteen' => '1.230000000000000001',
        ], new ResponseMetadata(200));

        self::assertSame('1.20', $load->one);
        self::assertSame('0.00', $load->five);
        self::assertSame('1.230000000000000001', $load->fifteen);
        self::assertSame([
            'one' => '1.20',
            'five' => '0.00',
            'fifteen' => '1.230000000000000001',
        ], $load->toArray());
        self::assertSame(['one' => '-0.25', 'five' => '+1', 'fifteen' => '00.25'], ServerLoadAverage::fromPayload(
            (object) ['one' => '-0.25', 'five' => '+1', 'fifteen' => '00.25'],
            new ResponseMetadata(200),
        )->toArray());
    }

    public function testDetachesInputReferencesAndExportedArrays(): void
    {
        $number = 0.25;
        $payload = (object) ['one' => &$number, 'five' => 0];
        $load = ServerLoadAverage::fromPayload($payload, new ResponseMetadata(200));
        $number = 99.0;
        $payload->five = 10;
        $export = $load->toArray();
        $export['one'] = 2;

        self::assertSame(0.25, $load->one);
        self::assertSame(0, $load->five);
        self::assertSame(['one' => 0.25, 'five' => 0], $load->toArray());
        self::assertSame(2, $export['one']);
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidWindowsWithTheMetadataSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            ServerLoadAverage::fromPayload($payload, $metadata);
            self::fail('Expected an invalid load-average payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'null payload' => [null];
        yield 'list payload' => [[]];
        yield 'string payload' => ['invalid'];
        foreach (['one', 'five', 'fifteen'] as $field) {
            foreach (
                [null, 'invalid', true, [], new stdClass(), INF, -INF, NAN,
                '', ' 1.20', "1.20\n", '1e2', 'NaN', 'INF', '.', '1.2x', str_repeat('9', 400)] as $index => $value
            ) {
                yield $field . '-' . $index => [(object) [$field => $value]];
            }
        }
    }
}
