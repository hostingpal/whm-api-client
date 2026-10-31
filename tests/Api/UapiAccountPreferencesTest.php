<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
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
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;

/** Independent literals from the five pinned account-preference modules. No live requests. */
final class UapiAccountPreferencesTest extends TestCase
{
    public function testSelectorsExistWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        foreach (['contactInformation', 'contactUs', 'locale', 'themes', 'notifications'] as $selector) {
            self::assertTrue(method_exists($account, $selector), 'Missing account selector: ' . $selector);
        }
        self::assertSame([], $http->getRequests());
    }

    #[DataProvider('validPayloads')]
    public function testExactWireAndIndependentPayloads(
        string $route,
        string $module,
        string $function,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        self::assertRequest($http, $route, $module, $function);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, ?string, mixed}> */
    public static function validPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                foreach (self::validCases($function) as $index => [$payload, $expected]) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload, $expected];
                }
            }
        }
    }

    /** @return list<array{?string, mixed}> */
    private static function validCases(string $function): array
    {
        return match ($function) {
            'get_notification_preferences' => [
                ['[{"additionalProperties":"pinned literal","descp":"Someone logs in to my account.",'
                    . '"enabled":1,"name":"notify_account_login"},{"enabled":0},{},'
                    . '{"descp":"","name":"","future":true}]',
                    [
            ['additionalProperties' => 'pinned literal', 'descp' => 'Someone logs in to my account.',
                    'enabled' => 1, 'name' => 'notify_account_login'], ['enabled' => 0], [],
                    ['descp' => '', 'name' => '']]], ['[]', []],
            ],
            'get_pushbullet_access_token' => [['"fixture-pushbullet-token"', 'fixture-pushbullet-token'], ['""', '']],
            'set_email_addresses', 'unset_email_addresses', 'set_pushbullet_access_token' => [
                [null, null], ['null', null],
            ],
            'is_enabled' => [['{"enabled":1}', ['enabled' => 1]], ['{"enabled":0}', ['enabled' => 0]], ['{}', []]],
            'get_attributes' => [
                ['{"direction":"ltr","encoding":"utf-8","locale":"en","name":"English"}',
                    ['direction' => 'ltr', 'encoding' => 'utf-8', 'locale' => 'en', 'name' => 'English']],
                ['{"direction":"rtl","encoding":"","locale":"","name":"","unknown":0}',
                    ['direction' => 'rtl', 'encoding' => '', 'locale' => '', 'name' => '']], ['{}', []],
            ],
            'list_locales' => [
                ['[{"direction":"ltr","local_name":"Deutsch","locale":"de","name":"German"},'
                    . '{"direction":"rtl","local_name":"","locale":"","name":""},{}]',
                    [
            ['direction' => 'ltr', 'local_name' => 'Deutsch', 'locale' => 'de', 'name' => 'German'],
                    ['direction' => 'rtl', 'local_name' => '', 'locale' => '', 'name' => ''], []]], ['[]', []],
            ],
            'set_locale' => [
                ['null', null], ['false', false], ['0', 0], ['1.25', 1.25], ['""', ''], ['[]', []],
                ['{"opaque":{"zero":0,"empty":[],"none":null}}',
                    ['opaque' => ['zero' => 0, 'empty' => [], 'none' => null]]],
            ],
            'list' => [['["jupiter","custom","", "0"]', ['jupiter', 'custom', '', '0']], ['[]', []]],
            'update' => [
                ['null', null], ['{}', []], ['[]', []],
                ['{"opaque":{"zero":0,"empty":[],"none":null}}',
                    ['opaque' => ['zero' => 0, 'empty' => [], 'none' => null]]],
            ],
            default => [['0', 0], ['3', 3]],
        };
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadReplacesMetadataAndNeverRetries(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '0')));
        $account->notifications()->getNotificationsCount();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Invalid payload must be rejected after confirming the outcome.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
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
        $cases = [
            'get_notification_preferences' => [null, 'null', '{}', 'false', '0', '""', '[false]',
                '[{"enabled":null}]', '[{"enabled":"0"}]', '[{"enabled":2}]', '[{"enabled":false}]',
                '[{"descp":0}]', '[{"name":null}]', '[{"additionalProperties":{}}]'],
            'get_pushbullet_access_token' => [null, 'null', '0', 'false', '[]', '{}'],
            'is_enabled' => [null, 'null', 'false', '0', '""', '[{}]', '{"enabled":null}',
                '{"enabled":"1"}', '{"enabled":true}', '{"enabled":2}', '{"enabled":1.0}'],
            'get_attributes' => [null, 'null', 'false', '0', '""', '[{}]', '{"direction":null}',
                '{"encoding":0}', '{"locale":false}', '{"name":[]}'],
            'list_locales' => [null, 'null', 'false', '0', '""', '{}', '[null]', '[{"direction":false}]',
                '[{"local_name":null}]', '[{"locale":0}]', '[{"name":[]}]'],
            'set_locale' => [null],
            'list' => [null, 'null', 'false', '0', '""', '{}', '[0]', '[null]', '[{}]', '[false]'],
            'update' => [null, 'false', '0', '1.25', '""', '[{}]'],
            'get_notifications_count' => [null, 'null', 'false', '1.0', '"0"', '[]', '{}'],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $function => $payloads) {
                foreach ($payloads as $index => $payload) {
                    yield "$route $function $index" => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailuresReplaceMetadataAndDoNotRetry(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '0')));
        $account->notifications()->getNotificationsCount();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $function);
            self::fail('Status zero must fail, including Themes/update and Contactus/is_enabled.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
            self::assertNotSame($previous, $error->metadata);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [, $function]) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    #[DataProvider('operationFunctions')]
    public function testOuterWhmFailurePrecedesSuccessfulInnerPayload(string $function): void
    {
        [$client, $http] = self::client('bridge');
        $payload = self::validCases($function)[0][0];
        $http->addResponse(new Response(200, [], str_replace(
            '"metadata":{"result":1}',
            '"metadata":{"result":0,"reason":"Outer failure"}',
            self::envelope('bridge', $payload),
        )));
        try {
            self::invoke($client->uapi('npr'), $function);
            self::fail('Outer WHM failure must be checked.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function operationFunctions(): iterable
    {
        foreach (self::operations() as [, $function]) {
            yield $function => [$function];
        }
    }

    #[DataProvider('routes')]
    public function testThemeDefaultOmissionAndExplicitFalseRemainDistinct(string $route): void
    {
        [$client, $http] = self::client($route);
        $themes = $client->uapi('npr')->themes();
        foreach ([null, false, true] as $showMailThemes) {
            $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
            $themes->listThemes(showMailThemes: $showMailThemes);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            $base = $route === 'bridge'
                ? 'api.version=1&cpanel.user=npr&cpanel.module=Themes&cpanel.function=list'
                : '';
            $suffix = match ($showMailThemes) {
                null => '', false => 'show_mail_themes=0', true => 'show_mail_themes=1'
            };
            self::assertSame(
                $base . ($base !== '' && $suffix !== '' ? '&' : '') . $suffix,
                $request->getUri()->getQuery(),
            );
        }
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testRequiredEmptyStringsAreSentInMutationBodies(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $cases = [
            'setEmailAddresses' => 'address=new%40example.test&old_address=old%40example.test&password=',
            'unsetEmailAddresses' => 'old_address=old%40example.test&password=',
            'setPushbulletAccessToken' => 'pushbullet_access_token=',
            'setLocale' => 'locale=',
            'update' => 'theme=',
        ];
        foreach ($cases as $method => $expectedBody) {
            $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
            switch ($method) {
                case 'setEmailAddresses':
                    $account->contactInformation()->setEmailAddresses(['new@example.test'], ['old@example.test'], '');
                    break;
                case 'unsetEmailAddresses':
                    $account->contactInformation()->unsetEmailAddresses(['old@example.test'], '');
                    break;
                case 'setPushbulletAccessToken':
                    $account->contactInformation()->setPushbulletAccessToken('');
                    break;
                case 'setLocale':
                    $account->locale()->setLocale('');
                    break;
                default:
                    $account->themes()->update('');
            }
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('POST', $request->getMethod());
            self::assertSame($expectedBody, (string) $request->getBody());
        }
        self::assertCount(5, $http->getRequests());
    }

    public function testSelectorsBindAccountsAndKeepMetadataWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($account->contactInformation(), $account->contactInformation());
        self::assertSame($account->contactUs(), $account->contactUs());
        self::assertSame($account->locale(), $account->locale());
        self::assertSame($account->themes(), $account->themes());
        self::assertSame($account->notifications(), $account->notifications());
        self::assertNotSame($account->contactInformation(), $other->contactInformation());
        self::assertNotSame($account->contactUs(), $other->contactUs());
        self::assertNotSame($account->locale(), $other->locale());
        self::assertNotSame($account->themes(), $other->themes());
        self::assertNotSame($account->notifications(), $other->notifications());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '0')));
        $account->notifications()->getNotificationsCount();
        $previous = $account->responseMeta();
        $account->contactInformation();
        $account->contactUs();
        $account->locale();
        $account->themes();
        self::assertSame($previous, $account->responseMeta());
        self::assertNull($other->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[]')));
        $other->themes()->listThemes();
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertStringContainsString('cpanel.user=other', (string) $request->getUri());
    }

    public function testRequiredContactInputsAndSensitivePublicParameters(): void
    {
        [$client] = self::client('direct');
        $contact = $client->uapi()->contactInformation();
        foreach (
            ['setEmailAddresses' => ['addresses', 'oldAddresses', 'password'],
            'unsetEmailAddresses' => ['oldAddresses', 'password'],
            'setPushbulletAccessToken' => ['pushbulletAccessToken']] as $method => $parameters
        ) {
            foreach ($parameters as $parameter) {
                $reflection = new ReflectionParameter([$contact, $method], $parameter);
                self::assertFalse($reflection->isOptional());
                if (in_array($parameter, ['password', 'pushbulletAccessToken'], true)) {
                    self::assertCount(1, $reflection->getAttributes(SensitiveParameter::class));
                }
            }
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidRepeatedInputs')]
    public function testMalformedRepeatedAddressInputClearsMetadataBeforeIo(string $method, array $arguments): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', '0')));
        $account->notifications()->getNotificationsCount();
        try {
            (new ReflectionMethod($account->contactInformation(), $method))->invokeArgs(
                $account->contactInformation(),
                $arguments,
            );
            self::fail('Invalid repeated-list input must fail before dispatch.');
        } catch (InvalidArgumentException) {
            self::assertNull($account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function invalidRepeatedInputs(): iterable
    {
        foreach ([[], [0], [false], [null], ['key' => 'mail@example.test']] as $index => $invalid) {
            yield "new $index" => ['setEmailAddresses', [$invalid, ['old@example.test'], 'password']];
            yield "old $index" => ['setEmailAddresses', [['new@example.test'], $invalid, 'password']];
            yield "unset $index" => ['unsetEmailAddresses', [$invalid, 'password']];
        }
    }

    #[DataProvider('secretFailures')]
    public function testRequestAndResponseSecretsStayOutOfLibraryTraces(string $route, string $method): void
    {
        [$client, $http] = self::client($route);
        $secret = 'synthetic-private-token-password';
        $response = $method === 'getPushbulletAccessToken'
            ? self::envelope($route, '{"private":"' . $secret . '"}')
            : str_replace('Synthetic failure', $secret, self::envelope($route, null, 0));
        $http->addResponse(new Response(200, [], $response));
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $contact = $client->uapi('npr')->contactInformation();
            switch ($method) {
                case 'getPushbulletAccessToken':
                    $contact->getPushbulletAccessToken();
                    break;
                case 'setPushbulletAccessToken':
                    $contact->setPushbulletAccessToken($secret);
                    break;
                case 'setEmailAddresses':
                    $contact->setEmailAddresses(['new@example.test'], ['old@example.test'], $secret);
                    break;
                default:
                    $contact->unsetEmailAddresses(['old@example.test'], $secret);
            }
            self::fail('Synthetic failure must throw.');
        } catch (ApiException $error) {
            foreach ($error->getTrace() as $frame) {
                if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    continue;
                }
                $seen = [];
                self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
            }
            self::assertStringNotContainsString($secret, $error->getMessage());
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function secretFailures(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['getPushbulletAccessToken', 'setPushbulletAccessToken', 'setEmailAddresses',
                'unsetEmailAddresses'] as $method
            ) {
                yield "$route $method" => [$route, $method];
            }
        }
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

    /** @return list<array{string, string}> */
    private static function operations(): array
    {
        return [
            ['ContactInformation', 'get_notification_preferences'],
            ['ContactInformation', 'get_pushbullet_access_token'],
            ['ContactInformation', 'set_email_addresses'], ['ContactInformation', 'set_pushbullet_access_token'],
            ['ContactInformation', 'unset_email_addresses'], ['Contactus', 'is_enabled'],
            ['Locale', 'get_attributes'], ['Locale', 'list_locales'], ['Locale', 'set_locale'],
            ['Themes', 'list'], ['Themes', 'update'], ['Notifications', 'get_notifications_count'],
        ];
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        switch ($function) {
            case 'set_email_addresses':
                $account->contactInformation()->setEmailAddresses(
                    addresses: ['new+tag@example.test', 'second@example.test'],
                    oldAddresses: ['old@example.test', 'other@example.test'],
                    password: 'p a+&=%/雪',
                );
                return null;
            case 'unset_email_addresses':
                $account->contactInformation()->unsetEmailAddresses(
                    oldAddresses: ['old@example.test', 'other@example.test'],
                    password: 'p a+&=%/雪',
                );
                return null;
            case 'set_pushbullet_access_token':
                $account->contactInformation()->setPushbulletAccessToken(pushbulletAccessToken: 'p a+&=%/雪');
                return null;
        }
        return match ($function) {
            'get_notification_preferences' => $account->contactInformation()->getNotificationPreferences(),
            'get_pushbullet_access_token' => $account->contactInformation()->getPushbulletAccessToken(),
            'is_enabled' => $account->contactUs()->isEnabled(),
            'get_attributes' => $account->locale()->getAttributes(),
            'list_locales' => $account->locale()->listLocales(),
            'set_locale' => $account->locale()->setLocale(locale: 'sr +&=%/雪'),
            'list' => $account->themes()->listThemes(showMailThemes: false),
            'update' => $account->themes()->update(theme: 'custom +&=%/雪'),
            default => $account->notifications()->getNotificationsCount(),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $module, string $function): void
    {
        $parameters = match ($function) {
            'set_email_addresses' => 'address=new%2Btag%40example.test&address=second%40example.test&'
                . 'old_address=old%40example.test&old_address=other%40example.test'
                . '&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'unset_email_addresses' => 'old_address=old%40example.test&old_address=other%40example.test'
                . '&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'set_pushbullet_access_token' => 'pushbullet_access_token=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'set_locale' => 'locale=sr%20%2B%26%3D%25%2F%E9%9B%AA',
            'list' => 'show_mail_themes=0',
            'update' => 'theme=custom%20%2B%26%3D%25%2F%E9%9B%AA',
            default => '',
        };
        $post = in_array($function, ['get_pushbullet_access_token', 'set_email_addresses',
            'set_pushbullet_access_token', 'unset_email_addresses', 'set_locale', 'update'], true);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        if (!$post && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($post ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($post) {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
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
