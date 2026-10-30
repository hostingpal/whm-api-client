<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\BoxTrapper;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

/**
 * Independent literal contracts from the pinned cpanel.openapi.json.gz BoxTrapper paths.
 *
 * @phpstan-type Call Closure(BoxTrapper): mixed
 * @phpstan-type Case array{string, string, Call, string, string}
 */
final class UapiBoxTrapperTest extends TestCase
{
    /** @param Call $call */
    #[DataProvider('requests')]
    public function testExactRequestsAndPinnedPayloads(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $call($account->boxTrapper()));
        self::assertRequest($http, $route, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, string, string, Call, string, string}> */
    public static function requests(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::cases() as $name => $case) {
                yield $route . ' ' . $name => [$route, ...$case];
            }
        }
    }

    /** @return iterable<string, Case> */
    public static function cases(): iterable
    {
        yield 'blacklist repeated messages with partial failure' => ['blacklist_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->blacklistMessages('user@example.com', ['example.msg', 'other.msg']),
            'email=user%40example.com&queuefile=example.msg&queuefile=other.msg',
            '[{"email":"sender@example.com","failed":1,"matches":[],"operator":"blacklist",'
                . '"reason":"denied","warning":1}]'];
        yield 'blacklist scalar message' => ['blacklist_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->blacklistMessages('user@example.com', 'example.msg'),
            'email=user%40example.com&queuefile=example.msg', '[]'];
        yield 'delete repeated messages explicit false' => ['delete_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->deleteMessages('user@example.com', ['a.msg', 'b.msg'], false),
            'email=user%40example.com&queuefile=a.msg&queuefile=b.msg&all_like=0',
            '[{"email":"sender@example.com","failed":1,"matches":["a.msg"],"operator":"delete",'
                . '"reason":"partial","warning":1}]'];
        yield 'deliver repeated messages true' => ['deliver_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->deliverMessages('user@example.com', ['a.msg', 'b.msg'], true),
            'email=user%40example.com&queuefile=a.msg&queuefile=b.msg&all_like=1',
            '[{"email":"sender@example.com","failed":1,"matches":["a.msg"],"operator":"deliverall",'
                . '"reason":"partial","warning":1}]'];
        yield 'get allowlist preserves escapes' => ['get_allowlist', 'GET',
            static fn (BoxTrapper $b): array => $b->getAllowlist('user@example.com'),
            'email=user%40example.com', '["from sender\\\\@example\\\\.com","to example\\\\.com"]'];
        yield 'get blocklist' => ['get_blocklist', 'GET',
            static fn (BoxTrapper $b): array => $b->getBlocklist('user@example.com'),
            'email=user%40example.com', '["from blocked\\\\@example\\\\.com"]'];
        yield 'complete configuration and zero' => ['get_configuration', 'GET',
            static fn (BoxTrapper $b): array => $b->getConfiguration('user@example.com'),
            'email=user%40example.com',
            '{"enable_auto_whitelist":0,"from_addresses":"a@example.com,b@example.com","from_name":"User",'
                . '"queue_days":0,"spam_score":2.5,"whitelist_by_association":1}'];
        yield 'get template string' => ['get_email_template', 'GET',
            static fn (BoxTrapper $b): string => $b->getEmailTemplate('user@example.com', 'verify'),
            'email=user%40example.com&template=verify', '"To: %email%\nSubject: verify#%msgid%\n\n%headers%"'];
        yield 'get forwarders' => ['get_forwarders', 'GET',
            static fn (BoxTrapper $b): array => $b->getForwarders('user@example.com'),
            'email=user%40example.com', '["one@example.com","two@example.com"]'];
        yield 'get ignorelist empty' => ['get_ignorelist', 'GET',
            static fn (BoxTrapper $b): array => $b->getIgnorelist('user@example.com'),
            'email=user%40example.com', '[]'];
        yield 'get log date zero' => ['get_log', 'GET',
            static fn (BoxTrapper $b): array => $b->getLog('user@example.com', 0),
            'email=user%40example.com&date=0', '{"date":0,"lines":[],"path":""}'];
        yield 'get message independent content and untyped contents' => ['get_message', 'GET',
            static fn (BoxTrapper $b): array => $b->getMessage('exampleuser', 'example.msg'),
            'email=exampleuser&queuefile=example.msg',
            '{"content":"From: private@example.com\n\nbody","contents":{"opaque":[false,null,0]},'
                . '"queuefile":"example.msg"}'];
        yield 'get status disabled payload' => ['get_status', 'GET',
            static fn (BoxTrapper $b): int => $b->getStatus('user@example.com'),
            'email=user%40example.com', '0'];
        yield 'ignore repeated messages' => ['ignore_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->ignoreMessages('user@example.com', ['a.msg', 'b.msg']),
            'email=user%40example.com&queuefile=a.msg&queuefile=b.msg',
            '[{"email":"sender@example.com","failed":1,"matches":[],"operator":"ignore",'
                . '"reason":"denied","warning":1}]'];
        yield 'list all template identifiers' => ['list_email_templates', 'GET',
            static fn (BoxTrapper $b): array => $b->listEmailTemplates(), '',
            '["blacklist","returnverify","verify","verifyreleased"]'];
        yield 'list queued messages date zero' => ['list_queued_messages', 'GET',
            static fn (BoxTrapper $b): array => $b->listQueuedMessages('user@example.com', 0),
            'email=user%40example.com&date=0',
            '[{"from":"sender@example.com","queuefile":"example.msg","subject":"A private subject","time":0}]'];
        yield 'process ordered actions and files' => ['process_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->processMessages(
                ['delete', 'ignore'],
                ['a.msg', 'b.msg'],
                'user@example.com',
            ), 'action=delete&action=ignore&queuefile=a.msg&queuefile=b.msg&email=user%40example.com',
            '[{"email":null,"failed":1,"matches":[],"operator":"delete","reason":"denied"}]'];
        yield 'reset template explicit mailbox' => ['reset_email_template', 'POST',
            static fn (BoxTrapper $b): mixed => $b->resetEmailTemplate('verify', 'user@example.com'),
            'template=verify&email=user%40example.com', 'null'];
        yield 'save complete configuration with zero flags' => ['save_configuration', 'POST',
            static fn (BoxTrapper $b): mixed => $b->saveConfiguration(
                email: 'user@example.com',
                enableAutoWhitelist: false,
                fromAddresses: 'one@example.com,two@example.com',
                queueDays: 14,
                whitelistByAssociation: false,
                fromName: '',
                spamScore: 0.0,
            ), 'email=user%40example.com&enable_auto_whitelist=0'
                . '&from_addresses=one%40example.com%2Ctwo%40example.com&queue_days=14'
                . '&whitelist_by_association=0&from_name=&spam_score=0.0', '{"opaque":[false,null,0]}'];
        yield 'save sensitive template encoded once' => ['save_email_template', 'POST',
            static fn (BoxTrapper $b): mixed => $b->saveEmailTemplate(
                'user@example.com',
                'verify',
                "To: %email%\nSubject: verify#%msgid%\n\nA&B=50% + café",
            ), 'email=user%40example.com&template=verify&contents=To%3A%20%25email%25%0A'
                . 'Subject%3A%20verify%23%25msgid%25%0A%0AA%26B%3D50%25%20%2B%20caf%C3%A9', 'null'];
        yield 'set allowlist repeated rules' => ['set_allowlist', 'POST',
            static fn (BoxTrapper $b): mixed => $b->setAllowlist(
                'user@example.com',
                ['from sender\@example\.com', 'to recipient\@example\.com'],
            ), 'email=user%40example.com&rules=from%20sender%5C%40example%5C.com'
                . '&rules=to%20recipient%5C%40example%5C.com', 'null'];
        yield 'set blocklist scalar rule' => ['set_blocklist', 'POST',
            static fn (BoxTrapper $b): mixed => $b->setBlocklist('user@example.com', 'from blocked\@example\.com'),
            'email=user%40example.com&rules=from%20blocked%5C%40example%5C.com', '{}'];
        yield 'set forwarding array' => ['set_forwarders', 'POST',
            static fn (BoxTrapper $b): mixed => $b->setForwarders(
                'user@example.com',
                ['one@example.com', 'two@example.com'],
            ), 'email=user%40example.com&forwarder=one%40example.com&forwarder=two%40example.com', 'null'];
        yield 'set ignorelist repeated rules' => ['set_ignorelist', 'POST',
            static fn (BoxTrapper $b): mixed => $b->setIgnorelist('user@example.com', ['from a', 'from b']),
            'email=user%40example.com&rules=from%20a&rules=from%20b', 'null'];
        yield 'set disabled username' => ['set_status', 'POST',
            static fn (BoxTrapper $b): mixed => $b->setStatus('exampleuser', false),
            'email=exampleuser&enabled=0', 'null'];
        yield 'whitelist string failed flag' => ['whitelist_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->whitelistMessages(['a.msg', 'b.msg'], 'user@example.com'),
            'queuefile=a.msg&queuefile=b.msg&email=user%40example.com',
            '[{"email":"sender@example.com","failed":"1","matches":[],"operator":"whitelist",'
                . '"reason":"denied","warning":1}]'];
        yield 'delete default omitted' => ['delete_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->deleteMessages('user@example.com', 'a.msg'),
            'email=user%40example.com&queuefile=a.msg', '[]'];
        yield 'deliver default omitted' => ['deliver_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->deliverMessages('user@example.com', 'a.msg'),
            'email=user%40example.com&queuefile=a.msg', '[]'];
        yield 'get log default omitted' => ['get_log', 'GET',
            static fn (BoxTrapper $b): array => $b->getLog('user@example.com'),
            'email=user%40example.com', '{}'];
        yield 'queued date omitted' => ['list_queued_messages', 'GET',
            static fn (BoxTrapper $b): array => $b->listQueuedMessages('user@example.com'),
            'email=user%40example.com', '[]'];
        yield 'save optional configuration omitted' => ['save_configuration', 'POST',
            static fn (BoxTrapper $b): mixed => $b->saveConfiguration(
                'user@example.com',
                true,
                '',
                1,
                true,
            ), 'email=user%40example.com&enable_auto_whitelist=1&from_addresses=&queue_days=1'
                . '&whitelist_by_association=1',
            'null'];
        yield 'process source optional mailbox omitted' => ['process_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->processMessages('delete', 'a.msg'),
            'action=delete&queuefile=a.msg', '[{}]'];
        yield 'reset source optional mailbox omitted' => ['reset_email_template', 'POST',
            static fn (BoxTrapper $b): mixed => $b->resetEmailTemplate('blacklist'),
            'template=blacklist', '{}'];
        yield 'whitelist source optional mailbox omitted' => ['whitelist_messages', 'POST',
            static fn (BoxTrapper $b): array => $b->whitelistMessages('a.msg'),
            'queuefile=a.msg', '[{}]'];
        yield 'message content absent and contents null' => ['get_message', 'GET',
            static fn (BoxTrapper $b): array => $b->getMessage('exampleuser', 'a.msg'),
            'email=exampleuser&queuefile=a.msg', '{"contents":null}'];
        yield 'template empty string' => ['get_email_template', 'GET',
            static fn (BoxTrapper $b): string => $b->getEmailTemplate('user@example.com', 'blacklist'),
            'email=user%40example.com&template=blacklist', '""'];
        // The shared PHP object boundary also accepts an empty JSON array as an empty object.
        yield 'shared empty object compatibility' => ['set_status', 'POST',
            static fn (BoxTrapper $b): mixed => $b->setStatus('user@example.com', false),
            'email=user%40example.com&enabled=0', '[]'];
    }

    /** @param Call $call */
    #[DataProvider('invalidPayloads')]
    public function testPayloadBoundariesRetainConfirmedOutcome(Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope('bridge', $payload)));
        try {
            $call($account->boxTrapper());
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(1, $error->metadata->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{Call, string|null}> */
    public static function invalidPayloads(): iterable
    {
        $status = static fn (BoxTrapper $b): int => $b->getStatus('user@example.com');
        foreach (['null', 'false', '"0"', '2', '{}'] as $payload) {
            yield 'integer status ' . $payload => [$status, $payload];
        }
        yield 'message typed content null' => [
            static fn (BoxTrapper $b): array => $b->getMessage('exampleuser', 'a.msg'), '{"content":null}',
        ];
        yield 'queued timestamp string' => [
            static fn (BoxTrapper $b): array => $b->listQueuedMessages('user@example.com'), '[{"time":"0"}]',
        ];
        yield 'configuration flag boolean' => [
            static fn (BoxTrapper $b): array => $b->getConfiguration('user@example.com'),
            '{"enable_auto_whitelist":false}',
        ];
        yield 'configuration score string' => [
            static fn (BoxTrapper $b): array => $b->getConfiguration('user@example.com'), '{"spam_score":"2.5"}',
        ];
        yield 'allowlist nonstring rule' => [
            static fn (BoxTrapper $b): array => $b->getAllowlist('user@example.com'), '[0]',
        ];
        yield 'log nonstring line' => [
            static fn (BoxTrapper $b): array => $b->getLog('user@example.com'), '{"lines":[null]}',
        ];
        yield 'whitelist integer failed' => [
            static fn (BoxTrapper $b): array => $b->whitelistMessages('a.msg', 'user@example.com'), '[{"failed":1}]',
        ];
        yield 'delete string failed' => [
            static fn (BoxTrapper $b): array => $b->deleteMessages('user@example.com', 'a.msg'), '[{"failed":"1"}]',
        ];
        yield 'process nonnullable reason' => [
            static fn (BoxTrapper $b): array => $b->processMessages('delete', 'a.msg', 'user@example.com'),
            '[{"reason":null}]',
        ];
        foreach (['false', '[1]', '"text"', '0'] as $payload) {
            yield 'nullable opaque mutation ' . $payload => [
                static fn (BoxTrapper $b): mixed => $b->setStatus('user@example.com', false), $payload,
            ];
        }
        foreach (self::cases() as $name => $case) {
            yield 'missing data ' . $name => [$case[2], null];
        }
    }

    /** @param array<mixed> $values */
    #[DataProvider('invalidLists')]
    public function testRepeatedInputFailsBeforeDispatchAndClearsMetadata(string $method, array $values): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope('direct', '0')));
        $account->boxTrapper()->getStatus('user@example.com');
        self::assertNotNull($account->responseMeta());
        try {
            // Reflection deliberately exercises invalid callers outside generated PHPDoc list contracts.
            match ($method) {
                'rules' => (new ReflectionMethod(BoxTrapper::class, 'setAllowlist'))
                    ->invoke($account->boxTrapper(), 'user@example.com', $values),
                'files' => (new ReflectionMethod(BoxTrapper::class, 'deleteMessages'))
                    ->invoke($account->boxTrapper(), 'user@example.com', $values),
                default => (new ReflectionMethod(BoxTrapper::class, 'processMessages'))
                    ->invoke($account->boxTrapper(), $values, 'a.msg', 'user@example.com'),
            };
            self::fail('Expected invalid repeated input.');
        } catch (InvalidArgumentException) {
            self::assertNull($account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidLists(): iterable
    {
        foreach (['rules', 'files', 'actions'] as $method) {
            foreach ([[], [1 => 'a'], ['a', false], [0], [null], [['a']]] as $index => $values) {
                yield $method . ' ' . $index => [$method, $values];
            }
        }
    }

    #[DataProvider('routes')]
    public function testApiFailureKeepsMetadataAndDoesNotRetry(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '0', 0)));
        try {
            $account->boxTrapper()->getStatus('user@example.com');
            self::fail('Expected API failure despite disabled payload.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic BoxTrapper failure'], $error->errors());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bare' => ['bare'];
        yield 'bridge' => ['bridge'];
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function configurationQueueDays(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                [
                    '0' => 0, '1' => 1, '"0"' => 0, '"1"' => 1, '"14"' => 14, '"-1"' => -1,
                    '"' . PHP_INT_MAX . '"' => PHP_INT_MAX, '"' . PHP_INT_MIN . '"' => PHP_INT_MIN,
                ] as $wire => $value
            ) {
                yield $route . ' ' . $wire => [$route, (string) $wire, $value];
            }
        }
    }

    #[DataProvider('configurationQueueDays')]
    public function testConfigurationQueueDaysNormalizesOnlyCanonicalBoundedIntegerStrings(
        string $route,
        string $wire,
        int $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"queue_days":' . $wire . '}')));
        self::assertSame(['queue_days' => $expected], $account->boxTrapper()->getConfiguration('user@example.com'));
        self::assertRequest($http, $route, 'get_configuration', 'GET', 'email=user%40example.com');
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidConfigurationQueueDays(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                [
                    '"1.0"', '"1.5"', '"1e2"', '"+1"', '"-0"', '"01"', '"-01"', '" 1"', '"1 "',
                    '"1\\n"', '""', '"days"', '"' . PHP_INT_MAX . '0"', '"' . PHP_INT_MIN . '0"',
                    'null', 'false', 'true', '1.0', '[]', '{}',
                ] as $wire
            ) {
                yield $route . ' ' . $wire => [$route, $wire];
            }
        }
    }

    #[DataProvider('invalidConfigurationQueueDays')]
    public function testConfigurationQueueDaysRejectsOtherFormatsAndOverflow(string $route, string $wire): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"queue_days":' . $wire . '}')));
        try {
            $account->boxTrapper()->getConfiguration('user@example.com');
            self::fail('Noncanonical or noninteger queue_days was accepted.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string,array{string}> */
    public static function unchangedConfigurationFields(): iterable
    {
        yield 'automatic whitelist flag' => ['"enable_auto_whitelist":"0"'];
        yield 'association flag' => ['"whitelist_by_association":"0"'];
        yield 'spam score numeric string' => ['"spam_score":"2.5"'];
        yield 'from addresses integer' => ['"from_addresses":0'];
        yield 'from name null' => ['"from_name":null'];
    }

    #[DataProvider('unchangedConfigurationFields')]
    public function testQueueDaysCorrectionDoesNotBroadenSiblingFields(string $sibling): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '{"queue_days":"1",' . $sibling . '}')));
        $this->expectException(PayloadMismatchException::class);
        $client->uapi('exampleuser')->boxTrapper()->getConfiguration('user@example.com');
    }

    public function testOmittedQueueDaysRemainsAbsent(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '{"enable_auto_whitelist":0}')));
        self::assertSame(
            ['enable_auto_whitelist' => 0],
            $client->uapi('exampleuser')->boxTrapper()->getConfiguration('user@example.com'),
        );
    }

    #[DataProvider('routes')]
    public function testGetLogDateNormalizesCanonicalIntegerString(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"date":"1556812881","lines":[],"path":""}',
        )));
        self::assertSame(
            ['date' => 1556812881, 'lines' => [], 'path' => ''],
            $account->boxTrapper()->getLog('user@example.com'),
        );
        self::assertRequest($http, $route, 'get_log', 'GET', 'email=user%40example.com');
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLogFields(): iterable
    {
        yield 'date null' => ['{"date":null}'];
        yield 'date boolean' => ['{"date":false}'];
        yield 'date fixed point' => ['{"date":"1.0"}'];
        yield 'date exponent' => ['{"date":"1e2"}'];
        yield 'date leading zero' => ['{"date":"01"}'];
        yield 'date overflow' => ['{"date":"' . PHP_INT_MAX . '0"}'];
        yield 'line remains string' => ['{"date":"0","lines":[0]}'];
        yield 'path remains nonnullable' => ['{"date":"0","path":null}'];
    }

    #[DataProvider('invalidLogFields')]
    public function testGetLogDateCorrectionRetainsStrictFields(string $payload): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope('direct', $payload)));
        try {
            $account->boxTrapper()->getLog('user@example.com');
            self::fail('Invalid log field was accepted.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testOmittedLogDateRemainsAbsent(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '{"lines":[],"path":""}')));
        self::assertSame(['lines' => [], 'path' => ''], $client->uapi('exampleuser')->boxTrapper()
            ->getLog('user@example.com'));
    }

    public function testOuterWhmFailureStillRejectsSuccessfulInnerMutation(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"outer denied"},'
            . '"data":{"uapi":{"status":1,"data":null,"errors":null}}}'));
        try {
            $account->boxTrapper()->setStatus('user@example.com', false);
            self::fail('Expected outer WHM failure.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testSensitiveRuleTemplateAndForwarderArguments(): void
    {
        foreach (
            [
                'saveEmailTemplate' => 'contents',
                'setAllowlist' => 'rules',
                'setBlocklist' => 'rules',
                'setIgnorelist' => 'rules',
                'setForwarders' => 'forwarders',
            ] as $method => $parameter
        ) {
            $parameters = (new ReflectionMethod(BoxTrapper::class, $method))->getParameters();
            $matches = array_filter($parameters, static fn ($p): bool => $p->getName() === $parameter);
            self::assertCount(1, $matches);
            self::assertCount(1, array_values($matches)[0]->getAttributes(SensitiveParameter::class));
        }
    }

    public function testSelectorCachesWithoutIoAndBindsDistinctAccounts(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        self::assertSame($first->boxTrapper(), $first->boxTrapper());
        self::assertNotSame($first->boxTrapper(), $second->boxTrapper());
        self::assertNull($first->responseMeta());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '0')));
        $http->addResponse(new Response(200, [], self::envelope('bridge', '1')));
        self::assertSame(0, $first->boxTrapper()->getStatus('first@example.com'));
        $firstRequest = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $firstRequest);
        self::assertStringContainsString('cpanel.user=exampleuser', (string) $firstRequest->getUri());
        $firstMetadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        self::assertSame(1, $second->boxTrapper()->getStatus('second@example.com'));
        self::assertSame($firstMetadata, $first->responseMeta());
        self::assertNotSame($firstMetadata, $second->responseMeta());
        $secondRequest = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $secondRequest);
        self::assertStringContainsString('cpanel.user=other', (string) $secondRequest->getUri());
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
                . '&cpanel.module=BoxTrapper&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/BoxTrapper/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic BoxTrapper failure"]') . '}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"BoxTrapper","result":' . $result . '}',
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
