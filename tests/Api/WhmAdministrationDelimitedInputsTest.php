<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Authentication;
use HostingPal\WhmApiClient\Api\Whm\Backups;
use HostingPal\WhmApiClient\Api\Whm\IpAddresses;
use HostingPal\WhmApiClient\Api\Whm\Mail;
use HostingPal\WhmApiClient\Api\Whm\ModSecurity;
use HostingPal\WhmApiClient\Api\Whm\Resellers;
use HostingPal\WhmApiClient\Api\Whm\Server;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Offline synthetic fixtures: no network, key, account, mail or server actions occur. */
final class WhmAdministrationDelimitedInputsTest extends TestCase
{
    public function testAddIpsExcludesAcceptsProtectedOptionalListsAfterNetmask(): void
    {
        self::assertSignature(IpAddresses::class, 'addIps', 2, 'excludes', true, ['ips', 'netmask', 'excludes']);
    }

    public function testSshOptionsAcceptsProtectedOptionalListsAfterExistingSensitiveText(): void
    {
        self::assertSignature(Authentication::class, 'authorizeSshKey', 3, 'options', true, [
            'authorize', 'file', 'text', 'options',
        ]);
        self::assertCount(1, (new ReflectionMethod(Authentication::class, 'authorizeSshKey'))
            ->getParameters()[2]->getAttributes(SensitiveParameter::class));
    }

    public function testBackupdaysKeepsItsSpellingAndAllNeighboringPositions(): void
    {
        self::assertSignature(Backups::class, 'backupConfigSet', 10, 'backupdays', true, [
            'backupDailyEnable', 'backupDailyRetention', 'backupMonthlyDates', 'backupMonthlyEnable',
            'backupMonthlyRetention', 'backupWeeklyDay', 'backupWeeklyEnable', 'backupWeeklyRetention',
            'backupaccts', 'backupbwdata', 'backupdays', 'backupdir', 'backupenable', 'backupfiles', 'backuplogs',
            'backupmount', 'backupsuspendedaccts', 'backuptype', 'checkMinFreeSpace', 'disableMetadata',
            'errorthreshhold', 'forcePruneDaily', 'forcePruneMonthly', 'forcePruneWeekly', 'gziprsyncopts', 'keeplocal',
            'linkdest', 'localzonesonly', 'maximumRestoreTimeout', 'maximumTimeout', 'minFreeSpace', 'minFreeSpaceUnit',
            'mysqlbackup', 'postbackup', 'prebackup', 'psqlbackup', 'remoteRestoreStagingDir',
        ]);
        $parameters = (new ReflectionMethod(Backups::class, 'backupConfigSet'))->getParameters();
        self::assertSame('string|int|null', (string) $parameters[2]->getType());
        self::assertSame('?int', (string) $parameters[5]->getType());
    }

    public function testEximAltportnumAcceptsProtectedOptionalListsBeforeMonitored(): void
    {
        self::assertSignature(Server::class, 'configureService', 2, 'eximAltportnum', true, [
            'service', 'enabled', 'eximAltportnum', 'monitored',
        ]);
    }

    public function testIpv6DisableUserAcceptsProtectedRequiredLists(): void
    {
        self::assertSignature(IpAddresses::class, 'disableIpv6Account', 0, 'user', false, ['user']);
    }

    public function testIpv6EnableUserAcceptsProtectedRequiredListsAfterRange(): void
    {
        self::assertSignature(IpAddresses::class, 'enableIpv6Account', 1, 'user', false, ['range', 'user']);
        self::assertSame('string', (string) (new ReflectionMethod(IpAddresses::class, 'enableIpv6Account'))
            ->getParameters()[0]->getType());
    }

    public function testModSecurityConfigAcceptsProtectedOptionalListsAtFirstPosition(): void
    {
        self::assertSignature(ModSecurity::class, 'getRules', 0, 'config', true, [
            'config', 'excludeBareComments', 'excludeOtherDirectives', 'vendorId',
        ]);
    }

    public function testModSecurityVendorIdAcceptsProtectedOptionalListsAfterFlags(): void
    {
        self::assertSignature(ModSecurity::class, 'getRules', 3, 'vendorId', true, [
            'config', 'excludeBareComments', 'excludeOtherDirectives', 'vendorId',
        ]);
    }

    public function testAllowedIpsAcceptsProtectedOptionalListsBeforeSpamdLimits(): void
    {
        self::assertSignature(Mail::class, 'saveSpamdConfig', 0, 'allowedIps', true, [
            'allowedIps', 'maxChildren', 'maxConnPerChild', 'pidFile', 'timeoutChild', 'timeoutTcp',
        ]);
    }

    public function testResellerIpsAcceptsProtectedOptionalListsAfterUserAndDelegate(): void
    {
        self::assertSignature(Resellers::class, 'setResellerIps', 2, 'ips', true, ['user', 'delegate', 'ips']);
    }

    public function testResellerNameserversAcceptsProtectedOptionalListsAfterUser(): void
    {
        self::assertSignature(Resellers::class, 'setResellerNameservers', 1, 'nameservers', true, [
            'user', 'nameservers',
        ]);
    }

    /** @return iterable<string, array{string, string, string, int, string}> */
    public static function adopters(): iterable
    {
        yield 'IPv4 exclusions' => ['ipAddresses', 'addIps', 'excludes', 2, 'addips'];
        yield 'SSH options' => ['authentication', 'authorizeSshKey', 'options', 3, 'authorizesshkey'];
        yield 'backup days' => ['backups', 'backupConfigSet', 'backupdays', 10, 'backup_config_set'];
        yield 'Exim ports' => ['server', 'configureService', 'eximAltportnum', 2, 'configureservice'];
        yield 'IPv6 disable users' => ['ipAddresses', 'disableIpv6Account', 'user', 0, 'ipv6_disable_account'];
        yield 'IPv6 enable users' => ['ipAddresses', 'enableIpv6Account', 'user', 1, 'ipv6_enable_account'];
        yield 'ModSecurity configs' => ['modSecurity', 'getRules', 'config', 0, 'modsec_get_rules'];
        yield 'ModSecurity vendors' => ['modSecurity', 'getRules', 'vendorId', 3, 'modsec_get_rules'];
        yield 'spamd addresses' => ['mail', 'saveSpamdConfig', 'allowedIps', 0, 'save_spamd_config'];
        yield 'reseller IPs' => ['resellers', 'setResellerIps', 'ips', 2, 'setresellerips'];
        yield 'reseller nameservers' => ['resellers', 'setResellerNameservers', 'nameservers', 1,
            'setresellernameservers'];
    }

    /** @return iterable<string, array{string, string, string, int, string}> */
    public static function optionalPositions(): iterable
    {
        foreach (self::adopters() as $name => $position) {
            if (!in_array($position[1], ['disableIpv6Account', 'enableIpv6Account'], true)) {
                yield $name => $position;
            }
        }
    }

    #[DataProvider('adopters')]
    public function testListsAndRawScalarsPreserveLiteralBytesAndOneRequestPerCall(
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($method);
        $cases = [
            [['b', 'a', 'b'], 'b%2Ca%2Cb'],
            ['b,a,b', 'b%2Ca%2Cb'],
            ['from="a,b",%2C|', 'from%3D%22a%2Cb%22%2C%252C%7C'],
            [['%2C', '|'], '%252C%2C%7C'],
            [['', 'a', ''], '%2Ca%2C'],
            [[" a+&=%2C/雪\n\0\xFF", '|'], '%20a%2B%26%3D%252C%2F%E9%9B%AA%0A%00%FF%2C%7C'],
            ['', ''],
            [[''], ''],
        ];
        foreach ($cases as $index => [$value, $encoded]) {
            self::assertSame(self::fixtureResult($method), self::invoke(
                $handle,
                $group,
                $method,
                [$parameter => $value],
                true,
            ));
            self::assertRequest($http, $operation, self::fields($method, $parameter, $encoded, true));
            self::assertCount($index + 1, $http->getRequests());
            self::assertSame(1, $handle->responseMeta()?->whmResult());
            if ($method === 'addIps') {
                self::assertAddressOutput($handle);
            }
        }
    }

    #[DataProvider('optionalPositions')]
    public function testAllOptionalOmissionsAndExplicitEmptyValuesRemainDistinctFromEmptyLists(
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($method);
        foreach ([[], [$parameter => null], [$parameter => ''], [$parameter => ['']]] as $index => $arguments) {
            self::assertSame(self::fixtureResult($method), self::invoke($handle, $group, $method, $arguments));
            self::assertRequest($http, $operation, self::fields($method, $parameter, $index < 2 ? null : ''));
            self::assertCount($index + 1, $http->getRequests());
        }
        $old = $handle->responseMeta();
        self::assertNotNull($old);
        $raw = $old->whm;
        try {
            self::invoke($handle, $group, $method, [$parameter => []]);
            self::fail('Empty list must not become omission or select server defaults.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertSame($raw, $old->whm);
            self::assertCount(4, $http->getRequests());
        }
    }

    #[DataProvider('adopters')]
    public function testInvalidEnteredListsClearMetadataBeforeIoAndProtectActualArgumentFrames(
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($method);
        $malformed = match ($parameter . ':' . $method) {
            'excludes:addIps' => [1 => 'synthetic-private-sparse'],
            'options:authorizeSshKey' => ['key' => 'synthetic-private-keyed'],
            'backupdays:backupConfigSet' => ['synthetic-private-first', 0],
            'eximAltportnum:configureService' => ['synthetic-private-first', 26],
            'user:disableIpv6Account' => ['synthetic-private-first', null],
            'user:enableIpv6Account' => ['synthetic-private-first', false],
            'config:getRules' => ['synthetic-private-first', 1.5],
            'vendorId:getRules' => ['synthetic-private-first', ['synthetic-private-nested']],
            default => ['synthetic-private-first', new \stdClass()],
        };
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ([[], ['synthetic-private-first', 'synthetic-private-late,'], $malformed] as $value) {
                self::invoke($handle, $group, $method, [$parameter => ['seed']], true);
                $old = $handle->responseMeta();
                self::assertNotNull($old);
                $raw = $old->whm;
                $count = count($http->getRequests());
                try {
                    self::invoke($handle, $group, $method, [$parameter => $value], true);
                    self::fail('Invalid entered list accepted for ' . $operation);
                } catch (InvalidArgumentException $error) {
                    self::assertNull($handle->responseMeta());
                    self::assertSame($raw, $old->whm);
                    self::assertCount($count, $http->getRequests());
                    self::assertStringNotContainsString('synthetic-private-', $error->getMessage());
                    $seen = false;
                    foreach ($error->getTrace() as $frame) {
                        $class = $frame['class'] ?? '';
                        if ($class === self::group($handle, $group)::class) {
                            self::assertSame($method, $frame['function']);
                            self::assertInstanceOf(
                                SensitiveParameterValue::class,
                                ($frame['args'] ?? [])[$position] ?? null,
                            );
                            if ($method === 'authorizeSshKey') {
                                self::assertInstanceOf(SensitiveParameterValue::class, ($frame['args'] ?? [])[2]);
                            }
                            $seen = true;
                        }
                        if (
                            str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                            && !str_contains($class, '\\Tests\\')
                        ) {
                            self::assertFalse(self::containsPrivateValue($frame['args'] ?? []));
                        }
                    }
                    self::assertTrue($seen, 'Inspect the generated public method frame.');
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    #[DataProvider('adopters')]
    public function testNativeWrongTypesKeepTheOldMetadataBeforePublicMethodEntry(
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($method);
        self::invoke($handle, $group, $method, [$parameter => 'seed']);
        $old = $handle->responseMeta();
        self::assertNotNull($old);
        $raw = $old->whm;
        $wrong = in_array($method, ['disableIpv6Account', 'enableIpv6Account'], true)
            ? [null, 0, false, 1.5, new \stdClass()] : [0, false];
        foreach ($wrong as $value) {
            try {
                self::invoke($handle, $group, $method, [$parameter => $value]);
                self::fail('Native wrong type accepted for ' . $operation);
            } catch (TypeError) {
                self::assertSame($old, $handle->responseMeta());
                self::assertSame($raw, $old->whm);
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    public function testIpv6PositionalAndNamedCallsAndSshQuotedScalarKeepTheirAliases(): void
    {
        [$handle, $http] = self::client('enableIpv6Account');
        self::assertSame(self::fixtureResult('enableIpv6Account'), $handle->ipAddresses()
            ->enableIpv6Account('SHARED', ['u2', 'u1']));
        self::assertRequest($http, 'ipv6_enable_account', 'range=SHARED&user=u2%2Cu1');
        self::assertCount(1, $http->getRequests());
        $handle->ipAddresses()->enableIpv6Account(user: ['u1', 'u2'], range: 'fixture-range');
        self::assertRequest($http, 'ipv6_enable_account', 'range=fixture-range&user=u1%2Cu2');
        self::assertCount(2, $http->getRequests());
        [$handle, $http] = self::client('disableIpv6Account');
        $handle->ipAddresses()->disableIpv6Account(['u2', 'u1']);
        self::assertRequest($http, 'ipv6_disable_account', 'user=u2%2Cu1');
        self::assertCount(1, $http->getRequests());
        $handle->ipAddresses()->disableIpv6Account(user: ['u1', 'u2']);
        self::assertRequest($http, 'ipv6_disable_account', 'user=u1%2Cu2');
        self::assertCount(2, $http->getRequests());
        [$handle, $http] = self::client('authorizeSshKey');
        $handle->authentication()->authorizeSshKey(false, 'fixture-key', 'synthetic-key-text', ['no-pty', 'restrict']);
        self::assertRequest(
            $http,
            'authorizesshkey',
            'authorize=0&file=fixture-key&text=synthetic-key-text&options=no-pty%2Crestrict',
        );
        self::assertCount(1, $http->getRequests());
        $handle->authentication()->authorizeSshKey(
            options: 'from="host1,host2",no-pty',
            file: 'fixture-key',
            authorize: false,
        );
        self::assertRequest(
            $http,
            'authorizesshkey',
            'authorize=0&file=fixture-key&options=from%3D%22host1%2Chost2%22%2Cno-pty',
        );
        self::assertCount(2, $http->getRequests());
        try {
            $handle->authentication()->authorizeSshKey(false, 'fixture-key', options: ['no-pty', 'from="host1,host2"']);
            self::fail('Quoted commas inside list items remain ambiguous.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertCount(2, $http->getRequests());
        }
    }

    public function testModSecurityBothSelectorsRemainOneGetWithIndependentFieldsAndFalseFlags(): void
    {
        [$handle, $http] = self::client('getRules');
        self::assertSame(self::fixtureResult('getRules'), $handle->modSecurity()->getRules(
            config: ['a.conf', 'b.conf'],
            excludeBareComments: false,
            excludeOtherDirectives: false,
            vendorId: ['v2', 'v1'],
        ));
        self::assertRequest(
            $http,
            'modsec_get_rules',
            'config=a.conf%2Cb.conf&exclude_bare_comments=0&exclude_other_directives=0&vendor_id=v2%2Cv1',
        );
        self::assertCount(1, $http->getRequests());
        $handle->modSecurity()->getRules(vendorId: ['v1']);
        self::assertRequest($http, 'modsec_get_rules', 'vendor_id=v1');
        self::assertCount(2, $http->getRequests());
        $handle->modSecurity()->getRules(config: ['a.conf']);
        self::assertRequest($http, 'modsec_get_rules', 'config=a.conf');
        self::assertCount(3, $http->getRequests());
        try {
            $handle->modSecurity()->getRules(config: ['good.conf'], vendorId: ['v1', 'late,v2']);
            self::fail('A later invalid selector must abort the complete request.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertCount(3, $http->getRequests());
        }
    }

    public function testDayAndPortListsAreStringsWithoutCoercionAndAdjacentOptionsStayUnchanged(): void
    {
        [$handle, $http] = self::client('backupConfigSet');
        $handle->backups()->backupConfigSet(
            backupDailyEnable: false,
            backupDailyRetention: 0,
            backupMonthlyDates: 0,
            backupWeeklyDay: 0,
            backupdays: ['0', '2', '6'],
            disableMetadata: false,
            forcePruneDaily: false,
            keeplocal: false,
            maximumRestoreTimeout: 0,
            maximumTimeout: 0,
        );
        self::assertRequest($http, 'backup_config_set', 'backup_daily_enable=0&backup_daily_retention=0'
            . '&backup_monthly_dates=0&backup_weekly_day=0&backupdays=0%2C2%2C6&disable_metadata=0'
            . '&force_prune_daily=0&keeplocal=0&maximum_restore_timeout=0&maximum_timeout=0');
        self::assertCount(1, $http->getRequests());
        $handle->backups()->backupConfigSet(backupMonthlyDates: '1,15', backupdays: ['0']);
        self::assertRequest($http, 'backup_config_set', 'backup_monthly_dates=1%2C15&backupdays=0');
        self::assertCount(2, $http->getRequests());
        try {
            self::invoke($handle, 'backups', 'backupConfigSet', ['backupdays' => [0, 2, 6]]);
            self::fail('Integer day lists must not be coerced.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertCount(2, $http->getRequests());
        }
        [$handle, $http] = self::client('configureService');
        $handle->server()->configureService('exim-altport', false, ['26', '5000', '6000'], false);
        self::assertRequest(
            $http,
            'configureservice',
            'service=exim-altport&enabled=0&exim-altportnum=26%2C5000%2C6000&monitored=0',
        );
        self::assertCount(1, $http->getRequests());
        try {
            self::invoke($handle, 'server', 'configureService', ['eximAltportnum' => ['26', 5000]]);
            self::fail('Integer port list items must not be coerced.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string|null, mixed}> */
    public static function acceptedResponses(): iterable
    {
        foreach (
            ['addIps', 'backupConfigSet', 'configureService', 'saveSpamdConfig',
            'setResellerNameservers'] as $method
        ) {
            yield "$method metadata only" => [$method, null, null];
        }
        yield 'SSH authorized zero' => ['authorizeSshKey', '{"authorized":0,"file":"fixture-key"}',
            ['authorized' => 0, 'file' => 'fixture-key']];
        yield 'SSH optional fields absent' => ['authorizeSshKey', '{}', []];
        yield 'IPv6 disable absent clean success' => ['disableIpv6Account', null, null];
        yield 'IPv6 disable null clean success' => ['disableIpv6Account', 'null', null];
        yield 'IPv6 disable optional fields absent' => ['disableIpv6Account', '{}', []];
        yield 'IPv6 disable mixed reasons' => ['disableIpv6Account',
            '{"fail_cnt":2,"failures":[{"name":"u2","reason":{"code":7}},{"reason":null}]}',
            ['fail_cnt' => 2, 'failures' => [['name' => 'u2', 'reason' => ['code' => 7]], ['reason' => null]]]];
        yield 'IPv6 enable keyed maps' => ['enableIpv6Account',
            '{"fail_cnt":1,"failures":{"u2":"fixture failure"},"ipv6":{"u1":"2001:db8::2"}}',
            ['fail_cnt' => 1, 'failures' => ['u2' => 'fixture failure'], 'ipv6' => ['u1' => '2001:db8::2']]];
        yield 'IPv6 enable optional fields absent' => ['enableIpv6Account', '{}', []];
        yield 'ModSecurity optional fields absent' => ['getRules', '{}', []];
        yield 'ModSecurity empty chunks and flags' => ['getRules', '{"chunks":[],"staged_changes":0}',
            ['chunks' => [], 'staged_changes' => 0]];
        yield 'reseller all remains integer including zero' => ['setResellerIps', '{"all":0,"ip":["b","a"]}',
            ['all' => 0, 'ip' => ['b', 'a']]];
        yield 'reseller empty IP list' => ['setResellerIps', '{"all":1,"ip":[]}', ['all' => 1, 'ip' => []]];
        yield 'reseller optional fields absent' => ['setResellerIps', '{}', []];
    }

    #[DataProvider('acceptedResponses')]
    public function testListCallsPreserveExistingResponseShapesAndMetadata(
        string $method,
        ?string $payload,
        mixed $expected,
    ): void {
        [$handle, $http] = self::client($method);
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, self::invokeList($handle, $method));
        self::assertSame(1, $handle->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
        if ($method === 'addIps') {
            self::assertAddressOutput($handle);
        }
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function malformedResponses(): iterable
    {
        foreach (['authorizeSshKey', 'enableIpv6Account', 'getRules', 'setResellerIps'] as $method) {
            yield "$method missing payload" => [$method, null];
            yield "$method null payload" => [$method, 'null'];
        }
        yield 'SSH boolean flag' => ['authorizeSshKey', '{"authorized":false}'];
        yield 'SSH wrong file' => ['authorizeSshKey', '{"file":0}'];
        yield 'disable string count' => ['disableIpv6Account', '{"fail_cnt":"0"}'];
        yield 'disable wrong name' => ['disableIpv6Account', '{"failures":[{"name":0}]}'];
        yield 'enable string count' => ['enableIpv6Account', '{"fail_cnt":"1"}'];
        yield 'enable nonstring map failure' => ['enableIpv6Account', '{"failures":{"u":0}}'];
        yield 'enable nonstring address' => ['enableIpv6Account', '{"ipv6":{"u":null}}'];
        yield 'rules null vendor ID' => ['getRules', '{"chunks":[{"vendor_id":null}]}'];
        yield 'rules string flag' => ['getRules', '{"chunks":[{"config_active":"1"}]}'];
        yield 'rules boolean flag' => ['getRules', '{"chunks":[{"disabled":false}]}'];
        yield 'rules string ID' => ['getRules', '{"chunks":[{"id":"7"}]}'];
        yield 'rules nonbinary flag' => ['getRules', '{"staged_changes":2}'];
        yield 'reseller string all' => ['setResellerIps', '{"all":"1"}'];
        yield 'reseller nonstring IP' => ['setResellerIps', '{"ip":[1]}'];
    }

    #[DataProvider('malformedResponses')]
    public function testListCallsKeepStrictPresentResponseTypesAndMissingPayloadErrors(
        string $method,
        ?string $payload,
    ): void {
        [$handle, $http] = self::client($method);
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            self::invokeList($handle, $method);
            self::fail('Malformed payload must retain the existing mismatch contract.');
        } catch (PayloadMismatchException) {
            self::assertNotNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('adopters')]
    public function testApiFailureWinsOverValidListCallPayloadWithoutRetry(
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($method);
        $result = self::fixtureResult($method);
        $payload = $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR);
        $http->addResponse(new Response(200, [], self::envelope($payload, 0)));
        try {
            self::invoke($handle, $group, $method, [$parameter => ['a']]);
            self::fail('WHM failure must win over a valid payload for ' . $operation);
        } catch (ApiErrorException) {
            self::assertSame(0, $handle->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @param array<string, mixed> $arguments */
    private static function invoke(
        WhmClient $handle,
        string $group,
        string $method,
        array $arguments,
        bool $neighbors = false,
    ): mixed {
        $required = match ($method) {
            'addIps' => ['ips' => '192.0.2.0/28', 'netmask' => '255.255.255.240'],
            'authorizeSshKey' => ['authorize' => false, 'file' => 'fixture-key'],
            'enableIpv6Account' => ['range' => 'SHARED'],
            'configureService' => ['service' => 'exim-altport'],
            'setResellerIps', 'setResellerNameservers' => ['user' => 'fixture-reseller'],
            default => [],
        };
        $optional = !$neighbors ? [] : match ($method) {
            'authorizeSshKey' => ['text' => 'synthetic-private-text'],
            'backupConfigSet' => ['backupDailyEnable' => false, 'backupDailyRetention' => 0,
                'backupMonthlyDates' => '1,15', 'backupWeeklyDay' => 0, 'disableMetadata' => false],
            'configureService' => ['enabled' => false, 'monitored' => false],
            'getRules' => ['excludeBareComments' => false, 'excludeOtherDirectives' => false],
            'saveSpamdConfig' => ['maxChildren' => 0, 'maxConnPerChild' => 0, 'pidFile' => '',
                'timeoutChild' => 0, 'timeoutTcp' => 0],
            'setResellerIps' => ['delegate' => false],
            default => [],
        };
        return self::group($handle, $group)->$method(...($arguments + $required + $optional));
    }

    private static function invokeList(WhmClient $handle, string $method): mixed
    {
        foreach (self::adopters() as [$group, $candidate, $parameter]) {
            if ($candidate === $method) {
                return self::invoke($handle, $group, $method, [$parameter => ['fixture']]);
            }
        }
        throw new LogicException('Unknown fixture method.');
    }

    private static function group(
        WhmClient $handle,
        string $group,
    ): Authentication|Backups|IpAddresses|Mail|ModSecurity|Resellers|Server {
        return match ($group) {
            'authentication' => $handle->authentication(),
            'backups' => $handle->backups(),
            'ipAddresses' => $handle->ipAddresses(),
            'mail' => $handle->mail(),
            'modSecurity' => $handle->modSecurity(),
            'resellers' => $handle->resellers(),
            'server' => $handle->server(),
            default => throw new LogicException('Unknown fixture group.'),
        };
    }

    private static function fields(string $method, string $parameter, ?string $encoded, bool $neighbors = false): string
    {
        $field = $encoded === null ? '' : match ($parameter) {
            'eximAltportnum' => 'exim-altportnum=' . $encoded,
            'vendorId' => 'vendor_id=' . $encoded,
            'allowedIps' => 'allowedips=' . $encoded,
            default => $parameter . '=' . $encoded,
        };
        $prefix = match ($method) {
            'addIps' => 'ips=192.0.2.0%2F28&netmask=255.255.255.240',
            'authorizeSshKey' => 'authorize=0&file=fixture-key'
                . ($neighbors ? '&text=synthetic-private-text' : ''),
            'backupConfigSet' => $neighbors
                ? 'backup_daily_enable=0&backup_daily_retention=0&backup_monthly_dates=1%2C15&backup_weekly_day=0' : '',
            'configureService' => 'service=exim-altport' . ($neighbors ? '&enabled=0' : ''),
            'enableIpv6Account' => 'range=SHARED',
            'getRules' => $neighbors && $parameter === 'vendorId'
                ? 'exclude_bare_comments=0&exclude_other_directives=0' : '',
            'setResellerIps' => 'user=fixture-reseller' . ($neighbors ? '&delegate=0' : ''),
            'setResellerNameservers' => 'user=fixture-reseller',
            default => '',
        };
        $suffix = !$neighbors ? '' : match ($method) {
            'backupConfigSet' => '&disable_metadata=0',
            'configureService' => '&monitored=0',
            'getRules' => $parameter === 'config' ? '&exclude_bare_comments=0&exclude_other_directives=0' : '',
            'saveSpamdConfig' => '&maxchildren=0&maxconnperchild=0&pidfile=&timeoutchild=0&timeouttcp=0',
            default => '',
        };
        return $prefix . ($prefix !== '' && $field !== '' ? '&' : '') . $field . $suffix;
    }

    private static function fixtureResult(string $method): mixed
    {
        return match ($method) {
            'authorizeSshKey' => ['authorized' => 0, 'file' => 'fixture-key'],
            'disableIpv6Account' => ['fail_cnt' => 1, 'failures' => [['name' => 'u2', 'reason' => ['code' => 7]]]],
            'enableIpv6Account' => ['fail_cnt' => 1, 'failures' => ['u2' => 'fixture failure'],
                'ipv6' => ['u1' => '2001:db8::2']],
            'getRules' => ['chunks' => [['config' => 'fixture.conf', 'config_active' => 1, 'disabled' => 0,
                'id' => 7, 'meta_msg' => 'fixture message', 'rule' => "SecRule ARGS test\n# fixture line",
                'staged' => 0, 'vendor_active' => 1, 'vendor_id' => '']], 'staged_changes' => 0],
            'setResellerIps' => ['all' => 1, 'ip' => ['192.0.2.2', '192.0.2.1']],
            default => null,
        };
    }

    /** @return array{WhmClient, MockClient} */
    private static function client(string $method): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $result = self::fixtureResult($method);
        $payload = $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR);
        $http->setDefaultResponse(new Response(200, [], self::envelope($payload)));
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$client->whm(), $http];
    }

    private static function envelope(?string $payload, int $result = 1): string
    {
        return '{"metadata":{"result":' . $result . ',"reason":"fixture reason",'
            . '"output":{"messages":["fixture address output"]}}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    private static function assertRequest(MockClient $http, string $operation, string $fields): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $get = $operation === 'modsec_get_rules';
        self::assertSame($get ? 'GET' : 'POST', $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
            . ($get && $fields !== '' ? '&' . $fields : ''), (string) $request->getUri());
        self::assertSame($get ? '' : $fields, (string) $request->getBody());
        self::assertSame($get ? '' : 'application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    private static function assertAddressOutput(WhmClient $handle): void
    {
        $metadata = $handle->responseMeta();
        self::assertNotNull($metadata);
        self::assertIsArray($metadata->whm);
        self::assertArrayHasKey('output', $metadata->whm);
        $output = $metadata->whm['output'];
        self::assertIsArray($output);
        self::assertSame(['fixture address output'], $output['messages'] ?? null);
    }

    private static function containsPrivateValue(mixed $value): bool
    {
        if ($value instanceof SensitiveParameterValue) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (self::containsPrivateValue($key) || self::containsPrivateValue($item)) {
                    return true;
                }
            }
        }
        return is_string($value) && str_contains($value, 'synthetic-private-');
    }

    /** @param class-string $class
     * @param list<string> $names
     */
    private static function assertSignature(
        string $class,
        string $method,
        int $position,
        string $name,
        bool $optional,
        array $names,
    ): void {
        $reflection = new ReflectionMethod($class, $method);
        $parameters = $reflection->getParameters();
        self::assertSame($names, array_map(static fn ($parameter) => $parameter->getName(), $parameters));
        $parameter = $parameters[$position];
        self::assertSame($name, $parameter->getName());
        self::assertSame($optional ? 'array|string|null' : 'array|string', (string) $parameter->getType());
        self::assertSame($optional, $parameter->allowsNull());
        self::assertSame($optional, $parameter->isDefaultValueAvailable());
        if ($optional) {
            self::assertNull($parameter->getDefaultValue());
        }
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        self::assertStringContainsString(
            '@param string|non-empty-list<string>' . ($optional ? '|null' : '') . ' $' . $name,
            $reflection->getDocComment() ?: '',
        );
    }
}
