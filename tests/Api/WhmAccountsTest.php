<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Accounts;
use HostingPal\WhmApiClient\Api\Whm\Spec\AccountsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\HostingPlansSpec;
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
use ReflectionMethod;
use SensitiveParameter;

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

    /** @return iterable<string, array{string, string, list<mixed>, string, string}> */
    public static function inventoryOperations(): iterable
    {
        yield 'list without filters' => [
            'accounts',
            'listAccounts',
            [],
            'listaccts?api.version=1',
            '{"acct":[' . self::ACCOUNT . ']}',
        ];
        yield 'list with filters' => [
            'accounts',
            'listAccounts',
            ['npr', 'user', 'exact', 'user,domain'],
            'listaccts?api.version=1&search=npr&searchtype=user&searchmethod=exact&want=user%2Cdomain',
            '{"acct":[' . self::ACCOUNT . ']}',
        ];
        yield 'summary' => [
            'accounts',
            'accountSummary',
            ['npr'],
            'accountsummary?api.version=1&user=npr',
            '{"acct":[' . self::ACCOUNT . ']}',
        ];
        yield 'packages' => [
            'hostingPlans',
            'listPackages',
            [],
            'listpkgs?api.version=1',
            '{"pkg":[' . self::PACKAGE . ']}',
        ];
        yield 'creatable packages' => [
            'hostingPlans',
            'listPackages',
            ['creatable'],
            'listpkgs?api.version=1&want=creatable',
            '{"pkg":[' . self::PACKAGE . ']}',
        ];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('inventoryOperations')]
    public function testInventoryOperationsExecuteGetAndReturnValidatedRecords(
        string $group,
        string $method,
        array $arguments,
        string $uri,
        string $data,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $selected = $whm->{$group}();
        self::assertSame($selected, $whm->{$group}());
        self::assertSame([], $http->getRequests());
        $operation = explode('?', $uri)[0];
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        $result = $selected->{$method}(...$arguments);

        self::assertSame($method === 'listPackages' ? [self::expectedPackage()] : [self::expectedAccount()], $result);
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

    /** @return iterable<string, array{string, array<array-key, mixed>, string, string, ?string, mixed}> */
    public static function mutations(): iterable
    {
        yield 'create with options' => [
            'createAccount',
            ['username' => 'newacct', 'domain' => 'new.example', 'password' => 'p a&ss=1', 'plan' => 'default',
                'cgi' => false],
            'createacct',
            'username=newacct&cgi=0&domain=new.example&password=p%20a%26ss%3D1&plan=default',
            '{"ip":"116.202.98.212","nameserver":"ns1.example","package":"default","extra":1}',
            ['ip' => '116.202.98.212', 'nameserver' => 'ns1.example', 'package' => 'default'],
        ];
        yield 'create with repeated account enhancements and null data' => [
            'createAccount',
            ['username' => 'real', 'accountEnhancements' => ['one', 'two']],
            'createacct',
            'username=real&account_enhancements=one&account_enhancements=two',
            'null',
            null,
        ];
        yield 'create without data' => ['createAccount', ['newacct'], 'createacct', 'username=newacct', null, null];
        yield 'suspend with reason and flags' => [
            'suspendAccount',
            ['npr', 'Unpaid invoice', true, false],
            'suspendacct',
            'user=npr&reason=Unpaid%20invoice&disallowun=1&leave-ftp-accts-enabled=0',
            null,
            null,
        ];
        yield 'suspend omits null flags' => ['suspendAccount', ['npr'], 'suspendacct', 'user=npr', null, null];
        yield 'unsuspend' => [
            'unsuspendAccount',
            ['npr', true],
            'unsuspendacct',
            'user=npr&retain-service-proxies=1',
            null,
            null,
        ];
        yield 'remove keeping dns' => [
            'removeAccount', ['npr', true], 'removeacct', 'username=npr&keepdns=1', null, null,
        ];
        yield 'remove omits null flag' => ['removeAccount', ['npr'], 'removeacct', 'username=npr', null, null];
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
            null,
        ];
        yield 'change password with null data' => [
            'changePassword',
            ['npr', 'S3cret!'],
            'passwd',
            'user=npr&password=S3cret%21',
            'null',
            null,
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
            'modifyAccount',
            ['user' => 'npr', 'contactemail' => 'new@npr.rs', 'hasshell' => true],
            'modifyacct',
            'user=npr&HASSHELL=1&contactemail=new%40npr.rs',
            '{"cpuser":{"CONTACTEMAIL":"new@npr.rs","nested":{"k":[1,2]}},"domain":"npr.rs",'
                . '"setshell":"/bin/bash","user":"npr"}',
            [
                'cpuser' => ['CONTACTEMAIL' => 'new@npr.rs', 'nested' => ['k' => [1, 2]]],
                'domain' => 'npr.rs',
                'setshell' => '/bin/bash',
                'user' => 'npr',
            ],
        ];
        yield 'modify without data' => ['modifyAccount', ['npr'], 'modifyacct', 'user=npr', null, null];
    }

    /** @param array<array-key, mixed> $arguments */
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

    /** @return iterable<string, array{string, string, array<mixed>, list<string>}> */
    public static function documentedInputs(): iterable
    {
        yield 'create options' => ['createAccount', 'createacct', AccountsSpec::CREATEACCT, [
            'account_enhancements', 'bwlimit', 'cgi', 'contactemail', 'cpmod', 'customip', 'digestauth', 'dkim',
            'dmarc', 'domain', 'enabledigest', 'featurelist', 'forcedns', 'frontpage', 'gid', 'hasshell',
            'hasuseregns', 'homedir', 'ip', 'language', 'mail_node_alias', 'mailbox_format',
            'max_defer_fail_percentage', 'max_email_per_hour', 'max_emailacct_quota', 'max_team_users', 'maxaddon',
            'maxftp', 'maxlst', 'maxpark', 'maxpop', 'maxsql', 'maxsub', 'mxcheck', 'owner', 'ownerself', 'pass',
            'password', 'pkgname', 'plan', 'quota', 'reseller', 'reseller_without_domain', 'savepkg', 'showpass',
            'spamassassin', 'spambox', 'spf', 'uid', 'useregns',
        ]];
        yield 'modify changes' => ['modifyAccount', 'modifyacct', AccountsSpec::MODIFYACCT, [
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
     * Every documented input key is a named argument forwarded under its wire name, in signature order.
     *
     * @param array<mixed> $spec
     * @param list<string> $keys
     */
    #[DataProvider('documentedInputs')]
    public function testDocumentedInputsAreForwardedUnchangedInTheBody(
        string $method,
        string $operation,
        array $spec,
        array $keys,
    ): void {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response($operation, 'null')));
        $wires = [];
        $arguments = [];
        $expected = [];
        $params = (new ReflectionMethod(Accounts::class, $method))->getParameters();
        $declared = is_array($spec['params'] ?? null) ? $spec['params'] : [];
        foreach ($params as $index => $parameter) {
            $param = $declared[$parameter->getName()] ?? null;
            self::assertIsArray($param);
            $wire = $param['name'] ?? null;
            self::assertIsString($wire);
            $value = match (true) {
                $index === 0 => 'npr',
                ($param['encoding'] ?? null) === 'bool' => true,
                str_contains((string) $parameter->getType(), 'string') => 'v' . $index,
                default => $index,
            };
            $arguments[$parameter->getName()] = $value;
            $expected[] = rawurlencode($wire) . '=' . rawurlencode($value === true ? '1' : (string) $value);
            if ($index > 0) {
                $wires[] = $wire;
            }
        }
        sort($wires);
        sort($keys);
        self::assertSame($keys, $wires);

        $result = $client->whm()->accounts()->{$method}(...$arguments);

        self::assertNull($result);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(implode('&', $expected), (string) $request->getBody());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            (string) $request->getUri(),
        );
    }

    public function testSecretBearingArgumentsAreRedactedFromTraces(): void
    {
        $sensitive = [];
        foreach (['createAccount', 'changePassword', 'modifyAccount'] as $method) {
            foreach ((new ReflectionMethod(Accounts::class, $method))->getParameters() as $parameter) {
                if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                    $sensitive[] = $method . '.' . $parameter->getName();
                }
            }
        }

        self::assertSame([
            'createAccount.pass',
            'createAccount.password',
            'changePassword.password',
            'modifyAccount.pushbulletAccessToken',
        ], $sensitive);
    }

    public function testFailedOutcomeRaisesApiErrorWithTheEnvelopeRetained(): void
    {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"suspendacct","reason":'
            . '"Account npr is already suspended.","result":0,"version":1}}'));

        try {
            $whm->accounts()->suspendAccount('npr', 'twice');
            self::fail('A failed outcome must raise ApiErrorException.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Account npr is already suspended.'], $exception->errors());
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertSame(0, $whm->responseMeta()?->whmResult());
        }
    }

    /** @return iterable<string, array{string, string, list<mixed>, string, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'list without acct' => ['accounts', 'listAccounts', [], 'listaccts', '{}'];
        yield 'list acct not a list' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":{"user":"npr"}}'];
        yield 'list record not an object' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":["npr"]}'];
        yield 'list explicit null data' => ['accounts', 'listAccounts', [], 'listaccts', 'null'];
        yield 'list null acct' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":null}'];
        yield 'list malformed row after a search' => [
            'accounts', 'listAccounts', ['npr', 'user', 'exact', 'user'], 'listaccts', '{"acct":[{"user":1}]}',
        ];
        yield 'list string flag' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":[{"suspended":"0"}]}'];
        yield 'list float limit' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":[{"disklimit":1.5}]}'];
        yield 'list zero-padded uid' => [
            'accounts', 'listAccounts', [], 'listaccts', '{"uid":"01","acct":[{"uid":"01"}]}',
        ];
        yield 'list fractional uid' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":[{"uid":"1.0"}]}'];
        yield 'list overflowing uid' => [
            'accounts', 'listAccounts', [], 'listaccts', '{"acct":[{"uid":"99999999999999999999"}]}',
        ];
        yield 'list boolean uid' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":[{"uid":true}]}'];
        yield 'list ipv6 not strings' => ['accounts', 'listAccounts', [], 'listaccts', '{"acct":[{"ipv6":[1]}]}'];
        yield 'list suspendtime boolean' => [
            'accounts', 'listAccounts', [], 'listaccts', '{"acct":[{"suspendtime":false}]}',
        ];
        yield 'summary without acct' => ['accounts', 'accountSummary', ['npr'], 'accountsummary', '{}'];
        yield 'summary suspendtime boolean' => [
            'accounts', 'accountSummary', ['npr'], 'accountsummary', '{"acct":[{"suspendtime":false}]}',
        ];
        yield 'packages without pkg' => ['hostingPlans', 'listPackages', [], 'listpkgs', '{"pkgs":[]}'];
        yield 'packages noncanonical string team users' => [
            'hostingPlans', 'listPackages', [], 'listpkgs', '{"pkg":[{"MAX_TEAM_USERS":"05"}]}',
        ];
        yield 'create data not an object' => ['accounts', 'createAccount', ['x'], 'createacct', '["ns1"]'];
        yield 'create integer nameserver' => ['accounts', 'createAccount', ['x'], 'createacct', '{"nameserver":1}'];
        yield 'password app not strings' => ['accounts', 'changePassword', ['x', 'y'], 'passwd', '{"app":[1]}'];
        yield 'modify cpuser not an object' => ['accounts', 'modifyAccount', ['x'], 'modifyacct', '{"cpuser":"text"}'];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsRaiseInvalidResponseAndRetainMetadata(
        string $group,
        string $method,
        array $arguments,
        string $operation,
        string $data,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        try {
            $whm->{$group}()->{$method}(...$arguments);
            self::fail('Malformed payloads must raise InvalidResponseException.');
        } catch (InvalidResponseException $exception) {
            self::assertSame('Invalid ' . $operation . ' payload.', $exception->getMessage());
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertSame($operation, $whm->responseMeta()?->whm['command'] ?? null);
        }
    }

    /**
     * Responses below are verbatim bodies from test.oblak.host 11.138.0.19 on 2026-11-01.
     *
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function observedSearchesWithoutMatches(): iterable
    {
        yield 'regex user' => [
            ['search' => '^wrvenv$', 'searchtype' => 'user', 'searchmethod' => 'regex'],
            'search=%5Ewrvenv%24&searchtype=user&searchmethod=regex',
            '{"metadata":{"result":1,"version":1,"reason":"OK","command":"listaccts"}}',
        ];
        yield 'regex user with want' => [
            ['search' => '^wrvenv$', 'searchtype' => 'user', 'searchmethod' => 'regex', 'want' => 'user'],
            'search=%5Ewrvenv%24&searchtype=user&searchmethod=regex&want=user',
            '{"metadata":{"command":"listaccts","reason":"OK","result":1,"version":1}}',
        ];
        yield 'exact domain' => [
            ['search' => 'nosuchacct9dy.example', 'searchtype' => 'domain', 'searchmethod' => 'exact'],
            'search=nosuchacct9dy.example&searchtype=domain&searchmethod=exact',
            '{"metadata":{"reason":"OK","command":"listaccts","version":1,"result":1}}',
        ];
        yield 'exact owner' => [
            ['search' => 'nosuchacct9dy', 'searchtype' => 'owner', 'searchmethod' => 'exact'],
            'search=nosuchacct9dy&searchtype=owner&searchmethod=exact',
            '{"metadata":{"version":1,"result":1,"command":"listaccts","reason":"OK"}}',
        ];
        yield 'exact package' => [
            ['search' => 'nosuchacct9dy', 'searchtype' => 'package', 'searchmethod' => 'exact'],
            'search=nosuchacct9dy&searchtype=package&searchmethod=exact',
            '{"metadata":{"reason":"OK","command":"listaccts","result":1,"version":1}}',
        ];
        yield 'exact ip' => [
            ['search' => '203.0.113.9', 'searchtype' => 'ip', 'searchmethod' => 'exact'],
            'search=203.0.113.9&searchtype=ip&searchmethod=exact',
            '{"metadata":{"command":"listaccts","reason":"OK","version":1,"result":1}}',
        ];
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('observedSearchesWithoutMatches')]
    public function testSearchWithoutMatchesReturnsAnEmptyList(array $arguments, string $query, string $body): void
    {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], $body));

        self::assertSame([], $whm->accounts()->listAccounts(...$arguments));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/listaccts?api.version=1&' . $query,
            (string) $request->getUri(),
        );
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame('listaccts', $whm->responseMeta()?->whm['command'] ?? null);
    }

    /**
     * Responses below are verbatim bodies from test.oblak.host 11.138.0.19 on 2026-11-01.
     *
     * @return iterable<string, array{string, array<string, string>, string, string}>
     */
    public static function observedMissingAccountErrors(): iterable
    {
        $exact = ['search' => 'nosuchacct9dy', 'searchtype' => 'user', 'searchmethod' => 'exact'];
        yield 'list exact user' => [
            'listAccounts',
            $exact,
            '{"metadata":{"version":1,"result":0,"command":"listaccts","reason":"No accounts found."}}',
            'No accounts found.',
        ];
        yield 'list exact user with want' => [
            'listAccounts',
            $exact + ['want' => 'user'],
            '{"metadata":{"result":0,"version":1,"command":"listaccts","reason":"No accounts found."}}',
            'No accounts found.',
        ];
        yield 'summary missing user' => [
            'accountSummary',
            ['user' => 'nosuchacct9dy'],
            '{"metadata":{"reason":"Account does not exist.","command":"accountsummary","result":0,"version":1},'
                . '"data":{"acct":null}}',
            'Account does not exist.',
        ];
        yield 'summary missing domain' => [
            'accountSummary',
            ['domain' => 'nosuchacct9dy.example'],
            '{"data":{"acct":[]},"metadata":{"version":1,"result":0,"reason":"Account does not exist.",'
                . '"command":"accountsummary"}}',
            'Account does not exist.',
        ];
        yield 'summary search only' => [
            'accountSummary',
            $exact,
            '{"metadata":{"reason":"API failure: You must specify a user or domain to get a summary of.\n",'
                . '"command":"accountsummary","version":1,"result":0}}',
            "API failure: You must specify a user or domain to get a summary of.\n",
        ];
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('observedMissingAccountErrors')]
    public function testServerReportedMissingAccountsStayApiErrors(
        string $method,
        array $arguments,
        string $body,
        string $reason,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], $body));

        try {
            $whm->accounts()->{$method}(...$arguments);
            self::fail('A server-reported failure must stay an API error.');
        } catch (ApiErrorException $exception) {
            self::assertSame([$reason], $exception->errors());
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertSame(0, $whm->responseMeta()?->whmResult());
        }
    }

    public function testMatchingSearchWithWantReturnsOnlyTheRequestedFields(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(
            200,
            [],
            '{"data":{"acct":[{"user":"npr"}]},'
                . '"metadata":{"reason":"OK","command":"listaccts","result":1,"version":1}}',
        ));

        self::assertSame(
            [['user' => 'npr']],
            $client->whm()->accounts()->listAccounts('npr', 'user', 'exact', 'user'),
        );
    }

    public function testOnlyListAccountsReadsOmittedDataAsAnEmptyResult(): void
    {
        self::assertArrayHasKey('empty_without_data', AccountsSpec::LISTACCTS);
        self::assertArrayNotHasKey('empty_without_data', AccountsSpec::ACCOUNTSUMMARY);
        self::assertArrayNotHasKey('empty_without_data', HostingPlansSpec::LISTPKGS);
    }

    public function testSummaryReturnsEveryRecordWithoutRequiringExactlyOne(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response('accountsummary', '{"acct":[]}')));
        $http->addResponse(new Response(200, [], self::response('accountsummary', '{"acct":[{},{"user":"npr"}]}')));

        self::assertSame([], $client->whm()->accounts()->accountSummary('npr'));
        self::assertSame([[], ['user' => 'npr']], $client->whm()->accounts()->accountSummary('npr'));
    }

    public function testObservedDecimalStringUidIsNormalizedToThePinnedInteger(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response(
            'accountsummary',
            '{"acct":[{"user":"npr","uid":"1001","suspendtime":0,"maxaddons":"0","standalone_feature":""}]}',
        )));

        $summary = $client->whm()->accounts()->accountSummary('npr');

        self::assertSame(
            [['maxaddons' => '0', 'standalone_feature' => '', 'suspendtime' => 0, 'uid' => 1001, 'user' => 'npr']],
            $summary,
        );
    }

    public function testListAndSummaryShareTheSuspendtimeContract(): void
    {
        [$client, $http] = $this->client();
        $payload = '{"acct":[{"suspendtime":1594040856},{"suspendtime":"1594040856"},{"suspendtime":null},'
            . '{"suspendtime":0}]}';
        $http->addResponse(new Response(200, [], self::response('listaccts', $payload)));
        $http->addResponse(new Response(200, [], self::response('accountsummary', $payload)));
        $expected = [
            ['suspendtime' => 1594040856],
            ['suspendtime' => '1594040856'],
            ['suspendtime' => null],
            ['suspendtime' => 0],
        ];

        self::assertSame($expected, $client->whm()->accounts()->listAccounts());
        self::assertSame($expected, $client->whm()->accounts()->accountSummary('npr'));
    }

    public function testSignedDecimalUidIsAcceptedPendingRangeEnforcement(): void
    {
        // The deleted hand-written validator rejected signed decimal strings; minimum/range enforcement is
        // deferred to bead whm-api-client-8x0.18, so the generated engine normalizes "-1" to -1.
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::response('listaccts', '{"acct":[{"uid":"-1"}]}')));

        self::assertSame([['uid' => -1]], $client->whm()->accounts()->listAccounts());
    }

    public function testGroupIsBoundToItsOwningClientOnly(): void
    {
        [$client, $http] = $this->client();
        [$other] = $this->client();
        $http->addResponse(new Response(200, [], self::response('listpkgs', '{"pkg":[]}')));

        self::assertSame([], $client->whm()->hostingPlans()->listPackages());
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
