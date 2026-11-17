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
        curl_close($curl);
        if (!is_string($body)) {
            throw new RuntimeException('Transport failure.');
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
$step('whm1:system_needs_reboot', 'read-only', fn () => $whm->server()->systemNeedsReboot());
$step('whm1:360Monitoring/webpros_auth_status', 'read-only', fn () => $whm->monitoring()->webProsAuthStatus());
$step('whm1:php_fpm_config_get', 'read-only', fn () => $whm->webServer()->phpFpmConfigGet([]));
$step('whm1:php_fpm_config_get(domain)', 'read-only',
    fn () => $whm->webServer()->phpFpmConfigGet(['domain' => 'npr.rs']));

// Server-wide mutations (undone by the snapshot restore).
$step('whm1:360Monitoring/webpros_auth_clear', 'server-wide', fn () => $whm->monitoring()->webProsAuthClear());
$step('whm1:360Monitoring/set_360_agent_polling', 'server-wide',
    fn () => $whm->monitoring()->set360AgentPolling(interval: 60));
$step('whm1:save_spamd_config(list)', 'server-wide',
    fn () => $whm->mail()->saveSpamdConfig(allowedIps: ['127.0.0.1', '::1'], maxChildren: 5));

// API tokens: list ACL and whitelist inputs, partial rename, list revoke (only tokens this run creates).
$name = 'wsbattery' . bin2hex(random_bytes(3));
$created = $step('whm1:api_token_create(lists)', 'server-wide', fn () => $whm->apiTools()->createToken(
    tokenName: $name,
    acl: ['list-accts', 'show-bandwidth'],
    whitelistIp: ['127.0.0.1', '10.0.0.1'],
));
unset($created); // The returned secret is never logged or kept.
$tokens = $step('whm1:api_token_list(after create)', 'read-only', fn () => $whm->apiTools()->listTokens());
$row = is_array($tokens) ? ($tokens[$name] ?? null) : null;
$expect('token created with both ACLs', is_array($row)
    && ($row['acls']['list-accts'] ?? null) === 1 && ($row['acls']['show-bandwidth'] ?? null) === 1);
$expect('token kept both whitelist addresses', is_array($row) && count((array) ($row['whitelist_ips'] ?? [])) === 2);
$step('whm1:api_token_update(rename only)', 'server-wide',
    fn () => $whm->apiTools()->updateToken(tokenName: $name, newName: $name . 'r'));
$tokens = $step('whm1:api_token_list(after rename)', 'read-only', fn () => $whm->apiTools()->listTokens());
$row = is_array($tokens) ? ($tokens[$name . 'r'] ?? null) : null;
$expect('rename preserved ACLs and whitelist', is_array($row) && ($row['acls']['show-bandwidth'] ?? null) === 1
    && count((array) ($row['whitelist_ips'] ?? [])) === 2);
$step('whm1:api_token_revoke(list with unknown name)', 'server-wide',
    fn () => $whm->apiTools()->revokeToken([$name . 'r', $name . '-missing']));
$tokens = $step('whm1:api_token_list(after revoke)', 'read-only', fn () => $whm->apiTools()->listTokens());
$expect('revoked token absent', is_array($tokens) && !array_key_exists($name . 'r', $tokens));

// Reseller fixture on one disposable account.
$user = 'wsresel';
$domain = 'wsresel.example.com';
$created = $step('whm1:createacct(reseller fixture)', 'account-scoped', fn () => $whm->accounts()->createAccount(
    username: $user,
    domain: $domain,
    password: bin2hex(random_bytes(12)) . 'Aa1!',
));
if ($created !== null || end($steps)['outcome'] === 'passed') {
    $step('whm1:setupreseller', 'server-wide', fn () => $whm->resellers()->setupReseller(user: $user));
    $step('whm1:setresellernameservers(list)', 'server-wide', fn () => $whm->resellers()->setResellerNameservers(
        user: $user,
        nameservers: ['ns1.example.test', 'ns2.example.test'],
    ));
    $ips = $step('whm1:getresellerips', 'read-only', fn () => $whm->resellers()->getResellerIps($user));
    $step('whm1:unsetupreseller', 'server-wide', fn () => $whm->resellers()->unsetupReseller($user));
    $step('whm1:removeacct(reseller fixture)', 'account-scoped', fn () => $whm->accounts()->removeAccount($user));
    $after = $step('whm1:listaccts(fixture absent)', 'read-only',
        fn () => $whm->accounts()->listAccounts(search: $user, searchtype: 'user', searchmethod: 'exact'));
    $expect('reseller fixture removed', $after === []);
}

// Lockout or version-changing calls last.
$step('whm1:php_fpm_config_set(validate_only)', 'lockout', fn () => $whm->webServer()->phpFpmConfigSet([
    'domain' => 'npr.rs',
    'validate_only' => true,
    'config' => [['base_flag_name' => 'pm_max_children', 'trinary_admin_value' => 0, 'value' => '5']],
]));
$step('whm1:update_sql_config', 'lockout', fn () => $whm->databases()->updateSqlConfig([
    'data' => [['section' => 'mysqld', 'name' => 'max_allowed_packet', 'value' => '64M']],
]));
$step('whm1:version(after lockout group)', 'read-only', fn () => $whm->server()->version());

$counts = array_count_values(array_column($steps, 'outcome'));
$summary = ['schema' => 'server-wide-battery-v1', 'host' => 'test.oblak.host',
    'server_version' => is_string($version) ? $version : null,
    'date' => gmdate('c'), 'counts' => $counts, 'steps' => $steps];
file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($logPath, implode("\n", $log) . "\n");
foreach ($steps as $s) {
    echo $s['id'] . ': ' . $s['outcome'] . "\n";
}
exit(($counts['failed'] ?? 0) + ($counts['failure'] ?? 0) > 0 ? 1 : 0);
