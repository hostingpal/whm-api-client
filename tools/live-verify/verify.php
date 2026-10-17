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

// Run reviewed probes and at most one explicitly opted-in fixture recipe.
$verifyCommand = static function (#[SensitiveParameter] array $arguments) use ($prepareEvidenceOutput): int {
    $temporary = null;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/verify.php "
                . "[--mysql-fixture|--finite-quota-fixture] --output=PATH\n");

            return 0;
        }
        $mysqlFixture = in_array('--mysql-fixture', $arguments, true);
        $finiteQuotaFixture = in_array('--finite-quota-fixture', $arguments, true);
        if ($mysqlFixture && $finiteQuotaFixture) {
            throw new LogicException('Only one fixture recipe may run at a time.');
        }
        if ($mysqlFixture || $finiteQuotaFixture) {
            $position = array_search($mysqlFixture ? '--mysql-fixture' : '--finite-quota-fixture', $arguments, true);
            unset($arguments[$position]);
            $arguments = array_values($arguments);
            if (getenv('WHM_API_LIVE_MUTATIONS') !== '1') {
                throw new LogicException('Fixtures require explicit mutation opt-in.');
            }
        }
        if (
            $finiteQuotaFixture
            && (getenv('WHM_API_LIVE_ACCOUNT') !== 'wqfinite'
                || getenv('WHM_API_LIVE_DOMAIN') !== 'wqfinite.example.com')
        ) {
            throw new LogicException('Finite Quota requires its exact disposable account and domain.');
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
        $policy = DiscrepancyPolicy::load($root);
        $policy->assertOutputAllowed($output);
        $probes = ProbeCatalog::load($root);
        foreach ($probes as $probe) {
            if ($finiteQuotaFixture && $probe->unavailable !== null) {
                throw new LogicException('Finite Quota requires an available reviewed baseline catalog.');
            }
        }
        $temporary = $prepareEvidenceOutput($output);
        $capture = new EvidenceRunner(new CurlClient(), $context);
        $fixtureReport = null;
        $mysqlMutations = null;
        if ($mysqlFixture) {
            $fixture = new MysqlFixture(
                new CurlClient(allowFormPost: true),
                $tokens,
                bin2hex(random_bytes(3)),
                $context,
            );
            $result = $fixture->run(
                $probes,
                static fn (array $selected): array => $capture->run($selected, $optIn, $tokens),
            );
            $records = $result['probes'];
            $fixtureReport = $result['mysql_fixture'];
            $mysqlMutations = MysqlMutationEvidence::finish($result['mysql_mutations'], $records);
        } else {
            $records = $capture->run($probes, $optIn, $tokens);
        }
        $review = $policy->assess($records);
        $finiteQuota = null;
        if ($finiteQuotaFixture) {
            $fixture = new FiniteQuotaFixture(new CurlClient(allowFormPost: true), $tokens['whm']);
            $finiteQuota = $fixture->run(
                static fn (#[SensitiveParameter] $http, #[SensitiveParameter] string $token): array =>
                    (new FiniteQuotaEvidence($http, $context))->run($probes, $tokens['whm'], $token),
            );
        }
        $document = ['schema_version' => 2, 'probes' => $records, 'discrepancy_review' => $review];
        if ($fixtureReport !== null) {
            $document['mysql_fixture'] = $fixtureReport;
            $document['mysql_mutations'] = $mysqlMutations;
        }
        if ($finiteQuota !== null) {
            $document['finite_quota'] = $finiteQuota;
        }
        $json = json_encode(
            $document,
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
            'uapi:Quota/get_quota_info|direct-uapi'
                => 'uapi:Quota/get_quota_info direct-uapi',
            'uapi:Quota/get_quota_info|bridged-uapi'
                => 'uapi:Quota/get_quota_info bridged-uapi',
            'uapi:Quota/get_local_quota_info|direct-uapi'
                => 'uapi:Quota/get_local_quota_info direct-uapi',
            'uapi:Quota/get_local_quota_info|bridged-uapi'
                => 'uapi:Quota/get_local_quota_info bridged-uapi',
            'uapi:Mysql/list_databases|direct-uapi' => 'uapi:Mysql/list_databases direct-uapi',
            'uapi:Mysql/list_databases|bridged-uapi' => 'uapi:Mysql/list_databases bridged-uapi',
            'uapi:Mysql/list_users|direct-uapi' => 'uapi:Mysql/list_users direct-uapi',
            'uapi:Mysql/list_users|bridged-uapi' => 'uapi:Mysql/list_users bridged-uapi',
            'uapi:Mysql/get_restrictions|direct-uapi' => 'uapi:Mysql/get_restrictions direct-uapi',
            'uapi:Mysql/get_restrictions|bridged-uapi' => 'uapi:Mysql/get_restrictions bridged-uapi',
            'uapi:Mysql/get_server_information|direct-uapi' => 'uapi:Mysql/get_server_information direct-uapi',
            'uapi:Mysql/get_server_information|bridged-uapi' => 'uapi:Mysql/get_server_information bridged-uapi',
            'uapi:Mysql/get_privileges_on_database|direct-uapi' => 'uapi:Mysql/get_privileges_on_database direct-uapi',
            'uapi:Mysql/get_privileges_on_database|bridged-uapi'
                => 'uapi:Mysql/get_privileges_on_database bridged-uapi',
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
        foreach ($records as $record) {
            $key = $record['operation'] . '|' . $record['route'];
            $outcome = $record['probe_outcome'];
            if (!isset($labels[$key], $outcomes[$outcome])) {
                throw new RuntimeException('Unexpected evidence record.');
            }
            fwrite(STDOUT, $labels[$key] . ': ' . $outcomes[$outcome] . "\n");
        }

        $reviewStatus = $review['status'] === 'passed' ? 'passed' : 'failed';
        fwrite(STDOUT, 'Discrepancy review: ' . $reviewStatus
            . ' (acknowledged: ' . count($review['acknowledged'])
            . ', unexpected: ' . count($review['unexpected'])
            . ', stale: ' . count($review['stale']) . ")\n");
        $acknowledgmentLabels = [
            'quota-total-direct-11.138.0.15' => 'quota-total-direct-11.138.0.15 ($.* unknown_field)',
            'quota-total-bridge-11.138.0.15' => 'quota-total-bridge-11.138.0.15 ($.* unknown_field)',
        ];
        foreach (['acknowledged' => 'Acknowledged', 'stale' => 'Stale'] as $field => $label) {
            foreach ($review[$field] as $id) {
                if (!isset($acknowledgmentLabels[$id])) {
                    throw new RuntimeException('Unexpected discrepancy acknowledgment.');
                }
                fwrite(STDOUT, $label . ': ' . $acknowledgmentLabels[$id] . "\n");
            }
        }
        /** @var array<string, string> $unexpectedReasons */
        $unexpectedReasons = [
            'unacknowledged_discrepancy' => 'unacknowledged discrepancy',
            'duplicate_probe' => 'duplicate probe',
            'unsuccessful_probe' => 'unsuccessful probe',
            'empty_run' => 'empty run',
        ];
        foreach ($review['unexpected'] as $finding) {
            $reason = $finding['reason'];
            if (!isset($unexpectedReasons[$reason])) {
                throw new RuntimeException('Unexpected discrepancy review reason.');
            }
            $key = $finding['operation'] . '|' . $finding['route'];
            $label = $labels[$key] ?? 'unrecognized probe';
            fwrite(STDOUT, 'Unexpected: ' . ($reason === 'empty_run' ? $unexpectedReasons[$reason]
                : $label . ' (' . $unexpectedReasons[$reason] . ')') . "\n");
        }

        if ($fixtureReport !== null) {
            fwrite(STDOUT, 'MySQL fixture: ' . ($fixtureReport['status'] === 'passed' ? 'passed' : 'failed') . "\n");
        }

        $fixturePassed = $fixtureReport === null || $fixtureReport['status'] === 'passed';
        if ($mysqlMutations !== null) {
            $mutationPassed = $mysqlMutations['discrepancy_review']['status'] === 'passed';
            fwrite(STDOUT, 'MySQL mutation evidence: ' . ($mutationPassed ? 'passed' : 'failed') . "\n");
            $fixturePassed = $fixturePassed && $mutationPassed;
        }
        $finitePassed = true;
        if ($finiteQuota !== null) {
            $lifecycleStatus = $finiteQuota['fixture']['status'] === 'passed' ? 'passed' : 'failed';
            $assessmentStatus = $finiteQuota['discrepancy_review']['status'] === 'passed' ? 'passed' : 'failed';
            fwrite(STDOUT, 'Finite Quota fixture: ' . $lifecycleStatus . "\n");
            fwrite(STDOUT, 'Finite Quota discrepancy review: ' . $assessmentStatus . "\n");
            $finitePassed = $lifecycleStatus === 'passed' && $assessmentStatus === 'passed';
        }

        return $review['status'] === 'passed' && $fixturePassed && $finitePassed ? 0 : 1;
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
