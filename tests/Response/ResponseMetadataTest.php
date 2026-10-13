<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Response\ResponseMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ResponseMetadataTest extends TestCase
{
    public function testRetainsIndependentWhmAndUapiMetadataSnapshots(): void
    {
        $warning = 'original warning';
        $uapi = ['status' => 0, 'errors' => ['failed'], 'warnings' => [&$warning], 'metadata' => null];
        $meta = new ResponseMetadata(200, ['result' => 1, 'reason' => 'OK'], $uapi);

        $warning = 'later warning';
        $uapi['status'] = 1;

        self::assertSame(200, $meta->httpStatus);
        self::assertSame(['result' => 1, 'reason' => 'OK'], $meta->whm);
        self::assertSame(
            ['status' => 0, 'errors' => ['failed'], 'warnings' => ['original warning'], 'metadata' => null],
            $meta->uapi,
        );
    }

    public function testRepresentsUnavailableFamilyMetadataWithoutInventingSuccess(): void
    {
        $meta = new ResponseMetadata(502);

        self::assertNull($meta->whm);
        self::assertNull($meta->uapi);
    }

    public function testRejectsObjectsThatCouldMutateASnapshot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResponseMetadata(200, null, ['metadata' => new stdClass()]);
    }

    public function testRejectsRecursiveMetadata(): void
    {
        $recursive = [];
        $recursive['nested'] = &$recursive;
        $this->expectException(InvalidArgumentException::class);

        new ResponseMetadata(200, $recursive);
    }

    public function testRejectsInvalidHttpStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResponseMetadata(0);
    }
}
