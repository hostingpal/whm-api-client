<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\SupportTickets;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use TypeError;

/**
 * Independent literal contracts from the pinned WHM Support Tickets source.
 *
 * No support service, URL, SSH connection, firewall or agreement is contacted or changed.
 *
 * @phpstan-type Call Closure(WhmClient): mixed
 * @phpstan-type Case array{string, string, Call, string, ?string}
 */
final class WhmSupportTicketsTest extends TestCase
{
    /** @param Call $call */
    #[DataProvider('requests')]
    public function testExactRequestsAndPopulatedSourcePayloads(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));

        self::assertSame($payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $call($whm));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
                . ($method === 'GET' && $parameters !== '' ? '&' . $parameters : ''),
            (string) $request->getUri(),
        );
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertSame($operation, $whm->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, Case> */
    public static function requests(): iterable
    {
        yield 'create stub token and integer ID' => ['ticket_create_stub_ticket', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->createStubTicket(), '',
            '{"secure_id":"private-token","ticket_id":7648492}'];
        yield 'full agreement and nullable dates' => ['ticket_get_support_agreement', 'GET',
            static fn (WhmClient $w): array => $w->supportTickets()->getSupportAgreement(), '',
            '{"accepted":0,"accepted_date":null,"accepted_date_human":null,"body":"<p>Agreement</p>",'
                . '"create_date":1564790400,"create_date_human":"8/3/19",'
                . '"download_url":"https://support.example.test/download","title":"Support Agreement",'
                . '"version":"2019-04.v01.TSUPPORT","view_url":"https://support.example.test/view"}'];
        yield 'support info and unknown architecture' => ['ticket_get_support_info', 'GET',
            static fn (WhmClient $w): array => $w->supportTickets()->getSupportInfo(), '',
            '{"arch":"x86_64","arch_supported":-1,"company_id":7,"company_name":"Example",'
                . '"distro":"linux","distro_supported":-1,"gets_direct_support":0,"has_company_info":1,'
                . '"has_compatibility_info":0,"hostname":"server.example.test","ip":"192.0.2.1",'
                . '"logo_url":"https://example.test/logo","pub_support_contact":"https://example.test/help",'
                . '"pub_tech_contact":"https://example.test/tech","tech_contact_email":"support@example.test"}'];
        yield 'grant secure token and explicit SSH user' => ['ticket_grant', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->grant('private +&=', 1, 7648492, 'user +&'),
            'secure_id=private%20%2B%26%3D&server_num=1&ticket_id=7648492&ssh_username=user%20%2B%26',
            '{"auth_status":"AUTHED","auth_time":1477338450,"chain_status":"ACTIVE",'
                . '"csf_wl_status":"ACTIVE","host_access_wl_status":"INACTIVE","hulk_wl_status":"ACTIVE",'
                . '"non_fatals":["audit_log"],"server_name":"fixture","server_num":1,'
                . '"ssh_username":"user +&","ticket_id":7648492,"ticket_status":"OPEN"}'];
        yield 'grant omitted SSH user does not assume root' => ['ticket_grant', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->grant('private', 1, 7648492),
            'secure_id=private&server_num=1&ticket_id=7648492', '{}'];
        yield 'list selectors and nested server rows' => ['ticket_list', 'GET',
            static fn (WhmClient $w): array => $w->supportTickets()->list(2, 7648492, 'user +&'),
            'server_num=2&ticket_id=7648492&ssh_username=user%20%2B%26',
            '{"auths":[{"servers":[{"auth_status":"AUTHED","auth_time":1400603208,'
                . '"server_name":"My Server","server_num":2,"ssh":"192.0.2.1:22",'
                . '"ssh_username":"user +&","whm_ip":"192.0.2.1"}],"ticket_id":7648492,'
                . '"ticket_status":"OPEN","ticket_subject":"Private support subject"}],"non_fatals":["audit_log"]}'];
        yield 'list omitted SSH user and empty collections' => ['ticket_list', 'GET',
            static fn (WhmClient $w): array => $w->supportTickets()->list(2, 7648492),
            'server_num=2&ticket_id=7648492', '{"auths":[],"non_fatals":[]}'];
        yield 'list nested omission remains omitted' => ['ticket_list', 'GET',
            static fn (WhmClient $w): array => $w->supportTickets()->list(2, 7648492, ''),
            'server_num=2&ticket_id=7648492&ssh_username=', '{"auths":[{}, {"servers":[{}]}]}'];
        yield 'remove closed exposes all firewall results' => ['ticket_remove_closed', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->removeClosed(), '',
            '{"chain_status":"INACTIVE","csf_wl_status":"ERR_UNSETUP","host_access_wl_status":"ACTIVE",'
                . '"hulk_wl_status":"ERR_UNKNOWN","non_fatals":["ticket_system_log_entry"],"revoked_keys":0}'];
        yield 'revoke requires explicit SSH user' => ['ticket_revoke', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->revoke(2, 'user +&', 7648492),
            'server_num=2&ssh_username=user%20%2B%26&ticket_id=7648492',
            '{"chain_status":"INACTIVE","csf_wl_status":"INACTIVE","host_access_wl_status":"INACTIVE",'
                . '"hulk_wl_status":"INACTIVE","non_fatals":[],"revoked_keys":1,"server_num":2,'
                . '"ssh_username":"user +&","ticket_id":7648492}'];
        yield 'SSH test returns payload result separately' => ['ticket_ssh_test', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->sshTest(2, 7648492),
            'server_num=2&ticket_id=7648492', '{"non_fatals":["audit_log"],"result":"SUCCESS"}'];
        yield 'SSH start opaque object fields retained' => ['ticket_ssh_test_start', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->sshTestStart(1, 7648492),
            'server_num=1&ticket_id=7648492', '{"opaque":{"command":"never execute","token":"private"}}'];
        yield 'agreement acceptance void' => ['ticket_update_service_agreement_approval', 'POST',
            static function (WhmClient $w): void {
                $w->supportTickets()->updateServiceAgreementApproval('2020-04.v01.TSUPPORT');
            }, 'version=2020-04.v01.TSUPPORT', null];
        yield 'OAuth exchange void and exact redirect bytes' => ['ticket_validate_oauth2_code', 'POST',
            static function (WhmClient $w): void {
                $w->supportTickets()->validateOauth2Code('private +&=', 'https://example.test/cb?a=b&state=private');
            }, 'code=private%20%2B%26%3D&redirect_uri=https%3A%2F%2Fexample.test%2Fcb%3Fa%3Db%26state%3Dprivate', null];
        yield 'firewall check' => ['ticket_whitelist_check', 'GET',
            static fn (WhmClient $w): array => $w->supportTickets()->whitelistCheck(), '',
            '{"chain_status":"ACTIVE","problem":"NEED_UNSETUP"}'];
        yield 'firewall setup' => ['ticket_whitelist_setup', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->whitelistSetup(), '',
            '{"chain_status":"ERR_SETUP"}'];
        yield 'firewall unsetup' => ['ticket_whitelist_unsetup', 'POST',
            static fn (WhmClient $w): array => $w->supportTickets()->whitelistUnsetup(), '',
            '{"chain_status":"ERR_UNSETUP"}'];
    }

    /** @param Call $call */
    #[DataProvider('requests')]
    public function testApiFailureIsNotRetriedAndHasCurrentMetadata(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('ticket_whitelist_check', '{}')));
        $whm->supportTickets()->whitelistCheck();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"' . $operation
            . '","result":0,"reason":"support service rejected request","version":1}}'));
        try {
            $call($whm);
            self::fail('Expected API failure.');
        } catch (ApiErrorException $exception) {
            self::assertSame(0, $whm->responseMeta()?->whmResult());
            self::assertSame($operation, $whm->responseMeta()?->whm['command'] ?? null);
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertCount(2, $http->getRequests());
        }
    }

    /** @param Call $call */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadFailsAfterSuccessWithoutRetry(
        string $operation,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $call($client->whm());
            self::fail('Expected payload mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, Call, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach (self::requests() as $name => [$operation, $method, $call, $parameters, $payload]) {
            if ($payload === null) {
                continue;
            }
            foreach ([null, 'null', '[1]', 'false', '1', '"object"'] as $bad) {
                yield $name . ' root ' . ($bad ?? 'missing') => [$operation, $call, $bad];
            }
        }
        $stub = static fn (WhmClient $w): array => $w->supportTickets()->createStubTicket();
        yield 'stub token string' => ['ticket_create_stub_ticket', $stub, '{"secure_id":42}'];
        yield 'stub ID strict integer' => ['ticket_create_stub_ticket', $stub, '{"ticket_id":"7648492"}'];
        $agreement = static fn (WhmClient $w): array => $w->supportTickets()->getSupportAgreement();
        foreach (['"0"', 'true', '2', 'null'] as $flag) {
            yield 'agreement strict flag ' . $flag => ['ticket_get_support_agreement', $agreement,
                '{"accepted":' . $flag . '}'];
        }
        yield 'agreement nullable date still integer' => ['ticket_get_support_agreement', $agreement,
            '{"accepted_date":"1566000000"}'];
        yield 'agreement HTML still string' => ['ticket_get_support_agreement', $agreement, '{"body":{}}'];
        $info = static fn (WhmClient $w): array => $w->supportTickets()->getSupportInfo();
        foreach (['gets_direct_support', 'has_company_info', 'has_compatibility_info'] as $field) {
            foreach (['"1"', '2', 'true', 'null'] as $flag) {
                yield $field . ' strict flag ' . $flag => ['ticket_get_support_info', $info,
                    '{"' . $field . '":' . $flag . '}'];
            }
        }
        yield 'architecture support integer' => ['ticket_get_support_info', $info, '{"arch_supported":"-1"}'];
        yield 'distro support integer' => ['ticket_get_support_info', $info, '{"distro_supported":false}'];
        yield 'company ID integer' => ['ticket_get_support_info', $info, '{"company_id":1.5}'];
        yield 'support URL string' => ['ticket_get_support_info', $info, '{"pub_support_contact":null}'];
        $list = static fn (WhmClient $w): array => $w->supportTickets()->list(2, 7648492);
        foreach (
            [
            '{"auths":{}}', '{"auths":[null]}', '{"auths":[{"ticket_id":"7648492"}]}',
            '{"auths":[{"servers":{}}]}', '{"auths":[{"servers":[false]}]}',
            '{"auths":[{"servers":[{"server_num":"2"}]}]}',
            '{"auths":[{"servers":[{"auth_time":"1400603208"}]}]}',
            '{"auths":[{"servers":[{"ssh":22}]}]}', '{"non_fatals":[{}]}',
            ] as $index => $bad
        ) {
            yield 'nested list type ' . $index => ['ticket_list', $list, $bad];
        }
        yield 'grant nonfatal list' => ['ticket_grant',
            static fn (WhmClient $w): array => $w->supportTickets()->grant('private', 1, 7648492),
            '{"non_fatals":{}}'];
        yield 'remove integer revoked count' => ['ticket_remove_closed',
            static fn (WhmClient $w): array => $w->supportTickets()->removeClosed(), '{"revoked_keys":"1"}'];
        yield 'revoke integer server' => ['ticket_revoke',
            static fn (WhmClient $w): array => $w->supportTickets()->revoke(1, 'user', 7648492), '{"server_num":null}'];
        yield 'SSH test result string' => ['ticket_ssh_test',
            static fn (WhmClient $w): array => $w->supportTickets()->sshTest(1, 7648492), '{"result":1}'];
        yield 'firewall status string' => ['ticket_whitelist_check',
            static fn (WhmClient $w): array => $w->supportTickets()->whitelistCheck(), '{"chain_status":true}'];
        yield 'setup status string' => ['ticket_whitelist_setup',
            static fn (WhmClient $w): array => $w->supportTickets()->whitelistSetup(), '{"chain_status":null}'];
        yield 'unsetup status string' => ['ticket_whitelist_unsetup',
            static fn (WhmClient $w): array => $w->supportTickets()->whitelistUnsetup(), '{"chain_status":0}'];
    }

    /** @param Call $call */
    #[DataProvider('requests')]
    public function testOptionalObjectsAndSourceVoidRemainDistinct(
        string $operation,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        foreach (['{}', '[]'] as $empty) {
            $http->addResponse(new Response(200, [], self::envelope($operation, $empty)));
            self::assertSame($payload === null ? null : [], $call($client->whm()));
        }
        if ($payload === null) {
            $http->addResponse(new Response(200, [], self::envelope($operation, 'null')));
            self::assertNull($call($client->whm()));
        }
    }

    public function testOptionalFieldsAndNullableDatesStayDistinct(): void
    {
        [$client, $http] = self::client();
        foreach (['{}', '{"accepted":1,"accepted_date":1566000000,"accepted_date_human":"8/17/19"}'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope('ticket_get_support_agreement', $payload)));
            self::assertSame(
                json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $client->whm()->supportTickets()->getSupportAgreement(),
            );
        }
        $http->addResponse(new Response(200, [], self::envelope('ticket_ssh_test_start', '{}')));
        self::assertSame([], $client->whm()->supportTickets()->sshTestStart(1, 7648492));
    }

    public function testSourceEnumsDoNotAddUnreviewedClosedStringContracts(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'ticket_whitelist_check',
            '{"chain_status":"FUTURE_STATUS","problem":"FUTURE_PROBLEM"}',
        )));
        self::assertSame(
            ['chain_status' => 'FUTURE_STATUS', 'problem' => 'FUTURE_PROBLEM'],
            $client->whm()->supportTickets()->whitelistCheck(),
        );
        $http->addResponse(new Response(200, [], self::envelope(
            'ticket_get_support_info',
            '{"arch_supported":42,"distro_supported":42}',
        )));
        self::assertSame(
            ['arch_supported' => 42, 'distro_supported' => 42],
            $client->whm()->supportTickets()->getSupportInfo(),
        );
    }

    public function testSecretInputsUseSensitiveParameterAttributes(): void
    {
        foreach (['grant' => ['secureId'], 'validateOauth2Code' => ['code', 'redirectUri']] as $method => $names) {
            $reflection = new ReflectionMethod(SupportTickets::class, $method);
            foreach ($reflection->getParameters() as $parameter) {
                if (in_array($parameter->getName(), $names, true)) {
                    self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                    $names = array_values(array_diff($names, [$parameter->getName()]));
                }
            }
            self::assertSame([], $names);
        }
    }

    public function testSelectorsRemainRequiredIntegersAndSshUserIsConditionalOnlyWhereDocumented(): void
    {
        foreach (['grant' => 3, 'list' => 2, 'revoke' => 3, 'sshTest' => 2, 'sshTestStart' => 2] as $method => $count) {
            $reflection = new ReflectionMethod(SupportTickets::class, $method);
            self::assertSame($count, $reflection->getNumberOfRequiredParameters());
            foreach ($reflection->getParameters() as $parameter) {
                if (in_array($parameter->getName(), ['serverNum', 'ticketId'], true)) {
                    self::assertSame('int', (string) $parameter->getType());
                }
            }
        }
        [$client, $http] = self::client();
        try {
            // @phpstan-ignore argument.type (Deliberately verify the strict public integer boundary.)
            $client->whm()->supportTickets()->list('2', 7648492);
            self::fail('String selector must fail before dispatch.');
        } catch (TypeError) {
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testPrivateMismatchPayloadIsRetainedButExcludedFromDiagnosticsAndLaterMetadata(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'ticket_create_stub_ticket',
            '{"secure_id":"private-token-never-print","ticket_id":"bad"}',
        )));
        try {
            $client->whm()->supportTickets()->createStubTicket();
            self::fail('Expected mismatch.');
        } catch (PayloadMismatchException $exception) {
            self::assertSame(['secure_id' => 'private-token-never-print', 'ticket_id' => 'bad'], $exception->payload);
            self::assertStringNotContainsString('private-token-never-print', $exception->getMessage());
            self::assertStringNotContainsString('private-token-never-print', print_r($exception, true));
        }
        $http->addResponse(new Response(200, [], self::envelope('ticket_whitelist_check', '{}')));
        self::assertSame([], $client->whm()->supportTickets()->whitelistCheck());
        self::assertSame('ticket_whitelist_check', $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertCount(2, $http->getRequests());
    }

    private static function envelope(string $operation, ?string $payload): string
    {
        return '{"metadata":{"command":"' . $operation . '","result":1,"reason":"OK","version":1}'
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
