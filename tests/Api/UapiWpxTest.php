<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Wpx;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/**
 * Literal fixtures independently authored from pinned WPX contracts, not generated specs.
 *
 * @phpstan-type WpxCall Closure(Wpx): mixed
 */
final class UapiWpxTest extends TestCase
{
    public function testWpxInventoryUsesRealRunnerAndCachedAccessor(): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('npr');
        self::assertInstanceOf(Wpx::class, $account->wpx());
        self::assertSame($account->wpx(), $account->wpx());
        self::assertCount(0, $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('direct', '{"domains":[]}')));
        self::assertSame(['domains' => []], $account->wpx()->listWebsites());
    }

    /** @return array<string, array{string, WpxCall, string, string}> */
    private static function operations(): array
    {
        return [
            'change_admin_password' => ['POST', static fn (Wpx $c): mixed => $c->changeAdminPassword(
                id: 0,
                password: 'p a+&=%雪',
                login: '',
            ), 'id=0&password=p%20a%2B%26%3D%25%E9%9B%AA&login=', '{"changed":1,"id":0,"login":""}'],
            'create_nova_for_wordpress_website' => ['POST',
                static fn (Wpx $c): mixed => $c->createNovaForWordPressWebsite(
                    domain: 'MiXeD.Example',
                    colorScheme: '',
                    promptToken: 'a+/=-_',
                    returnUrl: 'https://PaNel.Example:2083/a?x=+&y=%2F',
                ), 'domain=MiXeD.Example&colorScheme=&promptToken=a%2B%2F%3D-_' .
                '&return_url=https%3A%2F%2FPaNel.Example%3A2083%2Fa%3Fx%3D%2B%26y%3D%252F',
                '{"domain":"mixed.example","magic_link":"https://never-fetch.example/magic/+token"}'],
            'create_website' => ['POST',
                static fn (Wpx $c): mixed => $c->createWebsite(domain: 'MiXeD.Example', title: ''),
                'domain=MiXeD.Example&title=', self::running()],
            'get_admin_account' => ['GET', static fn (Wpx $c): mixed => $c->getAdminAccount(id: 0), 'id=0',
                '{"available_logins":["Admin","second"],"current_display_name":"Name","current_email":"a@example.test",'
                    . '"current_login":"Admin","current_user_id":7,"login_url":"https://never-fetch.example/login"}'],
            'get_credentials' => ['POST', static fn (Wpx $c): mixed => $c->getCredentials(
                id: 0,
                panelReturnUrl: 'https://PaNel.Example:2083/a?x=+&y=%2F',
            ), 'id=0&panel_return_url=https%3A%2F%2FPaNel.Example%3A2083%2Fa%3Fx%3D%2B%26y%3D%252F',
                '{"login":"","login_url":"https://never-fetch.example/login?token=private+opaque","password":""}'],
            'get_site_activity' => ['GET', static fn (Wpx $c): mixed => $c->getSiteActivity(id: 0), 'id=0',
                '{"authored_content":0,"changed_appearance":0,"extended_functionality":0,"has_user_activity":0,'
                    . '"personalized_identity":0,"uploaded_media":0}'],
            'get_task_progress' => ['GET', static fn (Wpx $c): mixed => $c->getTaskProgress(installId: 'run /+&=%'),
                'install_id=run%20%2F%2B%26%3D%25', '{"message":"","percentage":0,"status":"unknown"}'],
            'get_task_status' => ['GET', static fn (Wpx $c): mixed => $c->getTaskStatus(taskId: 'queue /+&=%'),
                'task_id=queue%20%2F%2B%26%3D%25', '{"message":"not found","status":"done"}'],
            'get_website' => ['GET', static fn (Wpx $c): mixed => $c->getWebsite(id: 0), 'id=0', self::website()],
            'has_reached_quota' => ['GET', static fn (Wpx $c): mixed => $c->hasReachedQuota(), '',
                '{"has_reached_quota":0}'],
            'import_website' => ['POST', static fn (Wpx $c): mixed => self::invoke($c, 'importWebsite', [
                'sourceUrl' => 'https://MiXeD.Example/a?x=+&y=%2F', 'sourceUser' => 'U +&',
                'sourcePassword' => 'p a+&=%雪', 'domain' => 'DeSt.Example', 'overwrite' => false, 'passwordType' => '',
            ]), 'source_url=https%3A%2F%2FMiXeD.Example%2Fa%3Fx%3D%2B%26y%3D%252F&source_user=U%20%2B%26'
                . '&source_password=p%20a%2B%26%3D%25%E9%9B%AA&domain=DeSt.Example&overwrite=0&password_type=',
                self::running()],
            'list_websites' => ['GET', static fn (Wpx $c): mixed => $c->listWebsites(), '',
                '{"domains":[' . self::website() . ',{}],"info":{"disk_quota_reached":0,"results":2,"total":2}}'],
            'remove_installation' => ['POST', static fn (Wpx $c): mixed => $c->removeInstallation(id: 0),
                'id=0', '{"id":0,"removed":1}'],
            'set_language' => ['POST', static fn (Wpx $c): mixed => $c->setLanguage(id: 0, language: ''),
                'id=0&language=', '{"id":0,"language":""}'],
        ];
    }

    private static function website(): string
    {
        return '{"admin_url":"https://never-fetch.example/admin","alive":1,"broken":0,"documentroot":"/home/npr/A +%",'
            . '"domain":"mixed.example","infected":0,"outdated_php":1,"outdated_wp":0,"title":"",'
            . '"unsupported_php":0,"unsupported_wp":1,"url":"https://never-fetch.example",'
            . '"version":"6.x","wptk_id":null}';
    }

    private static function running(): string
    {
        return '{"domain":"mixed.example","install_id":"run/01","sse_url":"/never-fetch/events",'
            . '"status":"running","task_id":"queue/01"}';
    }

    /** @return iterable<string, array{string, string, string, WpxCall, string, string}> */
    public static function successes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::operations() as $function => [$method, $call, $wire, $payload]) {
                yield "$route $function populated" => [$route, $function, $method, $call, $wire, $payload];
                yield "$route $function omitted fields" => [$route, $function, $method, $call, $wire, '{}'];
            }
            foreach (['create_website', 'import_website', 'create_nova_for_wordpress_website'] as $function) {
                foreach (
                    ['{"domain":"x","existing_installations":[{"id":0,"url":""},{}],"status":"collision"}',
                    '{"existing_installations":[]}', '{"status":"future-state"}'] as $i => $payload
                ) {
                    [$method, $call, $wire] = self::operations()[$function];
                    yield "$route $function collision $i" => [$route, $function, $method, $call, $wire, $payload];
                }
            }
            foreach (['null', '{"wptk_id":3}', '{}'] as $i => $payload) {
                yield "$route nullable website $i" => [$route, 'get_website', 'GET',
                    static fn (Wpx $c): mixed => $c->getWebsite(17), 'id=17', $payload];
            }
            foreach (['{"domains":[]}', '{"info":{}}', '{"info":{"disk_quota_reached":1}}'] as $i => $payload) {
                yield "$route list variants $i" => [$route, 'list_websites', 'GET',
                    static fn (Wpx $c): mixed => $c->listWebsites(), '', $payload];
            }
            yield "$route no administrator" => [$route, 'get_admin_account', 'GET',
                static fn (Wpx $c): mixed => $c->getAdminAccount(17), 'id=17',
                '{"available_logins":[],"current_display_name":"","current_email":"","current_login":"",'
                    . '"current_user_id":null,"login_url":""}'];
            foreach (['{"login_url":""}', '{"login":"formal string","password":"formal string"}'] as $i => $payload) {
                yield "$route credential strings $i" => [$route, 'get_credentials', 'POST',
                    static fn (Wpx $c): mixed => $c->getCredentials(17), 'id=17', $payload];
            }
            foreach (['complete', 'failed', 'running', 'unknown', 'future'] as $status) {
                yield "$route progress $status" => [$route, 'get_task_progress', 'GET',
                    static fn (Wpx $c): mixed => $c->getTaskProgress('run'), 'install_id=run',
                    '{"message":"retained","percentage":150,"status":"' . $status . '"}'];
            }
            yield "$route queue running" => [$route, 'get_task_status', 'GET',
                static fn (Wpx $c): mixed => $c->getTaskStatus('task'), 'task_id=task',
                '{"message":"running","status":"running"}'];
            yield "$route quota reached" => [$route, 'has_reached_quota', 'GET',
                static fn (Wpx $c): mixed => $c->hasReachedQuota(), '', '{"has_reached_quota":1}'];
            foreach ([null, '', 'Admin'] as $i => $login) {
                yield "$route password optional $i" => [$route, 'change_admin_password', 'POST',
                    static fn (Wpx $c): mixed => $c->changeAdminPassword(-1, '', $login),
                    'id=-1&password=' . ($login === null ? '' : '&login=' . $login), '{"changed":7}'];
            }
            yield "$route create defaults" => [$route, 'create_website', 'POST',
                static fn (Wpx $c): mixed => $c->createWebsite(''), 'domain=', '{}'];
            yield "$route invalid title still dispatches" => [$route, 'create_website', 'POST',
                static fn (Wpx $c): mixed => $c->createWebsite('MiXeD.Example', "a\n+&"),
                'domain=MiXeD.Example&title=a%0A%2B%26', self::running()];
            yield "$route opaque invalid prompt" => [$route, 'create_nova_for_wordpress_website', 'POST',
                static fn (Wpx $c): mixed => self::invoke(
                    $c,
                    'createNovaForWordPressWebsite',
                    ['', 'colorScheme' => 'future', 'promptToken' => 'not base64', 'returnUrl' => 'invalid'],
                ), 'domain=&colorScheme=future&promptToken=not%20base64&return_url=invalid', '{}'];
            yield "$route nova defaults" => [$route, 'create_nova_for_wordpress_website', 'POST',
                static fn (Wpx $c): mixed => $c->createNovaForWordPressWebsite(''), 'domain=', '{}'];
            foreach ([null, false, true] as $i => $overwrite) {
                yield "$route import overwrite $i" => [$route, 'import_website', 'POST',
                    static fn (Wpx $c): mixed => $c->importWebsite('', '', '', '', $overwrite),
                    'source_url=&source_user=&source_password=&domain='
                        . ($overwrite === null ? '' : '&overwrite=' . ($overwrite ? '1' : '0')), '{}'];
            }
            yield "$route supplied enums and patterns remain upstream" => [$route, 'import_website', 'POST',
                static fn (Wpx $c): mixed => self::invoke(
                    $c,
                    'importWebsite',
                    ['not a URL', '', '', '', true, 'custom'],
                ),
                'source_url=not%20a%20URL&source_user=&source_password=&domain=&overwrite=1&password_type=custom',
                '{}'];
            yield "$route language has no new pattern validator" => [$route, 'set_language', 'POST',
                static fn (Wpx $c): mixed => $c->setLanguage(-1, 'not a locale'),
                'id=-1&language=not%20a%20locale', '{}'];
            yield "$route removed singleton stays integer" => [$route, 'remove_installation', 'POST',
                static fn (Wpx $c): mixed => $c->removeInstallation(-1), 'id=-1', '{"removed":7}'];
        }
    }

    /** @param WpxCall $call */
    #[DataProvider('successes')]
    public function testSingleRequestMatchesLiteralWireAndPayload(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $call($account->wpx()));
        self::assertCount(1, $http->getRequests());
        self::assertRequest($http, $route, $function, $method, $wire);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame(['Fixture warning'], $account->responseMeta()->uapiWarnings());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function mismatches(): iterable
    {
        $cases = [
            'get_website' => [null, '"wrong"', '{"alive":"1"}', '{"broken":true}', '{"infected":2}',
                '{"outdated_php":null}', '{"outdated_wp":1.0}', '{"unsupported_php":-1}',
                '{"unsupported_wp":"0"}', '{"wptk_id":"17"}', '{"title":null}', '{"domain":7}'],
            'list_websites' => [null, 'null', '{"domains":[null]}', '{"domains":{}}', '{"domains":[{"wptk_id":"1"}]}',
                '{"info":null}', '{"info":{"results":"0"}}', '{"info":{"total":false}}',
                '{"info":{"disk_quota_reached":"0"}}'],
            'get_admin_account' => ['null', '{"available_logins":[1]}', '{"current_user_id":"1"}',
                '{"current_email":null}', '{"current_login":0}'],
            'get_credentials' => ['null', '{"password":null}', '{"login_url":false}', '{"login":7}'],
            'get_site_activity' => ['null', '{"authored_content":2}', '{"changed_appearance":"0"}',
                '{"extended_functionality":false}', '{"has_user_activity":null}', '{"personalized_identity":1.0}',
                '{"uploaded_media":-1}'],
            'has_reached_quota' => ['null', '{"has_reached_quota":"0"}', '{"has_reached_quota":false}',
                '{"has_reached_quota":2}'],
            'create_website' => ['null', '{"domain":null}', '{"status":1}', '{"install_id":1}', '{"task_id":null}',
                '{"sse_url":false}', '{"existing_installations":[{"id":"1"}]}', '{"existing_installations":[null]}'],
            'import_website' => ['null', '{"existing_installations":[{"url":null}]}', '{"existing_installations":{}}'],
            'create_nova_for_wordpress_website' => ['null', '{"magic_link":null}',
                '{"existing_installations":[{"id":false}]}'],
            'get_task_progress' => ['null', '{"percentage":"0"}', '{"percentage":0.5}', '{"status":null}',
                '{"message":7}'],
            'get_task_status' => ['null', '{"status":1}', '{"message":null}'],
            'change_admin_password' => ['null', '{"changed":"1"}', '{"id":null}', '{"login":false}'],
            'remove_installation' => ['null', '{"removed":true}', '{"id":"1"}'],
            'set_language' => ['null', '{"language":null}', '{"id":1.5}'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $function => $payloads) {
                foreach ($payloads as $i => $payload) {
                    yield "$route $function $i" => [$route, $function, $payload];
                }
            }
            foreach (array_keys(self::operations()) as $function) {
                yield "$route $function absent" => [$route, $function, null];
            }
        }
    }

    #[DataProvider('mismatches')]
    public function testKnownTypesAndAbsentPayloadsFailWithoutCoercion(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        try {
            self::operations()[$function][1]($account->wpx());
            self::fail('Wrong known type or absent payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
            self::assertSame(['Fixture warning'], $account->responseMeta()->uapiWarnings());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testTokenCallsReturnEachOpaqueResponseWithoutCachingOrFollowingLinks(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $group = $client->uapi('npr')->wpx();
            foreach (['first/+?token=%2F', 'second/_?token=+'] as $opaque) {
                $http->addResponse(new Response(200, [], self::envelope(
                    $route,
                    '{"login_url":"' . $opaque . '"}',
                )));
                self::assertSame(['login_url' => $opaque], $group->getCredentials(17));
            }
            self::assertCount(2, $http->getRequests());
            self::assertRequest($http, $route, 'get_credentials', 'POST', 'id=17');
        }
    }

    public function testSiblingFieldsAreFilteredWithoutInventingContracts(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $group = $client->uapi('npr')->wpx();
            foreach (
                [
                ['get_admin_account', '{"language":"invented","password":"invented"}', []],
                ['create_nova_for_wordpress_website',
                    '{"install_id":"invented","task_id":"invented","sse_url":"invented"}', []],
                ['create_website', '{"magic_link":"invented","status":"running"}', ['status' => 'running']],
                ['get_website', '{"current_user_id":1,"id":7,"wptk_id":null}', ['wptk_id' => null]],
                ['list_websites', '{"domains":[{"id":7,"wptk_id":1}],"info":{"quota":1}}',
                    ['domains' => [['wptk_id' => 1]], 'info' => []]],
                ] as [$function, $payload, $expected]
            ) {
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                self::assertSame($expected, self::operations()[$function][1]($group));
            }
            self::assertCount(5, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (array_keys(self::operations()) as $function) {
                foreach (['api', 'http', 'json', 'transport', 'payload'] as $failure) {
                    yield "$route $function $failure" => [$route, $function, $failure];
                }
                if ($route === 'bridge') {
                    yield "$route $function outer" => [$route, $function, 'outer'];
                }
            }
        }
    }

    #[DataProvider('failures')]
    public function testFailureOrderingAndMetadata(string $route, string $function, string $failure): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->wpx()->hasReachedQuota();
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $body = match ($failure) {
                'api' => self::envelope($route, '{"status":"running"}', 0),
                'http' => self::envelope($route, '"bad"', 0),
                'json' => 'not JSON',
                'outer' => '{"metadata":{"result":0,"reason":"Synthetic failure"},'
                    . '"data":{"uapi":{"status":1,"data":{}}}}',
                default => self::envelope($route, '"bad"'),
            };
            $http->addResponse(new Response($failure === 'http' ? 503 : 200, [], $body));
        }
        try {
            self::operations()[$function][1]($account->wpx());
            self::fail('Failure must throw before success is returned.');
        } catch (ApiException $error) {
            self::assertInstanceOf(match ($failure) {
                'api', 'outer' => ApiErrorException::class,
                'payload' => PayloadMismatchException::class,
                'http' => HttpException::class,
                'json' => InvalidResponseException::class,
                default => TransportException::class,
            }, $error);
            if (in_array($failure, ['transport', 'json'], true)) {
                self::assertNull($account->responseMeta());
            } elseif (in_array($failure, ['api', 'http'], true)) {
                self::assertSame(0, $account->responseMeta()?->uapiStatus());
            } elseif ($failure === 'outer') {
                self::assertSame(0, $account->responseMeta()?->whmResult());
            } else {
                self::assertSame(1, $account->responseMeta()?->uapiStatus());
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function privateFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                ['changeAdminPassword', 'createNovaForWordPressWebsite', 'getCredentials', 'importWebsite'] as $method
            ) {
                foreach (['api', 'http', 'json', 'payload', 'transport'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    #[DataProvider('privateFailures')]
    public function testActualRunnerRedactsPrivateInputsAndResponseSnapshots(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $secret = 'private-wpx-marker';
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $http->addResponse(match ($failure) {
                'api' => new Response(200, [], self::envelope($route, '"' . $secret . '"', 0)),
                'http' => new Response(503, [], $secret),
                'json' => new Response(200, [], $secret),
                default => new Response(200, [], self::envelope($route, '"' . $secret . '"')),
            });
        }
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::privateCall($client->uapi('npr')->wpx(), $method, $secret);
            self::fail('Synthetic failure must throw.');
        } catch (ApiException $error) {
            self::assertStringNotContainsString($secret, $error->getMessage());
            self::assertStringNotContainsString($secret, print_r($error, true));
            if ($error instanceof PayloadMismatchException) {
                self::assertSame($secret, $error->payload);
            }
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
                }
            }
            self::assertGreaterThan(0, $frames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    private static function privateCall(Wpx $group, string $method, #[SensitiveParameter] string $secret): mixed
    {
        return match ($method) {
            'changeAdminPassword' => $group->changeAdminPassword(1, $secret, $secret),
            'createNovaForWordPressWebsite' => $group->createNovaForWordPressWebsite(
                'd',
                promptToken: $secret,
                returnUrl: $secret,
            ),
            'getCredentials' => $group->getCredentials(1, $secret),
            default => $group->importWebsite($secret, $secret, $secret, 'd'),
        };
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, string $secret, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $secret);
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (str_contains((string) $key, $secret) || self::hasSecret($item, $secret, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function testNativePublicSignatureOrderRequirednessAndSensitivity(): void
    {
        $signatures = [
            'changeAdminPassword' => ['id', 'password', 'login'],
            'createNovaForWordPressWebsite' => ['domain', 'colorScheme', 'promptToken', 'returnUrl'],
            'createWebsite' => ['domain', 'title'], 'getAdminAccount' => ['id'],
            'getCredentials' => ['id', 'panelReturnUrl'], 'getSiteActivity' => ['id'],
            'getTaskProgress' => ['installId'], 'getTaskStatus' => ['taskId'], 'getWebsite' => ['id'],
            'hasReachedQuota' => [],
            'importWebsite' => ['sourceUrl', 'sourceUser', 'sourcePassword', 'domain', 'overwrite', 'passwordType'],
            'listWebsites' => [], 'removeInstallation' => ['id'], 'setLanguage' => ['id', 'language'],
        ];
        foreach ($signatures as $method => $names) {
            $reflection = new ReflectionMethod(Wpx::class, $method);
            self::assertSame($method === 'getWebsite' ? '?array' : 'array', (string) $reflection->getReturnType());
            self::assertSame($names, array_map(static fn ($p): string => $p->getName(), $reflection->getParameters()));
            $optional = match ($method) {
                'changeAdminPassword' => ['login'],
                'createNovaForWordPressWebsite' => ['colorScheme', 'promptToken', 'returnUrl'],
                'createWebsite' => ['title'], 'getCredentials' => ['panelReturnUrl'],
                'importWebsite' => ['overwrite', 'passwordType'], default => [],
            };
            foreach ($reflection->getParameters() as $p) {
                $name = $p->getName();
                $isOptional = in_array($name, $optional, true);
                self::assertSame($isOptional, $p->isOptional());
                $type = $name === 'id' ? 'int' : ($name === 'overwrite' ? 'bool' : 'string');
                self::assertSame(($isOptional ? '?' : '') . $type, (string) $p->getType());
                if ($isOptional) {
                    self::assertNull($p->getDefaultValue());
                }
                self::assertCount(
                    in_array($name, ['password', 'login', 'promptToken', 'returnUrl', 'panelReturnUrl',
                        'sourceUrl', 'sourceUser', 'sourcePassword', 'passwordType'], true) ? 1 : 0,
                    $p->getAttributes(SensitiveParameter::class),
                );
            }
        }
    }

    public function testNativeFailuresRetainPriorMetadataAndPerformNoIo(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->wpx()->hasReachedQuota();
            $meta = $account->responseMeta();
            foreach (
                [
                ['changeAdminPassword', [1]], ['changeAdminPassword', ['1', 'p']], ['changeAdminPassword', [1, false]],
                ['createNovaForWordPressWebsite', [null]], ['createWebsite', []], ['getAdminAccount', ['1']],
                ['getCredentials', [1, false]], ['getSiteActivity', [null]], ['getTaskProgress', [1]],
                ['getTaskStatus', [[]]], ['getWebsite', [false]], ['importWebsite', ['s', 'u', 'p']],
                ['importWebsite', ['s', 'u', 'p', 'd', 1]], ['removeInstallation', []], ['setLanguage', [1]],
                ] as [$method, $args]
            ) {
                try {
                    self::invoke($account->wpx(), $method, $args);
                    self::fail('Native boundary must fail before entry.');
                } catch (TypeError) {
                    self::assertSame($meta, $account->responseMeta());
                }
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @param array<int|string, mixed> $arguments */
    private static function invoke(Wpx $group, string $method, #[SensitiveParameter] array $arguments): mixed
    {
        return $group->$method(...$arguments);
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $wire,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=WPX&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/WPX/' . $function;
        if ($method === 'GET' && $wire !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $wire;
        }
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        foreach (
            ['password=', 'source_user=', 'source_password=', 'panel_return_url=', 'promptToken=',
                'return_url='] as $private
        ) {
            self::assertStringNotContainsString($private, (string) $request->getUri());
        }
    }

    private static function envelope(string $route, #[SensitiveParameter] ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Fixture warning"],"messages":null}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : '{"result":' . $result . '}';
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
