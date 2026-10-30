<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Email;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Tests\Fixtures\EmailSectionCases;
use HostingPal\WhmApiClient\Tests\Support\MysqlTestSupport;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UapiEmailSectionPayloadTest extends TestCase
{
    /** @return iterable<string, array{Closure(Email): mixed}> */
    public static function opaqueObjects(): iterable
    {
        foreach (EmailSectionCases::operations() as $name => [, $call, , , $payload]) {
            if ($payload === 'null') {
                yield $name => [$call];
            }
        }
    }

    #[DataProvider('opaqueObjects')]
    /** @param Closure(Email): mixed $call */
    public function testNullableOpaqueObjectsKeepFieldsWithoutInventingAFieldSchema(Closure $call): void
    {
        foreach (
            ['{}' => [], '{"opaque":{"nested":[null,0,false,"value"]}}' => [
            'opaque' => ['nested' => [null, 0, false, 'value']],
            ]] as $payload => $expected
        ) {
            [$client, $http] = MysqlTestSupport::client('direct');
            $http->addResponse(new Response(200, [], '{"status":1,"data":' . $payload . '}'));
            self::assertSame($expected, $call($client->uapi()->email()));
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{Closure(Email): mixed}> */
    public static function listObjects(): iterable
    {
        foreach (EmailSectionCases::operations() as $name => [, $call, , , $payload]) {
            if ($payload !== null && str_starts_with($payload, '[{')) {
                yield $name => [$call];
            }
        }
    }

    #[DataProvider('listObjects')]
    /** @param Closure(Email): mixed $call */
    public function testListsPreserveEmptyListsAndOmittedFields(Closure $call): void
    {
        foreach (['[]' => [], '[{},{}]' => [[], []]] as $payload => $expected) {
            [$client, $http] = MysqlTestSupport::client('direct');
            $http->addResponse(new Response(200, [], '{"status":1,"data":' . $payload . '}'));
            self::assertSame($expected, $call($client->uapi()->email()));
        }
    }

    #[DataProvider('listObjects')]
    /** @param Closure(Email): mixed $call */
    public function testAListItemMustMatchItsDocumentedObjectShape(Closure $call): void
    {
        [$client, $http] = MysqlTestSupport::client('direct');
        $http->addResponse(new Response(200, [], '{"status":1,"data":[false]}'));
        $this->expectException(PayloadMismatchException::class);
        $call($client->uapi()->email());
    }

    /** @return iterable<string, array{Closure(Email): mixed, string, mixed}> */
    public static function structuralBoundaries(): iterable
    {
        yield 'filter omitted members' => [static fn (Email $email): mixed => $email->getFilter('user', 'coffee'),
            '{}', []];
        yield 'filter empty nested lists' => [static fn (Email $email): mixed => $email->getFilter('user', 'coffee'),
            '{"actions":[],"rules":[],"metadata":{}}', ['actions' => [], 'metadata' => [], 'rules' => []]];
        yield 'filter ignores unknown members' => [
            static fn (Email $email): mixed =>
                $email->getFilter('user', 'coffee'),
            '{"filtername":"coffee","new":"ignored","actions":[{"action":"save","new":1}]}',
            ['actions' => [['action' => 'save']], 'filtername' => 'coffee']];
        yield 'spam nullable values' => [static fn (Email $email): mixed => $email->getSpamSettings(),
            '{"spam_auto_delete":null,"spam_auto_delete_score":0}',
            ['spam_auto_delete' => null, 'spam_auto_delete_score' => 0]];
        yield 'disk quota representations' => [static fn (Email $email): mixed => $email->listPopsWithDisk(),
            '[{"diskquota":0,"humandiskquota":0,"txtdiskquota":0,"diskused":1.25}]',
            [['diskquota' => 0, 'diskused' => 1.25, 'humandiskquota' => 0, 'txtdiskquota' => 0]]];
        yield 'disk percentage precision' => [static fn (Email $email): mixed => $email->listPopsWithDisk(),
            '[{"diskusedpercent_float":1.20494365692139e-05}]',
            [['diskusedpercent_float' => 1.20494365692139e-05]]];
        yield 'MX dynamic map null is retained' => [static fn (Email $email): mixed => $email->setManualMxRedirects(
            'example.test',
            'mail.test',
        ), '{"example.test":null}', ['example.test' => null]];
        yield 'MX empty map' => [static fn (Email $email): mixed => $email->unsetManualMxRedirects('example.test'),
            '{}', []];
        yield 'verification false is integer zero' => [
            static fn (Email $email): mixed =>
                $email->verifyPassword('user', 'secret'),
            '0', 0];
        yield 'deleting held messages returns count' => [
            static fn (Email $email): mixed =>
                $email->deleteHeldMessages('user'),
            '2', 2];
        yield 'delegate metadata remains opaque at transformed' => [
            static fn (Email $email): mixed =>
                $email->getMailmanDelegates('news'),
            '{"delegates":[],"metadata":{"transformed":{"unknown":null}}}',
            ['delegates' => [], 'metadata' => ['transformed' => ['unknown' => null]]]];
        yield 'HTML trace remains payload text' => [
            static fn (Email $email): mixed =>
                $email->traceFilter('Subject: test'),
            '{"trace":"<b>Normal delivery</b>"}', ['trace' => '<b>Normal delivery</b>']];
    }

    /** @return iterable<string, array{string, array<string, string>|list<array{mx: list<string>, type: string}>}> */
    public static function traceDestinations(): iterable
    {
        yield 'bounce message' => ['{"message":"Rejected","type":"bounce"}',
            ['message' => 'Rejected', 'type' => 'bounce']];
        yield 'command string' => ['{"command":"/bin/handler","type":"command"}',
            ['command' => '/bin/handler', 'type' => 'command']];
        yield 'deferred message' => ['{"message":"Deferred","type":"defer"}',
            ['message' => 'Deferred', 'type' => 'defer']];
        yield 'discard alias file' => ['{"aliasfile":"/etc/valiases/example.test","type":"discard"}',
            ['aliasfile' => '/etc/valiases/example.test', 'type' => 'discard']];
        yield 'error text' => ['{"message":"Error","result":"Lookup failed","type":"error"}',
            ['message' => 'Error', 'result' => 'Lookup failed', 'type' => 'error']];
        yield 'local mailbox' => ['{"mailbox":"user@example.test","type":"local_delivery"}',
            ['mailbox' => 'user@example.test', 'type' => 'local_delivery']];
        yield 'remote MX list' => ['[{"mx":["mail.test [192.0.2.1] MX=10 dnssec=no"],"type":"remote_delivery"}]',
            [['mx' => ['mail.test [192.0.2.1] MX=10 dnssec=no'], 'type' => 'remote_delivery']]];
    }

    /** @param array<string, string>|list<array{mx: list<string>, type: string}> $expected */
    #[DataProvider('traceDestinations')]
    public function testTraceRetainsEveryFormallyDocumentedDestinationAlternative(
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = MysqlTestSupport::client('direct');
        $http->addResponse(new Response(200, [], '{"status":1,"data":{"address":"user@example.test",'
            . '"destinations":' . $payload . ',"type":"routed"}}'));
        self::assertSame([
            'address' => 'user@example.test',
            'destinations' => $expected,
            'type' => 'routed',
        ], $client->uapi()->email()->traceDelivery('user@example.test'));
    }

    #[DataProvider('structuralBoundaries')]
    /** @param Closure(Email): mixed $call */
    public function testMeaningfulPayloadBoundaries(Closure $call, string $payload, mixed $expected): void
    {
        [$client, $http] = MysqlTestSupport::client('direct');
        $http->addResponse(new Response(200, [], '{"status":1,"data":' . $payload . '}'));
        self::assertSame($expected, $call($client->uapi()->email()));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{Closure(Email): mixed, string}> */
    public static function invalidNestedPayloads(): iterable
    {
        yield 'filter rule wrong value type' => [static fn (Email $email): mixed => $email->getFilter('user', 'coffee'),
            '{"rules":[{"val":3}]}'];
        yield 'filter action destination wrong type' => [
            static fn (Email $email): mixed =>
                $email->getFilter('user', 'coffee'),
            '{"actions":[{"dest":false}]}'];
        yield 'spam nullable integer rejects strings' => [static fn (Email $email): mixed => $email->getSpamSettings(),
            '{"spam_auto_delete_score":"5"}'];
        yield 'MX dynamic value must be string or null' => [
            static fn (Email $email): mixed =>
                $email->setManualMxRedirects(
                    'example.test',
                    'mail.test',
                ), '{"example.test":12}'];
        yield 'MX check nested fields retain type' => [
            static fn (Email $email): mixed =>
                $email->addMx('example.test', 'mail.test', 5),
            '{"checkmx":{"warnings":[1]}}'];
        yield 'delete MX warnings pinned string' => [
            static fn (Email $email): mixed =>
                $email->deleteMx('example.test', 'mail.test', 5),
            '{"checkmx":{"warnings":[]}}'];
        yield 'mailbox flags reject two' => [static fn (Email $email): mixed => $email->listPopsWithDisk(),
            '[{"suspended_login":2}]'];
        yield 'mailbox unescaped is boolean' => [static fn (Email $email): mixed => $email->listFilters(),
            '[{"unescaped":0}]'];
    }

    #[DataProvider('invalidNestedPayloads')]
    /** @param Closure(Email): mixed $call */
    public function testInvalidNestedFieldsAreRejected(Closure $call, string $payload): void
    {
        [$client, $http] = MysqlTestSupport::client('direct');
        $http->addResponse(new Response(200, [], '{"status":1,"data":' . $payload . '}'));
        $this->expectException(PayloadMismatchException::class);
        $call($client->uapi()->email());
    }
}
