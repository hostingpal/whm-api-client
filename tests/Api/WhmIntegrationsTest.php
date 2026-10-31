<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\Integrations;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;

/**
 * Independent pinned-source request and response literals; no generated spec supplies expectations.
 *
 * @phpstan-type IntegrationCall Closure(Integrations): mixed
 * @phpstan-type Operation array{string, string, IntegrationCall, string, ?string, mixed}
 */
final class WhmIntegrationsTest extends TestCase
{
    /** @param IntegrationCall $call */
    #[DataProvider('operations')]
    public function testExactRequestsAndPinnedPayloads(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $group = $whm->integrations();
        self::assertSame($group, $whm->integrations());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($payload)));

        self::assertSame($expected, $call($group));
        self::assertRequest($http, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertNull($client->uapi('alice')->responseMeta());
    }

    /** @return iterable<string, Operation> */
    public static function operations(): iterable
    {
        yield 'create group keeps integer order' => ['create_integration_group', 'POST',
            static function (Integrations $g): mixed {
                $g->createIntegrationGroup(groupId: 'group +&', label: 'Billing +&', order: 1, user: 'alice');
                return null;
            }, 'group_id=group%20%2B%26&label=Billing%20%2B%26&order=1&user=alice', null, null];
        yield 'create link all parameters once' => ['create_integration_link', 'POST',
            static function (Integrations $g): mixed {
                $g->createIntegrationLink(
                    app: 'billing_app',
                    implements: 'custom_service',
                    label: 'Billing +&',
                    subscriberUniqueId: 'id +&',
                    token: 'token +&=',
                    user: 'alice',
                    autologinTokenUrl: 'https://login.example.test/?token=a+&b=2',
                    base64PngImage: 'iVBORw0+//==',
                    groupId: 'custom +&',
                    hide: false,
                    order: '000',
                    url: 'https://fallback.example.test/?x=a+&y=2',
                );
                return null;
            }, 'app=billing_app&implements=custom_service&label=Billing%20%2B%26&subscriber_unique_id=id%20%2B%26'
                . '&token=token%20%2B%26%3D&user=alice'
                . '&autologin_token_url=https%3A%2F%2Flogin.example.test%2F%3Ftoken%3Da%2B%26b%3D2'
                . '&base64_png_image=iVBORw0%2B%2F%2F%3D%3D&group_id=custom%20%2B%26&hide=0&order=000'
                . '&url=https%3A%2F%2Ffallback.example.test%2F%3Fx%3Da%2B%26y%3D2', null, null];
        yield 'delete hook' => ['delete_hook', 'POST',
            static function (Integrations $g): mixed {
                $g->deleteHook('hook +&');
                return null;
            }, 'id=hook%20%2B%26', null, null];
        yield 'edit hook all parameters' => ['edit_hook', 'POST',
            static function (Integrations $g): mixed {
                $g->editHook(
                    id: 'hook +&',
                    check: '/scripts/check +&',
                    enabled: false,
                    escalateprivs: false,
                    exectype: 'module',
                    hook: 'Private::Module::run',
                    notes: 'note +&',
                    rollback: '/scripts/rollback +&',
                    stage: 'pre',
                    weight: 0,
                );
                return null;
            }, 'id=hook%20%2B%26&check=%2Fscripts%2Fcheck%20%2B%26&enabled=0&escalateprivs=0'
                . '&exectype=module&hook=Private%3A%3AModule%3A%3Arun&notes=note%20%2B%26'
                . '&rollback=%2Fscripts%2Frollback%20%2B%26&stage=pre&weight=0', null, null];
        yield 'user configuration remains POST and URLs stay payload' => ['get_integration_link_user_config', 'POST',
            static fn (Integrations $g): mixed => $g->getIntegrationLinkUserConfig(app: 'billing_app', user: 'alice'),
            'app=billing_app&user=alice', '{"userconfig":{"app":"billing_app",'
                . '"autologin_token_url":"https://login.example.test/?secret=private",'
                . '"base64_png_image":"iVBORw0+//==","implements":"custom_service",'
                . '"label":"Billing","subscriber_unique_id":"1234","url":"https://fallback.example.test/",'
                . '"user":"alice","unknown":"discard"}}',
            ['userconfig' => ['app' => 'billing_app',
                'autologin_token_url' => 'https://login.example.test/?secret=private',
                'base64_png_image' => 'iVBORw0+//==', 'implements' => 'custom_service', 'label' => 'Billing',
                'subscriber_unique_id' => '1234', 'url' => 'https://fallback.example.test/', 'user' => 'alice']]];
        yield 'nested hook inventory' => ['list_hooks', 'GET',
            static fn (Integrations $g): mixed => $g->listHooks(), '',
            '{"categories":[{"category":"RPM::Versions","events":[{"event":"MariaDB-server",'
                . '"stage_order":["pre","post"],"stages":[{"actions":[{"blocking":0,'
                . '"category":"RPM::Versions","check":null,"description":null,"enabled":1,"escalateprivs":0,'
                . '"event":"MariaDB-server","exectype":"module","hook":"Private::Module::run",'
                . '"id":"hook-id","rollback":null,"stage":"post","weight":100}],'
                . '"attributes":{"blocking":0,"escalateprivs":1},"description":"fixture","stage":"post"}]}]}]}',
            ['categories' => [['category' => 'RPM::Versions', 'events' => [['event' => 'MariaDB-server',
                'stage_order' => ['pre', 'post'], 'stages' => [['actions' => [['blocking' => 0,
                    'category' => 'RPM::Versions', 'check' => null, 'description' => null, 'enabled' => 1,
                    'escalateprivs' => 0, 'event' => 'MariaDB-server', 'exectype' => 'module',
                    'hook' => 'Private::Module::run', 'id' => 'hook-id', 'rollback' => null, 'stage' => 'post',
                    'weight' => 100]], 'attributes' => ['blocking' => 0, 'escalateprivs' => 1],
                    'description' => 'fixture', 'stage' => 'post']]]]]]]];
        yield 'account group inventory' => ['list_integration_groups', 'GET',
            static fn (Integrations $g): mixed => $g->listIntegrationGroups('alice +&'), 'user=alice%20%2B%26',
            '{"links":[{"group":"custom"},{}]}', ['links' => [['group' => 'custom'], []]]];
        yield 'account link inventory' => ['list_integration_links', 'GET',
            static fn (Integrations $g): mixed => $g->listIntegrationLinks('alice'), 'user=alice',
            '{"links":[{"app":"billing_app"},{}]}', ['links' => [['app' => 'billing_app'], []]]];
        yield 'remove group unspecified data preserves fields' => ['remove_integration_group', 'POST',
            static fn (Integrations $g): mixed => $g->removeIntegrationGroup(groupId: 'custom +&', user: 'alice'),
            'group_id=custom%20%2B%26&user=alice', '{"opaque":{"state":0}}', ['opaque' => ['state' => 0]]];
        yield 'remove link unspecified data preserves null' => ['remove_integration_link', 'POST',
            static fn (Integrations $g): mixed => $g->removeIntegrationLink(app: 'billing_app', user: 'alice'),
            'app=billing_app&user=alice', 'null', null];
        yield 'reorder exact caller CSV' => ['reorder_hooks', 'POST',
            static fn (Integrations $g): mixed => $g->reorderHooks('second+&,first'),
            'ids=second%2B%26%2Cfirst', '{"hook_order":[{"id":"second+&","weight":100},'
                . '{"id":"first","weight":200},{}]}',
            ['hook_order' => [['id' => 'second+&', 'weight' => 100], ['id' => 'first', 'weight' => 200], []]]];
        yield 'replace token unspecified data stays scalar' => ['update_integration_link_token', 'POST',
            static fn (Integrations $g): mixed => $g->updateIntegrationLinkToken(
                app: 'billing_app',
                token: 'new +&=',
                user: 'alice',
            ), 'app=billing_app&token=new%20%2B%26%3D&user=alice', '0', 0];
    }

    /** @return iterable<string, Operation> */
    public static function omissions(): iterable
    {
        yield 'link defaults are omitted and fallback URL is sufficient' => ['create_integration_link', 'POST',
            static function (Integrations $g): mixed {
                $g->createIntegrationLink(
                    'app',
                    'billing',
                    'Billing',
                    '1234',
                    'token',
                    'alice',
                    url: 'https://x.test/',
                );
                return null;
            }, 'app=app&implements=billing&label=Billing&subscriber_unique_id=1234&token=token&user=alice'
                . '&url=https%3A%2F%2Fx.test%2F', null, null];
        yield 'hook defaults are omitted' => ['edit_hook', 'POST',
            static function (Integrations $g): mixed {
                $g->editHook('hook-id', enabled: null, escalateprivs: null, weight: null);
                return null;
            }, 'id=hook-id', null, null];
        yield 'explicit hidden flag' => ['create_integration_link', 'POST',
            static function (Integrations $g): mixed {
                $g->createIntegrationLink('app', 'billing', '', '', '', 'alice', hide: true, order: '', url: '');
                return null;
            }, 'app=app&implements=billing&label=&subscriber_unique_id=&token=&user=alice&hide=1&order=&url=',
            null, null];
        yield 'script hook flags' => ['edit_hook', 'POST',
            static function (Integrations $g): mixed {
                $g->editHook('hook-id', enabled: true, escalateprivs: true, exectype: 'script', stage: 'post');
                return null;
            }, 'id=hook-id&enabled=1&escalateprivs=1&exectype=script&stage=post', null, null];
    }

    /** @param IntegrationCall $call */
    #[DataProvider('omissions')]
    public function testOmissionFalseZeroAndEmptyStrings(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        $this->testExactRequestsAndPinnedPayloads($function, $method, $call, $parameters, $payload, $expected);
    }

    /** @param IntegrationCall $call */
    #[DataProvider('operations')]
    public function testApiFailuresKeepMetadataAndNeverRetry(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('{"links":[]}')));
        $whm->integrations()->listIntegrationGroups('alice');
        $previous = $whm->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Fixture denied"}}'));
        try {
            $call($whm->integrations());
            self::fail('Failed WHM outcomes must be rejected before payload handling.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Fixture denied'], $exception->errors());
            self::assertSame($exception->metadata, $whm->responseMeta());
            self::assertNotSame($previous, $whm->responseMeta());
            self::assertSame(1, $previous?->whmResult());
            self::assertSame(0, $whm->responseMeta()?->whmResult());
            self::assertCount(2, $http->getRequests());
        }
    }

    public function testTransportFailureClearsMetadataBeforeDispatchAndNeverRetries(): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::envelope('{"links":[]}')));
        $whm->integrations()->listIntegrationGroups('alice');
        $snapshot = $whm->responseMeta();
        $http->addException(new class ('Fixture disconnected.') extends RuntimeException implements
            NetworkExceptionInterface
        {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('GET', 'https://api.example.test/');
            }
        });
        try {
            $whm->integrations()->listIntegrationGroups('alice');
            self::fail('The transport failure must propagate after one request.');
        } catch (TransportException $exception) {
            self::assertNull($whm->responseMeta());
            self::assertNull($exception->metadata);
            self::assertSame(1, $snapshot?->whmResult());
            self::assertCount(2, $http->getRequests());
        }
    }

    /** @return iterable<string, array{IntegrationCall, string}> */
    public static function malformedPayloads(): iterable
    {
        yield 'group is string' => [static fn (Integrations $g): mixed => $g->listIntegrationGroups('alice'),
            '{"links":[{"group":1}]}'];
        yield 'link is object' => [static fn (Integrations $g): mixed => $g->listIntegrationLinks('alice'),
            '{"links":["app"]}'];
        yield 'list is not map' => [static fn (Integrations $g): mixed => $g->listIntegrationLinks('alice'),
            '{"links":{"app":"x"}}'];
        yield 'userconfig is object' => [
            static fn (Integrations $g): mixed => $g->getIntegrationLinkUserConfig('app', 'alice'),
            '{"userconfig":["not object"]}'];
        yield 'subscriber ID is string' => [
            static fn (Integrations $g): mixed => $g->getIntegrationLinkUserConfig('app', 'alice'),
            '{"userconfig":{"subscriber_unique_id":1234}}'];
        yield 'config URL does not accept null' => [
            static fn (Integrations $g): mixed => $g->getIntegrationLinkUserConfig('app', 'alice'),
            '{"userconfig":{"autologin_token_url":null}}'];
        yield 'hook flag rejects noncanonical text' => [static fn (Integrations $g): mixed => $g->listHooks(),
            '{"categories":[{"events":[{"stages":[{"actions":[{"enabled":"01"}]}]}]}]}'];
        yield 'stage order items are strings' => [static fn (Integrations $g): mixed => $g->listHooks(),
            '{"categories":[{"events":[{"stage_order":[0]}]}]}'];
        yield 'hook weight rejects noncanonical text' => [static fn (Integrations $g): mixed => $g->listHooks(),
            '{"categories":[{"events":[{"stages":[{"actions":[{"weight":"0100"}]}]}]}]}'];
        yield 'nullable check remains string or null' => [static fn (Integrations $g): mixed => $g->listHooks(),
            '{"categories":[{"events":[{"stages":[{"actions":[{"check":false}]}]}]}]}'];
        yield 'hook-order node is list' => [static fn (Integrations $g): mixed => $g->reorderHooks('a,b'),
            '{"hook_order":{"id":"a","weight":100}}'];
        yield 'hook-order row is object' => [static fn (Integrations $g): mixed => $g->reorderHooks('a,b'),
            '{"hook_order":["a"]}'];
        yield 'hook-order weight rejects noncanonical text' => [
            static fn (Integrations $g): mixed => $g->reorderHooks('a,b'),
            '{"hook_order":[{"weight":"0100"}]}'];
    }

    /** @param IntegrationCall $call */
    #[DataProvider('malformedPayloads')]
    public function testInvalidPayloadsRetainConfirmedOutcomeWithoutRetry(Closure $call, string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $call($client->whm()->integrations());
            self::fail('Malformed pinned fields must be rejected.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $exception->metadata?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{IntegrationCall, ?string, mixed, bool}> */
    public static function payloadBoundaries(): iterable
    {
        foreach (
            [
            'groups' => static fn (Integrations $g): mixed => $g->listIntegrationGroups('alice'),
            'links' => static fn (Integrations $g): mixed => $g->listIntegrationLinks('alice'),
            'hooks' => static fn (Integrations $g): mixed => $g->listHooks(),
            'config' => static fn (Integrations $g): mixed => $g->getIntegrationLinkUserConfig('app', 'alice'),
            'order' => static fn (Integrations $g): mixed => $g->reorderHooks('a,b'),
            ] as $name => $call
        ) {
            yield $name . ' optional fields absent' => [$call, '{}', [], false];
            yield $name . ' empty-array object compatibility' => [$call, '[]', [], false];
            yield $name . ' absent payload' => [$call, null, null, true];
            yield $name . ' null payload' => [$call, 'null', null, true];
            yield $name . ' nonempty array payload' => [$call, '[{}]', null, true];
        }
        foreach (
            [
            'remove group' => static fn (Integrations $g): mixed => $g->removeIntegrationGroup('group', 'alice'),
            'remove link' => static fn (Integrations $g): mixed => $g->removeIntegrationLink('app', 'alice'),
            'replace token' => static fn (Integrations $g): mixed =>
                $g->updateIntegrationLinkToken('app', 'token', 'alice'),
            ] as $name => $call
        ) {
            yield $name . ' successful absent payload' => [$call, null, null, false];
            yield $name . ' explicit null' => [$call, 'null', null, false];
            yield $name . ' scalar stays unconstrained' => [$call, 'false', false, false];
            yield $name . ' opaque fields' => [
                $call, '{"opaque":[false,0,null]}', ['opaque' => [false, 0, null]], false,
            ];
        }
        yield 'empty hooks' => [static fn (Integrations $g): mixed => $g->listHooks(),
            '{"categories":[]}', ['categories' => []], false];
        yield 'empty groups' => [static fn (Integrations $g): mixed => $g->listIntegrationGroups('alice'),
            '{"links":[]}', ['links' => []], false];
        yield 'empty links' => [static fn (Integrations $g): mixed => $g->listIntegrationLinks('alice'),
            '{"links":[]}', ['links' => []], false];
        yield 'empty hook order' => [static fn (Integrations $g): mixed => $g->reorderHooks('a,b'),
            '{"hook_order":[]}', ['hook_order' => []], false];
    }

    /** @param IntegrationCall $call */
    #[DataProvider('payloadBoundaries')]
    public function testMissingNullOpaqueAndEmptyBoundaries(
        Closure $call,
        ?string $payload,
        mixed $expected,
        bool $invalid,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        if ($invalid) {
            $this->expectException(PayloadMismatchException::class);
        }
        self::assertSame($expected, $call($client->whm()->integrations()));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{IntegrationCall, string, bool}> */
    public static function invalidOptionalPayloadEnvelopes(): iterable
    {
        foreach (
            [
            'remove group' => static fn (Integrations $g): mixed => $g->removeIntegrationGroup('group', 'alice'),
            'remove link' => static fn (Integrations $g): mixed => $g->removeIntegrationLink('app', 'alice'),
            'replace token' => static fn (Integrations $g): mixed =>
                $g->updateIntegrationLinkToken('app', 'token', 'alice'),
            ] as $name => $call
        ) {
            yield $name . ' missing metadata' => [$call, '{"data":null}', false];
            yield $name . ' unusable result' => [$call, '{"metadata":{"result":null},"data":{}}', true];
            yield $name . ' malformed present JSON' => [$call, '{"metadata":{"result":1},"data":', false];
        }
    }

    /** @param IntegrationCall $call */
    #[DataProvider('invalidOptionalPayloadEnvelopes')]
    public function testOptionalPayloadStillRequiresUsableEnvelope(
        Closure $call,
        string $response,
        bool $hasSnapshot,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"links":[]}')));
        $client->whm()->integrations()->listIntegrationGroups('alice');
        $previous = $client->whm()->responseMeta();
        $http->addResponse(new Response(200, [], $response));
        try {
            $call($client->whm()->integrations());
            self::fail('Optional payloads must not bypass envelope validation.');
        } catch (InvalidResponseException $exception) {
            self::assertNotInstanceOf(PayloadMismatchException::class, $exception);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertNotSame($previous, $exception->metadata);
            self::assertSame($hasSnapshot ? ['result' => null] : null, $exception->metadata?->whm);
            self::assertSame(1, $previous?->whmResult());
            self::assertCount(2, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function sensitiveParameters(): iterable
    {
        yield 'subscriber ID' => ['createIntegrationLink', 'subscriberUniqueId'];
        yield 'create token' => ['createIntegrationLink', 'token'];
        yield 'autologin URL' => ['createIntegrationLink', 'autologinTokenUrl'];
        yield 'fallback URL' => ['createIntegrationLink', 'url'];
        yield 'new token' => ['updateIntegrationLinkToken', 'token'];
        yield 'hook command' => ['editHook', 'hook'];
        yield 'check command' => ['editHook', 'check'];
        yield 'rollback command' => ['editHook', 'rollback'];
    }

    #[DataProvider('sensitiveParameters')]
    public function testSensitiveInputsAreMarked(string $method, string $argument): void
    {
        $reflection = new ReflectionMethod(Integrations::class, $method);
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getName() === $argument) {
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                return;
            }
        }
        self::fail('The expected public named argument is missing.');
    }

    public function testCustomGroupIdHasAnOpenStringContract(): void
    {
        $method = new ReflectionMethod(Integrations::class, 'createIntegrationLink');
        self::assertStringContainsString('@param string|null $groupId', $method->getDocComment() ?: '');
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function compatibleHookWeights(): iterable
    {
        yield 'quoted zero' => ['0', 0];
        yield 'quoted priority' => ['100', 100];
        yield 'quoted negative' => ['-1', -1];
        yield 'quoted maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'quoted minimum' => [(string) PHP_INT_MIN, PHP_INT_MIN];
        yield 'native zero' => [0, 0];
        yield 'native priority' => [100, 100];
    }

    #[DataProvider('compatibleHookWeights')]
    public function testHookFamilyNormalizesOnlyCanonicalWeights(int|string $weight, int $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(self::hookPayload(['weight' => $weight]))));
        $hooks = $client->whm()->integrations()->listHooks();
        self::assertSame($expected, $hooks['categories'][0]['events'][0]['stages'][0]['actions'][0]['weight'] ?? null);
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('compatibleHookWeights')]
    public function testReorderHookFamilyNormalizesOnlyCanonicalWeights(int|string $weight, int $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            json_encode(['hook_order' => [['id' => 'a', 'weight' => $weight], []]], JSON_THROW_ON_ERROR),
        )));
        self::assertSame(
            ['hook_order' => [['id' => 'a', 'weight' => $expected], []]],
            $client->whm()->integrations()->reorderHooks('a'),
        );
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
    }

    public function testPopulatedHookActionsPreserveAllThirteenFieldsAndNormalizeExactFlags(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(self::hookPayload([
            'blocking' => '0', 'enabled' => '1', 'escalateprivs' => '0', 'weight' => '100',
        ]))));
        $hooks = $client->whm()->integrations()->listHooks();
        self::assertSame([
            'blocking' => 0, 'category' => 'RPM::Versions', 'check' => null, 'description' => 'fixture action',
            'enabled' => 1, 'escalateprivs' => 0, 'event' => 'MariaDB-server', 'exectype' => 'script',
            'hook' => '/fixture/hook', 'id' => 'a', 'rollback' => null, 'stage' => 'post', 'weight' => 100,
        ], $hooks['categories'][0]['events'][0]['stages'][0]['actions'][0] ?? null);
        $http->addResponse(new Response(200, [], self::envelope(self::hookPayload([
            'blocking' => '1', 'enabled' => '0', 'escalateprivs' => '1',
        ]))));
        $hooks = $client->whm()->integrations()->listHooks();
        $action = $hooks['categories'][0]['events'][0]['stages'][0]['actions'][0] ?? [];
        self::assertSame(
            [1, 0, 1],
            [$action['blocking'] ?? null, $action['enabled'] ?? null, $action['escalateprivs'] ?? null],
        );
    }

    /** @return iterable<string, array{IntegrationCall, string}> */
    public static function invalidHookFamilies(): iterable
    {
        foreach (
            ['00', '0100', '+1', '-0', '1.0', '1e2', ' 1', '1 ', '9223372036854775808',
            '-9223372036854775809', true, 1.5, null] as $i => $weight
        ) {
            yield 'action weight ' . $i => [static fn (Integrations $g): mixed => $g->listHooks(),
                self::hookPayload(['weight' => $weight])];
            yield 'order weight ' . $i => [static fn (Integrations $g): mixed => $g->reorderHooks('a'),
                json_encode(['hook_order' => [['weight' => $weight]]], JSON_THROW_ON_ERROR)];
        }
        foreach (['blocking', 'enabled', 'escalateprivs'] as $field) {
            foreach (['01', 'true', '2', 2, true, 1.0, null] as $i => $flag) {
                yield $field . ' invalid flag ' . $i => [static fn (Integrations $g): mixed => $g->listHooks(),
                    self::hookPayload([$field => $flag])];
            }
        }
        foreach (['category', 'event', 'exectype', 'hook', 'id', 'stage'] as $field) {
            yield $field . ' strict string neighbor' => [static fn (Integrations $g): mixed => $g->listHooks(),
                self::hookPayload([$field => 1])];
        }
        yield 'attributes remain strict' => [static fn (Integrations $g): mixed => $g->listHooks(),
            '{"categories":[{"events":[{"stages":[{"attributes":{"blocking":"1"}}]}]}]}'];
    }

    /** @param IntegrationCall $call */
    #[DataProvider('invalidHookFamilies')]
    public function testHookCompatibilityRejectsInvalidAndNeighborVariants(Closure $call, string $payload): void
    {
        $this->testInvalidPayloadsRetainConfirmedOutcomeWithoutRetry($call, $payload);
    }

    /** @param array<string, mixed> $changes */
    private static function hookPayload(array $changes): string
    {
        $action = array_replace([
            'blocking' => 0, 'category' => 'RPM::Versions', 'check' => null, 'description' => 'fixture action',
            'enabled' => 1, 'escalateprivs' => 0, 'event' => 'MariaDB-server', 'exectype' => 'script',
            'hook' => '/fixture/hook', 'id' => 'a', 'rollback' => null, 'stage' => 'post', 'weight' => 100,
        ], $changes);
        return json_encode(
            ['categories' => [['events' => [['stages' => [['actions' => [$action]]]]]]]],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function assertRequest(MockClient $http, string $function, string $method, string $parameters): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $function . '?api.version=1'
            . ($method === 'GET' && $parameters !== '' ? '&' . $parameters : ''), (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK","version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
