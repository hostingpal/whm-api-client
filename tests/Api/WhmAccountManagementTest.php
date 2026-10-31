<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Error;
use HostingPal\WhmApiClient\Api\Whm\Accounts;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

final class WhmAccountManagementTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, array<array-key, mixed>, string, string|null}> */
    public static function operations(): iterable
    {
        yield 'assign_account_enhancement' => ['assignAccountEnhancement', 'assign_account_enhancement',
            'POST', ['fixture', 'extra & space'],
            'account=fixture&name=extra%20%26%20space',
            null];
        yield 'convert_addon_fetch_conversion_details' => [
            'convertAddonFetchConversionDetails', 'convert_addon_fetch_conversion_details',
            'GET', [2],
            'job_id=2',
            '{"domain":"addon.example","job_end_time":0,"job_id":2,"job_start_time":1,'
                . '"job_status":"INPROGRESS","source_acct":"source","steps":[{"end_time":0,"start_time":1,'
                . '"status":"IN PROGRESS","step_name":"Copying content","warnings":"Synthetic warning"}],'
                . '"target_acct":"fixture"}'];
        yield 'convert_addon_fetch_domain_details' => [
            'convertAddonFetchDomainDetails', 'convert_addon_fetch_domain_details',
            'GET', ['addon.example'],
            'domain=addon.example',
            '{"docroot":"/home/source/addon","has_dedicated_ip":0,"has_ssl_cert_installed":1,'
                . '"ip":"192.0.2.1","is_sni_supported":1,"number_of_autoresponders":0,'
                . '"number_of_domain_forwarders":1,"number_of_email_accounts":2,"number_of_email_forwarders":1,'
                . '"owner":"source"}'];
        yield 'convert_addon_get_conversion_status' => [
            'convertAddonGetConversionStatus', 'convert_addon_get_conversion_status',
            'GET', [2],
            'job_id=2',
            '{"2":{"job_end_time":0,"job_id":"2","job_status":"DONE","source_acct":"source"}}'];
        yield 'convert_addon_list_addon_domains' => ['convertAddonListAddonDomains', 'convert_addon_list_addon_domains',
            'GET', [],
            '',
            '{"addon.example":{"docroot":"/home/source/addon","domain_type":"addon","ip":"192.0.2.1",'
                . '"ipv6":"2001:db8::1","owner":"source","reseller":"root","subdomain":"addon.main.example"}}'];
        yield 'convert_addon_list_conversions' => ['convertAddonListConversions', 'convert_addon_list_conversions',
            'GET', [],
            '',
            '{"conversions":[{"domain":"addon.example","end_time":0,"job_id":2,"source_acct":"source",'
                . '"start_time":1,"status":"DONE","target_acct":"fixture"}]}'];
        yield 'create_account_enhancement' => ['createAccountEnhancement', 'create_account_enhancement',
            'POST', ['plugin-id', 'extra'],
            'id=plugin-id&name=extra',
            '{"name":"extra"}'];
        yield 'delete_account_enhancement' => ['deleteAccountEnhancement', 'delete_account_enhancement',
            'POST', ['extra'],
            'name=extra',
            null];
        yield 'domainuserdata' => ['domainUserData', 'domainuserdata',
            'GET', ['fixture.example'],
            'domain=fixture.example',
            '{"userdata":{"customlog":[{"format":"combined","target":"/logs/access"}],'
                . '"documentroot":"/home/fixture/public_html","group":"fixture","hascgi":1,'
                . '"homedir":"/home/fixture","ip":"192.0.2.1","owner":"root","phpopenbasedirprotect":0,"port":80,'
                . '"scriptalias":[{"path":"/cgi-bin/","url":"/home/fixture/cgi-bin"}],'
                . '"serveradmin":"admin@example","serveralias":"www.fixture.example",'
                . '"servername":"fixture.example","usecanonicalname":"Off","user":"fixture","userdirprotect":""}}'];
        yield 'editquota' => ['editQuota', 'editquota',
            'POST', ['fixture', 0],
            'user=fixture&quota=0',
            null];
        yield 'forcepasswordchange' => ['forcePasswordChange', 'forcepasswordchange',
            'POST', ['{"fixture":1}', false],
            'users_json=%7B%22fixture%22%3A1%7D&stop_on_failure=0',
            '{"updated":["fixture"]}'];
        yield 'get_current_users_count' => ['getCurrentUsersCount', 'get_current_users_count',
            'GET', [],
            '',
            '{"users":0}'];
        yield 'get_disk_usage' => ['getDiskUsage', 'get_disk_usage',
            'GET', ['off'],
            'cache_mode=off',
            '{"accounts":[{"blocks_limit":null,"blocks_used":0,"inodes_limit":10,"inodes_used":1,'
                . '"user":"fixture"}]}'];
        yield 'get_homedir_roots' => ['getHomedirRoots', 'get_homedir_roots',
            'GET', [],
            '',
            '{"payload":[{"path":"/home"},{"path":"/home2"}]}'];
        yield 'get_maximum_users' => ['getMaximumUsers', 'get_maximum_users',
            'GET', [],
            '',
            '{"maximum_users":100}'];
        yield 'get_password_strength' => ['getPasswordStrength', 'get_password_strength',
            'POST', ['secret &=+'],
            'password=secret%20%26%3D%2B',
            '{"strength":0}'];
        yield 'get_upgrade_opportunities' => ['getUpgradeOpportunities', 'get_upgrade_opportunities',
            'GET', [0, 0.75],
            'disk_threshold_blocks=0&nearness_fraction=0.75',
            '{"upgrade_opportunities":{"fixture":{"bw_limit":{"last_month":{"fraction":0.5,"near":0,'
                . '"reached":0},"messages":["Bandwidth"],"this_month":{"fraction":1.0,"near":1,"reached":1}},'
                . '"disk_usage":{"messages":[],"relative_to_fixed_amount":{"fraction":null,"near":null,'
                . '"reached":null,"threshold_blocks":null},"relative_to_quota":{"fraction":0.75,"near":1,'
                . '"reached":0,"threshold_blocks":0}},"messages":["Synthetic opportunity"]}}}'];
        yield 'get_users_links' => ['getUsersLinks', 'get_users_links',
            'GET', ['fixture', 'webmail'],
            'user=fixture&service=webmail',
            '{"mail":"https://private.example/?token=opaque"}'];
        yield 'getdomainowner' => ['getDomainOwner', 'getdomainowner',
            'GET', ['fixture.example'],
            'domain=fixture.example',
            '{"user":null}'];
        yield 'has_digest_auth' => ['hasDigestAuth', 'has_digest_auth',
            'GET', ['fixture'],
            'user=fixture',
            '{"digestauth":0}'];
        yield 'has_mycnf_for_cpuser' => ['hasMycnfForCpuser', 'has_mycnf_for_cpuser',
            'GET', ['fixture'],
            'user=fixture',
            '{"has_mycnf_for_cpuser":1}'];
        yield 'limitbw' => ['limitBandwidth', 'limitbw',
            'POST', ['fixture', 'unlimited'],
            'user=fixture&bwlimit=unlimited',
            '{"bwlimits":[{"bwlimit":0,"bwlimitenable":0,"domains":["fixture.example"],'
                . '"human_bwlimit":"unlimited","human_bwused":"none","unlimited":1}]}'];
        yield 'list_account_enhancements' => ['listAccountEnhancements', 'list_account_enhancements',
            'GET', [],
            '',
            '{"enhancements":[{"id":"plugin-id","name":"extra"},{"id":"other","name":"second"}]}'];
        yield 'list_users' => ['listUsers', 'list_users',
            'GET', [],
            '',
            '{"users":["fixture","other"]}'];
        yield 'listlockedaccounts' => ['listLockedAccounts', 'listlockedaccounts',
            'GET', [],
            '',
            '{"account":["fixture"]}'];
        yield 'listsuspended' => ['listSuspended', 'listsuspended',
            'GET', [],
            '',
            '{"account":[{"is_locked":0,"owner":"root","reason":"Synthetic reason","time":"0","unixtime":0,'
                . '"user":"fixture"}]}'];
        yield 'modify_account_enhancement' => ['modifyAccountEnhancement', 'modify_account_enhancement',
            'POST', ['extra', 'plugin-id'],
            'name=extra&id=plugin-id',
            '{"id":"plugin-id","name":"extra"}'];
        yield 'myprivs' => ['myPrivileges', 'myprivs',
            'GET', [],
            '',
            '{"privileges":[{"acct-summary":1,"allow-shell":0,"list-pkgs":1,"all":0}]}'];
        yield 'quota_enabled' => ['quotaEnabled', 'quota_enabled',
            'GET', [],
            '',
            '{"quota_enabled":0}'];
        yield 'set_digest_auth' => ['setDigestAuth', 'set_digest_auth',
            'POST', ['fixture', 'secret &=+', false],
            'user=fixture&password=secret%20%26%3D%2B&enabledigest=0',
            null];
        yield 'set_primary_domain_docroot' => ['setPrimaryDomainDocroot', 'set_primary_domain_docroot',
            'POST', ['fixture', 'public_html/new root'],
            'username=fixture&docroot=public_html%2Fnew%20root',
            '{"documentroot":"/home/fixture/public_html/new root"}'];
        yield 'showbw' => ['showBandwidth', 'showbw',
            'GET', [10, '^fixture$', 'user', 'owner', 2026],
            'month=10&search=%5Efixture%24&searchtype=user&showres=owner&year=2026',
            '{"acct":[{"bwusage":[{"deleted":0,"domain":"fixture.example","usage":0}],"deleted":0,'
                . '"limit":0,"maindomain":"fixture.example","owner":"root","reseller":0,"totalbytes":0,'
                . '"user":"fixture"}],"month":10,"reseller":"owner","totalused":0,"year":2026}'];
        yield 'unassign_account_enhancement' => ['unassignAccountEnhancement', 'unassign_account_enhancement',
            'POST', ['fixture', 'extra'],
            'account=fixture&name=extra',
            null];
        yield 'untrack_acct_id' => ['untrackAcctId', 'untrack_acct_id',
            'POST', [0, 0, false, false, false],
            'uid=0&gid=0&check_exists=0&check_quota=0&protect_system=0',
            null];
        yield 'verify_new_username' => ['verifyNewUsername', 'verify_new_username',
            'GET', ['fixture'],
            'user=fixture',
            null];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testEveryAdditionUsesThePinnedWireContract(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        self::assertTrue(method_exists(Accounts::class, $method), 'Missing account method ' . $method);
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $result = $client->whm()->accounts()->{$method}(...$arguments);
        $expected = $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::canonical($expected), self::canonical($result));
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string|null}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operations() as $name => [$method, $operation, , $arguments, , $payload]) {
            if ($payload === null) {
                continue;
            }
            foreach ([null, 'null', 'false', '1', '"text"', '[1]'] as $bad) {
                yield $name . ' root ' . ($bad ?? 'absent') => [$method, $operation, $arguments, $bad];
            }
        }
        yield 'nested conversion step' => [
            'convertAddonFetchConversionDetails', 'convert_addon_fetch_conversion_details',
            [2], '{"steps":[{"warnings":1}]}'];
        yield 'addon string flag' => ['convertAddonFetchDomainDetails', 'convert_addon_fetch_domain_details',
            ['addon.example'], '{"has_dedicated_ip":"0"}'];
        yield 'status ID stays string' => ['convertAddonGetConversionStatus', 'convert_addon_get_conversion_status',
            [2], '{"2":{"job_id":2}}'];
        yield 'addon map leaf' => ['convertAddonListAddonDomains', 'convert_addon_list_addon_domains', [],
            '{"addon.example":{"docroot":null}}'];
        yield 'conversion list object' => ['convertAddonListConversions', 'convert_addon_list_conversions', [],
            '{"conversions":{}}'];
        yield 'userdata noncanonical port string' => ['domainUserData', 'domainuserdata', ['fixture.example'],
            '{"userdata":{"port":"080"}}'];
        yield 'userdata nested log' => ['domainUserData', 'domainuserdata', ['fixture.example'],
            '{"userdata":{"customlog":[{"target":0}]}}'];
        yield 'forced update user' => ['forcePasswordChange', 'forcepasswordchange', ['{}'], '{"updated":[0]}'];
        yield 'count string' => ['getCurrentUsersCount', 'get_current_users_count', [], '{"users":"0"}'];
        yield 'disk strict integer' => ['getDiskUsage', 'get_disk_usage', [], '{"accounts":[{"blocks_used":"0"}]}'];
        yield 'home root null' => ['getHomedirRoots', 'get_homedir_roots', [], '{"payload":[{"path":null}]}'];
        yield 'maximum boolean' => ['getMaximumUsers', 'get_maximum_users', [], '{"users":false}'];
        yield 'strength float' => ['getPasswordStrength', 'get_password_strength', ['secret'], '{"strength":0.5}'];
        yield 'upgrade string flag' => ['getUpgradeOpportunities', 'get_upgrade_opportunities', [],
            '{"upgrade_opportunities":{"fixture":{"bw_limit":{"this_month":{"near":"1"}}}}}'];
        yield 'upgrade string fraction' => ['getUpgradeOpportunities', 'get_upgrade_opportunities', [],
            '{"upgrade_opportunities":{"fixture":{"disk_usage":{"relative_to_quota":{"fraction":"0.5"}}}}}'];
        yield 'URL integer' => ['getUsersLinks', 'get_users_links', ['fixture'], '{"mail":1}'];
        yield 'owner integer' => ['getDomainOwner', 'getdomainowner', ['fixture.example'], '{"user":0}'];
        yield 'digest out of range' => ['hasDigestAuth', 'has_digest_auth', ['fixture'], '{"digestauth":2}'];
        yield 'mycnf boolean' => ['hasMycnfForCpuser', 'has_mycnf_for_cpuser', ['fixture'],
            '{"has_mycnf_for_cpuser":false}'];
        yield 'bandwidth integer string' => [
            'limitBandwidth', 'limitbw', ['fixture'], '{"bwlimits":[{"bwlimit":"0"}]}'];
        yield 'enhancement name integer' => ['listAccountEnhancements', 'list_account_enhancements', [],
            '{"enhancements":[{"name":1}]}'];
        yield 'user integer' => ['listUsers', 'list_users', [], '{"users":[1]}'];
        yield 'locked object' => ['listLockedAccounts', 'listlockedaccounts', [], '{"account":{}}'];
        yield 'suspension strict timestamp' => ['listSuspended', 'listsuspended', [], '{"account":[{"unixtime":"0"}]}'];
        yield 'create enhancement null' => ['createAccountEnhancement', 'create_account_enhancement', ['id', 'extra'],
            '{"name":null}'];
        yield 'modify enhancement null' => ['modifyAccountEnhancement', 'modify_account_enhancement', ['extra'],
            '{"id":null}'];
        yield 'ACL strict flag' => ['myPrivileges', 'myprivs', [], '{"privileges":[{"all":"0"}]}'];
        yield 'ACL unrestricted integer stays native' => ['myPrivileges', 'myprivs', [],
            '{"privileges":[{"allow-shell":"0"}]}'];
        yield 'ACL list packages stays native' => ['myPrivileges', 'myprivs', [],
            '{"privileges":[{"list-pkgs":false}]}'];
        yield 'ACL field null' => ['myPrivileges', 'myprivs', [], '{"privileges":[{"all":null}]}'];
        yield 'ACL privileges root is list' => ['myPrivileges', 'myprivs', [], '{"privileges":{"all":0}}'];
        yield 'ACL list item is object' => ['myPrivileges', 'myprivs', [], '{"privileges":[1]}'];
        $invalidQuotaFlags = ['2', '"2"', '"01"', '"00"', '1.0', '0.0', 'true', 'false', 'null',
            '"+1"', '"-0"', '" 1"', '"1 "', '"1.0"'];
        foreach ($invalidQuotaFlags as $flag) {
            yield 'quota invalid flag ' . $flag => [
                'quotaEnabled', 'quota_enabled', [], '{"quota_enabled":' . $flag . '}'];
        }
        yield 'docroot null' => ['setPrimaryDomainDocroot', 'set_primary_domain_docroot',
            ['fixture', 'public_html/new'],
            '{"documentroot":null}'];
        yield 'bandwidth noncanonical usage' => ['showBandwidth', 'showbw', [],
            '{"acct":[{"bwusage":[{"usage":"00"}]}]}'];
        $invalidIntegers = ['null', 'true', 'false', '1.0', '1.5', '[]', '{}', '""', '"01"', '"-0"',
            '"+1"', '"-01"', '" 1"', '"1 "', '"1.0"', '"1e3"',
            '"' . PHP_INT_MAX . '0"', '"' . PHP_INT_MIN . '0"', (string) PHP_INT_MAX . '0'];
        foreach ($invalidIntegers as $value) {
            yield 'maximum invalid ' . $value => ['getMaximumUsers', 'get_maximum_users', [],
                '{"maximum_users":' . $value . '}'];
            yield 'total bandwidth invalid ' . $value => ['showBandwidth', 'showbw', [],
                '{"totalused":' . $value . '}'];
            yield 'domain bandwidth invalid ' . $value => ['showBandwidth', 'showbw', [],
                '{"acct":[{"bwusage":[{"usage":' . $value . '}]}]}'];
            yield 'account bandwidth limit invalid ' . $value => ['showBandwidth', 'showbw', [],
                '{"acct":[{"limit":' . $value . '}]}'];
            yield 'port invalid ' . $value => ['domainUserData', 'domainuserdata', ['fixture.example'],
                '{"userdata":{"port":' . $value . '}}'];
        }
        foreach (
            ['Unlimited', 'UNLIMITED', 'unlimited ', ' unlimited', 'N/a', 'n/a', 'N/A ', ' N/A',
            'NA', 'unknown', 'Infinity', '-1.5'] as $value
        ) {
            yield 'account bandwidth limit near miss ' . $value => ['showBandwidth', 'showbw', [],
                '{"acct":[{"limit":' . json_encode($value, JSON_THROW_ON_ERROR) . '}]}'];
        }
        foreach (['hascgi', 'phpopenbasedirprotect'] as $field) {
            foreach (
                ['2', '"2"', '"01"', '"-0"', '"+1"', '" 1"', '"1.0"', 'true', 'false',
                'null', '1.0', '[]', '{}'] as $value
            ) {
                yield $field . ' invalid ' . $value => ['domainUserData', 'domainuserdata', ['fixture.example'],
                    '{"userdata":{"' . $field . '":' . $value . '}}'];
            }
        }
        foreach (
            ['null', '1', '"x"', '{"id":"id","name":"name"}', '[1]', '[{"id":null}]',
            '[{"id":1}]', '[{"name":false}]'] as $value
        ) {
            yield 'enhancement list invalid ' . $value => ['listAccountEnhancements', 'list_account_enhancements', [],
                '{"enhancements":' . $value . '}'];
        }
        yield 'maximum pinned users remains strict' => ['getMaximumUsers', 'get_maximum_users', [],
            '{"maximum_users":"100","users":"100"}'];
        yield 'bandwidth sibling totalbytes remains strict' => ['showBandwidth', 'showbw', [],
            '{"totalused":"12","acct":[{"totalbytes":"12"}]}'];
        yield 'bandwidth limit sibling totalbytes remains strict' => ['showBandwidth', 'showbw', [],
            '{"acct":[{"limit":"2048","totalbytes":"12"}]}'];
        yield 'bandwidth literal sibling deleted remains strict' => ['showBandwidth', 'showbw', [],
            '{"acct":[{"limit":"N/A","deleted":"1"}]}'];
        yield 'bandwidth literal does not widen totalused' => ['showBandwidth', 'showbw', [],
            '{"totalused":"unlimited"}'];
        yield 'bandwidth literal does not widen domain usage' => ['showBandwidth', 'showbw', [],
            '{"acct":[{"bwusage":[{"usage":"N/A"}]}]}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadRetainsConfirmedOutcomeAndDoesNotRetry(
        string $method,
        string $operation,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $client->whm()->accounts()->{$method}(...$arguments);
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiFailuresRetainMetadataAndDoNotRetry(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload, 0)));
        try {
            $client->whm()->accounts()->{$method}(...$arguments);
            self::fail('Expected an API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame(['Synthetic account failure'], $error->errors());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
        }
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertCount(1, $http->getRequests());
    }

    public function testSensitiveArgumentsHaveAttributesAndAreHiddenInLibraryTraces(): void
    {
        foreach (['getPasswordStrength', 'setDigestAuth'] as $method) {
            $reflection = new ReflectionMethod(Accounts::class, $method);
            $matches = array_filter($reflection->getParameters(), static fn ($p): bool => $p->name === 'password');
            self::assertCount(1, $matches);
            self::assertCount(1, array_values($matches)[0]->getAttributes(SensitiveParameter::class));
        }
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], self::envelope('get_password_strength', '{"strength":"bad"}')));
            try {
                $client->whm()->accounts()->getPasswordStrength('private-password-fixture');
                self::fail('Expected a payload mismatch.');
            } catch (PayloadMismatchException $error) {
                self::assertStringNotContainsString('private-password-fixture', print_r($error->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>, string, string|null}> */
    public static function optionalInputs(): iterable
    {
        yield 'bandwidth default' => ['limitBandwidth', 'limitbw', 'POST', ['fixture'], 'user=fixture', '{}'];
        yield 'bandwidth zero' => ['limitBandwidth', 'limitbw', 'POST', ['fixture', 0], 'user=fixture&bwlimit=0', '{}'];
        yield 'bandwidth null omitted' => [
            'limitBandwidth', 'limitbw', 'POST', ['fixture', null], 'user=fixture', '{}'];
        yield 'disk quota unlimited' => ['editQuota', 'editquota', 'POST', ['fixture', 'unlimited'],
            'user=fixture&quota=unlimited', null];
        yield 'password forcing default' => ['forcePasswordChange', 'forcepasswordchange', 'POST',
            ['usersJson' => '{ "fixture": 1 }'], 'users_json=%7B%20%22fixture%22%3A%201%20%7D', '{}'];
        yield 'password forcing stop' => ['forcePasswordChange', 'forcepasswordchange', 'POST',
            ['{}', true], 'users_json=%7B%7D&stop_on_failure=1', '{}'];
        yield 'disk cache default' => ['getDiskUsage', 'get_disk_usage', 'GET', [], '', '{}'];
        yield 'upgrade thresholds default' => [
            'getUpgradeOpportunities', 'get_upgrade_opportunities', 'GET', [], '', '{}'];
        yield 'upgrade null threshold omitted' => ['getUpgradeOpportunities', 'get_upgrade_opportunities', 'GET',
            [null, 0.5], 'nearness_fraction=0.5', '{}'];
        yield 'links service default' => ['getUsersLinks', 'get_users_links', 'GET', ['fixture'], 'user=fixture', '{}'];
        yield 'enhancement ID omission is documented no-op' => [
            'modifyAccountEnhancement', 'modify_account_enhancement', 'POST',
            ['extra'], 'name=extra', '{}'];
        yield 'digest alias omitted' => ['setDigestAuth', 'set_digest_auth', 'POST', ['fixture', 's e+c&r', true],
            'user=fixture&password=s%20e%2Bc%26r&enabledigest=1', null];
        yield 'docroot empty forwarded for server validation' => [
            'setPrimaryDomainDocroot', 'set_primary_domain_docroot', 'POST',
            ['fixture', ''], 'username=fixture&docroot=', '{}'];
        yield 'bandwidth date filter default' => ['showBandwidth', 'showbw', 'GET', [], '', '{}'];
        yield 'bandwidth named explicit date' => ['showBandwidth', 'showbw', 'GET', ['month' => 1, 'year' => 2026],
            'month=1&year=2026', '{}'];
        yield 'untrack UID only preserves defaults' => ['untrackAcctId', 'untrack_acct_id', 'POST', ['uid' => 1234],
            'uid=1234', null];
        yield 'untrack GID only preserves defaults' => ['untrackAcctId', 'untrack_acct_id', 'POST', ['gid' => 1234],
            'gid=1234', null];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('optionalInputs')]
    public function testOmittedOptionsDefaultsZeroAndFalseStayDistinct(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $client->whm()->accounts()->{$method}(...$arguments);
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testOptionalFieldsAndVoidResultsDoNotAcquireDefaults(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload === null ? null : '{}')));
        self::assertSame($payload === null ? null : [], $client->whm()->accounts()->{$method}(...$arguments));
        self::assertRequest($http, $operation, $verb, $wire);
    }

    public function testNullableFieldsAndEmptyCollectionsRemainDistinctFromOmission(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'get_disk_usage',
            '{"accounts":[{"blocks_limit":null,"inodes_limit":null},{"blocks_limit":0,"inodes_limit":0},{}]}',
        )));
        self::assertSame(['accounts' => [
            ['blocks_limit' => null, 'inodes_limit' => null], ['blocks_limit' => 0, 'inodes_limit' => 0], [],
        ]], $client->whm()->accounts()->getDiskUsage());
        $http->addResponse(new Response(200, [], self::envelope('getdomainowner', '{"user":null}')));
        self::assertSame(['user' => null], $client->whm()->accounts()->getDomainOwner('missing.example'));
        $http->addResponse(new Response(200, [], self::envelope('convert_addon_get_conversion_status', '{}')));
        self::assertSame([], $client->whm()->accounts()->convertAddonGetConversionStatus(2));
        $http->addResponse(new Response(200, [], self::envelope(
            'get_upgrade_opportunities',
            '{"upgrade_opportunities":{}}',
        )));
        self::assertSame(['upgrade_opportunities' => []], $client->whm()->accounts()->getUpgradeOpportunities());
        $http->addResponse(new Response(200, [], self::envelope('list_users', '{"users":[]}')));
        self::assertSame(['users' => []], $client->whm()->accounts()->listUsers());
    }

    public function testUnknownNamedFieldsAreNotInventedWhileReturnedMapsPreserveTheirKeys(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'get_current_users_count',
            '{"users":0,"undocumented":"payload data"}',
        )));
        self::assertSame(['users' => 0], $client->whm()->accounts()->getCurrentUsersCount());
        $http->addResponse(new Response(200, [], self::envelope(
            'get_users_links',
            '{"unfamiliar-app":"https://private.example/session","other":""}',
        )));
        self::assertSame(
            ['unfamiliar-app' => 'https://private.example/session', 'other' => ''],
            $client->whm()->accounts()->getUsersLinks('fixture'),
        );
        self::assertCount(2, $http->getRequests());
    }

    public function testJsonAndDynamicKeyedOperationsRemainExcluded(): void
    {
        $methods = ['personalizationGet', 'personalizationSet', 'convertAddonInitiateConversion', 'massModifyAccount'];
        foreach ($methods as $method) {
            self::assertFalse(method_exists(Accounts::class, $method));
        }
    }

    public function testBothPasswordInputsAreHiddenOnApiFailure(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (['get_password_strength', 'set_digest_auth'] as $operation) {
                [$client, $http] = self::client();
                $http->addResponse(new Response(200, [], self::envelope($operation, null, 0)));
                try {
                    if ($operation === 'get_password_strength') {
                        $client->whm()->accounts()->getPasswordStrength('private-password-fixture');
                    } else {
                        $client->whm()->accounts()->setDigestAuth('fixture', 'private-password-fixture', false);
                    }
                    self::fail('Expected an API failure.');
                } catch (ApiErrorException $error) {
                    self::assertStringNotContainsString('private-password-fixture', print_r($error->getTrace(), true));
                    self::assertSame($client->whm()->responseMeta(), $error->metadata);
                }
                self::assertCount(1, $http->getRequests());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testPrivateReturnedUrlsAreHiddenInLibraryFailureFrames(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], self::envelope(
                'get_users_links',
                '{"private":"https://private.example/private-url-fixture","invalid":1}',
            )));
            try {
                $client->whm()->accounts()->getUsersLinks('fixture');
                self::fail('Expected a payload mismatch.');
            } catch (PayloadMismatchException $error) {
                self::assertStringNotContainsString('private-url-fixture', print_r($error->getTrace(), true));
                self::assertTrue($error->outcomeConfirmed);
            }
            self::assertCount(1, $http->getRequests());
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testThirdPartyAclValuesStayOpaqueWhileKnownIntegerFlagsAreValidated(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'myprivs',
            '{"privileges":[{"all":0,"allow-shell":37,"list-pkgs":22,"custom-null":null,'
                . '"custom-object":{"opaque":true},"custom-list":[0,"raw"],"custom-string":"1"},{}]}',
        )));
        self::assertSame(['privileges' => [
            ['all' => 0, 'allow-shell' => 37, 'list-pkgs' => 22, 'custom-null' => null,
                'custom-object' => ['opaque' => true], 'custom-list' => [0, 'raw'], 'custom-string' => '1'],
            [],
        ]], $client->whm()->accounts()->myPrivileges());
        self::assertCount(1, $http->getRequests());
    }

    public function testAllPinnedAclNamesSurviveWithoutCoercingTheirNativeIntegers(): void
    {
        // An independent list of the pinned ACL keys, not an oracle loaded from generated specifications.
        $names = [
            'acct-summary', 'add-pkg', 'add-pkg-ip', 'add-pkg-shell', 'all', 'allow-addoncreate',
            'allow-emaillimits-pkgs', 'allow-parkedcreate', 'allow-shell', 'allow-unlimited-bw-pkgs',
            'allow-unlimited-disk-pkgs', 'allow-unlimited-pkgs', 'basic-system-info', 'basic-whm-functions',
            'clustering', 'cors-proxy-get', 'cpanel-api', 'cpanel-integration', 'create-acct', 'create-dns',
            'create-user-session', 'demo-setup', 'digest-auth', 'edit-account', 'edit-dns', 'edit-mx', 'edit-pkg',
            'file-restore', 'generate-email-config', 'kill-acct', 'kill-dns', 'limit-bandwidth', 'list-accts',
            'list-pkgs', 'locale-edit', 'mailcheck', 'manage-api-tokens', 'manage-dns-records', 'manage-oidc',
            'manage-styles', 'mysql-info', 'news', 'ns-config', 'park-dns', 'passwd', 'quota', 'rearrange-accts',
            'resftp', 'restart', 'show-bandwidth', 'ssl', 'ssl-buy', 'ssl-gencrt', 'ssl-info', 'stats', 'status',
            'suspend-acct', 'thirdparty', 'track-email', 'upgrade-account', 'viewglobalpackages',
        ];
        $row = array_fill_keys($names, 0);
        $row['allow-shell'] = 17;
        $row['list-pkgs'] = 23;
        [$client, $http] = self::client();
        $payload = json_encode(['privileges' => [$row]], JSON_THROW_ON_ERROR);
        $http->addResponse(new Response(200, [], self::envelope('myprivs', $payload)));
        self::assertSame(['privileges' => [$row]], $client->whm()->accounts()->myPrivileges());
        self::assertCount(61, $row);
    }

    /** @return iterable<string, array{string, array<string, int>}> */
    public static function canonicalQuotaFlags(): iterable
    {
        yield 'native zero' => ['{"quota_enabled":0}', ['quota_enabled' => 0]];
        yield 'native one' => ['{"quota_enabled":1}', ['quota_enabled' => 1]];
        yield 'quoted zero' => ['{"quota_enabled":"0"}', ['quota_enabled' => 0]];
        yield 'quoted one' => ['{"quota_enabled":"1"}', ['quota_enabled' => 1]];
        yield 'omitted field' => ['{}', []];
    }

    /** @param array<string, int> $expected */
    #[DataProvider('canonicalQuotaFlags')]
    public function testOnlyCanonicalQuotedQuotaFlagsNormalize(string $payload, array $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('quota_enabled', $payload)));
        try {
            $result = $client->whm()->accounts()->quotaEnabled();
        } catch (PayloadMismatchException) {
            self::fail('Canonical native/quoted 0|1 quota flags and omission must be accepted.');
        }
        self::assertSame($expected, $result);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertRequest($http, 'quota_enabled', 'GET', '');
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, array<array-key, mixed>}> */
    public static function reviewedResponses(): iterable
    {
        foreach ([0, 80, 4294967296, PHP_INT_MIN, PHP_INT_MAX] as $value) {
            foreach ([false, true] as $quoted) {
                $wire = json_encode($quoted ? (string) $value : $value, JSON_THROW_ON_ERROR);
                yield 'license maximum ' . $wire => ['getMaximumUsers', 'get_maximum_users', [],
                    '{"maximum_users":' . $wire . ',"users":7}', ['maximum_users' => $value, 'users' => 7]];
                yield 'bandwidth bytes ' . $wire => ['showBandwidth', 'showbw', [],
                    '{"totalused":' . $wire . ',"acct":[{"user":"fixture","totalbytes":0,"bwusage":'
                        . '[{"domain":"fixture.example","usage":' . $wire . '},{"usage":0},{}]}]}',
                    ['totalused' => $value, 'acct' => [['user' => 'fixture', 'totalbytes' => 0, 'bwusage' => [
                        ['domain' => 'fixture.example', 'usage' => $value], ['usage' => 0], [],
                    ]]]]];
                yield 'account bandwidth limit ' . $wire => ['showBandwidth', 'showbw', [],
                    '{"acct":[{"limit":' . $wire . ',"totalbytes":0},{"user":"omitted"}]}',
                    ['acct' => [['limit' => $value, 'totalbytes' => 0], ['user' => 'omitted']]]];
                yield 'userdata port ' . $wire => ['domainUserData', 'domainuserdata', ['fixture.example'],
                    '{"userdata":{"port":' . $wire . ',"userdirprotect":"0"}}',
                    ['userdata' => ['port' => $value, 'userdirprotect' => '0']]];
            }
        }
        foreach (['0' => 0, '1' => 1] as $wire => $expected) {
            yield 'quoted userdata flags ' . $wire => ['domainUserData', 'domainuserdata', ['fixture.example'],
                '{"userdata":{"hascgi":"' . $wire . '","phpopenbasedirprotect":"' . $wire . '"}}',
                ['userdata' => ['hascgi' => $expected, 'phpopenbasedirprotect' => $expected]]];
        }
        yield 'account bandwidth source unlimited literal' => ['showBandwidth', 'showbw', [],
            '{"acct":[{"limit":"unlimited"}]}', ['acct' => [['limit' => 'unlimited']]]];
        yield 'account bandwidth source unavailable literal without sibling constraint' => [
            'showBandwidth', 'showbw', [], '{"acct":[{"limit":"N/A"}]}', ['acct' => [['limit' => 'N/A']]]];
        yield 'account bandwidth mixed limits preserve source literals and zero' => ['showBandwidth', 'showbw', [],
            '{"month":10,"year":2026,"totalused":"0","acct":['
                . '{"user":"zero","deleted":0,"limit":0,"totalbytes":0},'
                . '{"user":"limited","deleted":0,"limit":"2048","totalbytes":12},'
                . '{"user":"unlimited","deleted":0,"limit":"unlimited"},'
                . '{"user":"deleted","deleted":1,"limit":"N/A"},'
                . '{"user":"omitted"},{}]}',
            ['month' => 10, 'year' => 2026, 'totalused' => 0, 'acct' => [
                ['user' => 'zero', 'deleted' => 0, 'limit' => 0, 'totalbytes' => 0],
                ['user' => 'limited', 'deleted' => 0, 'limit' => 2048, 'totalbytes' => 12],
                ['user' => 'unlimited', 'deleted' => 0, 'limit' => 'unlimited'],
                ['user' => 'deleted', 'deleted' => 1, 'limit' => 'N/A'],
                ['user' => 'omitted'], [],
            ]]];
        yield 'observed maximum without pinned users' => ['getMaximumUsers', 'get_maximum_users', [],
            '{"maximum_users":"100"}', ['maximum_users' => 100]];
        yield 'pinned users without observed maximum' => ['getMaximumUsers', 'get_maximum_users', [],
            '{"users":100}', ['users' => 100]];
        yield 'enhancements two rows and partial fields' => ['listAccountEnhancements', 'list_account_enhancements', [],
            '{"enhancements":[{"id":"001","name":"One"},{"id":"002","name":"Two"},{"id":"003"},{}]}',
            ['enhancements' => [
                ['id' => '001', 'name' => 'One'], ['id' => '002', 'name' => 'Two'], ['id' => '003'], [],
            ]]];
        yield 'enhancements empty list' => ['listAccountEnhancements', 'list_account_enhancements', [],
            '{"enhancements":[]}', ['enhancements' => []]];
        yield 'untyped bandwidth extra' => ['showBandwidth', 'showbw', [],
            '{"totalused":"0","acct":[{"bwlimited":{"unreviewed":true}}]}',
            ['totalused' => 0, 'acct' => [[]]]];
        yield 'untyped userdata extra' => ['domainUserData', 'domainuserdata', ['fixture.example'],
            '{"userdata":{"port":"80","phpversion":{"unreviewed":true}}}', ['userdata' => ['port' => 80]]];
    }

    /** @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('reviewedResponses')]
    public function testReviewedWireValuesPreserveKnownFields(
        string $method,
        string $operation,
        array $arguments,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $result = $client->whm()->accounts()->{$method}(...$arguments);
        self::assertSame(self::canonical($expected), self::canonical($result));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    public function testDigestAliasCannotCompeteWithPrimaryFlag(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('set_digest_auth', null)));
        try {
            $method = (new ReflectionMethod(Accounts::class, 'setDigestAuth'))->getName();
            $client->whm()->accounts()->{$method}(
                user: 'fixture',
                password: 'synthetic password',
                enabledigest: true,
                digestauth: false,
            );
            self::fail('The omitted digestauth alias must not remain a callable named argument.');
        } catch (Error $error) {
            self::assertStringContainsString('Unknown named parameter $digestauth', $error->getMessage());
        }
        self::assertCount(0, $http->getRequests());
    }

    public function testResourceArgumentsPrecedeValuesAndPasswordRemainsSensitive(): void
    {
        foreach (
            [
            'editQuota' => ['user', 'quota'],
            'setPrimaryDomainDocroot' => ['username', 'docroot'],
            'setDigestAuth' => ['user', 'password', 'enabledigest'],
            'untrackAcctId' => ['uid', 'gid', 'checkExists', 'checkQuota', 'protectSystem'],
            ] as $method => $expected
        ) {
            $parameters = (new ReflectionMethod(Accounts::class, $method))->getParameters();
            self::assertSame($expected, array_map(static fn ($parameter): string => $parameter->name, $parameters));
        }
        $parameters = (new ReflectionMethod(Accounts::class, 'setDigestAuth'))->getParameters();
        self::assertCount(1, $parameters[1]->getAttributes(SensitiveParameter::class));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }

    private static function assertRequest(MockClient $http, string $operation, string $verb, string $wire): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
            . ($verb === 'GET' && $wire !== '' ? '&' . $wire : ''), (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    private static function envelope(string $operation, ?string $payload, int $status = 1): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"'
            . ($status === 1 ? 'OK' : 'Synthetic account failure') . '","result":' . $status . ',"version":1}'
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
