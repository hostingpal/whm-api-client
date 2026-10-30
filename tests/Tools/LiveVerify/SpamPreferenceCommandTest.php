<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\TestCase;

/** Entry-point wiring only; publication and failure recovery are covered by SectionCommandTest. */
final class SpamPreferenceCommandTest extends TestCase
{
    public function testHelpIdentifiesTheBoundRecipeWithoutCredentials(): void
    {
        [$exit, $stdout, $stderr] = self::execute('--help');
        self::assertSame(0, $exit);
        self::assertStringContainsString('spam-preference-section.php --output=PATH', $stdout);
        self::assertSame('', $stderr);
    }

    public function testAbsentAuthorizationCannotProduceAnArtifact(): void
    {
        $output = sys_get_temp_dir() . '/spam-preferences-' . bin2hex(random_bytes(6)) . '.json';
        [$exit, $stdout, $stderr] = self::execute('--output=' . $output);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('configuration_failure', $stderr);
        self::assertFileDoesNotExist($output);
    }

    /** @return array{int,string,string} */
    private static function execute(string $argument): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/tools/live-verify/spam-preference-section.php', $argument],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['WHM_API_LIVE' => '0', 'WHM_API_LIVE_MUTATIONS' => '0'],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }
}
