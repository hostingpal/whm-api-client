<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use JsonException;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

// Reserve a private temporary file beside the destination, before sending requests.
$prepareEvidenceOutput = static function (#[SensitiveParameter] string $output): string {
    if (is_link($output) || (file_exists($output) && !is_file($output))) {
        throw new RuntimeException('Evidence destination must be a regular file.');
    }
    $directory = dirname($output);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot prepare evidence directory.');
    }
    $temporary = @tempnam($directory, '.live-verify-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot prepare evidence file.');
    }
    if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot protect evidence file.');
    }

    return $temporary;
};

// Run only the reviewed read-only recipes, with sanitized atomic evidence output.
$verifyCommand = static function (#[SensitiveParameter] array $arguments) use ($prepareEvidenceOutput): int {
    $temporary = null;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/verify.php --output=PATH\n");

            return 0;
        }
        $argument = count($arguments) === 1 ? $arguments[0] : null;
        if (
            !is_string($argument)
            || !str_starts_with($argument, '--output=')
            || strlen($argument) <= strlen('--output=')
        ) {
            throw new LogicException('Invalid command arguments.');
        }
        $output = substr($argument, strlen('--output='));
        $optIn = getenv('WHM_API_LIVE');
        $whmToken = getenv('OBLAK_TEST_WHM_KEY');
        $cpanelToken = getenv('OBLAK_TEST_NPR_KEY');
        $tokens = [
            'whm' => $whmToken === false ? '' : $whmToken,
            'cpanel' => $cpanelToken === false ? '' : $cpanelToken,
        ];
        if ($optIn !== '1' || $tokens['whm'] === '' || $tokens['cpanel'] === '') {
            throw new LogicException('Explicit live opt-in and both credentials are required.');
        }
        if (!extension_loaded('curl')) {
            throw new LogicException('The PHP cURL extension is required.');
        }

        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        $context = RepositoryContext::load($root);
        $probes = ProbeCatalog::load($root);
        $temporary = $prepareEvidenceOutput($output);
        $records = (new EvidenceRunner(new CurlClient(), $context))->run($probes, $optIn, $tokens);
        $json = json_encode(
            ['schema_version' => 1, 'probes' => $records],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (@file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $output)) {
            throw new RuntimeException('Cannot persist evidence.');
        }
        $temporary = null;

        $labels = [
            'whm1:version|whm' => 'whm1:version whm',
            'whm1:systemloadavg|whm' => 'whm1:systemloadavg whm',
            'uapi:DomainInfo/list_domains|direct-uapi' => 'uapi:DomainInfo/list_domains direct-uapi',
            'uapi:DomainInfo/list_domains|bridged-uapi' => 'uapi:DomainInfo/list_domains bridged-uapi',
            'uapi:Email/list_pops|direct-uapi' => 'uapi:Email/list_pops direct-uapi',
            'uapi:Email/list_pops|bridged-uapi' => 'uapi:Email/list_pops bridged-uapi',
        ];
        $outcomes = [
            'passed' => 'passed', 'failed' => 'failed', 'skipped' => 'skipped', 'unsupported' => 'unsupported',
        ];
        $failed = $records === [];
        foreach ($records as $record) {
            $key = $record['operation'] . '|' . $record['route'];
            $outcome = $record['probe_outcome'];
            if (!isset($labels[$key], $outcomes[$outcome])) {
                throw new RuntimeException('Unexpected evidence record.');
            }
            fwrite(STDOUT, $labels[$key] . ': ' . $outcomes[$outcome] . "\n");
            $failed = $failed || $outcome !== 'passed' || $record['discrepancies'] !== [];
        }

        return $failed ? 1 : 0;
    } catch (Throwable $error) {
        $kind = match (true) {
            $error instanceof JsonException => 'serialization_failure',
            $error instanceof LogicException => 'configuration_failure',
            $error instanceof RuntimeException => 'execution_failure',
            default => 'unexpected_failure',
        };
        fwrite(STDERR, 'Live verification failed (' . $kind . ").\n");

        return 1;
    } finally {
        if ($temporary !== null) {
            @unlink($temporary);
        }
    }
};

exit($verifyCommand(array_slice($argv, 1)));
