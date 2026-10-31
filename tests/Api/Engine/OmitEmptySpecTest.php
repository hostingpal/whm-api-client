<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OmitEmptySpecTest extends TestCase
{
    public function testOnlyExplicitEmptyRepeatInputIsOmitted(): void
    {
        $params = ['tags' => ['name' => 'tag', 'encoding' => 'repeat', 'omit_empty' => true]];
        SpecChecker::check(['function' => 'example', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
        self::assertSame('', ArgumentEncoder::encode($params, ['tags' => []])->encode());
        self::assertSame('', ArgumentEncoder::encode($params, ['tags' => null])->encode());
        self::assertSame('tag=single', ArgumentEncoder::encode($params, ['tags' => 'single'])->encode());
        self::assertSame('tag=a&tag=b', ArgumentEncoder::encode($params, ['tags' => ['a', 'b']])->encode());

        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode(['tags' => ['name' => 'tag', 'encoding' => 'repeat']], ['tags' => []]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidSettings(): iterable
    {
        foreach ([false, null, 1, 'true', []] as $value) {
            yield 'invalid value ' . get_debug_type($value) => [$value, 'repeat'];
        }
        foreach (['scalar', 'bool', 'numbered', 'suffix-numbered', 'suffix-numbered-scalar'] as $encoding) {
            yield $encoding => [true, $encoding];
        }
    }

    #[DataProvider('invalidSettings')]
    public function testMalformedSettingIsRejectedEvenForOmittedArguments(mixed $value, string $encoding): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'example', 'method' => 'POST', 'outcome' => 'flag',
            'params' => ['tags' => ['name' => str_starts_with($encoding, 'suffix-') ? 'tag*' : 'tag',
                'encoding' => $encoding, 'omit_empty' => $value]],
            'returns' => null]);
    }
}
