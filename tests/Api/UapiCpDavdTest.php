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

/** Independent pinned CPDAVD collection and delegation contracts. */
final class UapiCpDavdTest extends TestCase
{
    /** @param array<array-key, mixed> $expected */
    #[DataProvider('validPayloads')]
    public function testExactWireAndPayload(string $route, string $function, string $payload, array $expected): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        $parameters = match ($function) {
            'add_delegate', 'update_delegate' => 'calendar=calendar%3Aone%2F%2B%26&delegatee=guest%2Btag%40example.test'
                . '&delegator=owner%40example.test&readonly=0',
            'remove_delegate' => 'calendar=calendar%3Aone%2F%2B%26&delegatee=guest%2Btag%40example.test'
                . '&delegator=owner%40example.test',
            'manage_collection' => 'account=owner%40example.test&action=update&collection_type=calendar'
                . '&calendar-color=%23ff6c2c&description=Private%20%2B%20description&name=My%20%26%20Calendar'
                . '&path=%2Fcalendars%2Fowner%40example.test%2Fcalendar%3Aone',
            default => '',
        };
        self::assertRequest(
            $http,
            $route,
            $function,
            str_starts_with($function, 'list_') ? 'GET' : 'POST',
            $parameters,
        );
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>}> */
    public static function validPayloads(): iterable
    {
        foreach (self::operations() as $label => [$route, $function]) {
            if ($function === 'list_delegates') {
                $cases = [
                ['[]', []], ['[{}]', [[]]], ['[[]]', [[]]],
                    ['[{"calendar":"calendar:one","calname":"Private Calendar","delegatee":"guest@example.test",'
                        . '"delegator":"owner@example.test","readonly":0},{"readonly":1,"future":false}]',
                        [['calendar' => 'calendar:one', 'calname' => 'Private Calendar',
                            'delegatee' => 'guest@example.test',
                            'delegator' => 'owner@example.test', 'readonly' => 0], ['readonly' => 1]]],
                    ['[{"calendar":"","calname":"","delegatee":"","delegator":""}]',
                        [['calendar' => '', 'calname' => '', 'delegatee' => '', 'delegator' => '']]]];
            } elseif ($function === 'list_users') {
                $cases = [
                ['[]', []], ['[{}]', [[]]], ['[[]]', [[]]],
                    ['[{"owner@example.test":{"calendar":{"calendar-color":"#ff6c2c",'
                        . '"description":"Private calendar","displayname":"Calendar","protected":1,"type":"VCALENDAR"},'
                        . '"contacts":{"description":"Contacts","displayname":"Address book",'
                        . '"protected":0,"type":"VADDRESSBOOK"},'
                        . '"tasks":{"type":"VTODO"}}}]',
                        [['owner@example.test' => ['calendar' => ['calendar-color' => '#ff6c2c',
                            'description' => 'Private calendar', 'displayname' => 'Calendar',
                            'protected' => 1, 'type' => 'VCALENDAR'],
                            'contacts' => ['description' => 'Contacts', 'displayname' => 'Address book',
                                'protected' => 0,
                                'type' => 'VADDRESSBOOK'], 'tasks' => ['type' => 'VTODO']]]]],
                    ['[{"owner+tag@example.test":{"id/one":{},"id/two":[]},"other@example.test":{}}]',
                        [['owner+tag@example.test' => ['id/one' => [], 'id/two' => []], 'other@example.test' => []]]],
                    ['[{"owner":{"calendar":{"type":"future-type","displayname":"","future":17}}}]',
                        [['owner' => ['calendar' => ['displayname' => '', 'type' => 'future-type']]]]]];
            } else {
                $cases = [
                ['{}', []], ['[]', []], ['{"shared":0}', ['shared' => 0]],
                    ['{"shared":1,"future":"ignored"}', ['shared' => 1]]];
            }
            foreach ($cases as $index => [$payload, $expected]) {
                yield "$label $index" => [$route, $function, $payload, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedAndAbsentPayloadsRetainFreshConfirmedMetadata(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            str_starts_with($function, 'list_') ? '[]' : '{}',
        )));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Malformed collection or delegation payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operations() as $label => [$route, $function]) {
            $cases = [null, 'null', 'false', '1', '1.5', '"text"'];
            if (str_starts_with($function, 'list_')) {
                array_push($cases, '{}', '[null]', '[1]', '[false]', '["text"]');
                if ($function === 'list_delegates') {
                    foreach (['calendar', 'calname', 'delegatee', 'delegator'] as $field) {
                        foreach (['null', '1', 'false', '[]'] as $bad) {
                            $cases[] = '[{"' . $field . '":' . $bad . '}]';
                        }
                    }
                    foreach (['null', '"0"', 'false', '2', '0.5'] as $bad) {
                        $cases[] = '[{"readonly":' . $bad . '}]';
                    }
                    $cases[] = '[{"calendar":"valid"},{"delegatee":null}]';
                } else {
                    foreach (['null', '1', 'false', '[{}]'] as $bad) {
                        $cases[] = '[{"owner":' . $bad . '}]';
                        $cases[] = '[{"owner":{"collection":' . $bad . '}}]';
                    }
                    foreach (['calendar-color', 'description', 'displayname', 'type'] as $field) {
                        foreach (['null', '1', 'false', '[]'] as $bad) {
                            $cases[] = '[{"owner":{"collection":{"' . $field . '":' . $bad . '}}}]';
                        }
                    }
                    foreach (['null', '"0"', 'false', '2', '0.5'] as $bad) {
                        $cases[] = '[{"owner":{"collection":{"protected":' . $bad . '}}}]';
                    }
                    $cases[] = '[{"owner":{"good":{"type":"VCALENDAR"},"bad":{"displayname":null}}}]';
                    $cases[] = '[{"good":{}},{"bad":null}]';
                }
            } else {
                $cases[] = '[{}]';
                foreach (['null', '"0"', 'false', '2', '-1', '0.5', '[]', '{}'] as $bad) {
                    $cases[] = '{"shared":' . $bad . '}';
                }
            }
            foreach ($cases as $index => $payload) {
                yield "$label $index" => [$route, $function, $payload];
            }
        }
    }

    #[DataProvider('routes')]
    public function testReadonlyOmissionFalseTrueAndCollectionConditionalOmission(string $route): void
    {
        [$client, $http] = self::client($route);
        $dav = $client->uapi('npr')->cpDavd();
        foreach (['add_delegate', 'update_delegate'] as $function) {
            foreach ([null, false, true] as $readonly) {
                $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
                if ($function === 'add_delegate') {
                    $dav->addDelegate(calendar: '', delegatee: '', delegator: '', readonly: $readonly);
                } else {
                    $dav->updateDelegate(calendar: '', delegatee: '', delegator: '', readonly: $readonly);
                }
                self::assertRequest($http, $route, $function, 'POST', 'calendar=&delegatee=&delegator='
                    . ($readonly === null ? '' : '&readonly=' . ($readonly ? '1' : '0')));
            }
        }
        foreach (['create', 'update', 'delete'] as $action) {
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $dav->manageCollection(account: '', action: $action, collectionType: 'calendar');
            self::assertRequest(
                $http,
                $route,
                'manage_collection',
                'POST',
                'account=&action=' . $action . '&collection_type=calendar',
            );
        }
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $dav->manageCollection(
            account: '',
            action: 'create',
            collectionType: 'calendar',
            calendarColor: '',
            description: '',
            name: '',
            path: '',
        );
        self::assertRequest(
            $http,
            $route,
            'manage_collection',
            'POST',
            'account=&action=create&collection_type=calendar&calendar-color=&description=&name=&path=',
        );
        self::assertCount(10, $http->getRequests());
    }

    #[DataProvider('operations')]
    public function testApiAndOuterFailuresAreNotRetried(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $function);
            self::fail('UAPI failure must precede payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        if ($route === 'bridge') {
            $payload = str_starts_with($function, 'list_') ? '[]' : '{}';
            $http->addResponse(new Response(200, [], str_replace(
                '"result":1',
                '"result":0,"reason":"Outer failure"',
                self::envelope($route, $payload),
            )));
            try {
                self::invoke($account, $function);
                self::fail('Outer WHM failure must precede UAPI success.');
            } catch (ApiErrorException $error) {
                self::assertSame(0, $error->metadata?->whmResult());
            }
        }
        self::assertCount($route === 'bridge' ? 2 : 1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testSelectorsAreCachedPerAccountWithoutRequests(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $other = $route === 'bridge' ? $client->uapi('other') : self::client($route)[0]->uapi('npr');
        self::assertSame($account->cpDavd(), $account->cpDavd());
        self::assertNotSame($account->cpDavd(), $other->cpDavd());
        self::assertSame($account->dav(), $account->dav());
        self::assertNotSame($account->dav(), $other->dav());
        self::assertCount(0, $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->cpDavd()->listUsers();
        $previous = $account->responseMeta();
        $account->dav();
        self::assertSame($previous, $account->responseMeta());
        self::assertNull($other->responseMeta());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (
                ['add_delegate', 'list_delegates', 'list_users', 'manage_collection',
                'remove_delegate', 'update_delegate'] as $function
            ) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        return match ($function) {
            'add_delegate' => $account->cpDavd()->addDelegate(
                calendar: 'calendar:one/+&',
                delegatee: 'guest+tag@example.test', delegator: 'owner@example.test', readonly: false,
            ),
            'update_delegate' => $account->cpDavd()->updateDelegate(
                calendar: 'calendar:one/+&',
                delegatee: 'guest+tag@example.test', delegator: 'owner@example.test', readonly: false,
            ),
            'remove_delegate' => $account->cpDavd()->removeDelegate(
                calendar: 'calendar:one/+&',
                delegatee: 'guest+tag@example.test', delegator: 'owner@example.test',
            ),
            'manage_collection' => $account->cpDavd()->manageCollection(
                account: 'owner@example.test',
                action: 'update', collectionType: 'calendar', calendarColor: '#ff6c2c',
                description: 'Private + description', name: 'My & Calendar',
                path: '/calendars/owner@example.test/calendar:one',
            ),
            'list_delegates' => $account->cpDavd()->listDelegates(),
            default => $account->cpDavd()->listUsers(),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
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

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"messages":null,"warnings":null,"metadata":{}}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=CPDAVD&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/CPDAVD/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }
}
