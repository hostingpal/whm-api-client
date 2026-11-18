<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\CaptureClient;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\DnsZoneExercise;
use HostingPal\WhmApiClient\Tools\LiveVerify\DnsZoneGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
            $body(1, 0, [$reason . ".\nAnother failure."]),
            $body(1, 0, ['Other refusal quoting: ' . $reason . '.']),
            $body(1, 0, [$reason . ' line 40. More text.']),
            $body(1, 0, ['Undefined subroutine &Cpanel::AdminBin::Call::call_elsewhere']),
            $body(1, 0, $reason . '.'),
            'null',
            '{',
            ] as $bad
        ) {
            self::assertFalse(DnsZoneExercise::knownBridgeFailure($bad));
        }
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function swaps(): iterable
    {
        yield 'bridge refusal leaves both zones intact' => ['known_failure', 'known_failure', 30, true];
        yield 'both routes succeed and select different sources' => ['bridge_success', 'success', 30, true];
        yield 'per-zone detection changes both apices' => ['per_zone', 'known_failure', 30, false];
        yield 'bridge refusal changes only an alias TTL' => ['failure_changes_zone', 'unexpected', 25, false];
        yield 'spurious change for an unchanged marker' => ['reported_unchanged', 'known_failure', 30, false];
        yield 'second contradictory change for a changed marker' => ['conflicting_changed', 'known_failure', 30, false];
    }

    #[DataProvider('swaps')]
    public function testAdjudicatesReturnedChangesAgainstBothZoneFiles(
        string $behavior,
        string $bridge,
        int $count,
        bool $shouldPass,
    ): void {
        $saved = [];
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wldnsrecipe',
                'WHM_API_LIVE_DOMAIN' => DnsZoneExerciseServer::MAIN] as $key => $setting
        ) {
            $saved[$key] = getenv($key);
            putenv($key . '=' . $setting);
        }
        try {
            $account = DisposableAccount::fresh(
                'wldnsrecipe',
                DnsZoneExerciseServer::MAIN,
                'dns-zone-recipe',
                900,
                false,
            );
            $guard = new DnsZoneGuard(new DnsZoneExerciseServer($behavior), 'synthetic-root', $account);
            $capture = new CaptureClient($guard);
            $factory = new Psr17Factory();
            $transport = new HttpTransport($capture, $factory, $factory);
            $root = new Client([
                'host' => 'test.oblak.host', 'authType' => 'whm', 'username' => 'root', 'token' => 'synthetic-root',
            ], $transport);
            $direct = new Client([
                'host' => 'test.oblak.host', 'authType' => 'cpanel', 'username' => 'wldnsrecipe',
                'token' => 'synthetic-account',
            ], $transport);
            $guard->bindToken('synthetic-account');
            $guard->arm('limits');
            $root->whm()->accounts()->modifyAccount('wldnsrecipe', maxpark: 1);
            $guard->arm('park_alias');
            $direct->uapi()->park()->park(DnsZoneExerciseServer::ALIAS);
            $exercise = new DnsZoneExercise(
                new EvidenceRunner($guard, DomainRowsEvidenceTest::context(), 'wldnsrecipe'),
                $capture,
                $direct,
                $root,
                '11.138.0.24',
                $guard,
            );
            $error = null;
            try {
                $exercise->run();
            } catch (RuntimeException $exception) {
                $error = $exception;
            }
            $probes = $exercise->observations();
            self::assertCount($count, $probes);
            self::assertSame($bridge, $exercise->bridgeOutcome());
            self::assertSame(
                array_slice(DnsZoneGuard::EXERCISE_PHASES, 0, $count),
                array_column($probes, 'argument_variant'),
            );
            if ($shouldPass) {
                self::assertNull($error);
            } else {
                self::assertSame('DNS exercise expectation failed.', $error?->getMessage());
            }
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
