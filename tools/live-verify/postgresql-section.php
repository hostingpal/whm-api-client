<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

// Run the explicitly designated, committed-code recipe and atomically save structural evidence.
$command = static function (#[SensitiveParameter] array $arguments): int {
    $temporary = null;
    $recovery = null;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/postgresql-section.php --output=PATH\n"
                . "Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=npr "
                . "WHM_API_LIVE_DOMAIN=npr.rs and both test tokens.\n");

            return 0;
        }
        if (count($arguments) === 2 && $arguments[0] === '--output' && is_string($arguments[1])) {
            $arguments = ['--output=' . $arguments[1]];
        }
        $argument = count($arguments) === 1 ? $arguments[0] : null;
        if (!is_string($argument) || !str_starts_with($argument, '--output=') || strlen($argument) <= 9) {
            throw new LogicException('Invalid section arguments.');
        }
        $whm = getenv('OBLAK_TEST_WHM_KEY');
        $cpanel = getenv('OBLAK_TEST_NPR_KEY');
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'npr' || getenv('WHM_API_LIVE_DOMAIN') !== 'npr.rs'
            || $whm === false || $whm === ''
            || $cpanel === false || $cpanel === '' || !extension_loaded('curl')
        ) {
            throw new LogicException('Section authorization or runtime unavailable.');
        }
        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        $context = RepositoryContext::load($root);
        $output = substr($argument, 9);
        if (is_link($output) || file_exists($output)) {
            throw new LogicException('Section evidence needs a new destination.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Evidence directory unavailable.');
        }
        $temporary = @tempnam($directory, '.postgresql-section-');
        if ($temporary === false) {
            $temporary = null;
            throw new RuntimeException('Evidence file unavailable.');
        }
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
            throw new RuntimeException('Evidence file protection unavailable.');
        }
        $report = (new PostgresqlSectionFixture(
            new CurlClient(allowFormPost: true),
            ['whm' => $whm, 'cpanel' => $cpanel],
            bin2hex(random_bytes(3)),
            $context,
        ))->run();
        $json = json_encode(
            ['schema_version' => 1, 'postgresql_section' => $report],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException('Evidence persistence failed.');
        }
        // Preserve the complete protected report if atomic new-only publication cannot succeed.
        $recovery = $temporary;
        if (!@link($temporary, $output)) {
            throw new RuntimeException('Evidence publication failed.');
        }
        @unlink($temporary);
        $temporary = null;
        $recovery = null;
        $passed = $report['status'] === 'passed';
        fwrite(STDOUT, 'PostgreSQL section fixture: ' . ($passed ? 'passed' : 'failed') . "\n");

        return $passed ? 0 : 1;
    } catch (Throwable $error) {
        $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
        if ($recovery !== null) {
            fwrite(STDERR, 'Recovery evidence retained at '
                . json_encode($recovery, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . ".\n");
        }
        fwrite(STDERR, 'PostgreSQL section verification failed (' . $kind . ").\n");

        return 1;
    } finally {
        if ($temporary !== null && $recovery === null) {
            @unlink($temporary);
        }
    }
};

exit($command(array_slice($argv, 1)));
