<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Publish one committed, fixed GET catalog as protected structural evidence. */
final class ReadOnlySectionCommand
{
    /**
     * Entrypoints bind their reviewed recipe and catalog; command arguments select only a new output path.
     * Omitting $outcome keeps the strict assessment and its console output; see ReadOnlySectionRunner::passes().
     * With $outcome, a failed run also names its deviating probes on stdout. The published report is unchanged.
     *
     * @param list<string> $arguments
     * @param Closure(): list<Probe> $catalog
     * @param (Closure(Probe, array<mixed>): bool)|null $outcome
     */
    public static function run(
        #[SensitiveParameter] array $arguments,
        string $root,
        string $recipe,
        Closure $catalog,
        ?Closure $outcome = null,
    ): int {
        $temporary = null;
        $recovery = null;
        try {
            if ($arguments === ['--help']) {
                fwrite(STDOUT, "Usage: php tools/live-verify/<section>.php --output=PATH\n"
                    . 'Requires WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs '
                    . "and both test tokens.\n");

                return 0;
            }
            if (count($arguments) === 2 && $arguments[0] === '--output') {
                $arguments = ['--output=' . $arguments[1]];
            }
            $argument = count($arguments) === 1 ? $arguments[0] : null;
            if (!is_string($argument) || !str_starts_with($argument, '--output=') || strlen($argument) <= 9) {
                throw new LogicException('Invalid section arguments.');
            }
            $optIn = getenv('WHM_API_LIVE');
            $whm = getenv('OBLAK_TEST_WHM_KEY');
            $cpanel = getenv('OBLAK_TEST_NPR_KEY');
            if (
                $optIn !== '1' || getenv('WHM_API_LIVE_ACCOUNT') !== 'npr'
                || getenv('WHM_API_LIVE_DOMAIN') !== 'npr.rs'
                || $whm === false || $whm === '' || $cpanel === false || $cpanel === ''
                || !extension_loaded('curl')
            ) {
                throw new LogicException('Section authorization or runtime unavailable.');
            }
            $context = RepositoryContext::load($root);
            $output = substr($argument, 9);
            if (is_link($output) || file_exists($output)) {
                throw new LogicException('Section evidence needs a new destination.');
            }
            $directory = dirname($output);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Evidence directory unavailable.');
            }
            $temporary = @tempnam($directory, '.read-only-section-');
            if ($temporary === false) {
                $temporary = null;
                throw new RuntimeException('Evidence file unavailable.');
            }
            if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
                throw new RuntimeException('Evidence file protection unavailable.');
            }
            $probes = $catalog();
            $report = (new ReadOnlySectionRunner(new CurlClient(), $context))->run(
                $probes,
                $recipe,
                $optIn,
                ['whm' => $whm, 'cpanel' => $cpanel],
                $outcome,
            );
            $json = json_encode(
                ['schema_version' => 1, 'read_only_section' => $report],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
            if (@file_put_contents($temporary, $json) !== strlen($json)) {
                throw new RuntimeException('Evidence persistence failed.');
            }
            // Only a complete protected report is retained for recovery after new-only publication fails.
            $recovery = $temporary;
            if (!@link($temporary, $output)) {
                throw new RuntimeException('Evidence publication failed.');
            }
            @unlink($temporary);
            $temporary = null;
            $recovery = null;
            $passed = $report['status'] === 'passed';
            fwrite(STDOUT, 'Read-only section verification: ' . ($passed ? 'passed' : 'failed') . ".\n");
            if (!$passed && $outcome !== null) {
                foreach (ReadOnlySectionRunner::deviations($probes, $report['probes'], $outcome) as $line) {
                    fwrite(STDOUT, $line . "\n");
                }
            }

            return $passed ? 0 : 1;
        } catch (Throwable $error) {
            $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
            if ($recovery !== null) {
                fwrite(STDERR, 'Recovery evidence retained at '
                    . json_encode($recovery, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . ".\n");
            }
            fwrite(STDERR, 'Read-only section verification failed (' . $kind . ").\n");

            return 1;
        } finally {
            if ($temporary !== null && $recovery === null) {
                @unlink($temporary);
            }
        }
    }
}
