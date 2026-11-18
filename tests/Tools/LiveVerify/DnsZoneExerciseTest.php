<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DnsZoneExercise;
use PHPUnit\Framework\TestCase;

final class DnsZoneExerciseTest extends TestCase
{
    public function testBridgeFailureRecognizesTheUapiErrorInsideASuccessfulWhmEnvelope(): void
    {
        $reason = 'Undefined subroutine &Cpanel::AdminBin::Call::call called at /usr/local/cpanel/Cpanel/API/DNS.pm';
        $body = static fn (int $outer, int $inner, mixed $errors): string => json_encode([
            'metadata' => ['result' => $outer], 'data' => ['uapi' => ['status' => $inner, 'errors' => $errors]],
        ], JSON_THROW_ON_ERROR);

        self::assertTrue(DnsZoneExercise::knownBridgeFailure($body(1, 0, [$reason . ".\n"])));
        self::assertTrue(DnsZoneExercise::knownBridgeFailure($body(1, 0, [$reason . " line 40.\n"])));
        foreach (
            [
            $body(0, 0, [$reason . '.']),
            $body(1, 1, [$reason . '.']),
            $body(1, 0, [$reason . '.', 'different error']),
            $body(1, 0, [$reason . 'x.']),
            $body(1, 0, ['Undefined subroutine &Cpanel::AdminBin::Call::call_elsewhere']),
            $body(1, 0, $reason . '.'),
            'null',
            '{',
            ] as $bad
        ) {
            self::assertFalse(DnsZoneExercise::knownBridgeFailure($bad));
        }
    }
}
