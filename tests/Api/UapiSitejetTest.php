<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Sitejet;
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

/** Independent literal fixtures from the pinned Sitejet source; no generated metadata drives assertions. */
final class UapiSitejetTest extends TestCase
{
    #[DataProvider('operations')]
    public function testEveryPinnedOperationOnBothRoutes(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $group = $account->sitejet();
        self::assertSame($group, $account->sitejet());
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
        $group = $account->sitejet();
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

    #[DataProvider('routes')]
    public function testCreateWebsitePreservesBareIntegerAndPinnedObjectResults(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        foreach (['123456', '0', '{"websiteID":123456}', '{}', '{"future":1}'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'create_website', $payload)));
            $expected = $payload === '{"future":1}' ? [] : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $account->sitejet()->createWebsite('Company', 'example.test'));
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
        self::assertCount(5, $http->getRequests());
    }

    #[DataProvider('operations')]
    public function testApiAndHttpFailuresClearPriorMetadataAndNeverRetry(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $group = $account->sitejet();
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
                default => $function === 'create_website'
                    ? [null, 'null', 'true', '1.5', '"123"', '"bad"', '[{}]']
                    : [null, 'null', 'true', '1', '"bad"', '[{}]'],
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
        $group = $client->uapi('exampleuser')->sitejet();
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
        $group = $client->uapi('exampleuser')->sitejet();
        $http->addResponse(new Response(200, [], self::envelope($route, 'create_website', '{"websiteID":1}')));
        $group->createWebsite('Example', 'example.test');
        self::assertRequest($http, $route, 'create_website', 'POST', 'company=Example&domain=example.test');
        $http->addResponse(new Response(200, [], self::envelope($route, 'get_all_user_sitejet_info', '[]')));
        $group->getAllUserSitejetInfo();
        self::assertRequest($http, $route, 'get_all_user_sitejet_info', 'POST', '');
        $http->addResponse(new Response(200, [], self::envelope($route, 'get_sso_link', '"url"')));
        $group->getSsoLink('example.test', 'https://panel.test/');
        self::assertRequest(
            $http,
            $route,
            'get_sso_link',
            'POST',
            'domain=example.test&referrer=https%3A%2F%2Fpanel.test%2F',
        );
        $http->addResponse(new Response(200, [], self::envelope($route, 'start_publish', '{}')));
        $group->startPublish('example.test');
        self::assertRequest($http, $route, 'start_publish', 'POST', 'domain=example.test');
    }

    #[DataProvider('routes')]
    public function testCleanupBooleanEncodingAndStrictRejectionBeforeIo(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $group = $account->sitejet();
        $args = ['domain' => 'example.test'];
        foreach ([[], ['cleanup' => null], ['cleanup' => false], ['cleanup' => true]] as $index => $option) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'start_publish', '{}')));
            $group->startPublish(...array_merge($args, $option));
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
        [$method] = self::fixture('start_publish');
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
            'createWebsite' => ['company', 'assignTo', 'city', 'country', 'email', 'firstname',
                'lastname', 'metadata', 'note', 'phone', 'street', 'title', 'zip'],
            'getSsoLink' => ['referrer']];
        foreach ($sensitive as $method => $names) {
            $parameters = (new ReflectionMethod(Sitejet::class, $method))->getParameters();
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
        self::assertNotSame($first->sitejet(), $second->sitejet());
        $http->addResponse(new Response(200, [], self::envelope($route, 'can_create_domains', '0')));
        self::assertSame(0, $first->sitejet()->canCreateDomains());
        $metadata = $first->responseMeta();
        self::assertNotNull($metadata);
        $first->sitejet();
        $second->sitejet();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($second->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testUnknownFieldsAreDroppedWithoutDroppingKnownSiblings(string $route): void
    {
        [$client, $http] = self::client($route);
        $function = 'get_all_user_sitejet_info';
        $payload = '[{"domain":"example.test","future":1,"metadata":{"company":"Example","future":false},'
            . '"quota":{"available_space":0,"future":1},"status":{"ssl_status":0,"future":null}},{}]';
        $http->addResponse(new Response(200, [], self::envelope($route, $function, $payload)));
        $group = $client->uapi('exampleuser')->sitejet();
        self::assertSame(
            [['domain' => 'example.test', 'metadata' => ['company' => 'Example'],
                'quota' => ['available_space' => 0], 'status' => ['ssl_status' => 0]], []],
            $group->getAllUserSitejetInfo(),
        );
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
        return ['add_api_token',
            'can_create_domains',
            'create_account',
            'create_restore_point',
            'create_website',
            'get_all_user_sitejet_info',
            'get_api_token',
            'get_preview_url',
            'get_sso_link',
            'is_publish_in_progress',
            'restore_document_root',
            'start_publish'];
    }

    /** @return array{string, array<string, string|int|bool>, string, string, string, string} */
    public static function fixture(string $function): array
    {
        return match ($function) {
            'add_api_token' => ['addApiToken', ['apiToken' => 'private+&=%'], 'POST',
                'api_token=private%2B%26%3D%25', 'null', 'opaque'],
            'can_create_domains' => ['canCreateDomains', [], 'GET', '', '0', 'flag'],
            'create_account' => ['createAccount', [], 'POST', '', '{"key":"private-token"}', 'object'],
            'create_restore_point' => ['createRestorePoint', ['domain' => 'example.test'], 'POST',
                'domain=example.test', 'null', 'opaque'],
            'create_website' => ['createWebsite', ['company' => 'Example & Co', 'domain' => 'example.test',
                'assignTo' => 'owner+other@example.test', 'city' => 'City', 'country' => 'US',
                'email' => 'owner@example.test', 'firstname' => 'First', 'language' => 'en', 'lastname' => 'Last',
                'metadata' => '{"Attribute1":9,"text":"+&=%"}', 'note' => 'a note', 'phone' => '000-123',
                'street' => '1 Main St', 'title' => 'My Website', 'zip' => '00000'], 'POST',
                'company=Example%20%26%20Co&domain=example.test&assignTo=owner%2Bother%40example.test&city=City'
                . '&country=US&email=owner%40example.test&firstname=First&language=en&lastname=Last'
                . '&metadata=%7B%22Attribute1%22%3A9%2C%22text%22%3A%22%2B%26%3D%25%22%7D'
                . '&note=a%20note&phone=000-123&street=1%20Main%20St&title=My%20Website&zip=00000',
                '{"websiteID":123456}', 'object'],
            'get_all_user_sitejet_info' => ['getAllUserSitejetInfo', ['domain' => 'example.test'], 'POST',
                'domain=example.test', '[{"domain":"example.test","metadata":{"company":"Example",'
                . '"cpanelDomainGUID":"example.test","fullcms":0,"language":"en",'
                . '"latest_publish_date":1706889600,"publish_status":1,"websiteId":444444},'
                . '"quota":{"available_space":1024,"can_backup":1,"is_empty_docroot":0,"required_space":64},'
                . '"redirection_enabled":0,"shared_doc_root":1,"is_restore_point_available":1,'
                . '"is_temporary_domain":0,'
                . '"status":{"has_sitejet_published":1,"has_sitejet_website":0,"is_sitejet":1,"ssl_status":0}}]',
                'list'],
            'get_api_token' => ['getApiToken', [], 'POST', '', '"private-token"', 'string'],
            'get_preview_url' => ['getPreviewUrl', ['websiteId' => 123456], 'POST', 'websiteId=123456',
                '"https://builder.test/preview"', 'string'],
            'get_sso_link' => ['getSsoLink', ['domain' => 'example.test',
                'referrer' => 'https://panel.test/cpsess123/index.html#x', 'uiMode' => 'ai'], 'POST',
                'domain=example.test&referrer=https%3A%2F%2Fpanel.test%2Fcpsess123%2Findex.html%23x&ui_mode=ai',
                '"https://builder.test/?ssotoken=private"', 'string'],
            'is_publish_in_progress' => ['isPublishInProgress', ['domain' => 'example.test'], 'POST',
                'domain=example.test', '{"in_progress":1,"pid":123456}', 'object'],
            'restore_document_root' => ['restoreDocumentRoot', ['domain' => 'example.test'], 'POST',
                'domain=example.test', 'null', 'opaque'],
            'start_publish' => ['startPublish', ['domain' => 'example.test', 'cleanup' => false], 'POST',
                'domain=example.test&cleanup=0', '{"file_name":"/home/user/logs/publish.log","pid":123456}', 'object'],
            default => throw new \LogicException('Unknown builder function.'),
        };
    }

    /** @return list<string> */
    private static function badLeaves(string $function): array
    {
        return match ($function) {
            'create_account' => ['{"key":1}'],
            'create_website' => ['{"websiteID":"123"}', '{"websiteID":false}'],
            'get_all_user_sitejet_info' => ['[{"metadata":{"fullcms":true}}]', '[{"quota":{"can_backup":2}}]',
                '[{"quota":{"is_empty_docroot":"0"}}]', '[{"metadata":{"websiteId":"1"}}]',
                '[{"status":{"ssl_status":false}}]', '[{}, {"is_temporary_domain":2}]'],
            'is_publish_in_progress' => ['{"in_progress":true}', '{"in_progress":2}', '{"pid":"123"}'],
            'start_publish' => ['{"file_name":null}', '{"pid":"123"}'],
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
                . '&cpanel.module=Sitejet&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Sitejet/' . $function;
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
            default => '{"apiversion":3,"module":"Sitejet","func":"' . $function . '","result":' . $result . '}',
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
