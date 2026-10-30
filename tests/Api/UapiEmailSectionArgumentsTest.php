<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Email;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UapiEmailSectionArgumentsTest extends TestCase
{
    /** @return iterable<string, array{Closure(Email): mixed, string, string, string}> */
    public static function optionalArguments(): iterable
    {
        yield 'mailbox creation false and zero' => [static fn (Email $email): mixed => $email->addPop(
            email: 'user',
            password: 'a+b&c',
            domain: 'example.test',
            quota: 0,
            sendWelcomeEmail: false,
            skipUpdateDb: false,
        ), 'add_pop', 'POST', 'email=user&password=a%2Bb%26c&domain=example.test&quota=0&send_we'
            . 'lcome_email=0&skip_update_db=0'];
        yield 'hash replaces plaintext password' => [static fn (Email $email): mixed => $email->addPop(
            email: 'user',
            passwordHash: '$6$fixture&hash',
            sendWelcomeEmail: false,
        ), 'add_pop', 'POST', 'email=user&password_hash=%246%24fixture%26hash&send_welcome_email=0'];
        yield 'mailbox unlimited' => [
            static fn (Email $email): mixed =>
                $email->addPop('user', 'secret', quota: 'unlimited'),
            'add_pop', 'POST', 'email=user&password=secret&quota=unlimited'];
        yield 'delete flags and false' => [static fn (Email $email): mixed => $email->deletePop(
            email: 'user',
            domain: 'example.test',
            flags: 'passwd',
            skipQuota: false,
        ), 'delete_pop', 'POST', 'email=user&domain=example.test&flags=passwd&skip_quota=0'];
        yield 'list privacy false' => [static fn (Email $email): mixed => $email->addList(
            domain: 'example.test',
            list: 'news',
            password: 'secret',
            private: false,
            rebuildOnly: false,
        ), 'add_list', 'POST', 'domain=example.test&list=news&password=secret&private=0&rebuildonly=0'];
        yield 'mailbox browsing false' => [static fn (Email $email): mixed => $email->browseMailbox(
            account: 'user@example.test',
            dir: 'INBOX',
            showDotFiles: false,
        ), 'browse_mailbox', 'GET', 'account=user%40example.test&dir=INBOX&showdotfiles=0'];
        yield 'MX omitted differs from false' => [static fn (Email $email): mixed => $email->addMx(
            domain: 'example.test',
            exchanger: 'mail.test',
            priority: 0,
            alwaysAccept: false,
        ), 'add_mx', 'POST', 'domain=example.test&exchanger=mail.test&priority=0&alwaysaccept=0'];
        yield 'quota unit flag false' => [static fn (Email $email): mixed => $email->getPopQuota(
            email: 'user',
            asBytes: false,
            domain: 'example.test',
        ), 'get_pop_quota', 'GET', 'email=user&as_bytes=0&domain=example.test'];
        yield 'quota unit flag true' => [static fn (Email $email): mixed => $email->getPopQuota('user', asBytes: true),
            'get_pop_quota', 'GET', 'email=user&as_bytes=1'];
        yield 'literal quota string' => [
            static fn (Email $email): mixed =>
                $email->editPopQuota('example.test', 'user', '0'),
            'edit_pop_quota', 'POST', 'domain=example.test&email=user&quota=0'];
        yield 'empty regex and false inventory switches' => [
            static fn (Email $email): mixed =>
                $email->listPopsWithDisk(
                    domain: 'example.test',
                    email: 'user',
                    getRestrictions: false,
                    infinityImage: 'infinity.png',
                    infinityLanguage: false,
                    maxAccounts: 10,
                    noDisk: false,
                    noValidate: false,
                    regex: '',
                ), 'list_pops_with_disk', 'GET',
            'domain=example.test&email=user&get_restrictions=0&infinityimg=infinity.png'
            . '&infinitylang=0&maxaccounts=10&no_disk=0&no_validate=0&regex='];
        yield 'client settings account' => [
            static fn (Email $email): mixed =>
                $email->getClientSettings('user@example.test'),
            'get_client_settings', 'GET', 'account=user%40example.test'];
        yield 'account name presentation string' => [static fn (Email $email): mixed => $email->accountName('user', ''),
            'account_name', 'GET', 'account=user&display='];
        yield 'MX routing aliases retained' => [static fn (Email $email): mixed => $email->setAlwaysAccept(
            domain: 'example.test',
            alwaysAccept: 'remote',
            mxcheck: 'local',
        ), 'set_always_accept', 'POST', 'domain=example.test&alwaysaccept=remote&mxcheck=local'];
        yield 'forwarding pipe content' => [static fn (Email $email): mixed => $email->addForwarder(
            domain: 'example.test',
            email: 'user@example.test',
            forwardOption: 'pipe',
            failureMessage: '',
            forwardEmail: '',
            forwardSystem: 'user',
            pipeForward: '/bin/handler a&b',
        ), 'add_forwarder', 'POST', 'domain=example.test&email=user%40example.test&fwdopt=pipe&failmsg'
            . 's=&fwdemail=&fwdsystem=user&pipefwd=%2Fbin%2Fhandler%20a%26b'];
        yield 'filter patterns retain aligned numbering' => [static fn (Email $email): mixed => $email->storeFilter(
            actions: ['save', 'finish'],
            filterName: 'coffee',
            matches: ['contains', 'is above'],
            parts: ['$header_subject', '$h_x-Spam-Score'],
            values: ['coffee', 0],
            account: 'user@example.test',
            destinations: ['/mail', ''],
            oldFilterName: 'old',
            operators: ['and', 'or'],
        ), 'store_filter', 'POST', 'action1=save&action2=finish&filtername=coffee&match1=contains&match2=is%20above'
            . '&part1=%24header_subject&part2=%24h_x-Spam-Score&val1=coffee&val2=0&account=user%40example.test'
            . '&dest1=%2Fmail&dest2=&oldfiltername=old&opt1=and&opt2=or'];
        yield 'filter ordering singleton' => [
            static fn (Email $email): mixed =>
                $email->reorderFilters('coffee', 'user@example.test'),
            'reorder_filters', 'POST', 'filter1=coffee&mailbox=user%40example.test'];
        yield 'trace message stays in form body' => [static fn (Email $email): mixed => $email->traceFilter(
            msg: "Subject: private\r\n\r\nBody & +",
            account: 'user@example.test',
        ), 'trace_filter', 'POST', 'msg=Subject%3A%20private%0D%0A%0D%0ABody%20%26%20%2B&account=user%40example.test'];
        yield 'outgoing suspension exact identity' => [
            static fn (Email $email): mixed =>
                $email->suspendOutgoing('owned@example.test'),
            'suspend_outgoing', 'POST', 'email=owned%40example.test'];
        yield 'held release exact identity' => [
            static fn (Email $email): mixed =>
                $email->releaseOutgoing('owned@example.test'),
            'release_outgoing', 'POST', 'email=owned%40example.test'];
        yield 'default address failure text' => [static fn (Email $email): mixed => $email->setDefaultAddress(
            forwardOption: 'fail',
            domain: 'example.test',
            failureMessage: 'Private & unavailable',
        ), 'set_default_address', 'POST', 'fwdopt=fail&domain=example.test&failmsgs=Private%20%26%20unavailable'];
    }

    #[DataProvider('optionalArguments')]
    public function testOptionalAndSensitiveArgumentsUseLiteralWireRepresentation(
        Closure $call,
        string $function,
        string $method,
        string $parameters,
    ): void {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            [$client, $http] = MysqlTestSupport::client($route);
            // An API failure avoids coupling parameter expectations to payload schemas.
            $result = '{"status":0,"errors":["Synthetic refusal"]}';
            $json = match ($route) {
                'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
                'bare' => $result,
                default => '{"result":' . $result . '}',
            };
            $http->addResponse(new Response(200, [], $json));
            try {
                $call($client->uapi('exampleuser')->email());
                self::fail('Expected the synthetic API refusal');
            } catch (\HostingPal\WhmApiClient\Exception\ApiErrorException) {
                $base = $route === 'bridge'
                    ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                        . '&cpanel.module=Email&cpanel.function=' . $function
                    : 'https://api.example.test:2083/execute/Email/' . $function;
                MysqlTestSupport::assertRequest(
                    $http,
                    $method,
                    $base . ($method === 'GET' ? ($route === 'bridge' ? '&' : '?') . $parameters : ''),
                    $method === 'POST' ? $parameters : '',
                    $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
                    $method === 'POST' ? 'application/x-www-form-urlencoded' : null,
                );
                self::assertCount(1, $http->getRequests());
            }
        }
    }
}
