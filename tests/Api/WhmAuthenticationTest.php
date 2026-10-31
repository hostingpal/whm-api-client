<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\Authentication;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Independent literals for the pinned WHM Authentication operations; every secret is synthetic.
 *
 * @phpstan-type AuthCall Closure(Authentication): mixed
 * @phpstan-type Operation array{string, string, AuthCall, string, ?string, mixed}
 */
final class WhmAuthenticationTest extends TestCase
{
    /** @param AuthCall $call */
    #[DataProvider('operations')]
    #[DataProvider('reviewedNullableResponses')]
    public function testWireRequestsAndPayloads(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $group = $whm->authentication();
        self::assertSame($group, $whm->authentication());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, $call($group));
        self::assertRequest($http, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertNull($client->uapi('alice')->responseMeta());
    }

    /** @return iterable<string, Operation> */
    public static function operations(): iterable
    {
        yield 'authorize root key text overrides file' => ['authorizesshkey', 'POST',
            static fn (Authentication $a): mixed => $a->authorizeSshKey(false, 'id.pub', 'key +&=%20', 'no-pty'),
            'authorize=0&file=id.pub&text=key%20%2B%26%3D%2520&options=no-pty',
            '{"authorized":0,"file":"id.pub","unknown":true}', ['authorized' => 0, 'file' => 'id.pub']];
        yield 'authorize existing file without text' => ['authorizesshkey', 'POST',
            static fn (Authentication $a): mixed => $a->authorizeSshKey(true, 'id.pub'),
            'authorize=1&file=id.pub', '{"authorized":1,"file":"id.pub"}', ['authorized' => 1, 'file' => 'id.pub']];
        yield 'authorize null text omitted while options remain' => ['authorizesshkey', 'POST',
            static fn (Authentication $a): mixed => $a->authorizeSshKey(false, 'id.pub', null, 'no-pty'),
            'authorize=0&file=id.pub&options=no-pty', '{"authorized":0,"file":"id.pub"}',
            ['authorized' => 0, 'file' => 'id.pub']];
        yield 'authorize explicit empty text remains present' => ['authorizesshkey', 'POST',
            static fn (Authentication $a): mixed => $a->authorizeSshKey(true, 'id.pub', ''),
            'authorize=1&file=id.pub&text=', '{"authorized":1,"file":"id.pub"}',
            ['authorized' => 1, 'file' => 'id.pub']];
        yield 'remote ssh check is POST' => ['check_remote_ssh_connection', 'POST',
            static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test', 2222),
            'host=remote.test&port=2222',
            '{"comment":"fixture","protocol_versions":["2.0",""],"received":"SSH-fixture",'
                . '"server_software":"fixture"}',
            ['comment' => 'fixture', 'protocol_versions' => ['2.0', ''], 'received' => 'SSH-fixture',
                'server_software' => 'fixture']];
        yield 'remote ssh documented successful connection has null leaves' => ['check_remote_ssh_connection', 'POST',
            static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'),
            'host=remote.test',
            '{"comment":null,"protocol_versions":null,"received":"SSH-fixture","server_software":null}',
            ['comment' => null, 'protocol_versions' => null, 'received' => 'SSH-fixture', 'server_software' => null]];
        yield 'conversion may remove original file' => ['convertopensshtoputty', 'POST',
            static fn (Authentication $a): mixed => $a->convertOpenSshToPutty('id', false, 'pass +&'),
            'file=id&keep_file=0&passphrase=pass%20%2B%26', '{"key":"fixture-private-key"}',
            ['key' => 'fixture-private-key']];
        yield 'delete key leaves explicit false' => ['deletesshkey', 'POST',
            static fn (Authentication $a): mixed => $a->deleteSshKey('id', false), 'file=id&leave_authorized=0',
            '{"file":"id"}', ['file' => 'id']];
        yield 'disable provider' => ['disable_authentication_provider', 'POST',
            static function (Authentication $a): mixed {
                $a->disableAuthenticationProvider('fixture', 'cpaneld');
                return null;
            }, 'provider_id=fixture&service_name=cpaneld', null, null];
        yield 'failing providers retain per-service failures' => ['disable_failing_authentication_providers', 'POST',
            static fn (Authentication $a): mixed => $a->disableFailingAuthenticationProviders(), '',
            '{"payload":[{"disabled_services":["cpaneld"],"failures_to_disable":'
                . '[{"failure":"fixture failure","service_name":"whostmgrd"},{}],'
                . '"provider_failure":"load failed","provider_name":"Fixture",'
                . '"provider_namespace":"Fixture::Auth"},{}]}',
            ['payload' => [['disabled_services' => ['cpaneld'],
                'failures_to_disable' => [['failure' => 'fixture failure', 'service_name' => 'whostmgrd'], []],
                'provider_failure' => 'load failed', 'provider_name' => 'Fixture',
                'provider_namespace' => 'Fixture::Auth'], []]]];
        yield 'enable provider' => ['enable_authentication_provider', 'POST',
            static function (Authentication $a): mixed {
                $a->enableAuthenticationProvider('fixture', 'webmaild');
                return null;
            }, 'provider_id=fixture&service_name=webmaild', null, null];
        yield 'generate key with explicit empty passphrase' => ['generatesshkeypair', 'POST',
            static fn (Authentication $a): mixed => $a->generateSshKeyPair(
                abortOnExistingKey: false,
                algorithm: 'rsa2',
                bits: 4096,
                comment: '',
                name: 'fixture',
                passphrase: '',
            ), 'abort_on_existing_key=0&algorithm=rsa2&bits=4096&comment=&name=fixture&passphrase=',
            '{"fingerprint":null,"name":"fixture"}', ['fingerprint' => null, 'name' => 'fixture']];
        yield 'available providers all optional fields' => ['get_available_authentication_providers', 'GET',
            static fn (Authentication $a): mixed => $a->getAvailableAuthenticationProviders(), '',
            '{"providers":[{"color":"#fff","configured":0,"cpaneld_enabled":1,'
                . '"cpaneld_link":"https://fixture.test/?token=fake","display_name":"Fixture",'
                . '"documentation_url":"https://fixture.test/docs","icon":"<svg/>",'
                . '"icon_type":"image/svg+xml","id":"fixture","label":"Log in",'
                . '"provider_name":"Fixture","textcolor":"#000","webmaild_enabled":0,'
                . '"webmaild_link":"","whostmgr_enabled":1,"whostmgrd_link":""},{}]}',
            ['providers' => [['color' => '#fff', 'configured' => 0, 'cpaneld_enabled' => 1,
                'cpaneld_link' => 'https://fixture.test/?token=fake', 'display_name' => 'Fixture',
                'documentation_url' => 'https://fixture.test/docs', 'icon' => '<svg/>',
                'icon_type' => 'image/svg+xml', 'id' => 'fixture', 'label' => 'Log in',
                'provider_name' => 'Fixture', 'textcolor' => '#000', 'webmaild_enabled' => 0,
                'webmaild_link' => '', 'whostmgr_enabled' => 1, 'whostmgrd_link' => ''], []]]];
        yield 'login URL sensitive redirect uses POST and is never followed' => ['get_login_url', 'POST',
            static fn (Authentication $a): mixed => $a->getLoginUrl('fixture', 'https://after.test/?token=fake +&'),
            'provider=fixture&url_after_login=https%3A%2F%2Fafter.test%2F%3Ftoken%3Dfake%20%2B%26',
            '{"payload":"https://login.test/?token=fake"}', ['payload' => 'https://login.test/?token=fake']];
        yield 'client configuration private POST' => ['get_provider_client_configurations', 'POST',
            static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'whostmgrd'),
            'provider_id=fixture&service_name=whostmgrd',
            '{"client_configurations":{"client_id":"fake-id","client_secret":"fake-secret",'
                . '"redirect_uris":["https://fixture.test/",""]}}',
            ['client_configurations' => ['client_id' => 'fake-id', 'client_secret' => 'fake-secret',
                'redirect_uris' => ['https://fixture.test/', '']]]];
        yield 'client configuration unset fields retain redirect list' => ['get_provider_client_configurations', 'POST',
            static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'whostmgrd'),
            'provider_id=fixture&service_name=whostmgrd',
            '{"client_configurations":{"client_id":null,"client_secret":null,"redirect_uris":[]}}',
            ['client_configurations' => ['client_id' => null, 'client_secret' => null, 'redirect_uris' => []]]];
        yield 'provider fields nullable value' => ['get_provider_configuration_fields', 'GET',
            static fn (Authentication $a): mixed => $a->getProviderConfigurationFields('fixture', 'webmaild'),
            'provider_id=fixture&service_name=webmaild',
            '{"configuration_fields":[{"display_order":0,"field_id":"id","label":"Fixture","value":null},{}]}',
            ['configuration_fields' => [['display_order' => 0, 'field_id' => 'id', 'label' => 'Fixture',
                'value' => null], []]]];
        yield 'provider display data' => ['get_provider_display_configurations', 'GET',
            static fn (Authentication $a): mixed => $a->getProviderDisplayConfigurations('fixture'),
            'provider_id=fixture', '{"configurations":[{"color":"#fff","display_name":"Fixture",'
                . '"documentation_url":"https://fixture.test/docs","icon":"svg bytes",'
                . '"icon_type":"image/svg+xml","label":"Log in","link":"https://fixture.test/",'
                . '"provider_name":"Fixture","service":"cpaneld","textcolor":"#000"},{}]}',
            ['configurations' => [['color' => '#fff', 'display_name' => 'Fixture',
                'documentation_url' => 'https://fixture.test/docs', 'icon' => 'svg bytes',
                'icon_type' => 'image/svg+xml', 'label' => 'Log in', 'link' => 'https://fixture.test/',
                'provider_name' => 'Fixture', 'service' => 'cpaneld', 'textcolor' => '#000'], []]]];
        yield 'linked account identifiers remain exact strings' => ['get_users_authn_linked_accounts', 'GET',
            static fn (Authentication $a): mixed => $a->getUsersAuthnLinkedAccounts(), '',
            '{"username_linked_accounts":[{"link_time":0,"preferred_username":"remote",'
                . '"provider_id":"fixture","provider_protocol":"openid_connect",'
                . '"subject_unique_identifier":"00123456789012345678901","username":"alice"},{}]}',
            ['username_linked_accounts' => [['link_time' => 0, 'preferred_username' => 'remote',
                'provider_id' => 'fixture', 'provider_protocol' => 'openid_connect',
                'subject_unique_identifier' => '00123456789012345678901', 'username' => 'alice'], []]]];
        yield 'import key form and extraction options' => ['importsshkey', 'POST',
            static function (Authentication $a): mixed {
                $a->importSshKey('key +&=%20', 'fixture', false, true, 'pass +&');
                return null;
            }, 'key=key%20%2B%26%3D%2520&name=fixture&extract_private=0&extract_public=1&passphrase=pass%20%2B%26',
            null, null];
        yield 'link identity' => ['link_user_authn_provider', 'POST',
            static function (Authentication $a): mixed {
                $a->linkUserAuthnProvider('alice', 'fixture', '00123456789012345678901', 'remote');
                return null;
            }, 'username=alice&provider_id=fixture&subject_unique_identifier=00123456789012345678901'
                . '&preferred_username=remote', null, null];
        yield 'list root keys remains POST' => ['listsshkeys', 'POST',
            static fn (Authentication $a): mixed => $a->listSshKeys(
                files: 'id +&',
                private: false,
                privateTexts: false,
                public: false,
                publicTexts: false,
                syncAuthorized: false,
            ), 'files=id%20%2B%26&private=0&private_texts=0&public=0&public_texts=0&sync_authorized=0',
            '{"keys":[{"authorized":0,"comment":"","ctime":0,"file":"id",'
                . '"mtime":1,"private":1,"text":"fixture-private-key"},{}]}',
            ['keys' => [['authorized' => 0, 'comment' => '', 'ctime' => 0, 'file' => 'id',
                'mtime' => 1, 'private' => 1, 'text' => 'fixture-private-key'], []]]];
        yield 'set provider client values string encoded once' => ['set_provider_client_configurations', 'POST',
            static function (Authentication $a): mixed {
                $a->setProviderClientConfigurations('{"client_secret":"fake +&=%20"}', 'fixture', 'cpaneld');
                return null;
            }, 'configurations=%7B%22client_secret%22%3A%22fake%20%2B%26%3D%2520%22%7D'
                . '&provider_id=fixture&service_name=cpaneld', null, null];
        yield 'display JSON is a scalar form value' => ['set_provider_display_configurations', 'POST',
            static function (Authentication $a): mixed {
                $a->setProviderDisplayConfigurations('{"label":"fake +&=%20"}', 'fixture', 'webmaild');
                return null;
            }, 'configurations=%7B%22label%22%3A%22fake%20%2B%26%3D%2520%22%7D'
                . '&provider_id=fixture&service_name=webmaild', null, null];
        yield 'disable policy' => ['twofactorauth_disable_policy', 'POST',
            static function (Authentication $a): mixed {
                $a->twoFactorAuthDisablePolicy();
                return null;
            }, '', null, null];
        yield 'enable policy' => ['twofactorauth_enable_policy', 'POST',
            static function (Authentication $a): mixed {
                $a->twoFactorAuthEnablePolicy();
                return null;
            }, '', null, null];
        yield 'generate TFA secrets remains POST' => ['twofactorauth_generate_tfa_config', 'POST',
            static fn (Authentication $a): mixed => $a->twoFactorAuthGenerateTfaConfig(), '',
            '{"otpauth_str":"otpauth://totp/fixture?secret=FAKE","secret":"FAKE"}',
            ['otpauth_str' => 'otpauth://totp/fixture?secret=FAKE', 'secret' => 'FAKE']];
        yield 'issuer' => ['twofactorauth_get_issuer', 'GET',
            static fn (Authentication $a): mixed => $a->twoFactorAuthGetIssuer(), '',
            '{"issuer":"Fixture","system_wide_issuer":"server.test"}',
            ['issuer' => 'Fixture', 'system_wide_issuer' => 'server.test']];
        yield 'TFA nested dynamic accounts stay POST' => ['twofactorauth_get_tfa_config_for_user', 'POST',
            static fn (Authentication $a): mixed => $a->twoFactorAuthGetTfaConfigForUser('alice'), 'user=alice',
            '{"alice":{"email":{"mail@fixture.test":{"secret":"FAKE"},"empty@fixture.test":{}},'
                . '"primary_account":{"secret":"FAKE"},"team":{"team@alice":{"secret":"FAKE"}}},"bob":{}}',
            ['alice' => ['email' => ['mail@fixture.test' => ['secret' => 'FAKE'], 'empty@fixture.test' => []],
                'primary_account' => ['secret' => 'FAKE'], 'team' => ['team@alice' => ['secret' => 'FAKE']]],
                'bob' => []]];
        yield 'TFA primary account null empty and missing remain distinct' => ['twofactorauth_get_tfa_config_for_user',
            'POST', static fn (Authentication $a): mixed => $a->twoFactorAuthGetTfaConfigForUser('alice'), 'user=alice',
            '{"alice":{"email":{"mail@fixture.test":{"secret":"FAKE"}},"primary_account":null,"team":{}},'
                . '"bob":{},"carol":{"primary_account":{}},"dave":{"primary_account":{"secret":"FAKE"}}}',
            ['alice' => ['email' => ['mail@fixture.test' => ['secret' => 'FAKE']], 'primary_account' => null,
                'team' => []], 'bob' => [], 'carol' => ['primary_account' => []],
                'dave' => ['primary_account' => ['secret' => 'FAKE']]]];
        yield 'all users TFA flags' => ['twofactorauth_get_user_configs', 'GET',
            static fn (Authentication $a): mixed => $a->twoFactorAuthGetUserConfigs(), '',
            '{"alice":{"is_enabled":0,"primary_domain":"fixture.test"},"bob":{}}',
            ['alice' => ['is_enabled' => 0, 'primary_domain' => 'fixture.test'], 'bob' => []]];
        yield 'named user TFA filter' => ['twofactorauth_get_user_configs', 'GET',
            static fn (Authentication $a): mixed => $a->twoFactorAuthGetUserConfigs('alice'), 'user=alice',
            '{"alice":{"is_enabled":0,"primary_domain":"fixture.test"}}',
            ['alice' => ['is_enabled' => 0, 'primary_domain' => 'fixture.test']]];
        yield 'policy status zero is successful' => ['twofactorauth_policy_status', 'GET',
            static fn (Authentication $a): mixed => $a->twoFactorAuthPolicyStatus(), '',
            '{"is_enabled":0}', ['is_enabled' => 0]];
        yield 'remove TFA one account' => ['twofactorauth_remove_user_config', 'POST',
            static fn (Authentication $a): mixed => $a->twoFactorAuthRemoveUserConfig('alice'), 'user=alice',
            '{"failed":{"bob":"fixture error"},"users_modified":["alice",""]}',
            ['failed' => ['bob' => 'fixture error'], 'users_modified' => ['alice', '']]];
        yield 'set issuer' => ['twofactorauth_set_issuer', 'POST',
            static function (Authentication $a): mixed {
                $a->twoFactorAuthSetIssuer('Issuer +&');
                return null;
            }, 'issuer=Issuer%20%2B%26', null, null];
        yield 'set TFA token retains leading zero' => ['twofactorauth_set_tfa_config', 'POST',
            static fn (Authentication $a): mixed => $a->twoFactorAuthSetTfaConfig('FAKE +&', '001234'),
            'secret=FAKE%20%2B%26&tfa_token=001234', '{"success":0}', ['success' => 0]];
        yield 'unlink identity' => ['unlink_user_authn_provider', 'POST',
            static function (Authentication $a): mixed {
                $a->unlinkUserAuthnProvider('alice', 'fixture', '00123456789012345678901');
                return null;
            }, 'username=alice&provider_id=fixture&subject_unique_identifier=00123456789012345678901', null, null];
    }

    /** @return iterable<string, array{AuthCall, string, string, string}> */
    public static function omittedOptions(): iterable
    {
        yield 'authorization options' => [
            static fn (Authentication $a): mixed => $a->authorizeSshKey(true, 'id.pub', 'fake'),
            'authorizesshkey', 'POST', 'authorize=1&file=id.pub&text=fake'];
        yield 'remote port server default' => [
            static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'),
            'check_remote_ssh_connection', 'POST', 'host=remote.test'];
        yield 'keep file and passphrase' => [
            static fn (Authentication $a): mixed => $a->convertOpenSshToPutty('id'),
            'convertopensshtoputty', 'POST', 'file=id'];
        yield 'leave authorized default' => [static fn (Authentication $a): mixed => $a->deleteSshKey('id'),
            'deletesshkey', 'POST', 'file=id'];
        yield 'key generation defaults' => [static fn (Authentication $a): mixed => $a->generateSshKeyPair(),
            'generatesshkeypair', 'POST', ''];
        yield 'root key inventory defaults' => [static fn (Authentication $a): mixed => $a->listSshKeys(),
            'listsshkeys', 'POST', ''];
        yield 'explicit empty TFA filter' => [
            static fn (Authentication $a): mixed => $a->twoFactorAuthGetUserConfigs(''),
            'twofactorauth_get_user_configs', 'GET', 'user='];
        yield 'named file authorization skips optional text' => [
            static fn (Authentication $a): mixed => $a->authorizeSshKey(true, 'id.pub', options: 'no-pty'),
            'authorizesshkey', 'POST', 'authorize=1&file=id.pub&options=no-pty'];
    }

    /** @param AuthCall $call */
    #[DataProvider('omittedOptions')]
    public function testServerDefaultsRemainOmitted(Closure $call, string $function, string $method, string $wire): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        $call($client->whm()->authentication());
        self::assertRequest($http, $function, $method, $wire);
    }

    public function testSensitiveParametersAndExcludedOperations(): void
    {
        $expected = [
            'authorizeSshKey' => ['text'],
            'convertOpenSshToPutty' => ['passphrase'],
            'generateSshKeyPair' => ['passphrase'],
            'getLoginUrl' => ['urlAfterLogin'],
            'importSshKey' => ['key', 'passphrase'],
            'setProviderClientConfigurations' => ['configurations'],
            'setProviderDisplayConfigurations' => ['configurations'],
            'twoFactorAuthSetTfaConfig' => ['secret', 'tfaToken'],
        ];
        foreach ($expected as $name => $names) {
            $actual = [];
            foreach ((new ReflectionMethod(Authentication::class, $name))->getParameters() as $parameter) {
                if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                    $actual[] = $parameter->getName();
                }
            }
            self::assertSame($names, $actual, $name);
        }
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Authentication::class))->getMethods(),
        );
        self::assertNotContains('accesshash', $methods);
        self::assertNotContains('twoFactorAuthSetTfaConfigForUser', $methods);
    }

    public function testAuthorizationTextIsOptionalAndStillSensitive(): void
    {
        $method = new ReflectionMethod(Authentication::class, 'authorizeSshKey');
        self::assertSame(2, $method->getNumberOfRequiredParameters());
        $text = $method->getParameters()[2];
        self::assertSame('text', $text->getName());
        self::assertTrue($text->isOptional());
        self::assertTrue($text->allowsNull());
        self::assertNull($text->getDefaultValue());
        self::assertCount(1, $text->getAttributes(SensitiveParameter::class));
    }

    public function testExternalIdentitySignaturesKeepTheLocalUsernameFirst(): void
    {
        foreach (
            [
            'linkUserAuthnProvider' => ['username', 'providerId', 'subjectUniqueIdentifier', 'preferredUsername'],
            'unlinkUserAuthnProvider' => ['username', 'providerId', 'subjectUniqueIdentifier'],
            ] as $method => $expected
        ) {
            self::assertSame($expected, array_map(
                static fn ($parameter): string => $parameter->getName(),
                (new ReflectionMethod(Authentication::class, $method))->getParameters(),
            ));
        }
    }

    /** @return iterable<string, Operation> */
    public static function reviewedNullableResponses(): iterable
    {
        foreach (['comment', 'protocol_versions', 'server_software'] as $field) {
            yield 'remote null ' . $field => ['check_remote_ssh_connection', 'POST',
                static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'),
                'host=remote.test', '{"' . $field . '":null}', [$field => null]];
        }
        yield 'remote omitted optional leaves preserves banner' => ['check_remote_ssh_connection', 'POST',
            static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'), 'host=remote.test',
            '{"received":"SSH-fixture"}', ['received' => 'SSH-fixture']];
        yield 'remote empty strings and empty version list' => ['check_remote_ssh_connection', 'POST',
            static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'), 'host=remote.test',
            '{"comment":"","protocol_versions":[],"received":"","server_software":""}',
            ['comment' => '', 'protocol_versions' => [], 'received' => '', 'server_software' => '']];
        foreach (['client_id', 'client_secret'] as $field) {
            yield 'client null ' . $field => ['get_provider_client_configurations', 'POST',
                static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'cpaneld'),
                'provider_id=fixture&service_name=cpaneld', '{"client_configurations":{"' . $field . '":null}}',
                ['client_configurations' => [$field => null]]];
        }
        yield 'client fields omitted' => ['get_provider_client_configurations', 'POST',
            static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'cpaneld'),
            'provider_id=fixture&service_name=cpaneld', '{"client_configurations":{}}',
            ['client_configurations' => []]];
        yield 'client empty strings remain distinct from null' => ['get_provider_client_configurations', 'POST',
            static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'cpaneld'),
            'provider_id=fixture&service_name=cpaneld',
            '{"client_configurations":{"client_id":"","client_secret":""}}',
            ['client_configurations' => ['client_id' => '', 'client_secret' => '']]];
        yield 'provider values null omitted and empty preserve known row fields' => [
            'get_provider_configuration_fields', 'GET',
            static fn (Authentication $a): mixed => $a->getProviderConfigurationFields('fixture', 'cpaneld'),
            'provider_id=fixture&service_name=cpaneld',
            '{"configuration_fields":[{"description":"Secret","display_order":1,"field_id":"client_secret",'
                . '"label":"Client Secret","value":null},{"field_id":"client_id"},{"value":""}]}',
            ['configuration_fields' => [['description' => 'Secret', 'display_order' => 1, 'field_id' => 'client_secret',
                'label' => 'Client Secret', 'value' => null], ['field_id' => 'client_id'], ['value' => '']]]];
    }

    /** @return iterable<string, array{AuthCall, string}> */
    public static function invalidFields(): iterable
    {
        yield 'authorization flag string' => [
            static fn (Authentication $a): mixed => $a->authorizeSshKey(true, 'id', 'fake'), '{"authorized":"1"}'];
        yield 'remote protocol scalar' => [
            static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'),
            '{"protocol_versions":"2"}'];
        yield 'converted key object' => [static fn (Authentication $a): mixed => $a->convertOpenSshToPutty('id'),
            '{"key":{}}'];
        yield 'delete file null' => [static fn (Authentication $a): mixed => $a->deleteSshKey('id'), '{"file":null}'];
        yield 'service failure numeric' => [
            static fn (Authentication $a): mixed => $a->disableFailingAuthenticationProviders(),
            '{"payload":[{"failures_to_disable":[{"failure":0}]}]}'];
        yield 'fingerprint boolean' => [static fn (Authentication $a): mixed => $a->generateSshKeyPair(),
            '{"fingerprint":false}'];
        yield 'provider boolean flag' => [
            static fn (Authentication $a): mixed => $a->getAvailableAuthenticationProviders(),
            '{"providers":[{"configured":true}]}'];
        yield 'login URL object' => [static fn (Authentication $a): mixed => $a->getLoginUrl('fixture', 'after'),
            '{"payload":{"url":"fake"}}'];
        yield 'client redirect wrong leaf' => [
            static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'cpaneld'),
            '{"client_configurations":{"redirect_uris":[1]}}'];
        yield 'field value boolean' => [
            static fn (Authentication $a): mixed => $a->getProviderConfigurationFields('fixture', 'cpaneld'),
            '{"configuration_fields":[{"value":false}]}'];
        yield 'display label number' => [
            static fn (Authentication $a): mixed => $a->getProviderDisplayConfigurations('fixture'),
            '{"configurations":[{"label":1}]}'];
        yield 'linked subject remains string' => [
            static fn (Authentication $a): mixed => $a->getUsersAuthnLinkedAccounts(),
            '{"username_linked_accounts":[{"subject_unique_identifier":123}]}'];
        yield 'private key text null' => [static fn (Authentication $a): mixed => $a->listSshKeys(),
            '{"keys":[{"text":null}]}'];
        yield 'generated TFA secret object' => [
            static fn (Authentication $a): mixed => $a->twoFactorAuthGenerateTfaConfig(), '{"secret":{}}'];
        yield 'issuer null' => [
            static fn (Authentication $a): mixed => $a->twoFactorAuthGetIssuer(), '{"issuer":null}'];
        yield 'TFA nested secret boolean' => [
            static fn (Authentication $a): mixed => $a->twoFactorAuthGetTfaConfigForUser('alice'),
            '{"alice":{"email":{"mail@fixture.test":{"secret":false}}}}'];
        yield 'TFA user flag string' => [static fn (Authentication $a): mixed => $a->twoFactorAuthGetUserConfigs(),
            '{"alice":{"is_enabled":"1"}}'];
        yield 'policy flag float' => [static fn (Authentication $a): mixed => $a->twoFactorAuthPolicyStatus(),
            '{"is_enabled":1.0}'];
        yield 'failed user reason null' => [
            static fn (Authentication $a): mixed => $a->twoFactorAuthRemoveUserConfig('alice'),
            '{"failed":{"alice":null}}'];
        yield 'set TFA success bool' => [
            static fn (Authentication $a): mixed => $a->twoFactorAuthSetTfaConfig('FAKE', '000000'),
            '{"success":false}'];
        foreach (['comment', 'server_software'] as $field) {
            foreach (['0', 'true', 'false', '1.5', '[]', '{}'] as $bad) {
                yield 'remote strict ' . $field . ' ' . $bad => [
                    static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'),
                    '{"' . $field . '":' . $bad . '}'];
            }
        }
        foreach (['0', 'false', '"2.0"', '{}', '[null]', '[1]', '[{}]'] as $bad) {
            yield 'remote strict protocol list ' . $bad => [
                static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'),
                '{"protocol_versions":' . $bad . '}'];
        }
        foreach (['null', '0', 'false', '[]', '{}'] as $bad) {
            yield 'remote received remains nonnullable string ' . $bad => [
                static fn (Authentication $a): mixed => $a->checkRemoteSshConnection('remote.test'),
                '{"received":' . $bad . '}'];
        }
        foreach (['client_id', 'client_secret'] as $field) {
            foreach (['0', 'true', 'false', '1.5', '[]', '{}'] as $bad) {
                yield 'client strict ' . $field . ' ' . $bad => [
                    static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'cpaneld'),
                    '{"client_configurations":{"' . $field . '":' . $bad . '}}'];
            }
        }
        foreach (['null', 'false', '"uri"', '{}', '[null]', '[1]'] as $bad) {
            yield 'client redirect stays strict ' . $bad => [
                static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'cpaneld'),
                '{"client_configurations":{"redirect_uris":' . $bad . '}}'];
        }
        yield 'client configuration container remains nonnullable' => [
            static fn (Authentication $a): mixed => $a->getProviderClientConfigurations('fixture', 'cpaneld'),
            '{"client_configurations":null}'];
        foreach (['0', 'true', '1.5', '[]', '{}'] as $bad) {
            yield 'provider value remains string or null ' . $bad => [
                static fn (Authentication $a): mixed => $a->getProviderConfigurationFields('fixture', 'cpaneld'),
                '{"configuration_fields":[{"value":' . $bad . '}]}'];
        }
        foreach (['0', 'false', '"secret"', '[{}]'] as $bad) {
            yield 'primary account remains object or null ' . $bad => [
                static fn (Authentication $a): mixed => $a->twoFactorAuthGetTfaConfigForUser('alice'),
                '{"alice":{"primary_account":' . $bad . '}}'];
        }
        foreach (['null', '0', 'false'] as $bad) {
            yield 'primary secret remains nonnullable string ' . $bad => [
                static fn (Authentication $a): mixed => $a->twoFactorAuthGetTfaConfigForUser('alice'),
                '{"alice":{"primary_account":{"secret":' . $bad . '}}}'];
        }
        foreach (['email', 'team'] as $field) {
            yield 'TFA sibling map remains nonnullable ' . $field => [
                static fn (Authentication $a): mixed => $a->twoFactorAuthGetTfaConfigForUser('alice'),
                '{"alice":{"' . $field . '":null}}'];
        }
    }

    /** @param AuthCall $call */
    #[DataProvider('invalidFields')]
    public function testInvalidLeavesPreserveConfirmedWhmOutcome(Closure $call, string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $call($client->whm()->authentication());
            self::fail('Invalid payload fields must fail validation.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{AuthCall, ?string}> */
    public static function payloadBoundaries(): iterable
    {
        foreach (self::operations() as $name => [, , $call, , $payload]) {
            if ($payload === null) {
                continue;
            }
            yield $name . ' missing' => [$call, null];
            yield $name . ' null' => [$call, 'null'];
            yield $name . ' nonempty list root' => [$call, '["not-an-object"]'];
            yield $name . ' scalar root' => [$call, '1'];
        }
    }

    /** @param AuthCall $call */
    #[DataProvider('payloadBoundaries')]
    public function testRequiredObjectPayloadRejectsOtherRoots(Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        $this->expectException(PayloadMismatchException::class);
        $call($client->whm()->authentication());
    }

    /** @return iterable<string, array{AuthCall, string}> */
    public static function payloadCalls(): iterable
    {
        foreach (self::operations() as $name => [, , $call, , $payload]) {
            if ($payload !== null) {
                yield $name . ' empty object' => [$call, '{}'];
                // Shared validation also accepts [] as an empty object/map when no fields are required.
                yield $name . ' compatible empty array' => [$call, '[]'];
            }
        }
    }

    /** @param AuthCall $call */
    #[DataProvider('payloadCalls')]
    public function testEveryNamedPayloadFieldCanBeOmitted(Closure $call, string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame([], $call($client->whm()->authentication()));
    }

    /** @param AuthCall $call */
    #[DataProvider('operations')]
    public function testApiFailureDoesNotRetry(
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
            $call($client->whm()->authentication());
            self::fail('A failed WHM envelope must raise ApiErrorException.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Fixture failure'], $exception->errors());
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testImportOmissionAndExplicitEmptyPassphraseStayDistinct(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(null)));
        $http->addResponse(new Response(200, [], self::envelope(null)));
        $client->whm()->authentication()->importSshKey('fake', 'id');
        self::assertRequest($http, 'importsshkey', 'POST', 'key=fake&name=id');
        $client->whm()->authentication()->importSshKey('fake', 'id', passphrase: '');
        self::assertRequest($http, 'importsshkey', 'POST', 'key=fake&name=id&passphrase=');
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{AuthCall}> */
    public static function secretCalls(): iterable
    {
        yield 'public key text' => [static fn (Authentication $a): mixed =>
            $a->authorizeSshKey(true, 'id.pub', 'trace-fixture-secret')];
        yield 'conversion passphrase' => [static fn (Authentication $a): mixed =>
            $a->convertOpenSshToPutty('id', passphrase: 'trace-fixture-secret')];
        yield 'generation passphrase' => [static fn (Authentication $a): mixed =>
            $a->generateSshKeyPair(passphrase: 'trace-fixture-secret')];
        yield 'login redirect' => [static fn (Authentication $a): mixed =>
            $a->getLoginUrl('fixture', 'https://fixture.test/?token=trace-fixture-secret')];
        yield 'import key and passphrase' => [static function (Authentication $a): mixed {
            $a->importSshKey('trace-fixture-secret', 'id', passphrase: 'trace-fixture-secret');
            return null;
        }];
        yield 'client configuration' => [static function (Authentication $a): mixed {
            $a->setProviderClientConfigurations('{"secret":"trace-fixture-secret"}', 'fixture', 'cpaneld');
            return null;
        }];
        yield 'display configuration' => [static function (Authentication $a): mixed {
            $a->setProviderDisplayConfigurations('{"label":"trace-fixture-secret"}', 'fixture', 'cpaneld');
            return null;
        }];
        yield 'TFA secret and token' => [static fn (Authentication $a): mixed =>
            $a->twoFactorAuthSetTfaConfig('trace-fixture-secret', 'trace-fixture-secret')];
    }

    /** @param AuthCall $call */
    #[DataProvider('secretCalls')]
    public function testSecretInputsStayOutOfCapturedTraceArguments(Closure $call): void
    {
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            [$client, $http] = self::client();
            $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Fixture failure"}}'));
            try {
                $call($client->whm()->authentication());
                self::fail('Expected a failed WHM outcome.');
            } catch (ApiErrorException $exception) {
                $seen = [];
                self::assertFalse(self::hasSecret($exception->getTrace(), $seen));
                self::assertStringNotContainsString('trace-fixture-secret', $exception->getMessage());
                self::assertStringNotContainsString('trace-fixture-secret', $exception->getTraceAsString());
                self::assertCount(1, $http->getRequests());
            }
        } finally {
            if ($original !== false) {
                ini_set('zend.exception_ignore_args', $original);
            }
        }
    }

    public function testReturnedSecretOnlyRemainsInExplicitMismatchPayload(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            '{"secret":"trace-fixture-secret","otpauth_str":false}',
        )));
        try {
            $client->whm()->authentication()->twoFactorAuthGenerateTfaConfig();
            self::fail('Malformed OTP URL must fail after the confirmed outcome.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame(['secret' => 'trace-fixture-secret', 'otpauth_str' => false], $exception->payload);
            self::assertStringNotContainsString('trace-fixture-secret', $exception->getMessage());
            self::assertStringNotContainsString('trace-fixture-secret', print_r($exception->__debugInfo(), true));
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'trace-fixture-secret');
        }
        if ($value instanceof MessageInterface && str_contains((string) $value->getBody(), 'trace-fixture-secret')) {
            return true;
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (self::hasSecret((string) $key, $seen) || self::hasSecret($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
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
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK","version":1}'
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
