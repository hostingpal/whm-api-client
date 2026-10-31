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
use ReflectionParameter;
use SensitiveParameter;

/** Literal oracles from pinned contracts and reviewed upstream source; no Team live behavior is asserted. */
final class UapiTeamTest extends TestCase
{
    #[DataProvider('sourceCorrectionPayloads')]
    public function testReviewedSourceResponsesPreserveTeamData(
        string $route,
        string $function,
        ?string $format,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        $actual = $function === 'list_team'
            ? $account->team()->listTeam(format: $format)
            : self::invoke($account, $function);

        self::assertSame($expected, $actual);
        self::assertRequest($http, $route, $function, $format === 'hash' ? 'format=hash' : self::parameters($function));
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string|null, string, mixed}> */
    public static function sourceCorrectionPayloads(): iterable
    {
        $cases = [
            'creation without expiry' => ['add_team_user', null, 'null', null],
            'creation with quoted epoch' => ['add_team_user', null, '"1674575562"', 1674575562],
            'set expiry quoted epoch' => ['set_expire', null, '"1674575562"', 1674575562],
            'unset dates and text creation epoch' => ['list_team', null,
                '[{"username":"alice","contact_email":"alice@example.test","secondary_contact_email":"",'
                    . '"created":"1650036110","expire_date":"","lastlogin":"","suspend_date":"",'
                    . '"suspend_reason":"","expire_reason":""}]',
                [['contact_email' => 'alice@example.test', 'created' => 1650036110, 'expire_date' => '',
                    'expire_reason' => '', 'lastlogin' => '', 'secondary_contact_email' => '',
                    'suspend_date' => '', 'suspend_reason' => '', 'username' => 'alice']]],
            'two named users in hash' => ['list_team', 'hash',
                '{"owner":"npr","users":{"alice":{"username":"alice","contact_email":"alice@example.test",'
                    . '"roles":["email"]},"bob":{"username":"bob","contact_email":"bob@example.test",'
                    . '"roles":["web"]}}}',
                ['owner' => 'npr', 'users' => [
                    'alice' => ['contact_email' => 'alice@example.test', 'roles' => ['email'], 'username' => 'alice'],
                    'bob' => ['contact_email' => 'bob@example.test', 'roles' => ['web'], 'username' => 'bob'],
                ]]],
            'empty creation and subaccount identifiers' => ['list_team', null,
                '[{"created":"","subacct_guid":""},{"created":"1650036110","subacct_guid":"guid-alice"}]',
                [['created' => '', 'subacct_guid' => ''], ['created' => 1650036110, 'subacct_guid' => 'guid-alice']]],
            'hash version and empty creation' => ['list_team', 'hash',
                '{"version":"v1.1","users":{"alice":{"created":"","subacct_guid":"guid-alice"},'
                    . '"bob":{"created":"","subacct_guid":""}}}',
                ['users' => ['alice' => ['created' => '', 'subacct_guid' => 'guid-alice'],
                    'bob' => ['created' => '', 'subacct_guid' => '']], 'version' => 'v1.1']],
            'empty hash version' => ['list_team', 'hash', '{"version":""}', ['version' => '']],
            'two role descriptions' => ['list_feature_descriptions', null,
                '[{"features":["Backup"],"id":"web","title":"Web"},'
                    . '{"features":[],"id":"database","title":"Database"}]',
                [
            ['features' => ['Backup'], 'id' => 'web', 'title' => 'Web'],
                    ['features' => [], 'id' => 'database', 'title' => 'Database']]],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$function, $format, $payload, $expected]) {
                yield "$route $name" => [$route, $function, $format, $payload, $expected];
            }
        }
    }

    public function testSelectorsBindAccountsWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        self::assertSame($account->team(), $account->team());
        self::assertSame($account->teamRoles(), $account->teamRoles());
        self::assertNotSame($account->team(), $client->uapi('other')->team());
        self::assertNotSame($account->teamRoles(), $client->uapi('other')->teamRoles());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        foreach (['addTeamUser', 'editTeamUser', 'setPassword'] as $method) {
            $parameter = new ReflectionParameter([$account->team(), $method], 'password');
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
    }

    #[DataProvider('successfulPayloads')]
    public function testExactRequestsAndReviewedPayloads(
        string $route,
        string $function,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $function));
        self::assertRequest($http, $route, $function, self::parameters($function));
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function successfulPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::functions() as $function) {
                foreach (self::validCases($function) as $index => [$payload, $expected]) {
                    yield "$route $function $index" => [$route, $function, $payload, $expected];
                }
            }
        }
    }

    /** @return list<array{string, mixed}> */
    private static function validCases(string $function): array
    {
        if ($function === 'add_team_user' || $function === 'set_expire') {
            $cases = [
            ['1674575562', 1674575562], ['0', 0], ['-1', -1], ['"1674575562"', 1674575562],
                ['"0"', 0], ['"-1"', -1], ['"' . PHP_INT_MAX . '"', PHP_INT_MAX],
                ['"' . PHP_INT_MIN . '"', PHP_INT_MIN]];
            if ($function === 'add_team_user') {
                array_unshift($cases, ['null', null]);
            }
            return $cases;
        }
        if ($function === 'get_team_users_with_roles_count') {
            return [
            ['{}', []], ['{"max":7,"used":0,"future":true}', ['max' => 7, 'used' => 0]],
                ['{"used":2}', ['used' => 2]]];
        }
        if ($function === 'list_feature_descriptions') {
            return [
            ['[]', []], ['[{}]', [[]]], ['[{"features":["Backup","MySQL® Databases","PHP",""],'
                . '"id":"database","title":"Database","future":0}]',
                [['features' => ['Backup', 'MySQL® Databases', 'PHP', ''], 'id' => 'database', 'title' => 'Database']]],
                ['[{"features":[],"id":"","title":""}]', [['features' => [], 'id' => '', 'title' => '']]]];
        }
        if ($function === 'list_team') {
            // The password is synthetic fixture payload data and must be preserved, never silently removed.
            $info = ['contact-email' => 'team@example.test', 'created' => 0, 'expire_date' => 1674575562,
                'expire_reason' => '', 'lastlogin' => 1650046110, 'locale' => 'en', 'notes' => '',
                'password' => 'synthetic-encrypted-password', 'roles' => ['email', 'web'],
                'secondary-contact-email' => '', 'services' => ['email' => '500*2**20', 'ftp' => 0, 'webdisk' => 'rw'],
                'suspend_date' => 1650046210, 'suspend_reason' => 'vacation', 'username' => 'fixture'];
            $hash = ['owner' => 'npr', 'users' => ['fixture' => $info]];
            return [
            ['[]', []], ['{}', []], [json_encode([$info], JSON_THROW_ON_ERROR), [$info]],
                [json_encode($hash, JSON_THROW_ON_ERROR), $hash],
                ['[{"roles":[],"services":{}},{}]', [['roles' => [], 'services' => []], []]],
                ['{"users":{"fixture":{"password":"","suspend_date":"","services":{"ftp":1}}}}',
                    ['users' => ['fixture' => ['password' => '', 'services' => ['ftp' => 1], 'suspend_date' => '']]]]];
        }
        return [
        ['null', null], ['{}', []], ['[]', []],
            ['{"opaque":{"zero":0,"none":null,"empty":[]},"password":"synthetic"}',
                ['opaque' => ['zero' => 0, 'none' => null, 'empty' => []], 'password' => 'synthetic']]];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidAndAbsentPayloadsRetainConfirmedOutcome(
        string $route,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validCases($function)[0][0])));
        self::invoke($account, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $function);
            self::fail('Invalid Team payload must fail after the confirmed API outcome.');
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

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::functions() as $function) {
                $cases = [null, 'true', 'false', '1.5', '"value"'];
                if ($function === 'add_team_user' || $function === 'set_expire') {
                    array_push(
                        $cases,
                        '[]',
                        '{}',
                        '""',
                        '"00"',
                        '"01"',
                        '"+1"',
                        '"-0"',
                        '"1.0"',
                        '"1e3"',
                        '" 1"',
                        '"1 "',
                        '"' . PHP_INT_MAX . '0"',
                        '"' . PHP_INT_MIN . '0"',
                    );
                    if ($function === 'set_expire') {
                        $cases[] = 'null';
                    }
                } else {
                    $cases[] = '1';
                    if ($function === 'get_team_users_with_roles_count') {
                        array_push($cases, 'null', '[{}]', '{"max":null}', '{"used":"0"}', '{"max":false}');
                    } elseif ($function === 'list_feature_descriptions') {
                        array_push(
                            $cases,
                            'null',
                            '{}',
                            '[null]',
                            '[{"features":{}}]',
                            '[{"features":[0]}]',
                            '[{"features":null}]',
                            '[{"id":0}]',
                            '[{"title":null}]',
                        );
                    } elseif ($function === 'list_team') {
                        array_push(
                            $cases,
                            'null',
                            '[null]',
                            '[0]',
                            '{"owner":null}',
                            '{"users":null}',
                            '{"users":{"info":null}}',
                            '{"users":{"username":1}}',
                            '[{"password":null}]',
                            '[{"password":1}]',
                            '[{"created":"invalid"}]',
                            '[{"expire_date":null}]',
                            '[{"roles":[1]}]',
                            '[{"roles":null}]',
                            '[{"services":{"email":0}}]',
                            '[{"services":{"ftp":"1"}}]',
                            '[{"services":{"webdisk":false}}]',
                            '[{"suspend_date":"invalid"}]',
                            '[{"contact_email":null}]',
                            '[{"secondary_contact_email":1}]',
                            '{"users":{"alice":{"contact_email":false}}}',
                            '{"users":{"info":{"password":null}}}',
                        );
                        foreach (['null', '1', 'false', '[]', '{}'] as $value) {
                            $cases[] = '{"version":' . $value . '}';
                            $cases[] = '[{"subacct_guid":' . $value . '}]';
                            $cases[] = '{"users":{"alice":{"subacct_guid":' . $value . '}}}';
                        }
                        foreach (['created', 'expire_date', 'lastlogin', 'suspend_date'] as $field) {
                            foreach (
                                ['null', 'true', '1.5', '"00"', '"01"', '"+1"', '"-0"', '"1.0"', '"1e3"',
                                '" 1"', '"1 "', '"' . PHP_INT_MAX . '0"', '"' . PHP_INT_MIN . '0"'] as $value
                            ) {
                                $cases[] = '[{"' . $field . '":' . $value . '}]';
                                $cases[] = '{"users":{"alice":{"' . $field . '":' . $value . '}}}';
                            }
                        }
                    } else {
                        $cases[] = '[{}]';
                    }
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $function $index" => [$route, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('outcomeCases')]
    public function testApiFailureIsNotAValidZeroOrNullablePayload(string $route, string $function): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
        $this->expectException(ApiErrorException::class);
        try {
            self::invoke($account, $function);
        } finally {
            self::assertSame(0, $account->responseMeta()?->uapiStatus());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function outcomeCases(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::functions() as $function) {
                yield "$route $function" => [$route, $function];
            }
        }
    }

    #[DataProvider('optionCases')]
    public function testOmissionNullEmptyFalseAndZeroUseDocumentedWireFields(
        string $route,
        string $function,
        string $variant,
        string $parameters,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validCases($function)[0][0])));
        $team = $account->team();
        match ($variant) {
            'add options' => $team->addTeamUser(email1: 'team@example.test', user: 'fixture', password: 'p a+&=%/雪',
                activationEmail: 0, email2: '', expireDate: 0, expireReason: '', notes: '', roles: 'email,database',
                servicesEmailEnabled: false, servicesEmailQuota: '0', servicesFtpEnabled: true,
                servicesFtpHomedir: '/Team +&', servicesWebdiskEnabled: false, servicesWebdiskEnabledigest: false,
                servicesWebdiskHomedir: '', servicesWebdiskPerms: 'ro', servicesWebdiskPrivate: false),
            'add nulls' => $team->addTeamUser(email1: 'team@example.test', user: 'fixture', password: null,
                activationEmail: null, expireDate: null, servicesEmailEnabled: null),
            'edit options' => $team->editTeamUser(user: 'fixture', addRole: '', email1: '', email2: '',
                expireReason: '', notes: '', password: '', removeRole: 'email', servicesEmailEnabled: false,
                servicesEmailQuota: 'unlimited', servicesFtpEnabled: false, servicesFtpHomedir: '',
                servicesWebdiskEnabled: true, servicesWebdiskEnabledigest: true, servicesWebdiskHomedir: '/Team',
                servicesWebdiskPerms: '', servicesWebdiskPrivate: true, setExpire: '120days', setRole: 'web'),
            'edit nulls' => $team->editTeamUser(user: 'fixture', password: null, servicesFtpEnabled: null,
                setExpire: null, setRole: null),
            'hash' => $team->listTeam(format: 'hash'),
            'empty format' => $team->listTeam(format: ''),
            'null format' => $team->listTeam(format: null),
            'empty contacts' => $team->setContactEmail(user: 'fixture', email1: '', email2: ''),
            'null contacts' => $team->setContactEmail(user: 'fixture', email1: null, email2: null),
            'zero date' => $team->setExpire(date: 0, user: 'fixture', reason: ''),
            'empty role' => $team->setRoles(user: 'fixture', role: ''),
            'null role' => $team->setRoles(user: 'fixture', role: null),
            'empty reason' => $team->suspendTeamUser(user: 'fixture', reason: ''),
            default => $team->suspendTeamUser(user: 'fixture', reason: null),
        };
        self::assertRequest($http, $route, $function, $parameters);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function optionCases(): iterable
    {
        $cases = [
            ['add_team_user', 'add options', 'email1=team%40example.test&user=fixture&activation_email=0&email2='
                . '&expire_date=0&expire_reason=&notes=&password=p%20a%2B%26%3D%25%2F%E9%9B%AA&roles=email%2Cdatabase'
                . '&services.email.enabled=0&services.email.quota=0&services.ftp.enabled=1'
                . '&services.ftp.homedir=%2FTeam%20%2B%26'
                . '&services.webdisk.enabled=0&services.webdisk.enabledigest=0&services.webdisk.homedir='
                . '&services.webdisk.perms=ro&services.webdisk.private=0'],
            ['add_team_user', 'add nulls', 'email1=team%40example.test&user=fixture'],
            ['edit_team_user', 'edit options', 'user=fixture&add_role=&email1=&email2=&expire_reason=&notes=&password='
                . '&remove_role=email&services.email.enabled=0&services.email.quota=unlimited&services.ftp.enabled=0'
                . '&services.ftp.homedir=&services.webdisk.enabled=1&services.webdisk.enabledigest=1'
                . '&services.webdisk.homedir=%2FTeam&services.webdisk.perms=&services.webdisk.private=1'
                . '&set_expire=120days&set_role=web'],
            ['edit_team_user', 'edit nulls', 'user=fixture'],
            ['list_team', 'hash', 'format=hash'], ['list_team', 'empty format', 'format='],
            ['list_team', 'null format', ''],
            ['set_contact_email', 'empty contacts', 'user=fixture&email1=&email2='],
            ['set_contact_email', 'null contacts', 'user=fixture'],
            ['set_expire', 'zero date', 'date=0&user=fixture&reason='],
            ['set_roles', 'empty role', 'user=fixture&role='], ['set_roles', 'null role', 'user=fixture'],
            ['suspend_team_user', 'empty reason', 'user=fixture&reason='],
            ['suspend_team_user', 'null reason', 'user=fixture'],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as [$function, $variant, $parameters]) {
                yield "$route $variant" => [$route, $function, $variant, $parameters];
            }
        }
    }

    /** @return list<string> */
    private static function functions(): array
    {
        return ['add_roles', 'add_team_user', 'cancel_expire', 'edit_team_user', 'get_team_users_with_roles_count',
            'list_team', 'password_reset_request', 'reinstate_team_user', 'remove_roles', 'remove_team_user',
            'set_contact_email', 'set_expire', 'set_locale', 'set_notes', 'set_password', 'set_roles',
            'suspend_team_user', 'list_feature_descriptions'];
    }

    private static function invoke(UapiClient $account, string $function): mixed
    {
        $team = $account->team();
        return match ($function) {
            'add_roles' => $team->addRoles(role: 'email,web', user: 'fixture'),
            'add_team_user' => $team->addTeamUser(email1: 'team@example.test', user: 'fixture', password: 'p a+&=%/雪'),
            'cancel_expire' => $team->cancelExpire(user: 'fixture'),
            'edit_team_user' => $team->editTeamUser(user: 'fixture'),
            'get_team_users_with_roles_count' => $team->getTeamUsersWithRolesCount(),
            'list_team' => $team->listTeam(),
            'password_reset_request' => $team->passwordResetRequest(user: 'fixture'),
            'reinstate_team_user' => $team->reinstateTeamUser(user: 'fixture'),
            'remove_roles' => $team->removeRoles(role: 'email,web', user: 'fixture'),
            'remove_team_user' => $team->removeTeamUser(user: 'fixture'),
            'set_contact_email' => $team->setContactEmail(user: 'fixture'),
            'set_expire' => $team->setExpire(date: '120days', user: 'fixture'),
            'set_locale' => $team->setLocale(locale: 'es_es', user: 'fixture'),
            'set_notes' => $team->setNotes(notes: 'a +&=%/雪', user: 'fixture'),
            'set_password' => $team->setPassword(password: 'p a+&=%/雪', user: 'fixture'),
            'set_roles' => $team->setRoles(user: 'fixture'),
            'suspend_team_user' => $team->suspendTeamUser(user: 'fixture'),
            default => $account->teamRoles()->listFeatureDescriptions(),
        };
    }

    private static function parameters(string $function): string
    {
        return match ($function) {
            'add_roles', 'remove_roles' => 'role=email%2Cweb&user=fixture',
            'add_team_user' => 'email1=team%40example.test&user=fixture&password=p%20a%2B%26%3D%25%2F%E9%9B%AA',
            'get_team_users_with_roles_count', 'list_team', 'list_feature_descriptions' => '',
            'set_expire' => 'date=120days&user=fixture',
            'set_locale' => 'locale=es_es&user=fixture',
            'set_notes' => 'notes=a%20%2B%26%3D%25%2F%E9%9B%AA&user=fixture',
            'set_password' => 'password=p%20a%2B%26%3D%25%2F%E9%9B%AA&user=fixture',
            default => 'user=fixture',
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $function, string $parameters): void
    {
        $module = $function === 'list_feature_descriptions' ? 'TeamRoles' : 'Team';
        $post = !in_array(
            $function,
            ['get_team_users_with_roles_count', 'list_team', 'list_feature_descriptions'],
            true,
        );
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
