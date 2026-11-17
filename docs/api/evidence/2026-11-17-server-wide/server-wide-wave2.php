<?php

declare(strict_types=1);

// One-off server-wide live battery for the 2026-11-17 snapshot window on test.oblak.host.
// Usage: WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_SERVER_WIDE=1 \
//        php server-wide-battery.php REPO_ROOT SUMMARY_JSON PRIVATE_LOG
// The summary holds outcome classes only; the private log (never committed) keeps upstream error text.

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

[$self, $root, $summaryPath, $logPath] = $argv + [null, null, null, null];
if (!is_string($root) || !is_string($summaryPath) || !is_string($logPath)) {
    fwrite(STDERR, "usage: server-wide-battery.php REPO_ROOT SUMMARY_JSON PRIVATE_LOG\n");
    exit(2);
}
foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS', 'WHM_API_LIVE_SERVER_WIDE'] as $flag) {
    if (getenv($flag) !== '1') {
        fwrite(STDERR, "configuration_failure: $flag=1 required\n");
        exit(2);
    }
}
$token = getenv('OBLAK_TEST_WHM_KEY');
if (!is_string($token) || $token === '') {
    fwrite(STDERR, "configuration_failure: OBLAK_TEST_WHM_KEY required\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

/** Minimal guarded PSR-18 cURL client: test.oblak.host WHM/cPanel ports only, verified TLS, no redirects. */
final class GuardedCurl implements ClientInterface
{
    public function __construct(private Psr17Factory $factory)
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        if (
            $uri->getScheme() !== 'https' || $uri->getHost() !== 'test.oblak.host'
            || !in_array($uri->getPort(), [2083, 2087], true) || $uri->getUserInfo() !== ''
        ) {
            throw new LogicException('Request target outside test.oblak.host WHM/cPanel ports refused.');
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name . ': ' . implode(', ', $values);
        }
        $curl = curl_init((string) $uri);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $request->getMethod() === 'GET' ? null : (string) $request->getBody(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body)) {
            throw new RuntimeException('Transport failure: ' . $errno . ' ' . $error);
        }

        return $this->factory->createResponse((int) $status)
            ->withHeader('Content-Type', $type)
            ->withBody($this->factory->createStream($body));
    }
}

$factory = new Psr17Factory();
$client = new Client(['host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm', 'username' => 'root',
    'token' => $token], new HttpTransport(new GuardedCurl($factory), $factory, $factory));
$whm = $client->whm();
$steps = [];
$log = [];

/** Run one step, keeping only an outcome class (and a structural note) in the summary. */
$step = static function (string $id, string $group, Closure $call) use (&$steps, &$log): mixed {
    try {
        $result = $call();
        $steps[] = ['id' => $id, 'group' => $group, 'outcome' => 'passed', 'shape' => get_debug_type($result)];

        return $result;
    } catch (PayloadMismatchException $e) {
        $steps[] = ['id' => $id, 'group' => $group, 'outcome' => 'payload_mismatch',
            'outcome_confirmed' => $e->outcomeConfirmed];
        $log[] = "$id payload_mismatch: " . $e->getMessage();
    } catch (ApiErrorException $e) {
        $steps[] = ['id' => $id, 'group' => $group, 'outcome' => 'api_error'];
        $log[] = "$id api_error: " . implode(' | ', $e->errors()) . ' :: ' . $e->getMessage();
    } catch (Throwable $e) {
        $steps[] = ['id' => $id, 'group' => $group, 'outcome' => 'failure', 'class' => $e::class];
        $log[] = "$id " . $e::class . ': ' . $e->getMessage();
    }

    return null;
};
$expect = static function (string $id, bool $ok) use (&$steps): void {
    $steps[] = ['id' => $id, 'group' => 'expectation', 'outcome' => $ok ? 'passed' : 'failed'];
};

// Read-only context.
$version = $step('whm1:version', 'read-only', fn () => $whm->server()->version());

// Greylisting: observe the inventories that fail while the service is disabled, then restore the state.
$grey = $step('whm1:cpgreylist_status(before)', 'read-only', fn () => $whm->mail()->cpgreylistStatus());
$step('whm1:enable_cpgreylist', 'server-wide', fn () => $whm->mail()->enableCpgreylist());
$step('whm1:cpgreylist_status(enabled)', 'read-only', fn () => $whm->mail()->cpgreylistStatus());
$step('whm1:cpgreylist_is_server_netblock_trusted', 'read-only',
    fn () => $whm->mail()->cpgreylistIsServerNetblockTrusted());
$step('whm1:cpgreylist_load_common_mail_providers_config', 'read-only',
    fn () => $whm->mail()->cpgreylistLoadCommonMailProvidersConfig());
$step('whm1:read_cpgreylist_trusted_hosts', 'read-only', fn () => $whm->mail()->readCpgreylistTrustedHosts());
$step('whm1:load_cpgreylist_config', 'read-only', fn () => $whm->mail()->loadCpgreylistConfig());
$step('whm1:disable_cpgreylist', 'server-wide', fn () => $whm->mail()->disableCpgreylist());

// ModSecurity batch settings: re-save one setting's current state through the record list, then deploy.
$settings = $step('whm1:modsec_get_settings', 'read-only', fn () => $whm->modSecurity()->getSettings());
$row = null;
foreach ((is_array($settings) ? ($settings['settings'] ?? []) : []) as $candidate) {
    if (isset($candidate['setting_id'], $candidate['state'])) {
        $row = $candidate;
        break;
    }
}
$expect('modsec setting with id and state found', $row !== null);
if ($row !== null) {
    $step('whm1:modsec_batch_settings(record list)', 'server-wide', fn () => $whm->modSecurity()->updateSettings([
        ['settingId' => $row['setting_id'], 'state' => $row['state']],
    ]));
    $step('whm1:modsec_deploy_settings_changes', 'lockout', fn () => $whm->modSecurity()->deploySettingsChanges());
    $after = $step('whm1:modsec_get_settings(after)', 'read-only', fn () => $whm->modSecurity()->getSettings());
    $same = null;
    foreach ((is_array($after) ? ($after['settings'] ?? []) : []) as $candidate) {
        if (($candidate['setting_id'] ?? null) === $row['setting_id']) {
            $same = $candidate;
        }
    }
    $expect('modsec setting state unchanged after re-save', is_array($same)
        && (string) ($same['state'] ?? '') === (string) $row['state']);
}

// cPHulk last (lockout group): enable, whitelist round trip, then return to the original state.
$hulk = $step('whm1:cphulk_status(before)', 'read-only', fn () => $whm->cpHulk()->status());
$step('whm1:enable_cphulk', 'lockout', fn () => $whm->cpHulk()->enable());
$step('whm1:load_cphulk_config', 'read-only', fn () => $whm->cpHulk()->loadConfig());
$step('whm1:create_cphulk_record(list)', 'lockout', fn () => $whm->cpHulk()->createRecord(
    ips: ['192.0.2.55', '192.0.2.56'],
    listName: 'white',
    comment: 'live battery 2026-11-17',
));
$records = $step('whm1:read_cphulk_records(white)', 'read-only', fn () => $whm->cpHulk()->readRecords('white'));
$expect('whitelist records readable', is_array($records));
$step('whm1:delete_cphulk_record(list)', 'lockout',
    fn () => $whm->cpHulk()->deleteRecord(['192.0.2.55', '192.0.2.56'], 'white'));
$wasEnabled = is_array($hulk) && (int) ($hulk['is_enabled'] ?? 0) === 1;
if (!$wasEnabled) {
    $step('whm1:disable_cphulk', 'lockout', fn () => $whm->cpHulk()->disable());
}
$step('whm1:version(after lockout group)', 'read-only', fn () => $whm->server()->version());

$counts = array_count_values(array_column($steps, 'outcome'));
$summary = ['schema' => 'server-wide-battery-wave2-v1', 'host' => 'test.oblak.host',
    'server_version' => is_string($version) ? $version : null,
    'date' => gmdate('c'), 'counts' => $counts, 'steps' => $steps];
file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($logPath, implode("\n", $log) . "\n");
foreach ($steps as $s) {
    echo $s['id'] . ': ' . $s['outcome'] . "\n";
}
exit(($counts['failed'] ?? 0) + ($counts['failure'] ?? 0) > 0 ? 1 : 0);
