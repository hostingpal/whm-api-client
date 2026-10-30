<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Run one committed, account-bound mutation fixture and publish protected structural evidence.
 *
 * Entrypoints bind the reviewed fixture and safe display identifiers. Command arguments select only
 * a new output path. The fixture owns its exact requests, guards and cleanup; it executes once after
 * both live opt-ins, the npr/npr.rs target, tokens and committed context have been verified. Complete
 * reports survive failed new-only publication for recovery; incomplete files and raw errors do not.
 *
 * @phpstan-import-type Context from RepositoryContext
 */
final class SectionCommand
{
    /**
     * Preserve each fixture's report unchanged, with its status determining the exit code.
     *
     * @template Report of array{status:'passed'|'failed'}
     * @param list<string> $arguments
     * @param Closure(array{whm:string,cpanel:string}, string, Context): Report $fixture
     */
    public static function run(
        #[SensitiveParameter] array $arguments,
        string $root,
        string $scriptName,
        string $label,
        string $reportKey,
        Closure $fixture,
    ): int {
        $temporary = null;
        $recovery = null;
        $safeLabel = 'Section';
        try {
            if (
                preg_match('/\A[a-z][a-z0-9-]*\.php\z/', $scriptName) !== 1
                || preg_match('/\A[A-Za-z][A-Za-z0-9 _-]*\z/', $label) !== 1
                || preg_match('/\A[a-z][a-z0-9_]*\z/', $reportKey) !== 1
            ) {
                throw new LogicException('Invalid section identifiers.');
            }
            $safeLabel = $label;
            if ($arguments === ['--help']) {
                fwrite(STDOUT, 'Usage: php tools/live-verify/' . $scriptName . " --output=PATH\n"
                    . 'Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=npr '
                    . "WHM_API_LIVE_DOMAIN=npr.rs and both test tokens.\n");

                return 0;
            }
            if (count($arguments) === 2 && $arguments[0] === '--output') {
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
            $temporary = @tempnam($directory, '.' . substr($scriptName, 0, -4) . '-');
            if ($temporary === false) {
                $temporary = null;
                throw new RuntimeException('Evidence file unavailable.');
            }
            if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
                throw new RuntimeException('Evidence file protection unavailable.');
            }
            $report = $fixture(['whm' => $whm, 'cpanel' => $cpanel], bin2hex(random_bytes(3)), $context);
            $json = json_encode(
                ['schema_version' => 1, $reportKey => $report],
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
            fwrite(STDOUT, $safeLabel . ' section fixture: ' . ($passed ? 'passed' : 'failed') . "\n");

            return $passed ? 0 : 1;
        } catch (Throwable $error) {
            $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
            if ($recovery !== null) {
                fwrite(STDERR, 'Recovery evidence retained at '
                    . json_encode($recovery, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . ".\n");
            }
            fwrite(STDERR, $safeLabel . ' section verification failed (' . $kind . ").\n");

            return 1;
        } finally {
            if ($temporary !== null && $recovery === null) {
                @unlink($temporary);
            }
        }
    }
}
