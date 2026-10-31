<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Error;
use HostingPal\WhmApiClient\Api\Whm\Resellers;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Fixtures\WeakResellerCaller;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionNamedType;
use SensitiveParameter;
use TypeError;

final class WhmResellersTest extends TestCase
{
    /** The 59 assignment switches independently enumerated from the pinned setacls parameters. */
    private const ASSIGNMENT_SWITCHES = [
        'aclAcctSummary', 'aclAddPkg', 'aclAddPkgIp', 'aclAddPkgShell', 'aclAll',
        'aclAllowAddoncreate', 'aclAllowParkedcreate', 'aclAllowUnlimitedBwPkgs', 'aclAllowUnlimitedDiskPkgs',
        'aclAllowUnlimitedPkgs', 'aclBasicSystemInfo', 'aclBasicWhmFunctions', 'aclClustering', 'aclCorsProxyGet',
        'aclCpanelApi', 'aclCpanelIntegration', 'aclCreateAcct', 'aclCreateDns', 'aclCreateUserSession',
        'aclDemoSetup',
        'aclDigestAuth', 'aclDisallowShell', 'aclEditAccount', 'aclEditDns', 'aclEditMx', 'aclEditPkg',
        'aclGenerateEmailConfig', 'aclKillAcct', 'aclKillDns', 'aclLimitBandwidth', 'aclListAccts', 'aclListPkgs',
        'aclLocaleEdit', 'aclMailcheck', 'aclManageApiTokens', 'aclManageDnsRecords', 'aclManageOidc',
        'aclManageStyles',
        'aclMysqlInfo', 'aclNameserverConfig', 'aclNews', 'aclParkDns', 'aclPasswd', 'aclQuota', 'aclRearrangeAccts',
        'aclResftp', 'aclRestart', 'aclShowBandwidth', 'aclSsl', 'aclSslBuy', 'aclSslGencrt', 'aclSslInfo', 'aclStats',
        'aclStatus', 'aclSuspendAcct', 'aclThirdparty', 'aclTrackEmail', 'aclUpgradeAccount', 'aclViewglobalpackages',
    ];

    /** @return iterable<string, array{string, array<array-key, mixed>, string, string, string, ?string, mixed}> */
    public static function operations(): iterable
    {
        yield 'account counts default identity' => ['accountCounts', [], 'acctcounts', 'GET', '',
            '{"reseller":{"active":2,"limit":null,"suspended":null,"user":"root"}}',
            ['reseller' => ['active' => 2, 'limit' => null, 'suspended' => null, 'user' => 'root']]];
        yield 'public contact' => ['getPublicContact', [], 'get_public_contact', 'GET', '',
            '{"name":"Hosting & Co","url":"https://example.test/contact?a=1"}',
            ['name' => 'Hosting & Co', 'url' => 'https://example.test/contact?a=1']];
        yield 'reseller ips default identity' => ['getResellerIps', [], 'getresellerips', 'GET', '',
            '{"all":0,"ip":["192.0.2.1","192.0.2.2"]}',
            ['all' => 0, 'ip' => ['192.0.2.1', '192.0.2.2']]];
        yield 'enhancement dynamic limits' => ['listEnhancementLimits', ['reseller'],
            'list_enhancement_limits', 'GET', 'account=reseller',
            '{"limits":{"plugin-id":{"limit":0,"limited":0,"usage":2},"other":{"limit":5,"limited":1,"usage":0}}}',
            ['limits' => ['plugin-id' => ['limit' => 0, 'limited' => 0, 'usage' => 2],
                'other' => ['limit' => 5, 'limited' => 1, 'usage' => 0]]]];
        yield 'named ACL privileges' => ['listAcls', [], 'listacls', 'GET', '',
            '{"acl":[{"name":"basic","privileges":{"acct-summary":1,"all":0,"create-user'
            . '-session":1,"frontpage":0,"manage-api-tokens":0,"passwd":1,"quota":0,"ssl-i'
            . 'nfo":1}}]}',
            ['acl' => [['name' => 'basic', 'privileges' => ['acct-summary' => 1, 'all' => 0,
                'create-user-session' => 1, 'frontpage' => 0, 'manage-api-tokens' => 0,
                'passwd' => 1, 'quota' => 0, 'ssl-info' => 1]]]]];
        yield 'resellers' => ['listResellers', [], 'listresellers', 'GET', '',
            '{"reseller":["alpha","beta"]}', ['reseller' => ['alpha', 'beta']]];
        yield 'statistics' => ['resellerStats', ['reseller', false, true, 10, 2026],
            'resellerstats', 'GET', 'user=reseller&filter_deleted=0&filter_suspended=1&month=10&year=2026',
            '{"reseller":{"acct":[{"bandwidthlimit":"0.00","bandwidthused":"1.50","delet'
            . 'ed":0,"diskquota":"0.00","diskused":"1.57","domain":"example.test","package'
            . '":"default","suspended":1,"user":"child"}],"bandwidthlimit":0,"bwoversellin'
            . 'g":1,"diskoverselling":0,"diskquota":0,"diskused":5.69,"month":10,"totalbwa'
            . 'lloc":"500","totalbwused":"1.50","totaldiskalloc":"1100","user":"reseller",'
            . '"year":2026}}',
            ['reseller' => ['acct' => [['bandwidthlimit' => '0.00', 'bandwidthused' => '1.50',
                'deleted' => 0, 'diskquota' => '0.00', 'diskused' => '1.57', 'domain' => 'example.test',
                'package' => 'default', 'suspended' => 1, 'user' => 'child']], 'bandwidthlimit' => 0,
                'bwoverselling' => 1, 'diskoverselling' => 0, 'diskquota' => 0, 'diskused' => 5.69,
                'month' => 10, 'totalbwalloc' => '500', 'totalbwused' => '1.50',
                'totaldiskalloc' => '1100', 'user' => 'reseller', 'year' => 2026]]];
        yield 'save ACL explicit removals and additions' => ['saveAclList',
            ['acllist' => 'basic +&=', 'aclAll' => false, 'aclAllowShell' => true,
                'aclAllowEmaillimitsPkgs' => false, 'aclNsConfig' => true],
            'saveacllist', 'POST', 'acllist=basic%20%2B%26%3D&acl-all=0&acl-allow-emaillimits-pkgs=0&acl-allow-'
            . 'shell=1&acl-ns-config=1',
            null, null];
        yield 'unlimited enhancement limit' => ['setEnhancementLimit', ['reseller', 'plugin +&=', false, 0],
            'set_enhancement_limit', 'POST', 'account=reseller&id=plugin%20%2B%26%3D&limited=0&limit=0', null, null];
        yield 'public contact mutation' => ['setPublicContact', ['', 'https://example.test/?a=1&b=2'],
            'set_public_contact', 'POST', 'name=&url=https%3A%2F%2Fexample.test%2F%3Fa%3D1%26b%3D2', null, null];
        yield 'inline ACL grant names differ from save' => ['setAcls',
            ['reseller' => 'reseller', 'aclDisallowShell' => true, 'aclNameserverConfig' => true],
            'setacls', 'POST', 'reseller=reseller&acl-disallow-shell=1&acl-nameserver-config=1',
            '{"acl":["disallow-shell","nameserver-config"]}', ['acl' => ['disallow-shell', 'nameserver-config']]];
        yield 'delegate all available IPs' => ['setResellerIps', ['reseller', false, '192.0.2.1,192.0.2.2'],
            'setresellerips', 'POST', 'user=reseller&delegate=0&ips=192.0.2.1%2C192.0.2.2',
            '{"all":1,"ip":["192.0.2.1","192.0.2.2"]}', ['all' => 1, 'ip' => ['192.0.2.1', '192.0.2.2']]];
        yield 'resource limits preserve zero' => ['setResellerLimits', ['reseller', 5, 0, 0,
            false, true, false, true, false, true, false], 'setresellerlimits', 'POST',
            'user=reseller&account_limit=5&bandwidth_limit=0&diskspace_limit=0&enable_ac'
            . 'count_limit=0&enable_overselling=1&enable_overselling_bandwidth=0&enable_ov'
            . 'erselling_diskspace=1&enable_package_limit_numbers=0&enable_package_limits='
            . '1&enable_resource_limits=0', null, null];
        yield 'main shared IP' => ['setResellerMainIp', ['reseller', '192.0.2.3'],
            'setresellermainip', 'POST', 'user=reseller&ip=192.0.2.3', null, null];
        yield 'nameservers' => ['setResellerNameservers', ['reseller', 'ns1.example.test,ns2.example.test'],
            'setresellernameservers', 'POST',
            'user=reseller&nameservers=ns1.example.test%2Cns2.example.test', null, null];
        yield 'package access disabled' => ['setResellerPackageLimit', ['reseller', false, false, 5, 'plan +&='],
            'setresellerpackagelimit', 'POST',
            'user=reseller&allowed=0&no_limit=0&number=5&package=plan%20%2B%26%3D', null, null];
        yield 'retain existing owner' => ['setupReseller', ['reseller', false], 'setupreseller', 'POST',
            'user=reseller&makeowner=0', null, null];
        yield 'suspend owned accounts' => ['suspendReseller', ['reseller', 'Private reason +&=', false, false],
            'suspendreseller', 'POST', 'user=reseller&reason=Private%20reason%20%2B%26%3D&disallow=0&reseller-only='
            . '0', null, null];
        yield 'termination account results' => ['terminateReseller', ['reseller', false], 'terminatereseller',
            'POST', 'user=reseller&terminatereseller=0',
            '{"acct":[{"output":{"raw":"<p>removed</p>"},"reason":"child removed","resul'
            . 't":1,"user":"child"},{"reason":"not removed","result":0,"user":"other"}]}',
            ['acct' => [['output' => ['raw' => '<p>removed</p>'], 'reason' => 'child removed',
                'result' => 1, 'user' => 'child'], ['reason' => 'not removed', 'result' => 0, 'user' => 'other']]]];
        yield 'revoke status' => ['unsetupReseller', ['reseller'], 'unsetupreseller', 'POST',
            'user=reseller', null, null];
        yield 'unsuspend only reseller' => ['unsuspendReseller', ['reseller', true], 'unsuspendreseller', 'POST',
            'user=reseller&reseller-only=1', null, null];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testSourceRequestsAndPayloads(
        string $method,
        array $arguments,
        string $wire,
        string $verb,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $group = $whm->resellers();
        self::assertSame($group, $whm->resellers());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($wire, $payload)));

        self::assertSame($expected, $group->{$method}(...$arguments));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $wire . '?api.version=1'
            . ($verb === 'GET' && $parameters !== '' ? '&' . $parameters : ''), (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame($wire, $whm->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>, string, string, string, ?string, mixed}> */
    public static function omissionAndEmptyCases(): iterable
    {
        foreach (self::operations() as [$method, $arguments, $wire, $verb, $parameters, $payload]) {
            if ($payload !== null) {
                yield $wire . ' optional root fields' => [$method, $arguments, $wire, $verb, $parameters, '{}', []];
            }
        }
        yield 'counts selected user' => ['accountCounts', [''], 'acctcounts', 'GET', 'user=', '{}', []];
        yield 'ips empty user and list' => ['getResellerIps', [''], 'getresellerips', 'GET', 'user=',
            '{"all":1,"ip":[]}', ['all' => 1, 'ip' => []]];
        yield 'empty limits map' => ['listEnhancementLimits', ['reseller'], 'list_enhancement_limits', 'GET',
            'account=reseller', '{"limits":{}}', ['limits' => []]];
        yield 'optional limit fields' => ['listEnhancementLimits', ['reseller'], 'list_enhancement_limits', 'GET',
            'account=reseller', '{"limits":{"plugin":{}}}', ['limits' => ['plugin' => []]]];
        yield 'empty named ACLs' => ['listAcls', [], 'listacls', 'GET', '', '{"acl":[]}', ['acl' => []]];
        yield 'empty reseller list' => ['listResellers', [], 'listresellers', 'GET', '',
            '{"reseller":[]}', ['reseller' => []]];
        yield 'default statistics filters' => ['resellerStats', ['reseller'], 'resellerstats', 'GET',
            'user=reseller', '{"reseller":{"acct":[],"diskused":0}}', ['reseller' => ['acct' => [], 'diskused' => 0]]];
        yield 'omitted ACL flags' => ['saveAclList', ['basic'], 'saveacllist', 'POST', 'acllist=basic', 'null', null];
        yield 'unlimited enhancement omits limit' => ['setEnhancementLimit', ['reseller', 'plugin', false],
            'set_enhancement_limit', 'POST', 'account=reseller&id=plugin&limited=0', 'null', null];
        yield 'omitted public contact' => ['setPublicContact', [], 'set_public_contact', 'POST', '', 'null', null];
        yield 'named ACL assignment' => ['setAcls', ['reseller' => 'reseller', 'acllist' => 'basic'],
            'setacls', 'POST', 'reseller=reseller&acllist=basic', '{"acl":[]}', ['acl' => []]];
        yield 'IP omission clears list' => ['setResellerIps', ['reseller'], 'setresellerips', 'POST',
            'user=reseller', '{"ip":[]}', ['ip' => []]];
        yield 'empty IP list argument' => ['setResellerIps', ['reseller', true, ''], 'setresellerips', 'POST',
            'user=reseller&delegate=1&ips=', '{}', []];
        yield 'omitted resource limits' => ['setResellerLimits', ['reseller'], 'setresellerlimits', 'POST',
            'user=reseller', 'null', null];
        yield 'omitted nameservers restores defaults' => ['setResellerNameservers', ['reseller'],
            'setresellernameservers', 'POST', 'user=reseller', 'null', null];
        yield 'empty nameservers forwarded' => ['setResellerNameservers', ['reseller', ''],
            'setresellernameservers', 'POST', 'user=reseller&nameservers=', null, null];
        yield 'omitted package limits' => ['setResellerPackageLimit', ['reseller'], 'setresellerpackagelimit',
            'POST', 'user=reseller', 'null', null];
        yield 'omitted ownership default' => ['setupReseller', ['reseller'], 'setupreseller', 'POST',
            'user=reseller', 'null', null];
        yield 'default suspension includes owned' => ['suspendReseller', ['reseller'], 'suspendreseller', 'POST',
            'user=reseller', 'null', null];
        yield 'empty reason and reseller-only suspension' => ['suspendReseller', ['reseller', '', true, true],
            'suspendreseller', 'POST', 'user=reseller&reason=&disallow=1&reseller-only=1', null, null];
        yield 'empty termination results' => ['terminateReseller', ['reseller', true], 'terminatereseller', 'POST',
            'user=reseller&terminatereseller=1', '{"acct":[]}', ['acct' => []]];
        yield 'explicit null revoked status data' => ['unsetupReseller', ['reseller'], 'unsetupreseller', 'POST',
            'user=reseller', 'null', null];
        yield 'default unsuspension includes owned' => ['unsuspendReseller', ['reseller'], 'unsuspendreseller',
            'POST', 'user=reseller', 'null', null];
        yield 'explicit false unsuspension' => ['unsuspendReseller', ['reseller', false], 'unsuspendreseller',
            'POST', 'user=reseller&reseller-only=0', null, null];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('omissionAndEmptyCases')]
    public function testOmittedDefaultsRemainDistinctFromExplicitValues(
        string $method,
        array $arguments,
        string $wire,
        string $verb,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        $this->testSourceRequestsAndPayloads($method, $arguments, $wire, $verb, $parameters, $payload, $expected);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function accountLimitVariants(): iterable
    {
        yield 'native zero' => ['{"reseller":{"limit":0}}', ['reseller' => ['limit' => 0]]];
        yield 'native positive integer' => ['{"reseller":{"limit":25}}', ['reseller' => ['limit' => 25]]];
        yield 'explicit null' => ['{"reseller":{"limit":null}}', ['reseller' => ['limit' => null]]];
        yield 'observed exact empty string' => ['{"reseller":{"limit":""}}', ['reseller' => ['limit' => '']]];
        yield 'omitted limit' => ['{"reseller":{}}', ['reseller' => []]];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('accountLimitVariants')]
    public function testAccountLimitPreservesOnlyNativeNullableIntegerAndExactEmptyString(
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('acctcounts', $payload)));

        self::assertSame($expected, $client->whm()->resellers()->accountCounts());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>, string, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach (
            ['"0"', '"25"', '" "', '" 25"', '"25 "', '"0.00"', '"1.5"',
            '"unlimited"', '0.0', '1.5', 'true', 'false', '[]', '{}'] as $index => $bad
        ) {
            yield 'strict limit boundary ' . $index => ['accountCounts', [], 'acctcounts',
                '{"reseller":{"limit":' . $bad . '}}'];
        }
        foreach (['active', 'suspended'] as $field) {
            foreach (['""', '"0"', '"1"', 'false', '0.0'] as $index => $bad) {
                yield 'strict sibling ' . $field . ' ' . $index => ['accountCounts', [], 'acctcounts',
                    '{"reseller":{"' . $field . '":' . $bad . '}}'];
            }
        }
        foreach (self::operations() as [$method, $arguments, $wire, , , $payload]) {
            if ($payload === null) {
                continue;
            }
            foreach ([null, 'null', 'false', '1', '"wrong"', '[1]'] as $index => $bad) {
                yield $wire . ' root ' . $index => [$method, $arguments, $wire, $bad];
            }
        }
        $cases = [
            ['accountCounts', [], 'acctcounts', '{"reseller":{"active":"2"}}'],
            ['accountCounts', [], 'acctcounts', '{"reseller":{"limit":false}}'],
            ['accountCounts', [], 'acctcounts', '{"reseller":{"suspended":1.5}}'],
            ['accountCounts', [], 'acctcounts', '{"reseller":{"user":null}}'],
            ['getPublicContact', [], 'get_public_contact', '{"name":null}'],
            ['getPublicContact', [], 'get_public_contact', '{"url":1}'],
            ['getResellerIps', [], 'getresellerips', '{"all":"1"}'],
            ['getResellerIps', [], 'getresellerips', '{"all":2}'],
            ['getResellerIps', [], 'getresellerips', '{"ip":[1]}'],
            ['listEnhancementLimits', ['reseller'], 'list_enhancement_limits', '{"limits":{"plugin":{"limit":"1"}}}'],
            ['listEnhancementLimits', ['reseller'], 'list_enhancement_limits', '{"limits":{"plugin":{"limited":2}}}'],
            ['listEnhancementLimits', ['reseller'], 'list_enhancement_limits', '{"limits":{"plugin":{"usage":null}}}'],
            ['listEnhancementLimits', ['reseller'], 'list_enhancement_limits', '{"limits":{"plugin":false}}'],
            ['listAcls', [], 'listacls', '{"acl":[{"name":null}]}'],
            ['listAcls', [], 'listacls', '{"acl":[{"privileges":{"all":2}}]}'],
            ['listAcls', [], 'listacls', '{"acl":[{"privileges":{"passwd":"1"}}]}'],
            ['listResellers', [], 'listresellers', '{"reseller":[null]}'],
            ['resellerStats', ['reseller'], 'resellerstats', '{"reseller":{"diskused":"1.5"}}'],
            ['resellerStats', ['reseller'], 'resellerstats', '{"reseller":{"bandwidthlimit":"0"}}'],
            ['resellerStats', ['reseller'], 'resellerstats', '{"reseller":{"totalbwalloc":0}}'],
            ['resellerStats', ['reseller'], 'resellerstats', '{"reseller":{"acct":[{"diskquota":0}]}}'],
            ['resellerStats', ['reseller'], 'resellerstats', '{"reseller":{"acct":[{"deleted":true}]}}'],
            ['setAcls', ['reseller'], 'setacls', '{"acl":[1]}'],
            ['setResellerIps', ['reseller'], 'setresellerips', '{"all":"1"}'],
            ['setResellerIps', ['reseller'], 'setresellerips', '{"ip":[false]}'],
            ['terminateReseller', ['reseller', false], 'terminatereseller', '{"acct":[{"output":{"raw":1}}]}'],
            ['terminateReseller', ['reseller', false], 'terminatereseller', '{"acct":[{"reason":null}]}'],
            ['terminateReseller', ['reseller', false], 'terminatereseller', '{"acct":[{"result":"1"}]}'],
            ['terminateReseller', ['reseller', false], 'terminatereseller', '{"acct":[{"user":false}]}'],
        ];
        foreach ($cases as $index => $case) {
            yield 'leaf ' . $index => $case;
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedPayloads')]
    public function testPayloadMismatchRetainsConfirmedOutcomeAndDoesNotRetry(
        string $method,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('listresellers', '{"reseller":[]}')));
        $whm->resellers()->listResellers();
        $previous = $whm->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($wire, $payload)));
        try {
            $whm->resellers()->{$method}(...$arguments);
            self::fail('Malformed reseller payload must fail validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($wire, $error->metadata?->whm['command'] ?? null);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiFailureDoesNotRetry(
        string $method,
        array $arguments,
        string $wire,
        string $verb,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"' . $wire
            . '","reason":"denied","result":0,"version":1}}'));
        try {
            $client->whm()->resellers()->{$method}(...$arguments);
            self::fail('A failed API result must throw.');
        } catch (ApiErrorException) {
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
            self::assertSame('denied', $client->whm()->responseMeta()?->whm['reason'] ?? null);
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testEveryPinnedAclPrivilegeFieldSurvivesValidation(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'listacls',
            '{"acl":[{"name":"all-fields","privileges":{"acct-summary":0,"add-pkg":1,"add-pkg-ip":0,'
            . '"add-pkg-shell":1,"all":0,"allow-addoncreate":1,"allow-emaillimits-pkgs":0,'
            . '"allow-parkedcreate":1,"allow-shell":0,"allow-unlimited-bw-pkgs":1,'
            . '"allow-unlimited-disk-pkgs":0,"allow-unlimited-pkgs":1,"basic-system-info":0,'
            . '"basic-whm-functions":1,"clustering":0,"cors-proxy-get":1,"cpanel-api":0,'
            . '"cpanel-integration":1,"create-acct":0,"create-dns":1,"create-user-session":0,'
            . '"demo-setup":1,"digest-auth":0,"edit-account":1,"edit-dns":0,"edit-mx":1,"edit-pkg":0,'
            . '"frontpage":1,"generate-email-config":0,"kill-acct":1,"kill-dns":0,"limit-bandwidth":1,'
            . '"list-accts":0,"list-pkgs":1,"locale-edit":0,"mailcheck":1,"manage-api-tokens":0,'
            . '"manage-dns-records":1,"manage-oidc":0,"manage-styles":1,"mysql-info":0,"news":1,'
            . '"ns-config":0,"park-dns":1,"passwd":0,"quota":1,"rearrange-accts":0,"resftp":1,"restart":0,'
            . '"show-bandwidth":1,"ssl":0,"ssl-buy":1,"ssl-gencrt":0,"ssl-info":1,"stats":0,"status":1,'
            . '"suspend-acct":0,"thirdparty":1,"track-email":0,"upgrade-account":1,'
            . '"viewglobalpackages":0}}]}',
        )));
        self::assertSame(['acl' => [['name' => 'all-fields', 'privileges' => [
            'acct-summary' => 0,
            'add-pkg' => 1,
            'add-pkg-ip' => 0,
            'add-pkg-shell' => 1,
            'all' => 0,
            'allow-addoncreate' => 1,
            'allow-emaillimits-pkgs' => 0,
            'allow-parkedcreate' => 1,
            'allow-shell' => 0,
            'allow-unlimited-bw-pkgs' => 1,
            'allow-unlimited-disk-pkgs' => 0,
            'allow-unlimited-pkgs' => 1,
            'basic-system-info' => 0,
            'basic-whm-functions' => 1,
            'clustering' => 0,
            'cors-proxy-get' => 1,
            'cpanel-api' => 0,
            'cpanel-integration' => 1,
            'create-acct' => 0,
            'create-dns' => 1,
            'create-user-session' => 0,
            'demo-setup' => 1,
            'digest-auth' => 0,
            'edit-account' => 1,
            'edit-dns' => 0,
            'edit-mx' => 1,
            'edit-pkg' => 0,
            'frontpage' => 1,
            'generate-email-config' => 0,
            'kill-acct' => 1,
            'kill-dns' => 0,
            'limit-bandwidth' => 1,
            'list-accts' => 0,
            'list-pkgs' => 1,
            'locale-edit' => 0,
            'mailcheck' => 1,
            'manage-api-tokens' => 0,
            'manage-dns-records' => 1,
            'manage-oidc' => 0,
            'manage-styles' => 1,
            'mysql-info' => 0,
            'news' => 1,
            'ns-config' => 0,
            'park-dns' => 1,
            'passwd' => 0,
            'quota' => 1,
            'rearrange-accts' => 0,
            'resftp' => 1,
            'restart' => 0,
            'show-bandwidth' => 1,
            'ssl' => 0,
            'ssl-buy' => 1,
            'ssl-gencrt' => 0,
            'ssl-info' => 1,
            'stats' => 0,
            'status' => 1,
            'suspend-acct' => 0,
            'thirdparty' => 1,
            'track-email' => 0,
            'upgrade-account' => 1,
            'viewglobalpackages' => 0,
        ]]]], $client->whm()->resellers()->listAcls());
    }

    public function testEveryPinnedInlineAclUsesItsExactWireName(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('saveacllist', null)));
        $client->whm()->resellers()->saveAclList(
            acllist: 'reviewed',
            aclAcctSummary: false,
            aclAddPkg: true,
            aclAddPkgIp: false,
            aclAddPkgShell: true,
            aclAll: false,
            aclAllowAddoncreate: true,
            aclAllowEmaillimitsPkgs: false,
            aclAllowParkedcreate: true,
            aclAllowShell: false,
            aclAllowUnlimitedBwPkgs: true,
            aclAllowUnlimitedDiskPkgs: false,
            aclAllowUnlimitedPkgs: true,
            aclBasicSystemInfo: false,
            aclBasicWhmFunctions: true,
            aclClustering: false,
            aclCorsProxyGet: true,
            aclCpanelApi: false,
            aclCpanelIntegration: true,
            aclCreateAcct: false,
            aclCreateDns: true,
            aclCreateUserSession: false,
            aclDemoSetup: true,
            aclDigestAuth: false,
            aclEditAccount: true,
            aclEditDns: false,
            aclEditMx: true,
            aclEditPkg: false,
            aclGenerateEmailConfig: true,
            aclKillAcct: false,
            aclKillDns: true,
            aclLimitBandwidth: false,
            aclListAccts: true,
            aclListPkgs: false,
            aclLocaleEdit: true,
            aclMailcheck: false,
            aclManageApiTokens: true,
            aclManageDnsRecords: false,
            aclManageOidc: true,
            aclManageStyles: false,
            aclMysqlInfo: true,
            aclNews: false,
            aclNsConfig: true,
            aclParkDns: false,
            aclPasswd: false,
            aclQuota: true,
            aclRearrangeAccts: false,
            aclResftp: true,
            aclRestart: false,
            aclShowBandwidth: true,
            aclSsl: false,
            aclSslBuy: true,
            aclSslGencrt: false,
            aclSslInfo: true,
            aclStats: false,
            aclStatus: true,
            aclSuspendAcct: false,
            aclThirdparty: true,
            aclTrackEmail: false,
            aclUpgradeAccount: true,
            aclViewglobalpackages: false,
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'acllist=reviewed&acl-acct-summary=0&acl-add-pkg=1&acl-add-pkg-ip=0&acl-add-pkg-shell=1'
            . '&acl-all=0&acl-allow-addoncreate=1&acl-allow-emaillimits-pkgs=0&acl-allow-parkedcreate=1'
            . '&acl-allow-shell=0&acl-allow-unlimited-bw-pkgs=1&acl-allow-unlimited-disk-pkgs=0'
            . '&acl-allow-unlimited-pkgs=1&acl-basic-system-info=0&acl-basic-whm-functions=1'
            . '&acl-clustering=0&acl-cors-proxy-get=1&acl-cpanel-api=0&acl-cpanel-integration=1'
            . '&acl-create-acct=0&acl-create-dns=1&acl-create-user-session=0&acl-demo-setup=1&acl-digest-auth=0'
            . '&acl-edit-account=1&acl-edit-dns=0&acl-edit-mx=1&acl-edit-pkg=0&acl-generate-email-config=1'
            . '&acl-kill-acct=0&acl-kill-dns=1&acl-limit-bandwidth=0&acl-list-accts=1&acl-list-pkgs=0'
            . '&acl-locale-edit=1&acl-mailcheck=0&acl-manage-api-tokens=1&acl-manage-dns-records=0'
            . '&acl-manage-oidc=1&acl-manage-styles=0&acl-mysql-info=1&acl-news=0&acl-ns-config=1'
            . '&acl-park-dns=0&acl-passwd=0'
            . '&acl-quota=1&acl-rearrange-accts=0&acl-resftp=1&acl-restart=0&acl-show-bandwidth=1&acl-ssl=0'
            . '&acl-ssl-buy=1&acl-ssl-gencrt=0&acl-ssl-info=1&acl-stats=0&acl-status=1&acl-suspend-acct=0'
            . '&acl-thirdparty=1&acl-track-email=0&acl-upgrade-account=1&acl-viewglobalpackages=0',
            (string) $request->getBody(),
        );
        $http->addResponse(new Response(200, [], self::envelope('setacls', '{"acl":[]}')));
        self::assertSame(['acl' => []], $client->whm()->resellers()->setAcls(
            reseller: 'reseller',
            aclAcctSummary: true,
            aclAddPkg: true,
            aclAddPkgIp: true,
            aclAddPkgShell: true,
            aclAll: true,
            aclAllowAddoncreate: true,
            aclAllowParkedcreate: true,
            aclAllowUnlimitedBwPkgs: true,
            aclAllowUnlimitedDiskPkgs: true,
            aclAllowUnlimitedPkgs: true,
            aclBasicSystemInfo: true,
            aclBasicWhmFunctions: true,
            aclClustering: true,
            aclCorsProxyGet: true,
            aclCpanelApi: true,
            aclCpanelIntegration: true,
            aclCreateAcct: true,
            aclCreateDns: true,
            aclCreateUserSession: true,
            aclDemoSetup: true,
            aclDigestAuth: true,
            aclDisallowShell: true,
            aclEditAccount: true,
            aclEditDns: true,
            aclEditMx: true,
            aclEditPkg: true,
            aclGenerateEmailConfig: true,
            aclKillAcct: true,
            aclKillDns: true,
            aclLimitBandwidth: true,
            aclListAccts: true,
            aclListPkgs: true,
            aclLocaleEdit: true,
            aclMailcheck: true,
            aclManageApiTokens: true,
            aclManageDnsRecords: true,
            aclManageOidc: true,
            aclManageStyles: true,
            aclMysqlInfo: true,
            aclNameserverConfig: true,
            aclNews: true,
            aclParkDns: true,
            aclPasswd: true,
            aclQuota: true,
            aclRearrangeAccts: true,
            aclResftp: true,
            aclRestart: true,
            aclShowBandwidth: true,
            aclSsl: true,
            aclSslBuy: true,
            aclSslGencrt: true,
            aclSslInfo: true,
            aclStats: true,
            aclStatus: true,
            aclSuspendAcct: true,
            aclThirdparty: true,
            aclTrackEmail: true,
            aclUpgradeAccount: true,
            aclViewglobalpackages: true,
        ));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'reseller=reseller&acl-acct-summary=1&acl-add-pkg=1&acl-add-pkg-ip=1&acl-add-pkg-shell=1'
            . '&acl-all=1&acl-allow-addoncreate=1&acl-allow-parkedcreate=1&acl-allow-unlimited-bw-pkgs=1'
            . '&acl-allow-unlimited-disk-pkgs=1&acl-allow-unlimited-pkgs=1&acl-basic-system-info=1'
            . '&acl-basic-whm-functions=1&acl-clustering=1&acl-cors-proxy-get=1&acl-cpanel-api=1'
            . '&acl-cpanel-integration=1&acl-create-acct=1&acl-create-dns=1&acl-create-user-session=1'
            . '&acl-demo-setup=1&acl-digest-auth=1&acl-disallow-shell=1&acl-edit-account=1&acl-edit-dns=1'
            . '&acl-edit-mx=1&acl-edit-pkg=1&acl-generate-email-config=1&acl-kill-acct=1&acl-kill-dns=1'
            . '&acl-limit-bandwidth=1&acl-list-accts=1&acl-list-pkgs=1&acl-locale-edit=1&acl-mailcheck=1'
            . '&acl-manage-api-tokens=1&acl-manage-dns-records=1&acl-manage-oidc=1&acl-manage-styles=1'
            . '&acl-mysql-info=1&acl-nameserver-config=1&acl-news=1&acl-park-dns=1&acl-passwd=1&acl-quota=1'
            . '&acl-rearrange-accts=1&acl-resftp=1&acl-restart=1&acl-show-bandwidth=1&acl-ssl=1'
            . '&acl-ssl-buy=1&acl-ssl-gencrt=1&acl-ssl-info=1&acl-stats=1&acl-status=1&acl-suspend-acct=1'
            . '&acl-thirdparty=1&acl-track-email=1&acl-upgrade-account=1&acl-viewglobalpackages=1',
            (string) $request->getBody(),
        );
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{bool}> */
    public static function callerModes(): iterable
    {
        yield 'strict caller' => [false];
        yield 'weak caller' => [true];
    }

    public function testAssignmentSignatureHasExactly59NullableTrueSwitchesAndNoUserAlias(): void
    {
        self::assertCount(59, self::ASSIGNMENT_SWITCHES);
        $parameters = (new ReflectionMethod(Resellers::class, 'setAcls'))->getParameters();
        self::assertSame(
            ['reseller', ...self::ASSIGNMENT_SWITCHES, 'acllist'],
            array_map(static fn ($parameter): string => $parameter->getName(), $parameters),
        );
        foreach (array_slice($parameters, 1, 59) as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame('true', $type->getName(), $parameter->getName());
            self::assertTrue($type->allowsNull(), $parameter->getName());
            self::assertTrue($parameter->isOptional(), $parameter->getName());
            self::assertNull($parameter->getDefaultValue(), $parameter->getName());
        }
    }

    public function testSaveListKeepsAll60NullableBooleanSwitches(): void
    {
        $parameters = (new ReflectionMethod(Resellers::class, 'saveAclList'))->getParameters();
        self::assertCount(61, $parameters);
        self::assertSame('acllist', $parameters[0]->getName());
        foreach (array_slice($parameters, 1) as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame('bool', $type->getName(), $parameter->getName());
            self::assertTrue($type->allowsNull(), $parameter->getName());
            self::assertNull($parameter->getDefaultValue(), $parameter->getName());
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function nullableAssignmentSwitches(): iterable
    {
        foreach (self::ASSIGNMENT_SWITCHES as $parameter) {
            foreach (self::callerModes() as $mode => [$weak]) {
                yield $parameter . ' ' . $mode => [$parameter, $weak];
            }
        }
    }

    #[DataProvider('nullableAssignmentSwitches')]
    public function testEveryNullAssignmentSwitchIsOmitted(string $parameter, bool $weak): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('setacls', '{"acl":[]}')));
        self::assertSame(['acl' => []], self::call($client->whm()->resellers(), 'setAcls', [
            'reseller' => 'alice', $parameter => null,
        ], $weak));
        self::assertRequest($http, 'setacls', 'reseller=alice');
    }

    /** @return iterable<string, array{string, mixed, bool}> */
    public static function rejectedAssignmentValues(): iterable
    {
        foreach (self::ASSIGNMENT_SWITCHES as $parameter) {
            foreach (self::callerModes() as $mode => [$weak]) {
                foreach (
                    [
                    'false' => false, 'zero' => 0, 'one' => 1, 'negative' => -1, 'two' => 2,
                    'floating zero' => 0.0, 'floating one' => 1.0, 'quoted zero' => '0', 'quoted one' => '1',
                    'truthy string' => 'true', 'exponent string' => '1e0', 'empty string' => '', 'array' => [],
                    ] as $name => $value
                ) {
                    yield $parameter . ' ' . $mode . ' ' . $name => [$parameter, $value, $weak];
                }
            }
        }
    }

    #[DataProvider('rejectedAssignmentValues')]
    public function testAssignmentSwitchesRejectValuesBeforeHttpEvenForWeakCallers(
        string $parameter,
        mixed $value,
        bool $weak,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('setacls', '{"acl":[]}')));
        try {
            self::call($client->whm()->resellers(), 'setAcls', ['reseller' => 'alice', $parameter => $value], $weak);
            self::fail('Only true or null may be passed to an assignment switch.');
        } catch (TypeError) {
            self::assertCount(0, $http->getRequests());
        }
    }

    #[DataProvider('callerModes')]
    public function testAssignmentTrueGrantsAndSaveFalseRemovalStayDistinct(bool $weak): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('setacls', '{"acl":["all","list-accts"]}')));
        self::assertSame(['acl' => ['all', 'list-accts']], self::call($client->whm()->resellers(), 'setAcls', [
            'reseller' => 'alice', 'aclAll' => true, 'aclListAccts' => true, 'aclPasswd' => null,
        ], $weak));
        self::assertRequest($http, 'setacls', 'reseller=alice&acl-all=1&acl-list-accts=1');

        [$saveClient, $saveHttp] = self::client();
        $saveHttp->addResponse(new Response(200, [], self::envelope('saveacllist', null)));
        self::assertNull(self::call($saveClient->whm()->resellers(), 'saveAclList', [
            'acllist' => 'basic', 'aclAll' => false, 'aclListAccts' => true, 'aclPasswd' => null,
        ], $weak));
        self::assertRequest($saveHttp, 'saveacllist', 'acllist=basic&acl-all=0&acl-list-accts=1');
    }

    #[DataProvider('callerModes')]
    public function testUserAliasCannotOverrideRequiredReseller(bool $weak): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('setacls', '{"acl":[]}')));
        try {
            self::call($client->whm()->resellers(), 'setAcls', ['reseller' => 'alice', 'user' => 'bob'], $weak);
            self::fail('The overriding user alias must not be a generated parameter.');
        } catch (Error $error) {
            self::assertSame('Unknown named parameter $user', $error->getMessage());
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, list<mixed>, string, string, ?string, bool}> */
    public static function keyFirstCalls(): iterable
    {
        foreach (self::callerModes() as $mode => [$weak]) {
            yield 'suspension reason ' . $mode => ['suspendReseller', ['alice', 'Nonpayment +&='],
                'suspendreseller', 'user=alice&reason=Nonpayment%20%2B%26%3D', null, $weak];
            yield 'shared IP identity ' . $mode => ['setResellerMainIp', ['alice', '192.0.2.3'],
                'setresellermainip', 'user=alice&ip=192.0.2.3', null, $weak];
            yield 'termination retains reseller ' . $mode => ['terminateReseller', ['alice', false],
                'terminatereseller', 'user=alice&terminatereseller=0', '{"acct":[]}', $weak];
            yield 'termination includes reseller ' . $mode => ['terminateReseller', ['alice', true],
                'terminatereseller', 'user=alice&terminatereseller=1', '{"acct":[]}', $weak];
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('keyFirstCalls')]
    public function testPositionalCallsKeepIdentityAndSensitiveReasonInTheRightWireFields(
        string $method,
        array $arguments,
        string $wire,
        string $parameters,
        ?string $payload,
        bool $weak,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($wire, $payload)));
        self::assertSame(
            $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            self::call($client->whm()->resellers(), $method, $arguments, $weak),
        );
        self::assertRequest($http, $wire, $parameters);
    }

    public function testKeyFirstPublicParameterOrder(): void
    {
        foreach (
            [
            'suspendReseller' => ['user', 'reason', 'disallow', 'resellerOnly'],
            'setResellerMainIp' => ['user', 'ip'],
            'terminateReseller' => ['user', 'terminatereseller'],
            ] as $method => $expected
        ) {
            self::assertSame($expected, array_map(
                static fn ($parameter): string => $parameter->getName(),
                (new ReflectionMethod(Resellers::class, $method))->getParameters(),
            ));
        }
    }

    public function testRequiredParametersAndSensitiveReason(): void
    {
        $counts = ['accountCounts' => 0, 'getPublicContact' => 0, 'getResellerIps' => 0,
            'listEnhancementLimits' => 1, 'listAcls' => 0, 'listResellers' => 0, 'resellerStats' => 1,
            'saveAclList' => 1, 'setEnhancementLimit' => 3, 'setPublicContact' => 0, 'setAcls' => 1,
            'setResellerIps' => 1, 'setResellerLimits' => 1, 'setResellerMainIp' => 2,
            'setResellerNameservers' => 1, 'setResellerPackageLimit' => 1, 'setupReseller' => 1,
            'suspendReseller' => 1, 'terminateReseller' => 2, 'unsetupReseller' => 1, 'unsuspendReseller' => 1];
        foreach ($counts as $method => $count) {
            self::assertSame(
                $count,
                (new ReflectionMethod(Resellers::class, $method))->getNumberOfRequiredParameters(),
            );
        }
        $reason = (new ReflectionMethod(Resellers::class, 'suspendReseller'))->getParameters()[1];
        self::assertSame('reason', $reason->getName());
        self::assertCount(1, $reason->getAttributes(SensitiveParameter::class));
    }

    /** @param array<array-key, mixed> $arguments */
    private static function call(Resellers $group, string $method, array $arguments, bool $weak): mixed
    {
        if ($weak) {
            return WeakResellerCaller::call($group, $method, $arguments);
        }

        return $group->{$method}(...$arguments);
    }

    private static function assertRequest(MockClient $http, string $wire, string $body): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $wire . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame($body, (string) $request->getBody());
        self::assertCount(1, $http->getRequests());
    }

    private static function envelope(string $wire, ?string $payload): string
    {
        return '{"metadata":{"command":"' . $wire . '","reason":"OK","result":1,"version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory)), $http];
    }
}
