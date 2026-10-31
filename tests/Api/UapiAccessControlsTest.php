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

/** Independent literal contracts for KnownHosts, ExternalAuthentication, SSH and LastLogin. */
final class UapiAccessControlsTest extends TestCase
{
    /** @param array<array-key, mixed>|int|string|null $expected */
    #[DataProvider('validPayloads')]
    public function testExactRequestAndPayload(
        string $route,
        string $module,
        string $function,
        string $payload,
        array|int|string|null $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $module, $function));
        self::assertRequest($http, $route, $module, $function);
        self::assertCount(1, $http->getRequests());
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
        self::assertArrayNotHasKey('data', $meta->uapi ?? []);
    }

    #[DataProvider('routes')]
    public function testObservedPortStringNormalizesAndLoginStringPreservesSpelling(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"port":"8954"}')));
        self::assertSame(['port' => 8954], $account->ssh()->getPort());
        $http->addResponse(new Response(200, [], self::envelope($route, '"2001:0DB8::0001"')));
        self::assertSame('2001:0DB8::0001', $account->lastLogin()->getLastOrCurrentLoggedInIp());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, string, array<array-key, mixed>|int|string|null}> */
    public static function validPayloads(): iterable
    {
        foreach (self::operationRoutes() as $label => [$route, $module, $function]) {
            foreach (self::validCases($module, $function) as $index => [$payload, $expected]) {
                yield "$label $index" => [$route, $module, $function, $payload, $expected];
            }
        }
    }

    /** @return list<array{string, array<array-key, mixed>|int|string|null}> */
    private static function validCases(string $module, string $function): array
    {
        if ($module === 'KnownHosts') {
            if ($function === 'delete') {
                return self::opaqueCases();
            }
            $host = ['host' => 'host.example.test', 'key' => 'ssh-ed25519 public+key=',
                'line' => 'host.example.test ssh-ed25519 public+key=',
                'meta' => ['algorithm' => 'ssh-ed25519', 'body' => 'public+key=', 'md5' => 'abc',
                    'md5-printable' => 'MD5:ab:cd', 'sha256' => 'def', 'sha256-printable' => 'SHA256:def']];
            $full = ['host' => [$host]];
            $cases = [
                [json_encode($full, JSON_THROW_ON_ERROR), $full], ['{}', []],
                ['{"host":[]}', ['host' => []]],
                ['{"future":1,"host":[{"future":false,"meta":{"future":null,"algorithm":"ssh-ed25519"}}]}',
                    ['host' => [['meta' => ['algorithm' => 'ssh-ed25519']]]]],
                ['{"host":[{}, {"meta":{}}]}', ['host' => [[], ['meta' => []]]]],
                ['{"host":[{"host":"","key":"","line":"","meta":{"algorithm":"","body":"",'
                    . '"md5":"","md5-printable":"","sha256":"","sha256-printable":""}}]}',
                    ['host' => [['host' => '', 'key' => '', 'line' => '', 'meta' => ['algorithm' => '', 'body' => '',
                        'md5' => '', 'md5-printable' => '', 'sha256' => '', 'sha256-printable' => '']]]]],
            ];
            if ($function === 'verify') {
                $cases[] = ['{"errors":["Not trusted"],"failure_type":"new","host":[],"status":0}',
                    ['errors' => ['Not trusted'], 'failure_type' => 'new', 'host' => [], 'status' => 0]];
                $cases[] = ['{"errors":[],"failure_type":"changed","status":1}',
                    ['errors' => [], 'failure_type' => 'changed', 'status' => 1]];
            }
            return $cases;
        }
        if ($function === 'remove_authn_link') {
            return self::opaqueCases();
        }
        if ($function === 'has_external_auth_modules_configured') {
            return [['0', 0], ['1', 1]];
        }
        if ($function === 'configured_modules') {
            $row = ['color' => '00aef0', 'display_name' => 'Provider',
                'documentation_url' => 'https://docs.example.test/?a=1&b=2', 'icon' => 'public+icon/bytes=',
                'icon_type' => 'image/svg+xml', 'label' => 'Log in',
                'link' => 'https://identity.example.test/login', 'provider_name' => 'provider',
                'textcolor' => 'FFFFFF'];
            return [
            ['[]', []], ['[{}]', [[]]], [json_encode([$row], JSON_THROW_ON_ERROR), [$row]],
                ['[{"link":"","icon":"","future":1}]', [['icon' => '', 'link' => '']]]];
        }
        if ($function === 'get_authn_links') {
            return [
            ['[]', []], ['[{}]', [[]]],
                ['[{"link_time":0,"preferred_username":"","provider_id":"provider",'
                    . '"provider_protocol":"openid_connect","subject_unique_identifier":123456789}]',
                    [['link_time' => 0, 'preferred_username' => '', 'provider_id' => 'provider',
                        'provider_protocol' => 'openid_connect', 'subject_unique_identifier' => 123456789]]]];
        }
        if ($function === 'get_port') {
            return [
                ['{}', []], ['{"port":22}', ['port' => 22]], ['{"port":65535}', ['port' => 65535]],
                ['{"port":"22"}', ['port' => 22]], ['{"port":"0"}', ['port' => 0]],
                ['{"port":"-1"}', ['port' => -1]],
                ['{"port":"9223372036854775807"}', ['port' => PHP_INT_MAX]],
                ['{"port":"-9223372036854775808"}', ['port' => PHP_INT_MIN]],
            ];
        }
        if ($function === 'get_shell') {
            return [
            ['{}', []], ['{"has_terminal_access":false,"shell":""}',
                ['has_terminal_access' => false, 'shell' => '']],
                ['{"has_terminal_access":true,"shell":"/bin/bash"}',
                    ['has_terminal_access' => true, 'shell' => '/bin/bash']]];
        }
        return [
            ['[]', []], ['["192.0.2.1","192.0.2.1",""]', ['192.0.2.1', '192.0.2.1', '']],
            ['"192.0.2.1"', '192.0.2.1'], ['""', ''], ['" 192.0.2.1 "', ' 192.0.2.1 '],
            ['"2001:0DB8::0001"', '2001:0DB8::0001'], ['"001.002.003.004"', '001.002.003.004'],
            ['"0"', '0'], ['"1.5"', '1.5'], ['"future value"', 'future value'],
        ];
    }

    /** @return list<array{string, array<array-key, mixed>|null}> */
    private static function opaqueCases(): array
    {
        return [
        ['null', null], ['{}', []], ['[]', []],
            ['{"opaque":{"empty":[],"none":null,"zero":0,"flag":false}}',
                ['opaque' => ['empty' => [], 'none' => null, 'zero' => 0, 'flag' => false]]]];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadRetainsConfirmedOutcomeAndFreshMetadata(
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
            self::fail('A malformed or absent access-control payload must fail validation.');
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
        self::assertRequest($http, $route, $module, $function);
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operationRoutes() as $label => [$route, $module, $function]) {
            $opaque = $function === 'delete' || $function === 'remove_authn_link';
            $cases = [null, 'false', 'true', '1.5'];
            if ($module !== 'LastLogin') {
                $cases[] = '"text"';
            }
            if (!$opaque) {
                $cases[] = 'null';
            }
            if ($function !== 'has_external_auth_modules_configured') {
                $cases[] = '1';
            }
            if ($opaque) {
                $cases[] = '[{}]';
            } elseif ($module === 'KnownHosts') {
                array_push($cases, '[{}]', '{"host":null}', '{"host":{}}', '{"host":[null]}');
                foreach (['host', 'key', 'line'] as $field) {
                    foreach (['null', '0', 'false', '[]'] as $bad) {
                        $cases[] = '{"host":[{"' . $field . '":' . $bad . '}]}';
                    }
                }
                foreach (['null', '0', 'false', '[{}]'] as $bad) {
                    $cases[] = '{"host":[{"meta":' . $bad . '}]}';
                }
                foreach (['algorithm', 'body', 'md5', 'md5-printable', 'sha256', 'sha256-printable'] as $field) {
                    $cases[] = '{"host":[{"meta":{"' . $field . '":null}}]}';
                    $cases[] = '{"host":[{"meta":{"' . $field . '":1}}]}';
                }
                if ($function === 'verify') {
                    array_push(
                        $cases,
                        '{"errors":null}',
                        '{"errors":{}}',
                        '{"errors":[0]}',
                        '{"failure_type":0}',
                        '{"failure_type":null}',
                        '{"status":"0"}',
                        '{"status":false}',
                        '{"status":2}',
                    );
                }
            } elseif ($function === 'has_external_auth_modules_configured') {
                array_push($cases, '"0"', '"1"', '0.0', '2', '-1', '{}', '[]');
            } elseif ($function === 'configured_modules' || $function === 'get_authn_links') {
                array_push($cases, '{}', '[null]', '[0]', '[false]');
                $strings = $function === 'configured_modules'
                    ? ['color', 'display_name', 'documentation_url', 'icon', 'icon_type',
                        'label', 'link', 'provider_name', 'textcolor']
                    : ['preferred_username', 'provider_id', 'provider_protocol'];
                foreach ($strings as $field) {
                    $cases[] = '[{"' . $field . '":null}]';
                    $cases[] = '[{"' . $field . '":1}]';
                }
                if ($function === 'get_authn_links') {
                    foreach (['link_time', 'subject_unique_identifier'] as $field) {
                        foreach (['null', '"123"', '1.5', 'false', '{}'] as $bad) {
                            $cases[] = '[{"' . $field . '":' . $bad . '}]';
                        }
                    }
                }
            } elseif ($function === 'get_port') {
                array_push($cases, '[{}]', '{"port":null}', '{"port":22.5}', '{"port":true}');
                foreach (
                    ['', ' ', ' 22', '22 ', '+22', '022', '-0', '22.0', '22.5', '2e1',
                    '9223372036854775808', '-9223372036854775809'] as $bad
                ) {
                    $cases[] = json_encode(['port' => $bad], JSON_THROW_ON_ERROR);
                }
            } elseif ($function === 'get_shell') {
                array_push(
                    $cases,
                    '[{}]',
                    '{"has_terminal_access":0}',
                    '{"has_terminal_access":"1"}',
                    '{"has_terminal_access":null}',
                    '{"shell":null}',
                    '{"shell":1}',
                    '{"shell":[]}',
                );
            } else {
                array_push(
                    $cases,
                    '{}',
                    '[0]',
                    '[null]',
                    '[false]',
                    '[{}]',
                    '["192.0.2.1",null]',
                    '{"ip":"192.0.2.1"}',
                );
            }
            foreach ($cases as $index => $payload) {
                yield "$label $index" => [$route, $module, $function, $payload];
            }
        }
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailurePrecedesPayloadValidation(string $route, string $module, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $module, $function);
            self::fail('UAPI status zero must fail even when the payload has its own status flag.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('bridgeOperations')]
    public function testOuterWhmFailureIsNeverHiddenByUapiSuccess(string $module, string $function): void
    {
        [$client, $http] = self::client('bridge');
        $payload = self::validCases($module, $function)[0][0];
        $body = str_replace(
            '"result":1',
            '"result":0,"reason":"Synthetic outer failure"',
            self::envelope('bridge', $payload),
        );
        $http->addResponse(new Response(200, [], $body));
        try {
            self::invoke($client->uapi('npr'), $module, $function);
            self::fail('The outer WHM failure must take precedence over successful UAPI data.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function bridgeOperations(): iterable
    {
        foreach (self::operationRoutes() as [$route, $module, $function]) {
            if ($route === 'bridge') {
                yield "$module/$function" => [$module, $function];
            }
        }
    }

    #[DataProvider('routes')]
    public function testKnownHostPortOmissionAndZeroRemainDistinct(string $route): void
    {
        [$client, $http] = self::client($route);
        $hosts = $client->uapi('npr')->knownHosts();
        foreach (['create', 'delete', 'update', 'verify'] as $function) {
            foreach ([null, 0, 22] as $port) {
                $payload = $function === 'delete' ? 'null' : '{}';
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                match ($function) {
                    'create' => $hosts->create(hostName: '192.0.2.1', port: $port),
                    'delete' => $hosts->delete(hostName: '192.0.2.1', port: $port),
                    'update' => $hosts->update(hostName: '192.0.2.1', port: $port),
                    default => $hosts->verify(hostName: '192.0.2.1', port: $port),
                };
                $request = $http->getLastRequest();
                self::assertInstanceOf(RequestInterface::class, $request);
                $parameters = 'host_name=192.0.2.1' . ($port === null ? '' : '&port=' . $port);
                if ($function === 'verify') {
                    self::assertStringEndsWith($parameters, (string) $request->getUri());
                    self::assertSame('', (string) $request->getBody());
                } else {
                    self::assertSame($parameters, (string) $request->getBody());
                    self::assertStringNotContainsString('host_name=', (string) $request->getUri());
                }
            }
        }
        self::assertCount(12, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testOpaqueSubjectIdentifierIsSentAsStringWithoutConversion(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
        $client->uapi('npr')->externalAuthentication()->removeAuthnLink(
            provider: '',
            subjectUniqueIdentifier: '000123456789012345678901+&=%/雪',
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'provider=&subject_unique_identifier=000123456789012345678901%2B%26%3D%25%2F%E9%9B%AA',
            (string) $request->getBody(),
        );
        self::assertSame('POST', $request->getMethod());
        self::assertStringNotContainsString('subject_unique_identifier=', (string) $request->getUri());
    }

    public function testSelectorsCachePerAccountAndPreserveMetadata(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('npr');
        $other = $client->uapi('other');
        foreach (['knownHosts', 'externalAuthentication', 'ssh', 'lastLogin'] as $accessor) {
            self::assertSame($first->$accessor(), $first->$accessor());
            self::assertNotSame($first->$accessor(), $other->$accessor());
        }
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"port":22}')));
        $first->ssh()->getPort();
        $metadata = $first->responseMeta();
        $first->knownHosts();
        $first->externalAuthentication();
        $first->lastLogin();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (
                [
                ['KnownHosts', 'create'], ['KnownHosts', 'delete'], ['KnownHosts', 'update'],
                ['KnownHosts', 'verify'], ['ExternalAuthentication', 'configured_modules'],
                ['ExternalAuthentication', 'get_authn_links'],
                ['ExternalAuthentication', 'has_external_auth_modules_configured'],
                ['ExternalAuthentication', 'remove_authn_link'], ['SSH', 'get_port'], ['SSH', 'get_shell'],
                ['LastLogin', 'get_last_or_current_logged_in_ip']] as [$module, $function]
            ) {
                yield "$route $module/$function" => [$route, $module, $function];
            }
        }
    }

    private static function invoke(UapiClient $account, string $module, string $function): mixed
    {
        return match ($module . '/' . $function) {
            'KnownHosts/create' => $account->knownHosts()->create(hostName: 'host.example.test', port: 2222),
            'KnownHosts/delete' => $account->knownHosts()->delete(hostName: 'host.example.test', port: 2222),
            'KnownHosts/update' => $account->knownHosts()->update(hostName: 'host.example.test', port: 2222),
            'KnownHosts/verify' => $account->knownHosts()->verify(hostName: 'host.example.test', port: 2222),
            'ExternalAuthentication/configured_modules' => $account->externalAuthentication()->configuredModules(),
            'ExternalAuthentication/get_authn_links' => $account->externalAuthentication()->getAuthnLinks(),
            'ExternalAuthentication/has_external_auth_modules_configured' =>
                $account->externalAuthentication()->hasExternalAuthModulesConfigured(),
            'ExternalAuthentication/remove_authn_link' => $account->externalAuthentication()->removeAuthnLink(
                provider: 'example+provider',
                subjectUniqueIdentifier: '000123456789012345678901',
            ),
            'SSH/get_port' => $account->ssh()->getPort(),
            'SSH/get_shell' => $account->ssh()->getShell(),
            default => $account->lastLogin()->getLastOrCurrentLoggedInIp(),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $module, string $function): void
    {
        $parameters = match ($module) {
            'KnownHosts' => 'host_name=host.example.test&port=2222',
            'ExternalAuthentication' => $function === 'remove_authn_link'
                ? 'provider=example%2Bprovider&subject_unique_identifier=000123456789012345678901' : '',
            default => '',
        };
        $post = in_array($function, ['create', 'delete', 'update', 'remove_authn_link'], true);
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
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
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
