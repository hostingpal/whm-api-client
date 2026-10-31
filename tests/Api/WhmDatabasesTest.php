<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

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

/** Independent literal fixtures for the 26 form-compatible pinned WHM Databases operations. */
final class WhmDatabasesTest extends TestCase
{
    public function testSelectorIsLazyAndCachedAndJsonConfigurationIsNotExposed(): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        self::assertSame($whm->databases(), $whm->databases());
        self::assertSame([], $http->getRequests());
        self::assertNull($whm->responseMeta());
        self::assertNotContains('updateSqlConfig', get_class_methods($whm->databases()));
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    #[DataProvider('operations')]
    public function testExactRequestsAndPinnedPayloads(
        string $operation,
        string $method,
        array $arguments,
        string $verb,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));

        self::assertSame($expected, $whm->databases()->{$method}(...$arguments));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
                . ($verb === 'GET' && $parameters !== '' ? '&' . $parameters : ''),
            (string) $request->getUri(),
        );
        self::assertSame($verb === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        $metadata = $whm->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->whmResult());
        self::assertSame($operation, $metadata->whm['command'] ?? null);
        self::assertNotNull($metadata->whm);
        self::assertArrayNotHasKey('data', $metadata->whm);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string, mixed}> */
    public static function operations(): iterable
    {
        yield 'background check is a POST, its PID is not completion' => [
            'background_mysql_upgrade_checker_run', 'backgroundMysqlUpgradeCheckerRun', [], 'POST', '',
            '{"log_entry":"mysql_upgrade.synthetic","pid":123,"future":true}',
            ['log_entry' => 'mysql_upgrade.synthetic', 'pid' => 123],
        ];
        yield 'background status' => [
            'background_mysql_upgrade_status', 'backgroundMysqlUpgradeStatus', ['mysql_upgrade.synthetic'], 'GET',
            'upgrade_id=mysql_upgrade.synthetic',
            '{"error":-1,"error_log":"synthetic failure","log":"synthetic progress","state":"in progress"}',
            ['error' => -1, 'error_log' => 'synthetic failure', 'log' => 'synthetic progress',
                'state' => 'in progress'],
        ];
        yield 'current version' => [
            'current_mysql_version', 'currentMysqlVersion', [], 'GET', '',
            '{"server":"mysql","version":"8.0"}', ['server' => 'mysql', 'version' => '8.0'],
        ];
        yield 'optimizations' => [
            'get_database_optimizations', 'getDatabaseOptimizations', [], 'GET', '',
            '{"payload":[{}, {"name":"innodb_sort_buffer_size","reason":"synthetic reason","value":"2M"}]}',
            ['payload' => [[], ['name' => 'innodb_sort_buffer_size', 'reason' => 'synthetic reason', 'value' => '2M']]],
        ];
        yield 'installable versions' => [
            'installable_mysql_versions', 'installableMysqlVersions', [], 'GET', '',
            '{"versions":[{}, {"server":"mariadb","version":"10.11"}]}',
            ['versions' => [[], ['server' => 'mariadb', 'version' => '10.11']]],
        ];
        yield 'latest version' => [
            'latest_available_mysql_version', 'latestAvailableMysqlVersion', [], 'GET', '',
            '{"server":"mariadb","version":"10.11"}', ['server' => 'mariadb', 'version' => '10.11'],
        ];
        yield 'database users' => [
            'list_database_users', 'listDatabaseUsers', [], 'GET', '',
            '{"payload":[{}, {"cpuser":"example","engine":"postgresql","name":"example_user"}]}',
            ['payload' => [[], ['cpuser' => 'example', 'engine' => 'postgresql', 'name' => 'example_user']]],
        ];
        yield 'databases' => [
            'list_databases', 'listDatabases', [], 'GET', '',
            '{"payload":[{"cpuser":"example","engine":"mysql","name":"example_db"}]}',
            ['payload' => [['cpuser' => 'example', 'engine' => 'mysql', 'name' => 'example_db']]],
        ];
        yield 'account database map retains database names and empty user lists' => [
            'list_mysql_databases_and_users', 'listMysqlDatabasesAndUsers', ['example'], 'GET', 'user=example',
            '{"mysql_config":{"mysql-version":"8.0","prefix_length":8,"use_db_prefix":0},'
                . '"mysql_databases":{"example_one":["example_user"],"example_empty":[]}}',
            ['mysql_config' => ['mysql-version' => '8.0', 'prefix_length' => 8, 'use_db_prefix' => 0],
                'mysql_databases' => ['example_one' => ['example_user'], 'example_empty' => []]],
        ];
        yield 'create profile with explicit zero and empty string' => [
            'remote_mysql_create_profile', 'remoteMysqlCreateProfile',
            ['db.example.test', 'synthetic p&=word', 3306, 'dbuser', 'fixture', 0, ''], 'POST',
            'mysql_host=db.example.test&mysql_pass=synthetic%20p%26%3Dword&mysql_port=3306&mysql_user=dbuser'
                . '&name=fixture&cpcloud=0&setup_via=',
            '{"profile_details":{"active":0,"cpcloud":0,"mysql_host":"db.example.test",'
                . '"mysql_pass":"synthetic p&=word","mysql_port":3306,"mysql_user":"dbuser",'
                . '"setup_via":""},"profile_saved":"fixture"}',
            ['profile_details' => ['active' => 0, 'cpcloud' => 0, 'mysql_host' => 'db.example.test',
                'mysql_pass' => 'synthetic p&=word', 'mysql_port' => 3306, 'mysql_user' => 'dbuser', 'setup_via' => ''],
                'profile_saved' => 'fixture'],
        ];
        yield 'SSH profile with both documented credential alternatives forwarded' => [
            'remote_mysql_create_profile_via_ssh', 'remoteMysqlCreateProfileViaSsh',
            ['host' => 'db.example.test', 'name' => 'fixture', 'port' => 22, 'user' => 'operator', 'cpcloud' => 0,
                'password' => 'synthetic ssh', 'rootEscalationMethod' => 'su', 'rootPassword' => 'synthetic root',
                'sshkeyName' => 'fixture-key', 'sshkeyPassphrase' => 'synthetic phrase'], 'POST',
            'host=db.example.test&name=fixture&port=22&user=operator&cpcloud=0&password=synthetic%20ssh'
                . '&root_escalation_method=su&root_password=synthetic%20root&sshkey_name=fixture-key'
                . '&sshkey_passphrase=synthetic%20phrase',
            '{"profile_details":{"cpcloud":1,"mysql_host":"db.example.test","mysql_pass":"synthetic",'
                . '"mysql_port":3306,"mysql_user":"root","setup_via":"Created via SSH"},"profile_saved":"fixture"}',
            ['profile_details' => ['cpcloud' => 1, 'mysql_host' => 'db.example.test', 'mysql_pass' => 'synthetic',
                'mysql_port' => 3306, 'mysql_user' => 'root', 'setup_via' => 'Created via SSH'],
                'profile_saved' => 'fixture'],
        ];
        yield 'delete profile retains returned credential' => [
            'remote_mysql_delete_profile', 'remoteMysqlDeleteProfile', ['fixture'], 'POST', 'name=fixture',
            '{"profile_deleted":"fixture","profile_details":{"active":0,"mysql_host":"db.example.test",'
                . '"mysql_pass":"synthetic","mysql_port":3306,"mysql_user":"root","setup_via":"fixture"}}',
            ['profile_deleted' => 'fixture', 'profile_details' => ['active' => 0, 'mysql_host' => 'db.example.test',
                'mysql_pass' => 'synthetic', 'mysql_port' => 3306, 'mysql_user' => 'root', 'setup_via' => 'fixture']],
        ];
        yield 'activation returns job id' => [
            'remote_mysql_initiate_profile_activation', 'remoteMysqlInitiateProfileActivation', ['fixture'], 'POST',
            'name=fixture', '{"activation_job_started":123}', ['activation_job_started' => 123],
        ];
        yield 'activation monitor has independently optional job fields' => [
            'remote_mysql_monitor_profile_activation', 'remoteMysqlMonitorProfileActivation', [], 'GET', '',
            '{"job_in_progress":{"profile_name":"fixture","start_time":0,"status":"INPROGRESS",'
                . '"steps":[{}, {"end_time":1,"name":"synthetic","start_time":0,"status":"DONE"}]},'
                . '"last_job_details":{"end_time":2,"profile_name":"prior","start_time":1,"status":"FAILED",'
                . '"steps":[{"end_time":2,"name":"synthetic","start_time":1,"status":"FAILED"}]}}',
            ['job_in_progress' => ['profile_name' => 'fixture', 'start_time' => 0, 'status' => 'INPROGRESS',
                'steps' => [[], ['end_time' => 1, 'name' => 'synthetic', 'start_time' => 0, 'status' => 'DONE']]],
                'last_job_details' => ['end_time' => 2, 'profile_name' => 'prior', 'start_time' => 1,
                    'status' => 'FAILED', 'steps' => [['end_time' => 2, 'name' => 'synthetic',
                        'start_time' => 1, 'status' => 'FAILED']]]],
        ];
        yield 'read profile is POST despite its name' => [
            'remote_mysql_read_profile', 'remoteMysqlReadProfile', ['fixture'], 'POST', 'name=fixture',
            '{"profile_details":{"active":1,"mysql_host":"db.example.test","mysql_pass":"synthetic",'
                . '"mysql_port":3306,"mysql_user":"root","setup_via":"fixture"},"profile_name":"fixture"}',
            ['profile_details' => ['active' => 1, 'mysql_host' => 'db.example.test', 'mysql_pass' => 'synthetic',
                'mysql_port' => 3306, 'mysql_user' => 'root', 'setup_via' => 'fixture'], 'profile_name' => 'fixture'],
        ];
        yield 'read profiles is POST and preserves profile keys' => [
            'remote_mysql_read_profiles', 'remoteMysqlReadProfiles', [], 'POST', '',
            '{"fixture":{"active":1,"is_localhost_profile":0,"mysql_host":"db.example.test",'
                . '"mysql_pass":"synthetic","mysql_port":3306,"mysql_user":"root",'
                . '"mysql_version_is_supported":1,"setup_via":"fixture","future":1},"empty":{}}',
            ['fixture' => ['active' => 1, 'is_localhost_profile' => 0, 'mysql_host' => 'db.example.test',
                'mysql_pass' => 'synthetic', 'mysql_port' => 3306, 'mysql_user' => 'root',
                'mysql_version_is_supported' => 1, 'setup_via' => 'fixture'], 'empty' => []],
        ];
        yield 'update profile preserves empty input' => [
            'remote_mysql_update_profile', 'remoteMysqlUpdateProfile',
            ['fixture', 'db.example.test', 'synthetic', 3306, 'root', ''], 'POST',
            'name=fixture&mysql_host=db.example.test&mysql_pass=synthetic&mysql_port=3306&mysql_user=root&setup_via=',
            '{"profile_details":{"active":0,"mysql_host":"db.example.test","mysql_pass":"synthetic",'
                . '"mysql_port":3306,"mysql_user":"root","setup_via":""},"profile_saved":"fixture"}',
            ['profile_details' => ['active' => 0, 'mysql_host' => 'db.example.test', 'mysql_pass' => 'synthetic',
                'mysql_port' => 3306, 'mysql_user' => 'root', 'setup_via' => ''], 'profile_saved' => 'fixture'],
        ];
        yield 'validate profile is POST' => [
            'remote_mysql_validate_profile', 'remoteMysqlValidateProfile', ['fixture'], 'POST', 'name=fixture',
            '{"profile_validated":"fixture"}', ['profile_validated' => 'fixture'],
        ];
        yield 'rename mysql database' => [
            'rename_mysql_database', 'renameMysqlDatabase', ['example_new', 'example_old', 'example'], 'POST',
            'newname=example_new&oldname=example_old&cpuser=example', null, null,
        ];
        yield 'rename mysql user' => [
            'rename_mysql_user', 'renameMysqlUser', ['example_new', 'example_old'], 'POST',
            'newname=example_new&oldname=example_old', null, null,
        ];
        yield 'rename postgresql database' => [
            'rename_postgresql_database', 'renamePostgresqlDatabase', ['example_new', 'example_old'], 'POST',
            'newname=example_new&oldname=example_old', null, null,
        ];
        yield 'rename postgresql user' => [
            'rename_postgresql_user', 'renamePostgresqlUser', ['example_new', 'example_old', 'synthetic &', 'example'],
            'POST', 'newname=example_new&oldname=example_old&password=synthetic%20%26&cpuser=example', null, null,
        ];
        yield 'root password preserves false update flag' => [
            'set_local_mysql_root_password', 'setLocalMysqlRootPassword', ['synthetic &', false], 'POST',
            'password=synthetic%20%26&update_config=0',
            '{"configs_updated":0,"password_reset":1,"profile_updated":0}',
            ['configs_updated' => 0, 'password_reset' => 1, 'profile_updated' => 0],
        ];
        yield 'mysql password' => [
            'set_mysql_password', 'setMysqlPassword', ['synthetic &', 'example_user', 'example'], 'POST',
            'password=synthetic%20%26&user=example_user&cpuser=example', null, null,
        ];
        yield 'postgresql password' => [
            'set_postgresql_password', 'setPostgresqlPassword', ['synthetic &', 'example_user'], 'POST',
            'password=synthetic%20%26&user=example_user', null, null,
        ];
        yield 'start upgrade preserves numeric version and only returns id' => [
            'start_background_mysql_upgrade', 'startBackgroundMysqlUpgrade', [5.7], 'POST', 'version=5.7',
            '{"upgrade_id":"mysql_upgrade.synthetic"}', ['upgrade_id' => 'mysql_upgrade.synthetic'],
        ];
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function objectOperations(): iterable
    {
        yield 'checker' => ['background_mysql_upgrade_checker_run', 'backgroundMysqlUpgradeCheckerRun', []];
        yield 'status' => ['background_mysql_upgrade_status', 'backgroundMysqlUpgradeStatus', ['fixture']];
        yield 'current' => ['current_mysql_version', 'currentMysqlVersion', []];
        yield 'optimizations' => ['get_database_optimizations', 'getDatabaseOptimizations', []];
        yield 'installable' => ['installable_mysql_versions', 'installableMysqlVersions', []];
        yield 'latest' => ['latest_available_mysql_version', 'latestAvailableMysqlVersion', []];
        yield 'users' => ['list_database_users', 'listDatabaseUsers', []];
        yield 'databases' => ['list_databases', 'listDatabases', []];
        yield 'account' => ['list_mysql_databases_and_users', 'listMysqlDatabasesAndUsers', ['example']];
        yield 'create' => [
            'remote_mysql_create_profile',
            'remoteMysqlCreateProfile',
            ['host', 'synthetic', 3306, 'u', 'p'],
        ];
        yield 'ssh' => [
            'remote_mysql_create_profile_via_ssh',
            'remoteMysqlCreateProfileViaSsh',
            ['host', 'p', 22, 'root'],
        ];
        yield 'delete' => ['remote_mysql_delete_profile', 'remoteMysqlDeleteProfile', ['fixture']];
        yield 'activate' => [
            'remote_mysql_initiate_profile_activation',
            'remoteMysqlInitiateProfileActivation',
            ['fixture'],
        ];
        yield 'monitor' => ['remote_mysql_monitor_profile_activation', 'remoteMysqlMonitorProfileActivation', []];
        yield 'read' => ['remote_mysql_read_profile', 'remoteMysqlReadProfile', ['fixture']];
        yield 'read all' => ['remote_mysql_read_profiles', 'remoteMysqlReadProfiles', []];
        yield 'update' => ['remote_mysql_update_profile', 'remoteMysqlUpdateProfile', ['fixture']];
        yield 'validate' => ['remote_mysql_validate_profile', 'remoteMysqlValidateProfile', ['fixture']];
        yield 'root password' => ['set_local_mysql_root_password', 'setLocalMysqlRootPassword', ['synthetic']];
        yield 'upgrade' => ['start_background_mysql_upgrade', 'startBackgroundMysqlUpgrade', [8.0]];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('objectOperations')]
    public function testOptionalFieldsRemainAbsentAndMissingOrNullPayloadsFail(
        string $operation,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
        $emptyObject = $whm->databases()->{$method}(...$arguments);
        // The established PHP object/map boundary also accepts an empty JSON array.
        $http->addResponse(new Response(200, [], self::envelope($operation, '[]')));
        $emptyArray = $whm->databases()->{$method}(...$arguments);
        self::assertSame([[], []], [$emptyObject, $emptyArray]);
        foreach ([null, 'null', '[1]', 'false', '"text"', '1'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
            try {
                $whm->databases()->{$method}(...$arguments);
                self::fail('Missing, null or nonobject payload must fail.');
            } catch (PayloadMismatchException $exception) {
                self::assertSame($exception->metadata, $whm->responseMeta());
                self::assertSame(1, $whm->responseMeta()?->whmResult());
            }
        }
        self::assertCount(8, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string}> */
    public static function malformedPayloads(): iterable
    {
        yield 'pid string' => [
            'background_mysql_upgrade_checker_run',
            'backgroundMysqlUpgradeCheckerRun',
            [],
            '{"pid":"1"}',
        ];
        yield 'log path null' => [
            'background_mysql_upgrade_checker_run',
            'backgroundMysqlUpgradeCheckerRun',
            [],
            '{"log_entry":null}',
        ];
        yield 'error string' => [
            'background_mysql_upgrade_status',
            'backgroundMysqlUpgradeStatus',
            ['fixture'],
            '{"error":"0"}',
        ];
        yield 'error log boolean' => [
            'background_mysql_upgrade_status',
            'backgroundMysqlUpgradeStatus',
            ['fixture'],
            '{"error_log":false}',
        ];
        yield 'version number' => ['current_mysql_version', 'currentMysqlVersion', [], '{"version":8.0}'];
        yield 'engine null' => ['latest_available_mysql_version', 'latestAvailableMysqlVersion', [], '{"server":null}'];
        yield 'optimizations scalar' => [
            'get_database_optimizations',
            'getDatabaseOptimizations',
            [],
            '{"payload":true}',
        ];
        yield 'optimization value number' => [
            'get_database_optimizations',
            'getDatabaseOptimizations',
            [],
            '{"payload":[{"value":1}]}',
        ];
        yield 'versions row scalar' => [
            'installable_mysql_versions',
            'installableMysqlVersions',
            [],
            '{"versions":[1]}',
        ];
        yield 'database user name integer' => [
            'list_database_users',
            'listDatabaseUsers',
            [],
            '{"payload":[{"name":1}]}',
        ];
        yield 'database owner null' => ['list_databases', 'listDatabases', [], '{"payload":[{"cpuser":null}]}'];
        yield 'database map values not strings' => [
            'list_mysql_databases_and_users',
            'listMysqlDatabasesAndUsers',
            ['example'],
            '{"mysql_databases":{"example_db":[1]}}',
        ];
        yield 'database prefix noncanonical flag' => [
            'list_mysql_databases_and_users',
            'listMysqlDatabasesAndUsers',
            ['example'],
            '{"mysql_config":{"use_db_prefix":"00"}}',
        ];
        yield 'prefix length float' => [
            'list_mysql_databases_and_users',
            'listMysqlDatabasesAndUsers',
            ['example'],
            '{"mysql_config":{"prefix_length":8.0}}',
        ];
        yield 'create profile flag boolean' => [
            'remote_mysql_create_profile',
            'remoteMysqlCreateProfile',
            ['h', 'synthetic', 3306, 'u', 'n'],
            '{"profile_details":{"cpcloud":true}}',
        ];
        yield 'ssh password null' => [
            'remote_mysql_create_profile_via_ssh',
            'remoteMysqlCreateProfileViaSsh',
            ['h', 'n', 22, 'root'],
            '{"profile_details":{"mysql_pass":null}}',
        ];
        yield 'deleted profile port string' => [
            'remote_mysql_delete_profile',
            'remoteMysqlDeleteProfile',
            ['fixture'],
            '{"profile_details":{"mysql_port":"3306"}}',
        ];
        yield 'activation pid string' => [
            'remote_mysql_initiate_profile_activation',
            'remoteMysqlInitiateProfileActivation',
            ['fixture'],
            '{"activation_job_started":"1"}',
        ];
        yield 'monitor start string' => [
            'remote_mysql_monitor_profile_activation',
            'remoteMysqlMonitorProfileActivation',
            [],
            '{"job_in_progress":{"start_time":"0"}}',
        ];
        yield 'monitor steps object' => [
            'remote_mysql_monitor_profile_activation',
            'remoteMysqlMonitorProfileActivation',
            [],
            '{"last_job_details":{"steps":{}}}',
        ];
        yield 'monitor step end null' => [
            'remote_mysql_monitor_profile_activation',
            'remoteMysqlMonitorProfileActivation',
            [],
            '{"last_job_details":{"steps":[{"end_time":null}]}}',
        ];
        yield 'read profile active quoted' => [
            'remote_mysql_read_profile',
            'remoteMysqlReadProfile',
            ['fixture'],
            '{"profile_details":{"active":"1"}}',
        ];
        yield 'profile map row null' => [
            'remote_mysql_read_profiles',
            'remoteMysqlReadProfiles',
            [],
            '{"fixture":null}',
        ];
        yield 'profile map row list' => [
            'remote_mysql_read_profiles', 'remoteMysqlReadProfiles', [], '{"fixture":[1]}',
        ];
        yield 'profile map quoted localhost example is not inferred' => [
            'remote_mysql_read_profiles',
            'remoteMysqlReadProfiles',
            [],
            '{"fixture":{"is_localhost_profile":"1"}}',
        ];
        yield 'profile map supported boolean' => [
            'remote_mysql_read_profiles',
            'remoteMysqlReadProfiles',
            [],
            '{"fixture":{"mysql_version_is_supported":true}}',
        ];
        yield 'updated profile port float' => [
            'remote_mysql_update_profile',
            'remoteMysqlUpdateProfile',
            ['fixture'],
            '{"profile_details":{"mysql_port":3306.0}}',
        ];
        yield 'validated profile boolean' => [
            'remote_mysql_validate_profile',
            'remoteMysqlValidateProfile',
            ['fixture'],
            '{"profile_validated":true}',
        ];
        yield 'root update quoted flag' => [
            'set_local_mysql_root_password',
            'setLocalMysqlRootPassword',
            ['synthetic'],
            '{"configs_updated":"0"}',
        ];
        yield 'root reset boolean flag' => [
            'set_local_mysql_root_password',
            'setLocalMysqlRootPassword',
            ['synthetic'],
            '{"password_reset":true}',
        ];
        yield 'root profile flag outside binary' => [
            'set_local_mysql_root_password',
            'setLocalMysqlRootPassword',
            ['synthetic'],
            '{"profile_updated":2}',
        ];
        yield 'upgrade id number' => [
            'start_background_mysql_upgrade',
            'startBackgroundMysqlUpgrade',
            [8.0],
            '{"upgrade_id":1}',
        ];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedPayloads')]
    public function testWrongFieldTypesFailWithoutCoercion(
        string $operation,
        string $method,
        array $arguments,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $client->whm()->databases()->{$method}(...$arguments);
            self::fail('Malformed pinned field must fail.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, array<array-key, mixed>}> */
    public static function observedCompatibilityPayloads(): iterable
    {
        // These map names and string values are synthetic fixtures, not recovered names from masked live evidence.
        yield 'optimization map retains dynamic names and all three pinned fields' => [
            'get_database_optimizations', 'getDatabaseOptimizations', [],
            '{"payload":{"synthetic-setting":{"name":"synthetic-name","reason":"synthetic-reason",'
                . '"value":"2M"},"synthetic-empty":{}}}',
            ['payload' => ['synthetic-setting' => ['name' => 'synthetic-name', 'reason' => 'synthetic-reason',
                'value' => '2M'], 'synthetic-empty' => []]],
        ];
        yield 'empty optimization map' => [
            'get_database_optimizations', 'getDatabaseOptimizations', [], '{"payload":{}}', ['payload' => []],
        ];
        yield 'prefix quoted zero' => [
            'list_mysql_databases_and_users', 'listMysqlDatabasesAndUsers', ['example'],
            '{"mysql_config":{"use_db_prefix":"0"}}', ['mysql_config' => ['use_db_prefix' => 0]],
        ];
        yield 'prefix quoted one' => [
            'list_mysql_databases_and_users', 'listMysqlDatabasesAndUsers', ['example'],
            '{"mysql_config":{"use_db_prefix":"1"}}', ['mysql_config' => ['use_db_prefix' => 1]],
        ];
        yield 'both activation job fields null' => [
            'remote_mysql_monitor_profile_activation', 'remoteMysqlMonitorProfileActivation', [],
            '{"job_in_progress":null,"last_job_details":null}',
            ['job_in_progress' => null, 'last_job_details' => null],
        ];
        yield 'current job null and prior job record retained' => [
            'remote_mysql_monitor_profile_activation', 'remoteMysqlMonitorProfileActivation', [],
            '{"job_in_progress":null,"last_job_details":{"profile_name":"synthetic","steps":[]}}',
            ['job_in_progress' => null, 'last_job_details' => ['profile_name' => 'synthetic', 'steps' => []]],
        ];
        yield 'prior job null and current job record retained' => [
            'remote_mysql_monitor_profile_activation', 'remoteMysqlMonitorProfileActivation', [],
            '{"job_in_progress":{"status":"INPROGRESS","start_time":0},"last_job_details":null}',
            ['job_in_progress' => ['start_time' => 0, 'status' => 'INPROGRESS'], 'last_job_details' => null],
        ];
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('observedCompatibilityPayloads')]
    public function testReviewedLiveStructuralVariantsPreservePinnedRecordFields(
        string $operation,
        string $method,
        array $arguments,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));

        self::assertSame($expected, $client->whm()->databases()->{$method}(...$arguments));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string}> */
    public static function compatibilityBoundaries(): iterable
    {
        foreach (
            ['""', '"00"', '"01"', '"-0"', '"+1"', '"1.0"', '"0.0"', '" 1"', '"1 "',
            '"2"', '"true"', 'true', 'false', 'null', '0.0', '1.0', '2'] as $flag
        ) {
            yield 'prefix rejects ' . $flag => [
                'list_mysql_databases_and_users', 'listMysqlDatabasesAndUsers', ['example'],
                '{"mysql_config":{"use_db_prefix":' . $flag . '}}',
            ];
        }
        foreach (['name', 'reason', 'value'] as $field) {
            yield 'optimization map rejects integer ' . $field => [
                'get_database_optimizations', 'getDatabaseOptimizations', [],
                '{"payload":{"synthetic-setting":{"' . $field . '":1}}}',
            ];
            yield 'optimization list rejects null ' . $field => [
                'get_database_optimizations', 'getDatabaseOptimizations', [],
                '{"payload":[{"' . $field . '":null}]}',
            ];
        }
        foreach (['null', '"text"', '1', 'true', '[1]'] as $record) {
            yield 'optimization map rejects nonrecord ' . $record => [
                'get_database_optimizations', 'getDatabaseOptimizations', [],
                '{"payload":{"synthetic-setting":' . $record . '}}',
            ];
        }
        foreach (['job_in_progress', 'last_job_details'] as $field) {
            foreach (['true', '"text"', '1', '[1]'] as $record) {
                yield 'activation rejects nonrecord ' . $field . ' ' . $record => [
                    'remote_mysql_monitor_profile_activation', 'remoteMysqlMonitorProfileActivation', [],
                    '{"' . $field . '":' . $record . '}',
                ];
            }
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('compatibilityBoundaries')]
    public function testLiveCorrectionsRejectUnestablishedValues(
        string $operation,
        string $method,
        array $arguments,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $this->expectException(PayloadMismatchException::class);
        $client->whm()->databases()->{$method}(...$arguments);
    }

    public function testOptionalInputsAreOmittedAndEmptyMapsAndListsRemainEmpty(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('remote_mysql_create_profile', '{}')));
        $client->whm()->databases()->remoteMysqlCreateProfile('host', 'synthetic', 3306, 'u', 'n');
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'mysql_host=host&mysql_pass=synthetic&mysql_port=3306&mysql_user=u&name=n',
            (string) $request->getBody(),
        );
        $http->addResponse(new Response(200, [], self::envelope('remote_mysql_create_profile_via_ssh', '{}')));
        $client->whm()->databases()->remoteMysqlCreateProfileViaSsh('host', 'n', 22, 'root', sshkeyName: 'fixture');
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'host=host&name=n&port=22&user=root&sshkey_name=fixture',
            (string) $request->getBody(),
        );
        $http->addResponse(new Response(200, [], self::envelope(
            'list_mysql_databases_and_users',
            '{"mysql_config":{},"mysql_databases":{}}',
        )));
        self::assertSame(
            ['mysql_config' => [], 'mysql_databases' => []],
            $client->whm()->databases()->listMysqlDatabasesAndUsers('example'),
        );
        $http->addResponse(new Response(200, [], self::envelope('get_database_optimizations', '{"payload":[]}')));
        self::assertSame(['payload' => []], $client->whm()->databases()->getDatabaseOptimizations());
    }

    public function testPasswordsAndSshPassphrasesHaveSensitiveParameterAttributes(): void
    {
        [$client] = self::client();
        $group = $client->whm()->databases();
        $expected = [
            'remoteMysqlCreateProfile' => ['mysqlPass'],
            'remoteMysqlCreateProfileViaSsh' => ['password', 'rootPassword', 'sshkeyPassphrase'],
            'remoteMysqlUpdateProfile' => ['mysqlPass'],
            'renamePostgresqlUser' => ['password'],
            'setLocalMysqlRootPassword' => ['password'],
            'setMysqlPassword' => ['password'],
            'setPostgresqlPassword' => ['password'],
        ];
        foreach ($expected as $method => $names) {
            $sensitive = [];
            foreach ((new ReflectionMethod($group, $method))->getParameters() as $parameter) {
                if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                    $sensitive[] = $parameter->getName();
                }
            }
            self::assertSame($names, $sensitive, $method);
        }
        self::assertSame(
            5,
            (new ReflectionMethod($group, 'remoteMysqlCreateProfile'))->getNumberOfRequiredParameters(),
        );
        self::assertSame(
            4,
            (new ReflectionMethod($group, 'remoteMysqlCreateProfileViaSsh'))->getNumberOfRequiredParameters(),
        );
        self::assertSame(3, (new ReflectionMethod($group, 'renamePostgresqlUser'))->getNumberOfRequiredParameters());
    }

    public function testApiFailureIsNotRetriedAndPreservesFailureMetadata(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"start_background_mysql_upgrade",'
            . '"reason":"Synthetic rejection","result":0,"version":1}}'));
        try {
            $client->whm()->databases()->startBackgroundMysqlUpgrade(8.0);
            self::fail('API failure must throw.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Synthetic rejection'], $exception->errors());
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    private static function envelope(string $operation, ?string $payload): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"OK","result":1,"version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'username' => 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
