<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\AccountRestoration;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmAccountRestorationTest extends TestCase
{
    private const JOB = '{"options":{"destid":"local","give_ip":0,"mail_config":1,"mysql":0,'
        . '"subdomains":1},"restore_point":"2026-10-01","user":"fixture"}';

    private const STATE_JOB = '{"options":{"give_ip":0,"mail_config":1,"mysql":0,"subdomains":1},'
        . '"restore_point":"2026-10-01","user":"fixture"}';

    public function testSelectorCachesWithoutSendingRequests(): void
    {
        [$client, $http] = self::client();
        self::assertInstanceOf(AccountRestoration::class, $client->whm()->accountRestoration());
        self::assertSame($client->whm()->accountRestoration(), $client->whm()->accountRestoration());
        self::assertSame([], $http->getRequests());
        self::assertNull($client->whm()->responseMeta());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>, string, string|null}> */
    public static function operations(): iterable
    {
        yield 'modules' => ['restoreModulesSummary', 'restore_modules_summary', 'GET', [], '',
            '{"modules":[{"module":"Account","notes":[],"restricted_available":1,'
                . '"restricted_summary":["Force unavailable"],"summary":["Creates account"]}]}'];
        yield 'activate' => ['restoreQueueActivate', 'restore_queue_activate', 'POST', [], '', null];
        yield 'enqueue' => ['restoreQueueAddTask', 'restore_queue_add_task', 'POST',
            ['2026-10-01', 'fixture', 'remote id', false, true, false, true],
            'restore_point=2026-10-01&user=fixture&destid=remote%20id&give_ip=0&mail_config=1&mysql=0&subdomains=1',
            '{"queue_id":"queued"}'];
        yield 'clear completed' => ['restoreQueueClearAllCompletedTasks', 'restore_queue_clear_all_completed_tasks',
            'POST', [], '', null];
        yield 'clear failed' => ['restoreQueueClearAllFailedTasks', 'restore_queue_clear_all_failed_tasks',
            'POST', [], '', null];
        yield 'clear pending' => ['restoreQueueClearAllPendingTasks', 'restore_queue_clear_all_pending_tasks',
            'POST', [], '', null];
        yield 'clear all' => ['restoreQueueClearAllTasks', 'restore_queue_clear_all_tasks', 'POST', [], '', null];
        yield 'clear single completed' => ['restoreQueueClearCompletedTask', 'restore_queue_clear_completed_task',
            'POST', [0, 'fixture'], 'start_time=0&user=fixture', null];
        yield 'clear single pending' => ['restoreQueueClearPendingTask', 'restore_queue_clear_pending_task',
            'POST', ['fixture'], 'user=fixture', null];
        yield 'active flag' => ['restoreQueueIsActive', 'restore_queue_is_active', 'GET', [], '', '{"is_active":0}'];
        yield 'active jobs' => ['restoreQueueListActive', 'restore_queue_list_active', 'GET', [], '',
            '{"restore_job":[' . self::JOB . ']}'];
        yield 'completed jobs' => ['restoreQueueListCompleted', 'restore_queue_list_completed', 'GET', [], '',
            '{"restore_job":[{"restore_job":' . self::JOB . ',"status_info":{"altered_items":null,'
                . '"dangerous_items":0,"finished":"0","log":"Synthetic log","restore_logfile":"item-log",'
                . '"result":2,"skipped_items":null,"started":"0","transfer_session_id":"session",'
                . '"warnings":null}}]}'];
        yield 'pending jobs' => ['restoreQueueListPending', 'restore_queue_list_pending', 'GET', [], '',
            '{"restore_job":[' . self::JOB . ']}'];
        yield 'queue state' => ['restoreQueueState', 'restore_queue_state', 'GET', [], '',
            '{"active":[' . self::STATE_JOB . '],"completed":[],"is_active":0,"pending":[{}]}'];
        yield 'restore account' => ['restoreAccount', 'restoreaccount', 'POST',
            ['daily', 'fixture', false, false, true, false, false],
            'type=daily&user=fixture&all=0&ip=0&mail=1&mysql=0&subs=0', null];
        yield 'username conflict check' => ['verifyNewUsernameForRestore', 'verify_new_username_for_restore',
            'GET', ['fixture &name'], 'user=fixture%20%26name', null];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testCompleteModuleUsesDocumentedWireContracts(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));

        $result = $client->whm()->accountRestoration()->{$method}(...$arguments);

        self::assertSame($payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $result);
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string}> */
    public static function optionalInputs(): iterable
    {
        yield 'enqueue defaults stay omitted' => ['restoreQueueAddTask', 'restore_queue_add_task',
            ['2026-10-01', 'fixture'], 'restore_point=2026-10-01&user=fixture'];
        yield 'enqueue explicit null options stay omitted' => ['restoreQueueAddTask', 'restore_queue_add_task',
            ['2026-10-01', 'fixture', null, null, null, null, null],
            'restore_point=2026-10-01&user=fixture'];
        yield 'enqueue named options and empty destination' => ['restoreQueueAddTask', 'restore_queue_add_task',
            ['restorePoint' => '2026-10-01', 'user' => 'fixture', 'destid' => '', 'mysql' => false],
            'restore_point=2026-10-01&user=fixture&destid=&mysql=0'];
        yield 'restore only mail selector' => ['restoreAccount', 'restoreaccount',
            ['type' => 'weekly', 'user' => 'fixture', 'mail' => true], 'type=weekly&user=fixture&mail=1'];
        yield 'restore explicit zero selectors' => ['restoreAccount', 'restoreaccount',
            ['type' => 'monthly', 'user' => 'fixture', 'all' => false], 'type=monthly&user=fixture&all=0'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('optionalInputs')]
    public function testOmissionFalseAndZeroRemainDistinct(
        string $method,
        string $operation,
        array $arguments,
        string $wire,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            $operation,
            $operation === 'restoreaccount' ? null : '{}',
        )));
        $client->whm()->accountRestoration()->{$method}(...$arguments);
        self::assertRequest($http, $operation, 'POST', $wire);
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function payloadOperations(): iterable
    {
        yield 'modules' => ['restoreModulesSummary', 'restore_modules_summary', []];
        yield 'enqueue' => ['restoreQueueAddTask', 'restore_queue_add_task', ['2026-10-01', 'fixture']];
        yield 'active flag' => ['restoreQueueIsActive', 'restore_queue_is_active', []];
        yield 'active jobs' => ['restoreQueueListActive', 'restore_queue_list_active', []];
        yield 'completed jobs' => ['restoreQueueListCompleted', 'restore_queue_list_completed', []];
        yield 'pending jobs' => ['restoreQueueListPending', 'restore_queue_list_pending', []];
        yield 'state' => ['restoreQueueState', 'restore_queue_state', []];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('payloadOperations')]
    public function testOptionalFieldsStayAbsentRatherThanAcquiringDefaults(
        string $method,
        string $operation,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
        self::assertSame([], $client->whm()->accountRestoration()->{$method}(...$arguments));
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string|null}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::payloadOperations() as $name => [$method, $operation, $arguments]) {
            foreach ([null, 'null', 'false', '1', '"text"', '[1]'] as $payload) {
                yield $name . ' root ' . ($payload ?? 'missing') => [$method, $operation, $arguments, $payload];
            }
        }
        yield 'module flag string' => ['restoreModulesSummary', 'restore_modules_summary', [],
            '{"modules":[{"restricted_available":"1"}]}'];
        yield 'module summary nonstring' => ['restoreModulesSummary', 'restore_modules_summary', [],
            '{"modules":[{"summary":[0]}]}'];
        yield 'queue id null' => ['restoreQueueAddTask', 'restore_queue_add_task', ['2026-10-01', 'fixture'],
            '{"queue_id":null}'];
        yield 'active flag boolean' => ['restoreQueueIsActive', 'restore_queue_is_active', [], '{"is_active":false}'];
        yield 'active flag out of range' => ['restoreQueueIsActive', 'restore_queue_is_active', [], '{"is_active":2}'];
        yield 'active nested option string' => ['restoreQueueListActive', 'restore_queue_list_active', [],
            '{"restore_job":[{"options":{"give_ip":"0"}}]}'];
        yield 'pending rows not list' => ['restoreQueueListPending', 'restore_queue_list_pending', [],
            '{"restore_job":{"user":"fixture"}}'];
        yield 'completed integer timestamp' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"started":0}}]}'];
        yield 'completed string counter' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"altered_items":"0"}}]}'];
        yield 'completed warning nonstring' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"warnings":[1]}}]}'];
        yield 'completed status null' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"status":null}}]}'];
        yield 'completed status string' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"status":"2"}}]}'];
        yield 'completed status boolean' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"status":false}}]}'];
        yield 'completed status message null' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"statusmsg":null}}]}'];
        yield 'completed status message integer' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"statusmsg":2}}]}'];
        yield 'completed status message list' => ['restoreQueueListCompleted', 'restore_queue_list_completed', [],
            '{"restore_job":[{"status_info":{"statusmsg":["warning"]}}]}'];
        yield 'state jobs nullable' => ['restoreQueueState', 'restore_queue_state', [], '{"active":null}'];
        yield 'state nested user numeric' => ['restoreQueueState', 'restore_queue_state', [],
            '{"pending":[{"user":1}]}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadRetainsSuccessfulOutcomeWithoutRetrying(
        string $method,
        string $operation,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        try {
            $client->whm()->accountRestoration()->{$method}(...$arguments);
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiFailuresRetainMetadataAndNeverRetry(
        string $method,
        string $operation,
        string $verb,
        array $arguments,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload, 0)));
        try {
            $client->whm()->accountRestoration()->{$method}(...$arguments);
            self::fail('Expected an API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame(['Synthetic restoration failure'], $error->errors());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertRequest($http, $operation, $verb, $wire);
        self::assertCount(1, $http->getRequests());
    }

    public function testCompletedJobNullableCountersWarningsAndStringTimestampsRemainDistinct(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'restore_queue_list_completed',
            '{"restore_job":[{"status_info":{"altered_items":0,"dangerous_items":null,"skipped_items":0,'
            . '"warnings":[],"finished":"001","started":"0","result":0}},{"status_info":{}}]}',
        )));
        self::assertSame(['restore_job' => [
            ['status_info' => ['altered_items' => 0, 'dangerous_items' => null, 'finished' => '001',
                'result' => 0, 'skipped_items' => 0, 'started' => '0', 'warnings' => []]],
            ['status_info' => []],
        ]], $client->whm()->accountRestoration()->restoreQueueListCompleted());
    }

    public function testCompletedJobPreservesStatusFieldsFromThePinnedQueueExample(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'restore_queue_list_completed',
            '{"restore_job":[{"status_info":{"result":2,"status":2,"statusmsg":"warning"}}]}',
        )));

        self::assertSame(['restore_job' => [
            ['status_info' => ['result' => 2, 'status' => 2, 'statusmsg' => 'warning']],
        ]], $client->whm()->accountRestoration()->restoreQueueListCompleted());
    }

    public function testCompletedJobStatusFieldsPreserveOmissionZeroEmptyTextAndOtherIntegerValues(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'restore_queue_list_completed',
            '{"restore_job":[{"status_info":{}},{"status_info":{"status":0}},'
            . '{"status_info":{"statusmsg":""}},{"status_info":{"status":37,"statusmsg":"Synthetic status"}}]}',
        )));

        self::assertSame(['restore_job' => [
            ['status_info' => []],
            ['status_info' => ['status' => 0]],
            ['status_info' => ['statusmsg' => '']],
            ['status_info' => ['status' => 37, 'statusmsg' => 'Synthetic status']],
        ]], $client->whm()->accountRestoration()->restoreQueueListCompleted());
    }

    public function testRestoreOutputStaysInMetadataRatherThanBecomingInventedData(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"OK",'
            . '"output":{"raw":"Synthetic restoration output"}}}'));
        $client->whm()->accountRestoration()->restoreAccount('daily', 'fixture', mail: true);
        self::assertSame(
            ['raw' => 'Synthetic restoration output'],
            $client->whm()->responseMeta()?->whm['output'] ?? null,
        );
        self::assertCount(1, $http->getRequests());
    }

    private static function assertRequest(MockClient $http, string $operation, string $verb, string $wire): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
            . ($verb === 'GET' && $wire !== '' ? '&' . $wire : ''), (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    private static function envelope(string $operation, ?string $payload, int $status = 1): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"'
            . ($status === 1 ? 'OK' : 'Synthetic restoration failure') . '","result":' . $status . ',"version":1}'
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
