<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegralFixedPointSpecTest extends TestCase
{
    public function testIntegerSpecsCanExplicitlyDeclareTheNewLosslessVariant(): void
    {
        SpecChecker::check(['function' => 'example', 'method' => 'GET', 'outcome' => 'flag', 'params' => [],
            'returns' => ['type' => 'int', 'accept' => ['numeric-string', 'integral-fixed-point-string']]]);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<array{string}> */
    public static function unsupportedTypes(): iterable
    {
        foreach (['string', 'number', 'bool', 'flag', 'mixed', 'list', 'map', 'object', 'union'] as $type) {
            yield [$type];
        }
    }

    #[DataProvider('unsupportedTypes')]
    public function testEveryOtherTypeRejectsTheVariantBeforeDispatch(string $type): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'example', 'method' => 'GET', 'outcome' => 'flag', 'params' => [],
            'returns' => ['type' => $type, 'accept' => ['integral-fixed-point-string']]]);
    }
}
