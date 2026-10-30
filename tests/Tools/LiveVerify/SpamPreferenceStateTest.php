<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\SpamPreferenceState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpamPreferenceStateTest extends TestCase
{
    public function testObjectOrderIsIrrelevantAtEveryDepthButScalarTypesAndListsRemainExact(): void
    {
        $state = new SpamPreferenceState(
            '{"status":1,"data":{"required_score":["5.0"],"custom":{"b":[1,2],"a":5}}}',
            'direct-uapi',
        );
        self::assertTrue($state->matches(
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":'
                . '{"custom":{"a":5,"b":[1,2]},"required_score":["5.0"]}}}}',
            'bridged-uapi',
        ));
        foreach (
            ['{"a":5.0,"b":[1,2]}', '{"a":"5.0","b":[1,2]}',
                '{"a":5,"b":[2,1]}', '{"a":5,"b":{"0":1,"1":2}}'] as $custom
        ) {
            self::assertFalse($state->matches(
                '{"status":1,"data":{"required_score":["5.0"],"custom":' . $custom . '}}',
                'direct-uapi',
            ));
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function originals(): iterable
    {
        yield 'zero' => ['0', '5.1'];
        yield 'zero fractional' => ['0.0', '5.1'];
        yield 'fractional' => ['5.0', '5.1'];
        yield 'same value' => ['5.1', '6'];
        yield 'same padded fraction' => ['5.10', '6'];
        yield 'same signed value' => ['+5.1', '6'];
        yield 'different integer' => ['6', '5.1'];
    }

    #[DataProvider('originals')]
    public function testExactOriginalRestoresAndTargetChangesValue(string $original, string $target): void
    {
        $state = new SpamPreferenceState(
            '{"status":1,"data":{"required_score":[' . json_encode($original, JSON_THROW_ON_ERROR) . ']}}',
            'direct-uapi',
        );
        self::assertSame($original, $state->original());
        self::assertSame($target, $state->target());
    }
}
