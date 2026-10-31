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

/** Independent literal contracts from the pinned UserManager, WebDisk, Users and PasswdStrength sources. */
final class UapiUserManagementTest extends TestCase
{
    public function testSelectorsBindAccountsAndPasswordsRemainSensitive(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        foreach (['userManager', 'webDisk', 'users', 'passwdStrength'] as $accessor) {
            self::assertSame($account->$accessor(), $account->$accessor());
            self::assertNotSame($account->$accessor(), $client->uapi('other')->$accessor());
        }
        self::assertSame([], $http->getRequests());
        self::assertNull($account->responseMeta());
        foreach (
            [
            [$account->userManager(), 'createUser', 'password'],
            [$account->userManager(), 'editUser', 'password'],
            [$account->webDisk(), 'setPassword', 'password'],
            [$account->userManager(), 'changePassword', 'newpass'],
            [$account->userManager(), 'changePassword', 'oldpass'],
            [$account->users(), 'changePassword', 'newpass'],
            [$account->users(), 'changePassword', 'oldpass']] as [$group, $method, $argument]
        ) {
            $parameter = new ReflectionParameter([$group, $method], $argument);
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
        $password = new ReflectionParameter([$account->userManager(), 'createUser'], 'password');
        self::assertFalse($password->isOptional());
    }

    #[DataProvider('successfulPayloads')]
    public function testLiteralRequestsAndPayloads(
        string $route,
        string $module,
        string $function,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame(self::ordered($expected), self::ordered(self::invoke($account, $module, $function)));
        self::assertRequest($http, $route, $module, $function, self::parameters($module, $function));
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
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
        }
    }

    /** @return list<array{string, mixed}> */
    private static function validCases(string $module, string $function): array
    {
        if ($function === 'get_required_strength') {
            return [
            ['{}', []], ['{"strength":0,"future":1}', ['strength' => 0]],
                ['{"strength":100}', ['strength' => 100]]];
        }
        if ($module !== 'UserManager' || in_array($function, ['change_password', 'unlink_service_account'], true)) {
            return [
            ['null', null], ['{}', []], ['[]', []],
                ['{"opaque":{"empty":[],"null":null,"zero":0,"text":""}}',
                    ['opaque' => ['empty' => [], 'null' => null, 'zero' => 0, 'text' => '']]]];
        }
        if ($function === 'dismiss_merge') {
            return [
            ['null', null], ['"untyped"', 'untyped'], ['1', 1], ['false', false],
                ['[0,null,{}]', [0, null, []]], ['{"opaque":true}', ['opaque' => true]]];
        }
        if ($function === 'list_users') {
            $row = self::listRow();
            return [
            ['[]', []], [json_encode([$row], JSON_THROW_ON_ERROR), [$row]],
                ['[{"synced_password":null,"parent_type":null,"sub_account_exists":null,"services":{},'
                    . '"issues":[],"merge_candidates":[],"dismissed_merge_candidates":[],"future":true}]',
                    [['dismissed_merge_candidates' => [], 'issues' => [], 'merge_candidates' => [],
                        'parent_type' => null, 'services' => [], 'sub_account_exists' => null,
                        'synced_password' => null]]], ['[{},{}]', [[], []]]];
        }
        if ($function === 'check_account_conflicts') {
            $account = ['alternate_email' => null, 'avatar_url' => null, 'can_delete' => 0,
                'can_set_password' => 1, 'can_set_quota' => 0, 'dismissed' => 1,
                'dismissed_merge_candidates' => [], 'domain' => 'example.test',
                'full_username' => 'fixture@example.test', 'guid' => 'synthetic-guid',
                'has_expired_invite' => 0, 'has_invite' => 0, 'has_siblings' => 1,
                'invite_expiration' => null, 'issues' => [self::issue()],
                'merge_candidates' => [self::conflictCandidate()]];
            return [
            ['{}', []], [json_encode(['accounts' => $account, 'conflict' => 1], JSON_THROW_ON_ERROR),
                ['accounts' => $account, 'conflict' => 1]],
                ['{"accounts":{"merge_candidates":[],"dismissed_merge_candidates":[]},"conflict":0}',
                    ['accounts' => ['dismissed_merge_candidates' => [], 'merge_candidates' => []], 'conflict' => 0]]];
        }
        $base = self::listRow();
        unset($base['dismissed_merge_candidates'], $base['merge_candidates']);
        unset($base['services']['special']);
        if ($function === 'create_user') {
            $base['services']['ftp']['quota'] = '0.00';
            $base['parent_type'] = null;
            $base['sub_account_exists'] = null;
            $base['synced_password'] = null;
            $candidate = $base;
            unset($candidate['has_expired_invite'], $candidate['has_invite'], $candidate['invite_expiration']);
            $base['dismissed_merge_candidates'] = [$candidate];
            $base['merge_candidates'] = [$candidate];
        } elseif ($function === 'edit_user' || $function === 'lookup_service_account') {
            $candidate = $base;
            $candidate['merge_candidates'] = [['opaque' => ['kept' => true]]];
            $base['merge_candidates'] = [$candidate];
            $base['dismissed_merge_candidates'] = [['merge_candidates' => [$candidate]]];
        } elseif ($function === 'lookup_user') {
            $base['alternate_email'] = null;
            $base['avatar_url'] = null;
            $base['domain'] = null;
            $base['phone_number'] = null;
            $base['real_name'] = null;
            $base['invite_expiration'] = null;
            $base['services']['special'] = 0;
            $base['services']['webdisk']['enabledigest'] = 0;
            $base['dismissed_merge_candidates'] = [];
            $base['merge_candidates'] = [];
        } elseif ($function === 'delete_user') {
            $base['invite_expiration'] = null;
            $base['type'] = 'hypothetical';
            $base['dismissed_merge_candidates'] = [['opaque' => ['kept' => null]]];
            $base['merge_candidates'] = [['alternate_email' => '', 'avatar_url' => '', 'can_delete' => 0,
                'can_set_password' => 1, 'can_set_quota' => 0, 'dismissed' => 0,
                'dismissed_merge_candidates' => [], 'domain' => 'example.test',
                'full_username' => 'fixture@example.test', 'guid' => 'synthetic-candidate',
                'has_expired_invite' => 0, 'has_invite' => 0, 'has_siblings' => 0,
                'invite_expiration' => 0, 'issues' => [], 'merge_candidates' => [],
                'parent_type' => 'sub', 'phone_number' => '', 'real_name' => '',
                'services' => ['opaque' => true], 'special' => 0, 'sub_account_exists' => 0,
                'synced_password' => 1, 'type' => 'service', 'username' => 'fixture']];
        } else {
            $candidate = $base;
            unset(
                $candidate['dismissed'],
                $candidate['has_expired_invite'],
                $candidate['has_invite'],
                $candidate['invite_expiration'],
            );
            $base['dismissed_merge_candidates'] = [$candidate];
            $base['merge_candidates'] = [$candidate];
        }
        return [['{}', []], [json_encode($base, JSON_THROW_ON_ERROR), $base]];
    }

    /** Object member order is immaterial; list order and scalar types remain strict. */
    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::ordered(...), $value);
    }

    /**
     * @return array{
     *     alternate_email: string, avatar_url: string, can_delete: int, can_set_password: int,
     *     can_set_quota: int, dismissed: int, dismissed_merge_candidates: array{}, domain: string,
     *     full_username: string, guid: string, has_expired_invite: int, has_invite: int, has_siblings: int,
     *     invite_expiration: int, issues: list<array{area: string, limit: int, message: string,
     *         service: string, type: string, used: int}>, merge_candidates: array{}, parent_type: string,
     *     phone_number: string, real_name: string, services: array{
     *         email: array{enabled: int, quota: int}, ftp: array{enabled: int, homedir: string, quota: int},
     *         special: int, webdisk: array{enabled: int, homedir: string, perms: string, private: int}
     *     }, special: int, sub_account_exists: int, synced_password: int, type: string, username: string
     * }
     */
    private static function listRow(): array
    {
        return ['alternate_email' => '', 'avatar_url' => '', 'can_delete' => 0,
            'can_set_password' => 1, 'can_set_quota' => 0, 'dismissed' => 0,
            'dismissed_merge_candidates' => [], 'domain' => 'example.test',
            'full_username' => 'fixture@example.test', 'guid' => 'synthetic-guid',
            'has_expired_invite' => 0, 'has_invite' => 1, 'has_siblings' => 0,
            'invite_expiration' => 1600162200, 'issues' => [self::issue()], 'merge_candidates' => [],
            'parent_type' => 'sub', 'phone_number' => '+15551234567', 'real_name' => '',
            'services' => ['email' => ['enabled' => 1, 'quota' => 500],
                'ftp' => ['enabled' => 0, 'homedir' => '/fixture', 'quota' => 0], 'special' => 0,
                'webdisk' => ['enabled' => 1, 'homedir' => '/fixture', 'perms' => 'rw', 'private' => 0]],
            'special' => 0, 'sub_account_exists' => 1, 'synced_password' => 0, 'type' => 'sub',
            'username' => 'fixture'];
    }

    /** @return array{area: string, limit: int, message: string, service: string, type: string, used: int} */
    private static function issue(): array
    {
        return ['area' => 'quota', 'limit' => 1500, 'message' => 'Synthetic quota issue',
            'service' => 'email', 'type' => 'warning', 'used' => 2000];
    }

    /** @return array<string, mixed> */
    private static function conflictCandidate(): array
    {
        return ['parent_type' => null, 'phone_number' => '', 'real_name' => '',
            'services' => ['email' => ['enabled' => 1, 'quota' => 0],
                'ftp' => ['enabled' => 0, 'homedir' => '/fixture', 'quota' => 0],
                'webdisk' => ['enabled' => 1, 'homedir' => '/fixture', 'perms' => 'ro', 'private' => 1]],
            'special' => 0, 'sub_account_exists' => null, 'synced_password' => null,
            'type' => 'hypothetical', 'username' => 'fixture'];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsRetainConfirmedOutcome(
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
            self::fail('Malformed payload must fail after the API confirms the operation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                $cases = [null];
                if ($function === 'dismiss_merge') {
                    yield "$route $module/$function missing" => [$route, $module, $function, null];
                    continue;
                }
                if ($function !== 'delete_user' || $module !== 'UserManager') {
                    $cases = [null, 'false', '1', '1.5', '"text"'];
                } else {
                    $cases = ['false', '1', '1.5', '"text"'];
                }
                if ($function === 'change_password') {
                    $cases = [null, 'false', '1', '1.5', '["text"]'];
                }
                if (
                    $module === 'UserManager'
                    && !in_array($function, ['change_password', 'unlink_service_account'], true)
                ) {
                    array_push($cases, 'null');
                    if ($function === 'delete_user') {
                        array_pop($cases);
                    }
                    if ($function === 'list_users') {
                        array_push(
                            $cases,
                            '{}',
                            '[null]',
                            '[true]',
                            '[1]',
                            '[{"synced_password":2}]',
                            '[{"services":{"email":{"quota":"500"}}}]',
                            '[{"issues":[{"used":"1"}]}]',
                            '[{"merge_candidates":["not-a-record"]}]',
                            '[{"dismissed_merge_candidates":["not-a-record"]}]',
                            '[{"merge_candidates":[{"real_name":false}]}]',
                            '[{"dismissed_merge_candidates":[{"services":{"email":{"enabled":2}}}]}]',
                            '[{"full_username":null}]',
                            '[{"can_delete":null}]',
                            '[{"services":{"email":{"quota":null}}}]',
                            '[{"services":{"ftp":{"homedir":null}}}]',
                            '[{"alternate_email":false}]',
                            '[{"avatar_url":0}]',
                            '[{"domain":1}]',
                            '[{"invite_expiration":"0"}]',
                            '[{"phone_number":0}]',
                            '[{"real_name":false}]',
                            '[{"services":{"ftp":{"quota":"0"}}}]',
                            '[{"services":{"ftp":{"quota":0.5}}}]',
                        );
                    } elseif ($function === 'check_account_conflicts') {
                        array_push(
                            $cases,
                            '{"conflict":2}',
                            '{"accounts":[1]}',
                            '{"accounts":{"issues":[{"used":"1"}]}}',
                        );
                    } else {
                        array_push(
                            $cases,
                            '{"can_delete":2}',
                            '{"services":{"webdisk":{"enabled":true}}}',
                            '{"issues":[{"used":"1"}]}',
                        );
                        if ($function === 'edit_user' || $function === 'lookup_service_account') {
                            array_push(
                                $cases,
                                '{"synced_password":"0"}',
                                '{"merge_candidates":[{"sub_account_exists":true}]}',
                                '{"dismissed_merge_candidates":[{"synced_password":2}]}',
                            );
                        }
                        if ($function === 'create_user') {
                            $cases[] = '{"services":{"ftp":{"quota":0}}}';
                        }
                        if ($function === 'delete_user') {
                            $cases[] = '{"alternate_email":null}';
                        }
                        if ($function !== 'delete_user') {
                            array_push(
                                $cases,
                                '{"alternate_email":false}',
                                '{"avatar_url":0}',
                                '{"domain":1}',
                                '{"invite_expiration":"0"}',
                                '{"phone_number":0}',
                                '{"real_name":false}',
                                '{"parent_type":false}',
                                '{"sub_account_exists":2}',
                                '{"services":{"email":{"quota":null}}}',
                                '{"services":{"ftp":{"homedir":null}}}',
                                '{"services":{"ftp":{"quota":false}}}',
                                '{"merge_candidates":["not-a-record"]}',
                                '{"dismissed_merge_candidates":[null]}',
                                '{"merge_candidates":[{"real_name":false}]}',
                                '{"dismissed_merge_candidates":[{"services":{"email":{"enabled":2}}}]}',
                            );
                            if ($function !== 'create_user') {
                                $cases[] = '{"services":{"ftp":{"quota":"0"}}}';
                            }
                        }
                        if ($function === 'lookup_user') {
                            array_push(
                                $cases,
                                '{"synced_password":true}',
                                '{"dismissed_merge_candidates":[{"sub_account_exists":"0"}]}',
                            );
                        }
                    }
                } elseif ($function === 'get_required_strength') {
                    array_push(
                        $cases,
                        'null',
                        '[1]',
                        '{"strength":"50"}',
                        '{"strength":false}',
                        '{"strength":null}',
                        '{"strength":50.5}',
                    );
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload];
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $expected */
    #[DataProvider('observedNullableListFields')]
    public function testObservedNullListFieldsRemainDistinctFromOmission(
        string $route,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $account->userManager()->listUsers(flat: true));
        self::assertRequest($http, $route, 'UserManager', 'list_users', 'flat=1');
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, list<array<string, mixed>>}> */
    public static function observedNullableListFields(): iterable
    {
        // Direct UAPI on 11.138.0.19 at 5cae92f: immutable capture SHA-256 b0bbf85e...0c04116.
        // Other routes below exercise the common offline contract, not a claim of observed bridge success.
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['alternate_email', 'avatar_url', 'domain', 'invite_expiration',
                'phone_number', 'real_name'] as $field
            ) {
                yield "$route $field" => [$route, '[{"' . $field . '":null}]', [[$field => null]]];
            }
            yield "$route services.ftp.quota" => [$route, '[{"services":{"ftp":{"quota":null}}}]',
                [['services' => ['ftp' => ['quota' => null]]]]];
            yield "$route all observed nulls" => [$route,
                '[{"alternate_email":null,"avatar_url":null,"domain":null,"invite_expiration":null,'
                    . '"phone_number":null,"real_name":null,"services":{"ftp":{"quota":null}}}]',
                [['alternate_email' => null, 'avatar_url' => null, 'domain' => null, 'invite_expiration' => null,
                    'phone_number' => null, 'real_name' => null, 'services' => ['ftp' => ['quota' => null]]]]];
        }
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $expected */
    #[DataProvider('sharedNullableRecords')]
    public function testSharedNullFieldsDoNotRejectSuccessfulUserOperations(
        string $route,
        string $function,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            json_encode($expected, JSON_THROW_ON_ERROR),
        )));
        try {
            $actual = self::invoke($account, 'UserManager', $function);
        } catch (PayloadMismatchException) {
            self::fail('A successful shared Subaccount record must preserve its evidenced null fields.');
        } finally {
            self::assertCount(1, $http->getRequests());
        }
        self::assertSame(self::ordered($expected), self::ordered($actual));
        self::assertRequest($http, $route, 'UserManager', $function, self::parameters('UserManager', $function));
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string, string, array<string, mixed>|list<array<string, mixed>>}> */
    public static function sharedNullableRecords(): iterable
    {
        // Fixed family from review 5720337990, the original list capture and pinned ServiceAccount null prose.
        // Clarification 6537241960 identifies the same finite Record for both candidate lists.
        $base = ['alternate_email' => null, 'avatar_url' => null, 'domain' => null,
            'parent_type' => null, 'phone_number' => null, 'real_name' => null,
            'services' => ['ftp' => ['quota' => null]], 'sub_account_exists' => null, 'synced_password' => null];
        $invited = $base + ['invite_expiration' => null];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['create_user', 'edit_user', 'merge_service_account',
                'lookup_service_account', 'lookup_user'] as $function
            ) {
                yield "$route $function root" => [$route, $function, $invited];
            }
            yield "$route list_users root" => [$route, 'list_users', [$invited]];
            foreach (['create_user', 'merge_service_account'] as $function) {
                foreach (['merge_candidates', 'dismissed_merge_candidates'] as $field) {
                    yield "$route $function $field" => [$route, $function, [$field => [$base]]];
                }
            }
            foreach (['edit_user', 'lookup_service_account'] as $function) {
                yield "$route $function merge candidate" => [$route, $function,
                    ['merge_candidates' => [$invited]]];
                yield "$route $function dismissed candidate" => [$route, $function,
                    ['dismissed_merge_candidates' => [$invited]]];
            }
            yield "$route lookup_user dismissed candidate" => [$route, 'lookup_user',
                ['dismissed_merge_candidates' => [$base]]];
        }
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $expected */
    #[DataProvider('finiteCandidateRecords')]
    public function testCandidateRecordsPreserveFieldsAndOpaqueNestedLists(
        string $route,
        string $function,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            json_encode($expected, JSON_THROW_ON_ERROR),
        )));
        try {
            $actual = self::invoke($account, 'UserManager', $function);
        } catch (PayloadMismatchException) {
            self::fail('Candidates are records whose own candidate lists retain opaque contents.');
        } finally {
            self::assertCount(1, $http->getRequests());
        }
        self::assertSame(self::ordered($expected), self::ordered($actual));
    }

    /** @return iterable<string, array{string, string, array<string, mixed>|list<array<string, mixed>>}> */
    public static function finiteCandidateRecords(): iterable
    {
        // Literal known fields from pinned records and reviewer clarification 6537241960.
        // Only merge candidates were populated live; dismissed shape is source-inferred.
        $candidate = ['username' => 'fixture', 'domain' => 'example.test',
            'full_username' => 'fixture@example.test', 'guid' => 'synthetic-candidate',
            'type' => 'service', 'parent_type' => 'sub', 'real_name' => null, 'phone_number' => null,
            'alternate_email' => null, 'avatar_url' => null, 'invite_expiration' => null,
            'can_delete' => 0, 'dismissed' => 0, 'issues' => [self::issue()],
            'services' => ['email' => ['enabled' => 1, 'quota' => 0],
                'ftp' => ['enabled' => 0], 'webdisk' => ['enabled' => 0]],
            'merge_candidates' => [null, 'opaque', ['real_name' => false, 'future' => ['kept' => 1]]],
            'dismissed_merge_candidates' => [false, ['unknown' => ['nested' => null]]]];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['create_user', 'edit_user', 'merge_service_account', 'lookup_service_account',
                'lookup_user', 'list_users'] as $function
            ) {
                foreach (['merge_candidates', 'dismissed_merge_candidates'] as $field) {
                    $payload = [$field => [$candidate]];
                    yield "$route $function $field" => [$route, $function,
                        $function === 'list_users' ? [$payload] : $payload];
                }
            }
        }
    }

    #[DataProvider('passwordStringResults')]
    public function testSuccessfulPasswordChangesReturnOpaqueStringsWithoutRetry(
        string $route,
        string $module,
        string $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            json_encode($expected, JSON_THROW_ON_ERROR),
        )));
        try {
            $actual = self::invoke($account, $module, 'change_password');
        } catch (PayloadMismatchException) {
            self::fail('A successful password change must return the opaque string without a false payload failure.');
        } finally {
            self::assertCount(1, $http->getRequests());
        }
        self::assertSame($expected, $actual);
        self::assertRequest($http, $route, $module, 'change_password', self::parameters($module, 'change_password'));
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function passwordStringResults(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['Users', 'UserManager'] as $module) {
                foreach (['', '0', "Synthetic response; content is opaque.\n"] as $index => $value) {
                    yield "$route $module $index" => [$route, $module, $value];
                }
            }
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('nullableFlagPayloads')]
    public function testExplicitNullProseOverridesOnlyNamedFlagAndParentFields(
        string $route,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, 'UserManager', $function));
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function nullableFlagPayloads(): iterable
    {
        // These alternatives come from the explicit null prose in the pinned referenced base schemas.
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['edit_user', 'lookup_service_account'] as $function) {
                foreach (['parent_type', 'sub_account_exists', 'synced_password'] as $field) {
                    yield "$route $function root $field" => [$route, $function,
                        '{"' . $field . '":null}', [$field => null]];
                    yield "$route $function candidate $field" => [$route, $function,
                        '{"merge_candidates":[{"' . $field . '":null}]}',
                        ['merge_candidates' => [[$field => null]]]];
                    yield "$route $function dismissed candidate $field" => [$route, $function,
                        '{"dismissed_merge_candidates":[{"' . $field . '":null}]}',
                        ['dismissed_merge_candidates' => [[$field => null]]]];
                }
            }
            foreach (['sub_account_exists', 'synced_password'] as $field) {
                yield "$route lookup_user root $field" => [$route, 'lookup_user',
                    '{"' . $field . '":null}', [$field => null]];
                yield "$route lookup_user dismissed $field" => [$route, 'lookup_user',
                    '{"dismissed_merge_candidates":[{"' . $field . '":null}]}',
                    ['dismissed_merge_candidates' => [[$field => null]]]];
            }
        }
    }

    #[DataProvider('routes')]
    public function testDocumentedMetadataOnlyDelete(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        foreach ([null, 'null'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
            self::assertNull($account->userManager()->deleteUser(domain: 'example.test', username: 'fixture'));
        }
    }

    #[DataProvider('routes')]
    public function testOptionalArgumentsRemainOmittedWhileFalseZeroAndEmptyAreSent(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->userManager()->createUser(domain: 'example.test', password: 'synthetic', username: 'fixture');
        self::assertRequest(
            $http,
            $route,
            'UserManager',
            'create_user',
            'domain=example.test&password=synthetic&username=fixture',
        );
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->userManager()->editUser(
            domain: 'example.test',
            username: 'fixture',
            alternateEmail: null,
            realName: '',
            servicesEmailEnabled: false,
            servicesEmailQuota: 0,
            servicesFtpEnabled: false,
            servicesWebdiskEnabled: false,
        );
        self::assertRequest(
            $http,
            $route,
            'UserManager',
            'edit_user',
            'domain=example.test&username=fixture&real_name=&services.email.enabled=0&services.email.quota=0'
            . '&services.ftp.enabled=0&services.webdisk.enabled=0',
        );
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->userManager()->listUsers();
        self::assertRequest($http, $route, 'UserManager', 'list_users', '');
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
        $account->webDisk()->setHomedir(homedir: 'relative/', user: 'fixture@example.test', private: null);
        self::assertRequest(
            $http,
            $route,
            'WebDisk',
            'set_homedir',
            'homedir=relative%2F&user=fixture%40example.test',
        );
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailureIsNotMistakenForSuccessfulEmptyData(
        string $route,
        string $module,
        string $function,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}', 0)));
        try {
            self::invoke($account, $module, $function);
            self::fail('UAPI status zero must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
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

    /** @return list<array{string, string}> */
    private static function operations(): array
    {
        return [
        ['PasswdStrength', 'get_required_strength'], ['UserManager', 'change_password'],
            ['UserManager', 'check_account_conflicts'], ['UserManager', 'create_user'],
            ['UserManager', 'delete_user'], ['UserManager', 'dismiss_merge'], ['UserManager', 'edit_user'],
            ['UserManager', 'list_users'], ['UserManager', 'lookup_service_account'],
            ['UserManager', 'lookup_user'], ['UserManager', 'merge_service_account'],
            ['UserManager', 'unlink_service_account'], ['Users', 'change_password'],
            ['WebDisk', 'delete_user'], ['WebDisk', 'set_homedir'], ['WebDisk', 'set_password'],
            ['WebDisk', 'set_permissions']];
    }

    private static function invoke(UapiClient $account, string $module, string $function): mixed
    {
        if ($module === 'PasswdStrength') {
            return $account->passwdStrength()->getRequiredStrength(app: 'webdisk');
        }
        if ($module === 'Users') {
            return $account->users()->changePassword(
                newpass: 'n +&=%/雪',
                oldpass: 'o +&=%/雪',
                enabledigest: false,
                enablemysql: false,
            );
        }
        if ($module === 'WebDisk') {
            return match ($function) {
                'delete_user' => $account->webDisk()->deleteUser(destroy: false, user: 'fixture@example.test'),
                'set_homedir' => $account->webDisk()->setHomedir(
                    homedir: 'relative +&=%/雪',
                    user: 'fixture@example.test', private: false,
                ),
                'set_password' => $account->webDisk()->setPassword(
                    password: 'p +&=%/雪',
                    user: 'fixture@example.test', enabledigest: false,
                ),
                default => $account->webDisk()->setPermissions(perms: 'ro', user: 'fixture@example.test'),
            };
        }
        return match ($function) {
            'change_password' => $account->userManager()->changePassword(
                newpass: 'n +&=%/雪',
                oldpass: 'o +&=%/雪', enablemysql: false,
            ),
            'check_account_conflicts' => $account->userManager()->checkAccountConflicts(
                fullUsername: 'fixture@example.test',
            ),
            'create_user' => $account->userManager()->createUser(domain: 'example.test', password: 'p +&=%/雪',
                username: 'fixture', alternateEmail: '', realName: '', sendInvite: false,
                servicesEmailEnabled: false, servicesEmailQuota: 'unlimited', servicesEmailSendWelcomeEmail: false,
                servicesFtpEnabled: false, servicesFtpHomedir: 'relative +&=%/雪', servicesWebdiskEnabled: false,
                servicesWebdiskEnabledigest: false, servicesWebdiskHomedir: 'relative +&=%/雪',
                servicesWebdiskPerms: 'ro', servicesWebdiskPrivate: false, type: 'sub'),
            'delete_user' => $account->userManager()->deleteUser(domain: 'example.test', username: 'fixture'),
            'dismiss_merge' => $account->userManager()->dismissMerge(domain: 'example.test', username: 'fixture',
                servicesEmailDismiss: false, servicesFtpDismiss: true, servicesWebdiskDismiss: false),
            'edit_user' => $account->userManager()->editUser(domain: 'example.test', username: 'fixture',
                alternateEmail: '', password: 'p +&=%/雪', realName: '', servicesEmailEnabled: false,
                servicesEmailQuota: 0, servicesFtpEnabled: false, servicesFtpHomedir: 'relative +&=%/雪',
                servicesWebdiskEnabled: false, servicesWebdiskEnabledigest: false,
                servicesWebdiskHomedir: 'relative +&=%/雪', servicesWebdiskPerms: 'rw',
                servicesWebdiskPrivate: false, type: 'sub'),
            'list_users' => $account->userManager()->listUsers(flat: true),
            'lookup_service_account' => $account->userManager()->lookupServiceAccount(
                fullUsername: 'fixture@example.test',
                type: 'ftp',
            ),
            'lookup_user' => $account->userManager()->lookupUser(guid: 'synthetic +&=%/雪'),
            'merge_service_account' => $account->userManager()->mergeServiceAccount(
                domain: 'example.test',
                username: 'fixture', servicesEmailMerge: true, servicesFtpMerge: false, servicesWebdiskMerge: false,
            ),
            default => $account->userManager()->unlinkServiceAccount(
                domain: 'example.test',
                service: 'ftp',
                username: 'fixture',
                dismiss: false,
            ),
        };
    }

    private static function parameters(string $module, string $function): string
    {
        $path = 'relative%20%2B%26%3D%25%2F%E9%9B%AA';
        $password = 'p%20%2B%26%3D%25%2F%E9%9B%AA';
        if ($module === 'PasswdStrength') {
            return 'app=webdisk';
        }
        if ($module === 'Users' || $function === 'change_password') {
            return 'newpass=n%20%2B%26%3D%25%2F%E9%9B%AA&oldpass=o%20%2B%26%3D%25%2F%E9%9B%AA'
                . ($module === 'Users' ? '&enabledigest=0' : '') . '&enablemysql=0';
        }
        if ($module === 'WebDisk') {
            return match ($function) {
                'delete_user' => 'destroy=0&user=fixture%40example.test',
                'set_homedir' => 'homedir=' . $path . '&user=fixture%40example.test&private=0',
                'set_password' => 'password=' . $password . '&user=fixture%40example.test&enabledigest=0',
                default => 'perms=ro&user=fixture%40example.test',
            };
        }
        $serviceSettings = '&services.email.enabled=0&services.email.quota=';
        $ftpWebdisk = '&services.ftp.enabled=0&services.ftp.homedir=' . $path
            . '&services.webdisk.enabled=0&services.webdisk.enabledigest=0&services.webdisk.homedir=' . $path;
        return match ($function) {
            'check_account_conflicts' => 'full_username=fixture%40example.test',
            'create_user' => 'domain=example.test&password=' . $password . '&username=fixture&alternate_email='
                . '&real_name=&send_invite=0' . $serviceSettings . 'unlimited&services.email.send_welcome_email=0'
                . $ftpWebdisk . '&services.webdisk.perms=ro&services.webdisk.private=0&type=sub',
            'delete_user' => 'domain=example.test&username=fixture',
            'dismiss_merge' => 'domain=example.test&username=fixture&services.email.dismiss=0'
                . '&services.ftp.dismiss=1&services.webdisk.dismiss=0',
            'edit_user' => 'domain=example.test&username=fixture&alternate_email=&password=' . $password
                . '&real_name=' . $serviceSettings . '0' . $ftpWebdisk
                . '&services.webdisk.perms=rw&services.webdisk.private=0&type=sub',
            'list_users' => 'flat=1',
            'lookup_service_account' => 'full_username=fixture%40example.test&type=ftp',
            'lookup_user' => 'guid=synthetic%20%2B%26%3D%25%2F%E9%9B%AA',
            'merge_service_account' => 'domain=example.test&username=fixture&services.email.merge=1'
                . '&services.ftp.merge=0&services.webdisk.merge=0',
            default => 'domain=example.test&service=ftp&username=fixture&dismiss=0',
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $module,
        string $function,
        string $parameters,
    ): void {
        $post = !in_array(
            $function,
            ['get_required_strength', 'check_account_conflicts', 'list_users', 'lookup_service_account', 'lookup_user'],
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
