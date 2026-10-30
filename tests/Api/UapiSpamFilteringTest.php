<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

/** Independent literals from the pinned SpamAssassin and cPGreyList operations. */
final class UapiSpamFilteringTest extends TestCase
{
    /** @param array<array-key, mixed>|null $expected */
    #[DataProvider('successCases')]
    public function testPinnedPayloadAndExactRequest(
        string $route,
        string $module,
        string $function,
        ?string $payload,
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

    /** @return iterable<string, array{string, string, string, ?string, ?array<array-key, mixed>}> */
    public static function successCases(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                [$payload, $expected] = self::populated($function);
                yield "$route $module/$function populated" => [$route, $module, $function, $payload, $expected];
                $empty = match ($function) {
                    'clear_spam_box' => null,
                    'get_user_preferences', 'update_user_preference', 'has_greylisting_enabled' => '{}',
                    default => '[]',
                };
                yield "$route $module/$function empty" => [
                    $route, $module, $function, $empty, $function === 'clear_spam_box' ? null : [],
                ];
            }
        }
    }

    /** @return array{?string, ?array<array-key, mixed>} */
    private static function populated(string $function): array
    {
        if ($function === 'clear_spam_box') {
            return [null, null];
        }
        if ($function === 'get_symbolic_test_names') {
            return ['[{"key":"ACT_NOW_ALL_CAPS","rule_type":"body_tests","score":1},'
                . '{"key":"CUSTOM","rule_type":"meta_tests","score":-0.25},{}]', [
                    ['key' => 'ACT_NOW_ALL_CAPS', 'rule_type' => 'body_tests', 'score' => 1],
                    ['key' => 'CUSTOM', 'rule_type' => 'meta_tests', 'score' => -0.25], [],
                ]];
        }
        if ($function === 'get_user_preferences' || $function === 'update_user_preference') {
            return ['{"blacklist_from":["user2@example.test"],"required_score":[0,5.1],'
                . '"score":["ACT_NOW_ALL_CAPS 1","CUSTOM -0.25"],"whitelist_from":[]}', [
                    'blacklist_from' => ['user2@example.test'], 'required_score' => [0, 5.1],
                    'score' => ['ACT_NOW_ALL_CAPS 1', 'CUSTOM -0.25'], 'whitelist_from' => [],
                ]];
        }
        if ($function === 'has_greylisting_enabled') {
            return ['{"enabled":0}', ['enabled' => 0]];
        }
        $row = ['dependencies' => ['example.test', 'alias.example.test'], 'domain' => 'example.test',
            'enabled' => 0, 'searchhint' => '', 'type' => 'main'];
        if ($function === 'list_domains') {
            $row = ['cPGreyList' => ['total_disabled' => 0, 'total_enabled' => 2]] + $row;
        }
        return [json_encode([$row, ['dependencies' => [], 'enabled' => 1], []], JSON_THROW_ON_ERROR),
            [$row, ['dependencies' => [], 'enabled' => 1], []]];
    }

    #[DataProvider('routes')]
    public function testSymbolicTestScoresPreserveNumericStrings(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"key":"CUSTOM","rule_type":"body_tests","score":"0.10000000000000000500"},'
                . '{"score":"-0.25"},{"score":"+001.5000"},{"score":"0.00"},{"score":0},{"score":0.5},{}]',
        )));
        self::assertSame([
            ['key' => 'CUSTOM', 'rule_type' => 'body_tests', 'score' => '0.10000000000000000500'],
            ['score' => '-0.25'], ['score' => '+001.5000'], ['score' => '0.00'],
            ['score' => 0], ['score' => 0.5], [],
        ], $account->spamAssassin()->getSymbolicTestNames());
        self::assertRequest($http, $route, 'SpamAssassin', 'get_symbolic_test_names');
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
    }

    #[DataProvider('routes')]
    public function testPinnedCustomPreferenceExampleSurvivesUpdate(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"blacklist_from":["username2@example.com"],"ok_locales":["all"],"required_score":[5],'
                . '"score":["ACT_NOW_CAPS 5"],"whitelist_from":["username@example.com"]}',
        )));
        $actual = $client->uapi('npr')->spamAssassin()->updateUserPreference(preference: 'ok_locales', value: 'all');
        self::assertSame([
            'blacklist_from' => ['username2@example.com'], 'required_score' => [5],
            'score' => ['ACT_NOW_CAPS 5'], 'whitelist_from' => ['username@example.com'], 'ok_locales' => ['all'],
        ], $actual);
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('preferenceScoreCases')]
    public function testPreferenceResponsesPreserveScoreTypesAndPresence(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $actual = $function === 'get_user_preferences'
            ? $account->spamAssassin()->getUserPreferences()
            : $account->spamAssassin()->updateUserPreference(preference: 'required_score', value: '5.0');
        self::assertSame($expected, $actual);
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($function === 'get_user_preferences' ? 'GET' : 'POST', $request->getMethod());
        self::assertSame(
            $function === 'get_user_preferences' ? '' : 'preference=required_score&value=5.0',
            (string) $request->getBody(),
        );
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function preferenceScoreCases(): iterable
    {
        // The first three strings were reported by PR36 review 5719192482; the rest are synthetic boundaries.
        $cases = [
            'reviewed fractional string' => ['{"required_score":["5.1"]}', ['required_score' => ['5.1']]],
            'reviewed integer string' => ['{"required_score":["6"]}', ['required_score' => ['6']]],
            'reviewed trailing zero' => ['{"required_score":["5.0"]}', ['required_score' => ['5.0']]],
            'precision and formatting' => [
                '{"required_score":["0.10000000000000000500","+001.5000","-0.25","0.00"]}',
                ['required_score' => ['0.10000000000000000500', '+001.5000', '-0.25', '0.00']],
            ],
            'JSON numbers' => ['{"required_score":[0,6,5.1,5.0]}', ['required_score' => [0, 6, 5.1, 5.0]]],
            'empty score list' => ['{"required_score":[]}', ['required_score' => []]],
            'omitted score list' => ['{"whitelist_from":[]}', ['whitelist_from' => []]],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['get_user_preferences', 'update_user_preference'] as $function) {
                foreach ($cases as $name => [$payload, $expected]) {
                    yield "$route $function $name" => [$route, $function, $payload, $expected];
                }
            }
        }
    }

    #[DataProvider('preferenceRoutes')]
    public function testReadAndUpdatePreserveTheSameOpaqueCustomPreferences(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '{"required_score":[5.1],"ok_locales":["all"],"custom_setting":{"opaque":null},'
                . '"custom_null":null,"custom_empty":[],"custom_number":[1],"custom_scalar":"all",'
                . '"custom_flag":false}',
        )));
        self::assertSame(
            ['required_score' => [5.1], 'ok_locales' => ['all'], 'custom_setting' => ['opaque' => null],
                'custom_null' => null, 'custom_empty' => [], 'custom_number' => [1], 'custom_scalar' => 'all',
                'custom_flag' => false],
            self::invoke($client->uapi('npr'), 'SpamAssassin', $function),
        );
        self::assertRequest($http, $route, 'SpamAssassin', $function);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function preferenceRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['get_user_preferences', 'update_user_preference'] as $function) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    #[DataProvider('invalidCases')]
    public function testMalformedPayloadKeepsConfirmedOutcomeAndDoesNotRetry(
        string $route,
        string $module,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $module, $function);
            self::fail('Expected a payload mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidCases(): iterable
    {
        $cases = [
            ['SpamAssassin', 'get_symbolic_test_names', '[{"score":"not-a-score"}]'],
            ['SpamAssassin', 'get_symbolic_test_names', '[{"score":null}]'],
            ['SpamAssassin', 'get_symbolic_test_names', '[{"score":true}]'],
            ['SpamAssassin', 'get_symbolic_test_names', '[{"score":""}]'],
            ['SpamAssassin', 'get_symbolic_test_names', '[{"score":" 1.0"}]'],
            ['SpamAssassin', 'get_symbolic_test_names', '[{"score":"1e3"}]'],
            ['SpamAssassin', 'get_symbolic_test_names', '[{"key":null}]'],
            ['SpamAssassin', 'get_symbolic_test_names', '[{"rule_type":0}]'],
            ['cPGreyList', 'has_greylisting_enabled', '{"enabled":true}'],
            ['cPGreyList', 'has_greylisting_enabled', '{"enabled":2}'],
            ['cPGreyList', 'list_domains', '[{"cPGreyList":{"total_disabled":null}}]'],
            ['cPGreyList', 'list_domains', '[{"cPGreyList":{"total_enabled":"0"}}]'],
            ['cPGreyList', 'disable_all_domains', '[{"dependencies":[0]}]'],
            ['cPGreyList', 'disable_domains', '[{"domain":null}]'],
            ['cPGreyList', 'enable_all_domains', '[{"searchhint":false}]'],
            ['cPGreyList', 'enable_domains', '[{"type":1}]'],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['get_user_preferences', 'update_user_preference'] as $function) {
                foreach (
                    ['"not-a-score"', '""', '" 5.1"', '"5.1 "', '"1e3"', '"NaN"', '"INF"',
                        'true', 'false', 'null', '[]', '{}'] as $index => $item
                ) {
                    yield "$route $function malformed score $index" => [
                        $route, 'SpamAssassin', $function, '{"required_score":[' . $item . ']}',
                    ];
                }
                foreach (['null', '"5.1"', '{}'] as $index => $value) {
                    yield "$route $function nonlist score $index" => [
                        $route, 'SpamAssassin', $function, '{"required_score":' . $value . '}',
                    ];
                }
                foreach (
                    ['{"blacklist_from":[null]}', '{"score":[1]}', '{"whitelist_from":null}'] as $index => $payload
                ) {
                    yield "$route $function strict declared field $index" => [
                        $route, 'SpamAssassin', $function, $payload,
                    ];
                }
            }
            foreach ($cases as $index => [$module, $function, $payload]) {
                yield "$route malformed $index" => [$route, $module, $function, $payload];
            }
            foreach (self::operations() as [$module, $function]) {
                if ($function !== 'clear_spam_box') {
                    yield "$route missing $module/$function" => [$route, $module, $function, null];
                    yield "$route null $module/$function" => [$route, $module, $function, 'null'];
                }
            }
        }
    }

    #[DataProvider('errorCases')]
    public function testApiFailureDoesNotRetry(string $route, string $module, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $module, $function);
            self::fail('Expected an API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function errorCases(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                yield "$route $module/$function" => [$route, $module, $function];
            }
        }
    }

    #[DataProvider('routes')]
    public function testPreferenceOmissionEmptyAndZeroAreDistinct(string $route): void
    {
        [$client, $http] = self::client($route);
        $spam = $client->uapi('npr')->spamAssassin();
        foreach ([null, '', '0'] as $value) {
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $spam->updateUserPreference(preference: 'required_score', value: $value);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame(
                'preference=required_score' . ($value === null ? '' : '&value=' . $value),
                (string) $request->getBody(),
            );
        }
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testGreylistSingleAndRepeatedDomainsPreserveLiteralValues(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        foreach (['', '0', 'a,b', 'one.example.test'] as $domains) {
            $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
            $account->cpGreyList()->disableDomains(domains: $domains);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('domains=' . rawurlencode($domains), (string) $request->getBody());
        }
        self::assertCount(4, $http->getRequests());
    }

    public function testSelectorsBindAccountsAndInvalidRepeatedInputClearsMetadataBeforeIo(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($first->spamAssassin(), $first->spamAssassin());
        self::assertSame($first->cpGreyList(), $first->cpGreyList());
        self::assertNotSame($first->spamAssassin(), $other->spamAssassin());
        self::assertNotSame($first->cpGreyList(), $other->cpGreyList());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{"enabled":1}')));
        $first->cpGreyList()->hasGreylistingEnabled();
        $metadata = $first->responseMeta();
        $first->spamAssassin();
        self::assertSame($metadata, $first->responseMeta());
        foreach ([[], [1], ['x' => 'domain.test']] as $invalid) {
            try {
                (new ReflectionMethod($first->cpGreyList(), 'enableDomains'))->invoke($first->cpGreyList(), $invalid);
                self::fail('Expected invalid repeated-list input.');
            } catch (InvalidArgumentException) {
                self::assertNull($first->responseMeta());
            }
        }
        self::assertCount(1, $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[]')));
        $other->cpGreyList()->listDomains();
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertStringContainsString('cpanel.user=other&cpanel.module=cPGreyList', (string) $request->getUri());
        self::assertNull($first->responseMeta());
        self::assertNotNull($other->responseMeta());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return list<array{string, string}> */
    private static function operations(): array
    {
        return [
            ['SpamAssassin', 'clear_spam_box'], ['SpamAssassin', 'get_symbolic_test_names'],
            ['SpamAssassin', 'get_user_preferences'], ['SpamAssassin', 'update_user_preference'],
            ['cPGreyList', 'disable_all_domains'], ['cPGreyList', 'disable_domains'],
            ['cPGreyList', 'enable_all_domains'], ['cPGreyList', 'enable_domains'],
            ['cPGreyList', 'has_greylisting_enabled'], ['cPGreyList', 'list_domains'],
        ];
    }

    private static function invoke(UapiClient $account, string $module, string $function): mixed
    {
        if ($module === 'SpamAssassin') {
            return match ($function) {
                'clear_spam_box' => $account->spamAssassin()->clearSpamBox(),
                'get_symbolic_test_names' => $account->spamAssassin()->getSymbolicTestNames(),
                'get_user_preferences' => $account->spamAssassin()->getUserPreferences(),
                default => $account->spamAssassin()->updateUserPreference(
                    preference: 'score',
                    value: 'ACT_NOW_ALL_CAPS 5.0 +&=%/雪',
                ),
            };
        }
        return match ($function) {
            'disable_all_domains' => $account->cpGreyList()->disableAllDomains(),
            'disable_domains' => $account->cpGreyList()->disableDomains(domains: ['one.example.test', 'a +&=%/雪']),
            'enable_all_domains' => $account->cpGreyList()->enableAllDomains(),
            'enable_domains' => $account->cpGreyList()->enableDomains(domains: ['one.example.test', 'a +&=%/雪']),
            'has_greylisting_enabled' => $account->cpGreyList()->hasGreylistingEnabled(),
            default => $account->cpGreyList()->listDomains(),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $module, string $function): void
    {
        $post = in_array($function, ['clear_spam_box', 'update_user_preference', 'disable_all_domains',
            'disable_domains', 'enable_all_domains', 'enable_domains'], true);
        $parameters = match ($function) {
            'update_user_preference' => 'preference=score&value=ACT_NOW_ALL_CAPS%205.0%20%2B%26%3D%25%2F%E9%9B%AA',
            'disable_domains', 'enable_domains' => 'domains=one.example.test&domains=a%20%2B%26%3D%25%2F%E9%9B%AA',
            default => '',
        };
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($parameters, (string) $request->getBody());
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
