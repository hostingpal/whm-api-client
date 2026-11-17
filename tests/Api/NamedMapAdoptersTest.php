<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\CollectionEncoder;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
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

/** Literal endpoint fixtures; wire expectations do not consult generated specs. */
final class NamedMapAdoptersTest extends TestCase
{
    /** @param array<string, mixed> $args */
    #[DataProvider('requests')]
    public function testLiteralPostAndResponse(
        string $group,
        string $method,
        string $operation,
        array $args,
        string $body,
        string $payload,
    ): void {
        [$whm, $http] = self::client();
        $outer = $operation === 'massmodifyacct' && str_contains($payload, '"result":0') ? 0 : 1;
        $http->addResponse(new Response(200, [], '{"metadata":{"result":' . $outer . '},"data":' . $payload . '}'));
        $result = $whm->$group()->$method(...$args);
        self::assertSame($payload === 'null' ? null : json_decode($payload, true), $result);
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame($body, (string) $request->getBody());
        self::assertNotNull($whm->responseMeta());
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>, string, string}> */
    public static function requests(): iterable
    {
        yield 'create' => ['hostingPlans', 'createFeaturelist', 'create_featurelist',
            ['featurelist' => 'A&B', 'features' => ['email' => true, 'ftp' => false], 'overwrite' => false],
            'featurelist=A%26B&email=1&ftp=0&overwrite=0', '{"featurelist":"A&B"}'];
        yield 'create overwrite true' => ['hostingPlans', 'createFeaturelist', 'create_featurelist',
            ['featurelist' => 'A', 'features' => ['email' => false], 'overwrite' => true],
            'featurelist=A&email=0&overwrite=1', '{}'];
        yield 'grey autotrust true' => ['mail', 'cpgreylistSaveCommonMailProvidersConfig',
            'cpgreylist_save_common_mail_providers_config',
            ['autotrustNewCommonMailProviders' => true, 'providers' => ['google' => false]],
            'autotrust_new_common_mail_providers=1&google=0', '{}'];
        yield 'update' => ['hostingPlans', 'updateFeaturelist', 'update_featurelist',
            ['featurelist' => 'A', 'features' => ['a%&' => false]], 'featurelist=A&a%25%26=0',
            '{"featurelist":"A","invalid_features":["bad"],"updated_features":{"email":1,"ftp":0}}'];
        foreach (['create'] as $verb) {
            yield "$verb empty" => ['hostingPlans', $verb . 'Featurelist', $verb . '_featurelist',
                ['featurelist' => 'A', 'features' => []], 'featurelist=A', '{}'];
        }
        yield 'providers' => ['mail', 'cpgreylistSaveCommonMailProvidersConfig',
            'cpgreylist_save_common_mail_providers_config',
            ['autotrustNewCommonMailProviders' => false, 'providers' => ['google' => false, '01' => true]],
            'autotrust_new_common_mail_providers=0&google=0&01=1',
            '{"autotrust_new_common_mail_providers":0,"common_mail_providers":{"google":{"autoupdate":0}}}'];
        foreach (['reset', 'set'] as $verb) {
            yield "$verb provider" => ['ssl', $verb . 'AutoSslProvider', $verb . '_autossl_provider',
                ['provider' => 'LetsEncrypt', 'parameters' => ['terms_of_service_accepted' => 'https://a.test/?x=1&y=%',
                    'X_keep' => '', 'zero' => '0']],
                'provider=LetsEncrypt&x_terms_of_service_accepted=https%3A%2F%2Fa.test%2F%3Fx%3D1%26y%3D%25'
                    . '&x_X_keep=&x_zero=0',
                'null'];
            foreach ([null, []] as $i => $parameters) {
                yield "$verb empty $i" => ['ssl', $verb . 'AutoSslProvider', $verb . '_autossl_provider',
                    ['provider' => 'cPanel', 'parameters' => $parameters], 'provider=cPanel', 'null'];
            }
        }
        foreach ([null, []] as $i => $providers) {
            yield "grey empty $i" => ['mail', 'cpgreylistSaveCommonMailProvidersConfig',
                'cpgreylist_save_common_mail_providers_config', ['providers' => $providers], '', '{}'];
        }
        yield 'conversion list' => ['accounts', 'convertAddonInitiateConversion', 'convert_addon_initiate_conversion',
            ['domain' => 'a.test', 'username' => 'new', 'copyMysqlDatabases' => ['old' => 'new%&', 'empty' => ''],
                'moveMysqlDatabases' => ['one', '0'], 'moveMysqlUsers' => ['user'], 'pkgname' => 'p'],
            'domain=a.test&username=new&copymysqldb-old=new%25%26&copymysqldb-empty='
                . '&movemysqldb-1=one&movemysqldb-2=0&movemysqluser-1=user&pkgname=p',
            '{"opaque":{"progress":null}}'];
        yield 'conversion scalar' => ['accounts', 'convertAddonInitiateConversion', 'convert_addon_initiate_conversion',
            ['domain' => 'a.test', 'username' => 'new', 'copyMysqlDatabases' => [],
                'moveMysqlDatabases' => '', 'moveMysqlUsers' => '0'],
            'domain=a.test&username=new&movemysqldb=&movemysqluser=0', '{}'];
        yield 'mass' => ['accounts', 'massModifyAccount', 'massmodifyacct',
            ['user' => ['a', 'b']],
            'user-1=a&user-2=b',
            '{"payload":[{"result":1,"user":"a","warnings":[]},{"reason":"failed","result":0,"user":"b"}]}'];
        yield 'conversion omitted' => ['accounts', 'convertAddonInitiateConversion',
            'convert_addon_initiate_conversion', ['domain' => 'a.test', 'username' => 'new'],
            'domain=a.test&username=new', '{}'];
        yield 'conversion null singleton' => ['accounts', 'convertAddonInitiateConversion',
            'convert_addon_initiate_conversion', ['domain' => 'a.test', 'username' => 'new',
                'copyMysqlDatabases' => null, 'moveMysqlDatabases' => ['one']],
            'domain=a.test&username=new&movemysqldb-1=one', '{}'];
        yield 'conversion copy singleton' => ['accounts', 'convertAddonInitiateConversion',
            'convert_addon_initiate_conversion', ['domain' => 'a.test', 'username' => 'new',
                'copyMysqlDatabases' => ['0%' => '0']], 'domain=a.test&username=new&copymysqldb-0%25=0', '{}'];
        yield 'mass singleton' => ['accounts', 'massModifyAccount', 'massmodifyacct',
            ['user' => ['a']], 'user-1=a', '{}'];
        yield 'mass omitted settings' => ['accounts', 'massModifyAccount', 'massmodifyacct',
            ['user' => 'a'], 'user=a', '{}'];
        yield 'grey singleton' => ['mail', 'cpgreylistSaveCommonMailProvidersConfig',
            'cpgreylist_save_common_mail_providers_config', ['providers' => ['google' => true]], 'google=1', '{}'];
        yield 'create singleton' => ['hostingPlans', 'createFeaturelist', 'create_featurelist',
            ['featurelist' => 'A', 'features' => ['email' => true]], 'featurelist=A&email=1', '{}'];
        yield 'update multiple' => ['hostingPlans', 'updateFeaturelist', 'update_featurelist',
            ['featurelist' => 'A', 'features' => ['email' => true, 'ftp' => false]],
            'featurelist=A&email=1&ftp=0', '{}'];
        foreach (['reset', 'set'] as $verb) {
            yield "$verb singleton" => ['ssl', $verb . 'AutoSslProvider', $verb . '_autossl_provider',
                ['provider' => 'A', 'parameters' => ['token' => '0']], 'provider=A&x_token=0', 'null'];
        }
        yield 'mass scalar' => ['accounts', 'massModifyAccount', 'massmodifyacct',
            ['user' => 'a'], 'user=a', '{}'];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('invalidRequests')]
    public function testInvalidLateMapClearsMetadataAndNeverDispatches(string $group, string $method, array $args): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $whm->hostingPlans()->createFeaturelist('A', []);
        self::assertNotNull($whm->responseMeta());
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $whm->$group()->$method(...$args);
            self::fail('Invalid maps must not dispatch.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($whm->responseMeta());
            self::assertCount(1, $http->getRequests());
            self::assertStringNotContainsString('late-secret', $error->getMessage());
            self::assertStringNotContainsString('late-secret', $error->getTraceAsString());
            $publicFrames = 0;
            $handle = $whm->$group();
            self::assertIsObject($handle);
            foreach ($error->getTrace() as $frame) {
                if (($frame['class'] ?? null) === $handle::class) {
                    ++$publicFrames;
                    self::assertStringNotContainsString('late-secret', print_r($frame['args'] ?? [], true));
                }
            }
            self::assertSame(1, $publicFrames);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function invalidRequests(): iterable
    {
        foreach (['createFeaturelist', 'updateFeaturelist'] as $method) {
            foreach (
                [
                ['good' => true, 'late-secret' => 1], [0 => true], ['featurelist' => true],
                ['api.version' => false], ['good' => true, 'late-secret' => null]] as $i => $map
            ) {
                yield "$method $i" => ['hostingPlans', $method, ['featurelist' => 'A', 'features' => $map]];
            }
        }
        yield 'overwrite collision' => ['hostingPlans', 'createFeaturelist',
            ['featurelist' => 'A', 'features' => ['overwrite' => false], 'overwrite' => false]];
        yield 'grey collision' => ['mail', 'cpgreylistSaveCommonMailProvidersConfig',
            ['autotrustNewCommonMailProviders' => false,
                'providers' => ['autotrust_new_common_mail_providers' => false]]];
        foreach (['resetAutoSslProvider', 'setAutoSslProvider'] as $method) {
            foreach ([['good' => 'late-secret', 'bad' => false], [0 => 'late-secret']] as $i => $map) {
                yield "$method $i" => ['ssl', $method, ['provider' => 'A', 'parameters' => $map]];
            }
        }
        foreach ([['good' => true, 'late-secret' => 1], [0 => false], ['late-secret' => null]] as $i => $map) {
            yield "grey invalid $i" => ['mail', 'cpgreylistSaveCommonMailProvidersConfig', ['providers' => $map]];
        }
        yield 'copy wrong' => ['accounts', 'convertAddonInitiateConversion',
            ['domain' => 'a', 'username' => 'b', 'copyMysqlDatabases' => ['good' => 'late-secret', 'bad' => 1]]];
        yield 'copy int key' => ['accounts', 'convertAddonInitiateConversion',
            ['domain' => 'a', 'username' => 'b', 'copyMysqlDatabases' => [0 => 'late-secret']]];
        yield 'move empty' => ['accounts', 'convertAddonInitiateConversion',
            ['domain' => 'a', 'username' => 'b', 'moveMysqlDatabases' => []]];
        yield 'users empty' => ['accounts', 'massModifyAccount', ['user' => []]];
        yield 'empty update' => ['hostingPlans', 'updateFeaturelist', ['featurelist' => 'A', 'features' => []]];
        foreach (['resetAutoSslProvider', 'setAutoSslProvider'] as $method) {
            yield $method . ' prefixed key' => ['ssl', $method,
                ['provider' => 'A', 'parameters' => ['x_late-secret' => 'late-secret']]];
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, string}> */
    public static function omittedFixedControls(): iterable
    {
        foreach ([false, true] as $explicitNull) {
            $label = $explicitNull ? 'null' : 'omitted';
            yield "overwrite $label" => ['hostingPlans', 'createFeaturelist',
                ['featurelist' => 'existing-list', 'features' => ['private-entry' => true, 'overwrite' => true]]
                    + ($explicitNull ? ['overwrite' => null] : []), 'overwrite'];
            yield "autotrust $label" => ['mail', 'cpgreylistSaveCommonMailProvidersConfig',
                ['providers' => ['private-entry' => true, 'autotrust_new_common_mail_providers' => false]]
                    + ($explicitNull ? ['autotrustNewCommonMailProviders' => null] : []),
                'autotrust_new_common_mail_providers'];
        }
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('omittedFixedControls')]
    public function testMapCannotInjectOmittedFixedControl(
        string $group,
        string $method,
        array $args,
        string $control,
    ): void {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $whm->hostingPlans()->createFeaturelist('A', []);
        self::assertNotNull($whm->responseMeta());
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $whm->$group()->$method(...$args);
            self::fail('A map must not inject an omitted fixed control.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($whm->responseMeta());
            self::assertCount(1, $http->getRequests());
            self::assertStringNotContainsString($control, $error->getMessage());
            self::assertStringNotContainsString('private-entry', $error->getMessage());
            self::assertStringNotContainsString('private-entry', $error->getTraceAsString());
            foreach ($error->getTrace() as $frame) {
                if (
                    str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($frame['class'], 'HostingPal\\WhmApiClient\\Tests\\')
                ) {
                    $text = print_r($frame['args'] ?? [], true);
                    self::assertStringNotContainsString('private-entry', $text);
                    if (in_array($frame['class'], [OrderedFields::class, CollectionEncoder::class], true)) {
                        self::assertStringNotContainsString($control, $text);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testBothFeatureMapsAreNativeRequiredArguments(): void
    {
        [$whm, $http] = self::client();
        foreach (['createFeaturelist', 'updateFeaturelist'] as $method) {
            try {
                (new ReflectionMethod($whm->hostingPlans(), $method))->invokeArgs($whm->hostingPlans(), ['A']);
                self::fail('Required map omitted.');
            } catch (\ArgumentCountError $error) {
                self::assertStringContainsString('arguments', $error->getMessage());
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    public function testMassModificationRetainsFiftySourceFieldsWithExactCase(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $whm->accounts()->massModifyAccount(
            user: 'a',
            backup: false,
            bwlimit: 'unlimited',
            contactemail: '',
            dbowner: 'owner',
            diskBlockLimit: 0,
            hascgi: false,
            hasdkim: true,
            hasdmarc: false,
            hasshell: false,
            hasspf: true,
            lang: 'en',
            locale: 'en',
            mailboxFormat: 'maildir',
            maxaddon: 0,
            maxftp: 0,
            maxlst: 0,
            maxpark: 0,
            maxpassengerapps: 0,
            maxpop: 0,
            maxsql: 0,
            maxsub: 0,
            maxDeferFailPercentage: 0,
            maxEmailacctQuota: 'unlimited',
            maxEmailPerHour: 0,
            outgoingEmailSuspended: false,
            owner: 'root',
            pushbulletAccessToken: 'opaque%&',
            quota: 'unlimited',
            rs: 'jupiter',
            startdate: 0,
            style: '',
            modifyFirewall: false,
            notifyAccountAuthnLink: false,
            notifyAccountAuthnLinkNotificationDisabled: false,
            notifyAutosslExpiry: false,
            notifyAutosslExpiryCoverage: false,
            notifyAutosslRenewal: false,
            notifyAutosslRenewalCoverage: false,
            notifyContactAddressChange: false,
            notifyContactAddressChangeNotificationDisabled: false,
            notifyDiskLimit: false,
            notifyPasswordChange: false,
            notifyPasswordChangeNotificationDisabled: false,
            notifySslExpiry: false,
            removeMissingExtensions: '',
            renameDatabaseObjects: false,
            reseller: false,
            shell: '/bin/false',
            spamassassin: false,
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $body = (string) $request->getBody();
        self::assertStringContainsString('PUSHBULLET_ACCESS_TOKEN=opaque%25%26', $body);
        $fields = [];
        foreach (explode('&', $body) as $field) {
            [$name, $value] = explode('=', $field, 2);
            $fields[rawurldecode($name)] = rawurldecode($value);
        }
        self::assertCount(50, $fields);
        self::assertEquals([
            'user' => 'a', 'BACKUP' => '0', 'BWLIMIT' => 'unlimited', 'CONTACTEMAIL' => '', 'DBOWNER' => 'owner',
            'DISK_BLOCK_LIMIT' => '0', 'HASCGI' => '0', 'HASDKIM' => '1', 'HASDMARC' => '0', 'HASSHELL' => '0',
            'HASSPF' => '1', 'LANG' => 'en', 'LOCALE' => 'en', 'MAILBOX_FORMAT' => 'maildir', 'MAXADDON' => '0',
            'MAXFTP' => '0', 'MAXLST' => '0', 'MAXPARK' => '0', 'MAXPASSENGERAPPS' => '0', 'MAXPOP' => '0',
            'MAXSQL' => '0', 'MAXSUB' => '0', 'MAX_DEFER_FAIL_PERCENTAGE' => '0', 'MAX_EMAILACCT_QUOTA' => 'unlimited',
            'MAX_EMAIL_PER_HOUR' => '0', 'OUTGOING_EMAIL_SUSPENDED' => '0', 'OWNER' => 'root',
            'PUSHBULLET_ACCESS_TOKEN' => 'opaque%&', 'QUOTA' => 'unlimited', 'RS' => 'jupiter', 'STARTDATE' => '0',
            'STYLE' => '', 'modify_firewall' => '0', 'notify_account_authn_link' => '0',
            'notify_account_authn_link_notification_disabled' => '0', 'notify_autossl_expiry' => '0',
            'notify_autossl_expiry_coverage' => '0', 'notify_autossl_renewal' => '0',
            'notify_autossl_renewal_coverage' => '0', 'notify_contact_address_change' => '0',
            'notify_contact_address_change_notification_disabled' => '0', 'notify_disk_limit' => '0',
            'notify_password_change' => '0', 'notify_password_change_notification_disabled' => '0',
            'notify_ssl_expiry' => '0', 'remove_missing_extensions' => '', 'rename_database_objects' => '0',
            'reseller' => '0', 'shell' => '/bin/false', 'spamassassin' => '0',
        ], $fields);
        self::assertArrayNotHasKey('MXCHECK-*', $fields);
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('outcomeCases')]
    public function testApiFailureMakesExactlyOneAttempt(string $group, string $method, array $args): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"failed"},"data":{}}'));
        try {
            $whm->$group()->$method(...$args);
            self::fail('API failure must throw.');
        } catch (ApiException $error) {
            self::assertNotInstanceOf(PayloadMismatchException::class, $error);
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function outcomeCases(): iterable
    {
        yield 'create' => ['hostingPlans', 'createFeaturelist', ['featurelist' => 'A', 'features' => []]];
        yield 'update' => ['hostingPlans', 'updateFeaturelist', ['featurelist' => 'A', 'features' => ['a' => true]]];
        yield 'grey' => ['mail', 'cpgreylistSaveCommonMailProvidersConfig', []];
        yield 'reset' => ['ssl', 'resetAutoSslProvider', ['provider' => 'A']];
        yield 'set' => ['ssl', 'setAutoSslProvider', ['provider' => 'A']];
        yield 'convert' => ['accounts', 'convertAddonInitiateConversion', ['domain' => 'a', 'username' => 'b']];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('badPayloadCases')]
    public function testAcceptedOutcomePayloadMismatchNeverRetries(
        string $group,
        string $method,
        array $args,
        string $data,
    ): void {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1}' . $data . '}'));
        try {
            $whm->$group()->$method(...$args);
            self::fail('Payload must fail its narrow contract.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($whm->responseMeta(), $error->metadata);
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, string}> */
    public static function badPayloadCases(): iterable
    {
        foreach (self::outcomeCases() as $label => [$group, $method, $args]) {
            if ($group === 'ssl') {
                continue;
            }
            foreach (['', ',"data":null'] as $i => $data) {
                yield "$label $i" => [$group, $method, $args, $data];
            }
        }
        foreach (
            ['{"updated_features":{"email":true}}', '{"invalid_features":[{"bad":"0"}]}',
            '{"invalid_features":{}}', '{"featurelist":null}'] as $i => $data
        ) {
            yield "feature flag $i" => ['hostingPlans', 'updateFeaturelist',
                ['featurelist' => 'A', 'features' => ['a' => true]], ',"data":' . $data];
        }
        yield 'grey flag' => ['mail', 'cpgreylistSaveCommonMailProvidersConfig', [],
            ',"data":{"common_mail_providers":{"google":{"autoupdate":"0"}}}'];
    }

    public function testVoidProviderMethodsAcceptMissingPayload(): void
    {
        [$whm, $http] = self::client();
        foreach (['resetAutoSslProvider', 'setAutoSslProvider'] as $method) {
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1}}'));
            $whm->ssl()->$method('A');
            self::assertNotNull($whm->responseMeta());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return array{WhmClient, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'synthetic-token'], new HttpTransport($http, $factory, $factory));
        return [$client->whm(), $http];
    }
}
