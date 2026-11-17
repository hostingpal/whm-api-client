<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

// Committed-code disposable-account LangPHP mutation proof with exact opt-ins and a new-only structural artifact.
$command = static function (#[SensitiveParameter] array $arguments): int {
    $temporary = null;
    $reportReady = false;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/php-mutations.php --output=PATH\n"
                . "Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=wlphpset "
                . "WHM_API_LIVE_DOMAIN=wlphpset.example.com and OBLAK_TEST_WHM_KEY.\n"
                . "WHM_API_LIVE_FS_CHECK=1 adds the read-only filesystem proof over SSH.\n");

            return 0;
        }
        if (count($arguments) === 2 && $arguments[0] === '--output' && is_string($arguments[1])) {
            $arguments = ['--output=' . $arguments[1]];
        }
        $argument = count($arguments) === 1 ? $arguments[0] : null;
        if (!is_string($argument) || !str_starts_with($argument, '--output=') || strlen($argument) <= 9) {
            throw new LogicException('Invalid LangPHP mutation arguments.');
        }
        $whm = getenv('OBLAK_TEST_WHM_KEY');
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'wlphpset'
            || getenv('WHM_API_LIVE_DOMAIN') !== 'wlphpset.example.com'
            || $whm === false || $whm === '' || !extension_loaded('curl')
        ) {
            throw new LogicException('LangPHP mutation authorization or runtime unavailable.');
        }
        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        $context = RepositoryContext::load($root);
        $output = substr($argument, 9);
        if (is_link($output) || file_exists($output)) {
            throw new LogicException('LangPHP mutation evidence requires a new destination.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('LangPHP mutation evidence directory unavailable.');
        }
        $temporary = @tempnam($directory, '.php-mutations-');
        if ($temporary === false) {
            $temporary = null;
            throw new RuntimeException('LangPHP mutation evidence file unavailable.');
        }
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
            throw new RuntimeException('LangPHP mutation evidence protection unavailable.');
        }
        $scenario = (new LangPhpMutationFixture(
            new CurlClient(allowFormPost: true),
            $whm,
            $context,
            SshFilesystemAbsenceProof::fromEnvironment(),
        ))->run();
        $json = json_encode(
            ['schema_version' => 1, 'langphp_mutations' => $scenario],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException('LangPHP mutation evidence persistence failed.');
        }
        $reportReady = true;
        if (!@link($temporary, $output)) {
            throw new RuntimeException('LangPHP mutation evidence persistence failed.');
        }
        @unlink($temporary);
        $temporary = null;
        $passed = $scenario['fixture']['status'] === 'passed';
        $reviewed = $scenario['discrepancy_review']['status'] === 'passed';
        fwrite(STDOUT, 'LangPHP mutation fixture: ' . ($passed ? 'passed' : 'failed') . "\n");
        fwrite(STDOUT, 'LangPHP mutation discrepancy review: ' . ($reviewed ? 'passed' : 'failed') . "\n");

        return $passed && $reviewed ? 0 : 1;
    } catch (Throwable $error) {
        $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
        fwrite(STDERR, 'LangPHP mutation verification failed (' . $kind . ").\n");
        if ($reportReady && $temporary !== null) {
            fwrite(STDERR, 'LangPHP mutation report retained at: ' . $temporary . "\n");
        }

        return 1;
    } finally {
        if ($temporary !== null && !$reportReady) {
            @unlink($temporary);
        }
    }
};

exit($command(array_slice($argv, 1)));
