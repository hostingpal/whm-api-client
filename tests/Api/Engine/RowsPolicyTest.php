<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
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

    /**
     * @return array{
     *     function: string, module: string, method: string, outcome: string, params: array{}, rows: string,
     *     returns: array{type: string, items: array{type: string, fields: array{reason: array{type: string}}}}
     * }
     */
    private static function rootRows(): array
    {
        return ['function' => 'batch', 'module' => 'Synthetic', 'method' => 'POST', 'outcome' => 'unchecked',
            'params' => [], 'rows' => '$', 'returns' => ['type' => 'list', 'items' => ['type' => 'object',
                'fields' => ['reason' => ['type' => 'string']]]]];
    }

    public function testRootRowsPolicyAndLiteralDollarObjectField(): void
    {
        $spec = self::rootRows();
        $response = new ApiResponse(
            [(object) ['reason' => 'failed']],
            new ResponseMetadata(200, null, ['status' => 0]),
        );
        self::assertSame(
            [['reason' => 'failed']],
            OperationRunner::run($spec, [], static fn (): ApiResponse => $response),
        );
        $spec['returns'] = ['type' => 'object', 'fields' => ['$' => $spec['returns']]];
        $response = new ApiResponse(
            (object) ['$' => [(object) ['reason' => 'literal field']]],
            new ResponseMetadata(200, null, ['status' => 0]),
        );
        self::assertSame(
            ['$' => [['reason' => 'literal field']]],
            OperationRunner::run($spec, [], static fn (): ApiResponse => $response),
        );
    }

    public function testEmptyLiteralDollarObjectFieldRaisesApiErrorWithSameMetadata(): void
    {
        $spec = self::rootRows();
        $spec['returns'] = ['type' => 'object', 'fields' => ['$' => $spec['returns']]];
        $metadata = new ResponseMetadata(200, null, ['status' => 0]);
        $response = new ApiResponse((object) ['$' => []], $metadata);
        $caught = null;
        try {
            OperationRunner::run($spec, [], static fn (): ApiResponse => $response);
        } catch (ApiErrorException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(ApiErrorException::class, $caught);
        self::assertSame(ApiErrorException::class, $caught::class);
        self::assertSame($metadata, $caught->metadata);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function defectiveRootPolicies(): iterable
    {
        $base = self::rootRows();
        foreach (
            ['optional' => ['optional' => false], 'unwrap' => ['unwrap' => null],
            'flag' => ['outcome' => 'flag'], 'wrong selector' => ['rows' => 'rows'],
            'void' => ['returns' => null], 'map' => ['returns' => ['type' => 'map', 'values' => ['type' => 'object']]],
            'scalar root' => ['returns' => ['type' => 'string']],
            'scalar item' => ['returns' => ['type' => 'list', 'items' => ['type' => 'string']]],
            'nullable root' => ['returns' => ['nullable' => true] + $base['returns']],
            'nullable item' => ['returns' => ['type' => 'list', 'items' => ['type' => 'object', 'nullable' => true]]],
            ] as $name => $changes
        ) {
            yield $name => [$changes + $base];
        }
        unset($base['module']);
        yield 'WHM' => [$base];
    }

    /** @param array<mixed> $spec */
    #[DataProvider('defectiveRootPolicies')]
    public function testDefectiveRootPolicyRejectsBeforeDispatch(array $spec): void
    {
        $this->testDefectiveRowsPoliciesRejectBeforeDispatch($spec);
    }

    public function testRootUncheckedWithoutOptInKeepsEmptyAndNullSemantics(): void
    {
        $spec = self::rootRows();
        unset($spec['rows']);
        self::assertSame([], OperationRunner::result(
            $spec,
            new ApiResponse([], new ResponseMetadata(200, null, ['status' => 0])),
        ));
        $spec['returns']['nullable'] = true;
        self::assertNull(OperationRunner::result(
            $spec,
            new ApiResponse(null, new ResponseMetadata(200, null, ['status' => 0])),
        ));
    }

    public function testFilemanRowBoundariesRemainScopedAndStrict(): void
    {
        $spec = FilemanSpec::UPLOAD_FILES;
        foreach (
            [
            [0, null, ApiErrorException::class],
            [0, (object) ['uploads' => [], 'failed' => 1], ApiErrorException::class],
            [0, (object) ['uploads' => [], 'failed' => 'bad'], PayloadMismatchException::class],
            [0, (object) ['uploads' => [(object) ['file' => 3]]], PayloadMismatchException::class],
            [1, null, PayloadMismatchException::class],
            ] as [$status, $data, $expected]
        ) {
            $metadata = new ResponseMetadata(200, null, ['status' => $status]);
            $caught = null;
            try {
                OperationRunner::result($spec, new ApiResponse($data, $metadata));
            } catch (Throwable $error) {
                $caught = $error;
            }
            self::assertInstanceOf($expected, $caught);
            if ($caught instanceof PayloadMismatchException) {
                self::assertFalse($caught->outcomeConfirmed);
            }
        }
        self::assertSame(['uploads' => []], OperationRunner::result(
            $spec,
            new ApiResponse((object) ['uploads' => []], new ResponseMetadata(200, null, ['status' => 1])),
        ));
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
