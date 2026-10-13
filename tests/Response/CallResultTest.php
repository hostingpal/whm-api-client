<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\CallResult;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use PHPUnit\Framework\TestCase;

final class CallResultTest extends TestCase
{
    public function testConvertsObjectsToArraysWithoutRetainingSourceReferences(): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);
        $decoded = json_decode('{"a":{"7":"seven","list":[{"x":null},[1,2]],"f":1.5},"s":"str"}', false);
        $response = new ApiResponse($decoded, $metadata);

        $result = CallResult::fromApiResponse($response);

        self::assertSame(
            ['a' => [7 => 'seven', 'list' => [['x' => null], [1, 2]], 'f' => 1.5], 's' => 'str'],
            $result->data,
        );
        self::assertTrue($result->hasData);
        self::assertSame($metadata, $result->metadata);
    }

    public function testPreservesScalarsNullAndOmittedData(): void
    {
        $metadata = new ResponseMetadata(200, null, ['status' => 1]);

        self::assertSame('text', CallResult::fromApiResponse(new ApiResponse('text', $metadata))->data);
        self::assertSame([], CallResult::fromApiResponse(new ApiResponse([], $metadata))->data);
        $omitted = CallResult::fromApiResponse(new ApiResponse(null, $metadata, false));
        self::assertNull($omitted->data);
        self::assertFalse($omitted->hasData);
        $explicitNull = CallResult::fromApiResponse(new ApiResponse(null, $metadata));
        self::assertNull($explicitNull->data);
        self::assertTrue($explicitNull->hasData);
    }
}
