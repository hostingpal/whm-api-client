<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Accounts;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmAccountsTest extends TestCase
{
    private const ACCOUNT = '{"backup":1,"child_nodes":[],"disklimit":"unlimited","diskused":"12M",'
        . '"domain":"npr.rs","email":"owner@npr.rs","has_backup":0,"inodeslimit":"unlimited",'
        . '"inodesused":1234,"ip":"116.202.98.212","ipv6":[],"is_locked":0,"is_temporary_domain":0,'
        . '"legacy_backup":0,"mailbox_format":"maildir","max_defer_fail_percentage":"unlimited",'
        . '"max_email_per_hour":"unlimited","max_emailacct_quota":1024,"maxaddons":"unlimited",'
        . '"maxftp":"unlimited","maxlst":"unlimited","maxparked":"unlimited","maxpop":"unlimited",'
        . '"maxsql":"unlimited","maxsub":"unlimited","min_defer_fail_to_trigger_protection":5,'
        . '"outgoing_mail_hold":0,"outgoing_mail_suspended":0,"owner":"root","partition":"home",'
        . '"plan":"default","shell":"/bin/bash","startdate":"13 Oct 24 10:00","suspended":0,'
        . '"suspendreason":"not suspended","suspendtime":null,"temporary":0,"theme":"jupiter",'
        . '"uid":1001,"unix_startdate":1728813600,"user":"npr","undocumented":"ignored"}';

    private const PACKAGE = '{"BWLIMIT":"unlimited","CGI":"y","CPMOD":"jupiter","DIGESTAUTH":"n",'
        . '"FEATURELIST":"default","HASSHELL":"n","IP":"n","LANG":"en","MAXADDON":0,"MAXFTP":"unlimited",'
        . '"MAXLST":"unlimited","MAXPARK":0,"MAXPOP":"unlimited","MAXSQL":"unlimited","MAXSUB":"unlimited",'
        . '"MAX_DEFER_FAIL_PERCENTAGE":"unlimited","MAX_EMAILACCT_QUOTA":"unlimited",'
        . '"MAX_EMAIL_PER_HOUR":"unlimited","MAX_TEAM_USERS":null,"QUOTA":"unlimited",'
        . '"_PACKAGE_EXTENSIONS":"","name":"default","extra":true}';

    /** @return iterable<string, array{string, list<mixed>, string, string}> */
    public static function inventoryOperations(): iterable
    {
        yield 'list without filters' => [
            'list',
            [],
            'listaccts?api.version=1',
            '{"acct":[' . self::ACCOUNT . ']}',
        ];
        yield 'list with filters' => [
            'list',
            ['npr', 'user', 'exact', 'user,domain'],
            'listaccts?api.version=1&search=npr&searchmethod=exact&searchtype=user&want=user%2Cdomain',
            '{"acct":[' . self::ACCOUNT . ']}',
        ];
        yield 'summary' => [
            'summary',
            ['npr'],
            'accountsummary?api.version=1&user=npr',
            '{"acct":[' . self::ACCOUNT . ']}',
        ];
        yield 'packages' => ['packages', [], 'listpkgs?api.version=1', '{"pkg":[' . self::PACKAGE . ']}'];
        yield 'creatable packages' => [
            'packages',
            ['creatable'],
            'listpkgs?api.version=1&want=creatable',
            '{"pkg":[' . self::PACKAGE . ']}',
        ];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('inventoryOperations')]
    public function testInventoryOperationsExecuteGetAndReturnValidatedRecords(
        string $method,
        array $arguments,
        string $uri,
        string $data,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $accounts = $whm->accounts();
        self::assertSame($accounts, $whm->accounts());
        self::assertSame([], $http->getRequests());
        $operation = explode('?', $uri)[0];
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        $result = $accounts->{$method}(...$arguments);

        $expectedAccount = self::expectedAccount();
        self::assertSame(match ($method) {
            'list' => [$expectedAccount],
            'summary' => $expectedAccount,
            default => [self::expectedPackage()],
        }, $result);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $uri, (string) $request->getUri());
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $request->getBody());
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame($operation, $whm->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, list<mixed>, string, string, ?string, mixed}> */
    public static function mutations(): iterable
    {
        yield 'create with options' => [
            'create',
            ['newacct', ['domain' => 'new.example', 'password' => 'p a&ss=1', 'plan' => 'default', 'cgi' => 0]],
            'createacct',
            'username=newacct&domain=new.example&password=p%20a%26ss%3D1&plan=default&cgi=0',
            '{"ip":"116.202.98.212","nameserver":"ns1.example","package":"default","extra":1}',
            ['ip' => '116.202.98.212', 'nameserver' => 'ns1.example', 'package' => 'default'],
        ];
        yield 'create argument wins over username option' => [
            'create',
            ['real', ['username' => 'spoofed', 'account_enhancements' => ['one', 'two']]],
            'createacct',
            'username=real&account_enhancements=one&account_enhancements=two',
            'null',
            [],
        ];
        yield 'suspend with reason and flags' => [
            'suspend',
            ['npr', 'Unpaid invoice', true, false],
            'suspendacct',
            'user=npr&reason=Unpaid%20invoice&disallowun=1&leave-ftp-accts-enabled=0',
            null,
            null,
        ];
        yield 'suspend omits null flags' => ['suspend', ['npr'], 'suspendacct', 'user=npr', null, null];
        yield 'unsuspend' => [
            'unsuspend',
            ['npr', true],
            'unsuspendacct',
            'user=npr&retain-service-proxies=1',
            null,
            null,
        ];
        yield 'remove keeping dns' => ['remove', ['npr', true], 'removeacct', 'username=npr&keepdns=1', null, null];
        yield 'remove omits null flag' => ['remove', ['npr'], 'removeacct', 'username=npr', null, null];
        yield 'change password' => [
            'changePassword',
            ['npr', 'S3cret!', false, null],
            'passwd',
            'user=npr&password=S3cret%21&db_pass_update=0',
            '{"app":["system","ftp","mail"]}',
            ['app' => ['system', 'ftp', 'mail']],
        ];
        yield 'change password without data' => [
            'changePassword',
            ['npr', 'S3cret!'],
            'passwd',
            'user=npr&password=S3cret%21',
            null,
            [],
        ];
        yield 'change password with digest authentication' => [
            'changePassword',
            ['npr', 'S3cret!', null, true],
            'passwd',
            'user=npr&password=S3cret%21&digestauth=1',
            '{}',
            [],
        ];
        yield 'change package' => [
            'changePackage',
            ['npr', 'gold'],
            'changepackage',
            'user=npr&pkg=gold',
            null,
            null,
        ];
        yield 'modify' => [
            'modify',
            ['npr', ['user' => 'spoofed', 'contactemail' => 'new@npr.rs', 'HASSHELL' => 1]],
            'modifyacct',
            'user=npr&contactemail=new%40npr.rs&HASSHELL=1',
            '{"cpuser":{"CONTACTEMAIL":"new@npr.rs","nested":{"k":[1,2]}},"domain":"npr.rs",'
                . '"setshell":"/bin/bash","user":"npr"}',
            [
                'cpuser' => ['CONTACTEMAIL' => 'new@npr.rs', 'nested' => ['k' => [1, 2]]],
                'domain' => 'npr.rs',
                'setshell' => '/bin/bash',
                'user' => 'npr',
            ],
        ];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('mutations')]
    public function testMutationsPostFormEncodedParametersAndKeepThemOutOfTheQuery(
        string $method,
        array $arguments,
        string $operation,
        string $body,
        ?string $data,
        mixed $expected,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $envelope = $data === null
            ? '{"metadata":{"command":"' . $operation . '","reason":"OK","result":1,"version":1,'
                . '"output":{"raw":"log"}}}'
            : self::response($operation, $data);
        $http->addResponse(new Response(200, [], $envelope));

        $result = $whm->accounts()->{$method}(...$arguments);

        self::assertSame($expected, $result);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame($body, (string) $request->getBody());
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame($operation, $whm->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function documentedInputs(): iterable
    {
        yield 'create options' => ['create', 'createacct', [
            'account_enhancements', 'bwlimit', 'cgi', 'contactemail', 'cpmod', 'customip', 'digestauth', 'dkim',
            'dmarc', 'domain', 'enabledigest', 'featurelist', 'forcedns', 'frontpage', 'gid', 'hasshell',
            'hasuseregns', 'homedir', 'ip', 'language', 'mail_node_alias', 'mailbox_format',
            'max_defer_fail_percentage', 'max_email_per_hour', 'max_emailacct_quota', 'max_team_users', 'maxaddon',
            'maxftp', 'maxlst', 'maxpark', 'maxpop', 'maxsql', 'maxsub', 'mxcheck', 'owner', 'ownerself', 'pass',
            'password', 'pkgname', 'plan', 'quota', 'reseller', 'reseller_without_domain', 'savepkg', 'showpass',
            'spamassassin', 'spambox', 'spf', 'uid', 'useregns',
        ]];
        yield 'modify changes' => ['modify', 'modifyacct', [
            'account_enhancements', 'BACKUP', 'BWLIMIT', 'contactemail', 'DBOWNER', 'DISK_BLOCK_LIMIT', 'DNS',
            'domain', 'HASCGI', 'HASDKIM', 'HASDMARC', 'HASSHELL', 'HASSPF', 'LANG', 'LOCALE', 'mail_node_alias',
            'MAILBOX_FORMAT', 'MAX_DEFER_FAIL_PERCENTAGE', 'MAX_EMAIL_PER_HOUR', 'MAX_EMAILACCT_QUOTA',
            'max_team_users', 'MAXADDON', 'MAXFTP', 'MAXLST', 'MAXPARK', 'MAXPASSENGERAPPS', 'MAXPOP', 'MAXSQL',
            'MAXSUB', 'modify_firewall', 'newuser', 'notify_account_authn_link',
            'notify_account_authn_link_notification_disabled', 'notify_autossl_expiry',
            'notify_autossl_expiry_coverage', 'notify_autossl_renewal', 'notify_autossl_renewal_coverage',
            'notify_contact_address_change', 'notify_contact_address_change_notification_disabled',
            'notify_disk_limit', 'notify_password_change', 'notify_password_change_notification_disabled',
            'notify_ssl_expiry', 'OUTGOING_EMAIL_SUSPENDED', 'owner', 'PUSHBULLET_ACCESS_TOKEN', 'QUOTA',
            'remove_missing_extensions', 'rename_database_objects', 'reseller', 'RS', 'shell', 'spamassassin',
            'STARTDATE', 'STYLE', 'update_existing_email_account_quotas',
        ]];
    }

    /**
     * Every documented input key is forwarded unchanged, in order, in the form body.
     *
     * @param list<string> $keys
     */
    #[DataProvider('documentedInputs')]
    public function testDocumentedInputsAreForwardedUnchangedInTheBody(
        string $method,
        string $operation,
        array $keys,
    ): void {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response($operation, 'null')));
        $input = [];
        $expected = [$method === 'create' ? 'username=npr' : 'user=npr'];
        foreach ($keys as $index => $key) {
            $input[$key] = 'v' . $index;
            $expected[] = rawurlencode($key) . '=v' . $index;
        }

        $result = $client->whm()->accounts()->{$method}('npr', $input);

        self::assertSame([], $result);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(implode('&', $expected), (string) $request->getBody());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            (string) $request->getUri(),
        );
    }

    public function testFailedOutcomeRaisesApiErrorWithTheEnvelopeRetained(): void
    {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"suspendacct","reason":'
            . '"Account npr is already suspended.","result":0,"version":1}}'));

        try {
            $whm->accounts()->suspend('npr', 'twice');
            self::fail('A failed outcome must raise ApiErrorException.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Account npr is already suspended.'], $exception->errors());
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertSame(0, $whm->responseMeta()?->whmResult());
        }
    }

    /** @return iterable<string, array{string, list<mixed>, string, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'list without acct' => ['list', [], 'listaccts', '{}'];
        yield 'list acct not a list' => ['list', [], 'listaccts', '{"acct":{"user":"npr"}}'];
        yield 'list record not an object' => ['list', [], 'listaccts', '{"acct":["npr"]}'];
        yield 'list string flag' => ['list', [], 'listaccts', '{"acct":[{"suspended":"0"}]}'];
        yield 'list float limit' => ['list', [], 'listaccts', '{"acct":[{"disklimit":1.5}]}'];
        yield 'list signed uid' => ['list', [], 'listaccts', '{"acct":[{"uid":"-1"}]}'];
        yield 'list zero-padded uid' => ['list', [], 'listaccts', '{"uid":"01","acct":[{"uid":"01"}]}'];
        yield 'list fractional uid' => ['list', [], 'listaccts', '{"acct":[{"uid":"1.0"}]}'];
        yield 'list overflowing uid' => ['list', [], 'listaccts', '{"acct":[{"uid":"99999999999999999999"}]}'];
        yield 'list boolean uid' => ['list', [], 'listaccts', '{"acct":[{"uid":true}]}'];
        yield 'list ipv6 not strings' => ['list', [], 'listaccts', '{"acct":[{"ipv6":[1]}]}'];
        yield 'list suspendtime boolean' => ['list', [], 'listaccts', '{"acct":[{"suspendtime":false}]}'];
        yield 'summary empty' => ['summary', ['npr'], 'accountsummary', '{"acct":[]}'];
        yield 'summary ambiguous' => ['summary', ['npr'], 'accountsummary', '{"acct":[{},{}]}'];
        yield 'packages without pkg' => ['packages', [], 'listpkgs', '{"pkgs":[]}'];
        yield 'packages string team users' => ['packages', [], 'listpkgs', '{"pkg":[{"MAX_TEAM_USERS":"5"}]}'];
        yield 'create data not an object' => ['create', ['x'], 'createacct', '["ns1"]'];
        yield 'create integer nameserver' => ['create', ['x'], 'createacct', '{"nameserver":1}'];
        yield 'password app not strings' => ['changePassword', ['x', 'y'], 'passwd', '{"app":[1]}'];
        yield 'modify cpuser not an object' => ['modify', ['x', []], 'modifyacct', '{"cpuser":"text"}'];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsRaiseInvalidResponseAndRetainMetadata(
        string $method,
        array $arguments,
        string $operation,
        string $data,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        try {
            $whm->accounts()->{$method}(...$arguments);
            self::fail('Malformed payloads must raise InvalidResponseException.');
        } catch (InvalidResponseException $exception) {
            self::assertSame('Invalid account payload.', $exception->getMessage());
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertSame($operation, $whm->responseMeta()?->whm['command'] ?? null);
        }
    }

    public function testObservedDecimalStringUidIsNormalizedToThePinnedInteger(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response(
            'accountsummary',
            '{"acct":[{"user":"npr","uid":"1001","suspendtime":0,"maxaddons":"0","standalone_feature":""}]}',
        )));

        $summary = $client->whm()->accounts()->summary('npr');

        self::assertSame(
            ['user' => 'npr', 'uid' => 1001, 'suspendtime' => 0, 'maxaddons' => '0', 'standalone_feature' => ''],
            $summary,
        );
    }

    public function testGroupIsBoundToItsOwningClientOnly(): void
    {
        [$client, $http] = $this->client();
        [$other] = $this->client();
        $http->addResponse(new Response(200, [], self::response('listpkgs', '{"pkg":[]}')));

        self::assertSame([], $client->whm()->accounts()->packages());
        self::assertInstanceOf(Accounts::class, $other->whm()->accounts());
        self::assertNull($other->whm()->responseMeta());
        self::assertNotNull($client->whm()->responseMeta());
    }

    /** @return array<string, mixed> */
    private static function expectedAccount(): array
    {
        $account = json_decode(self::ACCOUNT, true, 512, JSON_THROW_ON_ERROR);
        unset($account['undocumented']);

        return $account;
    }

    /** @return array<string, mixed> */
    private static function expectedPackage(): array
    {
        $package = json_decode(self::PACKAGE, true, 512, JSON_THROW_ON_ERROR);
        unset($package['extra']);

        return $package;
    }

    private static function response(string $operation, string $data): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"OK","result":1,"version":1},'
            . '"data":' . $data . '}';
    }

    /** @return array{Client, MockClient} */
    private function client(): array
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
