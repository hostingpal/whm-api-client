<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use LogicException;
use PHPUnit\Framework\TestCase;

final class StringLiteralTest extends TestCase
{
    public function testStringLiteralAcceptsOnlyTheExactValue(): void
    {
        $spec = ['type' => 'string', 'literal' => 'unlimited'];
        $reader = new PayloadReader(new ResponseMetadata(200), 'literal result');
        self::assertSame('unlimited', ShapeValidator::value('unlimited', $spec, $reader));
        foreach (['Unlimited', 'unlimited ', '', '0', 0, null, false, []] as $value) {
            try {
                ShapeValidator::value($value, $spec, $reader);
                self::fail('A nonliteral value was accepted.');
            } catch (InvalidResponseException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('', ShapeValidator::value('', ['type' => 'string', 'literal' => ''], $reader));
    }

    public function testMalformedLiteralConstraintsAreRejectedBeforeDispatch(): void
    {
        foreach (
            [
            ['type' => 'string', 'literal' => 1], ['type' => 'string', 'literal' => null],
            ['type' => 'int', 'literal' => 'unlimited']] as $type
        ) {
            try {
                SpecChecker::check(['function' => 'example', 'method' => 'GET', 'outcome' => 'flag',
                    'params' => [], 'returns' => $type]);
                self::fail('A malformed literal constraint was accepted.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
