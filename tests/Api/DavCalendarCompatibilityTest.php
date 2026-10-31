<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use ArgumentCountError;
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
use TypeError;

/** Synthetic regression oracles for PR 67 review 5721214794; no network access. */
final class DavCalendarCompatibilityTest extends TestCase
{
    /** @param array<array-key, mixed>|null $expected */
    #[DataProvider('acceptedPayloads')]
    public function testReviewedPayloadCompatibility(
        string $route,
        string $operation,
        ?string $payload,
        ?array $expected,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $operation));
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, ?string, array<array-key, mixed>|null}> */
    public static function acceptedPayloads(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach (['add', 'update', 'remove', 'collection'] as $operation) {
                foreach ([null, 'null'] as $index => $payload) {
                    yield "$route $operation missing-or-null $index" => [$route, $operation, $payload, null];
                }
                yield "$route $operation pinned object" => [$route, $operation, '{"shared":1}', ['shared' => 1]];
                yield "$route $operation omitted shared" => [$route, $operation, '{}', []];
            }
            yield "$route collection maps" => [$route, 'users',
                '{"alpha@example.test":{"cal/one":{"calendar-color":"#123456","description":"Synthetic",'
                    . '"displayname":"One","protected":"1","type":"VCALENDAR"},'
                    . '"contacts":{"protected":"0"}},"beta@example.test":{"tasks":{"protected":0},"empty":{}}}',
                ['alpha@example.test' => ['cal/one' => ['calendar-color' => '#123456',
                    'description' => 'Synthetic', 'displayname' => 'One', 'protected' => 1, 'type' => 'VCALENDAR'],
                    'contacts' => ['protected' => 0]], 'beta@example.test' => ['tasks' => ['protected' => 0],
                    'empty' => []]]];
            yield "$route empty maps" => [$route, 'users', '{}', []];
            yield "$route ports" => [$route, 'config',
                '{"activesync":{"port":"2091","enabled":0},"no_ssl":{"port":"2079"},'
                    . '"ssl":{"port":"2080","is_self_signed":1}}',
                ['activesync' => ['enabled' => 0, 'port' => 2091], 'no_ssl' => ['port' => 2079],
                    'ssl' => ['is_self_signed' => 1, 'port' => 2080]]];
        }
    }

    #[DataProvider('rejectedPayloads')]
    public function testMalformedSiblingsStillFailWithFreshConfirmedMetadata(
        string $route,
        string $operation,
        string $payload,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::invoke($account, $operation);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $operation);
            self::fail('Malformed reviewed payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejectedPayloads(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach (['add', 'update', 'remove', 'collection'] as $operation) {
                foreach (
                    ['false', '1', '"text"', '[{}]', '{"shared":"0"}', '{"shared":null}',
                    '{"shared":false}', '{"shared":2}'] as $index => $payload
                ) {
                    yield "$route $operation $index" => [$route, $operation, $payload];
                }
            }
            foreach (
                ['null', '[{}]', '{"owner":null}', '{"owner":{"cal":null}}',
                '{"owner":{"good":{"protected":0},"bad":{"displayname":null}}}',
                '{"good":{},"bad":false}'] as $index => $payload
            ) {
                yield "$route users structure $index" => [$route, 'users', $payload];
            }
            foreach (['"00"', '"2"', 'true', 'null', '2', '0.5'] as $index => $flag) {
                yield "$route users flag $index" => [$route, 'users', '{"owner":{"cal":{"protected":' . $flag . '}}}'];
            }
            foreach (['activesync', 'no_ssl', 'ssl'] as $section) {
                foreach (
                    ['"02080"', '"2080.0"', '"+2080"', '"2080 "', '"9223372036854775808"',
                    '2080.5', 'true', 'null'] as $index => $port
                ) {
                    yield "$route $section port $index" => [$route, 'config',
                        '{"' . $section . '":{"port":' . $port . '}}'];
                }
            }
            yield "$route config sibling" => [$route, 'config', '{"ssl":{"port":"2080","server":1}}'];
            yield "$route config flag" => [$route, 'config', '{"ssl":{"port":"2080","is_self_signed":"1"}}'];
        }
    }

    #[DataProvider('routes')]
    public function testUpdateRequiresExplicitReadonlyBeforeIo(string $route): void
    {
        foreach (['missing', 'null'] as $case) {
            [$account, $http] = self::account($route);
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            try {
                if ($case === 'missing') {
                    // @phpstan-ignore arguments.count (Exercise the public required-argument boundary.)
                    $account->cpDavd()->updateDelegate('calendar', 'guest@example.test', 'owner@example.test');
                } else {
                    // @phpstan-ignore argument.type (Exercise the public nonnullable boolean boundary.)
                    $account->cpDavd()->updateDelegate('calendar', 'guest@example.test', 'owner@example.test', null);
                }
                self::fail('An update must explicitly choose read-only or read-write.');
            } catch (ArgumentCountError | TypeError) {
                self::assertCount(0, $http->getRequests());
            }
        }
        [$account, $http] = self::account($route);
        foreach ([false, true] as $readonly) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
            self::assertNull($account->cpDavd()->updateDelegate(
                'calendar',
                'guest@example.test',
                'owner@example.test',
                $readonly,
            ));
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('calendar=calendar&delegatee=guest%40example.test&delegator=owner%40example.test&readonly='
                . ($readonly ? '1' : '0'), (string) $request->getBody());
        }
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testObservedApiFailuresKeepEmptyErrorsAndAreNeverRetried(string $route): void
    {
        foreach (['add', 'update', 'remove', 'collection', 'has', 'has-named', 'horde'] as $operation) {
            [$account, $http] = self::account($route);
            $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
            try {
                self::invoke($account, $operation);
                self::fail('An upstream failure must remain an API error.');
            } catch (ApiErrorException $error) {
                self::assertSame([], $error->errors());
                self::assertSame(0, $error->metadata?->uapiStatus());
                self::assertSame($account->responseMeta(), $error->metadata);
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    private static function invoke(UapiClient $account, string $operation): mixed
    {
        return match ($operation) {
            'add' => $account->cpDavd()->addDelegate('calendar', 'guest@example.test', 'owner@example.test'),
            'update' => $account->cpDavd()->updateDelegate(
                'calendar',
                'guest@example.test', 'owner@example.test', false,
            ),
            'remove' => $account->cpDavd()->removeDelegate('calendar', 'guest@example.test', 'owner@example.test'),
            'collection' => $account->cpDavd()->manageCollection(
                'owner@example.test',
                'create', 'calendar', name: 'Synthetic',
            ),
            'users' => $account->cpDavd()->listUsers(),
            'config' => $account->dav()->getCalendarContactsConfig(),
            'has' => $account->dav()->hasSharedGlobalAddressbook(),
            'has-named' => $account->dav()->hasSharedGlobalAddressbook('owner@example.test'),
            default => $account->dav()->isHordeEnabled(),
        };
    }

    /** @return array{UapiClient, MockClient} */
    private static function account(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'synthetic-token'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$client->uapi('npr'), $http];
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ',"errors":null' . ($payload === null ? '' : ',"data":' . $payload) . '}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }
}
