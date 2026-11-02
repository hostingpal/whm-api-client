<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

// Committed-code disposable-account Webmail session proof with exact opt-ins and a new-only structural artifact.
$command = static function (#[SensitiveParameter] array $arguments): int {
    $temporary = null;
    $reportReady = false;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/webmail-sessions.php --output=PATH\n"
                . "Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_FS_CHECK=1 "
                . "WHM_API_LIVE_ACCOUNT=wlsession WHM_API_LIVE_DOMAIN=wlsession.example.com and OBLAK_TEST_WHM_KEY.\n"
                . "The filesystem proof is mandatory: account removal leaves session files that only it can see.\n"
                . "WHM_API_LIVE_SESSION_CLEANUP=1 also lets it delete them as root; without it, the run leaves\n"
                . "them for the owner and fails.\n");

            return 0;
        }
        if (count($arguments) === 2 && $arguments[0] === '--output' && is_string($arguments[1])) {
            $arguments = ['--output=' . $arguments[1]];
        }
        $argument = count($arguments) === 1 ? $arguments[0] : null;
        if (!is_string($argument) || !str_starts_with($argument, '--output=') || strlen($argument) <= 9) {
            throw new LogicException('Invalid Webmail session arguments.');
        }
        $whm = getenv('OBLAK_TEST_WHM_KEY');
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_FS_CHECK') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'wlsession'
            || getenv('WHM_API_LIVE_DOMAIN') !== 'wlsession.example.com'
            || $whm === false || $whm === '' || !extension_loaded('curl')
        ) {
            throw new LogicException('Webmail session authorization or runtime unavailable.');
        }
        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        $context = RepositoryContext::load($root);
        $output = substr($argument, 9);
        if (is_link($output) || file_exists($output)) {
            throw new LogicException('Webmail session evidence requires a new destination.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Webmail session evidence directory unavailable.');
        }
        $temporary = @tempnam($directory, '.webmail-sessions-');
        if ($temporary === false) {
            $temporary = null;
            throw new RuntimeException('Webmail session evidence file unavailable.');
        }
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
            throw new RuntimeException('Webmail session evidence protection unavailable.');
        }
        $proof = SshFilesystemAbsenceProof::fromEnvironment() ?? throw new LogicException('Filesystem proof is off.');
        $scenario = (new WebmailSessionFixture(
            new CurlClient(allowFormPost: true),
            $whm,
            $context,
            $proof,
            new SshSessionFileCleanup(),
        ))->run();
        $json = json_encode(
            ['schema_version' => 1, 'webmail_sessions' => $scenario],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException('Webmail session evidence persistence failed.');
        }
        $reportReady = true;
        if (!@link($temporary, $output)) {
            throw new RuntimeException('Webmail session evidence persistence failed.');
        }
        @unlink($temporary);
        $temporary = null;
        $passed = $scenario['fixture']['status'] === 'passed';
        $reviewed = $scenario['discrepancy_review']['status'] === 'passed';
        $files = $scenario['session_files']['status'];
        fwrite(STDOUT, 'Webmail session fixture: ' . ($passed ? 'passed' : 'failed') . "\n");
        fwrite(STDOUT, 'Webmail session discrepancy review: ' . ($reviewed ? 'passed' : 'failed') . "\n");
        fwrite(STDOUT, 'Webmail session files: ' . $files . "\n");

        return $passed && $reviewed && in_array($files, ['not_required', 'removed'], true) ? 0 : 1;
    } catch (Throwable $error) {
        $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
        fwrite(STDERR, 'Webmail session verification failed (' . $kind . ").\n");
        if ($reportReady && $temporary !== null) {
            fwrite(STDERR, 'Webmail session report retained at: ' . $temporary . "\n");
        }

        return 1;
    } finally {
        if ($temporary !== null && !$reportReady) {
            @unlink($temporary);
        }
    }
};

exit($command(array_slice($argv, 1)));
