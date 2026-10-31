<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RejectEmptyTest extends TestCase
{
    /** @return iterable<array{mixed}> */
    public static function unsafeTargets(): iterable
    {
        foreach ([null, '', ' ', "\t\n", '0', ' 0 ', [], [''], ['ok', ' '], ['0'], [false], [0]] as $target) {
            yield [$target];
        }
    }

    #[DataProvider('unsafeTargets')]
    public function testRejectEmptyCannotBecomeAnOmittedTarget(mixed $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode(
            ['domains' => ['name' => 'domain', 'encoding' => 'repeat', 'reject_empty' => true]],
            ['domains' => $target],
        );
    }

    public function testUnflaggedEmptyScalarStillPassesThrough(): void
    {
        self::assertSame('domain=', ArgumentEncoder::encode(
            ['domains' => ['name' => 'domain', 'encoding' => 'scalar']],
            ['domains' => ''],
        )->encode());
    }

    /** @return iterable<array{mixed, string}> */
    public static function defectivePolicies(): iterable
    {
        foreach ([false, null, 1, 'true'] as $value) {
            yield [$value, 'repeat'];
        }
        yield [true, 'bool'];
        yield [true, 'numbered'];
    }

    #[DataProvider('defectivePolicies')]
    public function testPolicySpecMustBeTrueAndUseAStringEncoding(mixed $value, string $encoding): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'example', 'method' => 'POST', 'outcome' => 'flag',
            'params' => ['domains' => ['name' => 'domain', 'encoding' => $encoding, 'reject_empty' => $value]],
            'returns' => null]);
    }
}
