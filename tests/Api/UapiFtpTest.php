<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Ftp;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Independent literal expectations from the pinned Ftp contracts, never generated specs.
 *
 * @phpstan-type FtpCall Closure(Ftp): mixed
 * @phpstan-type Operation array{string, string, FtpCall, string, string, mixed}
 */
final class UapiFtpTest extends TestCase
{
    /** @param FtpCall $call */
    #[DataProvider('successfulCalls')]
    public function testOperationsUseTheAccountBoundRouteAndPreserveTheirPayload(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));

        self::assertSame($expected, $call($account->ftp()));
        self::assertRequest($http, $route, $function, $method, $parameters);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic FTP notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, FtpCall, string, string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => $operation) {
                yield $route . ' ' . $name => [$route, ...$operation];
            }
            foreach (self::optionalArguments() as $name => $operation) {
                yield $route . ' ' . $name => [$route, ...$operation];
            }
            foreach (self::operations() as $name => [$function, $method, $call, $parameters, $payload]) {
                if ($payload === 'null') {
                    // The engine retains nullable opaque objects under its general enum policy.
                    // add_ftp pins enum [null]; these objects test engine behavior, not live evidence.
                    yield $route . ' ' . $name . ' opaque object' => [
                        $route, $function, $method, $call, $parameters,
                        '{"opaque":{"numbers":[0,1],"none":null},"0":"literal"}',
                        ['opaque' => ['numbers' => [0, 1], 'none' => null], 0 => 'literal'],
                    ];
                    yield $route . ' ' . $name . ' empty object' => [
                        $route, $function, $method, $call, $parameters, '{}', [],
                    ];
                }
            }
        }
    }

    /**
     * Every endpoint has independently authored wire parameters and a documented payload shape.
     *
     * @return iterable<string, Operation>
     */
    public static function operations(): iterable
    {
        yield 'add FTP password' => [
            'add_ftp', 'POST', static fn (Ftp $ftp): mixed => $ftp->addFtp(
                user: 'u.name',
                password: 'p a+&=%/雪',
                domain: 'example.test',
                homedir: 'public_ftp/a +&',
                quota: 0,
                disallowdot: false,
            ),
            'user=u.name&pass=p%20a%2B%26%3D%25%2F%E9%9B%AA&domain=example.test'
                . '&homedir=public_ftp%2Fa%20%2B%26&quota=0&disallowdot=0',
            'null', null,
        ];
        yield 'anonymous FTP access' => [
            'allows_anonymous_ftp', 'GET', static fn (Ftp $ftp): mixed => $ftp->allowsAnonymousFtp(),
            '', '{"allows":0,"extra":true}', ['allows' => 0],
        ];
        yield 'anonymous FTP incoming' => [
            'allows_anonymous_ftp_incoming', 'GET', static fn (Ftp $ftp): mixed => $ftp->allowsAnonymousFtpIncoming(),
            '', '{"allows":1}', ['allows' => 1],
        ];
        yield 'delete without destroying home' => [
            'delete_ftp', 'POST', static fn (Ftp $ftp): mixed =>
                $ftp->deleteFtp(user: 'u.name', destroy: false, domain: 'example.test'),
            'user=u.name&destroy=0&domain=example.test', 'null', null,
        ];
        yield 'existing FTP account' => [
            'ftp_exists', 'GET', static fn (Ftp $ftp): mixed =>
                $ftp->ftpExists(user: 'u +&', domain: 'example.test'),
            'user=u%20%2B%26&domain=example.test', 'null', null,
        ];
        yield 'daemon information' => [
            'get_ftp_daemon_info', 'GET', static fn (Ftp $ftp): mixed => $ftp->getFtpDaemonInfo(), '',
            '{"enabled":1,"name":"pure-ftpd","supports":{"login_without_domain":0,"quota":1,"extra":true}}',
            ['enabled' => 1, 'name' => 'pure-ftpd', 'supports' => ['login_without_domain' => 0, 'quota' => 1]],
        ];
        yield 'FTP port' => [
            'get_port', 'GET', static fn (Ftp $ftp): mixed => $ftp->getPort(), '', '{"port":21}', ['port' => 21],
        ];
        yield 'finite quota is a string' => [
            'get_quota', 'GET', static fn (Ftp $ftp): mixed =>
                $ftp->getQuota(account: 'u +&', domain: 'example.test'),
            'account=u%20%2B%26&domain=example.test', '"500"', '500',
        ];
        yield 'welcome text' => [
            'get_welcome_message', 'GET', static fn (Ftp $ftp): mixed => $ftp->getWelcomeMessage(),
            '', '"Welcome\\nFTP users"', "Welcome\nFTP users",
        ];
        yield 'kill only named session' => [
            'kill_session', 'POST', static fn (Ftp $ftp): mixed => $ftp->killSession(login: 'u@example.test'),
            'login=u%40example.test', 'null', null,
        ];
        yield 'FTP account list with pipe filters' => [
            'list_ftp', 'GET', static fn (Ftp $ftp): mixed =>
                $ftp->listFtp(includeAcctTypes: 'main|sub', skipAcctTypes: 'anonymous|logaccess'),
            'include_acct_types=main%7Csub&skip_acct_types=anonymous%7Clogaccess',
            '[{"homedir":"/home/exampleuser/public_ftp","type":"sub","user":"u","extra":true},{}]',
            [['homedir' => '/home/exampleuser/public_ftp', 'type' => 'sub', 'user' => 'u'], []],
        ];
        yield 'FTP disk inventory preserves decimal strings and null' => [
            'list_ftp_with_disk', 'GET', static fn (Ftp $ftp): mixed =>
                $ftp->listFtpWithDisk(includeAcctTypes: 'sub', skipAcctTypes: 'main|anonymous'),
            'include_acct_types=sub&skip_acct_types=main%7Canonymous',
            '[{"_diskquota":"0.00","_diskused":"2.34","accttype":"sub","deleteable":1,'
                . '"dir":"/home/exampleuser/public_ftp","diskquota":"unlimited","diskused":"2.34",'
                . '"diskusedpercent":0,"diskusedpercent20":0,"htmldir":null,"humandiskquota":"None",'
                . '"humandiskused":"2 MB","login":"u","reldir":"public_ftp",'
                . '"serverlogin":"u@example.test","extra":true},'
                . '{"_diskquota":"476.84","diskquota":"476.84","htmldir":"/public_ftp","deleteable":0},{}]',
            [
                ['_diskquota' => '0.00', '_diskused' => '2.34', 'accttype' => 'sub', 'deleteable' => 1,
                    'dir' => '/home/exampleuser/public_ftp', 'diskquota' => 'unlimited', 'diskused' => '2.34',
                    'diskusedpercent' => 0, 'diskusedpercent20' => 0, 'htmldir' => null, 'humandiskquota' => 'None',
                    'humandiskused' => '2 MB', 'login' => 'u', 'reldir' => 'public_ftp',
                    'serverlogin' => 'u@example.test'],
                ['_diskquota' => '476.84', 'deleteable' => 0, 'diskquota' => '476.84', 'htmldir' => '/public_ftp'], [],
            ],
        ];
        yield 'FTP sessions' => [
            'list_sessions', 'GET', static fn (Ftp $ftp): mixed => $ftp->listSessions(), '',
            '[{"cmdline":"pure-ftpd (IDLE)","file":"","host":"192.0.2.5",'
                . '"login":"Tue Apr 23 16:23:23 2020","pid":6735,"status":"IDLE","user":"u","extra":true},{}]',
            [['cmdline' => 'pure-ftpd (IDLE)', 'file' => '', 'host' => '192.0.2.5',
                'login' => 'Tue Apr 23 16:23:23 2020', 'pid' => 6735, 'status' => 'IDLE', 'user' => 'u'], []],
        ];
        yield 'change password' => [
            'passwd', 'POST', static fn (Ftp $ftp): mixed =>
                $ftp->changePassword(user: 'u', password: 'p a+&=%/雪', domain: 'example.test'),
            'user=u&pass=p%20a%2B%26%3D%25%2F%E9%9B%AA&domain=example.test', 'null', null,
        ];
        yield 'server disabled' => [
            'server_name', 'GET', static fn (Ftp $ftp): mixed => $ftp->serverName(), '', '"disabled"', 'disabled',
        ];
        yield 'disable anonymous FTP' => [
            'set_anonymous_ftp', 'POST', static fn (Ftp $ftp): mixed => $ftp->setAnonymousFtp(set: false),
            'set=0', 'null', null,
        ];
        yield 'enable anonymous incoming FTP' => [
            'set_anonymous_ftp_incoming', 'POST', static fn (Ftp $ftp): mixed =>
                $ftp->setAnonymousFtpIncoming(set: true), 'set=1', 'null', null,
        ];
        yield 'set home directory' => [
            'set_homedir', 'POST', static fn (Ftp $ftp): mixed =>
                $ftp->setHomedir(user: 'u', domain: 'example.test', homedir: 'public_ftp/a +&'),
            'user=u&domain=example.test&homedir=public_ftp%2Fa%20%2B%26', 'null', null,
        ];
        yield 'zero quota with explicit flag' => [
            'set_quota', 'POST', static fn (Ftp $ftp): mixed =>
                $ftp->setQuota(user: 'u', domain: 'example.test', kill: false, quota: 0),
            'user=u&domain=example.test&kill=0&quota=0', 'null', null,
        ];
        yield 'set welcome text' => [
            'set_welcome_message', 'POST', static fn (Ftp $ftp): mixed =>
                $ftp->setWelcomeMessage(message: "Welcome +&\nFTP users"),
            'message=Welcome%20%2B%26%0AFTP%20users', 'null', null,
        ];
    }

    /** @return iterable<string, Operation> */
    public static function optionalArguments(): iterable
    {
        yield 'password hash omits plaintext password' => [
            'add_ftp', 'POST', static fn (Ftp $ftp): mixed => $ftp->addFtp(user: 'u', passHash: '$6$a+b/c='),
            'user=u&pass_hash=%246%24a%2Bb%2Fc%3D', 'null', null,
        ];
        yield 'add omits all optional defaults' => [
            'add_ftp', 'POST', static fn (Ftp $ftp): mixed => $ftp->addFtp(user: 'u'), 'user=u', 'null', null,
        ];
        yield 'delete omits destroy and domain' => [
            'delete_ftp', 'POST', static fn (Ftp $ftp): mixed => $ftp->deleteFtp(user: 'u'), 'user=u', 'null', null,
        ];
        yield 'exists omits domain' => [
            'ftp_exists', 'GET', static fn (Ftp $ftp): mixed => $ftp->ftpExists(user: 'u'), 'user=u', 'null', null,
        ];
        yield 'unlimited quota omits domain' => [
            'get_quota', 'GET', static fn (Ftp $ftp): mixed => $ftp->getQuota(account: 'u'),
            'account=u', '"unlimited"', 'unlimited',
        ];
        yield 'kill session omits default all' => [
            'kill_session', 'POST', static fn (Ftp $ftp): mixed => $ftp->killSession(), '', 'null', null,
        ];
        yield 'empty account list omits filters' => [
            'list_ftp', 'GET', static fn (Ftp $ftp): mixed => $ftp->listFtp(), '', '[]', [],
        ];
        yield 'empty disk list omits filters' => [
            'list_ftp_with_disk', 'GET', static fn (Ftp $ftp): mixed => $ftp->listFtpWithDisk(), '', '[]', [],
        ];
        yield 'explicit empty filter remains present' => [
            'list_ftp', 'GET', static fn (Ftp $ftp): mixed => $ftp->listFtp(includeAcctTypes: ''),
            'include_acct_types=', '[]', [],
        ];
        yield 'empty session list' => [
            'list_sessions', 'GET', static fn (Ftp $ftp): mixed => $ftp->listSessions(), '', '[]', [],
        ];
        yield 'password omits domain' => [
            'passwd', 'POST', static fn (Ftp $ftp): mixed =>
                $ftp->changePassword(user: 'u', password: 'fixture-password'),
            'user=u&pass=fixture-password', 'null', null,
        ];
        yield 'anonymous setting omits default' => [
            'set_anonymous_ftp', 'POST', static fn (Ftp $ftp): mixed => $ftp->setAnonymousFtp(), '', 'null', null,
        ];
        yield 'incoming setting omits default' => [
            'set_anonymous_ftp_incoming', 'POST', static fn (Ftp $ftp): mixed => $ftp->setAnonymousFtpIncoming(),
            '', 'null', null,
        ];
        yield 'home directory omits defaults' => [
            'set_homedir', 'POST', static fn (Ftp $ftp): mixed => $ftp->setHomedir(user: 'u'), 'user=u', 'null', null,
        ];
        yield 'quota omits defaults' => [
            'set_quota', 'POST', static fn (Ftp $ftp): mixed => $ftp->setQuota(user: 'u'), 'user=u', 'null', null,
        ];
        yield 'empty welcome text remains present' => [
            'set_welcome_message', 'POST', static fn (Ftp $ftp): mixed => $ftp->setWelcomeMessage(message: ''),
            'message=', 'null', null,
        ];
        yield 'empty welcome response' => [
            'get_welcome_message', 'GET', static fn (Ftp $ftp): mixed => $ftp->getWelcomeMessage(), '', '""', '',
        ];
        yield 'daemon disabled with optional support fields absent' => [
            'get_ftp_daemon_info', 'GET', static fn (Ftp $ftp): mixed => $ftp->getFtpDaemonInfo(),
            '', '{"enabled":0,"name":"","supports":{}}', ['enabled' => 0, 'name' => '', 'supports' => []],
        ];
        foreach (self::operations() as $name => [$function, $method, $call, $parameters]) {
            if (
                in_array($function, ['allows_anonymous_ftp', 'allows_anonymous_ftp_incoming',
                'get_ftp_daemon_info', 'get_port'], true)
            ) {
                yield $name . ' all optional response fields omitted' => [
                    $function, $method, $call, $parameters, '{}', [],
                ];
            }
        }
    }

    /** @param FtpCall $call */
    #[DataProvider('invalidPayloads')]
    public function testMalformedOrAbsentPayloadPreservesTheConfirmedOutcomeWithoutRetry(
        string $route,
        string $function,
        Closure $call,
        string $validPayload,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $validPayload)));
        $call($account->ftp());
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        try {
            $call($account->ftp());
            self::fail('A malformed or absent FTP payload must fail validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, FtpCall, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $malformed = [
            'allows_anonymous_ftp' => ['{"allows":2}', '{"allows":"1"}', '{"allows":false}'],
            'allows_anonymous_ftp_incoming' => ['{"allows":null}', '{"allows":-1}'],
            'get_ftp_daemon_info' => ['{"enabled":2}', '{"name":null}', '{"supports":null}',
                '{"supports":{"quota":"1"}}', '{"supports":{"login_without_domain":true}}'],
            'get_port' => ['{"port":"21"}', '{"port":null}', '{"port":21.5}'],
            'get_quota' => ['500', '{}', '[]'],
            'get_welcome_message' => ['42', '{}', '[]'],
            'server_name' => ['42', '{}', '[]'],
            'list_ftp' => ['{}', '[null]', '[{"homedir":null}]', '[{"user":42}]'],
            'list_ftp_with_disk' => ['{}', '[null]', '[{"_diskquota":0}]', '[{"_diskused":2.34}]',
                '[{"diskquota":500}]', '[{"diskused":2.34}]', '[{"deleteable":2}]',
                '[{"diskusedpercent":"0"}]', '[{"diskusedpercent20":20.5}]', '[{"htmldir":false}]'],
            'list_sessions' => ['{}', '[null]', '[{"pid":"123"}]', '[{"file":null}]', '[{"host":42}]'],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [$function, , $call, , $validPayload]) {
                $payloads = [null, 'false'];
                if ($validPayload === 'null') {
                    $payloads = [...$payloads, '0', '"unexpected"', '[{}]'];
                } else {
                    $payloads[] = 'null';
                }
                foreach ([...$payloads, ...($malformed[$function] ?? [])] as $index => $payload) {
                    yield $route . ' ' . $name . ' case ' . $index => [
                        $route, $function, $call, $validPayload, $payload,
                    ];
                }
            }
        }
    }

    /** @param FtpCall $call */
    #[DataProvider('failureCalls')]
    public function testApiAndHttpFailuresReplaceMetadataAndAreNotRetried(
        string $route,
        string $function,
        Closure $call,
        string $validPayload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $validPayload)));
        $call($account->ftp());
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, null, 0)));
        try {
            $call($account->ftp());
            self::fail('A failed FTP outcome, including an absent ftp_exists account, must throw.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Synthetic FTP failure'], $error->errors());
        }
        $http->addResponse(new Response(503, [], 'Synthetic unavailable service'));
        try {
            $call($account->ftp());
            self::fail('An HTTP failure must clear old metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertCount(3, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, FtpCall, string}> */
    public static function failureCalls(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [$function, , $call, , $payload]) {
                yield $route . ' ' . $name => [$route, $function, $call, $payload];
            }
        }
    }

    public function testSelectorsAreLazyAndAccountMetadataAndTargetsStayIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        self::assertSame($first->ftp(), $first->ftp());
        self::assertNotSame($first->ftp(), $second->ftp());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'list_ftp', '[]')));
        $first->ftp()->listFtp();
        $metadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        $first->ftp();
        $http->addResponse(new Response(200, [], self::envelope('bridge', 'get_port', '{"port":21}')));
        $second->ftp()->getPort();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNotSame($metadata, $second->responseMeta());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=other'
                . '&cpanel.module=Ftp&cpanel.function=get_port',
            (string) $request->getUri(),
        );
        self::assertCount(2, $http->getRequests());
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Ftp&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Ftp/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, string $function, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic FTP failure"]')
            . ',"warnings":["Synthetic FTP notice"]}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Ftp","func":"' . $function . '","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
