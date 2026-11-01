<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\AccountSearchEmpty;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * Pin evidenced account-search absence and the boundaries of its generated policy.
 *
 * Present nonnull successes retain ordinary payload validation. Other failures
 * retain API-error precedence, even when their data would look empty.
 */
final class AccountSearchEmptyTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return [
            'function' => 'listaccts',
            'method' => 'GET',
            'outcome' => 'flag',
            'params' => [],
            'returns' => ['type' => 'object', 'fields' => ['acct' => [
                'type' => 'list',
                'items' => ['type' => 'object', 'fields' => ['user' => ['type' => 'string']]],
            ]]],
            'unwrap' => 'acct',
            'account_search_empty' => true,
        ];
    }

    private static function confirmed(): ResponseMetadata
    {
        return new ResponseMetadata(200, ['result' => 1, 'reason' => 'OK', 'command' => 'listaccts', 'version' => 1]);
    }

    public function testOmittedDataReturnsAnEmptyList(): void
    {
        $response = new ApiResponse(null, self::confirmed(), false);

        self::assertSame([], OperationRunner::run(self::spec(), [], static fn (): ApiResponse => $response));
    }

    public function testSuccessfulNullIsAnEmptyList(): void
    {
        $response = new ApiResponse(null, self::confirmed());
        self::assertSame([], OperationRunner::run(self::spec(), [], static fn (): ApiResponse => $response));
    }

    public function testPresentRowsAreValidatedAndUnwrappedAsUsual(): void
    {
        $response = new ApiResponse((object) ['acct' => [(object) ['user' => 'npr']]], self::confirmed());

        self::assertSame(
            [['user' => 'npr']],
            OperationRunner::run(self::spec(), [], static fn (): ApiResponse => $response),
        );
    }

    public function testAnExplicitEmptyListStaysAnEmptyList(): void
    {
        $response = new ApiResponse((object) ['acct' => []], self::confirmed());

        self::assertSame([], OperationRunner::run(self::spec(), [], static fn (): ApiResponse => $response));
    }

    public function testOutcomeExceptionTracesConcealTheCompleteResponse(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $response = new ApiResponse((object) ['acct' => 'private-payload'], new ResponseMetadata(200, [
            'result' => 0, 'reason' => 'private-diagnostic',
        ]));
        try {
            OperationRunner::run(self::spec(), [], static fn (): ApiResponse => $response);
            self::fail('Other failures must throw.');
        } catch (ApiErrorException $error) {
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                if (in_array($frame['class'] ?? '', [AccountSearchEmpty::class, OperationRunner::class], true)) {
                    ++$frames;
                    self::assertStringNotContainsString('private-payload', print_r($frame['args'] ?? [], true));
                    self::assertStringNotContainsString('private-diagnostic', print_r($frame['args'] ?? [], true));
                }
            }
            self::assertGreaterThanOrEqual(2, $frames);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function presentButMalformed(): iterable
    {
        yield 'empty list data' => [[]];
        yield 'object without the list' => [new stdClass()];
        yield 'null list' => [(object) ['acct' => null]];
        yield 'object instead of a list' => [(object) ['acct' => (object) ['user' => 'npr']]];
        yield 'scalar row' => [(object) ['acct' => ['npr']]];
        yield 'malformed row field' => [(object) ['acct' => [(object) ['user' => 1]]]];
    }

    #[DataProvider('presentButMalformed')]
    public function testPresentMalformedPayloadsStayConfirmedMismatches(mixed $data): void
    {
        $response = new ApiResponse($data, self::confirmed());

        try {
            OperationRunner::run(self::spec(), [], static fn (): ApiResponse => $response);
            self::fail('A present malformed payload must not read as an empty result.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame('Invalid listaccts payload.', $exception->getMessage());
        }
    }

    public function testWithoutThePolicyOmittedDataStaysAMismatch(): void
    {
        $spec = self::spec();
        unset($spec['account_search_empty']);
        $response = new ApiResponse(null, self::confirmed(), false);

        $this->expectException(PayloadMismatchException::class);

        OperationRunner::run($spec, [], static fn (): ApiResponse => $response);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function defectivePolicies(): iterable
    {
        $base = self::spec();
        foreach ([false, 1, 'true', null, []] as $i => $value) {
            yield 'non-true value ' . $i => [['account_search_empty' => $value] + $base];
        }
        yield 'wrong function' => [['function' => 'accountsummary'] + $base];
        yield 'wrong method' => [['method' => 'POST'] + $base];
        yield 'wrong body' => [['body' => 'multipart'] + $base];
        yield 'other empty policy' => [['empty_without_data' => true] + $base];
        yield 'UAPI' => [['module' => 'Synthetic'] + $base];
        yield 'unchecked outcome' => [['outcome' => 'unchecked'] + $base];
        yield 'optional payload' => [['optional' => true] + $base];
        yield 'void' => [['returns' => null, 'unwrap' => null] + $base];
        $withoutUnwrap = $base;
        unset($withoutUnwrap['unwrap']);
        yield 'without unwrap' => [$withoutUnwrap];
        yield 'other list' => [['unwrap' => 'other', 'returns' => ['type' => 'object', 'fields' => ['other' => [
            'type' => 'list', 'items' => ['type' => 'object', 'fields' => []],
        ]]]] + $base];
        yield 'scalar items' => [['returns' => ['type' => 'object', 'fields' => ['acct' => [
            'type' => 'list', 'items' => ['type' => 'string'],
        ]]]] + $base];
        yield 'nullable object' => [['returns' => ['type' => 'object', 'nullable' => true, 'fields' => ['acct' => [
            'type' => 'list', 'items' => ['type' => 'object', 'fields' => []],
        ]]]] + $base];
        yield 'nullable items' => [['returns' => ['type' => 'object', 'fields' => ['acct' => [
            'type' => 'list', 'items' => ['type' => 'object', 'nullable' => true, 'fields' => []],
        ]]]] + $base];
        $scalar = $base;
        $scalar['returns'] = ['type' => 'object', 'fields' => ['acct' => ['type' => 'string']]];
        yield 'unwrapped scalar' => [$scalar];
        $nullable = $base;
        $nullable['returns'] = ['type' => 'object', 'fields' => ['acct' => [
            'type' => 'list',
            'nullable' => true,
            'items' => ['type' => 'string'],
        ]]];
        yield 'nullable list' => [$nullable];
        $rows = ['function' => 'batch', 'module' => 'Synthetic', 'outcome' => 'unchecked', 'rows' => 'acct'] + $base;
        unset($rows['unwrap']);
        yield 'rows policy' => [$rows];
    }

    /** @param array<string, mixed> $spec */
    #[DataProvider('defectivePolicies')]
    public function testDefectivePoliciesRejectBeforeDispatch(array $spec): void
    {
        $sends = 0;
        $caught = null;
        try {
            OperationRunner::run($spec, [], static function () use (&$sends): ApiResponse {
                ++$sends;

                return new ApiResponse(null, self::confirmed(), false);
            });
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(LogicException::class, $caught);
        self::assertSame(0, $sends);
    }
}
