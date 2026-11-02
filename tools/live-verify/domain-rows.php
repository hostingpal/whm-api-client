<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

// Committed-code disposable-account domain-rows proof: a new-only structural artifact, or a private name inspection.
$command = static function (#[SensitiveParameter] array $arguments): int {
    $temporary = null;
    $reportReady = false;
    try {
        if ($arguments === ['--help']) {
            fwrite(STDOUT, "Usage: php tools/live-verify/domain-rows.php --output=PATH\n"
                . "       php tools/live-verify/domain-rows.php --inspect-names\n"
                . "Requires WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=wldomains "
                . "WHM_API_LIVE_DOMAIN=wldomains.example.com and OBLAK_TEST_WHM_KEY.\n"
                . "WHM_API_LIVE_FS_CHECK=1 adds the read-only filesystem proof over SSH.\n"
                . "--inspect-names runs the same recipe and prints undeclared key names and JSON types only.\n");

            return 0;
        }
        if (count($arguments) === 2 && $arguments[0] === '--output' && is_string($arguments[1])) {
            $arguments = ['--output=' . $arguments[1]];
        }
        $argument = count($arguments) === 1 ? $arguments[0] : null;
        $inspect = $argument === '--inspect-names';
        if (
            !$inspect
            && (!is_string($argument) || !str_starts_with($argument, '--output=') || strlen($argument) <= 9)
        ) {
            throw new LogicException('Invalid domain rows arguments.');
        }
        $whm = getenv('OBLAK_TEST_WHM_KEY');
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'wldomains'
            || getenv('WHM_API_LIVE_DOMAIN') !== 'wldomains.example.com'
            || $whm === false || $whm === '' || !extension_loaded('curl')
        ) {
            throw new LogicException('Domain rows authorization or runtime unavailable.');
        }
        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        $context = RepositoryContext::load($root);
        if ($inspect) {
            $scenario = (new DomainRowsFixture(
                new CurlClient(allowFormPost: true),
                $whm,
                $context,
                SshFilesystemAbsenceProof::fromEnvironment(),
                static function (string $phase, Probe $probe, #[SensitiveParameter] ?string $raw): void {
                    $type = is_array($probe->spec['returns'] ?? null) ? $probe->spec['returns'] : [];
                    foreach (DomainRowsInspection::undeclared($probe->route, $raw, $type) as $line) {
                        fwrite(STDOUT, $phase . ' ' . $line . "\n");
                    }
                },
            ))->run();
            foreach ($scenario['probes'] as $record) {
                fwrite(STDOUT, 'phase ' . $record['phase'] . ' ' . $record['route'] . ': probe='
                    . $record['probe_outcome'] . ' api=' . $record['api_outcome'] . ' validation='
                    . $record['payload_validation'] . ' failure=' . ($record['failure_kind'] ?? '-')
                    . ' uapi_status=' . ($record['uapi_status'] ?? '-') . ' expectation=' . $record['expectation']
                    . ' discrepancies=' . count($record['discrepancies']) . "\n");
            }
            foreach ($scenario['dns_results'] as $phase => $result) {
                fwrite(STDOUT, 'dns ' . $phase . ': returned=' . ($result['returned'] ?? '-') . ' identities='
                    . $result['identities'] . ' order=' . $result['order'] . "\n");
            }
            foreach ($scenario['domain_kinds'] as $phase => $kinds) {
                $listed = [];
                foreach ($kinds as $kind => $present) {
                    $listed[] = $kind . '=' . ($present ? '1' : '0');
                }
                fwrite(STDOUT, 'kinds ' . $phase . ': ' . implode(' ', $listed) . "\n");
            }
            foreach ($scenario['single_domain'] as $phase => $outcome) {
                fwrite(STDOUT, 'single ' . $phase . ': ' . $outcome . "\n");
            }
            $fixture = $scenario['fixture'];
            foreach ($fixture['resources'] as $label => $outcome) {
                fwrite(STDOUT, 'resource ' . $label . ': ' . $outcome['creation'] . ' ' . $outcome['cleanup'] . "\n");
            }
            fwrite(STDOUT, 'filesystem: ' . $fixture['filesystem_cleanup'] . "\n");
            foreach ($fixture['extra_filesystem'] as $label => $outcome) {
                fwrite(STDOUT, 'extra filesystem ' . $label . ': ' . $outcome['control']['status'] . ' '
                    . $outcome['cleanup'] . "\n");
            }
            fwrite(STDOUT, 'failures: ' . implode(' ', $fixture['failures']) . "\n");
            fwrite(STDOUT, 'review: ' . implode(' ', $scenario['discrepancy_review']['findings']) . "\n");
            fwrite(STDOUT, 'Domain rows inspection fixture: ' . $fixture['status'] . "\n");

            return $fixture['status'] === 'passed' ? 0 : 1;
        }
        $output = substr((string) $argument, 9);
        if (is_link($output) || file_exists($output)) {
            throw new LogicException('Domain rows evidence requires a new destination.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Domain rows evidence directory unavailable.');
        }
        $temporary = @tempnam($directory, '.domain-rows-');
        if ($temporary === false) {
            $temporary = null;
            throw new RuntimeException('Domain rows evidence file unavailable.');
        }
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
            throw new RuntimeException('Domain rows evidence protection unavailable.');
        }
        $scenario = (new DomainRowsFixture(
            new CurlClient(allowFormPost: true),
            $whm,
            $context,
            SshFilesystemAbsenceProof::fromEnvironment(),
        ))->run();
        $json = json_encode(
            ['schema_version' => 1, 'domain_rows' => $scenario],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException('Domain rows evidence persistence failed.');
        }
        $reportReady = true;
        if (!@link($temporary, $output)) {
            throw new RuntimeException('Domain rows evidence persistence failed.');
        }
        @unlink($temporary);
        $temporary = null;
        $passed = $scenario['fixture']['status'] === 'passed';
        $reviewed = $scenario['discrepancy_review']['status'] === 'passed';
        fwrite(STDOUT, 'Domain rows fixture: ' . ($passed ? 'passed' : 'failed') . "\n");
        fwrite(STDOUT, 'Domain rows discrepancy review: ' . ($reviewed ? 'passed' : 'failed') . "\n");

        return $passed && $reviewed ? 0 : 1;
    } catch (Throwable $error) {
        $kind = $error instanceof LogicException ? 'configuration_failure' : 'execution_failure';
        fwrite(STDERR, 'Domain rows verification failed (' . $kind . ").\n");
        if ($reportReady && $temporary !== null) {
            fwrite(STDERR, 'Domain rows report retained at: ' . $temporary . "\n");
        }

        return 1;
    } finally {
        if ($temporary !== null && !$reportReady) {
            @unlink($temporary);
        }
    }
};

exit($command(array_slice($argv, 1)));
