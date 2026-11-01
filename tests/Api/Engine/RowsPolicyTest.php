<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class RowsPolicyTest extends TestCase
{
    /** @return iterable<string, array{array<mixed>}> */
    public static function defectivePolicies(): iterable
    {
        $base = FilemanSpec::UPLOAD_FILES;
        unset($base['multipart_uploads'], $base['body']);
        $base['params'] = [];
        $base['rows'] = 'uploads';
        foreach ([null, false, [], 1, '', 'unknown', 'failed'] as $i => $rows) {
            yield 'rows type or field ' . $i => [['rows' => $rows] + $base];
        }
        yield 'flag' => [['outcome' => 'flag'] + $base];
        $whm = $base;
        unset($whm['module']);
        yield 'WHM' => [$whm];
        yield 'optional' => [['optional' => true] + $base];
        yield 'unwrap' => [['unwrap' => 'uploads'] + $base];
        yield 'void' => [['returns' => null] + $base];
        $nullable = $base;
        $nullable['returns']['nullable'] = true;
        yield 'nullable root' => [$nullable];
        $nullable = $base;
        $nullable['returns']['fields']['uploads']['nullable'] = true;
        yield 'nullable list' => [$nullable];
        $nullable = $base;
        $nullable['returns']['fields']['uploads']['items']['nullable'] = true;
        yield 'nullable row' => [$nullable];
        $scalar = $base;
        $scalar['returns']['fields']['uploads']['items'] = ['type' => 'string'];
        yield 'scalar row' => [$scalar];
    }

    /** @param array<mixed> $spec */
    #[DataProvider('defectivePolicies')]
    public function testDefectiveRowsPoliciesRejectBeforeDispatch(array $spec): void
    {
        $sends = 0;
        $caught = null;
        try {
            OperationRunner::run($spec, [], static function () use (&$sends): ApiResponse {
                ++$sends;
                return new ApiResponse((object) [], new ResponseMetadata(200, null, ['status' => 1]));
            });
        } catch (Throwable $error) {
            $caught = $error;
        }
        self::assertInstanceOf(LogicException::class, $caught);
        self::assertSame(0, $sends);
    }

    public function testUncheckedWithoutRowsPolicyStillReturnsStatusZeroObject(): void
    {
        $spec = FilemanSpec::UPLOAD_FILES;
        unset($spec['rows']);
        self::assertSame(['failed' => 0, 'uploads' => []], OperationRunner::result(
            $spec,
            new ApiResponse(
                (object) ['uploads' => [], 'failed' => 0],
                new ResponseMetadata(200, null, ['status' => 0]),
            ),
        ));
    }
}
