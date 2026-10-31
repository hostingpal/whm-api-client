<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;

/** Independent literal contracts from pinned BlockIP, ModSecurity and ClamScanner operations. */
final class UapiWebSecurityTest extends TestCase
{
    /** @param array<array-key, mixed>|null $expected */
    #[DataProvider('successfulPayloads')]
    public function testExactRequestsAndPayloads(
        string $route,
        string $module,
        string $function,
        string $payload,
        ?array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $module, $function));
        self::assertRequest($http, $route, $module, $function);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, string, array<array-key, mixed>|null}> */
    public static function successfulPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                foreach (self::validCases($module, $function) as $index => [$payload, $expected]) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload, $expected];
                }
            }
        }
    }

    /** @return list<array{string, array<array-key, mixed>|null}> */
    private static function validCases(string $module, string $function): array
    {
        if ($module === 'BlockIP') {
            return [
            ['null', null], ['[]', []], ['["192.0.2.0","2001:db8::1",""]',
                ['192.0.2.0', '2001:db8::1', '']]];
        }
        if ($function === 'start_scan') {
            return [
            ['null', null], ['{}', []], ['{"opaque":{"flag":false,"none":null,"rows":[]}}',
                ['opaque' => ['flag' => false, 'none' => null, 'rows' => []]]]];
        }
        if ($function === 'get_scan_paths') {
            return [
            ['{}', []], ['{"id":"home","message":"Scan Entire Home Directory","future":true}',
                ['id' => 'home', 'message' => 'Scan Entire Home Directory']],
                ['{"id":"mail","message":""}', ['id' => 'mail', 'message' => '']],
                ['{"id":"public_ftp"}', ['id' => 'public_ftp']],
                ['{"id":"public_html"}', ['id' => 'public_html']],
                // The capture establishes a list of objects; these field contracts come from the pinned record.
                ['[]', []],
                ['[{"id":"home","message":"Scan Entire Home Directory"},'
                    . '{"id":"mail","message":""},{"id":"public_html"},'
                    . '{"id":"public_ftp"},{}]',
                    [
            ['id' => 'home', 'message' => 'Scan Entire Home Directory'],
                        ['id' => 'mail', 'message' => ''], ['id' => 'public_html'], ['id' => 'public_ftp'], []]],
                ['[{"message":"Description only","future":true}]', [['message' => 'Description only']]]];
        }
        if ($function === 'get_scan_status') {
            return [
            ['{}', []], ['{"current_file":"/home/npr/a +&=%/雪","infected_files":["/home/npr/virus"],'
                . '"scan_complete":1,"scanned_file_count":135,"scanned_file_size":6022,"time_started":"1608593571",'
                . '"total_file_count":135,"total_file_size_MiB":3640222,"future":true}',
                ['current_file' => '/home/npr/a +&=%/雪', 'infected_files' => ['/home/npr/virus'],
                    'scan_complete' => 1, 'scanned_file_count' => 135, 'scanned_file_size' => 6022,
                    'time_started' => '1608593571', 'total_file_count' => 135, 'total_file_size_MiB' => 3640222]],
                ['{"infected_files":[],"scan_complete":0,"scanned_file_count":0,"scanned_file_size":0,'
                    . '"time_started":"","total_file_count":0,"total_file_size_MiB":0}',
                    ['infected_files' => [], 'scan_complete' => 0, 'scanned_file_count' => 0,
                        'scanned_file_size' => 0, 'time_started' => '', 'total_file_count' => 0,
                        'total_file_size_MiB' => 0]], ['null', null]];
        }
        if ($function === 'check_disinfection_status') {
            return [
            ['{}', []], ['{"details":[],"status":"queued","log":""}',
                ['details' => [], 'log' => '', 'status' => 'queued']],
                ['{"details":[{"type":"step","state":"deleted","file":"/home/npr/virus","id":2,'
                    . '"message":"removed","future":true},{"type":"issue","state":"warning","id":2.5,'
                    . '"message":"warning"},{"type":"done"}],"log":"/home/npr/.clamavconnector.disinfection.log",'
                    . '"status":"done"}',
                    ['details' => [
            ['file' => '/home/npr/virus', 'id' => 2, 'message' => 'removed',
                        'state' => 'deleted', 'type' => 'step'],
                        ['id' => 2.5, 'message' => 'warning', 'state' => 'warning', 'type' => 'issue'],
                        ['type' => 'done']],
                    'log' => '/home/npr/.clamavconnector.disinfection.log', 'status' => 'done']],
                ['{"details":[{"type":"done","state":"success","id":0,"message":""}],"status":"running"}',
                    ['details' => [['id' => 0, 'message' => '', 'state' => 'success', 'type' => 'done']],
                        'status' => 'running']]];
        }
        if ($function === 'list_infected_files') {
            return [
            ['[]', []], ['[{}, {"file":"/home/npr/virus","virus_type":"Eicar-Signature","future":true}]',
                [[], ['file' => '/home/npr/virus', 'virus_type' => 'Eicar-Signature']]]];
        }
        if ($function === 'has_modsecurity_installed') {
            return [
            ['{}', []], ['{"installed":0}', ['installed' => 0]],
                ['{"installed":1,"future":true}', ['installed' => 1]]];
        }
        $cases = [
        ['[]', []], ['[{}, {"domain":"example.com","dependencies":["alias.example.com"],'
            . '"enabled":0,"searchhint":"alias.example.com,other.example.com","type":"main"},'
            . '{"domain":"sub.example.com","dependencies":[],"enabled":1,"searchhint":"","type":"sub"}]',
            [
        [], ['dependencies' => ['alias.example.com'], 'domain' => 'example.com', 'enabled' => 0,
                'searchhint' => 'alias.example.com,other.example.com', 'type' => 'main'],
                ['dependencies' => [], 'domain' => 'sub.example.com', 'enabled' => 1,
                    'searchhint' => '', 'type' => 'sub']]]];
        if ($function !== 'list_domains') {
            $cases[] = ['[{"exception":"Update failed","domain":"example.com","enabled":0}]',
                [['domain' => 'example.com', 'enabled' => 0, 'exception' => 'Update failed']]];
        }
        if ($function === 'enable_domains' || $function === 'disable_domains') {
            array_push(
                $cases,
                ['{"no_domains_provided":1}', ['no_domains_provided' => 1]],
                ['{"invalid_domains":["invalid.example"],"invalid_domains_provided":1}',
                ['invalid_domains' => ['invalid.example'], 'invalid_domains_provided' => 1]],
                ['{}', []],
            );
        }
        return $cases;
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidAndMissingPayloadsRetainConfirmedOutcome(
        string $route,
        string $module,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validCases($module, $function)[0][0])));
        self::invoke($account, $module, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $module, $function);
            self::fail('Invalid security payload must fail validation.');
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

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                $cases = [null, 'false', '1', '1.5', '"value"'];
                if ($module !== 'BlockIP' && $function !== 'start_scan' && $function !== 'get_scan_status') {
                    $cases[] = 'null';
                }
                if ($module === 'BlockIP') {
                    array_push($cases, '{}', '[null]', '[1]', '[false]', '[{}]');
                } elseif ($function === 'start_scan') {
                    $cases[] = '[{}]';
                } elseif ($function === 'has_modsecurity_installed') {
                    foreach (['null', '"0"', '"1"', 'true', '2', '1.0'] as $bad) {
                        $cases[] = '{"installed":' . $bad . '}';
                    }
                } elseif ($function === 'get_scan_paths') {
                    array_push(
                        $cases,
                        '{"id":null}',
                        '{"id":1}',
                        '{"message":false}',
                        '[null]',
                        '[false]',
                        '[1]',
                        '[[{}]]',
                        '[{"id":null}]',
                        '[{"id":1}]',
                        '[{"id":false}]',
                        '[{"id":[]}]',
                        '[{"message":null}]',
                        '[{"message":1}]',
                        '[{"message":false}]',
                        '[{"message":[]}]',
                    );
                } elseif ($function === 'get_scan_status') {
                    array_push(
                        $cases,
                        '[{}]',
                        '{"current_file":null}',
                        '{"time_started":true}',
                        '{"infected_files":{}}',
                        '{"infected_files":[0]}',
                    );
                    $integerFields = ['scan_complete', 'scanned_file_count', 'scanned_file_size',
                        'total_file_count', 'total_file_size_MiB'];
                    foreach ($integerFields as $field) {
                        foreach (['null', 'true', '"0"', '1.5'] as $bad) {
                            $cases[] = '{"' . $field . '":' . $bad . '}';
                        }
                    }
                    array_push($cases, '{"scan_complete":2}', '{"scan_complete":-1}');
                } elseif ($function === 'check_disinfection_status') {
                    array_push(
                        $cases,
                        '[{}]',
                        '{"details":{}}',
                        '{"details":[null]}',
                        '{"details":[{}]}',
                        '{"details":[{"state":"success"}]}',
                        '{"details":[{"type":null}]}',
                        '{"details":[{"type":"step","file":false}]}',
                        '{"details":[{"type":"issue","id":"2"}]}',
                        '{"details":[{"type":"done","state":null}]}',
                        '{"details":[{"type":"done","message":false}]}',
                        '{"status":null}',
                        '{"log":0}',
                    );
                } elseif ($function === 'list_infected_files') {
                    array_push($cases, '{}', '[null]', '[{"file":null}]', '[{"virus_type":0}]');
                } else {
                    array_push(
                        $cases,
                        '[null]',
                        '[{"dependencies":{}}]',
                        '[{"dependencies":[1]}]',
                        '[{"domain":null}]',
                        '[{"searchhint":0}]',
                        '[{"type":false}]',
                    );
                    foreach (['null', 'true', '"0"', '2', '1.0'] as $bad) {
                        $cases[] = '[{"enabled":' . $bad . '}]';
                    }
                    if ($function === 'enable_domains' || $function === 'disable_domains') {
                        array_push(
                            $cases,
                            '{"no_domains_provided":"1"}',
                            '{"invalid_domains_provided":false}',
                            '{"invalid_domains":[0]}',
                        );
                    } else {
                        $cases[] = '{"domain":"example.com"}';
                    }
                    if ($function !== 'list_domains') {
                        $cases[] = '[{"exception":1}]';
                    }
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('allOperations')]
    public function testApiFailuresPrecedePayloadValidation(string $route, string $module, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, 'false', 0)));
        try {
            self::invoke($account, $module, $function);
            self::fail('An ordinary UAPI status zero must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function allOperations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                yield "$route $module/$function" => [$route, $module, $function];
            }
        }
    }

    public function testOptionalLastIdAndSelectorCaching(): void
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $other = $route === 'bridge' ? $client->uapi('other') : self::client($route)[0]->uapi('npr');
            self::assertSame($account->blockIp(), $account->blockIp());
            self::assertSame($account->modSecurity(), $account->modSecurity());
            self::assertSame($account->clamScanner(), $account->clamScanner());
            self::assertNotSame($account->blockIp(), $other->blockIp());
            self::assertNotSame($account->modSecurity(), $other->modSecurity());
            self::assertNotSame($account->clamScanner(), $other->clamScanner());
            self::assertFalse((new ReflectionClass($account->clamScanner()))->hasMethod('disinfectFiles'));
            self::assertSame([], $http->getRequests());
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->clamScanner()->checkDisinfectionStatus();
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertStringNotContainsString('last_id', (string) $request->getUri());
            $previous = $account->responseMeta();
            $account->blockIp();
            $account->modSecurity();
            self::assertSame($previous, $account->responseMeta());
            self::assertNull($other->responseMeta());
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->clamScanner()->checkDisinfectionStatus(lastId: 0);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertStringEndsWith('last_id=0', (string) $request->getUri());
        }
    }

    /** @return list<array{string, string}> */
    private static function operations(): array
    {
        return [
        ['BlockIP', 'add_ip'], ['BlockIP', 'remove_ip'], ['ModSecurity', 'disable_all_domains'],
            ['ModSecurity', 'disable_domains'], ['ModSecurity', 'enable_all_domains'],
            ['ModSecurity', 'enable_domains'],
            ['ModSecurity', 'has_modsecurity_installed'], ['ModSecurity', 'list_domains'],
            ['ClamScanner', 'check_disinfection_status'], ['ClamScanner', 'get_scan_paths'],
            ['ClamScanner', 'get_scan_status'], ['ClamScanner', 'list_infected_files'], ['ClamScanner', 'start_scan']];
    }

    private static function invoke(UapiClient $account, string $module, string $function): mixed
    {
        return match ($module . '/' . $function) {
            'BlockIP/add_ip' => $account->blockIp()->addIp(ip: '2001:db8::1-2001:db8::3'),
            'BlockIP/remove_ip' => $account->blockIp()->removeIp(ip: '192.168.0.1/16'),
            'ModSecurity/disable_all_domains' => $account->modSecurity()->disableAllDomains(),
            'ModSecurity/disable_domains' => $account->modSecurity()->disableDomains(
                domains: 'example.com,sub.example.com',
            ),
            'ModSecurity/enable_all_domains' => $account->modSecurity()->enableAllDomains(),
            'ModSecurity/enable_domains' => $account->modSecurity()->enableDomains(
                domains: 'example.com,sub.example.com',
            ),
            'ModSecurity/has_modsecurity_installed' => $account->modSecurity()->hasModsecurityInstalled(),
            'ModSecurity/list_domains' => $account->modSecurity()->listDomains(),
            'ClamScanner/check_disinfection_status' => $account->clamScanner()->checkDisinfectionStatus(lastId: 10),
            'ClamScanner/get_scan_paths' => $account->clamScanner()->getScanPaths(),
            'ClamScanner/get_scan_status' => $account->clamScanner()->getScanStatus(),
            'ClamScanner/list_infected_files' => $account->clamScanner()->listInfectedFiles(),
            default => $account->clamScanner()->startScan(scanType: 'public_html'),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $module, string $function): void
    {
        $parameters = match ($function) {
            'add_ip' => 'ip=2001%3Adb8%3A%3A1-2001%3Adb8%3A%3A3',
            'remove_ip' => 'ip=192.168.0.1%2F16',
            'disable_domains', 'enable_domains' => 'domains=example.com%2Csub.example.com',
            'check_disinfection_status' => 'last_id=10',
            'start_scan' => 'scan_type=public_html',
            default => '',
        };
        $post = in_array($function, ['add_ip', 'remove_ip', 'disable_all_domains', 'disable_domains',
            'enable_all_domains', 'enable_domains', 'start_scan'], true);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        if (!$post && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($post ? $parameters : '', (string) $request->getBody());
        if ($post) {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"messages":null,"warnings":null,"metadata":{}}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
