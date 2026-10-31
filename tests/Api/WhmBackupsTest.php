<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\Backups;
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
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;

/**
 * Independent request and payload literals from pinned documentation and the reviewed backup contracts.
 *
 * @phpstan-type BackupCall Closure(Backups): mixed
 * @phpstan-type Operation array{string, string, BackupCall, string, ?string, mixed}
 */
final class WhmBackupsTest extends TestCase
{
    /** @param BackupCall $call */
    #[DataProvider('operations')]
    public function testRequestsAndPayloads(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $group = $whm->backups();
        self::assertSame($group, $whm->backups());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));

        self::assertSame($expected, $call($group));
        self::assertRequest($http, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame($function, $whm->responseMeta()?->whm['command'] ?? null);
        self::assertNull($client->uapi('alice')->responseMeta());
    }

    /** @return iterable<string, Operation> */
    public static function operations(): iterable
    {
        yield 'configuration' => ['backup_config_get', 'GET',
            static fn (Backups $b): mixed => $b->backupConfigGet(), '',
            '{"backup_config":{"backupenable":0,"disable_metadata":1,"keeplocal":0,'
                . '"backup_weekly_day":"1,3","backup_monthly_dates":"1,15",'
                . '"remote_restore_staging_dir":"/backup","extra":true}}',
            ['backup_config' => ['backup_monthly_dates' => '1,15', 'backup_weekly_day' => '1,3',
                'backupenable' => 0, 'disable_metadata' => 1, 'keeplocal' => 0,
                'remote_restore_staging_dir' => '/backup']]];
        yield 'configuration mutation' => ['backup_config_set', 'POST',
            static function (Backups $b): mixed {
                $b->backupConfigSet(
                    backupDailyEnable: false,
                    backupDailyRetention: 0,
                    backupdays: '0,2,4',
                    backupdir: '/server/backup +&',
                    disableMetadata: true,
                    keeplocal: false,
                );
                return null;
            }, 'backup_daily_enable=0&backup_daily_retention=0&backupdays=0%2C2%2C4'
                . '&backupdir=%2Fserver%2Fbackup%20%2B%26&disable_metadata=1&keeplocal=0', null, null];
        yield 'dates remain POST' => ['backup_date_list', 'POST',
            static fn (Backups $b): mixed => $b->backupDateList(), '',
            '{"backup_set":["2026-10-01",""]}', ['backup_set' => ['2026-10-01', '']]];
        yield 'local provider omits foreign credentials' => ['backup_destination_add', 'POST',
            static fn (Backups $b): mixed => $b->backupDestinationAdd(
                name: 'local +&',
                type: 'Local',
                disabled: false,
                mount: false,
                noMountFail: true,
                path: '/server/backups',
                timeout: 0,
            ), 'name=local%20%2B%26&type=Local&disabled=0&mount=0&no_mount_fail=1'
                . '&path=%2Fserver%2Fbackups&timeout=0', '{"id":"fixture"}', ['id' => 'fixture']];
        yield 'delete destination' => ['backup_destination_delete', 'POST',
            static function (Backups $b): mixed {
                $b->backupDestinationDelete('id +&');
                return null;
            },
            'id=id%20%2B%26', null, null];
        yield 'get destination keeps later provider fields' => ['backup_destination_get', 'POST',
            static fn (Backups $b): mixed => $b->backupDestinationGet('fixture'), 'id=fixture',
            '{"id":"fixture","type":"SFTP","authtype":"key","privatekey":"/server/key",'
                . '"client_secret":"fixture-secret","mount":0,"no_mount_fail":1,"ssl":0,"extra":true}',
            ['authtype' => 'key', 'client_secret' => 'fixture-secret', 'id' => 'fixture', 'mount' => 0,
                'no_mount_fail' => 1, 'privatekey' => '/server/key', 'ssl' => 0, 'type' => 'SFTP']];
        yield 'destination list keeps provider alternatives' => ['backup_destination_list', 'POST',
            static fn (Backups $b): mixed => $b->backupDestinationList(), '',
            '{"destination_list":[{"type":"GoogleDrive","client_id":"id",'
                . '"client_secret":"fixture-secret","folder":""},{"type":"Local","mount":0},{}]}',
            ['destination_list' => [['client_id' => 'id', 'client_secret' => 'fixture-secret',
                'folder' => '', 'type' => 'GoogleDrive'], ['mount' => 0, 'type' => 'Local'], []]]];
        yield 'validation may disable destination' => ['backup_destination_validate', 'POST',
            static fn (Backups $b): mixed => $b->backupDestinationValidate(false, 'fixture'),
            'disableonfail=0&id=fixture', '{"id":"fixture"}', ['id' => 'fixture']];
        yield 'google credentials file status' => ['backup_does_client_id_have_google_credentials', 'GET',
            static fn (Backups $b): mixed => $b->backupDoesClientIdHaveGoogleCredentials('id +&'),
            'client_id=id%20%2B%26', '{"exists":0}', ['exists' => 0]];
        yield 'oauth URI is returned without fetching' => ['backup_generate_google_oauth_uri', 'POST',
            static fn (Backups $b): mixed => $b->backupGenerateGoogleOauthUri('id', 'secret +&='),
            'client_id=id&client_secret=secret%20%2B%26%3D', '{"uri":"https://accounts.example.test/?code=x"}',
            ['uri' => 'https://accounts.example.test/?code=x']];
        yield 'transport status' => ['backup_get_transport_status', 'POST',
            static fn (Backups $b): mixed => $b->backupGetTransportStatus(state: 'failed', transportId: ''),
            'state=failed&transport_id=', '{"pages":0,"transport_status":[{"date":"2026-10-01",'
                . '"end_timestamp":"","start_timestamp":"a","status":"failed",'
                . '"transport":"fixture","user":"alice"},{}]}',
            ['pages' => 0, 'transport_status' => [['date' => '2026-10-01', 'end_timestamp' => '',
                'start_timestamp' => 'a', 'status' => 'failed', 'transport' => 'fixture', 'user' => 'alice'], []]]];
        yield 'transported dynamic maps' => ['backup_list_transported', 'POST',
            static fn (Backups $b): mixed => $b->backupListTransported('fixture'), 'transport=fixture',
            '{"remote_backups":{"fixture":{"alice":["2026-10-01"],"bob":[]},"empty":{}}}',
            ['remote_backups' => ['fixture' => ['alice' => ['2026-10-01'], 'bob' => []], 'empty' => []]]];
        yield 'local sets preserve independent account rows' => ['backup_set_list', 'POST',
            static fn (Backups $b): mixed => $b->backupSetList(), '',
            '{"backup_set":[{"user":"alice","backup_date":["2026-10-01","2026-10-15"],"extra":true},'
                . '{"user":"bob","backup_date":[]}]}',
            ['backup_set' => [
                ['backup_date' => ['2026-10-01', '2026-10-15'], 'user' => 'alice'],
                ['backup_date' => [], 'user' => 'bob']]]];
        yield 'local sets empty' => ['backup_set_list', 'POST',
            static fn (Backups $b): mixed => $b->backupSetList(), '', '{"backup_set":[]}', ['backup_set' => []]];
        yield 'local sets omitted' => ['backup_set_list', 'POST',
            static fn (Backups $b): mixed => $b->backupSetList(), '', '{}', []];
        yield 'combined sets preserve dynamic names' => ['backup_set_list_combined', 'POST',
            static fn (Backups $b): mixed => $b->backupSetListCombined(), '',
            '{"backup_set":{"alice":[{"when":"2026-10-01","where":"fixture"},{}],"bob":[]},'
                . '"destination_legend":{"fixture":{"name":"remote","type":"SFTP"}}}',
            ['backup_set' => ['alice' => [['when' => '2026-10-01', 'where' => 'fixture'], []], 'bob' => []],
                'destination_legend' => ['fixture' => ['name' => 'remote', 'type' => 'SFTP']]]];
        yield 'combined local sets without destinations' => ['backup_set_list_combined', 'POST',
            static fn (Backups $b): mixed => $b->backupSetListCombined(), '',
            '{"backup_set":{"alice":[{"when":"2026-10-01","where":"local"}]},"destination_legend":null}',
            ['backup_set' => ['alice' => [['when' => '2026-10-01', 'where' => 'local']]],
                'destination_legend' => null]];
        yield 'combined sets empty destination object' => ['backup_set_list_combined', 'POST',
            static fn (Backups $b): mixed => $b->backupSetListCombined(), '',
            '{"backup_set":{},"destination_legend":{}}', ['backup_set' => [], 'destination_legend' => []]];
        yield 'combined sets omit destination legend' => ['backup_set_list_combined', 'POST',
            static fn (Backups $b): mixed => $b->backupSetListCombined(), '',
            '{"backup_set":{}}', ['backup_set' => []]];
        yield 'disable all users' => ['backup_skip_users_all', 'POST',
            static function (Backups $b): mixed {
                $b->backupSkipUsersAll('backup', false);
                return null;
            },
            'backupversion=backup&state=0', null, null];
        yield 'all user configuration status remains POST' => ['backup_skip_users_all_status', 'POST',
            static fn (Backups $b): mixed => $b->backupSkipUsersAllStatus(), '',
            '{"reason":{"msg":"","perc":0,"running":0}}',
            ['reason' => ['msg' => '', 'perc' => 0, 'running' => 0]]];
        yield 'user list' => ['backup_user_list', 'POST',
            static fn (Backups $b): mixed => $b->backupUserList('2026-10-01'), 'restore_point=2026-10-01',
            '{"user":[{"status":"no_backup","username":"alice"},{}]}',
            ['user' => [['status' => 'no_backup', 'username' => 'alice'], []]]];
        yield 'legacy migration' => ['convert_and_migrate_from_legacy_config', 'POST',
            static function (Backups $b): mixed {
                $b->convertAndMigrateFromLegacyConfig(noConvert: false);
                return null;
            },
            'no_convert=0', null, null];
        yield 'master log stays payload text' => ['fetch_pkgacct_master_log', 'GET',
            static fn (Backups $b): mixed => $b->fetchPkgacctMasterLog('session +&'), 'session_id=session%20%2B%26',
            '{"log":"line one\n{\"status\":0}"}', ['log' => "line one\n{\"status\":0}"]];
        yield 'packaging session state' => ['get_pkgacct_session_state', 'GET',
            static fn (Backups $b): mixed => $b->getPkgacctSessionState('fixture'), 'session_id=fixture',
            '{"state":"FAILED"}', ['state' => 'FAILED']];
        yield 'metadata domain map' => ['get_users_and_domains_with_backup_metadata', 'GET',
            static fn (Backups $b): mixed => $b->getUsersAndDomainsWithBackupMetadata(), '',
            '{"alice":"example.test","bob":""}', ['alice' => 'example.test', 'bob' => '']];
        yield 'metadata users' => ['get_users_with_backup_metadata', 'GET',
            static fn (Backups $b): mixed => $b->getUsersWithBackupMetadata(), '',
            '{"accounts":["alice",""]}', ['accounts' => ['alice', '']]];
        yield 'server archive list' => ['list_cparchive_files', 'GET',
            static fn (Backups $b): mixed => $b->listCparchiveFiles(), '',
            '{"quickrestore_files":[{"file":"cpmove-alice.tar.gz","path":"/home","user":"alice"},{}]}',
            ['quickrestore_files' => [['file' => 'cpmove-alice.tar.gz', 'path' => '/home', 'user' => 'alice'], []]]];
        yield 'start packaging uses server path' => ['start_background_pkgacct', 'POST',
            static fn (Backups $b): mixed => $b->startBackgroundPkgacct(
                user: 'alice',
                incremental: true,
                skipapitokens: true,
                split: false,
                tarroot: '/server/archive +&',
                useBackups: false,
            ), 'user=alice&incremental=1&skipapitokens=1&split=0&tarroot=%2Fserver%2Farchive%20%2B%26&use_backups=0',
            '{"complete_master_error_log":"master.error_log","complete_master_log":"master.log",'
                . '"session_id":"fixture"}', ['complete_master_error_log' => 'master.error_log',
                'complete_master_log' => 'master.log', 'session_id' => 'fixture']];
        yield 'local restore forwards archive path and flags' => ['start_local_cpmove_restore', 'POST',
            static fn (Backups $b): mixed => $b->startLocalCpmoveRestore(
                cpmovepath: '/server/cpmove +&.tar.gz',
                deleteArchive: false,
                mailLocation: 'linked-node',
                overwrite: false,
                restricted: true,
            ), 'cpmovepath=%2Fserver%2Fcpmove%20%2B%26.tar.gz&delete_archive=0'
                . '&mail_location=linked-node&overwrite=0&restricted=1',
            '{"transfer_session_id":"fixture"}', ['transfer_session_id' => 'fixture']];
        yield 'toggle user standard backup' => ['toggle_user_backup_state', 'POST',
            static fn (Backups $b): mixed => $b->toggleUserBackupState('alice', false),
            'user=alice&legacy=0', '{"toggle_status":0}', ['toggle_status' => 0]];
    }

    public function testProviderCredentialsAreOptionalAndSensitive(): void
    {
        $method = new ReflectionMethod(Backups::class, 'backupDestinationAdd');
        $required = [];
        $sensitive = [];
        foreach ($method->getParameters() as $parameter) {
            if (!$parameter->isOptional()) {
                $required[] = $parameter->getName();
            }
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $sensitive[] = $parameter->getName();
            }
        }
        self::assertSame(['name', 'type', 'disabled'], $required);
        self::assertSame([
            'applicationKey', 'applicationKeyId', 'awsAccessKeyId', 'clientSecret',
            'passphrase', 'password', 'privatekey',
        ], $sensitive);
        $secret = new ReflectionMethod(Backups::class, 'backupGenerateGoogleOauthUri');
        self::assertNotEmpty($secret->getParameters()[1]->getAttributes(SensitiveParameter::class));
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Backups::class))->getMethods(),
        );
        self::assertNotContains('backupDestinationSet', $methods);
    }

    public function testProviderSecretValuesStayInTheFormAndAreEncodedOnce(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('backup_destination_add', '{}')));
        $client->whm()->backups()->backupDestinationAdd(
            name: 'remote',
            type: 'SFTP',
            disabled: false,
            authtype: 'key',
            password: 'p +&=%20',
            username: 'alice',
            passphrase: '',
            privatekey: '/server/key +&',
        );
        self::assertRequest(
            $http,
            'backup_destination_add',
            'POST',
            'name=remote&type=SFTP&disabled=0&authtype=key&passphrase=&password=p%20%2B%26%3D%2520'
            . '&privatekey=%2Fserver%2Fkey%20%2B%26&username=alice',
        );
    }

    /** @return iterable<string, array{array<string, int|string>, array<string, int|string>}> */
    public static function reviewedConfigurationRepresentations(): iterable
    {
        yield 'weekly day integer zero' => [['backup_weekly_day' => 0], ['backup_weekly_day' => 0]];
        yield 'weekly day integer' => [['backup_weekly_day' => 3], ['backup_weekly_day' => 3]];
        yield 'weekly day original string spelling' => [
            ['backup_weekly_day' => '01'], ['backup_weekly_day' => '01'],
        ];
        foreach (['check_min_free_space', 'prebackup'] as $field) {
            yield $field . ' quoted zero' => [[$field => '0'], [$field => 0]];
            yield $field . ' quoted one' => [[$field => '1'], [$field => 1]];
            yield $field . ' native zero' => [[$field => 0], [$field => 0]];
            yield $field . ' native one' => [[$field => 1], [$field => 1]];
        }
        yield 'prebackup stock default' => [['prebackup' => '-1'], ['prebackup' => '-1']];
        yield 'prebackup source empty value' => [['prebackup' => ''], ['prebackup' => '']];
        yield 'actual suspended spelling disabled' => [['backupsuspendedaccts' => 0], ['backupsuspendedaccts' => 0]];
        yield 'actual suspended spelling enabled' => [['backupsuspendedaccts' => 1], ['backupsuspendedaccts' => 1]];
        yield 'documented suspended spelling stays available' => [
            ['backupsuspendedaccounts' => 1], ['backupsuspendedaccounts' => 1],
        ];
        yield 'suspended spellings stay distinct' => [
            ['backupsuspendedaccounts' => 0, 'backupsuspendedaccts' => 1],
            ['backupsuspendedaccounts' => 0, 'backupsuspendedaccts' => 1],
        ];
        yield 'omitted fields' => [[], []];
    }

    /**
     * @param array<string, int|string> $configuration
     * @param array<string, int|string> $expected
     */
    #[DataProvider('reviewedConfigurationRepresentations')]
    public function testReviewedBackupConfigurationRepresentations(
        array $configuration,
        array $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'backup_config_get',
            json_encode(['backup_config' => (object) $configuration], JSON_THROW_ON_ERROR),
        )));
        self::assertSame(['backup_config' => $expected], $client->whm()->backups()->backupConfigGet());
        self::assertRequest($http, 'backup_config_get', 'GET', '');
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidConfigurationRepresentations(): iterable
    {
        foreach (['check_min_free_space', 'prebackup'] as $field) {
            foreach (
                ['00', '01', '1.0', '1e0', ' 1', '1 ', '2', '-01', '-1.0', 'other',
                    2, -1, 0.0, true, false, null] as $value
            ) {
                yield $field . ' ' . json_encode($value, JSON_PRESERVE_ZERO_FRACTION) => [$field, $value];
            }
        }
        yield 'free space empty stays invalid' => ['check_min_free_space', ''];
        yield 'free space negative stays invalid' => ['check_min_free_space', '-1'];
        foreach (['0', '1', 2, -1, true, false, null] as $index => $value) {
            yield 'suspended flag invalid ' . $index => ['backupsuspendedaccts', $value];
        }
        foreach ([0.5, true, false, null, [], new \stdClass()] as $index => $value) {
            yield 'weekly day invalid ' . $index => ['backup_weekly_day', $value];
        }
        yield 'unrelated backup flag stays strict' => ['backupenable', '0'];
        yield 'unrelated backup count stays strict' => ['backup_daily_retention', '5'];
    }

    #[DataProvider('invalidConfigurationRepresentations')]
    public function testBackupConfigurationCorrectionsStayNarrow(string $field, mixed $value): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'backup_config_get',
            json_encode(['backup_config' => [$field => $value]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        )));
        $this->expectException(PayloadMismatchException::class);
        $client->whm()->backups()->backupConfigGet();
    }

    public function testMonthlyBackupDatesAcceptACommaDelimitedString(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('backup_config_set', null)));
        $client->whm()->backups()->backupConfigSet(backupMonthlyDates: '1,15,22', backupWeeklyDay: 3);
        self::assertRequest($http, 'backup_config_set', 'POST', 'backup_monthly_dates=1%2C15%2C22&backup_weekly_day=3');
        self::assertCount(1, $http->getRequests());
    }

    public function testDestinationGetPreservesSystemBackupFlagsAndOmission(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'backup_destination_get',
            '{"upload_system_backup":0}',
        )));
        $http->addResponse(new Response(200, [], self::envelope(
            'backup_destination_get',
            '{"upload_system_backup":1}',
        )));
        $http->addResponse(new Response(200, [], self::envelope('backup_destination_get', '{}')));
        self::assertSame(['upload_system_backup' => 0], $client->whm()->backups()->backupDestinationGet('fixture'));
        self::assertSame(['upload_system_backup' => 1], $client->whm()->backups()->backupDestinationGet('fixture'));
        self::assertSame([], $client->whm()->backups()->backupDestinationGet('fixture'));
        self::assertRequest($http, 'backup_destination_get', 'POST', 'id=fixture');
        self::assertCount(3, $http->getRequests());
    }

    public function testEveryBackupConfigurationOptionHasItsDocumentedWireName(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('backup_config_set', null)));
        $client->whm()->backups()->backupConfigSet(
            backupDailyEnable: false,
            backupDailyRetention: 0,
            backupMonthlyDates: 15,
            backupMonthlyEnable: true,
            backupMonthlyRetention: 0,
            backupWeeklyDay: 0,
            backupWeeklyEnable: false,
            backupWeeklyRetention: 0,
            backupaccts: true,
            backupbwdata: false,
            backupdays: '0,2',
            backupdir: '/server/backups',
            backupenable: false,
            backupfiles: true,
            backuplogs: false,
            backupmount: false,
            backupsuspendedaccts: true,
            backuptype: 'incremental',
            checkMinFreeSpace: false,
            disableMetadata: true,
            errorthreshhold: 2,
            forcePruneDaily: true,
            forcePruneMonthly: false,
            forcePruneWeekly: true,
            gziprsyncopts: '--rsyncable',
            keeplocal: false,
            linkdest: false,
            localzonesonly: true,
            maximumRestoreTimeout: 20,
            maximumTimeout: 10,
            minFreeSpace: 1,
            minFreeSpaceUnit: 'percent',
            mysqlbackup: 'both',
            postbackup: false,
            prebackup: false,
            psqlbackup: true,
            remoteRestoreStagingDir: '/server/staging',
        );
        self::assertRequest(
            $http,
            'backup_config_set',
            'POST',
            'backup_daily_enable=0&backup_daily_retention=0&backup_monthly_dates=15&backup_monthly_enable=1'
                . '&backup_monthly_retention=0&backup_weekly_day=0&backup_weekly_enable=0&backup_weekly_retention=0'
                . '&backupaccts=1&backupbwdata=0&backupdays=0%2C2&backupdir=%2Fserver%2Fbackups&backupenable=0'
                . '&backupfiles=1&backuplogs=0&backupmount=0&backupsuspendedaccts=1&backuptype=incremental'
                . '&check_min_free_space=0&disable_metadata=1&errorthreshhold=2&force_prune_daily=1'
                . '&force_prune_monthly=0&force_prune_weekly=1&gziprsyncopts=--rsyncable&keeplocal=0'
                . '&linkdest=0&localzonesonly=1&maximum_restore_timeout=20&maximum_timeout=10&min_free_space=1'
                . '&min_free_space_unit=percent&mysqlbackup=both&postbackup=0&prebackup=0&psqlbackup=1'
                . '&remote_restore_staging_dir=%2Fserver%2Fstaging',
        );
    }

    public function testEveryPackagingOptionIsSentAsAScalarFormField(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('start_background_pkgacct', '{}')));
        $client->whm()->backups()->startBackgroundPkgacct(
            user: 'alice',
            compressionsetting: 'compress',
            incremental: false,
            lowPriority: true,
            mysqlver: '8.0',
            serializedOutput: true,
            skipacctdb: false,
            skipapitokens: true,
            skipauthnlinks: true,
            skipbwdata: false,
            skipdnssec: false,
            skipdnszones: false,
            skipftpusers: true,
            skiphomedir: false,
            skiplinkednodes: true,
            skiplogs: false,
            skipresellerconfig: true,
            skipshell: false,
            skipvhosttemplates: true,
            split: false,
            tarroot: '/server/archive',
            useBackups: false,
        );
        self::assertRequest(
            $http,
            'start_background_pkgacct',
            'POST',
            'user=alice&compressionsetting=compress&incremental=0&low_priority=1&mysqlver=8.0'
                . '&serialized_output=1&skipacctdb=0&skipapitokens=1&skipauthnlinks=1&skipbwdata=0'
                . '&skipdnssec=0&skipdnszones=0&skipftpusers=1&skiphomedir=0&skiplinkednodes=1&skiplogs=0'
                . '&skipresellerconfig=1&skipshell=0&skipvhosttemplates=1&split=0&tarroot=%2Fserver%2Farchive'
                . '&use_backups=0',
        );
    }

    /** @return iterable<string, Operation> */
    public static function omittedOptions(): iterable
    {
        yield 'configuration' => ['backup_config_set', 'POST',
            static function (Backups $b): mixed {
                $b->backupConfigSet();
                return null;
            }, '', null, null];
        yield 'transport status' => ['backup_get_transport_status', 'POST',
            static fn (Backups $b): mixed => $b->backupGetTransportStatus(), '', '{}', []];
        yield 'transported' => ['backup_list_transported', 'POST',
            static fn (Backups $b): mixed => $b->backupListTransported(), '', '{}', []];
        yield 'migration' => ['convert_and_migrate_from_legacy_config', 'POST',
            static function (Backups $b): mixed {
                $b->convertAndMigrateFromLegacyConfig();
                return null;
            }, '', null, null];
        yield 'packaging' => ['start_background_pkgacct', 'POST',
            static fn (Backups $b): mixed => $b->startBackgroundPkgacct('alice', compressionsetting: null),
            'user=alice', '{}', []];
        yield 'restore' => ['start_local_cpmove_restore', 'POST',
            static fn (Backups $b): mixed => $b->startLocalCpmoveRestore('/server/archive'),
            'cpmovepath=%2Fserver%2Farchive', '{}', []];
        yield 'toggle' => ['toggle_user_backup_state', 'POST',
            static fn (Backups $b): mixed => $b->toggleUserBackupState('alice'), 'user=alice', '{}', []];
    }

    /** @param BackupCall $call */
    #[DataProvider('omittedOptions')]
    public function testOmissionPreservesServerDefaults(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        $this->testRequestsAndPayloads($function, $method, $call, $parameters, $payload, $expected);
    }

    /** @return iterable<string, array{BackupCall, string}> */
    public static function malformedFields(): iterable
    {
        yield 'config boolean flag' => [static fn (Backups $b): mixed => $b->backupConfigGet(),
            '{"backup_config":{"backupenable":false}}'];
        yield 'config numeric date' => [static fn (Backups $b): mixed => $b->backupConfigGet(),
            '{"backup_config":{"backup_monthly_dates":1}}'];
        yield 'date numeric item' => [static fn (Backups $b): mixed => $b->backupDateList(), '{"backup_set":[1]}'];
        yield 'destination flag string' => [static fn (Backups $b): mixed => $b->backupDestinationGet('fixture'),
            '{"disabled":"0"}'];
        yield 'destination system backup string flag' => [
            static fn (Backups $b): mixed => $b->backupDestinationGet('fixture'), '{"upload_system_backup":"0"}'];
        yield 'destination system backup null' => [
            static fn (Backups $b): mixed => $b->backupDestinationGet('fixture'), '{"upload_system_backup":null}'];
        yield 'destination system backup boolean' => [
            static fn (Backups $b): mixed => $b->backupDestinationGet('fixture'), '{"upload_system_backup":false}'];
        yield 'destination system backup outside flags' => [
            static fn (Backups $b): mixed => $b->backupDestinationGet('fixture'), '{"upload_system_backup":2}'];
        yield 'later provider wrong type' => [static fn (Backups $b): mixed => $b->backupDestinationGet('fixture'),
            '{"privatekey":1}'];
        yield 'list record not object' => [static fn (Backups $b): mixed => $b->backupDestinationList(),
            '{"destination_list":[1]}'];
        yield 'credential status boolean' => [
            static fn (Backups $b): mixed => $b->backupDoesClientIdHaveGoogleCredentials('fixture'), '{"exists":true}'];
        yield 'transport timestamp number' => [static fn (Backups $b): mixed => $b->backupGetTransportStatus(),
            '{"transport_status":[{"start_timestamp":1}]}'];
        yield 'transported leaf not list' => [static fn (Backups $b): mixed => $b->backupListTransported(),
            '{"remote_backups":{"fixture":{"alice":"2026-10-01"}}}'];
        yield 'combined date number' => [static fn (Backups $b): mixed => $b->backupSetListCombined(),
            '{"backup_set":{"alice":[{"when":0}]}}'];
        yield 'combined legend scalar' => [static fn (Backups $b): mixed => $b->backupSetListCombined(),
            '{"destination_legend":false}'];
        yield 'local set object is not a row list' => [static fn (Backups $b): mixed => $b->backupSetList(),
            '{"backup_set":{"user":"alice","backup_date":["2026-10-01"]}}'];
        yield 'local set row is not an object' => [static fn (Backups $b): mixed => $b->backupSetList(),
            '{"backup_set":["alice"]}'];
        yield 'local set date is not a list' => [static fn (Backups $b): mixed => $b->backupSetList(),
            '{"backup_set":[{"user":"alice","backup_date":"2026-10-01"}]}'];
        yield 'local set date item is not a string' => [static fn (Backups $b): mixed => $b->backupSetList(),
            '{"backup_set":[{"user":"alice","backup_date":[1]}]}'];
        yield 'status string flag' => [static fn (Backups $b): mixed => $b->backupSkipUsersAllStatus(),
            '{"reason":{"running":"0"}}'];
        yield 'users null name' => [static fn (Backups $b): mixed => $b->backupUserList('2026-10-01'),
            '{"user":[{"username":null}]}'];
        yield 'log decoded object' => [static fn (Backups $b): mixed => $b->fetchPkgacctMasterLog('fixture'),
            '{"log":{"contents":"text"}}'];
        yield 'domain map null value' => [static fn (Backups $b): mixed => $b->getUsersAndDomainsWithBackupMetadata(),
            '{"alice":null}'];
        yield 'metadata users numeric item' => [static fn (Backups $b): mixed => $b->getUsersWithBackupMetadata(),
            '{"accounts":[1]}'];
        yield 'archive list null path' => [static fn (Backups $b): mixed => $b->listCparchiveFiles(),
            '{"quickrestore_files":[{"path":null}]}'];
        yield 'toggle bool' => [static fn (Backups $b): mixed => $b->toggleUserBackupState('alice'),
            '{"toggle_status":false}'];
    }

    /** @param BackupCall $call */
    #[DataProvider('malformedFields')]
    public function testMalformedFieldsFailWithoutLosingMetadata(Closure $call, string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('fixture', $payload)));
        try {
            $call($client->whm()->backups());
            self::fail('Invalid field types must fail payload validation.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{BackupCall, ?string}> */
    public static function missingPayloads(): iterable
    {
        foreach (self::operations() as $name => [, , $call, , $payload]) {
            if ($payload === null) {
                continue;
            }
            yield $name . ' missing' => [$call, null];
            yield $name . ' explicit null' => [$call, 'null'];
        }
    }

    /** @param BackupCall $call */
    #[DataProvider('missingPayloads')]
    public function testDocumentedPayloadCannotDisappear(Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('fixture', $payload)));
        $this->expectException(PayloadMismatchException::class);
        $call($client->whm()->backups());
    }

    /** @param BackupCall $call */
    #[DataProvider('operations')]
    public function testApiFailureStopsBeforePayloadValidation(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Fixture failure"}}'));
        try {
            $call($client->whm()->backups());
            self::fail('A failed WHM outcome must raise an API exception.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Fixture failure'], $exception->errors());
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    private static function assertRequest(MockClient $http, string $function, string $method, string $parameters): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        $query = 'api.version=1' . ($method === 'GET' && $parameters !== '' ? '&' . $parameters : '');
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?' . $query,
            (string) $request->getUri(),
        );
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    private static function envelope(string $function, ?string $payload): string
    {
        return '{"metadata":{"command":"' . $function . '","result":1,"reason":"OK","version":1}'
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
