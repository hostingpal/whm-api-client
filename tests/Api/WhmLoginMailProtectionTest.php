<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\CpHulk;
use HostingPal\WhmApiClient\Api\Whm\Mail;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

/**
 * Literal contracts independently reviewed against the pinned WHM cPHulk and Greylisting source.
 *
 * @phpstan-type Call Closure(WhmClient): mixed
 * @phpstan-type Case array{string, string, Call, string, ?string}
 */
final class WhmLoginMailProtectionTest extends TestCase
{
    /** @param Call $call */
    #[DataProvider('requests')]
    public function testExactRequestsAndPinnedPayloads(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));

        self::assertSame($payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $call($whm));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
                . ($method === 'GET' && $parameters !== '' ? '&' . $parameters : ''),
            (string) $request->getUri(),
        );
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame($operation, $whm->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, Case> */
    public static function requests(): iterable
    {
        yield 'cPHulk status' => ['cphulk_status', 'GET', static fn (WhmClient $w): array => $w->cpHulk()->status(), '',
            '{"is_enabled":0,"service":"cphulkd"}'];
        yield 'create cPHulk records' => ['create_cphulk_record', 'POST',
            static fn (WhmClient $w): array => $w->cpHulk()->createRecord(
                ['192.0.2.1', '2001:db8::1'],
                'white',
                'A & B',
                false,
            ),
            'ip=192.0.2.1&ip-1=2001%3Adb8%3A%3A1&list_name=white&comment=A%20%26%20B&skip_enabled_check=0',
            '{"comment":"A & B","ip_blocks_removed":0,"ips_added":["192.0.2.1"],'
                . '"ips_failed":{"2001:db8::1":"rejected"},"iptable_bans_removed":0,"list_name":"white",'
                . '"requester_ip":"192.0.2.2","requester_ip_is_whitelisted":0}'];
        yield 'delete cPHulk records' => ['delete_cphulk_record', 'POST',
            static fn (WhmClient $w): array => $w->cpHulk()->deleteRecord('192.0.2.1', 'black', true),
            'ip=192.0.2.1&list_name=black&skip_enabled_check=1',
            '{"ips_failed":{},"ips_removed":["192.0.2.1"],"list_name":"black","requester_ip":"192.0.2.2",'
                . '"requester_ip_is_whitelisted":1}'];
        yield 'disable cPHulk' => ['disable_cphulk', 'POST',
            static function (WhmClient $w): void {
                $w->cpHulk()->disable();
            }, '', null];
        yield 'enable cPHulk' => ['enable_cphulk', 'POST',
            static function (WhmClient $w): void {
                $w->cpHulk()->enable();
            }, '', null];
        yield 'flush all history' => ['flush_cphulk_login_history', 'POST',
            static fn (WhmClient $w): array => $w->cpHulk()->flushLoginHistory(), '', '{"records_removed":0}'];
        yield 'flush selected history' => ['flush_cphulk_login_history_for_ips', 'POST',
            static fn (WhmClient $w): array => $w->cpHulk()->flushLoginHistoryForIps(['192.0.2.1', '2001:db8::1']),
            'ip=192.0.2.1&ip-1=2001%3Adb8%3A%3A1', '{"iptable_bans_removed":0,"records_removed":2}'];
        yield 'country codes' => ['get_countries_with_known_ip_ranges', 'GET',
            static fn (WhmClient $w): array => $w->cpHulk()->getCountriesWithKnownIpRanges(), '',
            '{"countries":[{"code":"RS","name":"Serbia"},{}]}'];
        yield 'brutes' => ['get_cphulk_brutes', 'GET', static fn (WhmClient $w): array => $w->cpHulk()->getBrutes(), '',
            '{"brutes":[{"exptime":"2026-10-31 12:00:00","ip":"192.0.2.1","logintime":"2026-10-31 11:00:00",'
                . '"notes":"blocked","timeleft":60}]}'];
        yield 'excessive brutes fractional time' => ['get_cphulk_excessive_brutes', 'GET',
            static fn (WhmClient $w): array => $w->cpHulk()->getExcessiveBrutes(), '',
            '{"excessive_brutes":[{"exptime":"later","ip":"2001:db8::1","logintime":"earlier",'
                . '"notes":"blocked","timeleft":1.5}]}'];
        yield 'failed logins' => ['get_cphulk_failed_logins', 'GET',
            static fn (WhmClient $w): array => $w->cpHulk()->getFailedLogins(), '',
            '{"failed_logins":[{"authservice":"system","exptime":"later","ip":"192.0.2.1",'
                . '"logintime":"earlier","service":"sshd","timeleft":1,"user":"fixture"}]}'];
        yield 'user brutes' => ['get_cphulk_user_brutes', 'GET',
            static fn (WhmClient $w): array => $w->cpHulk()->getUserBrutes(), '',
            '{"user_brutes":[{"authservice":"system","exptime":"later","ip":"192.0.2.1",'
                . '"logintime":"earlier","service":"sshd","timeleft":0,"user":"fixture"}]}'];
        yield 'load cPHulk config optional fields' => ['load_cphulk_config', 'GET',
            static fn (WhmClient $w): array => $w->cpHulk()->loadConfig(), '',
            '{"cphulk_config":{"can_temp_ban_firewall":0,"command_to_run_on_brute_force":"",'
                . '"is_enabled":1,"max_failures":0}}'];
        yield 'read cPHulk records' => ['read_cphulk_records', 'GET',
            static fn (WhmClient $w): array => $w->cpHulk()->readRecords('white', false),
            'list_name=white&skip_enabled_check=0',
            '{"ips_in_list":{"192.0.2.0/24":"fixture"},"list_name":"white","requester_ip":"192.0.2.1",'
                . '"requester_ip_is_whitelisted":0,"restart_ssh":1,"warning_ip":"warning","warning_ssh":"restart"}'];
        yield 'save cPHulk sensitive commands and zero' => ['save_cphulk_config', 'POST',
            static fn (WhmClient $w): array => $w->cpHulk()->saveConfig(
                blockBruteForceWithFirewall: false,
                commandToRunOnBruteForce: 'echo a & b=1',
                commandToRunOnExcessiveBruteForce: '',
                lookbackPeriodMin: 0,
                maxFailures: 0,
                skipEnabledCheck: false,
            ),
            'block_brute_force_with_firewall=0&command_to_run_on_brute_force=echo%20a%20%26%20b%3D1'
                . '&command_to_run_on_excessive_brute_force=&lookback_period_min=0&max_failures=0&skip_enabled_check=0',
            '{"cphulk_config":{"is_enabled":1,"max_failures":0},"restart_ssh":0,"warning":null}'];
        yield 'set cPHulk configuration command' => ['set_cphulk_config_key', 'POST',
            static fn (WhmClient $w): array => $w->cpHulk()->setConfigKey(
                'command_to_run_on_brute_force',
                'echo a & b=1',
            ),
            'key=command_to_run_on_brute_force&value=echo%20a%20%26%20b%3D1',
            '{"cphulk_config":{"command_to_run_on_brute_force":"echo a & b=1","country_blacklist":"RS,US"}}'];
        yield 'server netblock map' => ['cpgreylist_is_server_netblock_trusted', 'GET',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistIsServerNetblockTrusted(), '',
            '{"ip_blocks":{"192.0.2.0/24":0,"2001:db8::/32":1}}'];
        yield 'provider entries' => ['cpgreylist_list_entries_for_common_mail_provider', 'GET',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistListEntriesForCommonMailProvider(
                ['cpanel', 'example'],
            ),
            'provider=cpanel&provider-1=example',
            '{"providers":{"cpanel":{"ips":[{"create_time":"now","host_ip":"192.0.2.1",'
                . '"is_trusted":1,"provider_id":2}]}},"providers_failed":{"example":"unknown"}}'];
        yield 'provider entries pinned list example' => ['cpgreylist_list_entries_for_common_mail_provider', 'GET',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistListEntriesForCommonMailProvider(
                ['cpanel', 'dpanel'],
            ),
            'provider=cpanel&provider-1=dpanel',
            '{"providers":{"cpanel":{"ips":[{"create_time":"2015-07-28T08:16:52.000Z",'
                . '"host_ip":"208.115.214.0-208.115.214.255","is_trusted":1,"provider_id":7},'
                . '{"create_time":"2015-07-28T08:16:52.000Z","host_ip":"74.63.202.0-74.63.202.255",'
                . '"is_trusted":1,"provider_id":7}]}},"providers_failed":{"dpanel":"Unknown mail provider: dpanel"}}'];
        yield 'provider entries empty list and optional row fields' => [
            'cpgreylist_list_entries_for_common_mail_provider', 'GET',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistListEntriesForCommonMailProvider('cpanel'),
            'provider=cpanel', '{"providers":{"empty":{"ips":[]},"sparse":{"ips":[{}]},"omitted":{}},'
                . '"providers_failed":{}}'];
        yield 'common provider configuration' => ['cpgreylist_load_common_mail_providers_config', 'GET',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistLoadCommonMailProvidersConfig(), '',
            '{"autotrust_new_common_mail_providers":0,"common_mail_providers":{"cpanel":'
                . '{"autoupdate":1,"display_name":"cPanel","is_trusted":0}}}'];
        yield 'Greylisting status' => ['cpgreylist_status', 'GET',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistStatus(), '',
            '{"is_enabled":0,"is_exim_enabled":1,"service":"cpgreylistd"}'];
        yield 'trust providers' => ['cpgreylist_trust_entries_for_common_mail_provider', 'POST',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistTrustEntriesForCommonMailProvider('cpanel'),
            'provider=cpanel',
            '{"providers_failed":{"other":"unknown"},"providers_trusted":{"cpanel":{"ips_trusted":2}}}'];
        yield 'untrust retains successful counts and provider errors' => [
            'cpgreylist_untrust_entries_for_common_mail_provider',
            'POST',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistUntrustEntriesForCommonMailProvider('cpanel'),
            'provider=cpanel',
            '{"providers_failed":{"other":"unknown"},"providers_untrusted":{"cpanel":{"ips_untrusted":2}}}'];
        yield 'untrust distinguishes missing counts from zero' => [
            'cpgreylist_untrust_entries_for_common_mail_provider', 'POST',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistUntrustEntriesForCommonMailProvider('cpanel'),
            'provider=cpanel', '{"providers_failed":{},"providers_untrusted":{"empty":{},"zero":{"ips_untrusted":0}}}'];
        yield 'create trusted host range' => ['create_cpgreylist_trusted_host', 'POST',
            static fn (WhmClient $w): array => $w->mail()->createCpgreylistTrustedHost(
                ['192.0.2.0/24', '2001:db8::1'],
                'A & B',
            ),
            'ip=192.0.2.0%2F24&ip-1=2001%3Adb8%3A%3A1&comment=A%20%26%20B',
            '{"comment":"A & B","ips_added":[{"comment":"A & B","create_time":"now",'
                . '"host_ip":"192.0.2.0/24","id":1}],"ips_failed":{"2001:db8::1":"rejected"}}'];
        yield 'create trusted host preserves each address failure' => ['create_cpgreylist_trusted_host', 'POST',
            static fn (WhmClient $w): array => $w->mail()->createCpgreylistTrustedHost(
                ['192.0.2.0/24', '2001:db8::1', 'invalid address'],
            ),
            'ip=192.0.2.0%2F24&ip-1=2001%3Adb8%3A%3A1&ip-2=invalid%20address',
            '{"comment":"","ips_added":[],"ips_failed":{"192.0.2.0/24":"already trusted",'
                . '"2001:db8::1":"","invalid address":"Invalid IP address"}}'];
        yield 'delete trusted host' => ['delete_cpgreylist_trusted_host', 'POST',
            static fn (WhmClient $w): array => $w->mail()->deleteCpgreylistTrustedHost('192.0.2.0/24'),
            'ip=192.0.2.0%2F24', '{"ips_failed":{"2001:db8::1":"unknown"},"ips_removed":["192.0.2.0/24"]}'];
        yield 'disable Greylisting' => ['disable_cpgreylist', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->disableCpgreylist();
            }, '', null];
        yield 'enable Greylisting' => ['enable_cpgreylist', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->enableCpgreylist();
            }, '', null];
        yield 'load Greylisting config' => ['load_cpgreylist_config', 'GET',
            static fn (WhmClient $w): array => $w->mail()->loadCpgreylistConfig(), '',
            '{"cpgreylist_config":{"child_timeout_secs":5,"initial_block_time_mins":10,"is_enabled":0,'
                . '"is_exim_enabled":1,"max_child_procs":5,"must_try_time_mins":240,"purge_interval_mins":60,'
                . '"record_exp_time_mins":4320,"spf_bypass":0}}'];
        yield 'deferred triplet fields' => ['read_cpgreylist_deferred_entries', 'GET',
            static fn (WhmClient $w): array => $w->mail()->readCpgreylistDeferredEntries(), '',
            '{"greylist_deferred_entries":[{"accepted_count":0,"block_exp_time":"later","create_time":"now",'
                . '"deferred_count":1,"from_addr":"sender@example.test","id":1,"must_retry_by":"later",'
                . '"record_exp_time":"later","sender_ip":"192.0.2.1","to_addr":"recipient@example.test"}],'
                . '"limit":0,"offset":0,"server_timezone":"UTC","server_tzoffset":0,"total_rows":1}'];
        yield 'trusted hosts' => ['read_cpgreylist_trusted_hosts', 'GET',
            static fn (WhmClient $w): array => $w->mail()->readCpgreylistTrustedHosts(), '',
            '{"greylist_trusted_hosts":[{"comment":"fixture","create_time":"now","host_ip":"192.0.2.1","id":1}]}'];
        yield 'save Greylisting config explicit false' => ['save_cpgreylist_config', 'POST',
            static fn (WhmClient $w): array => $w->mail()->saveCpgreylistConfig(
                initialBlockTimeMins: 240,
                spfBypass: false,
            ),
            'initial_block_time_mins=240&spf_bypass=0',
            '{"cpgreylist_config":{"initial_block_time_mins":240,"spf_bypass":0}}'];
    }

    /** @param Call $call */
    #[DataProvider('requests')]
    public function testFailedOutcomesRetainMetadataAndNeverRetry(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"' . $operation
            . '","result":0,"reason":"denied","version":1}}'));
        try {
            $call($whm);
            self::fail('A failed WHM outcome must throw.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['denied'], $exception->errors());
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertSame(0, $whm->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testSensitiveArgumentsAndPublicSignatureOrder(): void
    {
        foreach (
            [
            [CpHulk::class, 'createRecord', ['ips', 'listName', 'comment', 'skipEnabledCheck'], 2, []],
            [CpHulk::class, 'deleteRecord', ['ips', 'listName', 'skipEnabledCheck'], 2, []],
            [CpHulk::class, 'flushLoginHistoryForIps', ['ips'], 1, []],
            [CpHulk::class, 'readRecords', ['listName', 'skipEnabledCheck'], 1, []],
            [CpHulk::class, 'setConfigKey', ['key', 'value'], 2, ['value']],
            [Mail::class, 'cpgreylistListEntriesForCommonMailProvider', ['providers'], 1, []],
            [Mail::class, 'cpgreylistTrustEntriesForCommonMailProvider', ['providers'], 1, []],
            [Mail::class, 'cpgreylistUntrustEntriesForCommonMailProvider', ['providers'], 1, []],
            [Mail::class, 'createCpgreylistTrustedHost', ['ips', 'comment'], 1, []],
            [Mail::class, 'deleteCpgreylistTrustedHost', ['ips'], 1, []],
            [Mail::class, 'saveCpgreylistConfig', ['childTimeoutSecs', 'initialBlockTimeMins', 'maxChildProcs',
                'mustTryTimeMins', 'purgeIntervalMins', 'recordExpTimeMins', 'spfBypass'], 0, []],
            [CpHulk::class, 'saveConfig', ['blockBruteForceWithFirewall', 'blockExcessiveBruteForceWithFirewall',
                'bruteForcePeriodMins', 'commandToRunOnBruteForce', 'commandToRunOnExcessiveBruteForce',
                'ipBasedProtection', 'ipBruteForcePeriodMins', 'lookbackPeriodMin', 'markAsBrute', 'maxFailures',
                'maxFailuresByip', 'notifyOnBrute', 'notifyOnRootLogin', 'notifyOnRootLoginForKnownNetblock',
                'skipEnabledCheck', 'usernameBasedProtection', 'usernameBasedProtectionForRoot',
                'usernameBasedProtectionLocalOrigin'], 0,
                ['commandToRunOnBruteForce', 'commandToRunOnExcessiveBruteForce']],
            ] as [$class, $method, $names, $required, $sensitive]
        ) {
            $reflection = new ReflectionMethod($class, $method);
            self::assertSame($required, $reflection->getNumberOfRequiredParameters());
            self::assertSame($names, array_map(static fn ($p): string => $p->getName(), $reflection->getParameters()));
            self::assertSame($sensitive, array_values(array_map(
                static fn ($p): string => $p->getName(),
                array_filter($reflection->getParameters(), static fn ($p): bool =>
                    $p->getAttributes(SensitiveParameter::class) !== []),
            )));
        }
    }

    /** @return iterable<string, array{string, Call, ?string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'status boolean flag' => ['cphulk_status', static fn (WhmClient $w): array => $w->cpHulk()->status(),
            '{"is_enabled":true}'];
        yield 'status quoted flag' => ['cphulk_status', static fn (WhmClient $w): array => $w->cpHulk()->status(),
            '{"is_enabled":"1"}'];
        yield 'status null root' => ['cphulk_status', static fn (WhmClient $w): array => $w->cpHulk()->status(),
            'null'];
        yield 'status list root' => ['cphulk_status', static fn (WhmClient $w): array => $w->cpHulk()->status(), '[1]'];
        yield 'status missing payload' => ['cphulk_status', static fn (WhmClient $w): array => $w->cpHulk()->status(),
            null];
        yield 'netblock bad map value' => ['cpgreylist_is_server_netblock_trusted',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistIsServerNetblockTrusted(),
            '{"ip_blocks":{"net":2}}'];
        yield 'provider trust quoted count' => ['cpgreylist_trust_entries_for_common_mail_provider',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistTrustEntriesForCommonMailProvider('cpanel'),
            '{"providers_trusted":{"cpanel":{"ips_trusted":"2"}}}'];
        yield 'untrust count remains integer' => ['cpgreylist_untrust_entries_for_common_mail_provider',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistUntrustEntriesForCommonMailProvider('cpanel'),
            '{"providers_untrusted":{"cpanel":{"ips_untrusted":"2"}}}'];
        yield 'provider nested strict flag' => ['cpgreylist_list_entries_for_common_mail_provider',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistListEntriesForCommonMailProvider('cpanel'),
            '{"providers":{"cpanel":{"ips":[{"is_trusted":"1"}]}}}'];
        yield 'history non-list' => ['get_cphulk_brutes', static fn (WhmClient $w): array => $w->cpHulk()->getBrutes(),
            '{"brutes":{}}'];
        yield 'history integer time rejects fraction' => ['get_cphulk_brutes',
            static fn (WhmClient $w): array => $w->cpHulk()->getBrutes(), '{"brutes":[{"timeleft":1.5}]}'];
        yield 'history number rejects fractional string' => ['get_cphulk_excessive_brutes',
            static fn (WhmClient $w): array => $w->cpHulk()->getExcessiveBrutes(),
            '{"excessive_brutes":[{"timeleft":"1.5"}]}'];
        yield 'failed addresses not strings' => ['delete_cphulk_record',
            static fn (WhmClient $w): array => $w->cpHulk()->deleteRecord('192.0.2.1', 'white'),
            '{"ips_failed":{"ip":1}}'];
        yield 'save warning integer' => ['save_cphulk_config',
            static fn (WhmClient $w): array => $w->cpHulk()->saveConfig(),
            '{"warning":0}'];
        yield 'deferred quoted count' => ['read_cpgreylist_deferred_entries',
            static fn (WhmClient $w): array => $w->mail()->readCpgreylistDeferredEntries(),
            '{"greylist_deferred_entries":[{"accepted_count":"0"}]}'];
        foreach (
            [
            'old failed record shape' => '{"providers_failed":{"cpanel":{"ips_untrusted":2}}}',
            'old success string shape' => '{"providers_untrusted":{"cpanel":"unknown"}}',
            'failed integer' => '{"providers_failed":{"cpanel":1}}',
            'failed null' => '{"providers_failed":{"cpanel":null}}',
            'count null' => '{"providers_untrusted":{"cpanel":{"ips_untrusted":null}}}',
            'count fraction' => '{"providers_untrusted":{"cpanel":{"ips_untrusted":1.5}}}',
            ] as $name => $payload
        ) {
            yield 'untrust ' . $name => ['cpgreylist_untrust_entries_for_common_mail_provider',
                static fn (WhmClient $w): array => $w->mail()->cpgreylistUntrustEntriesForCommonMailProvider('cpanel'),
                $payload];
        }
        foreach (
            [
            'old object shape' => '{"providers":{"cpanel":{"ips":{"provider_id":7}}}}',
            'null list' => '{"providers":{"cpanel":{"ips":null}}}',
            'scalar item' => '{"providers":{"cpanel":{"ips":["192.0.2.1"]}}}',
            'quoted provider id' => '{"providers":{"cpanel":{"ips":[{"provider_id":"7"}]}}}',
            'boolean flag' => '{"providers":{"cpanel":{"ips":[{"is_trusted":true}]}}}',
            'null address' => '{"providers":{"cpanel":{"ips":[{"host_ip":null}]}}}',
            'numeric time' => '{"providers":{"cpanel":{"ips":[{"create_time":1}]}}}',
            'failed nonstring' => '{"providers_failed":{"cpanel":{}}}',
            ] as $name => $payload
        ) {
            yield 'provider entries ' . $name => ['cpgreylist_list_entries_for_common_mail_provider',
                static fn (WhmClient $w): array => $w->mail()->cpgreylistListEntriesForCommonMailProvider('cpanel'),
                $payload];
        }
        foreach (
            [
            'failure integer' => '{"ips_failed":{"192.0.2.1":1}}',
            'failure null' => '{"ips_failed":{"192.0.2.1":null}}',
            'failure object' => '{"ips_failed":{"192.0.2.1":{"comment":"error"}}}',
            'failure list' => '{"ips_failed":["error"]}',
            'root comment null' => '{"comment":null}',
            'row comment null' => '{"ips_added":[{"comment":null}]}',
            'row id quoted' => '{"ips_added":[{"id":"1"}]}',
            ] as $name => $payload
        ) {
            yield 'create trusted host ' . $name => ['create_cpgreylist_trusted_host',
                static fn (WhmClient $w): array => $w->mail()->createCpgreylistTrustedHost('192.0.2.1'),
                $payload];
        }
    }

    /** @param Call $call */
    #[DataProvider('invalidPayloads')]
    public function testInvalidRootsAndKnownLeavesRejectWithoutRetry(
        string $operation,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $call($whm);
            self::fail('Malformed payload must throw.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertSame(1, $whm->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testOmittedOptionsEmptyObjectsAndIntegerConfigValuesRemainDistinct(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('create_cphulk_record', '{}')));
        self::assertSame([], $client->whm()->cpHulk()->createRecord('192.0.2.1', 'white'));
        self::assertSame('ip=192.0.2.1&list_name=white', self::body($http));
        $http->addResponse(new Response(200, [], self::envelope('set_cphulk_config_key', '{"cphulk_config":{}}')));
        self::assertSame(['cphulk_config' => []], $client->whm()->cpHulk()->setConfigKey('max_failures', 0));
        self::assertSame('key=max_failures&value=0', self::body($http));
        $http->addResponse(new Response(200, [], self::envelope('save_cphulk_config', '{}')));
        self::assertSame([], $client->whm()->cpHulk()->saveConfig());
        self::assertSame('', self::body($http));
    }

    /** @return iterable<string, Case> */
    public static function payloadRequests(): iterable
    {
        foreach (self::requests() as $name => $case) {
            if ($case[4] !== null) {
                yield $name => $case;
            }
        }
    }

    /** @param Call $call */
    #[DataProvider('payloadRequests')]
    public function testOptionalFieldsMayBeAbsentAndMalformedRootsStillFail(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
        self::assertSame([], $call($client->whm()));
        $http->addResponse(new Response(200, [], self::envelope($operation, '"wrong root"')));
        try {
            $call($client->whm());
            self::fail('A known object response must reject a scalar root.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertCount(2, $http->getRequests());
        }
    }

    public function testCompleteCphulkConfigurationRetainsEveryPinnedFieldWithoutCoercion(): void
    {
        [$client, $http] = self::client();
        $payload = '{"cphulk_config":{"block_brute_force_with_firewall":0,'
            . '"block_excessive_brute_force_with_firewall":1,"brute_force_period_mins":5,'
            . '"brute_force_period_sec":300,"can_temp_ban_firewall":0,"command_to_run_on_brute_force":"",'
            . '"command_to_run_on_excessive_brute_force":"echo fixture","ip_based_protection":1,'
            . '"ip_brute_force_period_mins":15,"ip_brute_force_period_sec":900,"is_enabled":1,'
            . '"lookback_period_min":360,"lookback_time":21600,"mark_as_brute":0,"max_failures":0,'
            . '"max_failures_byip":0,"notify_on_brute":0,"notify_on_root_login":1,'
            . '"notify_on_root_login_for_known_netblock":0,"username_based_protection":1,'
            . '"username_based_protection_for_root":0,"username_based_protection_local_origin":1}}';
        $http->addResponse(new Response(200, [], self::envelope('load_cphulk_config', $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->cpHulk()->loadConfig(),
        );
    }

    /** @return iterable<string, array{string, string, list<string>, array<mixed>}> */
    public static function invalidLists(): iterable
    {
        foreach (
            [
            ['cpHulk', 'createRecord', ['white']],
            ['cpHulk', 'deleteRecord', ['white']],
            ['cpHulk', 'flushLoginHistoryForIps', []],
            ['mail', 'cpgreylistListEntriesForCommonMailProvider', []],
            ['mail', 'cpgreylistTrustEntriesForCommonMailProvider', []],
            ['mail', 'cpgreylistUntrustEntriesForCommonMailProvider', []],
            ['mail', 'createCpgreylistTrustedHost', []],
            ['mail', 'deleteCpgreylistTrustedHost', []],
            ] as [$group, $method, $remaining]
        ) {
            foreach ([[], [1 => 'a'], ['a', false], [0], [null], [['a']]] as $index => $values) {
                yield $method . ' ' . $index => [$group, $method, $remaining, $values];
            }
        }
    }

    /**
     * @param list<string> $remaining
     * @param array<mixed> $values
     */
    #[DataProvider('invalidLists')]
    public function testNumberedInputsRejectInvalidListsBeforeDispatchAndClearMetadata(
        string $group,
        string $method,
        array $remaining,
        array $values,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('cphulk_status', '{"is_enabled":1}')));
        $whm->cpHulk()->status();
        self::assertNotNull($whm->responseMeta());
        $handle = $group === 'cpHulk' ? $whm->cpHulk() : $whm->mail();
        try {
            // Reflection deliberately exercises invalid callers outside generated PHPDoc list contracts.
            (new ReflectionMethod($handle, $method))->invoke($handle, $values, ...$remaining);
            self::fail('Invalid numbered input must fail before dispatch.');
        } catch (InvalidArgumentException) {
            self::assertNull($whm->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testProviderMutationsAndHostDeletionUseTheReviewedBaseThenSuffixFamily(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'cpgreylist_trust_entries_for_common_mail_provider',
            '{}',
        )));
        $client->whm()->mail()->cpgreylistTrustEntriesForCommonMailProvider(['cpanel', 'example']);
        self::assertSame('provider=cpanel&provider-1=example', self::body($http));
        $http->addResponse(new Response(200, [], self::envelope(
            'cpgreylist_untrust_entries_for_common_mail_provider',
            '{}',
        )));
        $client->whm()->mail()->cpgreylistUntrustEntriesForCommonMailProvider(['cpanel', 'example']);
        self::assertSame('provider=cpanel&provider-1=example', self::body($http));
        $http->addResponse(new Response(200, [], self::envelope('delete_cpgreylist_trusted_host', '{}')));
        $client->whm()->mail()->deleteCpgreylistTrustedHost(['192.0.2.1', '2001:db8::/32']);
        self::assertSame('ip=192.0.2.1&ip-1=2001%3Adb8%3A%3A%2F32', self::body($http));
        $http->addResponse(new Response(200, [], self::envelope('delete_cphulk_record', '{}')));
        $client->whm()->cpHulk()->deleteRecord(['192.0.2.1', '192.0.2.2-192.0.2.3'], 'white');
        self::assertSame('ip=192.0.2.1&ip-1=192.0.2.2-192.0.2.3&list_name=white', self::body($http));
        $http->addResponse(new Response(200, [], self::envelope('set_cphulk_config_key', '{}')));
        $client->whm()->cpHulk()->setConfigKey('country_whitelist', 'RS,US');
        self::assertSame('key=country_whitelist&value=RS%2CUS', self::body($http));
    }

    /** @return iterable<string, array{string, Call, string, string}> */
    public static function historyContracts(): iterable
    {
        $brute = '{"exptime":"later","ip":"192.0.2.1","logintime":"earlier",'
            . '"notes":"blocked","timeleft":__TIMELEFT__}';
        $login = '{"authservice":"system","exptime":"later","ip":"2001:db8::1",'
            . '"logintime":"earlier","service":"sshd","timeleft":__TIMELEFT__,"user":"fixture"}';
        yield 'brutes' => ['get_cphulk_brutes',
            static fn (WhmClient $w): array => $w->cpHulk()->getBrutes(), 'brutes', $brute];
        yield 'excessive brutes' => ['get_cphulk_excessive_brutes',
            static fn (WhmClient $w): array => $w->cpHulk()->getExcessiveBrutes(), 'excessive_brutes', $brute];
        yield 'failed logins' => ['get_cphulk_failed_logins',
            static fn (WhmClient $w): array => $w->cpHulk()->getFailedLogins(), 'failed_logins', $login];
        yield 'user brutes' => ['get_cphulk_user_brutes',
            static fn (WhmClient $w): array => $w->cpHulk()->getUserBrutes(), 'user_brutes', $login];
    }

    /** @return iterable<string, array{string, Call, string, string, string, int}> */
    public static function canonicalHistoryTimes(): iterable
    {
        foreach (self::historyContracts() as $name => [$operation, $call, $key, $row]) {
            foreach ([0, 1, -1, 60, PHP_INT_MIN, PHP_INT_MAX] as $value) {
                yield $name . ' native ' . $value => [$operation, $call, $key, $row, (string) $value, $value];
                yield $name . ' quoted ' . $value => [$operation, $call, $key, $row, '"' . $value . '"', $value];
            }
        }
    }

    /** @param Call $call */
    #[DataProvider('canonicalHistoryTimes')]
    public function testHistoryTimesNormalizeBoundedCanonicalStringsAndPreserveEveryKnownField(
        string $operation,
        Closure $call,
        string $key,
        string $row,
        string $wire,
        int $expected,
    ): void {
        [$client, $http] = self::client();
        $payload = '{"' . $key . '":[' . str_replace('__TIMELEFT__', $wire, $row) . ',{}]}';
        $expectedPayload = '{"' . $key . '":[' . str_replace('__TIMELEFT__', (string) $expected, $row) . ',{}]}';
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));

        self::assertSame(json_decode($expectedPayload, true, 512, JSON_THROW_ON_ERROR), $call($client->whm()));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/json-api/' . $operation, $request->getUri()->getPath());
        self::assertSame('api.version=1', $request->getUri()->getQuery());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, Call, string}> */
    public static function invalidHistoryTimes(): iterable
    {
        foreach (self::historyContracts() as $name => [$operation, $call, $key, $row]) {
            foreach (
                [
                '""', '"00"', '"01"', '"-01"', '"-0"', '"+1"', '" 1"', '"1 "', '"1\\n"',
                '"1.0"', '"1.5"', '"1e3"', '"9223372036854775808"', '"-9223372036854775809"',
                '"999999999999999999999999999999999999"', 'null', 'true', 'false', '[]', '{}',
                ] as $wire
            ) {
                yield $name . ' ' . $wire => [$operation, $call,
                    '{"' . $key . '":[' . str_replace('__TIMELEFT__', $wire, $row) . ']}'];
            }
            if ($key !== 'excessive_brutes') {
                foreach (['0.0', '1.5', '1e30'] as $wire) {
                    yield $name . ' native float ' . $wire => [$operation, $call,
                        '{"' . $key . '":[' . str_replace('__TIMELEFT__', $wire, $row) . ']}'];
                }
            }
            yield $name . ' known sibling stays string' => [$operation, $call,
                '{"' . $key . '":[{"ip":1,"timeleft":0}]}'];
        }
    }

    /** @param Call $call */
    #[DataProvider('invalidHistoryTimes')]
    public function testHistoryStringCompatibilityRejectsMalformedAndOutOfRangeValuesWithoutRetry(
        string $operation,
        Closure $call,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $call($client->whm());
            self::fail('History compatibility must remain bounded and preserve sibling types.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, float}> */
    public static function nativeExcessiveTimes(): iterable
    {
        yield 'floating zero' => ['0.0', 0.0];
        yield 'fraction' => ['1.5', 1.5];
        yield 'negative fraction' => ['-0.5', -0.5];
        yield 'outside integer bounds' => ['1e30', 1e30];
    }

    #[DataProvider('nativeExcessiveTimes')]
    public function testExcessiveHistoryRetainsPinnedNativeNumberSupport(string $wire, float $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'get_cphulk_excessive_brutes',
            '{"excessive_brutes":[{"timeleft":' . $wire . '}]}',
        )));
        self::assertSame(
            ['excessive_brutes' => [['timeleft' => $expected]]],
            $client->whm()->cpHulk()->getExcessiveBrutes(),
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, int}> */
    public static function observedGreylistingEnabledFlags(): iterable
    {
        yield 'quoted zero' => ['"0"', 0];
        yield 'quoted one' => ['"1"', 1];
        yield 'native zero' => ['0', 0];
        yield 'native one' => ['1', 1];
    }

    #[DataProvider('observedGreylistingEnabledFlags')]
    public function testObservedGreylistingEnabledFlagNormalizesOnlyExactQuotedFlags(string $wire, int $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'load_cpgreylist_config',
            '{"cpgreylist_config":{"is_enabled":' . $wire . ',"is_exim_enabled":0,"spf_bypass":1}}',
        )));
        self::assertSame([
            'cpgreylist_config' => ['is_enabled' => $expected, 'is_exim_enabled' => 0, 'spf_bypass' => 1],
        ], $client->whm()->mail()->loadCpgreylistConfig());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('observedGreylistingEnabledFlags')]
    public function testSaveGreylistingConfigRetainsLoadFamilyFlagCompatibility(string $wire, int $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'save_cpgreylist_config',
            '{"cpgreylist_config":{"child_timeout_secs":5,"initial_block_time_mins":10,"is_enabled":' . $wire
                . ',"is_exim_enabled":1,"max_child_procs":5,"must_try_time_mins":240,"purge_interval_mins":60,'
                . '"record_exp_time_mins":4320,"spf_bypass":0}}',
        )));
        self::assertSame(['cpgreylist_config' => [
            'child_timeout_secs' => 5,
            'initial_block_time_mins' => 10,
            'is_enabled' => $expected,
            'is_exim_enabled' => 1,
            'max_child_procs' => 5,
            'must_try_time_mins' => 240,
            'purge_interval_mins' => 60,
            'record_exp_time_mins' => 4320,
            'spf_bypass' => 0,
        ]], $client->whm()->mail()->saveCpgreylistConfig(spfBypass: false));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('api.version=1', $request->getUri()->getQuery());
        self::assertSame('spf_bypass=0', (string) $request->getBody());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidObservedGreylistingEnabledFlags(): iterable
    {
        foreach (
            [
            '"00"', '"01"', '"0.0"', '"1.0"', '"-0"', '"+1"', '" 1"', '"1 "', '""', '"2"',
            '"1e0"', 'true', 'false', 'null', '0.0', '1.0', '2', '-1', '[]', '{}',
            ] as $wire
        ) {
            yield $wire => [$wire];
        }
    }

    #[DataProvider('invalidObservedGreylistingEnabledFlags')]
    public function testObservedGreylistingEnabledFlagRejectsNoncanonicalStringsAndOtherTypes(string $wire): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'load_cpgreylist_config',
            '{"cpgreylist_config":{"is_enabled":' . $wire . '}}',
        )));
        try {
            $client->whm()->mail()->loadCpgreylistConfig();
            self::fail('Only native flags and exact quoted zero/one may be accepted.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('invalidObservedGreylistingEnabledFlags')]
    public function testSaveGreylistingConfigRejectsOtherEnabledValuesWithoutRetry(string $wire): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'save_cpgreylist_config',
            '{"cpgreylist_config":{"is_enabled":' . $wire . '}}',
        )));
        try {
            $client->whm()->mail()->saveCpgreylistConfig(spfBypass: false);
            self::fail('The save response must share only exact enabled-flag compatibility.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertSame('spf_bypass=0', self::body($http));
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, Call, string}> */
    public static function observedGreylistingFlagStrictSiblings(): iterable
    {
        yield 'load Exim flag' => ['load_cpgreylist_config',
            static fn (WhmClient $w): array => $w->mail()->loadCpgreylistConfig(),
            '{"cpgreylist_config":{"is_exim_enabled":"0"}}'];
        yield 'load SPF flag' => ['load_cpgreylist_config',
            static fn (WhmClient $w): array => $w->mail()->loadCpgreylistConfig(),
            '{"cpgreylist_config":{"spf_bypass":"1"}}'];
        yield 'load integer timeout' => ['load_cpgreylist_config',
            static fn (WhmClient $w): array => $w->mail()->loadCpgreylistConfig(),
            '{"cpgreylist_config":{"child_timeout_secs":"5"}}'];
        yield 'save Exim flag' => ['save_cpgreylist_config',
            static fn (WhmClient $w): array => $w->mail()->saveCpgreylistConfig(spfBypass: false),
            '{"cpgreylist_config":{"is_exim_enabled":"0"}}'];
        yield 'save SPF flag' => ['save_cpgreylist_config',
            static fn (WhmClient $w): array => $w->mail()->saveCpgreylistConfig(spfBypass: false),
            '{"cpgreylist_config":{"spf_bypass":"0"}}'];
        yield 'save integer timeout' => ['save_cpgreylist_config',
            static fn (WhmClient $w): array => $w->mail()->saveCpgreylistConfig(),
            '{"cpgreylist_config":{"child_timeout_secs":"5"}}'];
        yield 'Greylisting status' => ['cpgreylist_status',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistStatus(), '{"is_enabled":"1"}'];
        yield 'cPHulk load enabled flag' => ['load_cphulk_config',
            static fn (WhmClient $w): array => $w->cpHulk()->loadConfig(),
            '{"cphulk_config":{"is_enabled":"1"}}'];
        yield 'netblock trusted flag' => ['cpgreylist_is_server_netblock_trusted',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistIsServerNetblockTrusted(),
            '{"ip_blocks":{"192.0.2.0/24":"0"}}'];
        yield 'provider configuration flag' => ['cpgreylist_load_common_mail_providers_config',
            static fn (WhmClient $w): array => $w->mail()->cpgreylistLoadCommonMailProvidersConfig(),
            '{"autotrust_new_common_mail_providers":"1"}'];
    }

    /** @param Call $call */
    #[DataProvider('observedGreylistingFlagStrictSiblings')]
    public function testObservedGreylistingEnabledCorrectionPreservesStrictSiblingFields(
        string $operation,
        Closure $call,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $call($client->whm());
            self::fail('The observed enabled-flag correction must not broaden sibling fields.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /**
     * Read the mutable transport request state.
     *
     * @phpstan-impure
     */
    private static function body(MockClient $http): string
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return (string) $request->getBody();
    }

    private static function envelope(string $operation, ?string $payload): string
    {
        return '{"metadata":{"command":"' . $operation . '","result":1,"reason":"OK","version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
