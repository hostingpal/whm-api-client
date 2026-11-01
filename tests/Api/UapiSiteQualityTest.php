<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\SiteQuality;
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
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Source-authored synthetic fixtures: no external service, activation email or returned URL is contacted. */
final class UapiSiteQualityTest extends TestCase
{
    public function testAccessorCachesPerAccountWithoutIoAndPreservesAuditedSignatures(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertTrue(
            (new ReflectionClass($account))->hasMethod('siteQuality'),
            'The audited SiteQuality accessor must exist.',
        );
        self::assertSame($account->siteQuality(), $account->siteQuality());
        self::assertNotSame($account->siteQuality(), $client->uapi('other')->siteQuality());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        $signatures = [
            'createProject' => ['name' => 'string', 'url' => 'string',
                'standardAlerting' => '?bool', 'systemType' => '?string'],
            'createSiteQualityUser' => ['email' => 'string', 'password' => '?string'],
            'deleteSiteQualityUser' => [], 'getAppToken' => [], 'hasSiteQualityUser' => [],
            'isSiteQualityUserEnabled' => [], 'resetConfig' => [], 'sendActivationEmail' => [],
            'updateDomain' => ['oldDomain' => 'string', 'newDomain' => 'string'], 'verifyCode' => ['code' => 'string'],
        ];
        foreach ($signatures as $method => $expected) {
            $reflection = new ReflectionMethod(SiteQuality::class, $method);
            self::assertSame('array', (string) $reflection->getReturnType());
            self::assertSame(array_keys($expected), array_map(
                static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                $reflection->getParameters(),
            ));
            foreach ($reflection->getParameters() as $parameter) {
                $name = $parameter->getName();
                self::assertSame($expected[$name] ?? '', (string) $parameter->getType());
                $optional = str_starts_with($expected[$name] ?? '', '?');
                self::assertSame($optional, $parameter->isDefaultValueAvailable());
                self::assertSame(!$optional, !$parameter->isOptional());
                if ($optional) {
                    self::assertNull($parameter->getDefaultValue());
                }
                self::assertCount(
                    in_array($name, ['url', 'password', 'code'], true) ? 1 : 0,
                    $parameter->getAttributes(SensitiveParameter::class),
                );
            }
        }
        foreach (['getMonitoredDomains', 'getMonitoredSystemScores', 'getAllScores'] as $excluded) {
            self::assertFalse(method_exists($account->siteQuality(), $excluded));
        }
    }

    /** @param Closure(SiteQuality): array<mixed> $call */
    #[DataProvider('requests')]
    public function testLiteralRequestsOnBothRoutes(
        string $route,
        Closure $call,
        string $function,
        string $body,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        self::assertTrue(
            (new ReflectionClass($account))->hasMethod('siteQuality'),
            'The audited SiteQuality accessor must exist.',
        );
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::assertSame([], $call($account->siteQuality()));
        self::assertRequest($http, $route, $function, $body);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, Closure(SiteQuality): array<mixed>, string, string}> */
    public static function requests(): iterable
    {
        $cases = [
            'project omitted' => [static fn (SiteQuality $g): array => $g->createProject('A', 'PRIVATE'),
                'create_project', 'name=A&url=PRIVATE'],
            'project named false bytes' => [static fn (SiteQuality $g): array => $g->createProject(
                systemType: 'Other +% š',
                standardAlerting: false,
                url: 'https://example.test/a+b?x=%2F',
                name: 'A +% š',
            ), 'create_project', 'name=A%20%2B%25%20%C5%A1&url=https%3A%2F%2Fexample.test%2Fa%2Bb%3Fx%3D%252F'
                . '&standard_alerting=0&system_type=Other%20%2B%25%20%C5%A1'],
            'project true empty' => [static fn (SiteQuality $g): array => $g->createProject('', '', true, ''),
                'create_project', 'name=&url=&standard_alerting=1&system_type='],
            'project explicit null' => [static fn (SiteQuality $g): array => $g->createProject('', '', null, null),
                'create_project', 'name=&url='],
            'user omitted password' => [static fn (SiteQuality $g): array => $g->createSiteQualityUser(
                'a+b@example.test',
            ),
                'create_site_quality_user', 'email=a%2Bb%40example.test'],
            'user null password' => [static fn (SiteQuality $g): array => $g->createSiteQualityUser('', null),
                'create_site_quality_user', 'email='],
            'user empty password' => [static fn (SiteQuality $g): array => $g->createSiteQualityUser('', ''),
                'create_site_quality_user', 'email=&password='],
            'user named bytes' => [static fn (SiteQuality $g): array => $g->createSiteQualityUser(
                password: 'PRIVATE +% š',
                email: 'a+b@example.test',
            ), 'create_site_quality_user', 'email=a%2Bb%40example.test&password=PRIVATE%20%2B%25%20%C5%A1'],
            'delete' => [static fn (SiteQuality $g): array => $g->deleteSiteQualityUser(),
                'delete_site_quality_user', ''],
            'token' => [static fn (SiteQuality $g): array => $g->getAppToken(), 'get_app_token', ''],
            'has' => [static fn (SiteQuality $g): array => $g->hasSiteQualityUser(), 'has_site_quality_user', ''],
            'enabled' => [static fn (SiteQuality $g): array => $g->isSiteQualityUserEnabled(),
                'is_site_quality_user_enabled', ''],
            'reset' => [static fn (SiteQuality $g): array => $g->resetConfig(), 'reset_config', ''],
            'email explicit' => [static fn (SiteQuality $g): array => $g->sendActivationEmail(),
                'send_activation_email', ''],
            'update named bytes' => [static fn (SiteQuality $g): array => $g->updateDomain(
                newDomain: 'new+% š',
                oldDomain: 'old+% š',
            ), 'update_domain', 'old_domain=old%2B%25%20%C5%A1&new_domain=new%2B%25%20%C5%A1'],
            'update positional' => [static fn (SiteQuality $g): array => $g->updateDomain('old', 'new'),
                'update_domain', 'old_domain=old&new_domain=new'],
            'update empty' => [static fn (SiteQuality $g): array => $g->updateDomain('', ''),
                'update_domain', 'old_domain=&new_domain='],
            'code bytes' => [static fn (SiteQuality $g): array => $g->verifyCode(code: 'PRIVATE +% š'),
                'verify_code', 'code=PRIVATE%20%2B%25%20%C5%A1'],
            'code empty' => [static fn (SiteQuality $g): array => $g->verifyCode(''), 'verify_code', 'code='],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => $case) {
                yield "$route $name" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('payloads')]
    public function testSourceAuthoredPayloadPresenceAndTypes(
        string $route,
        string $method,
        ?string $json,
        bool $valid,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $json)));
        $account = $client->uapi('fixture');
        if (!$valid) {
            $this->expectException(PayloadMismatchException::class);
        }
        try {
            $actual = self::invoke($account->siteQuality(), $method);
            self::assertSame(json_decode($json ?? 'null', true, 512, JSON_THROW_ON_ERROR), $actual);
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
    }

    /** @return iterable<string, array{string, string, ?string, bool}> */
    public static function payloads(): iterable
    {
        $project = '{"id":0,"identifier":"p","location":"region","name":"project",'
            . '"role":{"id":0,"name":"Owner"},"systems":[{"description":null,'
            . '"domain":"https://example.test","id":1,"interval":"hour","limits":{"maximumCrawlDepth":0},'
            . '"name":"site","system_type":{"fixedComponents":false,"id":{"untyped":[null,false,"x"]},'
            . '"name":"Open template"}},{}]}';
        $cases = [
            ['createProject', $project, true],
            ['createProject', '{"systems":[]}', true],
            ['createProject', '{"systems":[{"system_type":{"fixedComponents":true}}]}', true],
            ['createProject', '{"role":{},"systems":[{"description":"","limits":{},"system_type":{}}]}', true],
            ['createSiteQualityUser', '{"app_token":"PRIVATE","enabled":true,"username":"a"}', true],
            ['createSiteQualityUser', '{"enabled":false}', true],
            ['createSiteQualityUser', '{"enabled":null}', true],
            ['updateDomain', '{"system":{"id":0,"name":"new","system_size":{"id":0,"name":"plan"},'
                . '"url":"https://example.test"}}', true],
            ['updateDomain', '{"system":{"system_size":{}}}', true],
        ];
        foreach ([null, false, 0, 1.25, 'untyped', [], ['k' => 'v']] as $id) {
            $cases[] = ['createProject', json_encode(
                ['systems' => [['system_type' => ['id' => $id]]]],
                JSON_THROW_ON_ERROR,
            ), true];
        }
        foreach (
            [
            ['getAppToken', 'app_token'], ['createSiteQualityUser', 'app_token'],
            ['createSiteQualityUser', 'username']] as [$method, $field]
        ) {
            $cases[] = [$method, '{"' . $field . '":""}', true];
            foreach (['null', '1', 'false', '[]', '{}'] as $wrong) {
                $cases[] = [$method, '{"' . $field . '":' . $wrong . '}', false];
            }
        }
        foreach (self::flagMethods() as $method => $field) {
            foreach ([0, 1] as $flag) {
                $cases[] = [$method, '{"' . $field . '":' . $flag . '}', true];
            }
            foreach (['null', 'true', 'false', '"0"', '"1"', '2', '0.5', '[]', '{}'] as $wrong) {
                $cases[] = [$method, '{"' . $field . '":' . $wrong . '}', false];
            }
        }
        foreach (['0', '1', '"true"', '[]', '{}'] as $wrong) {
            $cases[] = ['createSiteQualityUser', '{"enabled":' . $wrong . '}', false];
        }
        foreach (
            [
            '{"id":"1"}', '{"identifier":null}', '{"location":1}', '{"name":false}', '{"role":null}',
            '{"role":{"id":"0"}}', '{"role":{"name":0}}', '{"systems":null}', '{"systems":{}}',
            '{"systems":[null]}', '{"systems":[{"name":0}]}', '{"systems":[{"description":0}]}',
            '{"systems":[{"domain":null}]}', '{"systems":[{"id":"1"}]}', '{"systems":[{"interval":0}]}',
            '{"systems":[{"limits":null}]}', '{"systems":[{"limits":{"maximumCrawlDepth":"0"}}]}',
            '{"systems":[{"system_type":null}]}', '{"systems":[{"system_type":{"name":0}}]}',
            '{"systems":[{"system_type":{"fixedComponents":1}}]}',
            '{"systems":[{"system_type":{"fixedComponents":null}}]}',
            ] as $wrong
        ) {
            $cases[] = ['createProject', $wrong, false];
        }
        foreach (
            [
            '{"system":null}', '{"system":{"id":"0"}}', '{"system":{"name":null}}',
            '{"system":{"url":false}}', '{"system":{"system_size":null}}',
            '{"system":{"system_size":{"id":"0"}}}', '{"system":{"system_size":{"name":1}}}',
            ] as $wrong
        ) {
            $cases[] = ['updateDomain', $wrong, false];
        }
        foreach (self::methods() as $method) {
            foreach (['{}', '[]'] as $empty) {
                $cases[] = [$method, $empty, true];
            }
            foreach ([null, 'null', '0', 'false', '"payload"', '[{}]'] as $wrong) {
                $cases[] = [$method, $wrong, false];
            }
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('outcomes')]
    public function testKnownFieldObjectsDoNotBecomeOpaque(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{"undocumented":"PRIVATE"}')));
        self::assertSame([], self::invoke($client->uapi('fixture')->siteQuality(), $method));
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testTokenBearingObjectMismatchesProtectActualLibraryFrames(string $route): void
    {
        foreach (['getAppToken', 'createSiteQualityUser'] as $method) {
            [$client, $http] = self::client($route);
            $http->addResponse(new Response(200, [], self::envelope($route, '{"app_token":["PRIVATE"]}')));
            $old = ini_set('zend.exception_ignore_args', '0');
            try {
                self::invoke($client->uapi('fixture')->siteQuality(), $method);
                self::fail('A non-string token must mismatch without echoing it.');
            } catch (PayloadMismatchException $error) {
                self::assertSame(['app_token' => ['PRIVATE']], $error->payload);
                self::assertStringNotContainsString('PRIVATE', $error->getMessage());
                $frames = 0;
                foreach ($error->getTrace() as $frame) {
                    $class = $frame['class'] ?? '';
                    if (
                        str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                        && !str_starts_with($class, __NAMESPACE__ . '\\')
                    ) {
                        $frames++;
                        $seen = [];
                        self::assertFalse(self::hasSecret($frame['args'] ?? [], $seen));
                    }
                }
                self::assertGreaterThan(0, $frames);
                self::assertTrue($error->outcomeConfirmed);
            } finally {
                if ($old !== false) {
                    ini_set('zend.exception_ignore_args', $old);
                }
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return array<string, string> */
    private static function flagMethods(): array
    {
        return ['deleteSiteQualityUser' => 'deleted', 'hasSiteQualityUser' => 'has_site_quality_user',
            'isSiteQualityUserEnabled' => 'enabled', 'resetConfig' => 'reset',
            'sendActivationEmail' => 'send_activation_email', 'verifyCode' => 'status'];
    }

    #[DataProvider('routes')]
    public function testNativeTypeErrorsRetainPriorMetadataBeforeEntry(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->siteQuality()->getAppToken();
        $before = $account->responseMeta();
        foreach (
            [
            ['createProject', [[], '']], ['createProject', ['', []]],
            ['createProject', ['', '', []]], ['createProject', ['', '', false, []]],
            ['createSiteQualityUser', [[], '']], ['createSiteQualityUser', ['', []]],
            ['updateDomain', [[], '']], ['updateDomain', ['', []]], ['verifyCode', [[]]],
            ['createProject', []], ['createSiteQualityUser', []], ['updateDomain', ['old']], ['verifyCode', []],
            ] as [$method, $arguments]
        ) {
            try {
                (new ReflectionMethod(SiteQuality::class, $method))->invokeArgs($account->siteQuality(), $arguments);
                self::fail('Wrong scalar types or missing arguments must fail before entry.');
            } catch (TypeError) {
                self::assertSame($before, $account->responseMeta());
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    #[DataProvider('outcomes')]
    public function testEnvelopeFailurePrecedesValidPayloadAndZeroPayloadRemainsResult(
        string $route,
        string $method,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $field = self::flagMethods()[$method] ?? null;
        $payload = $field === null ? '{}' : '{"' . $field . '":0}';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 0)));
        try {
            self::invoke($account->siteQuality(), $method);
            self::fail('UAPI status zero must reject even a valid payload.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        $before = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        self::assertSame($field === null ? [] : [$field => 0], self::invoke($account->siteQuality(), $method));
        self::assertNotSame($before, $account->responseMeta());
        self::assertSame(['Synthetic warning'], $account->responseMeta()?->uapiWarnings());
        self::assertSame(['synthetic_warning' => true], $account->responseMeta()->uapi['metadata'] ?? null);
        self::assertArrayNotHasKey('data', $account->responseMeta()->uapi);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function outcomes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::methods() as $method) {
                yield "$route $method" => [$route, $method];
            }
        }
    }

    #[DataProvider('failures')]
    public function testRunnerFailuresResetMetadataPreservePrecedenceAndHideSensitiveFrames(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->siteQuality()->getAppToken();
        $previous = $account->responseMeta();
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $http->addResponse(match ($failure) {
                'http' => new Response(503, [], 'PRIVATE not json'),
                'json' => new Response(200, [], 'PRIVATE not json'),
                'api' => new Response(200, [], self::envelope($route, '{"app_token":"PRIVATE"}', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic outer failure"},'
                    . '"data":{"uapi":{"status":0,"data":{"app_token":"PRIVATE"}}}}'),
                default => new Response(200, [], self::envelope($route, '"PRIVATE token-bearing response"')),
            });
        }
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($account->siteQuality(), $method);
            self::fail('Failure must throw without a retry or fallback.');
        } catch (ApiException $error) {
            $expected = match ($failure) {
                'transport' => TransportException::class,
                'http' => HttpException::class,
                'json' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class,
                default => PayloadMismatchException::class,
            };
            self::assertInstanceOf($expected, $error);
            if (in_array($failure, ['transport', 'http', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotSame($previous, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                if ($failure === 'outer') {
                    self::assertStringContainsString('WHM', $error->getMessage());
                }
                self::assertSame(in_array($failure, ['api', 'outer'], true) ? 0 : 1, $error->metadata?->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' ? 1 : null),
                    $error->metadata->whmResult(),
                );
            }
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($class, __NAMESPACE__ . '\\')
                ) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(
                        self::hasSecret($frame['args'] ?? [], $seen),
                        $frame['class'] . '::' . $frame['function'],
                    );
                }
            }
            self::assertGreaterThan(0, $frames);
            if ($error instanceof PayloadMismatchException) {
                self::assertSame('PRIVATE token-bearing response', $error->payload);
                self::assertTrue($error->outcomeConfirmed);
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::methods() as $method) {
                $failures = ['transport', 'http', 'json', 'api', 'payload', ...($route === 'bridge' ? ['outer'] : [])];
                foreach ($failures as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    /** @return list<string> */
    private static function methods(): array
    {
        return ['createProject', 'createSiteQualityUser', 'deleteSiteQualityUser', 'getAppToken',
            'hasSiteQualityUser', 'isSiteQualityUserEnabled', 'resetConfig', 'sendActivationEmail',
            'updateDomain', 'verifyCode'];
    }

    /** @return array<mixed> */
    private static function invoke(SiteQuality $group, string $method): array
    {
        return match ($method) {
            'createProject' => $group->createProject('Fixture', 'PRIVATE'),
            'createSiteQualityUser' => $group->createSiteQualityUser('fixture@example.test', 'PRIVATE'),
            'updateDomain' => $group->updateDomain('old.example.test', 'new.example.test'),
            'verifyCode' => $group->verifyCode('PRIVATE'),
            'deleteSiteQualityUser' => $group->deleteSiteQualityUser(),
            'getAppToken' => $group->getAppToken(),
            'hasSiteQualityUser' => $group->hasSiteQualityUser(),
            'isSiteQualityUserEnabled' => $group->isSiteQualityUserEnabled(),
            'resetConfig' => $group->resetConfig(),
            'sendActivationEmail' => $group->sendActivationEmail(),
            default => throw new RuntimeException('Unknown test method.'),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $function, string $body): void
    {
        $post = !in_array($function, ['get_app_token', 'has_site_quality_user', 'is_site_quality_user_enabled'], true);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=SiteQuality&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/SiteQuality/' . $function, (string) $request->getUri());
        self::assertSame($post ? $body : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame($post ? 'application/x-www-form-urlencoded' : '', $request->getHeaderLine('Content-Type'));
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'PRIVATE');
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
                if (str_contains((string) $key, 'PRIVATE') || self::hasSecret($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function envelope(string $route, ?string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["PRIVATE failure"]')
            . ',"messages":null,"warnings":' . ($warning ? '["Synthetic warning"]' : 'null')
            . ',"metadata":' . ($warning ? '{"synthetic_warning":true}' : '{}') . '}';
        return $route === 'bridge' ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'fixture', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
