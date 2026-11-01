<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Server;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

/** Literal request/payload fixtures independently transcribed from the pinned source. No live actions. */
final class WhmServerAdministrationTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, array<string, mixed>, string, ?string}> */
    public static function requests(): iterable
    {
        foreach (self::cases() as $method => [$function, $verb, $arguments, $wire, $payload]) {
            yield $method => [$method, $function, $verb, $arguments, $wire, $payload];
        }
    }

    /** @return array<string, array{string, string, array<string, mixed>, string, ?string}> */
    private static function cases(): array
    {
        return [
            'isEcosystemFeatureReady' => [
                'EcosystemFeatures/is_ready',
                'POST',
                [
                    'plugin' => 'fixture plugin',
                    'namespace' => 'Cpanel',
                ],
                'plugin=fixture%20plugin&namespace=Cpanel',
                '{"ready":0}',
            ],
            'disableEcosystemFeatureLocally' => [
                'EcosystemFeatures/local_disable',
                'POST',
                [
                    'plugin' => 'cpanel-monitoring-plugin',
                    'namespace' => 'Whostmgr',
                ],
                'plugin=cpanel-monitoring-plugin&namespace=Whostmgr',
                null,
            ],
            'enableEcosystemFeatureLocally' => [
                'EcosystemFeatures/local_enable',
                'POST',
                [
                    'plugin' => 'cpanel-nova-plugin',
                    'namespace' => 'Whostmgr',
                ],
                'plugin=cpanel-nova-plugin&namespace=Whostmgr',
                null,
            ],
            'acceptEula' => [
                'accept_eula',
                'POST',
                [],
                '',
                null,
            ],
            'addConfigClusterServer' => [
                'add_configclusterserver',
                'POST',
                [
                    'key' => 'fixture/key+secret',
                    'name' => 'node.example.test',
                    'user' => 'root',
                ],
                'key=fixture%2Fkey%2Bsecret&name=node.example.test&user=root',
                null,
            ],
            'configureBackgroundProcessKiller' => [
                'configurebackgroundprocesskiller',
                'POST',
                [
                    'processesToKill' => [
                        'process one',
                        'process2',
                    ],
                    'trustedUsers' => [
                        'trusted1',
                        'trusted2',
                    ],
                ],
                'processes_to_kill=process%20one&processes_to_kill=process2&trusted_users=trusted1&tr'
                    . 'usted_users=trusted2',
                null,
            ],
            'configureService' => [
                'configureservice',
                'POST',
                [
                    'service' => 'exim-altport',
                    'enabled' => false,
                    'eximAltportnum' => '26, 5000',
                    'monitored' => true,
                ],
                'service=exim-altport&enabled=0&exim-altportnum=26%2C%205000&monitored=1',
                null,
            ],
            'deleteConfigClusterServer' => [
                'delete_configclusterserver',
                'POST',
                [
                    'name' => 'node.example.test',
                ],
                'name=node.example.test',
                null,
            ],
            'enableMonitorAllEnabledServices' => [
                'enable_monitor_all_enabled_services',
                'POST',
                [],
                '',
                '{"services":[{"monitored":0,"service":"mysql"},{"monitored":1}]}',
            ],
            'fetchConnectedApplication' => [
                'fetch_connected_application',
                'POST',
                [
                    'name' => 'fixture app',
                ],
                'name=fixture%20app',
                '{"data":{"jwt":{"challenge":"fixture","exp":1,"scope":["fixture"]},"private_key":"ke'
                    . 'y-id","privileges_granted":["list-accts"],"public_key":"public-id","token_name":"tok'
                    . 'en-name","future":{"list":[1,null]}},"name":"fixture app"}',
            ],
            'fetchSecurityAdvice' => [
                'fetch_security_advice',
                'GET',
                [],
                '',
                '{"payload":[{"advice":{"key":"fixture","suggestion":null,"summary":"<b>Advice</b>","'
                    . 'type":"ADVISE_INFO"},"module":"Fixture","type":"mod_advice"},{"message":"fixture mes'
                    . 'sage","type":"mod_load"}]}',
            ],
            'forceDedistributionFromNode' => [
                'force_dedistribution_from_node',
                'POST',
                [
                    'nodeAlias' => 'child',
                    'users' => [
                        'fixture1',
                        'fixture2',
                    ],
                ],
                'node_alias=child&user=fixture1&user=fixture2',
                '{"log":[{"contents":"fixture","indent":0,"type":"warn"}],"user_info":[{"username":"f'
                    . 'ixture1"}]}',
            ],
            'getAllContactImportances' => [
                'get_all_contact_importances',
                'GET',
                [],
                '',
                '{"importances":[{"app":"Fixture","event":"Check","importance":0,"name":"Disabled"}]}',
            ],
            'getApplicationContactEventImportance' => [
                'get_application_contact_event_importance',
                'GET',
                [
                    'app' => 'Fixture',
                    'event' => 'Check',
                ],
                'app=Fixture&event=Check',
                '{"importance":0,"name":"Disabled"}',
            ],
            'getApplicationContactImportance' => [
                'get_application_contact_importance',
                'GET',
                [
                    'app' => 'Fixture',
                ],
                'app=Fixture',
                '{"importance":1,"name":"High"}',
            ],
            'getAvailableProfiles' => [
                'get_available_profiles',
                'GET',
                [],
                '',
                '{"profiles":[{"code":"STANDARD","disabled_roles":[{"module":"DNS","name":"DNS"}],"en'
                    . 'abled_roles":[{"module":"FTP","name":"FTP"}],"experimental":0,"name":"Standard","opt'
                    . 'ional_roles":[{"module":"SpamFilter","name":"Spam"}]}]}',
            ],
            'getAvailableTiers' => [
                'get_available_tiers',
                'POST',
                [],
                '',
                '{"current":"11.138","edge":"11.139","lts":"11.136","release":"11.138","stable":"11.1'
                    . '38","future":"11.140"}',
            ],
            'getCurrentLtsExpirationStatus' => [
                'get_current_lts_expiration_status',
                'POST',
                [],
                '',
                '{"expiration":0,"expires_in_next_three_months":0,"full_version":"11.136.0.0"}',
            ],
            'getCurrentProfile' => [
                'get_current_profile',
                'GET',
                [],
                '',
                '{"code":"STANDARD","disabled_roles":[{"module":"DNS","name":"DNS"}],"enabled_roles":'
                    . '[{"module":"FTP","name":"FTP"}],"experimental":0,"name":"Standard","optional_roles":'
                    . '[{"module":"SpamFilter","name":"Spam"}]}',
            ],
            'getLinkedServerNode' => [
                'get_linked_server_node',
                'GET',
                [
                    'alias' => 'child',
                ],
                'alias=child',
                '{"enabled_services":["exim"],"hostname":"node.example.test","last_check":0,"system_s'
                    . 'ettings":{"Mail":{"flag":0}},"tls_verified":1,"username":"root","version":"11.138","'
                    . 'worker_capabilities":{"Mail":{"opaque":[1,null]}}}',
            ],
            'getLtsWexpire' => [
                'get_lts_wexpire',
                'POST',
                [],
                '',
                '{"branch":{"11.136":[{"build":"1","is_main":0}]},"flags":{"future":1},"tiers":{"LTS"'
                    . ':[{"build":"1","expires":"future","is_lts":1,"is_main":0,"named":["lts"]}]}}',
            ],
            'getServerNodeStatus' => [
                'get_server_node_status',
                'POST',
                [
                    'apiToken' => 'fixture/token',
                    'hostname' => 'node.example.test',
                    'username' => 'root',
                    'skipTlsVerification' => false,
                ],
                'api_token=fixture%2Ftoken&hostname=node.example.test&username=root&skip_tls_verifica'
                    . 'tion=0',
                '{"enabled_services":["exim"],"remote_node_linkages":[{"opaque":null}],"system_settin'
                    . 'gs":{"Mail":{"flag":0}},"tls_verified":0,"version":"11.138"}',
            ],
            'getServiceConfig' => [
                'get_service_config',
                'GET',
                [
                    'service' => 'dovecot',
                ],
                'service=dovecot',
                '{"protocols":"imap pop3","mail_process_size":512,"protocol_imap":{"max":20},"hulk_au'
                    . 'th_passwd":"fixture-secret"}',
            ],
            'getServiceConfigKey' => [
                'get_service_config_key',
                'GET',
                [
                    'key' => 'fixture_key',
                    'service' => 'dovecot',
                ],
                'key=fixture_key&service=dovecot',
                '{"fixture_key":"fixture value","mail_process_size":{"opaque":[false,null]}}',
            ],
            'getServiceProxyBackends' => [
                'get_service_proxy_backends',
                'GET',
                [
                    'username' => 'fixture',
                ],
                'username=fixture',
                '{"payload":[{"backend":"backend.example.test","service_group":null},{"service_group"'
                    . ':"Mail"}]}',
            ],
            'getTcp4Sockets' => [
                'get_tcp4_sockets',
                'GET',
                [],
                '',
                '{"payload":[{"dport":0,"dst":"::","inode":1,"rqueue":0,"sport":1,"src":"::1","state"'
                    . ':1,"uid":0,"wqueue":0}]}',
            ],
            'getTcp6Sockets' => [
                'get_tcp6_sockets',
                'GET',
                [],
                '',
                '{"payload":[{"dport":0,"dst":"::","inode":1,"rqueue":0,"sport":1,"src":"::1","state"'
                    . ':1,"uid":0,"wqueue":0}]}',
            ],
            'getUdp4Sockets' => [
                'get_udp4_sockets',
                'GET',
                [],
                '',
                '{"payload":[{"dport":0,"dst":"::","inode":1,"rqueue":0,"sport":1,"src":"::1","state"'
                    . ':1,"uid":0,"wqueue":0}]}',
            ],
            'getUdp6Sockets' => [
                'get_udp6_sockets',
                'GET',
                [],
                '',
                '{"payload":[{"dport":0,"dst":"::","inode":1,"rqueue":0,"sport":1,"src":"::1","state"'
                    . ':1,"uid":0,"wqueue":0}]}',
            ],
            'getTweakSetting' => [
                'get_tweaksetting',
                'GET',
                [
                    'key' => 'fixture_key',
                    'module' => 'Main',
                ],
                'key=fixture_key&module=Main',
                '{"tweaksetting":{"key":"fixture_key","value":"0"}}',
            ],
            'getUpdateAvailability' => [
                'get_update_availability',
                'POST',
                [],
                '',
                '{"current_version":"11.138","newest_version":"11.140","tier":"release","update_avail'
                    . 'able":0}',
            ],
            'getMinimumPasswordStrengths' => [
                'getminimumpasswordstrengths',
                'GET',
                [
                    'name' => 'default',
                ],
                'name=default',
                '{"createacct":0,"default":50,"ftp":100,"list":1,"mysql":2,"passwd":3,"postgres":4,"s'
                    . 'shkey":5,"virtual":6}',
            ],
            'installedVersions' => [
                'installed_versions',
                'GET',
                [
                    'packages' => false,
                ],
                'packages=0',
                '{"apache":0,"apache_php_default_version":"8.3","apache_php_versions":[0,"8.3"],"bind'
                    . '":0,"clamav":0,"cpanel_and_whm":"11.138","cpanel_packages":[],"cpanel_php":0,"cron":'
                    . '0,"cronie":0,"dovecot":"2.3","ea_4_packages":["fixture"],"easyapache":0,"exim":0,"li'
                    . 'nux_kernel":"6.1","mailman":0,"mariadb":0,"mariadb_build":0,"munin":0,"mysql":0,"mys'
                    . 'ql_build":0,"nscd":0,"openssh-server":0,"operating_system_name":"FixtureOS","operati'
                    . 'ng_system_version":"1","os_packages":[],"p0f":0,"postgresql":0,"postgresql_build":0,'
                    . '"powerdns":0,"proftpd":0,"pureftpd":0,"roundcube":0,"rsyslog":0,"spamd":0}',
            ],
            'isRoleEnabled' => [
                'is_role_enabled',
                'GET',
                [
                    'role' => 'FTP',
                ],
                'role=FTP',
                '{"enabled":0}',
            ],
            'linkServerNodeWithApiToken' => [
                'link_server_node_with_api_token',
                'POST',
                [
                    'alias' => 'child',
                    'apiToken' => 'fixture/token',
                    'hostname' => 'node.example.test',
                    'username' => 'root',
                    'skipTlsVerification' => false,
                ],
                'alias=child&api_token=fixture%2Ftoken&hostname=node.example.test&username=root&skip_'
                    . 'tls_verification=0',
                null,
            ],
            'listConfigClusterServers' => [
                'list_configclusterservers',
                'GET',
                [],
                '',
                '{"node.example.test":{"signature":"fixture-signature","user":"root"},"other.example.'
                    . 'test":{}}',
            ],
            'listConnectedApplications' => [
                'list_connected_applications',
                'POST',
                [],
                '',
                '{"list":[{"jwt":{"opaque":[null,1]},"name":"Fixture","private_key":"key-id","public_'
                    . 'key":"public-id","token_name":"fixture-token-name","future":{"nested":false}}]}',
            ],
            'listLinkedServerNodes' => [
                'list_linked_server_nodes',
                'GET',
                [],
                '',
                '{"payload":[{"alias":"child","enabled_services":[],"hostname":"node.example.test","l'
                    . 'ast_check":0,"tls_verified":1,"username":"root","version":"11.138","worker_capabilit'
                    . 'ies":{"Mail":{"future":null}}}]}',
            ],
            'listUserChildNodes' => [
                'list_user_child_nodes',
                'GET',
                [],
                '',
                '{"payload":[{"alias":"child","type":"Mail","user":"fixture"}]}',
            ],
            'manageFeatures' => [
                'manage_features',
                'POST',
                [
                    'action' => 'info',
                    'features' => 'feature1,feature2',
                ],
                'action=info&features=feature1%2Cfeature2',
                '{"action":[{"feature":"fixture","status":"success"}],"feature":[{"enabled":0,"featur'
                    . 'e_key":"fixture","link":"https://example.test","name":"Fixture","recommended":1,"ven'
                    . 'dor":"Fixture"}]}',
            ],
            'participateInAnalytics' => [
                'participate_in_analytics',
                'POST',
                [
                    'enabled' => false,
                ],
                'enabled=0',
                null,
            ],
            'purchaseALicense' => [
                'purchase_a_license',
                'POST',
                [
                    'loginToken' => 'fixture/token',
                    'provider' => 'cPStore',
                    'urlAfterCheckout' => 'https://example.test/?token=fixture',
                    'upgrade' => false,
                ],
                'login_token=fixture%2Ftoken&provider=cPStore&url_after_checkout=https%3A%2F%2Fexampl'
                    . 'e.test%2F%3Ftoken%3Dfixture&upgrade=0',
                '{"payload":["https://example.test/checkout?token=fixture"]}',
            ],
            'reboot' => [
                'reboot',
                'POST',
                [
                    'force' => false,
                ],
                'force=0',
                null,
            ],
            'removeConnectedApplication' => [
                'remove_connected_application',
                'POST',
                [
                    'name' => 'fixture app',
                ],
                'name=fixture%20app',
                null,
            ],
            'restartService' => [
                'restartservice',
                'POST',
                [
                    'service' => 'exim',
                    'queueTask' => true,
                ],
                'service=exim&queue_task=1',
                '{"output":"queued fixture","service":"exim"}',
            ],
            'restoreConfigFromFile' => [
                'restore_config_from_file',
                'POST',
                [
                    'module' => 'Main',
                    'path' => '/fixture/config',
                ],
                'module=Main&path=%2Ffixture%2Fconfig',
                null,
            ],
            'runCpkeyclt' => [
                'run_cpkeyclt',
                'POST',
                [],
                '',
                null,
            ],
            'sendTestPostUrl' => [
                'send_test_posturl',
                'POST',
                [
                    'url' => 'https%3A%2F%2Fexample.test%2F%3Ftoken%3Dfixture',
                ],
                'url=https%253A%252F%252Fexample.test%252F%253Ftoken%253Dfixture',
                '{"message_id":"fixture-id"}',
            ],
            'sendTestPushbulletNote' => [
                'send_test_pushbullet_note',
                'POST',
                [
                    'accessToken' => 'fixture/token',
                ],
                'access_token=fixture%2Ftoken',
                '{"message_id":"fixture-id","payload":{"future":[1,null,false]}}',
            ],
            'serviceStatus' => [
                'servicestatus',
                'GET',
                [
                    'service' => 'mysql',
                ],
                'service=mysql',
                '{"service":[{"display_name":"MySQL","enabled":0,"installed":1,"monitored":0,"name":"'
                    . 'mysql","running":1}]}',
            ],
            'setApplicationContactEventImportance' => [
                'set_application_contact_event_importance',
                'POST',
                [
                    'app' => 'Fixture',
                    'event' => 'Check',
                    'importance' => 'Disabled',
                ],
                'app=Fixture&event=Check&importance=Disabled',
                null,
            ],
            'setApplicationContactImportance' => [
                'set_application_contact_importance',
                'POST',
                [
                    'app' => 'Fixture',
                    'importance' => 'High',
                ],
                'app=Fixture&importance=High',
                null,
            ],
            'setCpanelUpdates' => [
                'set_cpanel_updates',
                'POST',
                [
                    'updates' => 'manual',
                ],
                'updates=manual',
                '{"updates":"manual"}',
            ],
            'setPrimaryServername' => [
                'set_primary_servername',
                'POST',
                [
                    'servername' => 'node.example.test',
                    'type' => 'ssl',
                ],
                'servername=node.example.test&type=ssl',
                null,
            ],
            'setServiceConfigKey' => [
                'set_service_config_key',
                'POST',
                [
                    'key' => 'fixture',
                    'service' => 'dovecot',
                    'value' => 0,
                ],
                'key=fixture&service=dovecot&value=0',
                null,
            ],
            'setServiceProxyBackends' => [
                'set_service_proxy_backends',
                'POST',
                [
                    'username' => 'fixture',
                    'general' => 'proxy.example.test',
                    'serviceGroup' => [
                        'Mail',
                        'Mail',
                    ],
                    'serviceGroupBackend' => [
                        'mail1.example.test',
                        'mail2.example.test',
                    ],
                ],
                'username=fixture&general=proxy.example.test&service_group=Mail&service_group-1=Mail&'
                    . 'service_group_backend=mail1.example.test&service_group_backend-1=mail2.example.test',
                null,
            ],
            'setTier' => [
                'set_tier',
                'POST',
                [
                    'tier' => 'lts',
                ],
                'tier=lts',
                '{"tier":"lts"}',
            ],
            'setTweakSetting' => [
                'set_tweaksetting',
                'POST',
                [
                    'key' => 'fixture',
                    'module' => 'Main',
                    'value' => '',
                ],
                'key=fixture&module=Main&value=',
                null,
            ],
            'setHostname' => [
                'sethostname',
                'POST',
                [
                    'hostname' => 'node.example.test',
                ],
                'hostname=node.example.test',
                null,
            ],
            'setMinimumPasswordStrengths' => [
                'setminimumpasswordstrengths',
                'POST',
                [
                    'createacct' => 1,
                    'default' => 2,
                    'ftp' => 3,
                    'list' => 4,
                    'mysql' => 5,
                    'passwd' => 6,
                    'postgres' => 7,
                    'sshkey' => 8,
                    'virtual' => 9,
                ],
                'createacct=1&default=2&ftp=3&list=4&mysql=5&passwd=6&postgres=7&sshkey=8&virtual=9',
                null,
            ],
            'startCpanelUpdate' => [
                'start_cpanel_update',
                'POST',
                [
                    'mode' => 'sync',
                ],
                'mode=sync',
                '{"is_new":0,"log_path":"/fixture/log","pid":2}',
            ],
            'startProfileActivation' => [
                'start_profile_activation',
                'POST',
                [
                    'code' => 'MAILNODE',
                    'optional' => '{"SpamFilter":1,"DNS":0}',
                ],
                'code=MAILNODE&optional=%7B%22SpamFilter%22%3A1%2C%22DNS%22%3A0%7D',
                '{"log_id":"fixture-log"}',
            ],
            'unlinkServerNode' => [
                'unlink_server_node',
                'POST',
                [
                    'alias' => 'child',
                    'handleApiToken' => 'expire_24h',
                ],
                'alias=child&handle_api_token=expire_24h',
                '{"alias":"child","enabled_services":[],"hostname":"node.example.test","last_check":0'
                    . ',"system_settings":{"Mail":{"globalspamassassin":"0"}},"tls_verified":0,"username":"'
                    . 'root","version":"11.138","worker_capabilities":{"Mail":{"opaque":[null]}}}',
            ],
            'unsetAllServiceProxyBackends' => [
                'unset_all_service_proxy_backends',
                'POST',
                [
                    'username' => 'fixture',
                ],
                'username=fixture',
                null,
            ],
            'updateConfigClusterServer' => [
                'update_configclusterserver',
                'POST',
                [
                    'name' => 'node.example.test',
                    'key' => 'fixture/key',
                    'user' => 'root',
                ],
                'name=node.example.test&key=fixture%2Fkey&user=root',
                null,
            ],
            'updateContactEmail' => [
                'update_contact_email',
                'POST',
                [
                    'contactEmail' => 'fixture+tag@example.test',
                ],
                'contact_email=fixture%2Btag%40example.test',
                null,
            ],
            'updateLinkedServerNode' => [
                'update_linked_server_node',
                'POST',
                [
                    'alias' => 'child',
                    'apiToken' => 'fixture/token',
                    'hostname' => 'node.example.test',
                    'skipTlsVerification' => false,
                    'username' => 'root',
                ],
                'alias=child&api_token=fixture%2Ftoken&hostname=node.example.test&skip_tls_verificati'
                    . 'on=0&username=root',
                null,
            ],
            'updateUpdateconf' => [
                'update_updateconf',
                'POST',
                [
                    'cpanel' => 'RELEASE',
                    'rpmup' => 'manual',
                    'sarulesup' => 'never',
                    'securityUpdates' => 'hourly',
                    'stagingDir' => '/fixture/update dir',
                    'updates' => 'manual',
                ],
                'CPANEL=RELEASE&RPMUP=manual&SARULESUP=never&SECURITY_UPDATES=hourly&STAGING_DIR=%2Ff'
                    . 'ixture%2Fupdate%20dir&UPDATES=manual',
                null,
            ],
            'verifyPostUrlAccess' => [
                'verify_posturl_access',
                'POST',
                [],
                '',
                '{"results":[{"result":{"message_id":"fixture","payload":{"content":"<b>fixture</b>",'
                    . '"headers":{"x-fixture":"value","set-cookie":"fixture-secret"},"protocol":"HTTP/1.1",'
                    . '"reason":"OK","status":"200","success":0,"url":"https://example.test"}},"url":"https'
                    . '://example.test?token=fixture"}]}',
            ],
            'verifyPushbulletAccess' => [
                'verify_pushbullet_access',
                'POST',
                [],
                '',
                '{"results":[{"access_token":"fixture-secret","result":{"message_id":"fixture","paylo'
                    . 'ad":{"opaque":[false,null]}}}]}',
            ],
            'verifySlackAccess' => [
                'verify_slack_access',
                'POST',
                [],
                '',
                '{"results":[{"result":{"message_id":1,"payload":{"content":null,"headers":{"access-c'
                    . 'ontrol-allow-origin":false,"content-type":"fixture","date":1,"referrer-policy":null,'
                    . '"server":"fixture","strict-transport-security":{},"transfer-encoding":[],"vary":"fix'
                    . 'ture","x-frame-options":false,"x-slack-backend":"fixture","x-via":0},"protocol":fals'
                    . 'e,"reason":null,"status":200,"success":true,"url":{"opaque":1}}},"url":null}]}',
            ],
            'wpDashboardGetHostname' => [
                'wp_dashboard_get_hostname',
                'GET',
                [],
                '',
                '{"hostname":"node.example.test"}',
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('requests')]
    public function testPinnedRequestAndPopulatedPayload(
        string $method,
        string $function,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $server = $client->whm()->server();
        self::assertTrue(method_exists($server, $method), $method . ' must be generated.');
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        $expected = $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::ordered($expected), self::ordered(self::invoke($server, $method, $arguments)));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?api.version=1'
                . ($verb === 'GET' && $wire !== '' ? '&' . $wire : ''),
            (string) $request->getUri(),
        );
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertNull($client->uapi('fixture')->responseMeta());
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('requests')]
    public function testApiFailureDoesNotRetry(
        string $method,
        string $function,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        self::assertTrue(method_exists($client->whm()->server(), $method));
        $http->addResponse(new Response(200, [], self::envelope($function, $payload, 0)));
        try {
            self::invoke($client->whm()->server(), $method, $arguments);
            self::fail('A failed API outcome must throw.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function invalidRoots(): iterable
    {
        foreach (self::cases() as $method => [, , , , $payload]) {
            if ($payload === null) {
                continue;
            }
            foreach ([null, 'null', 'false', '1', '1.5', '"opaque"', '[1]'] as $index => $invalid) {
                yield $method . ' root ' . $index => [$method, $invalid];
            }
        }
    }

    #[DataProvider('invalidRoots')]
    #[DataProvider('invalidLeaves')]
    public function testPayloadMismatchKeepsConfirmedOutcome(string $method, ?string $payload): void
    {
        [$client, $http] = self::client();
        self::assertTrue(method_exists($client->whm()->server(), $method));
        [$function, , $arguments] = self::cases()[$method];
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        try {
            self::invoke($client->whm()->server(), $method, $arguments);
            self::fail('A malformed typed payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertNotNull($error->metadata);
            self::assertSame(1, $error->metadata->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLeaves(): iterable
    {
        $cases = [
            'isEcosystemFeatureReady' => ['{"ready":"0"}', '{"ready":2}', '{"ready":false}'],
            'enableMonitorAllEnabledServices' => ['{"services":{}}', '{"services":[{"monitored":true}]}'],
            'fetchConnectedApplication' => ['{"data":{"private_key":1}}', '{"data":{"jwt":[1]}}',
                '{"data":{"privileges_granted":[0]}}', '{"name":null}'],
            'fetchSecurityAdvice' => ['{"payload":[{"advice":{"suggestion":1}}]}',
                '{"payload":[{"advice":{"summary":null}}]}'],
            'forceDedistributionFromNode' => ['{"log":[{"indent":"0"}]}', '{"user_info":[{"username":1}]}'],
            'getAllContactImportances' => ['{"importances":[{"importance":"0.5"}]}'],
            'getApplicationContactEventImportance' => ['{"importance":false}'],
            'getApplicationContactImportance' => ['{"importance":"00"}'],
            'getAvailableProfiles' => ['{"profiles":[{"optional_roles":[{"module":1}]}]}',
                '{"profiles":[{"experimental":"0"}]}'],
            'getAvailableTiers' => ['{"future":1}', '{"current":null}'],
            'getCurrentLtsExpirationStatus' => ['{"expiration":"0"}', '{"expires_in_next_three_months":2}'],
            'getCurrentProfile' => ['{"enabled_roles":[{"name":null}]}', '{"experimental":true}'],
            'getLinkedServerNode' => ['{"system_settings":{"Mail":1}}', '{"tls_verified":"1"}',
                '{"worker_capabilities":{"Mail":false}}'],
            'getLtsWexpire' => ['{"flags":{"fixture":"1"}}', '{"branch":{"fixture":[{"is_main":2}]}}',
                '{"tiers":{"fixture":[{"named":[1]}]}}'],
            'getServerNodeStatus' => ['{"tls_verified":2}', '{"enabled_services":[1]}'],
            'getServiceConfig' => ['{"fixture":null}', '{"fixture":false}', '{"fixture":1.5}'],
            'getServiceConfigKey' => ['{"fixture_key":1.5}', '{"fixture_key":null}'],
            'getServiceProxyBackends' => ['{"payload":[{"backend":null}]}', '{"payload":[{"service_group":1}]}'],
            'getTcp4Sockets' => ['{"payload":[{"dport":"0"}]}'],
            'getTcp6Sockets' => ['{"payload":[{"src":1}]}'],
            'getUdp4Sockets' => ['{"payload":[{"rqueue":null}]}'],
            'getUdp6Sockets' => ['{"payload":[{"state":false}]}'],
            'getTweakSetting' => ['{"tweaksetting":{"value":0}}'],
            'getUpdateAvailability' => ['{"update_available":"0"}', '{"current_version":0}'],
            'getMinimumPasswordStrengths' => ['{"default":"01"}', '{"mysql":null}'],
            'installedVersions' => ['{"apache":false}', '{"apache_php_versions":[null]}',
                '{"cpanel_packages":[1]}', '{"cpanel_and_whm":0}'],
            'isRoleEnabled' => ['{"enabled":"00"}', '{"enabled":2}'],
            'listConfigClusterServers' => ['{"node":{"signature":null}}', '{"node":false}'],
            'listConnectedApplications' => ['{"list":[{"jwt":false}]}', '{"list":[{"token_name":1}]}'],
            'listLinkedServerNodes' => ['{"payload":[{"last_check":"0"}]}', '{"payload":[{"tls_verified":null}]}'],
            'listUserChildNodes' => ['{"payload":[{"user":1}]}'],
            'manageFeatures' => ['{"feature":[{"recommended":true}]}', '{"action":[{"status":0}]}'],
            'purchaseALicense' => ['{"payload":[1]}'],
            'restartService' => ['{"output":null}'],
            'sendTestPostUrl' => ['{"message_id":1}'],
            'sendTestPushbulletNote' => ['{"payload":false}'],
            'serviceStatus' => ['{"service":[{"running":"0"}]}', '{"service":[{"enabled":2}]}'],
            'setCpanelUpdates' => ['{"updates":1}'],
            'setTier' => ['{"tier":null}'],
            'startCpanelUpdate' => ['{"is_new":"0"}', '{"pid":"2"}'],
            'startProfileActivation' => ['{"log_id":1}'],
            'unlinkServerNode' => ['{"last_check":"0"}', '{"worker_capabilities":{"Mail":1}}'],
            'verifyPostUrlAccess' => ['{"results":[{"result":{"payload":{"success":"0"}}}]}',
                '{"results":[{"result":{"payload":{"status":200}}}]}'],
            'verifyPushbulletAccess' => ['{"results":[{"access_token":1}]}'],
            'verifySlackAccess' => ['{"results":[{"result":{"payload":{"headers":false}}}]}'],
            'wpDashboardGetHostname' => ['{"hostname":null}'],
        ];
        foreach ($cases as $method => $payloads) {
            foreach ($payloads as $index => $payload) {
                yield $method . ' leaf ' . $index => [$method, $payload];
            }
        }
    }

    public function testEmptyObjectsPreserveOmissionAndVoidIgnoresPayload(): void
    {
        [$client, $http] = self::client();
        foreach (self::cases() as $method => [$function, , $arguments, , $payload]) {
            self::assertTrue(method_exists($client->whm()->server(), $method));
            $http->addResponse(new Response(200, [], self::envelope($function, '{}')));
            self::assertSame(
                $payload === null ? null : [],
                self::invoke($client->whm()->server(), $method, $arguments),
            );
        }
        self::assertCount(count(self::cases()), $http->getRequests());
    }

    public function testAllNewParametersHaveLiteralWireCoverageAndSensitiveInputs(): void
    {
        foreach (self::cases() as $method => [, , $arguments]) {
            self::assertTrue(method_exists(Server::class, $method));
            $reflection = new ReflectionMethod(Server::class, $method);
            self::assertSame(
                array_keys($arguments),
                array_map(static fn ($p): string => $p->getName(), $reflection->getParameters()),
            );
        }
        foreach (
            [
            ['addConfigClusterServer', 'key'], ['updateConfigClusterServer', 'key'],
            ['getServerNodeStatus', 'apiToken'], ['linkServerNodeWithApiToken', 'apiToken'],
            ['updateLinkedServerNode', 'apiToken'], ['purchaseALicense', 'loginToken'],
            ['purchaseALicense', 'urlAfterCheckout'], ['sendTestPostUrl', 'url'],
            ['sendTestPushbulletNote', 'accessToken'], ['setServiceConfigKey', 'value'],
            ['setTweakSetting', 'value'], ['startProfileActivation', 'optional'],
            ['configureBackgroundProcessKiller', 'processesToKill'],
            ] as [$method, $parameter]
        ) {
            $parameters = (new ReflectionMethod(Server::class, $method))->getParameters();
            $matching = array_values(array_filter($parameters, static fn ($p): bool => $p->getName() === $parameter));
            self::assertCount(1, $matching);
            self::assertCount(1, $matching[0]->getAttributes(SensitiveParameter::class), $method . ' ' . $parameter);
        }
        $identifiers = [
            ['getServiceConfigKey', 'key'], ['setServiceConfigKey', 'key'], ['getTweakSetting', 'key'],
        ];
        foreach ($identifiers as [$method, $name]) {
            $parameters = (new ReflectionMethod(Server::class, $method))->getParameters();
            foreach ($parameters as $parameter) {
                if ($parameter->getName() === $name) {
                    self::assertSame([], $parameter->getAttributes(SensitiveParameter::class));
                }
            }
        }
    }

    public function testOmittedDefaultsAndExplicitZeroRemainDifferent(): void
    {
        [$client, $http] = self::client();
        foreach (
            [
            ['configureService', ['service' => 'mysql'], 'service=mysql'],
            ['configureService', ['service' => 'mysql', 'enabled' => false, 'monitored' => false],
                'service=mysql&enabled=0&monitored=0'],
            ['reboot', [], ''], ['reboot', ['force' => false], 'force=0'],
            ['setTweakSetting', ['key' => 'fixture'], 'key=fixture'],
            ['setTweakSetting', ['key' => 'fixture', 'value' => ''], 'key=fixture&value='],
            ['startProfileActivation', ['code' => 'MAILNODE'], 'code=MAILNODE'],
            ['startProfileActivation', ['code' => 'MAILNODE', 'optional' => '{"DNS":0}'],
                'code=MAILNODE&optional=%7B%22DNS%22%3A0%7D'],
            ['startCpanelUpdate', [], ''], ['startCpanelUpdate', ['mode' => null], ''],
            ['updateLinkedServerNode', ['alias' => 'child'], 'alias=child'],
            ['updateLinkedServerNode', ['alias' => 'child', 'skipTlsVerification' => false],
                'alias=child&skip_tls_verification=0'],
            ['setServiceConfigKey', ['key' => 'fixture', 'service' => 'dovecot', 'value' => '0'],
                'key=fixture&service=dovecot&value=0'],
            ['configureBackgroundProcessKiller', ['processesToKill' => 'fixture'],
                'processes_to_kill=fixture'],
            ] as [$method, $arguments, $expected]
        ) {
            [$function] = self::cases()[$method];
            self::assertTrue(method_exists($client->whm()->server(), $method));
            $http->addResponse(new Response(200, [], self::envelope($function, '{}')));
            self::invoke($client->whm()->server(), $method, $arguments);
             $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($expected, (string) $request->getBody());
        }
    }

    public function testInvalidMultiValueListsFailBeforeDispatchAfterMetadataReset(): void
    {
        foreach ([[], [1], [false], [null], [1 => 'fixture'], ['named' => 'fixture']] as $invalid) {
            [$client, $http] = self::client();
            try {
                self::invoke(
                    $client->whm()->server(),
                    'forceDedistributionFromNode',
                    ['nodeAlias' => 'child', 'users' => $invalid],
                );
                self::fail('Invalid list must fail.');
            } catch (InvalidArgumentException) {
                self::assertNull($client->whm()->responseMeta());
                self::assertSame([], $http->getRequests());
            }
        }
    }

    public function testPinnedRequirednessAndNullDefaultsRemainExplicit(): void
    {
        // Literal source-derived required arguments. All other fixture arguments are optional with null omission.
        $required = [
            'isEcosystemFeatureReady' => ['plugin'],
            'disableEcosystemFeatureLocally' => ['plugin'],
            'enableEcosystemFeatureLocally' => ['plugin'],
            'addConfigClusterServer' => ['key', 'name', 'user'],
            'configureBackgroundProcessKiller' => ['processesToKill'],
            'configureService' => ['service'],
            'deleteConfigClusterServer' => ['name'],
            'fetchConnectedApplication' => ['name'],
            'forceDedistributionFromNode' => ['nodeAlias', 'users'],
            'getApplicationContactEventImportance' => ['app', 'event'],
            'getApplicationContactImportance' => ['app'],
            'getLinkedServerNode' => ['alias'],
            'getServerNodeStatus' => ['apiToken', 'hostname', 'username'],
            'getServiceConfig' => ['service'],
            'getServiceConfigKey' => ['key', 'service'],
            'getServiceProxyBackends' => ['username'],
            'getTweakSetting' => ['key'],
            'isRoleEnabled' => ['role'],
            'linkServerNodeWithApiToken' => ['alias', 'apiToken', 'hostname', 'username'],
            'manageFeatures' => ['action'],
            'participateInAnalytics' => ['enabled'],
            'purchaseALicense' => ['loginToken', 'provider', 'urlAfterCheckout'],
            'removeConnectedApplication' => ['name'],
            'restartService' => ['service'],
            'restoreConfigFromFile' => ['module', 'path'],
            'sendTestPostUrl' => ['url'],
            'sendTestPushbulletNote' => ['accessToken'],
            'setApplicationContactEventImportance' => ['app', 'event', 'importance'],
            'setApplicationContactImportance' => ['app', 'importance'],
            'setCpanelUpdates' => ['updates'],
            'setPrimaryServername' => ['servername'],
            'setServiceConfigKey' => ['key', 'service', 'value'],
            'setServiceProxyBackends' => ['username'],
            'setTier' => ['tier'],
            'setTweakSetting' => ['key'],
            'setHostname' => ['hostname'],
            'startProfileActivation' => ['code'],
            'unlinkServerNode' => ['alias'],
            'unsetAllServiceProxyBackends' => ['username'],
            'updateConfigClusterServer' => ['name'],
            'updateContactEmail' => ['contactEmail'],
            'updateLinkedServerNode' => ['alias'],
        ];
        foreach (self::cases() as $method => [, , $arguments]) {
            $actualRequired = [];
            foreach ((new ReflectionMethod(Server::class, $method))->getParameters() as $parameter) {
                if (!$parameter->isOptional()) {
                    $actualRequired[] = $parameter->getName();
                    continue;
                }
                self::assertNull($parameter->getDefaultValue(), $method . ' ' . $parameter->getName());
                self::assertTrue($parameter->allowsNull(), $method . ' ' . $parameter->getName());
            }
            self::assertSame($required[$method] ?? [], $actualRequired, $method);
        }
    }

    public function testNativeBoundariesKeepScalarJsonTextAndBooleanFlags(): void
    {
        foreach (self::cases() as $method => [, , $arguments]) {
            foreach ((new ReflectionMethod(Server::class, $method))->getParameters() as $parameter) {
                if (is_bool($arguments[$parameter->getName()])) {
                    self::assertSame(
                        $parameter->isOptional() ? '?bool' : 'bool',
                        (string) $parameter->getType(),
                        $method . ' ' . $parameter->getName(),
                    );
                }
            }
        }
        $scalarTypes = [
            ['startProfileActivation', 'optional', '?string'],
            ['setServiceConfigKey', 'value', 'string|int'],
        ];
        foreach ($scalarTypes as [$method, $name, $type]) {
            foreach ((new ReflectionMethod(Server::class, $method))->getParameters() as $parameter) {
                if ($parameter->getName() === $name) {
                    self::assertSame(
                        $type,
                        (string) $parameter->getType(),
                    );
                }
            }
        }
        // Unsupported transports and the outside-data reboot result must not masquerade as usable typed methods.
        foreach (['saveConnectedApplication', 'systemNeedsReboot'] as $method) {
            self::assertFalse(method_exists(Server::class, $method));
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    private static function observedIntegerFields(): iterable
    {
        yield 'notification priority' => ['getAllContactImportances', 'get_all_contact_importances', 'importance'];
        yield 'application priority' => [
            'getApplicationContactImportance', 'get_application_contact_importance', 'importance',
        ];
        yield 'application event priority' => [
            'getApplicationContactEventImportance', 'get_application_contact_event_importance', 'importance',
        ];
        $fields = ['createacct', 'default', 'ftp', 'list', 'mysql', 'passwd', 'postgres', 'sshkey', 'virtual'];
        foreach ($fields as $field) {
            yield $field => ['getMinimumPasswordStrengths', 'getminimumpasswordstrengths', $field];
        }
    }

    /** @return iterable<string, array{string, string, string, string, int}> */
    public static function canonicalObservedIntegerStrings(): iterable
    {
        foreach (self::observedIntegerFields() as $label => [$method, $function, $field]) {
            // Boundary formats are policy tests for the existing integer converter, not additional live observations.
            foreach (['0', '1', '25', '-1', '-25', (string) PHP_INT_MAX, (string) PHP_INT_MIN] as $wire) {
                yield $label . ' ' . $wire => [$method, $function, $field, $wire, (int) $wire];
            }
        }
    }

    #[DataProvider('canonicalObservedIntegerStrings')]
    public function testObservedServerIntegerStringsNormalizeLosslessly(
        string $method,
        string $function,
        string $field,
        string $wire,
        int $expected,
    ): void {
        [$client, $http] = self::client();
        $payload = self::integerFieldPayload($method, $field, $wire);
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        [$arguments, $query] = match ($method) {
            'getApplicationContactImportance' => [['app' => 'ChkServd'], '&app=ChkServd'],
            'getApplicationContactEventImportance' => [
                ['app' => 'ChkServd', 'event' => 'Check'], '&app=ChkServd&event=Check',
            ],
            default => [[], ''],
        };
        try {
            $actual = self::invoke($client->whm()->server(), $method, $arguments);
        } catch (PayloadMismatchException) {
            self::fail('The reviewed field must accept a lossless canonical integer string.');
        }
        $expectedPayload = json_decode(
            self::integerFieldPayload($method, $field, $expected),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($expectedPayload, $actual);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?api.version=1' . $query,
            (string) $request->getUri(),
        );
        self::assertSame('', (string) $request->getBody());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function invalidObservedIntegerRepresentations(): iterable
    {
        $invalid = [
            '', '00', '01', '-0', '-00', '-01', '+0', '+1', ' 1', '1 ', "1\n", "\t1",
            '1.0', '1.00', '1.5', '-1.5', '1e0', '1E1', 'NaN', 'INF', 'unlimited',
            (string) PHP_INT_MAX . '0', (string) PHP_INT_MIN . '0',
            true, false, null, 0.0, 1.0, 1.5, [], ['nested' => 1],
        ];
        foreach (self::observedIntegerFields() as $label => [$method, , $field]) {
            foreach ($invalid as $index => $value) {
                yield $label . ' invalid ' . $index => [$method, self::integerFieldPayload($method, $field, $value)];
            }
        }
        // Root and sibling contracts stay strict; only the reviewed integer leaves opt in.
        foreach (['"0"', '"25"', 'true', '["1"]'] as $index => $payload) {
            yield 'priority root ' . $index => ['getAllContactImportances', $payload];
            yield 'strength root ' . $index => ['getMinimumPasswordStrengths', $payload];
        }
        foreach (
            [
            ['getAllContactImportances', '{"importances":"1"}'],
            ['getAllContactImportances', '{"importances":[null]}'],
            ['getAllContactImportances', '{"importances":[{"app":1,"importance":"1"}]}'],
            ['getAllContactImportances', '{"importances":[{"event":null,"importance":"1"}]}'],
            ['getAllContactImportances', '{"importances":[{"name":1,"importance":"1"}]}'],
            ['getApplicationContactImportance', '{"importance":"1","name":1}'],
            ['getApplicationContactEventImportance', '{"importance":"1","name":null}'],
            ['getCurrentLtsExpirationStatus', '{"expiration":"1"}'],
            ['getCurrentLtsExpirationStatus', '{"expires_in_next_three_months":"1"}'],
            ['getCurrentProfile', '{"experimental":"1"}'],
            ['serviceStatus', '{"service":[{"running":"1"}]}'],
            ['getUpdateAvailability', '{"update_available":"1"}'],
            ['getServerNodeStatus', '{"tls_verified":"1"}'],
            ] as $index => $case
        ) {
            yield 'unaffected sibling ' . $index => $case;
        }
    }

    #[DataProvider('invalidObservedIntegerRepresentations')]
    public function testObservedIntegerCompatibilityRejectsUnreviewedVariants(string $method, ?string $payload): void
    {
        $this->testPayloadMismatchKeepsConfirmedOutcome($method, $payload);
    }

    public function testObservedIntegerLeavesKeepOptionalFieldsAndStringSiblings(): void
    {
        [$client, $http] = self::client();
        foreach (
            [
            ['getAllContactImportances', 'get_all_contact_importances',
                '{"importances":[{}, {"app":"001","event":"0","importance":"0","name":"1"},'
                    . '{"importance":1},{"importance":"3"}]}',
                ['importances' => [
            [], ['app' => '001', 'event' => '0', 'importance' => 0, 'name' => '1'],
                    ['importance' => 1], ['importance' => 3]]]],
            ['getAllContactImportances', 'get_all_contact_importances', '{"importances":[]}', ['importances' => []]],
            ['getMinimumPasswordStrengths', 'getminimumpasswordstrengths',
                '{"createacct":"0","default":1,"ftp":"25","list":2,"mysql":"50",'
                    . '"passwd":3,"postgres":"75","sshkey":4,"virtual":"100"}',
                ['createacct' => 0, 'default' => 1, 'ftp' => 25, 'list' => 2, 'mysql' => 50,
                    'passwd' => 3, 'postgres' => 75, 'sshkey' => 4, 'virtual' => 100]],
            ['getMinimumPasswordStrengths', 'getminimumpasswordstrengths', '{"default":"0"}', ['default' => 0]],
            ] as [$method, $function, $payload, $expected]
        ) {
            $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
            self::assertSame($expected, self::invoke($client->whm()->server(), $method, []));
        }
        self::assertCount(4, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function reviewedServerPayloads(): iterable
    {
        foreach (['0', '1'] as $flag) {
            yield 'quoted role ' . $flag => ['isRoleEnabled', '{"enabled":"' . $flag . '"}',
                ['enabled' => (int) $flag]];
            yield 'native role ' . $flag => ['isRoleEnabled', '{"enabled":' . $flag . '}', ['enabled' => (int) $flag]];
        }
        foreach (['getApplicationContactImportance', 'getApplicationContactEventImportance'] as $method) {
            yield $method . ' optional priority' => [$method, '{"name":"0"}', ['name' => '0']];
            yield $method . ' name remains a string' => [$method, '{"importance":"0","name":"1"}',
                ['importance' => 0, 'name' => '1']];
            yield $method . ' native integer boundary' => [$method, '{"importance":' . PHP_INT_MAX . '}',
                ['importance' => PHP_INT_MAX]];
        }
        foreach (['getServiceConfig', 'getServiceConfigKey'] as $method) {
            yield $method . ' string retains exact text' => [$method, '{"fixture_key":"001.00"}',
                ['fixture_key' => '001.00']];
            yield $method . ' integer zero' => [$method, '{"fixture_key":0}', ['fixture_key' => 0]];
            yield $method . ' integer bounds' => [$method,
                '{"first":' . PHP_INT_MIN . ',"second":' . PHP_INT_MAX . '}',
                ['first' => PHP_INT_MIN, 'second' => PHP_INT_MAX]];
            yield $method . ' empty object value' => [$method, '{"quota":{}}', ['quota' => []]];
            yield $method . ' object keys retain opaque nested values' => [$method,
                '{"quota":{"rule":"*:storage=0","flag":false},"namespace_private":{"prefix":"",'
                    . '"nested":{"future":[null,1,true]}},"protocol_imap":{"mail_max_userip_connections":20}}',
                [
                    'quota' => ['rule' => '*:storage=0', 'flag' => false],
                    'namespace_private' => ['prefix' => '', 'nested' => ['future' => [null, 1, true]]],
                    'protocol_imap' => ['mail_max_userip_connections' => 20],
                ]];
        }
        yield 'child node documented empty object' => ['getServerNodeStatus',
            '{"enabled_services":["exim"],"remote_node_linkages":{},"system_settings":{"Mail":{"flag":0}},'
                . '"tls_verified":1,"version":"11.138"}',
            ['enabled_services' => ['exim'], 'remote_node_linkages' => [],
                'system_settings' => ['Mail' => ['flag' => 0]], 'tls_verified' => 1, 'version' => '11.138']];
        yield 'node linkages empty list' => ['getServerNodeStatus', '{"remote_node_linkages":[]}',
            ['remote_node_linkages' => []]];
        yield 'node linkages retain opaque list row' => ['getServerNodeStatus',
            '{"remote_node_linkages":[{"alias":"child","enabled_services":["exim"],"last_check":0,'
                . '"future":{"opaque":[null,true]}}]}',
            ['remote_node_linkages' => [['alias' => 'child', 'enabled_services' => ['exim'], 'last_check' => 0,
                'future' => ['opaque' => [null, true]]]]]];
        yield 'tiers known and version keys coexist' => ['getAvailableTiers',
            '{"current":"11.90.0.3","edge":"11.90.0.3","lts":"11.86.0.24","release":"11.88.0.13",'
                . '"stable":"11.88.0.13","11.90":"11.90.0.3","future":"exact text"}',
            ['current' => '11.90.0.3', 'edge' => '11.90.0.3', 'lts' => '11.86.0.24', 'release' => '11.88.0.13',
                'stable' => '11.88.0.13', '11.90' => '11.90.0.3', 'future' => 'exact text']];
        foreach (['current', 'edge', 'lts', 'release', 'stable'] as $field) {
            yield 'tier optional ' . $field => ['getAvailableTiers', '{"' . $field . '":""}', [$field => '']];
        }
    }

    #[DataProvider('reviewedServerPayloads')]
    public function testReviewedServerVariantsPreserveValuesAndSendOneRequest(
        string $method,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        [$function, $verb, $arguments, $wire] = self::cases()[$method];
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));

        self::assertSame(
            self::ordered($expected),
            self::ordered(self::invoke($client->whm()->server(), $method, $arguments)),
        );
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?api.version=1'
                . ($verb === 'GET' && $wire !== '' ? '&' . $wire : ''),
            (string) $request->getUri(),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReviewedServerPayloads(): iterable
    {
        foreach (
            ['"00"', '"01"', '"-0"', '"+1"', '"1.0"', '"1e0"', '" 1"', '"1 "', '""', '"2"',
            'true', 'false', 'null', '0.0', '1.0', '2', '-1', '[]', '{}'] as $wire
        ) {
            yield 'role flag ' . $wire => ['isRoleEnabled', '{"enabled":' . $wire . '}'];
        }
        foreach (['getServiceConfig', 'getServiceConfigKey'] as $method) {
            foreach (['null', 'false', 'true', '1.5', '1.0', '[1]', '[{}]', '["value"]'] as $wire) {
                yield $method . ' value ' . $wire => [$method, '{"fixture_key":' . $wire . '}'];
            }
        }
        foreach (['null', 'false', 'true', '0', '"text"', '[1]', '[null]', '[false]'] as $wire) {
            yield 'node linkage ' . $wire => ['getServerNodeStatus', '{"remote_node_linkages":' . $wire . '}'];
        }
        foreach (['current', 'edge', 'lts', 'release', 'stable', '11.90', 'future'] as $field) {
            foreach (['null', '1', 'false', '{}', '[]'] as $wire) {
                yield 'tier ' . $field . ' ' . $wire => ['getAvailableTiers', '{"' . $field . '":' . $wire . '}'];
            }
        }
    }

    #[DataProvider('invalidReviewedServerPayloads')]
    public function testReviewedServerCompatibilityKeepsMalformedValuesAndOutcomesStrict(
        string $method,
        string $payload,
    ): void {
        $this->testPayloadMismatchKeepsConfirmedOutcome($method, $payload);
    }

    public function testAvailableTiersContractRetainsFivePinnedOptionalFieldsAndDynamicStrings(): void
    {
        $returns = ServerSpec::GET_AVAILABLE_TIERS['returns'];
        self::assertSame('object', $returns['type']);
        self::assertSame([
            'current' => ['type' => 'string'],
            'edge' => ['type' => 'string'],
            'lts' => ['type' => 'string'],
            'release' => ['type' => 'string'],
            'stable' => ['type' => 'string'],
        ], $returns['fields']);
        self::assertArrayNotHasKey('required', $returns);
        self::assertSame(['type' => 'string'], $returns['values']);
    }

    private static function integerFieldPayload(string $method, string $field, mixed $value): string
    {
        $payload = $method === 'getAllContactImportances'
            ? ['importances' => [[$field => $value]]]
            : [$field => $value];
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string, mixed> $arguments */
    private static function invoke(Server $server, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($server, $method))->invokeArgs($server, $arguments);
    }

    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::ordered(...), $value);
    }

    private static function envelope(string $function, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":' . json_encode($function, JSON_THROW_ON_ERROR)
            . ',"reason":"Fixture outcome","result":' . $result . ',"version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $http = new MockClient();
        $factory = new Psr17Factory();
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
