<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

// Committed-code disposable-account upload proof with exact opt-ins and a new-only structural evidence artifact.
$command = static function (#[SensitiveParameter] array $arguments): int {
    $temporary = null;
    $reportReady = false;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/fileman-upload.php --output=PATH\n"
                . "Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=wlupload "
                . "WHM_API_LIVE_DOMAIN=wlupload.example.com and OBLAK_TEST_WHM_KEY.\n"
                . "WHM_API_LIVE_FS_CHECK=1 adds the read-only filesystem proof over SSH.\n");

            return 0;
        }
        if (count($arguments) === 2 && $arguments[0] === '--output' && is_string($arguments[1])) {
            $arguments = ['--output=' . $arguments[1]];
        }
        $argument = count($arguments) === 1 ? $arguments[0] : null;
        if (!is_string($argument) || !str_starts_with($argument, '--output=') || strlen($argument) <= 9) {
            throw new LogicException('Invalid Fileman upload arguments.');
        }
        $whm = getenv('OBLAK_TEST_WHM_KEY');
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'wlupload'
            || getenv('WHM_API_LIVE_DOMAIN') !== 'wlupload.example.com'
            || $whm === false || $whm === '' || !extension_loaded('curl')
        ) {
            throw new LogicException('Fileman upload authorization or runtime unavailable.');
        }
        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        $context = RepositoryContext::load($root);
        $output = substr($argument, 9);
        if (is_link($output) || file_exists($output)) {
            throw new LogicException('Fileman upload evidence requires a new destination.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Fileman upload evidence directory unavailable.');
        }
        $temporary = @tempnam($directory, '.fileman-upload-');
        if ($temporary === false) {
            $temporary = null;
            throw new RuntimeException('Fileman upload evidence file unavailable.');
        }
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
            throw new RuntimeException('Fileman upload evidence protection unavailable.');
        }
        $scenario = (new FilemanUploadFixture(
            new CurlClient(allowFormPost: true),
            new StreamingCurlClient(),
            $whm,
            $context,
            SshFilesystemAbsenceProof::fromEnvironment(),
        ))->run();
        $json = json_encode(
            ['schema_version' => 1, 'fileman_upload' => $scenario],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException('Fileman upload evidence persistence failed.');
        }
        $reportReady = true;
        if (!@link($temporary, $output)) {
            throw new RuntimeException('Fileman upload evidence persistence failed.');
        }
        @unlink($temporary);
        $temporary = null;
        $passed = $scenario['fixture']['status'] === 'passed';
        $reviewed = $scenario['discrepancy_review']['status'] === 'passed';
        fwrite(STDOUT, 'Fileman upload fixture: ' . ($passed ? 'passed' : 'failed') . "\n");
        fwrite(STDOUT, 'Fileman upload discrepancy review: ' . ($reviewed ? 'passed' : 'failed') . "\n");

        return $passed && $reviewed ? 0 : 1;
    } catch (Throwable $error) {
        $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
        fwrite(STDERR, 'Fileman upload verification failed (' . $kind . ").\n");
        if ($reportReady && $temporary !== null) {
            fwrite(STDERR, 'Fileman upload report retained at: ' . $temporary . "\n");
        }

        return 1;
    } finally {
        if ($temporary !== null && !$reportReady) {
            @unlink($temporary);
        }
    }
};

exit($command(array_slice($argv, 1)));
