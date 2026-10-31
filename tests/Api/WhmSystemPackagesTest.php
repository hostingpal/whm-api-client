<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\SystemPackages;
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

final class WhmSystemPackagesTest extends TestCase
{
    public function testSelectorCachesWithoutDispatch(): void
    {
        [$client, $http] = self::client();
        self::assertInstanceOf(SystemPackages::class, $client->whm()->systemPackages());
        self::assertSame($client->whm()->systemPackages(), $client->whm()->systemPackages());
        self::assertSame([], $http->getRequests());
        self::assertNull($client->whm()->responseMeta());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>, string, string}> */
    public static function operations(): iterable
    {
        yield 'delete RPM data' => ['deleteRpmVersion', 'delete_rpm_version', 'POST',
            ['key' => '3rdparty', 'section' => 'rpm_groups', 'value' => 'cpanel-pcre'],
            'key=3rdparty&section=rpm_groups&value=cpanel-pcre', '{"status":0}'];
        yield 'edit RPM data' => ['editRpmVersion', 'edit_rpm_version', 'POST',
            ['key' => '3rdparty', 'section' => 'rpm_groups', 'value' => 'rpm &value'],
            'key=3rdparty&section=rpm_groups&value=rpm%20%26value', '{"status":1}'];
        yield 'RPM key data' => ['getRpmVersionData', 'get_rpm_version_data', 'GET',
            ['section' => 'rpm_groups', 'key' => '3rdparty'], 'section=rpm_groups&key=3rdparty',
            '{"3rdparty":["cpanel-pcre","cpanel-pixman"]}'];
        yield 'install plugin' => ['installRpmPlugin', 'install_rpm_plugin', 'POST', ['name' => 'plugin &name'],
            'name=plugin%20%26name', '{"log_entry":"synthetic-install","pid":1234}'];
        yield 'missing RPM inventory' => ['listRpms', 'list_rpms', 'GET', ['target' => '3rdparty'],
            'target=3rdparty', '{"rpms":["cpanel-pcre","cpanel-pixman"]}'];
        yield 'cache repair' => ['fixCache', 'package_manager_fixcache', 'POST', [], '', '{"cache_seems_ok_now":1}'];
        yield 'build log' => ['getBuildLog', 'package_manager_get_build_log', 'GET', ['build' => 1234, 'offset' => 0],
            'build=1234&offset=0', '{"content":["synthetic log","second line"],"offset":9672,"still_running":0}'];
        yield 'package info' => ['getPackageInfo', 'package_manager_get_package_info', 'GET',
            ['namespaces' => ['ea', 'altea'], 'disableExcludes' => false],
            'ns=ea&ns=altea&disable-excludes=0',
            '{"packages":[{"architecture":"x86_64","license":"ASL 2.0","long_description":"Synthetic description",'
            . '"more_info_url":null,"package":"ea-apr-util","pkg_dep":{"conflicts":[],"requires":["glibc"]},'
            . '"pkg_group":"System Environment/Libraries","release":"7.7.1.cpanel","repo_name":"NYI",'
            . '"short_description":"Utility library","size":214647,"state":"updatable","version":"1.6.1",'
            . '"version_installed":"1.6.1-4.4.8.cpanel","version_latest":"1.6.1-7.7.1.cpanel"}]}'];
        yield 'build activity' => ['isPerformingActions', 'package_manager_is_performing_actions', 'GET',
            ['build' => 10053], 'build=10053', '{"active":0}'];
        yield 'installed packages' => ['listPackages', 'package_manager_list_packages', 'GET', ['state' => 'installed'],
            'state=installed', '{"packages":[{"package":"MariaDB-client"},{"package":"MariaDB-common"}]}'];
        yield 'resolve actions' => ['resolveActions', 'package_manager_resolve_actions', 'GET',
            ['packages' => ['ea-php55', 'ea-apr-util'], 'namespaces' => ['ea', 'altea']],
            'package=ea-php55&package=ea-apr-util&ns=ea&ns=altea',
            '{"install":["ea-apache24-mod_mpm_event"],"unaffected":["ea-php70-php-devel"],'
            . '"uninstall":["ea-apache24-mod_cgi"],"upgrade":["ea-php56"]}'];
        yield 'submit actions' => ['submitActions', 'package_manager_submit_actions', 'POST',
            ['install' => ['pkg &one', 'pkg2'], 'uninstall' => ['pkg3', 'pkg4'], 'upgrade' => ['pkg5', 'pkg6']],
            'install=pkg%20%26one&install=pkg2&uninstall=pkg3&uninstall=pkg4&upgrade=pkg5&upgrade=pkg6',
            '{"build":9717}'];
        yield 'upgrade server packages' => ['upgrade', 'package_manager_upgrade', 'POST', [], '', '{"build":10111}'];
        yield 'uninstall plugin' => ['uninstallRpmPlugin', 'uninstall_rpm_plugin', 'POST', ['name' => 'plugin_name'],
            'name=plugin_name', '{"log_entry":"synthetic-uninstall","pid":1234}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testEveryOperationUsesItsPinnedTransportAndPopulatedResult(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->systemPackages()->{$method}(...$arguments),
        );
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>, string, string}> */
    public static function optionalCases(): iterable
    {
        yield 'all RPM categories' => ['getRpmVersionData', 'get_rpm_version_data', 'GET', ['section' => 'rpm_groups'],
            'section=rpm_groups', '{"rpm_groups":{"3rdparty":["cpanel-pcre"],"empty":[]}}'];
        yield 'empty RPM map' => ['getRpmVersionData', 'get_rpm_version_data', 'GET', ['section' => 'rpm_groups'],
            'section=rpm_groups', '{}'];
        yield 'empty nested RPM map' => ['getRpmVersionData', 'get_rpm_version_data', 'GET',
            ['section' => 'rpm_groups'],
            'section=rpm_groups', '{"rpm_groups":{}}'];
        yield 'RPM target omitted' => ['listRpms', 'list_rpms', 'GET', [], '', '{"rpms":[]}'];
        yield 'RPM target explicit null' => ['listRpms', 'list_rpms', 'GET', ['target' => null], '', '{}'];
        yield 'package state omitted' => ['listPackages', 'package_manager_list_packages', 'GET', [], '',
            '{"packages":[]}'];
        yield 'package selector' => ['getPackageInfo', 'package_manager_get_package_info', 'GET',
            ['packages' => ['ea-php55', 'ea-apr-util'], 'disableExcludes' => true],
            'package=ea-php55&package=ea-apr-util&disable-excludes=1', '{"packages":[{},'
            . '{"more_info_url":"https://example.test/","pkg_dep":{"conflicts":["pkg"],"requires":[]}}]}'];
        yield 'scalar namespace' => ['getPackageInfo', 'package_manager_get_package_info', 'GET',
            ['namespaces' => 'altea'], 'ns=altea', '{"packages":[]}'];
        yield 'scalar package' => ['getPackageInfo', 'package_manager_get_package_info', 'GET',
            ['packages' => 'ea-apr-util'], 'package=ea-apr-util', '{}'];
        yield 'resolve namespace omitted' => ['resolveActions', 'package_manager_resolve_actions', 'GET',
            ['packages' => 'ea-apr-util'], 'package=ea-apr-util',
            '{"install":[],"unaffected":[],"uninstall":[],"upgrade":[]}'];
        yield 'submit omitted actions' => ['submitActions', 'package_manager_submit_actions', 'POST', [], '', '{}'];
        yield 'submit scalar actions' => ['submitActions', 'package_manager_submit_actions', 'POST',
            ['install' => 'pkg1', 'uninstall' => 'pkg2', 'upgrade' => 'pkg3'],
            'install=pkg1&uninstall=pkg2&upgrade=pkg3', '{"build":0}'];
        yield 'submit null actions omitted' => ['submitActions', 'package_manager_submit_actions', 'POST',
            ['install' => null, 'uninstall' => null, 'upgrade' => null], '', '{}'];
        yield 'empty log and active process' => ['getBuildLog', 'package_manager_get_build_log', 'GET',
            ['build' => 1234, 'offset' => 0], 'build=1234&offset=0', '{"content":[],"offset":0,"still_running":1}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('optionalCases')]
    public function testOptionalDefaultsEmptyListsAndSelectorAlternatives(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->systemPackages()->{$method}(...$arguments),
        );
        self::assertRequest($http, $operation, $verb, $wire);
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string|null}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operations() as $name => [$method, $operation, , $arguments]) {
            foreach ([null, 'null', 'false', '1', '"text"', '[1]'] as $payload) {
                if ($method === 'listRpms' && ($payload === null || $payload === 'null')) {
                    continue;
                }
                yield $name . ' root ' . ($payload ?? 'missing') => [$method, $operation, $arguments, $payload];
            }
        }
        yield 'RPM status boolean' => ['deleteRpmVersion', 'delete_rpm_version', ['key' => 'k',
            'section' => 's', 'value' => 'v'], '{"status":false}'];
        yield 'RPM status string' => ['editRpmVersion', 'edit_rpm_version', ['key' => 'k',
            'section' => 's', 'value' => 'v'], '{"status":"1"}'];
        yield 'RPM map scalar' => ['getRpmVersionData', 'get_rpm_version_data', ['section' => 's'], '{"k":"rpm"}'];
        yield 'RPM map numeric member' => ['getRpmVersionData', 'get_rpm_version_data', ['section' => 's'],
            '{"k":[1]}'];
        yield 'RPM nested map null' => ['getRpmVersionData', 'get_rpm_version_data', ['section' => 's'],
            '{"s":{"k":null}}'];
        yield 'RPM map mixes flat and nested alternatives' => ['getRpmVersionData', 'get_rpm_version_data',
            ['section' => 's'], '{"flat":["rpm"],"nested":{"k":["rpm"]}}'];
        yield 'RPM map exceeds documented nesting' => ['getRpmVersionData', 'get_rpm_version_data',
            ['section' => 's'], '{"s":{"k":{"extra":["rpm"]}}}'];
        yield 'plugin PID string' => ['installRpmPlugin', 'install_rpm_plugin', ['name' => 'p'], '{"pid":"1234"}'];
        yield 'plugin log null' => ['uninstallRpmPlugin', 'uninstall_rpm_plugin', ['name' => 'p'],
            '{"log_entry":null}'];
        yield 'missing RPM numeric' => ['listRpms', 'list_rpms', [], '{"rpms":[1]}'];
        yield 'missing RPM list null' => ['listRpms', 'list_rpms', [], '{"rpms":null}'];
        yield 'cache flag string' => ['fixCache', 'package_manager_fixcache', [], '{"cache_seems_ok_now":"1"}'];
        yield 'log content string' => ['getBuildLog', 'package_manager_get_build_log', ['build' => 1, 'offset' => 0],
            '{"content":"line"}'];
        yield 'log offset string' => ['getBuildLog', 'package_manager_get_build_log', ['build' => 1, 'offset' => 0],
            '{"offset":"0"}'];
        yield 'log running boolean' => ['getBuildLog', 'package_manager_get_build_log', ['build' => 1, 'offset' => 0],
            '{"still_running":true}'];
        yield 'package size string' => ['getPackageInfo', 'package_manager_get_package_info', ['namespaces' => 'ea'],
            '{"packages":[{"size":"214647"}]}'];
        yield 'package dependency numeric' => ['getPackageInfo', 'package_manager_get_package_info',
            ['namespaces' => 'ea'],
            '{"packages":[{"pkg_dep":{"requires":[1]}}]}'];
        yield 'package URL boolean' => ['getPackageInfo', 'package_manager_get_package_info', ['namespaces' => 'ea'],
            '{"packages":[{"more_info_url":false}]}'];
        yield 'activity out of range' => ['isPerformingActions', 'package_manager_is_performing_actions',
            ['build' => 1],
            '{"active":2}'];
        yield 'package rows map' => ['listPackages', 'package_manager_list_packages', [],
            '{"packages":{"package":"p"}}'];
        yield 'package name numeric' => ['listPackages', 'package_manager_list_packages', [],
            '{"packages":[{"package":1}]}'];
        yield 'action list scalar' => ['resolveActions', 'package_manager_resolve_actions', ['packages' => 'p'],
            '{"install":"p"}'];
        yield 'action numeric member' => ['resolveActions', 'package_manager_resolve_actions', ['packages' => 'p'],
            '{"uninstall":[1]}'];
        yield 'submitted build string' => ['submitActions', 'package_manager_submit_actions', [], '{"build":"9717"}'];
        yield 'upgraded build null' => ['upgrade', 'package_manager_upgrade', [], '{"build":null}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadRetainsConfirmedOutcomeAndDoesNotRetry(
        string $method,
        string $operation,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $client->whm()->systemPackages()->{$method}(...$arguments);
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
    public function testApiFailuresAreSeparateFromPayloadFlagsAndNeverRetried(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload, 0)));
        try {
            $client->whm()->systemPackages()->{$method}(...$arguments);
            self::fail('Expected an API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame(['Synthetic package failure'], $error->errors());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function malformedRepeatedInputs(): iterable
    {
        yield 'empty namespaces' => ['getPackageInfo', ['namespaces' => []]];
        yield 'nonsequential packages' => ['getPackageInfo', ['packages' => [1 => 'p']]];
        yield 'numeric package' => ['resolveActions', ['packages' => [1]]];
        yield 'empty required packages' => ['resolveActions', ['packages' => []]];
        yield 'empty install' => ['submitActions', ['install' => []]];
        yield 'boolean uninstall' => ['submitActions', ['uninstall' => [false]]];
        yield 'nested upgrade' => ['submitActions', ['upgrade' => [['p']]]];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedRepeatedInputs')]
    public function testMalformedRepeatedInputsFailBeforeDispatch(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        try {
            $client->whm()->systemPackages()->{$method}(...$arguments);
            self::fail('Expected an invalid repeated parameter.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->getRequests());
            self::assertNull($client->whm()->responseMeta());
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testOptionalResponseFieldsStayAbsent(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
        self::assertSame([], $client->whm()->systemPackages()->{$method}(...$arguments));
    }

    /** @return iterable<string, array{string|null}> */
    public static function optionalRpmPayloads(): iterable
    {
        yield 'observed metadata-only success' => [null];
        yield 'existing optional-payload null boundary, unobserved live' => ['null'];
    }

    #[DataProvider('optionalRpmPayloads')]
    public function testMissingRpmPayloadReturnsNullWithoutInferringAnEmptyInventory(?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('list_rpms', $payload)));
        self::assertNull($client->whm()->systemPackages()->listRpms());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertRequest($http, 'list_rpms', 'GET', '');
        self::assertCount(1, $http->getRequests());
    }

    public function testCacheFailureWithMetadataOnlyIsAnApiError(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('package_manager_fixcache', null, 0)));
        try {
            $client->whm()->systemPackages()->fixCache();
            self::fail('Expected the documented cache API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testInvalidRepeatedInputClearsMetadataFromThePreviousCall(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('list_rpms', '{"rpms":[]}')));
        $client->whm()->systemPackages()->listRpms();
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        try {
            $client->whm()->systemPackages()->submitActions(install: []);
            self::fail('Expected invalid repeated input.');
        } catch (\InvalidArgumentException) {
            self::assertNull($client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
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
            . ($status === 1 ? 'OK' : 'Synthetic package failure') . '","result":' . $status . ',"version":1}'
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
