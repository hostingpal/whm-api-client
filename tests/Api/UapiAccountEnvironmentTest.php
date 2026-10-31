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
use TypeError;

/** Independently authored payload and wire cases for the eight pinned environment reads. */
final class UapiAccountEnvironmentTest extends TestCase
{
    public function testSelectorsExistAndPerformNoIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        $other = $client->uapi('other');
        foreach (['accountEnhancements', 'ea4', 'serverInformation', 'variables'] as $selector) {
            self::assertTrue(method_exists($account, $selector), $selector . ' must expose the generated group.');
            self::assertSame($account->{$selector}(), $account->{$selector}());
            self::assertNotSame($account->{$selector}(), $other->{$selector}());
        }
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '0')));
        self::assertSame(0, $account->accountEnhancements()->hasEnhancement('example'));
        $metadata = $account->responseMeta();
        $account->ea4();
        $account->serverInformation();
        $account->variables();
        self::assertSame($metadata, $account->responseMeta());
        self::assertNull($other->responseMeta());
    }

    #[DataProvider('successfulPayloads')]
    public function testExactRequestsAndPayloads(
        string $route,
        string $module,
        string $function,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::assertSame($expected, self::invoke($account, $module, $function));
        } catch (PayloadMismatchException) {
            self::fail('The independently reviewed valid payload must be accepted.');
        }
        self::assertRequest($http, $route, $module, $function);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    #[DataProvider('observedCompatibilityPayloads')]
    #[DataProvider('reviewedVariablesFamilyPayloads')]
    public function testReviewedCompatibilityPreservesPayloadAndMetadata(
        string $route,
        string $module,
        string $function,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validCases($module, $function)[0][0])));
        self::invoke($account, $module, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::assertSame($expected, self::invoke($account, $module, $function));
        } catch (PayloadMismatchException) {
            self::fail('The reviewed field representation must satisfy its compatibility contract.');
        }
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertNotSame($previous, $metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(2, $http->getRequests());
        self::assertRequest($http, $route, $module, $function);
    }

    /** @return iterable<string, array{string, string, string, string, mixed}> */
    public static function observedCompatibilityPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::observedServerFields() as $field => $type) {
                $cases = $type === 'flag'
                    ? [['0', 0], ['1', 1]]
                    : [
                ['0', 0], ['123', 123], [(string) PHP_INT_MAX, PHP_INT_MAX],
                        [(string) PHP_INT_MIN, PHP_INT_MIN]];
                foreach ($cases as $index => [$wire, $value]) {
                    yield "$route observed $field $index" => [$route, 'Variables', 'get_server_information',
                        json_encode([$field => $wire], JSON_THROW_ON_ERROR), [$field => $value]];
                }
            }
            yield "$route observed null session host" =>
                [$route, 'Variables', 'get_session_information', '{"host":null}', ['host' => null]];
            foreach ([false, true] as $index => $value) {
                yield "$route observed boolean recommendation url $index" =>
                    [$route, 'EA4', 'get_recommendations',
                        json_encode(['fixture-package' => [['url' => $value]]], JSON_THROW_ON_ERROR),
                        ['fixture-package' => [['url' => $value]]]];
            }
            yield "$route numeric strings in string fields remain strings" =>
                [$route, 'Variables', 'get_server_information',
                    '{"empty_trash_days":"7","mysql_version":"8.0","version":"5.14"}',
                    ['empty_trash_days' => '7', 'mysql_version' => '8.0', 'version' => '5.14']];
        }
    }

    /** @return iterable<string, array{string, string, string, string, mixed}> */
    public static function reviewedVariablesFamilyPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::variableFields() as [$function, $field, $type]) {
                if ($function !== 'get_user_information' || !in_array($type, ['flag', 'int', 'limit'], true)) {
                    continue;
                }
                // Literal field families; some numeric variants are source-inferred rather than live-observed.
                $values = $type === 'flag' ? [0, 1] : [0, 25, PHP_INT_MAX, PHP_INT_MIN];
                foreach ($values as $index => $value) {
                    yield "$route reviewed user $field $index" => [$route, 'Variables', $function,
                        json_encode([$field => (string) $value], JSON_THROW_ON_ERROR), [$field => $value]];
                }
                if ($type === 'limit') {
                    // Preserve the existing string alternative; no new closed unlimited enum is inferred.
                    foreach (['unlimited', 'future', '01', '1.5', '999999999999999999999999999'] as $index => $value) {
                        yield "$route unchanged limit string $field $index" => [$route, 'Variables', $function,
                            json_encode([$field => $value], JSON_THROW_ON_ERROR), [$field => $value]];
                    }
                }
            }
            yield "$route reviewed dynamic feature flags" => [$route, 'Variables', 'get_user_information',
                '{"feature":{"fixture_0":"0","fixture_1":"1","fixture_int":1}}',
                ['feature' => ['fixture_0' => 0, 'fixture_1' => 1, 'fixture_int' => 1]]];
            yield "$route reviewed unset contacts" => [$route, 'Variables', 'get_user_information',
                '{"contact_email":null,"contact_email_2":null}', ['contact_email' => null, 'contact_email_2' => null]];
            yield "$route reviewed alternate contact unset" => [$route, 'Variables', 'get_user_information',
                '{"contact_email":"fixture@example.test","contact_email_2":null}',
                ['contact_email' => 'fixture@example.test', 'contact_email_2' => null]];
            foreach (['file_upload_maximum_bytes', 'minimum_password_strength_mysql'] as $field) {
                foreach ([0, 25, PHP_INT_MAX, PHP_INT_MIN] as $index => $value) {
                    yield "$route inferred server $field $index" => [$route, 'Variables', 'get_server_information',
                        json_encode([$field => (string) $value], JSON_THROW_ON_ERROR), [$field => $value]];
                }
            }
        }
    }

    /** @return array<string, string> */
    private static function observedServerFields(): array
    {
        // Literal known paths from the original e7b14c3 artifact, not the effective specs.
        $flags = 'allow_park_subdomain_on_hostname allow_remote_domains allow_reset_password '
            . 'allow_reset_password_for_subaccounts allow_unregistered_domains api_shell awstats_browser_update '
            . 'awstats_reverse_dns database_prefix_required disable_analog disable_awstats disable_boxtrapper '
            . 'disable_mailman disable_roundcube disable_spamassassin disable_spambox disable_webalizer '
            . 'display_cpanel_doclinks docroots_in_public_html_only enable_file_protect file_usage invite_sub '
            . 'ipv6_listen is_mod_userdir_enabled php_open_basedir_home phpmyadmin_disable_search_info_schema '
            . 'require_ssl skip_apache_clients_optimizer skip_bandwidth_limit_check skip_mailbox_warnings_check '
            . 'use_information_schema use_mail_for_mailman_url';
        $integers = 'file_upload_must_leave_bytes htaccess_check_recurse minimum_password_strength '
            . 'php_maximum_execution_time php_post_maximum_size php_upload_maximum_filesize';
        return array_fill_keys(explode(' ', $flags), 'flag') + array_fill_keys(explode(' ', $integers), 'int');
    }

    /** @return iterable<string, array{string, string, string, string, mixed}> */
    public static function successfulPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                foreach (self::validCases($module, $function) as $index => [$payload, $expected]) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload, $expected];
                }
            }
            foreach (self::variableFields() as [$function, $field, $type]) {
                $values = match ($type) {
                    'nullable-flag' => [null, 0, 1],
                    'nullable-int' => [null, 0, 25],
                    'nullable-string' => [null, '', 'synthetic'],
                    'flag' => [0, 1],
                    'int' => [0, 25],
                    'string' => ['', 'synthetic'],
                    'limit' => [0, 25, 'unlimited'],
                    default => [[], ['synthetic']],
                };
                foreach ($values as $index => $value) {
                    $expected = [$field => $value];
                    yield "$route $function $field $index" =>
                        [$route, 'Variables', $function, json_encode($expected, JSON_THROW_ON_ERROR), $expected];
                }
            }
        }
    }

    /** @return list<array{string, mixed}> */
    private static function validCases(string $module, string $function): array
    {
        return match ($module . '/' . $function) {
            'AccountEnhancements/has_enhancement' => [['0', 0], ['1', 1]],
            // No root type is pinned: retain the unresolved payload instead of guessing list or object.
            'AccountEnhancements/list' => [
                ['[]', []], ['{}', []], ['null', null], ['false', false], ['3', 3], ['"opaque"', 'opaque'],
                ['[{"id":"fixture-id","name":"Fixture"}]', [['id' => 'fixture-id', 'name' => 'Fixture']]],
                ['{"opaque":{"nested":null}}', ['opaque' => ['nested' => null]]],
            ],
            'EA4/get_php_recommendations' => [
            ['[]', []], ['["php80","php99","", "php80"]',
                ['php80', 'php99', '', 'php80']]],
            'EA4/get_recommendations' => [
                ['{}', []], ['{"synthetic-package":[]}', ['synthetic-package' => []]],
                ['{"synthetic-package":[{}]}', ['synthetic-package' => [[]]]],
                ['{"pkg-future":[{"desc":"","level":"future","name":"Fixture","on":"future",'
                    . '"options":[{"items":[],"level":"info","recommended":false,"text":"","url":""},'
                    . '{"recommended":true},{"items":["synthetic-dependency"]},{}],"url":"","extra":1}]}',
                    ['pkg-future' => [['desc' => '', 'level' => 'future', 'name' => 'Fixture', 'on' => 'future',
                        'options' => [
            ['items' => [], 'level' => 'info', 'recommended' => false, 'text' => '', 'url' => ''],
                            ['recommended' => true], ['items' => ['synthetic-dependency']], []], 'url' => '']]]],
            ],
            'ServerInformation/get_information' => [
                ['[]', []], ['[{}]', [[]]],
                ['[{"name":"Fixture service","status":0,"type":"service","value":"down"},'
                    . '{"error":"Synthetic error","name":"Fixture metric","status":"unknown",'
                    . '"type":"metric","value":"1.73","version":"fixture-version","extra":1}]',
                    [
            ['name' => 'Fixture service', 'status' => 0, 'type' => 'service', 'value' => 'down'],
                        ['error' => 'Synthetic error', 'name' => 'Fixture metric', 'status' => 'unknown',
                            'type' => 'metric', 'value' => '1.73', 'version' => 'fixture-version']]],
                ['[{"status":1,"value":"44.12%"},{"type":"future","status":"future","value":""}]',
                    [['status' => 1, 'value' => '44.12%'], ['status' => 'future', 'type' => 'future', 'value' => '']]],
            ],
            'Variables/get_server_information' => [
                ['{}', []], ['{"allow_remote_domains":null,"mysql_host":null,"default_theme":"","future":1}',
                    ['allow_remote_domains' => null, 'default_theme' => '', 'mysql_host' => null]],
            ],
            'Variables/get_session_information' => [
                ['{}', []], ['{"host":"fixture.example.test","future":1}', ['host' => 'fixture.example.test']],
                ['{"host":""}', ['host' => '']],
            ],
            default => [
                ['{}', []], ['{"feature":{},"mxcheck":{},"domains":[]}',
                    ['domains' => [], 'feature' => [], 'mxcheck' => []]],
                ['{"feature":{"fixture_a":0,"fixture_b":1},"mxcheck":{"fixture.example":"local",'
                    . '"other.example":"future"},"maximum_mail_accounts":0,"maximum_emails_per_hour":"unlimited"}',
                    ['feature' => ['fixture_a' => 0, 'fixture_b' => 1], 'maximum_emails_per_hour' => 'unlimited',
                        'maximum_mail_accounts' => 0,
                        'mxcheck' => ['fixture.example' => 'local', 'other.example' => 'future']]],
                ['{"home_directory_links":[null,false,4,{"fixture":"opaque"},["nested"]]}',
                    ['home_directory_links' => [null, false, 4, ['fixture' => 'opaque'], ['nested']]]],
                ['{"pushbullet_access_token":"synthetic-payload-token","future":1}',
                    ['pushbullet_access_token' => 'synthetic-payload-token']],
            ],
        };
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidOrMissingPayloadRetainsConfirmedOutcome(
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
            self::fail('Invalid environment payload must be rejected.');
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
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                $cases = [null];
                if ($function !== 'list') {
                    array_push($cases, 'null', 'false', '1.5', '"text"');
                }
                if ($function === 'has_enhancement') {
                    array_push($cases, '2', '-1', '"0"', '"1"', '[]', '{}');
                } elseif ($function === 'get_php_recommendations') {
                    array_push($cases, '1', '{}', '[0]', '[null]', '[false]', '[{}]');
                } elseif ($function === 'get_recommendations') {
                    array_push(
                        $cases,
                        '1',
                        '[{}]',
                        '{"pkg":null}',
                        '{"pkg":{}}',
                        '{"pkg":[null]}',
                        '{"pkg":[{"options":null}]}',
                        '{"pkg":[{"options":[null]}]}',
                        '{"pkg":[{"options":[{"recommended":0}]}]}',
                        '{"pkg":[{"options":[{"recommended":"false"}]}]}',
                        '{"pkg":[{"options":[{"items":[1]}]}]}',
                    );
                    foreach (['desc', 'level', 'name', 'on', 'url'] as $field) {
                        array_push(
                            $cases,
                            '{"pkg":[{"' . $field . '":null}]}',
                            '{"pkg":[{"' . $field . '":0}]}',
                        );
                    }
                    foreach (['level', 'text', 'url'] as $field) {
                        $cases[] = '{"pkg":[{"options":[{"' . $field . '":0}]}]}';
                        $cases[] = '{"pkg":[{"options":[{"' . $field . '":false}]}]}';
                        $cases[] = '{"pkg":[{"options":[{"' . $field . '":true}]}]}';
                    }
                } elseif ($function === 'get_information') {
                    array_push(
                        $cases,
                        '1',
                        '{}',
                        '[null]',
                        '[1]',
                        '[{"status":2}]',
                        '[{"status":true}]',
                        '[{"status":1.0}]',
                        '[{"status":null}]',
                    );
                    foreach (['error', 'name', 'type', 'value', 'version'] as $field) {
                        array_push($cases, '[{"' . $field . '":null}]', '[{"' . $field . '":0}]');
                    }
                } elseif ($module === 'Variables') {
                    array_push($cases, '1', '[{}]');
                    if ($function === 'get_session_information') {
                        array_push($cases, '{"host":1}', '{"host":false}', '{"host":[]}');
                    } elseif ($function === 'get_user_information') {
                        foreach (['feature', 'mxcheck'] as $field) {
                            array_push(
                                $cases,
                                '{"' . $field . '":null}',
                                '{"' . $field . '":[1]}',
                                '{"' . $field . '":{"fixture":null}}',
                                '{"' . $field . '":{"fixture":false}}',
                            );
                        }
                        array_push(
                            $cases,
                            '{"feature":{"fixture":2}}',
                            '{"feature":{"fixture":"2"}}',
                            '{"feature":{"fixture":"01"}}',
                            '{"feature":{"fixture":"-1"}}',
                            '{"feature":{"fixture":"1.0"}}',
                            '{"feature":{"fixture":"999999999999999999999999999"}}',
                            '{"mxcheck":{"fixture":1}}',
                            '{"home_directory_links":null}',
                        );
                    }
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload];
                }
            }
            foreach (self::variableFields() as [$function, $field, $type]) {
                $badValues = ['false', '{}'];
                if (!str_starts_with($type, 'nullable-')) {
                    $badValues[] = 'null';
                }
                if (str_contains($type, 'flag') || str_contains($type, 'int')) {
                    $badValues[] = '0.5';
                    array_push(
                        $badValues,
                        '"00"',
                        '"01"',
                        '"-0"',
                        '"+1"',
                        '"1.0"',
                        '"0.5"',
                        '"1e3"',
                        '" 1"',
                        '"1 "',
                        '""',
                        '"unlimited"',
                        '"999999999999999999999999999"',
                        '"-999999999999999999999999999"',
                        'true',
                    );
                    if (str_contains($type, 'flag')) {
                        array_push($badValues, '"2"', '"-1"');
                    }
                }
                if (str_contains($type, 'flag')) {
                    array_push($badValues, '2', '-1');
                }
                if (str_contains($type, 'string')) {
                    $badValues[] = '0';
                }
                if ($type === 'limit') {
                    $badValues[] = '0.5';
                }
                if ($type === 'list') {
                    array_push($badValues, '[0]', '[null]');
                }
                foreach ($badValues as $index => $bad) {
                    yield "$route $function invalid $field $index" =>
                        [$route, 'Variables', $function, '{"' . $field . '":' . $bad . '}'];
                }
            }
        }
    }

    #[DataProvider('operationRoutes')]
    public function testOrdinaryUapiAndOuterWhmFailuresAreNotFeatureResults(
        string $route,
        string $module,
        string $function,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $module, $function);
            self::fail('UAPI status zero is an API failure even with null errors.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
        if ($route !== 'bridge') {
            return;
        }
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic failure"},'
            . '"data":{"uapi":{"status":1,"data":0,"errors":null}}}'));
        try {
            self::invoke($account, $module, $function);
            self::fail('An outer WHM failure must not become a successful UAPI result.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                yield "$route $module/$function" => [$route, $module, $function];
            }
        }
    }

    #[DataProvider('routes')]
    public function testOptionalNameSelectorsPreserveOmissionAndEncodeOnce(string $route): void
    {
        [$client, $http] = self::client($route);
        $variables = $client->uapi('npr')->variables();
        foreach (['get_server_information', 'get_session_information', 'get_user_information'] as $function) {
            foreach ([false, true] as $explicitNull) {
                $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
                match ($function) {
                    'get_server_information' => $explicitNull
                        ? $variables->getServerInformation(name: null) : $variables->getServerInformation(),
                    'get_session_information' => $explicitNull
                        ? $variables->getSessionInformation(name: null) : $variables->getSessionInformation(),
                    default => $explicitNull
                        ? $variables->getUserInformation(name: null) : $variables->getUserInformation(),
                };
                self::assertRequest($http, $route, 'Variables', $function);
            }
        }
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $variables->getServerInformation(name: 'default_theme');
        self::assertRequest($http, $route, 'Variables', 'get_server_information', 'name=default_theme');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $variables->getSessionInformation(name: 'host');
        self::assertRequest($http, $route, 'Variables', 'get_session_information', 'name=host');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $variables->getUserInformation(name: 'a +&=%/雪');
        self::assertRequest($http, $route, 'Variables', 'get_user_information', 'name=a%20%2B%26%3D%25%2F%E9%9B%AA');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $variables->getUserInformation(name: '');
        self::assertRequest($http, $route, 'Variables', 'get_user_information', 'name=');
        self::assertCount(10, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testUserAndServerNamesRepeatWithoutNumbering(string $route): void
    {
        [$client, $http] = self::client($route);
        $variables = $client->uapi('npr')->variables();
        foreach (['get_server_information', 'get_user_information'] as $function) {
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            match ($function) {
                'get_server_information' => $variables->getServerInformation(name: ['default_theme', 'a +&=%/雪']),
                default => $variables->getUserInformation(name: ['domain', 'a +&=%/雪']),
            };
            $first = $function === 'get_server_information' ? 'default_theme' : 'domain';
            self::assertRequest($http, $route, 'Variables', $function, 'name=' . $first
                . '&name=a%20%2B%26%3D%25%2F%E9%9B%AA');
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param array<mixed> $names */
    #[DataProvider('invalidRepeatedNames')]
    public function testMalformedRepeatedNamesClearMetadataBeforeIo(string $method, array $names): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', '{}')));
        $account->variables()->getSessionInformation();
        self::assertNotNull($account->responseMeta());
        try {
            // Exercise invalid lists outside the generated PHPDoc contract.
            (new ReflectionMethod($account->variables(), $method))->invoke($account->variables(), $names);
            self::fail('Malformed repeated names must fail before dispatch.');
        } catch (InvalidArgumentException) {
            self::assertNull($account->responseMeta());
        } catch (TypeError) {
            self::fail('List validation must run after the metadata reset.');
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidRepeatedNames(): iterable
    {
        foreach (['getServerInformation', 'getUserInformation'] as $method) {
            foreach ([[], [1 => 'domain'], ['domain', false], [0], [null], [['domain']]] as $index => $names) {
                yield "$method $index" => [$method, $names];
            }
        }
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
            ['AccountEnhancements', 'has_enhancement'], ['AccountEnhancements', 'list'],
            ['EA4', 'get_php_recommendations'], ['EA4', 'get_recommendations'],
            ['ServerInformation', 'get_information'], ['Variables', 'get_server_information'],
            ['Variables', 'get_session_information'], ['Variables', 'get_user_information'],
        ];
    }

    private static function invoke(UapiClient $account, string $module, string $function): mixed
    {
        return match ($module . '/' . $function) {
            'AccountEnhancements/has_enhancement' => $account->accountEnhancements()->hasEnhancement(id: 'a +&=%/雪'),
            'AccountEnhancements/list' => $account->accountEnhancements()->listEnhancements(),
            'EA4/get_php_recommendations' => $account->ea4()->getPhpRecommendations(),
            'EA4/get_recommendations' => $account->ea4()->getRecommendations(),
            'ServerInformation/get_information' => $account->serverInformation()->getInformation(),
            'Variables/get_server_information' => $account->variables()->getServerInformation(),
            'Variables/get_session_information' => $account->variables()->getSessionInformation(),
            default => $account->variables()->getUserInformation(),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $module,
        string $function,
        string $parameters = '',
    ): void {
        if ($function === 'has_enhancement') {
            $parameters = 'id=a%20%2B%26%3D%25%2F%E9%9B%AA';
        }
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        if ($parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}';
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

    /** @return list<array{string, string, string}> */
    private static function variableFields(): array
    {
        // Literal reviewed field families; never derive expectations from generated specs.
        $server = [
            'nullable-flag' => 'allow_park_subdomain_on_hostname allow_remote_domains allow_reset_password '
                . 'allow_reset_password_for_subaccounts allow_unregistered_domains api_shell awstats_browser_update '
                . 'awstats_reverse_dns database_prefix_required disable_analog disable_awstats disable_boxtrapper '
                . 'disable_mailman disable_roundcube disable_spamassassin disable_spambox disable_webalizer '
                . 'display_cpanel_doclinks docroots_in_public_html_only enable_file_protect file_usage invite_sub '
                . 'ipv6_listen is_mod_userdir_enabled php_open_basedir_home phpmyadmin_disable_search_info_schema '
                . 'require_ssl skip_apache_clients_optimizer skip_bandwidth_limit_check skip_mailbox_warnings_check '
                . 'use_information_schema use_mail_for_mailman_url',
            'nullable-int' => 'file_upload_maximum_bytes file_upload_must_leave_bytes htaccess_check_recurse '
                . 'minimum_password_strength minimum_password_strength_mysql php_maximum_execution_time '
                . 'php_post_maximum_size php_upload_maximum_filesize',
            'nullable-string' => 'apache_port apache_ssl_port cpanel_root_directory default_theme dnsadmin_app '
                . 'email_filter_storage_directory empty_trash_days ftp_server local_nameserver_type '
                . 'logout_redirect_url '
                . 'mail_server mailbox_storage_format mysql_host mysql_version php_loader php_system_default_version '
                . 'ssl_default_key_type version',
        ];
        $user = [
            'flag' => 'backup_enabled cgi_enabled demo_mode dkim_enabled legacy_backup_enabled '
                . 'notify_account_authn_link notify_account_authn_link_notification_disabled notify_account_login '
                . 'notify_account_login_for_known_netblock notify_account_login_notification_disabled '
                . 'notify_autossl_expiry notify_autossl_expiry_coverage notify_autossl_renewal_coverage '
                . 'notify_autossl_renewal_coverage_reduced notify_autossl_renewal_uncovered_domains '
                . 'notify_bandwidth_limit '
                . 'notify_contact_address_change notify_contact_address_change_notification_disabled notify_disk_limit '
                . 'notify_password_change notify_password_change_notification_disabled notify_ssl_expiry '
                . 'notify_twofactorauth_change notify_twofactorauth_change_notification_disabled '
                . 'spf_enabled utf8_mailbox',
            'int' => 'bandwidth_limit created disk_block_limit gid last_modified uid',
            'nullable-string' => 'contact_email contact_email_2',
            'string' => 'cpanel_root_directory created_in_version database_owner domain '
                . 'feature_list home ip lang locale mailbox_format owner plan pushbullet_access_token shell '
                . 'ssl_default_key_type theme user',
            'limit' => 'maximum_addon_domains maximum_databases maximum_defer_fail_percentage '
                . 'maximum_email_account_disk_quota maximum_emails_per_hour maximum_ftp_accounts maximum_mail_accounts '
                . 'maximum_mailing_lists maximum_parked_domains maximum_passenger_apps maximum_subdomains',
            'list' => 'dead_domains domains package_extensions',
        ];
        $fields = [];
        foreach (['get_server_information' => $server, 'get_user_information' => $user] as $function => $families) {
            foreach ($families as $type => $names) {
                foreach (explode(' ', $names) as $name) {
                    $fields[] = [$function, $name, $type];
                }
            }
        }
        return $fields;
    }
}
