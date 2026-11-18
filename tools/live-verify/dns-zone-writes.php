<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

// Publish one committed-code DNS zone run on the designated disposable account.
$command = static function (#[SensitiveParameter] array $arguments): int {
    $temporary = null;
    $reportReady = false;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/dns-zone-writes.php --output=PATH\n"
                . "Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=wldnsrecipe "
                . "WHM_API_LIVE_DOMAIN=dns-recipe.example.test and OBLAK_TEST_WHM_KEY.\n");

            return 0;
        }
        if (count($arguments) === 2 && $arguments[0] === '--output' && is_string($arguments[1])) {
            $arguments = ['--output=' . $arguments[1]];
        }
        $argument = count($arguments) === 1 ? $arguments[0] : null;
        if (!is_string($argument) || !str_starts_with($argument, '--output=') || strlen($argument) <= 9) {
            throw new LogicException('Invalid DNS zone arguments.');
        }
        $whm = getenv('OBLAK_TEST_WHM_KEY');
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'wldnsrecipe'
            || getenv('WHM_API_LIVE_DOMAIN') !== 'dns-recipe.example.test'
            || $whm === false || $whm === '' || !extension_loaded('curl')
        ) {
            throw new LogicException('DNS zone authorization or runtime unavailable.');
        }
        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        $context = RepositoryContext::load($root);
        $output = substr($argument, 9);
        if (is_link($output) || file_exists($output)) {
            throw new LogicException('DNS zone evidence requires a new destination.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('DNS zone evidence directory unavailable.');
        }
        $temporary = @tempnam($directory, '.dns-zone-writes-');
        if ($temporary === false) {
            $temporary = null;
            throw new RuntimeException('DNS zone evidence file unavailable.');
        }
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
            throw new RuntimeException('DNS zone evidence protection unavailable.');
        }
        $scenario = (new DnsZoneFixture(
            new CurlClient(allowFormPost: true),
            $whm,
            $context,
        ))->run();
        $json = json_encode(
            ['schema_version' => 1, 'dns_zone_writes' => $scenario],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException('DNS zone evidence persistence failed.');
        }
        $reportReady = true;
        if (!@link($temporary, $output)) {
            throw new RuntimeException('DNS zone evidence persistence failed.');
        }
        @unlink($temporary);
        $temporary = null;
        $passed = $scenario['fixture']['status'] === 'passed';
        fwrite(STDOUT, 'DNS zone fixture: ' . ($passed ? 'passed' : 'failed') . "\n");

        $reviewed = $scenario['discrepancy_review']['status'] === 'passed';
        fwrite(STDOUT, 'DNS evidence review: ' . ($reviewed ? 'passed' : 'failed') . "\n");

        return $passed && $reviewed ? 0 : 1;
    } catch (Throwable $error) {
        $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
        fwrite(STDERR, 'DNS zone verification failed (' . $kind . ").\n");
        if ($reportReady && $temporary !== null) {
            fwrite(STDERR, 'DNS zone report retained at: ' . $temporary . "\n");
        }

        return 1;
    } finally {
        if ($temporary !== null && !$reportReady) {
            @unlink($temporary);
        }
    }
};

exit($command(array_slice($argv, 1)));
