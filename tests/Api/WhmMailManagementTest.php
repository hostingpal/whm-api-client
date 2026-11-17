<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
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
 * Literal requests and response fixtures reviewed independently against the pinned WHM Mail source.
 *
 * @phpstan-type Call Closure(WhmClient): mixed
 * @phpstan-type Case array{string, string, string, Call, string, ?string}
 */
final class WhmMailManagementTest extends TestCase
{
    /** @param Call $call */
    #[DataProvider('requests')]
    public function testExactRequestsAndPinnedPayloads(
        string $operation,
        string $publicMethod,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        self::assertTrue(method_exists($whm->mail(), $publicMethod), 'Missing Mail::' . $publicMethod);
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
        yield 'apply matching DMARC policies' => ['apply_dmarc', 'applyDmarc', 'POST',
            static fn (WhmClient $w): array => $w->mail()->applyDmarc(
                ['v=DMARC1; p=none;', 'v=DMARC1; p=reject;'],
                ['a.example.test', 'b.example.test'],
            ),
            'policy=v%3DDMARC1%3B%20p%3Dnone%3B&policy=v%3DDMARC1%3B%20p%3Dreject%3B'
                . '&domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","msg":"applied","status":1},'
                . '{"domain":"b.example.test","msg":"failed","status":0}]}'];
        yield 'block countries' => ['block_incoming_email_from_country', 'blockIncomingEmailFromCountry', 'POST',
            static fn (WhmClient $w): array => $w->mail()->blockIncomingEmailFromCountry(['RS', 'US']),
            'country_code=RS&country_code=US', '{"updated":0}'];
        yield 'block wildcard domains' => ['block_incoming_email_from_domain', 'blockIncomingEmailFromDomain', 'POST',
            static fn (WhmClient $w): array => $w->mail()->blockIncomingEmailFromDomain(
                ['*.example.test', 'other.test'],
            ),
            'domain=%2A.example.test&domain=other.test', '{"updated":1}'];
        yield 'disable DKIM per-domain failure' => ['disable_dkim', 'disableDkim', 'POST',
            static fn (WhmClient $w): array => $w->mail()->disableDkim(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","msg":"removed","status":1},'
                . '{"domain":"b.example.test","msg":"failed","status":0}]}'];
        yield 'delivery records all pinned fields' => ['emailtrack_search', 'emailtrackSearch', 'GET',
            static fn (WhmClient $w): array => $w->mail()->emailtrackSearch(
                defer: false,
                deliveryType: 'remote',
                failure: true,
                inProgress: false,
                maxResultsByType: 0,
                success: true,
            ),
            'defer=0&deliverytype=remote&failure=1&inprogress=0&max_results_by_type=0&success=1',
            '{"records":[{"actiontime":"2026-10-31T00:00:00Z","actionunixtime":1793404800,'
                . '"deliveredto":null,"deliverydomain":null,"deliveryuser":null,"domain":"example.test",'
                . '"host":null,"ip":null,"message":"deferred","msgid":"fixture-id",'
                . '"recipient":"recipient@example.test","router":"router","sender":"sender@example.test",'
                . '"senderauth":"localuser","senderhost":"host.example.test","senderip":"192.0.2.1",'
                . '"sendunixtime":1793404800,"size":5,"spamscore":0,"transport":null,'
                . '"transport_is_remote":1,"type":"defer","user":"fixture"}]}'];
        yield 'delivery statistics aliases and zero' => ['emailtrack_stats', 'emailtrackStats', 'GET',
            static fn (WhmClient $w): array => $w->mail()->emailtrackStats(
                deliveryType: 'remote-or-faildefer',
                endDate: 2,
                endTime: 3,
                noSize: false,
                noSuccess: true,
                startDate: 0,
                startTime: 1,
                user: 'fixture',
            ),
            'deliverytype=remote-or-faildefer&enddate=2&endtime=3&nosize=0&nosuccess=1'
                . '&startdate=0&starttime=1&user=fixture',
            '{"records":[{"DEFERCOUNT":1,"DEFERFAILCOUNT":2,"FAILCOUNT":3,"INPROGRESSCOUNT":4,'
                . '"SENDCOUNT":5,"SUCCESSCOUNT":0,"TOTALSIZE":6}]}'];
        yield 'sender statistics' => ['emailtrack_user_stats', 'emailtrackUserStats', 'GET',
            static fn (WhmClient $w): array => $w->mail()->emailtrackUserStats(
                deliveryType: 'local',
                endTime: 2,
                sender: 'sender@example.test',
                startTime: 0,
            ),
            'deliverytype=local&endtime=2&sender=sender%40example.test&starttime=0',
            '{"records":[{"DEFERCOUNT":1,"DEFERFAILCOUNT":2,"DOMAIN":"example.test","FAILCOUNT":3,'
                . '"OWNER":"root","PRIMARY_DOMAIN":"example.test","REACHED_MAXDEFERFAIL":0,'
                . '"REACHED_MAXEMAILS":1,"SENDCOUNT":4,"SUCCESSCOUNT":5,"TOTALSIZE":6,"USER":"fixture"}]}'];
        yield 'enable DKIM' => ['enable_dkim', 'enableDkim', 'POST',
            static fn (WhmClient $w): array => $w->mail()->enableDkim(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","msg":"installed","status":1}]}'];
        yield 'mail SNI always enabled' => ['enable_mail_sni', 'enableMailSni', 'POST',
            static fn (WhmClient $w): array => $w->mail()->enableMailSni('example.test'), 'domain=example.test',
            '{"failed_domains":{"unowned.test":"not owned"},"updated_domains":{}}'];
        yield 'mail SNI numbered domains' => ['enable_mail_sni', 'enableMailSni', 'POST',
            static fn (WhmClient $w): array => $w->mail()->enableMailSni(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain-1=b.example.test',
            '{"failed_domains":{"b.example.test":"not owned"},"updated_domains":{}}'];
        yield 'ensure DKIM keys may replace keys' => ['ensure_dkim_keys_exist', 'ensureDkimKeysExist', 'POST',
            static fn (WhmClient $w): array => $w->mail()->ensureDkimKeysExist(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","msg":"created","status":1}]}'];
        yield 'Exim check attempts repair' => ['exim_configuration_check', 'eximConfigurationCheck', 'POST',
            static fn (WhmClient $w): ?array => $w->mail()->eximConfigurationCheck(), '',
            '{"message":"repair failed"}'];
        yield 'expunge mailbox name' => ['expunge_mailbox_messages', 'expungeMailboxMessages', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->expungeMailboxMessages('fixture@example.test', 'INBOX', 'subject "A & B"');
            }, 'account=fixture%40example.test&mailbox=INBOX&query=subject%20%22A%20%26%20B%22', null];
        yield 'expunge mailbox GUID' => ['expunge_messages_for_mailbox_guid', 'expungeMessagesForMailboxGuid', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->expungeMessagesForMailboxGuid('fixture@example.test', 'fixture-guid', 'savedbefore 52w');
            }, 'account=fixture%40example.test&mailbox_guid=fixture-guid&query=savedbefore%2052w', null];
        yield 'private DKIM export' => ['fetch_dkim_private_keys', 'fetchDkimPrivateKeys', 'POST',
            static fn (WhmClient $w): array => $w->mail()->fetchDkimPrivateKeys(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","pem":"synthetic-private-material"}]}'];
        yield 'queue fields' => ['fetch_mail_queue', 'fetchMailQueue', 'GET',
            static fn (WhmClient $w): array => $w->mail()->fetchMailQueue(), '',
            '{"records":[{"frozen":1,"msgid":"fixture-id","recipients":["recipient@example.test"],'
                . '"sender":"sender@example.test","size":0,"time":1793404800,"user":null}]}'];
        yield 'mobile configuration profile' => ['generate_mobileconfig', 'generateMobileconfig', 'GET',
            static fn (WhmClient $w): array => $w->mail()->generateMobileconfig('fixture', false, 'email,caldav'),
            'account=fixture&use_ssl=0&selected_account_services=email%2Ccaldav', '{"payload":"c3ludGhldGlj"}'];
        yield 'default DMARC record' => ['get_default_dmarc_record', 'getDefaultDmarcRecord', 'GET',
            static fn (WhmClient $w): array => $w->mail()->getDefaultDmarcRecord(), '',
            '{"payload":{"record":"v=DMARC1; p=none;"}}'];
        yield 'mailbox names remain dynamic keys' => ['get_mailbox_status', 'getMailboxStatus', 'GET',
            static fn (WhmClient $w): array => $w->mail()->getMailboxStatus('_mainaccount@example.test'),
            'account=_mainaccount%40example.test',
            '{"INBOX":{"guid":"fixture-guid","messages":0,"vsize":0},"Archive.2026":{"messages":1}}'];
        yield 'mailbox list' => ['get_mailbox_status_list', 'getMailboxStatusList', 'GET',
            static fn (WhmClient $w): array => $w->mail()->getMailboxStatusList('fixture@example.test'),
            'account=fixture%40example.test',
            '{"mailboxes":[{"guid":"fixture-guid","mailbox":"INBOX","messages":0,"vsize":0}]}'];
        yield 'unique recipients per sender' => ['get_unique_recipient_count_per_sender_for_user',
            'getUniqueRecipientCountPerSenderForUser', 'GET',
            static fn (WhmClient $w): array => $w->mail()->getUniqueRecipientCountPerSenderForUser('fixture', 0, 2),
            'user=fixture&start_time=0&end_time=2',
            '{"payload":[{"sender":"fixture@example.test","unique_recipient_count":1}]}'];
        yield 'unique pairs per user' => ['get_unique_sender_recipient_count_per_user',
            'getUniqueSenderRecipientCountPerUser', 'GET',
            static fn (WhmClient $w): array => $w->mail()->getUniqueSenderRecipientCountPerUser(0, 2),
            'start_time=0&end_time=2', '{"payload":[{"unique_sender_recipient_count":1,"user":"fixture"}]}'];
        yield 'system forward destinations' => ['get_user_email_forward_destination', 'getUserEmailForwardDestination',
            'GET', static fn (WhmClient $w): array => $w->mail()->getUserEmailForwardDestination('fixture'),
            'user=fixture', '{"forward_to":["first@example.test","second@example.test"]}'];
        yield 'hold outgoing email' => ['hold_outgoing_email', 'holdOutgoingEmail', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->holdOutgoingEmail('fixture');
            }, 'user=fixture', null];
        yield 'paired private key install encoded once' => [
            'install_dkim_private_keys', 'installDkimPrivateKeys', 'POST',
            static fn (WhmClient $w): array => $w->mail()->installDkimPrivateKeys(
                ['a.example.test', 'b.example.test'],
                ["synthetic+key\nA&B", 'second=key%'],
            ),
            'domain=a.example.test&domain-1=b.example.test&key=synthetic%2Bkey%0AA%26B&key-1=second%3Dkey%25',
            '{"payload":[{"domain":"a.example.test","msg":"installed","status":1},'
                . '{"domain":"b.example.test","msg":"failed","status":0}]}'];
        yield 'single SPF install encoded once' => ['install_spf_records', 'installSpfRecords', 'POST',
            static fn (WhmClient $w): array => $w->mail()->installSpfRecords(
                'a.example.test',
                'v=spf1 +ip4:192.0.2.1 -all',
            ),
            'domain=a.example.test&record=v%3Dspf1%20%2Bip4%3A192.0.2.1%20-all',
            '{"payload":[{"domain":"a.example.test","msg":"installed","status":1}]}'];
        yield 'SNI support flag' => ['is_sni_supported', 'isSniSupported', 'GET',
            static fn (WhmClient $w): array => $w->mail()->isSniSupported(), '', '{"sni":0}'];
        yield 'blocked countries' => [
            'list_blocked_incoming_email_countries', 'listBlockedIncomingEmailCountries', 'GET',
            static fn (WhmClient $w): array => $w->mail()->listBlockedIncomingEmailCountries(), '',
            '{"countries":[{"country_code":"RS"},{}]}'];
        yield 'blocked domains' => ['list_blocked_incoming_email_domains', 'listBlockedIncomingEmailDomains', 'GET',
            static fn (WhmClient $w): array => $w->mail()->listBlockedIncomingEmailDomains(), '',
            '{"domains":[{"domain":"*.example.test"},{}]}'];
        yield 'account mailboxes' => ['list_pops_for', 'listPopsFor', 'GET',
            static fn (WhmClient $w): array => $w->mail()->listPopsFor('fixture'), 'user=fixture',
            '{"pops":["fixture@example.test"]}'];
        yield 'domain mail SNI flag' => ['mail_sni_status', 'mailSniStatus', 'GET',
            static fn (WhmClient $w): array => $w->mail()->mailSniStatus('example.test'), 'domain=example.test',
            '{"enabled":1}'];
        yield 'normalize account configuration' => ['normalize_user_email_configuration',
            'normalizeUserEmailConfiguration', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->normalizeUserEmailConfiguration('fixture');
            }, 'username=fixture', null];
        yield 'rebuild SNI without Dovecot reload' => ['rebuild_mail_sni_config', 'rebuildMailSniConfig', 'POST',
            static fn (WhmClient $w): array => $w->mail()->rebuildMailSniConfig(false), 'reload_dovecot=0',
            '{"configs_built":["synthetic-config"],"success":0}'];
        yield 'release outgoing email' => ['release_outgoing_email', 'releaseOutgoingEmail', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->releaseOutgoingEmail('fixture');
            }, 'user=fixture', null];
        yield 'remove DMARC' => ['remove_dmarc', 'removeDmarc', 'POST',
            static fn (WhmClient $w): array => $w->mail()->removeDmarc(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","msg":"removed","status":0}]}'];
        yield 'remove failed Exim dry run' => ['remove_in_progress_exim_config_edit', 'removeInProgressEximConfigEdit',
            'POST', static function (WhmClient $w): void {
                $w->mail()->removeInProgressEximConfigEdit();
            }, '', null];
        yield 'spamd zero timeouts' => ['save_spamd_config', 'saveSpamdConfig', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->saveSpamdConfig(
                    allowedIps: '127.0.0.1,192.0.2.1',
                    maxChildren: 5,
                    maxConnPerChild: 200,
                    pidFile: '/var/run/spamd.pid',
                    timeoutChild: 0,
                    timeoutTcp: 0,
                );
            }, 'allowedips=127.0.0.1%2C192.0.2.1&maxchildren=5&maxconnperchild=200'
                . '&pidfile=%2Fvar%2Frun%2Fspamd.pid&timeoutchild=0&timeouttcp=0', null];
        yield 'reset custom default DMARC' => ['set_default_dmarc_record', 'setDefaultDmarcRecord', 'POST',
            static fn (WhmClient $w): array => $w->mail()->setDefaultDmarcRecord(''), 'record=',
            '{"payload":{"success":1}}'];
        yield 'paired manual MX redirects' => ['set_manual_mx_redirects', 'setManualMxRedirects', 'POST',
            static fn (WhmClient $w): array => $w->mail()->setManualMxRedirects(
                ['a.example.test', 'b.example.test'],
                ['192.0.2.1', '2001:db8::1'],
            ),
            'domain=a.example.test&domain-1=b.example.test&mx_host=192.0.2.1&mx_host-1=2001%3Adb8%3A%3A1',
            '{"payload":{"a.example.test":null,"b.example.test":"failed"}}'];
        yield 'system forward comma list' => ['set_user_email_forward_destination', 'setUserEmailForwardDestination',
            'POST', static function (WhmClient $w): void {
                $w->mail()->setUserEmailForwardDestination('fixture', 'first@example.test,second@example.test');
            }, 'user=fixture&forward_to=first%40example.test%2Csecond%40example.test', null];
        yield 'suspend outgoing email' => ['suspend_outgoing_email', 'suspendOutgoingEmail', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->suspendOutgoingEmail('fixture');
            }, 'user=fixture', null];
        yield 'terminate every account mailbox session' => ['terminate_cpuser_mailbox_sessions',
            'terminateCpuserMailboxSessions', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->terminateCpuserMailboxSessions('fixture');
            }, 'username=fixture', null];
        yield 'unblock countries' => ['unblock_incoming_email_from_country', 'unblockIncomingEmailFromCountry', 'POST',
            static fn (WhmClient $w): array => $w->mail()->unblockIncomingEmailFromCountry(['RS', 'US']),
            'country_code=RS&country_code=US', '{"updated":0}'];
        yield 'unblock wildcard domains' => ['unblock_incoming_email_from_domain', 'unblockIncomingEmailFromDomain',
            'POST', static fn (WhmClient $w): array => $w->mail()->unblockIncomingEmailFromDomain(['*.example.test']),
            'domain=%2A.example.test', '{"updated":0}'];
        yield 'remove manual MX redirects' => ['unset_manual_mx_redirects', 'unsetManualMxRedirects', 'POST',
            static fn (WhmClient $w): array => $w->mail()->unsetManualMxRedirects(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain-1=b.example.test',
            '{"payload":{"a.example.test":null,"b.example.test":"failed"}}'];
        yield 'unsuspend outgoing email' => ['unsuspend_outgoing_email', 'unsuspendOutgoingEmail', 'POST',
            static function (WhmClient $w): void {
                $w->mail()->unsuspendOutgoingEmail('fixture');
            }, 'user=fixture', null];
        yield 'DKIM validation may update validity cache' => ['validate_current_dkims', 'validateCurrentDkims', 'POST',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentDkims(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"default._domainkey.a.example.test","error":"synthetic lookup failure",'
                . '"expected":"synthetic-public-key","records":[{"current":"synthetic-public-key","state":"VALID"}],'
                . '"state":"ERROR","validity_cache_update":"none"}]}'];
        yield 'DMARC validation' => ['validate_current_dmarcs', 'validateCurrentDmarcs', 'GET',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentDmarcs(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","error":"","record":"v=DMARC1; p=none;",'
                . '"state":"VALID","subdomain":"_dmarc.a.example.test","suggested":"v=DMARC1; p=none;"}]}'];
        yield 'installed Exim validation' => ['validate_current_installed_exim_config',
            'validateCurrentInstalledEximConfig', 'GET',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentInstalledEximConfig(), '',
            '{"message":"<p>synthetic status</p>"}'];
        yield 'PTR and forward DNS validation' => ['validate_current_ptrs', 'validateCurrentPtrs', 'GET',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentPtrs(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"arpa_domain":"1.2.0.192.in-addr.arpa","domain":"a.example.test","error":"",'
                . '"helo":"mail.a.example.test","ip_address":"192.0.2.1","ip_version":4,'
                . '"nameservers":["ns.example.test"],"ptr_records":[{"domain":"mail.a.example.test",'
                . '"forward_records":["192.0.2.1"],"state":"VALID"}],"state":"VALID"}]}'];
        yield 'SPF validation' => ['validate_current_spfs', 'validateCurrentSpfs', 'GET',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentSpfs('example.test'), 'domain=example.test',
            '{"payload":[{"domain":"example.test","error":"","expected":"v=spf1 -all",'
                . '"ip_address":"2001:db8::1","ip_version":6,"records":[{"current":"v=spf1 -all",'
                . '"reason":"synthetic","state":"FAIL"}],"state":"VALID"}]}'];
        yield 'SPF validation repeated domains' => ['validate_current_spfs', 'validateCurrentSpfs', 'GET',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentSpfs(['a.example.test', 'b.example.test']),
            'domain=a.example.test&domain=b.example.test',
            '{"payload":[{"domain":"a.example.test","records":[],"state":"MISSING"},'
                . '{"domain":"b.example.test","error":"synthetic lookup failure","state":"ERROR"}]}'];
        yield 'private Exim syntax text uses POST' => ['validate_exim_configuration_syntax',
            'validateEximConfigurationSyntax', 'POST',
            static fn (WhmClient $w): array => $w->mail()->validateEximConfigurationSyntax("begin acl\nA&B=+%", 'acl'),
            'cfg_text=begin%20acl%0AA%26B%3D%2B%25&section=acl',
            '{"broken_cfg_html":"<p>synthetic</p>","broken_cfg_text":"synthetic",'
                . '"error_line":1,"error_msg":"synthetic syntax error"}'];
    }

    /**
     * @param Call $call
     */
    #[DataProvider('requests')]
    public function testEveryOperationChecksTheApiEnvelopeBeforeItsPayload(
        string $operation,
        string $publicMethod,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        self::assertTrue(method_exists($client->whm()->mail(), $publicMethod));
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"synthetic denial"}}'));
        try {
            $call($client->whm());
            self::fail('API failure must throw even for void operations.');
        } catch (ApiErrorException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, Call, ?string}> */
    public static function invalidRoots(): iterable
    {
        foreach (self::requests() as $label => [$operation, $publicMethod, $method, $call, $parameters, $payload]) {
            if ($payload === null) {
                continue;
            }
            foreach (['null', 'true', '42', '"wrong"', '[{}]', null] as $index => $invalid) {
                if ($operation === 'exim_configuration_check' && ($invalid === null || $invalid === 'null')) {
                    continue;
                }
                yield $label . ' root ' . $index => [$operation, $call, $invalid];
            }
        }
    }

    /** @param Call $call */
    #[DataProvider('invalidRoots')]
    public function testMalformedOrMissingPayloadsKeepMetadata(string $operation, Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $call($client->whm());
            self::fail('The required object payload must reject malformed or absent data.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, Call, string}> */
    public static function invalidLeaves(): iterable
    {
        yield 'DMARC quoted status' => ['apply_dmarc',
            static fn (WhmClient $w): array => $w->mail()->applyDmarc('v=DMARC1; p=none;', 'example.test'),
            '{"payload":[{"status":"1"}]}'];
        yield 'country native flag' => ['block_incoming_email_from_country',
            static fn (WhmClient $w): array => $w->mail()->blockIncomingEmailFromCountry('RS'), '{"updated":true}'];
        yield 'domain native flag' => ['block_incoming_email_from_domain',
            static fn (WhmClient $w): array => $w->mail()->blockIncomingEmailFromDomain('example.test'),
            '{"updated":2}'];
        yield 'DKIM missing nullable state' => ['disable_dkim',
            static fn (WhmClient $w): array => $w->mail()->disableDkim('example.test'), '{"payload":[{"msg":null}]}'];
        yield 'delivery time strict' => ['emailtrack_search',
            static fn (WhmClient $w): array => $w->mail()->emailtrackSearch(), '{"records":[{"actionunixtime":"1"}]}'];
        yield 'delivery nested list' => ['emailtrack_search',
            static fn (WhmClient $w): array => $w->mail()->emailtrackSearch(), '{"records":[[1]]}'];
        yield 'delivery count strict' => ['emailtrack_stats',
            static fn (WhmClient $w): array => $w->mail()->emailtrackStats(), '{"records":[{"SENDCOUNT":1.5}]}'];
        yield 'delivery account flags strict' => ['emailtrack_user_stats',
            static fn (WhmClient $w): array => $w->mail()->emailtrackUserStats(),
            '{"records":[{"REACHED_MAXEMAILS":"1"}]}'];
        yield 'mail SNI failure map' => ['enable_mail_sni',
            static fn (WhmClient $w): array => $w->mail()->enableMailSni('example.test'),
            '{"failed_domains":{"example.test":null}}'];
        yield 'DKIM ensure row' => ['ensure_dkim_keys_exist',
            static fn (WhmClient $w): array => $w->mail()->ensureDkimKeysExist('example.test'),
            '{"payload":[{"status":false}]}'];
        yield 'Exim repair message' => ['exim_configuration_check',
            static fn (WhmClient $w): ?array => $w->mail()->eximConfigurationCheck(), '{"message":null}'];
        yield 'private PEM type' => ['fetch_dkim_private_keys',
            static fn (WhmClient $w): array => $w->mail()->fetchDkimPrivateKeys('example.test'),
            '{"payload":[{"pem":0}]}'];
        yield 'queue recipient list' => ['fetch_mail_queue',
            static fn (WhmClient $w): array => $w->mail()->fetchMailQueue(), '{"records":[{"recipients":[null]}]}'];
        yield 'queue nullable username remains string' => ['fetch_mail_queue',
            static fn (WhmClient $w): array => $w->mail()->fetchMailQueue(), '{"records":[{"user":false}]}'];
        yield 'mobile profile type' => ['generate_mobileconfig',
            static fn (WhmClient $w): array => $w->mail()->generateMobileconfig('fixture', true), '{"payload":{}}'];
        yield 'default DMARC nested object' => ['get_default_dmarc_record',
            static fn (WhmClient $w): array => $w->mail()->getDefaultDmarcRecord(), '{"payload":{"record":null}}'];
        yield 'mailbox map integer bytes' => ['get_mailbox_status',
            static fn (WhmClient $w): array => $w->mail()->getMailboxStatus('fixture'), '{"INBOX":{"vsize":"00"}}'];
        yield 'mailbox map row object' => ['get_mailbox_status',
            static fn (WhmClient $w): array => $w->mail()->getMailboxStatus('fixture'), '{"INBOX":null}'];
        yield 'mailbox list strict count' => ['get_mailbox_status_list',
            static fn (WhmClient $w): array => $w->mail()->getMailboxStatusList('fixture'),
            '{"mailboxes":[{"messages":1.5}]}'];
        yield 'recipient count strict' => ['get_unique_recipient_count_per_sender_for_user',
            static fn (WhmClient $w): array => $w->mail()->getUniqueRecipientCountPerSenderForUser('fixture', 0, 2),
            '{"payload":[{"unique_recipient_count":"1"}]}'];
        yield 'pair count strict' => ['get_unique_sender_recipient_count_per_user',
            static fn (WhmClient $w): array => $w->mail()->getUniqueSenderRecipientCountPerUser(0, 2),
            '{"payload":[{"unique_sender_recipient_count":true}]}'];
        yield 'forward list' => ['get_user_email_forward_destination',
            static fn (WhmClient $w): array => $w->mail()->getUserEmailForwardDestination('fixture'),
            '{"forward_to":{"destination":"fixture@example.test"}}'];
        yield 'DKIM install status' => ['install_dkim_private_keys',
            static fn (WhmClient $w): array => $w->mail()->installDkimPrivateKeys('example.test', 'synthetic'),
            '{"payload":[{"status":"1"}]}'];
        yield 'SPF install status' => ['install_spf_records',
            static fn (WhmClient $w): array => $w->mail()->installSpfRecords('example.test', 'v=spf1 -all'),
            '{"payload":[{"status":2}]}'];
        yield 'SNI flag' => ['is_sni_supported',
            static fn (WhmClient $w): array => $w->mail()->isSniSupported(), '{"sni":"1"}'];
        yield 'blocked country row' => ['list_blocked_incoming_email_countries',
            static fn (WhmClient $w): array => $w->mail()->listBlockedIncomingEmailCountries(),
            '{"countries":[{"country_code":0}]}'];
        yield 'blocked domain row' => ['list_blocked_incoming_email_domains',
            static fn (WhmClient $w): array => $w->mail()->listBlockedIncomingEmailDomains(),
            '{"domains":[{"domain":null}]}'];
        yield 'POP list' => ['list_pops_for',
            static fn (WhmClient $w): array => $w->mail()->listPopsFor('fixture'), '{"pops":[{}]}'];
        yield 'domain SNI flag' => ['mail_sni_status',
            static fn (WhmClient $w): array => $w->mail()->mailSniStatus('example.test'), '{"enabled":null}'];
        yield 'SNI rebuilt configs' => ['rebuild_mail_sni_config',
            static fn (WhmClient $w): array => $w->mail()->rebuildMailSniConfig(), '{"configs_built":[1]}'];
        yield 'DMARC remove status' => ['remove_dmarc',
            static fn (WhmClient $w): array => $w->mail()->removeDmarc('example.test'),
            '{"payload":[{"status":null}]}'];
        yield 'default DMARC success integer' => ['set_default_dmarc_record',
            static fn (WhmClient $w): array => $w->mail()->setDefaultDmarcRecord(), '{"payload":{"success":"1"}}'];
        yield 'manual MX map' => ['set_manual_mx_redirects',
            static fn (WhmClient $w): array => $w->mail()->setManualMxRedirects('example.test', '192.0.2.1'),
            '{"payload":{"example.test":0}}'];
        yield 'country unblock flag' => ['unblock_incoming_email_from_country',
            static fn (WhmClient $w): array => $w->mail()->unblockIncomingEmailFromCountry('RS'), '{"updated":"0"}'];
        yield 'domain unblock flag' => ['unblock_incoming_email_from_domain',
            static fn (WhmClient $w): array => $w->mail()->unblockIncomingEmailFromDomain('example.test'),
            '{"updated":false}'];
        yield 'manual MX unset map' => ['unset_manual_mx_redirects',
            static fn (WhmClient $w): array => $w->mail()->unsetManualMxRedirects('example.test'),
            '{"payload":{"example.test":false}}'];
        yield 'DKIM repaired list type' => ['validate_current_dkims',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentDkims('example.test'), '{"payload":{}}'];
        yield 'DKIM record nested leaf' => ['validate_current_dkims',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentDkims('example.test'),
            '{"payload":[{"records":[{"current":null}]}]}'];
        yield 'DMARC repaired list leaf' => ['validate_current_dmarcs',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentDmarcs(), '{"payload":[{"record":0}]}'];
        yield 'installed Exim message type' => ['validate_current_installed_exim_config',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentInstalledEximConfig(), '{"message":0}'];
        yield 'PTR forward record leaf' => ['validate_current_ptrs',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentPtrs('example.test'),
            '{"payload":[{"ptr_records":[{"forward_records":[null]}]}]}'];
        yield 'SPF nested state string' => ['validate_current_spfs',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentSpfs('example.test'),
            '{"payload":[{"records":[{"state":0}]}]}'];
        yield 'Exim syntax error line' => ['validate_exim_configuration_syntax',
            static fn (WhmClient $w): array => $w->mail()->validateEximConfigurationSyntax(), '{"error_line":"1"}'];
        yield 'Exim capability dynamic flag' => ['validate_exim_configuration_syntax',
            static fn (WhmClient $w): array => $w->mail()->validateEximConfigurationSyntax(),
            '{"exim_caps":{"directives":{"synthetic_directive":"1"}}}'];
        yield 'Exim capability object' => ['validate_exim_configuration_syntax',
            static fn (WhmClient $w): array => $w->mail()->validateEximConfigurationSyntax(), '{"exim_caps":null}'];
    }

    /** @param Call $call */
    #[DataProvider('invalidLeaves')]
    public function testMalformedPinnedLeavesAreRejected(string $operation, Closure $call, string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $this->expectException(PayloadMismatchException::class);
        $call($client->whm());
    }

    /** @return iterable<string, array{string, Call}> */
    public static function defaults(): iterable
    {
        yield 'search defaults' => ['emailtrack_search',
            static fn (WhmClient $w): array => $w->mail()->emailtrackSearch()];
        yield 'stats defaults' => ['emailtrack_stats',
            static fn (WhmClient $w): array => $w->mail()->emailtrackStats()];
        yield 'user stats defaults' => ['emailtrack_user_stats',
            static fn (WhmClient $w): array => $w->mail()->emailtrackUserStats()];
        yield 'rebuild defaults' => ['rebuild_mail_sni_config',
            static fn (WhmClient $w): array => $w->mail()->rebuildMailSniConfig()];
        yield 'set DMARC omission' => ['set_default_dmarc_record',
            static fn (WhmClient $w): array => $w->mail()->setDefaultDmarcRecord()];
        yield 'validate DMARC all domains' => ['validate_current_dmarcs',
            static fn (WhmClient $w): array => $w->mail()->validateCurrentDmarcs()];
        yield 'syntax current configuration defaults' => ['validate_exim_configuration_syntax',
            static fn (WhmClient $w): array => $w->mail()->validateEximConfigurationSyntax()];
    }

    /** @param Call $call */
    #[DataProvider('defaults')]
    public function testOmittedOptionsPreserveServerDefaults(string $operation, Closure $call): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
        if ($operation === 'apply_dmarc') {
            $call($client->whm());
            self::assertSame('policy=v%3DDMARC1%3B%20p%3Dnone%3B', self::body($http));
        } else {
            self::assertSame([], $call($client->whm()));
            self::assertSame('', self::body($http));
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('api.version=1', $request->getUri()->getQuery());
    }

    public function testSyntaxSuccessRetainsEveryPinnedCapabilityAndDynamicDirective(): void
    {
        [$client, $http] = self::client();
        $payload = '{"exim_caps":{"add_header":1,"archive":0,"boxtrapper":1,"content_scanning":0,'
            . '"directives":{"accept_8bitmime":1,"synthetic_directive":0},"dkim":1,"domainkeys":0,'
            . '"dovecot":1,"exiscan":0,"force_command":1,"maildir":0,"mailman":1,'
            . '"no_forward_outbound_spam":0,"no_forward_outbound_spam_over_int":1,"notquit":0,"passwd":1,'
            . '"rewrite_from_all":0,"rewrite_from_remote":1,"spf":0,"srs":1}}';
        $http->addResponse(new Response(200, [], self::envelope('validate_exim_configuration_syntax', $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->mail()->validateEximConfigurationSyntax(),
        );
    }

    public function testOptionalFieldsAndEmptyListsStayDistinctFromNull(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'validate_current_ptrs',
            '{"payload":[{}, {"nameservers":[],"ptr_records":[]}]}',
        )));
        self::assertSame(
            ['payload' => [[], ['nameservers' => [], 'ptr_records' => []]]],
            $client->whm()->mail()->validateCurrentPtrs('example.test'),
        );
        $http->addResponse(new Response(200, [], self::envelope(
            'fetch_mail_queue',
            '{"records":[{}, {"user":null}]}',
        )));
        self::assertSame(['records' => [[], ['user' => null]]], $client->whm()->mail()->fetchMailQueue());
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function invalidLists(): iterable
    {
        $invalid = [[], [1 => 'example.test'], ['key' => 'example.test'], [0], [null], [['example.test']]];
        foreach ($invalid as $i => $bad) {
            yield 'repeat domains ' . $i => ['enableDkim', [$bad]];
            yield 'repeat policies ' . $i => ['applyDmarc', [$bad, 'example.test']];
            yield 'repeat countries ' . $i => ['blockIncomingEmailFromCountry', [$bad]];
            yield 'numbered domains ' . $i => ['fetchDkimPrivateKeys', [$bad]];
            yield 'numbered keys ' . $i => ['installDkimPrivateKeys', ['example.test', $bad]];
            yield 'numbered MX hosts ' . $i => ['setManualMxRedirects', ['example.test', $bad]];
            yield 'numbered SNI domains ' . $i => ['enableMailSni', [$bad]];
            yield 'repeated SPF domains ' . $i => ['validateCurrentSpfs', [$bad]];
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidLists')]
    public function testInvalidListsFailBeforeDispatchAndClearPreviousMetadata(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('is_sni_supported', '{"sni":1}')));
        $client->whm()->mail()->isSniSupported();
        self::assertNotNull($client->whm()->responseMeta());
        try {
            // Reflection exercises callers outside the generated PHPDoc list contract.
            (new ReflectionMethod(Mail::class, $method))->invokeArgs($client->whm()->mail(), $arguments);
            self::fail('Invalid lists must fail before dispatch.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function forwardDestinationVariants(): iterable
    {
        yield 'omitted field' => ['{}', []];
        yield 'empty destination list' => ['{"forward_to":[]}', ['forward_to' => []]];
        yield 'populated pinned list' => ['{"forward_to":["fixture@example.test","fixture"]}',
            ['forward_to' => ['fixture@example.test', 'fixture']]];
        yield 'source scalar alias' => ['{"forward_to":"fixture@example.test"}',
            ['forward_to' => 'fixture@example.test']];
        yield 'scalar zero' => ['{"forward_to":"0"}', ['forward_to' => '0']];
        yield 'scalar whitespace' => ['{"forward_to":" "}', ['forward_to' => ' ']];
        yield 'observed literal empty string' => ['{"forward_to":""}', ['forward_to' => '']];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('forwardDestinationVariants')]
    public function testForwardDestinationPreservesItsExactVariants(string $payload, array $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('get_user_email_forward_destination', $payload)));
        self::assertSame($expected, $client->whm()->mail()->getUserEmailForwardDestination('fixture'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidForwardDestinations(): iterable
    {
        foreach (
            [
            'null' => 'null', 'boolean' => 'false', 'integer' => '0', 'float' => '1.5',
            'nonempty object' => '{"destination":"fixture@example.test"}',
            'nullable list item' => '[null]', 'integer list item' => '[0]', 'nested list item' => '[["fixture"]]',
            ] as $label => $value
        ) {
            yield $label => ['{"forward_to":' . $value . '}'];
        }
    }

    #[DataProvider('invalidForwardDestinations')]
    public function testForwardDestinationCorrectionRejectsOtherScalarsAndMalformedLists(string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('get_user_email_forward_destination', $payload)));
        try {
            $client->whm()->mail()->getUserEmailForwardDestination('fixture');
            self::fail('Only strings and string lists are accepted.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function reviewedWireVariants(): iterable
    {
        yield 'mailbox counts' => ['get_mailbox_status', 'getMailboxStatus',
            '{"INBOX":{"messages":"0","vsize":"9223372036854775807"}}',
            ['INBOX' => ['messages' => 0, 'vsize' => PHP_INT_MAX]]];
        yield 'mailbox list counts' => ['get_mailbox_status_list', 'getMailboxStatusList',
            '{"mailboxes":[{"messages":"1","vsize":"0"}]}',
            ['mailboxes' => [['messages' => 1, 'vsize' => 0]]]];
        foreach (['Dmarcs', 'Dkims', 'Ptrs', 'Spfs'] as $suffix) {
            $row = ['error' => null];
            if ($suffix === 'Dmarcs') {
                $row['record'] = null;
            }
            if (in_array($suffix, ['Ptrs', 'Spfs'], true)) {
                $row['ip_version'] = 4;
            }
            $wire = $row;
            if (isset($wire['ip_version'])) {
                $wire['ip_version'] = '4';
            }
            yield $suffix => ['validate_current_' . strtolower($suffix), 'validateCurrent' . $suffix,
                json_encode(['payload' => [$wire]], JSON_THROW_ON_ERROR), ['payload' => [$row]]];
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('reviewedWireVariants')]
    public function testReviewedWireVariants(string $operation, string $method, string $payload, array $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $actual = (new ReflectionMethod(Mail::class, $method))->invoke($client->whm()->mail(), 'example.test');
        self::assertSame($expected, $actual);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    public function testSuccessfulEximCheckMayOmitPayload(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('exim_configuration_check', null)));
        self::assertNull($client->whm()->mail()->eximConfigurationCheck());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
    }

    public function testCountryUnblockRequiresAnExplicitCountry(): void
    {
        [$client, $http] = self::client();
        try {
            (new ReflectionMethod(Mail::class, 'unblockIncomingEmailFromCountry'))->invoke($client->whm()->mail());
            self::fail('A country target is required.');
        } catch (\ArgumentCountError) {
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function unsafeDmarcTargets(): iterable
    {
        $targets = ['', ' ', "\t\n", '0', ' 0 ', [], [''], ['ok.test', ' '], ['0'], [1 => 'ok.test'], [null]];
        foreach ($targets as $i => $target) {
            yield 'remove ' . $i => ['removeDmarc', [$target]];
            yield 'apply ' . $i => ['applyDmarc', ['v=DMARC1; p=none;', $target]];
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('unsafeDmarcTargets')]
    public function testUnsafeDmarcTargetsClearMetadataWithoutIo(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('is_sni_supported', '{"sni":1}')));
        $client->whm()->mail()->isSniSupported();
        try {
            (new ReflectionMethod(Mail::class, $method))->invokeArgs($client->whm()->mail(), $arguments);
            self::fail('Unsafe scope must not dispatch.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('An explicit nonblank, nonzero target is required.', $exception->getMessage());
            self::assertNull($client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testMissingAndNullDmarcTargetsRejectBeforeMetadataReset(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('is_sni_supported', '{"sni":1}')));
        $client->whm()->mail()->isSniSupported();
        $before = $client->whm()->responseMeta();
        foreach (
            [
            ['removeDmarc', []], ['removeDmarc', [null]],
            ['applyDmarc', ['v=DMARC1; p=none;']], ['applyDmarc', ['v=DMARC1; p=none;', null]]] as [$method, $arguments]
        ) {
            try {
                (new ReflectionMethod(Mail::class, $method))->invokeArgs($client->whm()->mail(), $arguments);
                self::fail('Native required argument must reject.');
            } catch (\TypeError) {
                self::assertSame($before, $client->whm()->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    public function testWeakFalseyDmarcTargetsCannotBecomeServerWide(): void
    {
        [$client, $http] = self::client();
        foreach ([0, false] as $target) {
            try {
                \HostingPal\WhmApiClient\Tests\Fixtures\WeakMailCaller::call(
                    $client->whm()->mail(),
                    'removeDmarc',
                    [$target],
                );
                self::fail('Weak falsey targets must reject.');
            } catch (InvalidArgumentException) {
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    public function testAllDomainDmarcChoiceUsesExplicitGenericPost(): void
    {
        [$client, $http] = self::client();
        $choices = ['remove_dmarc' => [], 'apply_dmarc' => ['policy' => 'v=DMARC1; p=none;']];
        foreach ($choices as $operation => $parameters) {
            $http->addResponse(new Response(200, [], self::envelope($operation, '{"payload":[]}')));
            self::assertSame(['payload' => []], $client->whm()->call($operation, $parameters)->data);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('POST', $request->getMethod());
            self::assertSame('api.version=1', $request->getUri()->getQuery());
            self::assertSame(
                $operation === 'remove_dmarc' ? '' : 'policy=v%3DDMARC1%3B%20p%3Dnone%3B',
                (string) $request->getBody(),
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedReviewedIntegerLeaves(): iterable
    {
        foreach (['"00"', '"+1"', '"1.0"', '"1e1"', '"9223372036854775808"', 'true', 'null', '1.5'] as $i => $value) {
            foreach (['messages', 'vsize'] as $field) {
                yield 'map ' . $field . $i => ['get_mailbox_status', 'getMailboxStatus',
                    '{"INBOX":{"' . $field . '":' . $value . '}}'];
                yield 'list ' . $field . $i => ['get_mailbox_status_list', 'getMailboxStatusList',
                    '{"mailboxes":[{"' . $field . '":' . $value . '}]}'];
            }
            foreach (['Ptrs', 'Spfs'] as $suffix) {
                yield $suffix . $i => ['validate_current_' . strtolower($suffix), 'validateCurrent' . $suffix,
                    '{"payload":[{"ip_version":' . $value . '}]}'];
            }
        }
    }

    #[DataProvider('malformedReviewedIntegerLeaves')]
    public function testMalformedReviewedIntegerLeavesKeepOutcomeMetadata(
        string $operation,
        string $method,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            (new ReflectionMethod(Mail::class, $method))->invoke($client->whm()->mail(), 'example.test');
            self::fail('Noncanonical or out of range counts must reject.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testScalarSpfMethodStillRejectsParallelArrays(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('install_spf_records', '{"payload":[]}')));
        $this->expectException(\TypeError::class);
        // Paired lists belong to installSpfRecordBatch; the scalar method stays scalar.
        (new ReflectionMethod(Mail::class, 'installSpfRecords'))->invoke(
            $client->whm()->mail(),
            ['a.example.test', 'b.example.test'],
            ['v=spf1 -all', 'v=spf1 -all'],
        );
    }

    public function testPrivateInputsAreSensitive(): void
    {
        foreach (
            [
            ['installDkimPrivateKeys', 'keys'], ['validateEximConfigurationSyntax', 'cfgText'],
            ['expungeMailboxMessages', 'query'], ['expungeMessagesForMailboxGuid', 'query']] as [$method, $parameter]
        ) {
            $reflection = new ReflectionMethod(Mail::class, $method);
            $parameters = array_filter(
                $reflection->getParameters(),
                static fn ($p): bool => $p->getName() === $parameter,
            );
            self::assertCount(1, $parameters);
            self::assertCount(1, array_values($parameters)[0]->getAttributes(SensitiveParameter::class));
        }
    }

    /** @phpstan-impure */
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
