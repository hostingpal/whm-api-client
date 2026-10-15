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
            'uapi:Email/list_mail_domains|direct-uapi' => 'uapi:Email/list_mail_domains direct-uapi',
            'uapi:Email/list_mail_domains|bridged-uapi' => 'uapi:Email/list_mail_domains bridged-uapi',
            'uapi:Email/list_domain_forwarders|direct-uapi' => 'uapi:Email/list_domain_forwarders direct-uapi',
            'uapi:Email/list_domain_forwarders|bridged-uapi' => 'uapi:Email/list_domain_forwarders bridged-uapi',
            'uapi:Email/count_pops|direct-uapi' => 'uapi:Email/count_pops direct-uapi',
            'uapi:Email/count_pops|bridged-uapi' => 'uapi:Email/count_pops bridged-uapi',
            'uapi:SSL/installed_hosts|direct-uapi'
                => 'uapi:SSL/installed_hosts direct-uapi',
            'uapi:SSL/installed_hosts|bridged-uapi'
                => 'uapi:SSL/installed_hosts bridged-uapi',
            'uapi:SSL/get_autossl_excluded_domains|direct-uapi'
                => 'uapi:SSL/get_autossl_excluded_domains direct-uapi',
            'uapi:SSL/get_autossl_excluded_domains|bridged-uapi'
                => 'uapi:SSL/get_autossl_excluded_domains bridged-uapi',
            'uapi:SSL/get_autossl_problems|direct-uapi'
                => 'uapi:SSL/get_autossl_problems direct-uapi',
            'uapi:SSL/get_autossl_problems|bridged-uapi'
                => 'uapi:SSL/get_autossl_problems bridged-uapi',
            'uapi:SSL/get_autossl_renewal_status|direct-uapi'
                => 'uapi:SSL/get_autossl_renewal_status direct-uapi',
            'uapi:SSL/get_autossl_renewal_status|bridged-uapi'
                => 'uapi:SSL/get_autossl_renewal_status bridged-uapi',
            'uapi:SSL/is_autossl_check_in_progress|direct-uapi'
                => 'uapi:SSL/is_autossl_check_in_progress direct-uapi',
            'uapi:SSL/is_autossl_check_in_progress|bridged-uapi'
                => 'uapi:SSL/is_autossl_check_in_progress bridged-uapi',
            'uapi:SSL/can_ssl_redirect|direct-uapi'
                => 'uapi:SSL/can_ssl_redirect direct-uapi',
            'uapi:SSL/can_ssl_redirect|bridged-uapi'
                => 'uapi:SSL/can_ssl_redirect bridged-uapi',
            'whm1:listaccts|whm' => 'whm1:listaccts whm',
            'whm1:accountsummary|whm' => 'whm1:accountsummary whm',
            'whm1:get_domain_info|whm' => 'whm1:get_domain_info whm',
            'whm1:php_get_installed_versions|whm' => 'whm1:php_get_installed_versions whm',
            'whm1:php_get_system_default_version|whm' => 'whm1:php_get_system_default_version whm',
            'uapi:DomainInfo/primary_domain|direct-uapi' => 'uapi:DomainInfo/primary_domain direct-uapi',
            'uapi:DomainInfo/primary_domain|bridged-uapi' => 'uapi:DomainInfo/primary_domain bridged-uapi',
            'uapi:LangPHP/php_get_installed_versions|direct-uapi'
                => 'uapi:LangPHP/php_get_installed_versions direct-uapi',
            'uapi:LangPHP/php_get_installed_versions|bridged-uapi'
                => 'uapi:LangPHP/php_get_installed_versions bridged-uapi',
            'uapi:LangPHP/php_get_domain_handler|direct-uapi' => 'uapi:LangPHP/php_get_domain_handler direct-uapi',
            'uapi:LangPHP/php_get_domain_handler|bridged-uapi' => 'uapi:LangPHP/php_get_domain_handler bridged-uapi',
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
