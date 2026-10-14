<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ArgumentEncoderTest extends TestCase
{
    private const PARAMS = [
        'domain' => ['name' => 'domain', 'encoding' => 'scalar'],
        'hideTemporary' => ['name' => 'hide_temporary_domains', 'encoding' => 'bool'],
        'domains' => ['name' => 'domain', 'encoding' => 'repeat'],
        'users' => ['name' => 'user', 'encoding' => 'numbered'],
        'ratio' => ['name' => 'ratio', 'encoding' => 'scalar'],
    ];

    public function testEncodesEachKindInSpecOrderUnderWireNames(): void
    {
        $encoded = ArgumentEncoder::encode(self::PARAMS, [
            'users' => ['alice', 'bob'],
            'hideTemporary' => false,
            'domain' => 'example.test',
            'ratio' => 1.5,
        ]);

        self::assertSame(['domain', 'hide_temporary_domains', 'user', 'ratio'], array_keys($encoded));
        self::assertSame('example.test', $encoded['domain']);
        self::assertSame(0, $encoded['hide_temporary_domains']);
        self::assertInstanceOf(NumberedList::class, $encoded['user']);
        self::assertSame(['alice', 'bob'], $encoded['user']->values);
        self::assertSame('1.5', $encoded['ratio']);
    }

    public function testRepeatedArgumentsBecomeRepeatedLists(): void
    {
        $encoded = ArgumentEncoder::encode(self::PARAMS, ['domains' => ['a.test', 'b.test']]);

        self::assertInstanceOf(RepeatedList::class, $encoded['domain']);
        self::assertSame(['a.test', 'b.test'], $encoded['domain']->values);
    }

    public function testExplicitNullEqualsOmission(): void
    {
        self::assertSame(
            ArgumentEncoder::encode(self::PARAMS, ['domain' => 'example.test']),
            ArgumentEncoder::encode(
                self::PARAMS,
                ['domain' => 'example.test', 'hideTemporary' => null, 'users' => null],
            ),
        );
    }

    public function testUnknownArgumentsAreGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        ArgumentEncoder::encode(self::PARAMS, ['unknown' => 'x']);
    }

    public function testNonBooleanFlagsAreGeneratorDefects(): void
    {
        $this->expectException(LogicException::class);

        ArgumentEncoder::encode(self::PARAMS, ['hideTemporary' => 1]);
    }
}
