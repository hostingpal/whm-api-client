<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Literal synthetic responses derived from review 5725223473, not a wire capture.
 *
 * These independent expectations catch lost GUID-null compatibility, either missing
 * integer normalization, widened neighboring fields and failures after accepted outcomes.
 */
final class UapiMailboxesCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{string, string, array{string, mixed}}> */
    public static function acceptedPayloads(): iterable
    {
        $fixtures = [
            'guid null' => ['expungeMessagesForMailboxGuid', 'null', null],
            'guid empty object' => ['expungeMessagesForMailboxGuid', '{}', []],
            'guid legacy empty array' => ['expungeMessagesForMailboxGuid', '[]', []],
            'guid opaque fields' => ['expungeMessagesForMailboxGuid',
                '{"provider":{"rows":[null,false,7],"text":"private"},"future":true}',
                ['provider' => ['rows' => [null, false, 7], 'text' => 'private'], 'future' => true]],
            'guid numeric object key' => ['expungeMessagesForMailboxGuid', '{"0":{"value":null}}',
                [0 => ['value' => null]]],
            'messages quoted alone' => ['getMailboxStatusList', '[{"messages":"3","vsize":42}]',
                [['messages' => 3, 'vsize' => 42]]],
            'vsize quoted alone' => ['getMailboxStatusList', '[{"messages":3,"vsize":"42"}]',
                [['messages' => 3, 'vsize' => 42]]],
            'both quoted and filtered' => ['getMailboxStatusList',
                '[{"guid":"0001","mailbox":"INBOX","messages":"0","vsize":"1052299","extra":7},{}]',
                [['guid' => '0001', 'mailbox' => 'INBOX', 'messages' => 0, 'vsize' => 1052299], []]],
            'native zero and omission' => ['getMailboxStatusList', '[{"messages":0,"vsize":0},{}]',
                [['messages' => 0, 'vsize' => 0], []]],
            'negative existing policy' => ['getMailboxStatusList', '[{"messages":-1,"vsize":"-2"}]',
                [['messages' => -1, 'vsize' => -2]]],
            'empty list' => ['getMailboxStatusList', '[]', []],
        ];
        foreach (['messages', 'vsize'] as $field) {
            foreach ([PHP_INT_MIN, PHP_INT_MAX] as $bound) {
                $fixtures[$field . ' quoted boundary ' . $bound] = ['getMailboxStatusList',
                    '[{"' . $field . '":"' . $bound . '"}]', [[$field => $bound]]];
                $fixtures[$field . ' native boundary ' . $bound] = ['getMailboxStatusList',
                    '[{"' . $field . '":' . $bound . '}]', [[$field => $bound]]];
            }
        }
        foreach (['null', 'false', '1', '1.0', '"text"', '[null,true,7]', '{"opaque":1}'] as $payload) {
            $fixtures['name mixed ' . $payload] = ['expungeMailboxMessages', $payload,
                json_decode($payload, true, 512, JSON_THROW_ON_ERROR)];
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($fixtures as $name => [$method, $payload, $expected]) {
                yield "$route $name" => [$route, $method, [$payload, $expected]];
            }
        }
    }

    /** @param array{string, mixed} $fixture */
    #[DataProvider('acceptedPayloads')]
    public function testCompatibleValuesRetainExactMeaning(string $route, string $method, array $fixture): void
    {
        [$payload, $expected] = $fixture;
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"enabled":1}')));
        $account->mailboxes()->hasUtf8MailboxNames();
        $before = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $group = $account->mailboxes();
        $actual = match ($method) {
            'expungeMessagesForMailboxGuid' =>
                $group->expungeMessagesForMailboxGuid('mail@example.test', 'guid', 'ALL'),
            'expungeMailboxMessages' => $group->expungeMailboxMessages('mail@example.test', 'INBOX', 'ALL'),
            default => $group->getMailboxStatusList('mail@example.test'),
        };
        self::assertSame($expected, $actual);
        self::assertCount(2, $http->getRequests());
        self::assertNotSame($before, $account->responseMeta());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function rejectedPayloads(): iterable
    {
        $fixtures = [];
        foreach ([null, 'true', '1', '1.0', '"text"', '[{}]', '[null]'] as $i => $payload) {
            $fixtures['guid root ' . $i] = ['expungeMessagesForMailboxGuid', $payload];
        }
        $fixtures['name absent'] = ['expungeMailboxMessages', null];
        foreach ([null, 'null', '{}', '[null]'] as $i => $payload) {
            $fixtures['status root ' . $i] = ['getMailboxStatusList', $payload];
        }
        foreach (['messages', 'vsize'] as $field) {
            foreach (
                ['"+1"', '"01"', '"-0"', '"1.0"', '"1e2"', '" 1"', '"1 "', '""',
                    '"' . PHP_INT_MAX . '0"', '"' . PHP_INT_MIN . '0"',
                    'true', 'false', '1.0', 'null', '[]', '{}'] as $i => $value
            ) {
                $fixtures[$field . ' invalid ' . $i] = ['getMailboxStatusList',
                    '[{"' . $field . '":' . $value . ',"private_extra":"unvalidated"}]'];
            }
        }
        foreach (['guid', 'mailbox'] as $field) {
            foreach (['1', 'null', 'false'] as $value) {
                $fixtures[$field . ' strict ' . $value] = ['getMailboxStatusList',
                    '[{"' . $field . '":' . $value . '}]'];
            }
        }
        foreach (['hasUtf8MailboxNames' => 'enabled', 'setUtf8MailboxNames' => 'success'] as $method => $field) {
            foreach (['"0"', '"1"', 'true', '1.0', 'null', '2'] as $value) {
                $fixtures[$method . ' strict ' . $value] = [$method, '{"' . $field . '":' . $value . '}'];
            }
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($fixtures as $name => [$method, $payload]) {
                yield "$route $name" => [$route, $method, $payload];
            }
        }
    }

    #[DataProvider('rejectedPayloads')]
    public function testMalformedValuesKeepFreshOutcomeAndUnvalidatedPayload(
        string $route,
        string $method,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"enabled":1}')));
        $account->mailboxes()->hasUtf8MailboxNames();
        $before = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $error = null;
        try {
            $group = $account->mailboxes();
            match ($method) {
                'expungeMessagesForMailboxGuid' => $group->expungeMessagesForMailboxGuid('acct', 'guid', 'ALL'),
                'expungeMailboxMessages' => $group->expungeMailboxMessages('acct', 'INBOX', 'ALL'),
                'hasUtf8MailboxNames' => $group->hasUtf8MailboxNames(),
                'setUtf8MailboxNames' => $group->setUtf8MailboxNames(false),
                default => $group->getMailboxStatusList('acct'),
            };
        } catch (PayloadMismatchException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(PayloadMismatchException::class, $error);
        self::assertTrue($error->outcomeConfirmed);
        self::assertNotSame($before, $account->responseMeta());
        self::assertSame($account->responseMeta(), $error->metadata);
        self::assertSame(1, $error->metadata?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
        self::assertSame(
            $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $error->payload,
        );
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function outcomeFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['expungeMessagesForMailboxGuid', 'getMailboxStatusList'] as $method) {
                yield "$route $method UAPI" => [$route, $method, false];
                if ($route === 'bridge') {
                    yield "$route $method outer" => [$route, $method, true];
                }
            }
        }
    }

    #[DataProvider('outcomeFailures')]
    public function testApiOutcomeRejectsBeforeCorrectedPayload(string $route, string $method, bool $outer): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"enabled":1}')));
        $account->mailboxes()->hasUtf8MailboxNames();
        $before = $account->responseMeta();
        $http->addResponse(new Response(
            200,
            [],
            self::envelope($route, '"malformed"', $outer ? 1 : 0, $outer ? 0 : 1),
        ));
        $error = null;
        try {
            $group = $account->mailboxes();
            if ($method === 'expungeMessagesForMailboxGuid') {
                $group->expungeMessagesForMailboxGuid('acct', 'guid', 'ALL');
            } else {
                $group->getMailboxStatusList('acct');
            }
        } catch (ApiErrorException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(ApiErrorException::class, $error);
        self::assertSame($account->responseMeta(), $error->metadata);
        self::assertNotSame($before, $error->metadata);
        self::assertSame($outer ? 1 : 0, $error->metadata?->uapiStatus());
        self::assertSame($route === 'bridge' ? ($outer ? 0 : 1) : null, $error->metadata->whmResult());
        self::assertCount(2, $http->getRequests());
    }

    private static function envelope(string $route, ?string $payload, int $status = 1, int $outer = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic API failure"]') . ',"warnings":null}';
        return $route === 'bridge'
            ? '{"metadata":{"result":' . $outer . '},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
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
