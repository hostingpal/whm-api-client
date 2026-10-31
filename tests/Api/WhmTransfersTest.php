<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\Whm\Spec\TransfersSpec;
use HostingPal\WhmApiClient\Api\Whm\Transfers;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Fixtures\WeakTransferCaller;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

final class WhmTransfersTest extends TestCase
{
    private const ANALYSIS = '{"config":{"shared_mysql_server":0},"items":{"accounts":[{"user":"fixture",'
        . '"priority":"255","extension":{"opaque":true}}],"packages":[{"name":"package","unknown":null}]},'
        . '"local":{"available_ips":["192.0.2.1","2001:db8::1"],"dbs":{"dbs":{"MYSQL":{"fixture_db":'
        . '{"owner":"fixture"}},"PGSQL":{}},"users":{"MYSQL":{},"PGSQL":{"fixture_user":{"owner":"fixture"}}}},'
        . '"dedicated_ips":{"192.0.2.1":"fixture.test"},"domains":{"*":"nobody","fixture.test":"fixture"},'
        . '"groups":{"fixture":1},"host":"local.test","major_version":"11.90","roundcube_dbtype":"sqlite",'
        . '"users":{"root":1},"version":"11.90.0.6"},"modules":{"Backups":{"analysis":'
        . '{"Local Backups Version":"11.90.0.6","Remote Backups Version":"11.88.0.7"},"name":"Backups"}},'
        . '"options":{"skip_reseller_privs":0,"unrestricted":1},"remote":{"conflicts":{"opaque":[1,null]},'
        . '"cpversion":"11.64","dbs":{"dbs":{"MYSQL":{"remote_db":{"owner":"remote"}},"PGSQL":{}},'
        . '"users":{"MYSQL":{},"PGSQL":{"remote_user":{"owner":"remote"}}}},"has_disk_used":1,'
        . '"has_files_used":0,"has_owners":1,"has_package_extensions":0,"has_xfertool":1,"host":"192.0.2.2",'
        . '"hostname":"remote.test","linked_nodes":[{"alias":"mailnode","enabled_services":["exim","imap"],'
        . '"hostname":"mail.test","last_check":0,"system_settings":{"Mail":{"globalspamassassin":0}},'
        . '"tls_verified":1,"username":"root","version":"11.90.0.6","worker_capabilities":{"Mail":'
        . '{"opaque":true}}}],"major_version":"11.90","resellers":{"reseller":1},"roundcube_dbtype":"mysql",'
        . '"server_type":"WHM1164","supports_live_transfers":1,"version":"11.90.0.6"},'
        . '"transfer_session_id":"session &id"}';

    public function testSelectorCachesWithoutDispatch(): void
    {
        [$client, $http] = self::client();
        self::assertInstanceOf(Transfers::class, $client->whm()->transfers());
        self::assertSame($client->whm()->transfers(), $client->whm()->transfers());
        self::assertSame([], $http->getRequests());
        self::assertNull($client->whm()->responseMeta());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>, string, string|null}> */
    public static function operations(): iterable
    {
        yield 'abort' => ['abortTransferSession', 'abort_transfer_session', 'POST', ['session &id'],
            'transfer_session_id=session%20%26id', null];
        yield 'analyze credentials' => ['analyzeTransferSessionRemote', 'analyze_transfer_session_remote', 'POST',
            ['session &id'], 'transfer_session_id=session%20%26id', null];
        yield 'available modules' => ['availableTransferModules', 'available_transfer_modules', 'GET', [], '',
            '{"modules":{"AccountRemoteRoot":"6000","PackageRemoteRoot":"255"}}'];
        yield 'root creation all fields' => ['createRemoteRootTransferSession', 'create_remote_root_transfer_session',
            'POST', ['remote &host.test', 'fixture user', 'ssh', false, true, false, true, 2, 3, false, false, true,
                'p& =+', 2222, 'su', 'root&secret', 'named key', 'key&pass'],
            'host=remote%20%26host.test&user=fixture%20user&comm_transport=ssh&compressed=0'
                . '&copy_reseller_privs=1&enable_custom_pkgacct=0'
                . '&low_priority=1&restore_threads=2&transfer_threads=3&unencrypted=0&unrestricted_restore=0'
                . '&use_backups=1&password=p%26%20%3D%2B&port=2222&root_escalation_method=su'
                . '&root_password=root%26secret&sshkey_name=named%20key&sshkey_passphrase=key%26pass',
            '{"analyze_rawout":"Synthetic remote details","create_rawout":"Synthetic credential output",'
                . '"transfer_session_id":"session &id"}'];
        yield 'nonroot creation no invented username' => ['createRemoteUserTransferSession',
            'create_remote_user_transfer_session', 'POST', ['remote.test', 'p& =+', true],
            'host=remote.test&password=p%26%20%3D%2B&unrestricted_restore=1', '{"transfer_session_id":"session"}'];
        yield 'archive deletion explicit empty location' => ['deleteAccountArchives', 'delete_account_archives',
            'POST', ['fixture &user', ''], 'user=fixture%20%26user&mountpoint=', null];
        yield 'log text stays text' => ['fetchTransferSessionLog', 'fetch_transfer_session_log', 'GET',
            ['session &id', 'item-TRANSFER_AccountRemoteUser_fixture'],
            'transfer_session_id=session%20%26id&logfile=item-TRANSFER_AccountRemoteUser_fixture',
            '{"log":"{\"action\":\"complete\"}\nSynthetic private path /home/fixture\n"}'];
        yield 'state' => ['getTransferSessionState', 'get_transfer_session_state', 'GET', ['session &id'],
            'transfer_session_id=session%20%26id', '{"state_name":"FAILED"}'];
        yield 'pause' => ['pauseTransferSession', 'pause_transfer_session', 'POST', ['session &id'],
            'transfer_session_id=session%20%26id', null];
        yield 'remote credentials form post' => ['remoteBasicCredentialCheck', 'remote_basic_credential_check',
            'POST', ['remote.test', 'fixture', 'p& =+', 2222, 'sudo', 'root&secret', 'named key', 'key&pass'],
            'host=remote.test&user=fixture&password=p%26%20%3D%2B&port=2222&root_escalation_method=sudo'
                . '&root_password=root%26secret&sshkey_name=named%20key&sshkey_passphrase=key%26pass',
            '{"output":"Synthetic output\n","response":"Synthetic response\n"}'];
        yield 'analysis dynamic maps and opaque items' => ['retrieveTransferSessionRemoteAnalysis',
            'retrieve_transfer_session_remote_analysis', 'GET', ['session &id'],
            'transfer_session_id=session%20%26id', self::ANALYSIS];
        yield 'start returns pid without polling' => ['startTransferSession', 'start_transfer_session', 'POST',
            ['session &id'], 'transfer_session_id=session%20%26id', '{"pid":90210}'];
        yield 'schema keys remain data' => ['transferModuleSchema', 'transfer_module_schema', 'GET',
            ['AccountRemoteRoot'], 'module=AccountRemoteRoot', '{"schema":{"keys":{"priority":'
                . '{"def":"int(1) DEFAULT 255"},"user":{"def":"char(255) DEFAULT NULL"}},'
                . '"primary":["user"],"required":["user","localuser"]}}'];
        yield 'username outcome not payload boolean' => ['validateSystemUser', 'validate_system_user', 'GET',
            ['fixture &user'], 'user=fixture%20%26user',
            '{"exists":0,"reserved":0,"valid_for_new":1,"valid_for_transfer":0}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testEveryMethodEmitsExactDocumentedRequestAndPreservesPayload(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        $actual = $client->whm()->transfers()->{$method}(...$arguments);
        self::assertSame($payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $actual);
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string}> */
    public static function optionalInputs(): iterable
    {
        yield 'root required fields only' => ['createRemoteRootTransferSession', 'create_remote_root_transfer_session',
            ['remote.test', 'root', 'whostmgr', false, false, false, false, 1, 1, false, false, false],
            'host=remote.test&user=root&comm_transport=whostmgr&compressed=0'
                . '&copy_reseller_privs=0&enable_custom_pkgacct=0'
                . '&low_priority=0&restore_threads=1&transfer_threads=1&unencrypted=0&unrestricted_restore=0'
                . '&use_backups=0'];
        yield 'root key authentication optional password omitted' => ['createRemoteRootTransferSession',
            'create_remote_root_transfer_session', ['commTransport' => 'whostmgr_insecure', 'compressed' => true,
                'copyResellerPrivs' => true, 'enableCustomPkgacct' => true, 'host' => 'remote.test',
                'lowPriority' => true, 'restoreThreads' => 1, 'transferThreads' => 1, 'unencrypted' => true,
                'unrestrictedRestore' => true, 'useBackups' => true, 'user' => 'root', 'password' => null,
                'sshkeyName' => 'key'],
            'host=remote.test&user=root&comm_transport=whostmgr_insecure&compressed=1'
                . '&copy_reseller_privs=1&enable_custom_pkgacct=1'
                . '&low_priority=1&restore_threads=1&transfer_threads=1&unencrypted=1'
                . '&unrestricted_restore=1&use_backups=1&sshkey_name=key'];
        yield 'credential defaults omitted' => ['remoteBasicCredentialCheck', 'remote_basic_credential_check',
            ['remote.test', 'root'], 'host=remote.test&user=root'];
        yield 'credential null omitted and empty secret retained' => ['remoteBasicCredentialCheck',
            'remote_basic_credential_check', ['host' => 'remote.test', 'user' => 'root', 'password' => '',
                'port' => null, 'sshkeyPassphrase' => ''], 'host=remote.test&user=root&password=&sshkey_passphrase='];
        yield 'archive location default omitted' => ['deleteAccountArchives', 'delete_account_archives',
            ['fixture'], 'user=fixture'];
        yield 'archive explicit null omitted' => ['deleteAccountArchives', 'delete_account_archives',
            ['fixture', null], 'user=fixture'];
        yield 'archive supplied path unchanged before one encoding' => ['deleteAccountArchives',
            'delete_account_archives', ['fixture', '/home/archive &old/'],
            'user=fixture&mountpoint=%2Fhome%2Farchive%20%26old%2F'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('optionalInputs')]
    public function testOmittedDefaultsAndExplicitFalseEmptyAndNullInputsRemainDistinct(
        string $method,
        string $operation,
        array $arguments,
        string $wire,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            $operation,
            $operation === 'delete_account_archives' ? null : '{}',
        )));
        $client->whm()->transfers()->{$method}(...$arguments);
        self::assertRequest($http, $operation, 'POST', $wire);
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function payloadOperations(): iterable
    {
        yield 'modules' => ['availableTransferModules', 'available_transfer_modules', []];
        yield 'root' => ['createRemoteRootTransferSession', 'create_remote_root_transfer_session',
            ['remote.test', 'root', 'ssh', false, false, false, false, 1, 1, false, false, false]];
        yield 'nonroot' => ['createRemoteUserTransferSession', 'create_remote_user_transfer_session',
            ['remote.test', 'synthetic', true]];
        yield 'log' => ['fetchTransferSessionLog', 'fetch_transfer_session_log', ['session', 'master.log']];
        yield 'state' => ['getTransferSessionState', 'get_transfer_session_state', ['session']];
        yield 'credentials' => ['remoteBasicCredentialCheck', 'remote_basic_credential_check', ['remote.test', 'root']];
        yield 'analysis' => ['retrieveTransferSessionRemoteAnalysis', 'retrieve_transfer_session_remote_analysis',
            ['session']];
        yield 'start' => ['startTransferSession', 'start_transfer_session', ['session']];
        yield 'schema' => ['transferModuleSchema', 'transfer_module_schema', ['AccountLocal']];
        yield 'username' => ['validateSystemUser', 'validate_system_user', ['fixture']];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('payloadOperations')]
    public function testOptionalFieldsStayOmitted(string $method, string $operation, array $arguments): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
        self::assertSame([], $client->whm()->transfers()->{$method}(...$arguments));
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string|null}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::payloadOperations() as $label => [$method, $operation, $arguments]) {
            foreach (
                ['absent' => null, 'null' => 'null', 'string' => '"invalid"', 'integer' => '1',
                'boolean' => 'false', 'nonempty list' => '[{}]'] as $variant => $payload
            ) {
                yield $label . ' root ' . $variant => [$method, $operation, $arguments, $payload];
            }
        }
        yield 'module priority remains string' => ['availableTransferModules', 'available_transfer_modules', [],
            '{"modules":{"AccountRemoteRoot":6000}}'];
        yield 'root session id strict string' => ['createRemoteRootTransferSession',
            'create_remote_root_transfer_session',
            ['remote.test', 'root', 'ssh', false, false, false, false, 1, 1, false, false, false],
            '{"transfer_session_id":1}'];
        yield 'nonroot session id null forbidden' => ['createRemoteUserTransferSession',
            'create_remote_user_transfer_session', ['remote.test', 'synthetic', true], '{"transfer_session_id":null}'];
        yield 'log is not parsed JSON' => ['fetchTransferSessionLog', 'fetch_transfer_session_log',
            ['session', 'master.log'], '{"log":{"action":"complete"}}'];
        yield 'state string strict' => ['getTransferSessionState', 'get_transfer_session_state', ['session'],
            '{"state_name":0}'];
        yield 'credential output strict' => ['remoteBasicCredentialCheck', 'remote_basic_credential_check',
            ['remote.test', 'root'], '{"response":null}'];
        yield 'pid quoted number forbidden' => ['startTransferSession', 'start_transfer_session', ['session'],
            '{"pid":"90210"}'];
        yield 'username flag not boolean' => ['validateSystemUser', 'validate_system_user', ['fixture'],
            '{"exists":true}'];
        yield 'username flag closed' => ['validateSystemUser', 'validate_system_user', ['fixture'],
            '{"valid_for_transfer":2}'];
        yield 'schema definition not integer' => ['transferModuleSchema', 'transfer_module_schema', ['AccountLocal'],
            '{"schema":{"keys":{"priority":{"def":255}}}}'];
        yield 'schema primary list strings' => ['transferModuleSchema', 'transfer_module_schema', ['AccountLocal'],
            '{"schema":{"primary":[1]}}'];
        foreach (
            [
            'domains owner string' => '{"local":{"domains":{"fixture.test":0}}}',
            'database owner string' => '{"local":{"dbs":{"dbs":{"MYSQL":{"db":{"owner":1}}}}}}',
            'database owner object' => '{"remote":{"dbs":{"users":{"PGSQL":{"user":"fixture"}}}}}',
            'dedicated ip value string' => '{"local":{"dedicated_ips":{"192.0.2.1":null}}}',
            'user int not string' => '{"local":{"users":{"fixture":"1"}}}',
            'groups not boolean' => '{"local":{"groups":{"fixture":true}}}',
            'modules analysis string' => '{"modules":{"Backups":{"analysis":{"Version":1}}}}',
            'modules record object' => '{"modules":{"Backups":"11.90"}}',
            'accounts objects only' => '{"items":{"accounts":["fixture"]}}',
            'packages list only' => '{"items":{"packages":{"name":"package"}}}',
            'flags strict native' => '{"options":{"unrestricted":"1"}}',
            'config flags closed' => '{"config":{"shared_mysql_server":2}}',
            'linked timestamp strict' => '{"remote":{"linked_nodes":[{"last_check":"0"}]}}',
            'node services strings' => '{"remote":{"linked_nodes":[{"enabled_services":[0]}]}}',
            'node settings flags' => '{"remote":{"linked_nodes":[{"system_settings":{"Mail":{"setting":2}}}]}}',
            'capability value opaque object' => '{"remote":{"linked_nodes":[{"worker_capabilities":{"Mail":1}}]}}',
            'reseller native int' => '{"remote":{"resellers":{"fixture":"1"}}}',
            ] as $label => $payload
        ) {
            yield 'analysis ' . $label => ['retrieveTransferSessionRemoteAnalysis',
                'retrieve_transfer_session_remote_analysis', ['session'], $payload];
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadKeepsConfirmedOutcomeAndNeverRetries(
        string $method,
        string $operation,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $client->whm()->transfers()->{$method}(...$arguments);
            self::fail('Expected payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiFailurePreservesMetadataWithoutRetryOrPayloadAcceptance(
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
            $client->whm()->transfers()->{$method}(...$arguments);
            self::fail('Expected API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame(['Synthetic transfer failure'], $error->errors());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertCount(1, $http->getRequests());
    }

    public function testHttpFailureClearsPriorMetadataAndDoesNotRetry(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('validate_system_user', '{}')));
        $client->whm()->transfers()->validateSystemUser('fixture');
        self::assertNotNull($client->whm()->responseMeta());
        $http->addResponse(new Response(503, [], 'Synthetic unavailable response'));
        try {
            $client->whm()->transfers()->getTransferSessionState('session');
            self::fail('Expected HTTP failure.');
        } catch (HttpException) {
            self::assertNull($client->whm()->responseMeta());
        }
        self::assertCount(2, $http->getRequests());
    }

    public function testPasswordAndEscalationSecretsHaveSensitiveParameterAttributes(): void
    {
        foreach (['createRemoteRootTransferSession', 'remoteBasicCredentialCheck'] as $method) {
            foreach (['password', 'rootPassword', 'sshkeyPassphrase'] as $name) {
                $parameters = (new ReflectionMethod(Transfers::class, $method))->getParameters();
                $matching = array_values(array_filter(
                    $parameters,
                    static fn (ReflectionParameter $p): bool => $p->getName() === $name,
                ));
                self::assertCount(1, $matching);
                self::assertCount(1, $matching[0]->getAttributes(SensitiveParameter::class));
            }
        }
        $parameter = (new ReflectionMethod(Transfers::class, 'createRemoteUserTransferSession'))->getParameters()[1];
        self::assertSame('password', $parameter->getName());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    /** @return iterable<string, array{string}> */
    public static function conflictPayloads(): iterable
    {
        foreach (
            ['object' => '{"opaque":[1,null]}', 'string' => '"Synthetic load error"',
            'list' => '[1,"x",null]', 'integer' => '1', 'boolean' => 'false', 'null' => 'null'] as $label => $value
        ) {
            yield $label => ['{"remote":{"conflicts":' . $value . ',"has_disk_used":1}}'];
        }
    }

    #[DataProvider('conflictPayloads')]
    public function testOnlyContradictoryConflictsLeafAcceptsOpaqueValues(string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'retrieve_transfer_session_remote_analysis',
            $payload,
        )));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->transfers()->retrieveTransferSessionRemoteAnalysis('session'),
        );
        self::assertCount(1, $http->getRequests());
    }

    public function testRequiredTrueUsesExistingCheckedBoolEncoderAndHasNoDefault(): void
    {
        $parameter = (new ReflectionMethod(Transfers::class, 'createRemoteUserTransferSession'))->getParameters()[2];
        self::assertSame('true', (string) $parameter->getType());
        self::assertFalse($parameter->isOptional());
        self::assertFalse($parameter->allowsNull());
        SpecChecker::check(TransfersSpec::CREATE_REMOTE_USER_TRANSFER_SESSION);
        self::assertSame(['unrestricted_restore' => 1], ArgumentEncoder::encode(
            TransfersSpec::CREATE_REMOTE_USER_TRANSFER_SESSION['params'],
            ['unrestrictedRestore' => true],
        ));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function forbiddenUnrestrictedRestore(): iterable
    {
        foreach ([false, true] as $weak) {
            foreach ([false, 0, 1, '1', null] as $value) {
                yield ($weak ? 'weak ' : 'strict ') . get_debug_type($value) . var_export($value, true)
                    => [['unrestrictedRestore' => $value], $weak];
            }
            yield ($weak ? 'weak ' : 'strict ') . 'omitted' => [[], $weak];
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('forbiddenUnrestrictedRestore')]
    public function testForbiddenCredentialInputNeverReachesHttpOrExposesLibraryTraceSecrets(
        array $arguments,
        bool $weak,
    ): void {
        [$client, $http] = self::client();
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $inputs = ['host' => 'remote.test', 'password' => 'synthetic-private-password', ...$arguments];
            self::call($client->whm()->transfers(), 'createRemoteUserTransferSession', $inputs, $weak);
            self::fail('Only explicit true may reach the credential-bearing request.');
        } catch (TypeError $error) {
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\Api\\')) {
                    self::assertLibraryTraceValueHasNoPassword($frame['args'] ?? []);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
        self::assertSame([], $http->getRequests());
    }

    public function testWeakCallerTrueSendsExactlyOneEncodedRequiredValue(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('create_remote_user_transfer_session', '{}')));
        self::assertSame([], WeakTransferCaller::call(
            $client->whm()->transfers(),
            'createRemoteUserTransferSession',
            ['host' => 'remote.test', 'password' => 'synthetic', 'unrestrictedRestore' => true],
        ));
        self::assertRequest(
            $http,
            'create_remote_user_transfer_session',
            'POST',
            'host=remote.test&password=synthetic&unrestricted_restore=1',
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    private static function call(Transfers $group, string $method, array $arguments, bool $weak): mixed
    {
        if ($weak) {
            return WeakTransferCaller::call($group, $method, $arguments);
        }
        return $group->{$method}(...$arguments);
    }

    private static function assertLibraryTraceValueHasNoPassword(mixed $value): void
    {
        if ($value instanceof SensitiveParameterValue) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $nested) {
                self::assertLibraryTraceValueHasNoPassword($nested);
            }
            return;
        }
        self::assertNotSame('synthetic-private-password', $value);
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
            . ($status === 1 ? 'OK' : 'Synthetic transfer failure') . '","result":' . $status . ',"version":1}'
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
