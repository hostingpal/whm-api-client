<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;

/** Independent fixtures from the seven pinned repository and deployment operations. */
final class UapiVersionControlTest extends TestCase
{
    public function testVersionControlSelectorsExistWithoutNetworkIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        self::assertSame($account->versionControl(), $account->versionControl());
        self::assertSame($account->versionControlDeployment(), $account->versionControlDeployment());
        self::assertNotSame($account->versionControl(), $client->uapi('other')->versionControl());
        self::assertNotSame($account->versionControlDeployment(), $client->uapi('other')->versionControlDeployment());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());

        $http->addResponse(new Response(200, [], self::envelope('bridge', '[]')));
        self::assertSame([], $account->versionControl()->retrieve());
        $metadata = $account->responseMeta();
        $account->versionControl();
        $account->versionControlDeployment();
        self::assertSame($metadata, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed>|null $expected */
    #[DataProvider('successfulPayloads')]
    public function testPinnedPayloadsAndExactFormPostRequests(
        string $route,
        string $operation,
        string $payload,
        ?array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        self::assertSame($expected, self::invoke($account, $operation));

        self::assertRequest($http, $route, $operation, self::body($operation));
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Synthetic notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<array-key, mixed>|null}> */
    public static function successfulPayloads(): iterable
    {
        $cases = [
            ['VersionControl/create', '{}', []],
            ['VersionControl/create', '{"available_branches":[],"branch":null,"last_update":null}',
                ['available_branches' => [], 'branch' => null, 'last_update' => null]],
            // The null-only enum has no type: the effective contract remains unspecified, not invented as an object.
            ['VersionControl/create', '{"last_update":{"future":[1,null]}}',
                ['last_update' => ['future' => [1, null]]]],
            ['VersionControl/create', '{"last_update":{"date":1730419200}}',
                ['last_update' => ['date' => 1730419200]]],
            ['VersionControl/create', '{"last_update":{"date":"1730419200"}}',
                ['last_update' => ['date' => '1730419200']]],
            ['VersionControl/create', '{"available_branches":["main",""],"branch":"","clone_urls":'
                . '{"read_only":[],"read_write":["ssh://user@example.test/repo"]},"last_update":null,'
                . '"name":"","repository_root":"/home/exampleuser/repo","source_repository":'
                . '{"remote_name":"origin","url":"https://example.test/repo"},"tasks":[{"action":"create",'
                . '"args":{"log_file":"/log","repository_root":"/repo"},"id":"0","sse_url":"/sse/task",'
                . '"subsystem":"VersionControl"}],"type":"git","future":"ignored"}', [
                    'available_branches' => ['main', ''], 'branch' => '',
                    'clone_urls' => ['read_only' => [], 'read_write' => ['ssh://user@example.test/repo']],
                    'last_update' => null, 'name' => '', 'repository_root' => '/home/exampleuser/repo',
                    'source_repository' => ['remote_name' => 'origin', 'url' => 'https://example.test/repo'],
                    'tasks' => [['action' => 'create', 'args' => ['log_file' => '/log', 'repository_root' => '/repo'],
                        'id' => '0', 'sse_url' => '/sse/task', 'subsystem' => 'VersionControl']], 'type' => 'git',
                ]],
            ['VersionControl/retrieve', '[]', []],
            ['VersionControl/retrieve', '[{}, {"available_branches":null,"branch":null,"last_update":null}]',
                [[], ['available_branches' => null, 'branch' => null, 'last_update' => null]]],
            ['VersionControl/retrieve', '[{"deployable":"0","last_deployment":null,"last_update":null},'
                . '{"deployable":"1","last_update":{"date":"1730419200"}}]', [
                    ['deployable' => 0, 'last_deployment' => null, 'last_update' => null],
                    ['deployable' => 1, 'last_update' => ['date' => 1730419200]],
                ]],
            ['VersionControl/retrieve', '[{"available_branches":[],"branch":"main","clone_urls":'
                . '{"read_only":["https://example.test/repo"],"read_write":[]},"deployable":0,'
                . '"last_deployment":{"deployment_date":0,"repository_state":{"author":"A",'
                . '"date":0,"identifier":"abc","message":""}},"last_update":{"author":"B",'
                . '"date":1,"identifier":"def","message":"commit"},"name":"Repo","repository_root":"/repo",'
                . '"source_repository":{"remote_name":"origin","url":"ssh://example.test/repo"},'
                . '"tasks":[{"action":"deploy","args":{"log_file":"/log","repository_root":"/repo"},'
                . '"id":"task","sse_url":"/sse/task","subsystem":"VersionControl"}],"type":"git"}]', [[
                    'available_branches' => [], 'branch' => 'main',
                    'clone_urls' => ['read_only' => ['https://example.test/repo'], 'read_write' => []],
                    'deployable' => 0,
                    'last_deployment' => ['deployment_date' => 0,
                        'repository_state' => ['author' => 'A', 'date' => 0, 'identifier' => 'abc', 'message' => '']],
                    'last_update' => ['author' => 'B', 'date' => 1, 'identifier' => 'def', 'message' => 'commit'],
                    'name' => 'Repo', 'repository_root' => '/repo',
                    'source_repository' => ['remote_name' => 'origin', 'url' => 'ssh://example.test/repo'],
                    'tasks' => [['action' => 'deploy', 'args' => ['log_file' => '/log', 'repository_root' => '/repo'],
                        'id' => 'task', 'sse_url' => '/sse/task', 'subsystem' => 'VersionControl']], 'type' => 'git',
                ]]],
            ['VersionControl/update', '{}', []],
            ['VersionControl/update', '{"available_branches":null,"branch":null,"deployable":1,'
                . '"last_deployment":null,"last_update":null}', ['available_branches' => null,
                    'branch' => null, 'deployable' => 1, 'last_deployment' => null, 'last_update' => null]],
            ['VersionControl/update', '{"available_branches":["main",""],"deployable":"0",'
                . '"last_update":{"date":"0"}}',
                ['available_branches' => ['main', ''], 'deployable' => 0, 'last_update' => ['date' => 0]]],
            ['VersionControl/update', '{"deployable":"1","last_update":{"date":"1730419200"}}',
                ['deployable' => 1, 'last_update' => ['date' => 1730419200]]],
            ['VersionControl/update', '{"available_branches":[],"branch":"main","clone_urls":{"read_only":[], '
                . '"read_write":[]},"deployable":0,"last_deployment":{"deployment_date":0,"repository_state":'
                . '{"author":"A","date":0,"identifier":"abc","message":""}},"last_update":{"author":"B",'
                . '"date":0,"identifier":"def","message":""},"name":"Repo","repository_root":"/repo",'
                . '"source_repository":{"remote_name":"origin","url":"ssh://example.test/repo"},'
                . '"tasks":[{"action":"deploy","args":{"log_file":"/log","repository_root":"/repo"},'
                . '"id":"task","subsystem":"VersionControl","sse_url":"ignored"}],"type":"git"}', [
                    'available_branches' => [], 'branch' => 'main',
                    'clone_urls' => ['read_only' => [], 'read_write' => []],
                    'deployable' => 0,
                    'last_deployment' => ['deployment_date' => 0,
                        'repository_state' => ['author' => 'A', 'date' => 0, 'identifier' => 'abc', 'message' => '']],
                    'last_update' => ['author' => 'B', 'date' => 0, 'identifier' => 'def', 'message' => ''],
                    'name' => 'Repo', 'repository_root' => '/repo',
                    'source_repository' => ['remote_name' => 'origin', 'url' => 'ssh://example.test/repo'],
                    'tasks' => [['action' => 'deploy', 'args' => ['log_file' => '/log', 'repository_root' => '/repo'],
                        'id' => 'task', 'subsystem' => 'VersionControl']], 'type' => 'git',
                ]],
            ['VersionControlDeployment/create', '{}', []],
            ['VersionControlDeployment/create', 'null', null],
            ['VersionControlDeployment/create', '{"deploy_id":13,"timestamps":{"queued":1730419200.125}}',
                ['deploy_id' => 13, 'timestamps' => ['queued' => 1730419200.125]]],
            ['VersionControlDeployment/create', '{"deploy_id":"0","log_path":"","repository_root":"/repo",'
                . '"sse_url":"/sse/task","task_id":"task","timestamps":{"queued":"0.000"}}', [
                    'deploy_id' => '0', 'log_path' => '', 'repository_root' => '/repo', 'sse_url' => '/sse/task',
                    'task_id' => 'task', 'timestamps' => ['queued' => '0.000'],
                ]],
            ['VersionControlDeployment/retrieve', '[]', []],
            ['VersionControlDeployment/retrieve', '[{}]', [[]]],
            ['VersionControlDeployment/retrieve', '[{"deploy_id":13,"repository_state":{"date":1730419200},'
                . '"timestamps":{"active":1730419200.25,"queued":1730419200.125,"succeeded":1730419200.5}}]', [[
                    'deploy_id' => 13, 'repository_state' => ['date' => 1730419200],
                    'timestamps' => ['active' => 1730419200.25, 'queued' => 1730419200.125,
                        'succeeded' => 1730419200.5],
                ]]],
            ['VersionControlDeployment/retrieve', '[{"deploy_id":0,"log_path":"","repository_root":"/repo",'
                . '"repository_state":{"author":"A","branch":"main","date":0,"identifier":"abc",'
                . '"message":""},"sse_url":"/sse/task","task_id":"task","timestamps":{"active":"0.1",'
                . '"canceled":"0.2","failed":"0.3","queued":"0.0","succeeded":"0.4"}}]', [[
                    'deploy_id' => 0, 'log_path' => '', 'repository_root' => '/repo',
                    'repository_state' => ['author' => 'A', 'branch' => 'main', 'date' => 0,
                        'identifier' => 'abc', 'message' => ''], 'sse_url' => '/sse/task', 'task_id' => 'task',
                    'timestamps' => ['active' => '0.1', 'canceled' => '0.2', 'failed' => '0.3',
                        'queued' => '0.0', 'succeeded' => '0.4'],
                ]]],
        ];
        foreach (['VersionControl/delete', 'VersionControlDeployment/delete'] as $operation) {
            $cases[] = [$operation, 'null', null];
            $cases[] = [$operation, '{}', []];
            $cases[] = [$operation, '[]', []];
            $cases[] = [$operation, '{"opaque":{"zero":0,"none":null,"list":[]}}',
                ['opaque' => ['zero' => 0, 'none' => null, 'list' => []]]];
        }
        foreach (self::routes() as [$route]) {
            foreach ($cases as $index => [$operation, $payload, $expected]) {
                yield "$route $operation $index" => [$route, $operation, $payload, $expected];
            }
        }
    }

    #[DataProvider('routes')]
    public function testOmissionEmptyStringsAndCallerSerializedJsonAreSentExactlyOnce(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $calls = [
            ['VersionControl/create', fn () => $account->versionControl()->create('/repo', 'Repo', 'git'),
                'repository_root=%2Frepo&name=Repo&type=git'],
            ['VersionControl/create', fn () => $account->versionControl()->create('/repo', '', 'git', ''),
                'repository_root=%2Frepo&name=&type=git&source_repository='],
            ['VersionControl/retrieve', fn () => $account->versionControl()->retrieve(), ''],
            ['VersionControl/retrieve', fn () => $account->versionControl()->retrieve(''), 'fields='],
            ['VersionControl/retrieve', fn () => $account->versionControl()->retrieve('*'), 'fields=%2A'],
            ['VersionControl/update', fn () => $account->versionControl()->update('/repo'), 'repository_root=%2Frepo'],
            ['VersionControl/update', fn () => $account->versionControl()->update('/repo', '', '', ''),
                'repository_root=%2Frepo&branch=&name=&source_repository='],
            ['VersionControl/update', fn () => $account->versionControl()->update(
                '/repo',
                sourceRepository: '{"remote_name":"a +&=%/雪"}',
            ), 'repository_root=%2Frepo&source_repository='
                . '%7B%22remote_name%22%3A%22a%20%2B%26%3D%25%2F%E9%9B%AA%22%7D'],
            ['VersionControlDeployment/delete', fn () => $account->versionControlDeployment()->delete('0'),
                'deploy_id=0'],
            ['VersionControlDeployment/delete', fn () => $account->versionControlDeployment()->delete(''),
                'deploy_id='],
        ];
        foreach ($calls as [$operation, $call, $body]) {
            $http->addResponse(new Response(200, [], self::envelope($route, self::validPayload($operation))));
            $call();
            self::assertRequest($http, $route, $operation, $body);
        }
        self::assertCount(10, $http->getRequests());
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadReplacesMetadataAndKeepsConfirmedOutcomeWithoutRetry(
        string $route,
        string $operation,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validPayload($operation))));
        self::invoke($account, $operation);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $operation);
            self::fail('Malformed repository data must fail validation.');
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
        self::assertSame(1, $previous?->uapiStatus());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [];
        foreach (self::functions() as [$operation]) {
            foreach ([null, '0', 'false', '"wrong"', '[null]', '[7]', '[false]'] as $payload) {
                $cases[] = [$operation, $payload];
            }
            if (!str_ends_with($operation, '/delete') && $operation !== 'VersionControlDeployment/create') {
                $cases[] = [$operation, 'null'];
            }
            if (str_ends_with($operation, '/retrieve')) {
                $cases[] = [$operation, '{}'];
                $cases[] = [$operation, '[[1]]'];
            }
        }
        foreach (['create', 'retrieve', 'update'] as $function) {
            $fields = [
                'name' => ['null', '1', 'false', '[]'],
                'repository_root' => ['null', '1', '[]'],
                'branch' => ['1', 'false', '[]'],
                'type' => ['null', '1', '[]'],
                'clone_urls' => ['null', 'false', '[1]'],
                'clone_urls.read_only' => ['null', '{}', '1', '[null]', '[1]'],
                'clone_urls.read_write' => ['null', '{}', '[false]'],
                'source_repository' => ['null', 'false', '[1]'],
                'source_repository.remote_name' => ['null', '1', '[]'],
                'source_repository.url' => ['null', '1', '[]'],
                'available_branches' => ['false', '{}', '[1]', '[null]'],
                'tasks' => ['null', '{}', '[null]', '[1]'],
                'tasks[].action' => ['null', '1', '[]'],
                'tasks[].args' => ['null', 'false', '[1]'],
                'tasks[].args.log_file' => ['null', '1'],
                'tasks[].args.repository_root' => ['null', '1'],
                'tasks[].id' => ['null', '1', '[]'],
                'tasks[].subsystem' => ['null', '1', '[]'],
            ];
            if ($function !== 'update') {
                $fields['tasks[].sse_url'] = ['null', '1', '[]'];
            }
            if ($function === 'create') {
                $fields['available_branches'][] = 'null';
            }
            if ($function !== 'create') {
                $fields['deployable'] = ['null', '"00"', '"2"', '"1.0"', '2', 'false', '0.0'];
                $fields['last_update'] = ['1', 'false', '[1]'];
                $fields['last_deployment'] = ['1', 'false', '[1]'];
                foreach (['last_update', 'last_deployment.repository_state'] as $prefix) {
                    foreach (['author', 'identifier', 'message'] as $field) {
                        $fields[$prefix . '.' . $field] = ['null', '1', '[]'];
                    }
                    $fields[$prefix . '.date'] = $prefix === 'last_update'
                        ? ['null', '"01"', '"1.5"', '"1e2"', '"9223372036854775808"', 'false', '1.5']
                        : ['null', '"0"', 'false', '1.5'];
                }
                $fields['last_deployment.deployment_date'] = ['null', '"0"', 'false', '1.5'];
                $fields['last_deployment.repository_state'] = ['null', '1', '[1]'];
            }
            foreach ($fields as $path => $values) {
                foreach ($values as $value) {
                    $payload = self::fieldPayload($path, $value);
                    $cases[] = ['VersionControl/' . $function,
                        $function === 'retrieve' ? '[' . $payload . ']' : $payload];
                }
            }
        }
        foreach (['create', 'retrieve'] as $function) {
            $fields = [
                'deploy_id' => $function === 'create'
                    ? ['null', '1.5', 'false', '[]'] : ['null', '"0"', 'false', '1.5'],
                'timestamps' => ['null', '1', '[1]'],
            ];
            foreach (['log_path', 'repository_root', 'sse_url', 'task_id'] as $field) {
                $fields[$field] = ['null', '0', 'false', '[]'];
            }
            $timestamps = $function === 'create' ? ['queued'] : ['active', 'canceled', 'failed', 'queued', 'succeeded'];
            foreach ($timestamps as $field) {
                $fields['timestamps.' . $field] = ['null', 'false', '[]', '{}'];
                if (in_array($field, ['canceled', 'failed'], true)) {
                    $fields['timestamps.' . $field][] = '0';
                }
            }
            if ($function === 'retrieve') {
                $fields['repository_state'] = ['null', '1', '[1]'];
                foreach (['author', 'branch', 'identifier', 'message'] as $field) {
                    $fields['repository_state.' . $field] = ['null', '0', '[]'];
                }
                $fields['repository_state.date'] = ['null', '"0"', 'false', '1.5'];
            }
            foreach ($fields as $path => $values) {
                foreach ($values as $value) {
                    $payload = self::fieldPayload($path, $value);
                    $cases[] = ['VersionControlDeployment/' . $function,
                        $function === 'retrieve' ? '[' . $payload . ']' : $payload];
                }
            }
        }
        foreach (self::routes() as [$route]) {
            foreach ($cases as $index => [$operation, $payload]) {
                yield "$route $operation $index" => [$route, $operation, $payload];
            }
        }
    }

    #[DataProvider('routes')]
    public function testRetrievedIntegerDeploymentIdCanBeDeletedUnderStrictTypes(string $route): void
    {
        [$client, $http] = self::client($route);
        $deployments = $client->uapi('exampleuser')->versionControlDeployment();
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"deploy_id":13,"task_id":"different-task-id"}]',
        )));
        $rows = $deployments->retrieve();
        self::assertSame([['deploy_id' => 13, 'task_id' => 'different-task-id']], $rows);
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
        self::assertNull($deployments->delete($rows[0]['deploy_id']));
        self::assertRequest($http, $route, 'VersionControlDeployment/delete', 'deploy_id=13');
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testCreatedDeploymentIdsRemainUsableAsIntegersAndDocumentedStrings(string $route): void
    {
        [$client, $http] = self::client($route);
        $deployments = $client->uapi('exampleuser')->versionControlDeployment();
        foreach (['{"deploy_id":13}', '{"deploy_id":"0013"}'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
            $created = $deployments->create('/repo');
            self::assertNotNull($created);
            self::assertArrayHasKey('deploy_id', $created);
            $deployId = $created['deploy_id'] ?? null;
            self::assertNotNull($deployId);
            $http->addResponse(new Response(200, [], self::envelope($route, 'null')));
            self::assertNull($deployments->delete($deployId));
            self::assertRequest(
                $http,
                $route,
                'VersionControlDeployment/delete',
                $payload === '{"deploy_id":13}' ? 'deploy_id=13' : 'deploy_id=0013',
            );
        }
        self::assertCount(4, $http->getRequests());
    }

    #[DataProvider('operations')]
    public function testApiAndHttpFailuresReplaceMetadataWithoutRetrying(string $route, string $operation): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, self::validPayload($operation))));
        self::invoke($account, $operation);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($account, $operation);
            self::fail('API failure must precede payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        $http->addResponse(new Response(503, [], 'Unavailable'));
        try {
            self::invoke($account, $operation);
            self::fail('HTTP failure must clear metadata.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->httpStatus);
            self::assertNull($error->metadata);
            self::assertNull($account->responseMeta());
        }
        self::assertSame(1, $previous?->uapiStatus());
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('functions')]
    public function testOuterWhmFailureWinsOverInnerSuccess(string $operation): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Denied"},'
            . '"data":{"uapi":{"status":1,"data":' . self::validPayload($operation) . '}}}'));
        try {
            self::invoke($account, $operation);
            self::fail('Outer WHM failure must be checked.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame(['Denied'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testAccountBindingAndMetadataIsolation(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '[]')));
        self::assertSame([], $client->uapi()->versionControl()->retrieve());
        self::assertRequest($http, 'direct', 'VersionControl/retrieve', '');
        [$client, $http] = self::client('bridge');
        try {
            $client->uapi()->versionControl()->retrieve();
            self::fail('WHM root must not be selected as a cPanel target.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->getRequests());
        }
        $first = $client->uapi('exampleuser');
        $second = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::envelope('bridge', '[]')));
        self::assertSame([], $first->versionControl()->retrieve());
        $metadata = $first->responseMeta();
        self::assertNull($second->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{}')));
        self::assertSame([], $second->versionControlDeployment()->create('/repo'));
        self::assertRequest($http, 'bridge', 'VersionControlDeployment/create', 'repository_root=%2Frepo', 'other');
        self::assertSame($metadata, $first->responseMeta());
        self::assertNotSame($metadata, $second->responseMeta());
        self::assertNull($client->whm()->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('sensitiveFailures')]
    public function testCloneSourceAndResponsesStaySensitiveInFailureTraces(
        string $route,
        string $method,
        string $failure,
    ): void {
        $secret = 'private-clone-marker';
        [$client, $http] = self::client($route);
        $repository = $client->uapi('exampleuser')->versionControl();
        $parameter = new ReflectionParameter([$repository, $method], 'sourceRepository');
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        $http->addResponse(match ($failure) {
            'http' => new Response(500, [], $secret),
            'json' => new Response(200, [], $secret),
            'api' => new Response(200, [], self::envelope($route, 'null', 0)),
            default => new Response(200, [], self::envelope(
                $route,
                '{"name":false,"source_repository":{"url":"' . $secret . '"}}',
            )),
        });
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $source = '{"url":"https://user:' . $secret . '@example.test/repo"}';
            if ($method === 'create') {
                $repository->create('/repo', 'Repo', 'git', $source);
            } else {
                $repository->update('/repo', sourceRepository: $source);
            }
            self::fail('The synthetic failure must throw.');
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

    /** @return iterable<string, array{string, string, string}> */
    public static function sensitiveFailures(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (['create', 'update'] as $method) {
                foreach (['api', 'http', 'json', 'payload'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
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

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function functions(): iterable
    {
        foreach (
            ['VersionControl/create', 'VersionControl/delete', 'VersionControl/retrieve', 'VersionControl/update',
            'VersionControlDeployment/create', 'VersionControlDeployment/delete',
                'VersionControlDeployment/retrieve'] as $op
        ) {
            yield $op => [$op];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (self::routes() as [$route]) {
            foreach (self::functions() as [$operation]) {
                yield "$route $operation" => [$route, $operation];
            }
        }
    }

    /** @return array<array-key, mixed>|null */
    private static function invoke(UapiClient $account, string $operation): ?array
    {
        return match ($operation) {
            'VersionControl/create' => $account->versionControl()->create(
                '/home/exampleuser/repo',
                'Repo',
                'git',
                '{"url":"https://user:secret+&=%@example.test/repo","remote_name":"origin"}',
            ),
            'VersionControl/delete' => $account->versionControl()->delete('/home/exampleuser/repo'),
            'VersionControl/retrieve' => $account->versionControl()->retrieve('name,type,branch,last_update'),
            'VersionControl/update' => $account->versionControl()->update(
                '/home/exampleuser/repo',
                branch: 'main',
                name: 'Renamed',
                sourceRepository: '{"remote_name":"upstream"}',
            ),
            'VersionControlDeployment/create' => $account->versionControlDeployment()->create('/home/exampleuser/repo'),
            'VersionControlDeployment/delete' => $account->versionControlDeployment()->delete('13'),
            'VersionControlDeployment/retrieve' => $account->versionControlDeployment()->retrieve(),
            default => throw new LogicException('Unknown repository test operation.'),
        };
    }

    private static function body(string $operation): string
    {
        return match ($operation) {
            'VersionControl/create' => 'repository_root=%2Fhome%2Fexampleuser%2Frepo&name=Repo&type=git'
                . '&source_repository='
                . '%7B%22url%22%3A%22https%3A%2F%2Fuser%3Asecret%2B%26%3D%25%40example.test%2Frepo%22%2C'
                . '%22remote_name%22%3A%22origin%22%7D',
            'VersionControl/delete', 'VersionControlDeployment/create' =>
                'repository_root=%2Fhome%2Fexampleuser%2Frepo',
            'VersionControl/retrieve' => 'fields=name%2Ctype%2Cbranch%2Clast_update',
            'VersionControl/update' => 'repository_root=%2Fhome%2Fexampleuser%2Frepo&branch=main&name=Renamed'
                . '&source_repository='
                . '%7B%22remote_name%22%3A%22upstream%22%7D',
            'VersionControlDeployment/delete' => 'deploy_id=13',
            'VersionControlDeployment/retrieve' => '',
            default => throw new LogicException('Unknown repository test operation.'),
        };
    }

    private static function fieldPayload(string $path, string $value): string
    {
        foreach (array_reverse(explode('.', $path)) as $part) {
            if (str_ends_with($part, '[]')) {
                $part = substr($part, 0, -2);
                $value = '[' . $value . ']';
            }
            $value = '{"' . $part . '":' . $value . '}';
        }
        return $value;
    }

    private static function validPayload(string $operation): string
    {
        if (str_ends_with($operation, '/delete')) {
            return 'null';
        }
        return str_ends_with($operation, '/retrieve') ? '[]' : '{}';
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $operation,
        string $body,
        string $account = 'exampleuser',
    ): void {
        [$module, $function] = explode('/', $operation);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function, (string) $request->getUri());
        self::assertSame($body, (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"messages":null,"warnings":["Synthetic notice"],"metadata":{"transformed":1}}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
