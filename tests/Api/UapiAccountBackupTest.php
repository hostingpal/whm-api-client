<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

/** Literal fixtures derived from the pinned Backup, Restore and WordPress source contracts. */
final class UapiAccountBackupTest extends TestCase
{
    private const SECRET = 'Backup+Secret&=%';

    #[DataProvider('operations')]
    public function testEveryOperationUsesItsExactRouteFieldsAndMethod(
        string $route,
        string $group,
        string $function,
        string $method,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $group, $function, $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            self::invoke($account, $group, $function),
        );
        self::assertRequest($http, $route, $group, $function, $method, $wire);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Backup fixture warning'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function operations(): iterable
    {
        $cases = [
            ['Backup', 'fullbackup_to_ftp', 'POST', 'host=remote.example.test&password=Backup%2BSecret%26%3D%25'
                . '&username=remote%20user&directory=%2Fpublic_ftp%2Fbackup%20path&email=notify%2Bbackup%40example.test'
                . '&homedir=skip&port=2121&variant=passive', '{"pid":"02857"}'],
            ['Backup', 'fullbackup_to_homedir', 'POST', 'email=notify%2Bbackup%40example.test&homedir=skip',
                '{"pid":"2857"}'],
            ['Backup', 'fullbackup_to_scp_with_key', 'POST', 'host=remote.example.test&key_name=key%20name'
                . '&key_passphrase=Backup%2BSecret%26%3D%25&directory=%2Fremote%20path'
                . '&email=notify%2Bbackup%40example.test&homedir=skip&port=2222', '{"pid":"2857"}'],
            ['Backup', 'fullbackup_to_scp_with_password', 'POST', 'host=remote.example.test'
                . '&password=Backup%2BSecret%26%3D%25&username=remote%20user&directory=%2Fremote%20path'
                . '&email=notify%2Bbackup%40example.test&homedir=skip&port=2222', '{"pid":"2857"}'],
            ['Backup', 'list_backups', 'GET', '', '["2026-10-01T00:00:00.000Z","weekly/incremental"]'],
            ['Restore', 'directory_listing', 'GET', 'path=%2Fpublic_html%2Fbackup%20path%2F',
                '[{"conflict":0,"exists":1,"name":"file &.txt","onDiskType":"file","type":"symlink"}]'],
            ['Restore', 'get_users', 'GET', '', '["first","second"]'],
            ['Restore', 'query_file_info', 'GET', 'path=%2Fpublic_html%2Fbackup%20path%2F&exists=0',
                '[{"backupDate":"2026-10-01T00:00:00.000Z","backupID":"weekly/incremental",'
                . '"backupType":"incremental","exists":0,"fileSize":0,"mtime":0,'
                . '"path":"/public_html/backup path/","type":"dir"}]'],
            ['Restore', 'restore_file', 'POST', 'backupID=weekly%2Fincremental&overwrite=0'
                . '&path=%2Fpublic_html%2Fbackup%20path%2F', '{"success":0}'],
            ['WordPressBackup', 'any_running', 'GET', '', '{"any_running":1,"sites":[{"id":null,'
                . '"site":"example.test/blog","type":"backup"}],"sse_url":"/sse/WordPressBackup"}'],
            ['WordPressBackup', 'cancel', 'POST', 'site=example.test%2Fblog', '{"ok":0,"site":"example.test/blog"}'],
            ['WordPressBackup', 'cleanup', 'POST', 'site=example.test%2Fblog', '{"ok":1,"site":"example.test/blog"}'],
            ['WordPressBackup', 'get_available_backups', 'GET', 'site=example.test%2Fblog',
                '{"available":[{"date":1539267197000,"file":"site.tar.gz","path":"/home/user/site.tar.gz",'
                . '"site":"example.test/blog"}],"dir":"/home/user/wordpress-backups"}'],
            ['WordPressBackup', 'is_running', 'GET', 'site=example.test%2Fblog', '{"action_id":null,"is_running":0}'],
            ['WordPressBackup', 'start', 'POST', 'site=example.test%2Fblog',
                '{"backup_id":"backup-id","site":"example.test/blog","sse_url":"/sse/WordPressBackup"}'],
            ['WordPressRestore', 'any_running', 'GET', '', '{"items":{"any_running":0,"last_outcome":{'
                . '"error":null,"have_outcome":1,"site":"example.test/blog","status":"low-quota"},"sites":{}}}'],
            ['WordPressRestore', 'cleanup', 'POST', 'site=example.test%2Fblog', '0'],
            ['WordPressRestore', 'start', 'POST', 'backup_path=%2Fhome%2Fuser%2Fbackup%20%2B%26%3D.tar.gz'
                . '&site=example.test%2Fblog', '{"restore_id":"restore-id"}'],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as [$group, $function, $method, $wire, $payload]) {
                yield $route . ' ' . $group . '/' . $function => [$route, $group, $function, $method, $wire, $payload];
            }
        }
    }

    #[DataProvider('optionalPayloads')]
    public function testOptionalFieldsNullsAndEmptyCollectionsRemainDistinct(
        string $route,
        string $group,
        string $function,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $group, $function, $payload)));
        self::assertSame($expected, self::invoke($client->uapi('exampleuser'), $group, $function));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, string, mixed}> */
    public static function optionalPayloads(): iterable
    {
        $cases = [
            ['Backup', 'fullbackup_to_ftp', '{}', []],
            ['Backup', 'list_backups', '[]', []],
            ['Restore', 'get_users', '[]', []],
            ['Restore', 'directory_listing', '[{}]', [[]]],
            ['Restore', 'query_file_info', '[{}]', [[]]],
            ['Restore', 'restore_file', '{}', []],
            ['WordPressBackup', 'cleanup', 'null', null],
            ['WordPressBackup', 'cleanup', '{}', []],
            ['WordPressBackup', 'get_available_backups', '{"available":null}', ['available' => null]],
            ['WordPressBackup', 'get_available_backups', '{"available":[]}', ['available' => []]],
            ['WordPressBackup', 'get_available_backups', '{}', []],
            ['WordPressBackup', 'is_running', '{}', []],
            ['WordPressBackup', 'any_running', '{"any_running":0,"sites":[]}', ['any_running' => 0, 'sites' => []]],
            ['WordPressRestore', 'any_running', '{}', []],
            ['WordPressRestore', 'any_running', '{"items":{"any_running":1,"sites":[{"id":null,'
                . '"site":"example.test/blog","type":"restore"}]}}', ['items' => ['any_running' => 1,
                    'sites' => [['id' => null, 'site' => 'example.test/blog', 'type' => 'restore']]]]],
            ['WordPressRestore', 'any_running', '{"items":{"last_outcome":{"error":"failed",'
                . '"have_outcome":0,"status":"error"},"sites":{"future":{"opaque":[false,0,null]}}}}',
                ['items' => ['last_outcome' => ['error' => 'failed', 'have_outcome' => 0, 'status' => 'error'],
                    'sites' => ['future' => ['opaque' => [false, 0, null]]]]]],
            ['WordPressRestore', 'cleanup', '1', 1],
            ['WordPressRestore', 'start', '{}', []],
            ['WordPressBackup', 'start', '{"future":"ignored","backup_id":"id"}', ['backup_id' => 'id']],
            ['WordPressBackup', 'cancel', '{}', []],
            ['WordPressBackup', 'cancel', '{"site":""}', ['site' => '']],
            ['WordPressBackup', 'cancel', '{"site":"example.test/path +&=%"}',
                ['site' => 'example.test/path +&=%']],
            ['WordPressBackup', 'start', '{}', []],
            ['WordPressBackup', 'start', '{"site":""}', ['site' => '']],
            ['WordPressBackup', 'start', '{"site":"example.test/path +&=%"}',
                ['site' => 'example.test/path +&=%']],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $index => [$group, $function, $payload, $expected]) {
                yield $route . ' ' . $index => [$route, $group, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedAndMissingPayloadsFailAfterConfirmedApiOutcome(
        string $route,
        string $group,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $group, $function, $payload)));
        try {
            self::invoke($account, $group, $function);
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $seen = [];
        $cases = [];
        foreach (self::operations() as [$route, $group, $function]) {
            $key = $group . '/' . $function;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cases[] = [$group, $function, null];
            $cases[] = [$group, $function, 'false'];
            if ($key !== 'WordPressBackup/cleanup') {
                $cases[] = [$group, $function, 'null'];
            }
        }
        foreach (
            [
            ['Backup', 'fullbackup_to_ftp', '{"pid":2857}'],
            ['Backup', 'fullbackup_to_homedir', '{"pid":null}'],
            ['Backup', 'fullbackup_to_scp_with_key', '{"pid":false}'],
            ['Backup', 'fullbackup_to_scp_with_password', '{"pid":[]}'],
            ['Backup', 'list_backups', '[123]'],
            ['Restore', 'get_users', '[null]'],
            ['Restore', 'directory_listing', '[{"exists":"1"}]'],
            ['Restore', 'directory_listing', '[{"conflict":true}]'],
            ['Restore', 'query_file_info', '[{"fileSize":"0"}]'],
            ['Restore', 'query_file_info', '[{"mtime":null}]'],
            ['Restore', 'restore_file', '{"success":"0"}'],
            ['WordPressBackup', 'any_running', '{"any_running":"0"}'],
            ['WordPressBackup', 'any_running', '{"sites":null}'],
            ['WordPressBackup', 'cancel', '{"ok":false}'],
            ['WordPressBackup', 'cleanup', '{"ok":"1"}'],
            ['WordPressBackup', 'get_available_backups', '{"available":[{"date":"1539267197000"}]}'],
            ['WordPressBackup', 'get_available_backups', '{"dir":null}'],
            ['WordPressBackup', 'is_running', '{"is_running":true}'],
            ['WordPressBackup', 'start', '{"backup_id":null}'],
            ['WordPressRestore', 'any_running', '{"items":null}'],
            ['WordPressRestore', 'any_running', '{"items":{"any_running":2}}'],
            ['WordPressRestore', 'any_running', '{"items":{"any_running":"0"}}'],
            ['WordPressRestore', 'any_running', '{"items":{"last_outcome":{"have_outcome":"1"}}}'],
            ['WordPressRestore', 'cleanup', '"1"'],
            ['WordPressRestore', 'cleanup', '2'],
            ['WordPressRestore', 'start', '{"restore_id":false}'],
            ] as $case
        ) {
            $cases[] = $case;
        }
        foreach (['cancel', 'start'] as $function) {
            foreach (['null', 'false', '0', '1.5', '{}', '[]', '["example.test"]'] as $site) {
                $cases[] = ['WordPressBackup', $function, '{"site":' . $site . '}'];
            }
        }
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $index => [$group, $function, $payload]) {
                yield $route . ' ' . $index => [$route, $group, $function, $payload];
            }
        }
    }

    public function testOmittedOptionsRetainServerDefaultsAndExplicitFalseIsSent(): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope('direct', 'Backup', 'fullbackup_to_homedir', '{}')));
        self::assertSame([], $account->backup()->fullbackupToHomedir());
        self::assertRequest($http, 'direct', 'Backup', 'fullbackup_to_homedir', 'POST', '');
        $http->addResponse(new Response(200, [], self::envelope('direct', 'Restore', 'query_file_info', '[]')));
        self::assertSame([], $account->restore()->queryFileInfo('/'));
        self::assertRequest($http, 'direct', 'Restore', 'query_file_info', 'GET', 'path=%2F');
        $http->addResponse(new Response(200, [], self::envelope('direct', 'Restore', 'query_file_info', '[]')));
        self::assertSame([], $account->restore()->queryFileInfo('/', exists: true));
        self::assertRequest($http, 'direct', 'Restore', 'query_file_info', 'GET', 'path=%2F&exists=1');
    }

    public function testSelectorsAreCachedAndKeepAccountMetadataIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $other = $client->uapi('other');
        self::assertSame($first->backup(), $first->backup());
        self::assertSame($first->restore(), $first->restore());
        self::assertSame($first->wordPressBackup(), $first->wordPressBackup());
        self::assertSame($first->wordPressRestore(), $first->wordPressRestore());
        self::assertNotSame($first->backup(), $other->backup());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'Backup', 'list_backups', '[]')));
        self::assertSame([], $first->backup()->listBackups());
        $metadata = $first->responseMeta();
        $first->wordPressBackup();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($other->responseMeta());
    }

    #[DataProvider('operations')]
    public function testApiFailuresAreNotPayloadSuccessOrRetried(
        string $route,
        string $group,
        string $function,
        string $method,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $group, $function, '{}', 0)));
        try {
            self::invoke($account, $group, $function);
            self::fail('Expected an API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $account->responseMeta()?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testPasswordsAndKeyPassphraseHaveSensitivePublicArguments(): void
    {
        foreach (
            [
            ['fullbackupToFtp', 'password'], ['fullbackupToScpWithPassword', 'password'],
            ['fullbackupToScpWithKey', 'keyPassphrase']] as [$method, $parameter]
        ) {
            $reflection = new ReflectionMethod('HostingPal\\WhmApiClient\\Api\\Uapi\\Backup', $method);
            $matched = false;
            foreach ($reflection->getParameters() as $input) {
                if ($input->getName() === $parameter) {
                    self::assertCount(1, $input->getAttributes(SensitiveParameter::class));
                    $matched = true;
                }
            }
            self::assertTrue($matched);
        }
    }

    private static function invoke(UapiClient $account, string $group, string $function): mixed
    {
        return match ($group . '/' . $function) {
            'Backup/fullbackup_to_ftp' => $account->backup()->fullbackupToFtp('remote.example.test', self::SECRET,
                'remote user', directory: '/public_ftp/backup path', email: 'notify+backup@example.test',
                homedir: 'skip', port: 2121, variant: 'passive'),
            'Backup/fullbackup_to_homedir' => $account->backup()->fullbackupToHomedir(
                email: 'notify+backup@example.test',
                homedir: 'skip',
            ),
            'Backup/fullbackup_to_scp_with_key' => $account->backup()->fullbackupToScpWithKey(
                'remote.example.test',
                'key name', self::SECRET, directory: '/remote path',
                email: 'notify+backup@example.test', homedir: 'skip', port: 2222,
            ),
            'Backup/fullbackup_to_scp_with_password' => $account->backup()->fullbackupToScpWithPassword(
                'remote.example.test',
                self::SECRET, 'remote user', directory: '/remote path',
                email: 'notify+backup@example.test', homedir: 'skip', port: 2222,
            ),
            'Backup/list_backups' => $account->backup()->listBackups(),
            'Restore/directory_listing' => $account->restore()->directoryListing('/public_html/backup path/'),
            'Restore/get_users' => $account->restore()->getUsers(),
            'Restore/query_file_info' => $account->restore()->queryFileInfo('/public_html/backup path/', exists: false),
            'Restore/restore_file' => $account->restore()->restoreFile('weekly/incremental', false,
                '/public_html/backup path/'),
            'WordPressBackup/any_running' => $account->wordPressBackup()->anyRunning(),
            'WordPressBackup/cancel' => $account->wordPressBackup()->cancel('example.test/blog'),
            'WordPressBackup/cleanup' => $account->wordPressBackup()->cleanup('example.test/blog'),
            'WordPressBackup/get_available_backups' => $account->wordPressBackup()->getAvailableBackups(
                'example.test/blog',
            ),
            'WordPressBackup/is_running' => $account->wordPressBackup()->isRunning('example.test/blog'),
            'WordPressBackup/start' => $account->wordPressBackup()->start('example.test/blog'),
            'WordPressRestore/any_running' => $account->wordPressRestore()->anyRunning(),
            'WordPressRestore/cleanup' => $account->wordPressRestore()->cleanup('example.test/blog'),
            'WordPressRestore/start' => $account->wordPressRestore()->start(
                '/home/user/backup +&=.tar.gz',
                'example.test/blog',
            ),
            default => throw new LogicException('Unknown backup fixture.'),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $group,
        string $function,
        string $method,
        string $wire,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $url = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=' . $group . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $group . '/' . $function;
        if ($method === 'GET' && $wire !== '') {
            $url .= ($route === 'bridge' ? '&' : '?') . $wire;
        }
        self::assertSame($method, $request->getMethod());
        self::assertSame($url, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(
        string $route,
        string $group,
        string $function,
        ?string $payload,
        int $status = 1,
    ): string {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Backup fixture failure"]')
            . ',"messages":null,"warnings":["Backup fixture warning"],"metadata":{"transformed":1}}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"' . $group . '","func":"' . $function . '","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser', 'token' => 'fixture-token'];
        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
