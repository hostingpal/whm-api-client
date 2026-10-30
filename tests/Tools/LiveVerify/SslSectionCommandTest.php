<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\TestCase;

final class SslSectionCommandTest extends TestCase
{
    public function testHelpAndMissingAuthorizationCannotReachNetworkOrPublishEvidence(): void
    {
        $root = dirname(__DIR__, 3);
        $output = sys_get_temp_dir() . '/ssl-refusal-' . bin2hex(random_bytes(8)) . '.json';
        foreach ([['--help'], ['--output=' . $output]] as $arguments) {
            $process = proc_open(
                [PHP_BINARY, $root . '/tools/live-verify/ssl-section.php', ...$arguments],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                ['WHM_API_LIVE' => '0', 'WHM_API_LIVE_MUTATIONS' => '0'],
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            self::assertSame($arguments === ['--help'] ? 0 : 1, $status);
            if ($arguments === ['--help']) {
                self::assertIsString($stdout);
                self::assertStringContainsString('WHM_API_LIVE_MUTATIONS=1', $stdout);
            } else {
                self::assertSame('', $stdout);
                self::assertSame("SSL section verification failed (configuration_failure).\n", $stderr);
            }
            self::assertFileDoesNotExist($output);
        }
    }
}
