<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use SensitiveParameter;
use Throwable;

// Publish one committed-code DNS zone run on the designated disposable account.
$command = static function (#[SensitiveParameter] array $arguments): int {
    $publication = null;
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
        $publication = new EvidencePublication($output, '.dns-zone-writes-');
        $scenario = (new DnsZoneFixture(
            new CurlClient(allowFormPost: true),
            $whm,
            $context,
        ))->run();
        $json = json_encode(
            ['schema_version' => 1, 'dns_zone_writes' => $scenario],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $publication->publish($json);
        $passed = $scenario['fixture']['status'] === 'passed';
        fwrite(STDOUT, 'DNS zone fixture: ' . ($passed ? 'passed' : 'failed') . "\n");

        $reviewed = $scenario['discrepancy_review']['status'] === 'passed';
        fwrite(STDOUT, 'DNS evidence review: ' . ($reviewed ? 'passed' : 'failed') . "\n");

        return $passed && $reviewed ? 0 : 1;
    } catch (Throwable $error) {
        $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
        fwrite(STDERR, 'DNS zone verification failed (' . $kind . ").\n");
        $recovery = $publication?->quotedRecoveryPath();
        if ($recovery !== null) {
            fwrite(STDERR, 'DNS zone report retained at: ' . $recovery . "\n");
        }

        return 1;
    } finally {
        $publication?->finish();
    }
};

exit($command(array_slice($argv, 1)));
