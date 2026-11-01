<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Nova;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

/** Independent literal fixtures from the pinned Nova source; no generated metadata drives assertions. */
final class UapiNovaTest extends TestCase
{
    #[DataProvider('operations')]
    public function testEveryPinnedOperationOnBothRoutes(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $group = $account->nova();
        self::assertSame($group, $account->nova());
        self::assertSame([], $http->getRequests());
        [$method, $args, $verb, $parameters, $payload] = self::fixture($function);
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        self::assertSame(
            self::orderedObjects(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)),
            self::orderedObjects($group->$method(...$args)),
        );
        self::assertRequest($http, $route, $function, $verb, $parameters);
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
        self::assertSame(['Synthetic builder notice'], $meta->uapiWarnings());
        self::assertArrayNotHasKey('data', $meta->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedRootsLeavesAndAbsentDataFailWithoutRetry(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        [$method, $args, , , $good] = self::fixture($function);
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $good)));
        $group = $account->nova();
        $group->$method(...$args);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        try {
            $group->$method(...$args);
            self::fail('Malformed or absent payload must be rejected.');
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

    #[DataProvider('operations')]
    public function testApiAndHttpFailuresClearPriorMetadataAndNeverRetry(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $group = $account->nova();
        [$method, $args, , , $payload] = self::fixture($function);
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        $group->$method(...$args);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $function, null, 0)));
        try {
            $group->$method(...$args);
            self::fail('UAPI status zero must fail.');
        } catch (ApiErrorException) {
            self::assertNotSame($previous, $account->responseMeta());
            self::assertSame(0, $account->responseMeta()?->uapiStatus());
        }
        $http->addResponse(new Response(503, [], '{}'));
        try {
            $group->$method(...$args);
            self::fail('HTTP failure must fail.');
        } catch (HttpException) {
            self::assertNull($account->responseMeta());
        }
        self::assertCount(3, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::functions() as $function) {
            [, , , , , $kind] = self::fixture($function);
            $bad = match ($kind) {
                'flag' => [null, 'null', 'true', '"1"', '2', '{}', '[]'],
                'string' => [null, 'null', '1', 'true', '{}', '[]'],
                'list' => [null, 'null', 'true', '1', '"bad"', '{}', '[null]', '[1]', '[{"metadata":false}]'],
                'opaque' => [null, 'true', '1', '"bad"', '[1]'],
                default => [null, 'null', 'true', '1', '"bad"', '[{}]'],
            };
            foreach (self::routes() as [$route]) {
                foreach ($bad as $index => $payload) {
                    yield $route . ' ' . $function . ' root ' . $index => [$route, $function, $payload];
                }
                foreach (self::badLeaves($function) as $index => $payload) {
                    yield $route . ' ' . $function . ' leaf ' . $index => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('operations')]
    public function testEmptyOptionalObjectsOpaqueFieldsAndZeroFlagsStayDistinct(string $route, string $function): void
    {
        [, , , , , $kind] = self::fixture($function);
        $cases = match ($kind) {
            'flag' => ['0', '1'],
            'string' => ['""', '"0"', '"https://builder.test/?ssotoken=private"'],
            'list' => ['[]', '[{}]', '[{"metadata":{},"quota":{},"status":{}}]'],
            'opaque' => ['null', '{}', '[]', '{"future":{"nested":[0,false,null]},"text":"0"}'],
            default => ['{}', '[]'],
        };
        [$client, $http] = self::client($route);
        $group = $client->uapi('exampleuser')->nova();
        [$method, $args] = self::fixture($function);
        foreach ($cases as $payload) {
            $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
            self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $group->$method(...$args));
        }
        self::assertCount(count($cases), $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testOptionalInputsAreOmittedAndExplicitZeroIsSent(string $route): void
    {
        [$client, $http] = self::client($route);
        $group = $client->uapi('exampleuser')->nova();
        foreach (
            ['disk_quota_check' => 'diskQuotaCheck', 'ensure_account' => 'ensureAccount',
            'get_all_user_nova_info' => 'getAllUserNovaInfo', 'get_sso_link' => 'getSsoLink'] as $function => $method
        ) {
            [, , $verb, , $payload] = self::fixture($function);
            $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
            $group->$method();
            self::assertRequest($http, $route, $function, $verb, '');
        }
        $http->addResponse(new Response(200, [], self::envelope($route, 'publish', '{}')));
        $group->publish('https://builder.test/a.zip', 'example.test', 'signature', 'nova_0');
        self::assertRequest(
            $http,
            $route,
            'publish',
            'POST',
            'archiveUrl=https%3A%2F%2Fbuilder.test%2Fa.zip&domain=example.test&signature=signature&userId=nova_0',
        );
    }

    #[DataProvider('routes')]
    public function testCleanupBooleanEncodingAndStrictRejectionBeforeIo(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $group = $account->nova();
        $args = ['archiveUrl' => 'archive', 'domain' => 'example.test',
            'signature' => 'signature', 'userId' => 'id'];
        foreach ([[], ['cleanup' => null], ['cleanup' => false], ['cleanup' => true]] as $index => $option) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'publish', '{}')));
            $group->publish(...array_merge($args, $option));
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            parse_str((string) $request->getBody(), $wire);
            if ($index < 2) {
                self::assertArrayNotHasKey('cleanup', $wire);
            } else {
                self::assertSame($index === 2 ? '0' : '1', $wire['cleanup']);
            }
        }
        $metadata = $account->responseMeta();
        // The independent fixture supplies a dynamic method for deliberate invalid strict calls.
        [$method] = self::fixture('publish');
        foreach ([0, 1, 2, -1, '0', 'false', [], new \stdClass()] as $bad) {
            try {
                $group->$method(...array_merge($args, ['cleanup' => $bad]));
                self::fail('Strict callers must reject nonboolean cleanup before I/O.');
            } catch (\TypeError) {
                self::assertSame($metadata, $account->responseMeta());
            }
        }
        self::assertCount(4, $http->getRequests());
    }

    public function testSensitiveInputsHaveSensitiveParameterAttributes(): void
    {
        $sensitive = ['addApiToken' => ['apiToken'],
            'ensureAccount' => ['promptToken', 'returnUrl'],
            'getSsoLink' => ['promptToken', 'returnUrl'],
            'publish' => ['archiveUrl', 'signature']];
        foreach ($sensitive as $method => $names) {
            $parameters = (new ReflectionMethod(Nova::class, $method))->getParameters();
            foreach ($names as $name) {
                $found = false;
                foreach ($parameters as $parameter) {
                    if ($parameter->getName() === $name) {
                        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                        $found = true;
                    }
                }
                self::assertTrue($found, $method . ' has sensitive parameter ' . $name);
            }
        }
    }


    #[DataProvider('routes')]
    public function testSelectorsKeepAccountMetadataIsolatedAndDoNotDispatch(string $route): void
    {
        [$client, $http] = self::client($route);
        $first = $client->uapi('exampleuser');
        [$otherClient] = self::client($route);
        $second = $route === 'bridge' ? $client->uapi('other') : $otherClient->uapi('exampleuser');
        self::assertNotSame($first->nova(), $second->nova());
        $http->addResponse(new Response(200, [], self::envelope($route, 'can_create_domains', '0')));
        self::assertSame(0, $first->nova()->canCreateDomains());
        $metadata = $first->responseMeta();
        self::assertNotNull($metadata);
        $first->nova();
        $second->nova();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($second->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testUnknownFieldsAreDroppedWithoutDroppingKnownSiblings(string $route): void
    {
        [$client, $http] = self::client($route);
        $function = 'get_all_user_nova_info';
        $payload = '[{"domain":"example.test","future":1,"metadata":{"company":"Example","future":false},'
            . '"quota":{"available_space":0,"future":1},"status":{"ssl_status":0,"future":null}},{}]';
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        $group = $client->uapi('exampleuser')->nova();
        self::assertSame([['domain' => 'example.test', 'metadata' => ['company' => 'Example'],
            'quota' => ['available_space' => 0], 'status' => ['ssl_status' => 0]], []], $group->getAllUserNovaInfo());
        self::assertCount(1, $http->getRequests());
    }

    /** JSON object key order is not semantic; scalar types and list order stay strict. */
    private static function orderedObjects(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::orderedObjects($item);
        }

        return $value;
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::functions() as $function) {
                yield $route . ' ' . $function => [$route, $function];
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

    /** @return list<string> */
    private static function functions(): array
    {
        return ['account_exists',
            'add_api_token',
            'can_create_domains',
            'create_account',
            'disk_quota_check',
            'ensure_account',
            'get_all_user_nova_info',
            'get_sso_link',
            'get_tier',
            'poll_publish',
            'publish'];
    }

    /** @return array{string, array<string, string|int|bool>, string, string, string, string} */
    public static function fixture(string $function): array
    {
        return match ($function) {
            'account_exists' => ['accountExists', [], 'GET', '', '1', 'flag'],
            'add_api_token' => ['addApiToken', ['apiToken' => 'private+&=%'], 'POST',
                'api_token=private%2B%26%3D%25', 'null', 'opaque'],
            'can_create_domains' => ['canCreateDomains', [], 'GET', '', '0', 'flag'],
            'create_account' => ['createAccount', [], 'POST', '', '{"key":"private-token"}', 'object'],
            'disk_quota_check' => ['diskQuotaCheck', ['domain' => 'example.test'], 'GET', 'domain=example.test',
                '{"available_space":1024,"can_backup":0,"is_docroot_empty":1,"required_space":64}', 'object'],
            'ensure_account' => ['ensureAccount', ['colorScheme' => 'dark', 'domain' => 'example.test',
                'promptToken' => 'cHJvbXB0+&=%', 'returnUrl' => 'https://panel.test/route#x'], 'POST',
                'colorScheme=dark&domain=example.test&promptToken=cHJvbXB0%2B%26%3D%25'
                . '&return_url=https%3A%2F%2Fpanel.test%2Froute%23x',
                '{"created":0,"sso_url":"https://builder.test/?ssotoken=private"}', 'object'],
            'get_all_user_nova_info' => ['getAllUserNovaInfo', ['domain' => 'example.test'], 'GET',
                'domain=example.test', '[{"domain":"example.test","metadata":{"company":"Example",'
                . '"cpanelDomainGUID":"example.test","document_root":"/home/user/public_html",'
                . '"fullcms":0,"language":"en","latest_publish_date":1706889600,"publish_status":1,'
                . '"session_minted_at":1706893200,"websiteId":444444},"quota":{"available_space":1024,'
                . '"can_backup":1,"is_docroot_empty":0,"required_space":64},"redirection_enabled":0,'
                . '"shared_doc_root":1,"status":{"has_nova_published":1,"has_nova_session":0,'
                . '"has_nova_website":1,"is_nova":0,"ssl_status":1}}]', 'list'],
            'get_sso_link' => ['getSsoLink', ['colorScheme' => 'auto', 'domain' => 'example.test',
                'promptToken' => 'cHJvbXB0+&=%', 'returnUrl' => 'https://panel.test/route#x'], 'POST',
                'colorScheme=auto&domain=example.test&promptToken=cHJvbXB0%2B%26%3D%25'
                . '&return_url=https%3A%2F%2Fpanel.test%2Froute%23x',
                '"https://builder.test/?ssotoken=private"', 'string'],
            'get_tier' => ['getTier', [], 'GET', '', '{"tier":"free","account_exists":0}', 'object'],
            'poll_publish' => ['pollPublish', ['fileName' => '/home/user/logs/publish x.log', 'pid' => 123456], 'POST',
                'file_name=%2Fhome%2Fuser%2Flogs%2Fpublish%20x.log&pid=123456',
                '{"failed":0,"is_running":1,"log":["Starting...","Done"],"progress":50}', 'object'],
            'publish' => ['publish', ['archiveUrl' => 'https://user:pass@builder.test/archive+&.zip',
                'domain' => 'example.test', 'signature' => 'private+&=%', 'userId' => 'nova_0', 'cleanup' => false],
                'POST',
                'archiveUrl=https%3A%2F%2Fuser%3Apass%40builder.test%2Farchive%2B%26.zip'
                . '&domain=example.test&signature=private%2B%26%3D%25&userId=nova_0&cleanup=0',
                '{"file_name":"/home/user/logs/nova.log","pid":123456,"user":"exampleuser"}', 'object'],
            default => throw new \LogicException('Unknown builder function.'),
        };
    }

    /** @return list<string> */
    private static function badLeaves(string $function): array
    {
        return match ($function) {
            'create_account' => ['{"key":1}'],
            'disk_quota_check' => ['{"available_space":"1024"}', '{"can_backup":true}', '{"is_docroot_empty":2}',
                '{"required_space":1.5}'],
            'ensure_account' => ['{"created":true}', '{"created":2}', '{"sso_url":null}'],
            'get_all_user_nova_info' => ['[{"metadata":{"fullcms":true}}]', '[{"metadata":{"websiteId":"1"}}]',
                '[{"quota":{"can_backup":2}}]', '[{"status":{"has_nova_session":false}}]',
                '[{"status":{"ssl_status":2}}]', '[{}, {"metadata":{"latest_publish_date":null}}]'],
            'get_tier' => ['{"tier":null}', '{"account_exists":true}', '{"account_exists":2}'],
            'poll_publish' => ['{"failed":2}', '{"is_running":false}', '{"progress":"50"}', '{"log":["ok",1]}'],
            'publish' => ['{"pid":"123"}', '{"file_name":false}', '{"user":null}'],
            default => [],
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $verb,
        string $parameters,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $url = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Nova&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Nova/' . $function;
        if ($verb === 'GET' && $parameters !== '') {
            $url .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        self::assertSame($verb, $request->getMethod());
        self::assertSame($url, (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($verb === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    private static function envelope(string $route, string $function, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic builder failure"]')
            . ',"warnings":["Synthetic builder notice"],"metadata":{"transformed":1}}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Nova","func":"' . $function . '","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser', 'token' => 'fixture-token'];

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
