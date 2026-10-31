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

/** Literal request and response oracles for the pinned BackupInfo, ExtractInfo and WebsiteBackup operations. */
final class UapiWebsiteBackupTest extends TestCase
{
    /** @param array<array-key, mixed> $expected */
    #[DataProvider('successfulPayloads')]
    public function testExactFormRequestsAndOptionalPayloadFields(
        string $route,
        string $module,
        string $function,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $module, $function));
        self::assertRequest($http, $route, $module, $function);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, string, array<array-key, mixed>}> */
    public static function successfulPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                $row = self::example($module, $function);
                $list = self::hasListPayload($module, $function);
                $cases = [[$list ? [$row, $row] : $row], [[]], [$list ? [[]] : []]];
                foreach ($cases as $index => [$expected]) {
                    $payload = json_encode($expected, JSON_THROW_ON_ERROR);
                    if (!$list && $expected === []) {
                        $payload = '{}';
                    }
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload, $expected];
                }
                foreach ($row as $field => $value) {
                    $alternatives = match (true) {
                        $value === null => [null, 0, 1750636800],
                        is_bool($value) => [false, true],
                        in_array($field, ['ok', 'unlimited', 'includesDatabases'], true) => [0, 1],
                        is_int($value), is_float($value) => [$value, 0],
                        is_array($value) => [[], ['', '0', 'repeat', 'repeat']],
                        default => [$value, '', '0', 'future'],
                    };
                    foreach ($alternatives as $index => $alternative) {
                        $expected = $list ? [[$field => $alternative]] : [$field => $alternative];
                        yield "$route $module/$function optional $field $index" =>
                            [$route, $module, $function, json_encode($expected, JSON_THROW_ON_ERROR), $expected];
                    }
                }
                if (!$list) {
                    yield "$route $module/$function empty-map compatibility" => [$route, $module, $function, '[]', []];
                }
                // Named object schemas discard unknown fields; unknown values do not invent a field contract.
                yield "$route $module/$function unknown" => [$route, $module, $function,
                    $list ? '[{"unknown":{"private":"payload"}}]' : '{"unknown":{"private":"payload"}}',
                    $list ? [[]] : []];
            }
        }
    }

    /** @return array<string, mixed> */
    private static function example(string $module, string $function): array
    {
        return match ($module . '/' . $function) {
            'BackupInfo/list' => ['fullpath' => 'backup.tar.gz', 'mtime' => 0, 'name' => 'backup.tar.gz',
                'sizeBytes' => 0, 'startedAtEpoch' => null, 'status' => 'ready'],
            'BackupInfo/progress' => ['currentBytes' => 0, 'elapsedSeconds' => 0, 'estimatedPercent' => 33.3,
                'estimatedTotalBytes' => 0, 'finishedAtEpoch' => null, 'startedAtEpoch' => null,
                'state' => 'none', 'stateSource' => 'none'],
            'BackupInfo/reset', 'ExtractInfo/finish' => ['cleared' => true],
            'ExtractInfo/start' => ['started' => true],
            'ExtractInfo/progress' => ['currentBytes' => 0, 'elapsedSeconds' => 0, 'percent' => 33.3,
                'startedAtEpoch' => null, 'state' => 'none', 'totalBytes' => 0, 'totalBytesEstimated' => 0],
            'WebsiteBackup/create_backup', 'WebsiteBackup/extract_backup', 'WebsiteBackup/restore_backup' =>
                ['operationId' => '2026-08-18T12:53:12Z.1'],
            'WebsiteBackup/delete_backup' => ['error' => 'Synthetic per-id failure', 'id' => 'backup-id', 'ok' => 0],
            'WebsiteBackup/disk_quota_check' =>
                ['availableBytes' => 0, 'estimatedBytes' => 0, 'ok' => 1, 'unlimited' => 1],
            'WebsiteBackup/list_backups' => ['createdMs' => 1755520392000, 'databaseNames' => [], 'id' => 'backup-id',
                'includesDatabases' => 0, 'name' => '', 'relativePath' => '', 'sites' => ['example.com', 'example.com'],
                'sizeBytes' => 0, 'source' => 'user', 'status' => 'ready', 'type' => 'website'],
            'WebsiteBackup/list_operations' => ['domain' => 'example.com', 'extractPath' => 'restored/site',
                'kind' => 'extract', 'operationId' => '2026-08-18T12:53:12Z.1', 'progressPct' => 0.5,
                'startedAtMs' => 1755520392000, 'status' => 'inProgress', 'subStatus' => '', 'title' => ''],
            default => ['error' => 'Synthetic background failure', 'kind' => 'restore', 'progressPct' => 100,
                'startedAtMs' => 1755520392000, 'status' => 'failed', 'subStatus' => '', 'title' => '',
                'warnings' => ['', 'Synthetic warning']],
        };
    }

    #[DataProvider('invalidPayloads')]
    public function testMissingNullAndMalformedPayloadKeepConfirmedOutcome(
        string $route,
        string $module,
        string $function,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $initial = self::hasListPayload($module, $function) ? '[]' : '{}';
        $http->addResponse(new Response(200, [], self::envelope($route, $initial)));
        self::invoke($account, $module, $function);
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::invoke($account, $module, $function);
            self::fail('Malformed backup payload must be rejected.');
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
        self::assertRequest($http, $route, $module, $function);
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as [$module, $function]) {
                $list = self::hasListPayload($module, $function);
                $cases = [null, 'null', 'false', 'true', '0', '1.5', '"text"', $list ? '{}' : '[{}]'];
                if ($list) {
                    array_push($cases, '[null]', '[false]', '[1]', '["text"]');
                }
                foreach (self::example($module, $function) as $field => $value) {
                    $bad = match (true) {
                        is_bool($value) => ['null', '0', '1', '"true"', '[]'],
                        is_int($value) && in_array($field, ['ok', 'unlimited', 'includesDatabases'], true) =>
                            ['null', '"0"', '"1"', '2', '-1', '1.0', 'true', '[]'],
                        in_array($field, ['estimatedPercent', 'percent', 'progressPct'], true) =>
                            ['null', '"0"', '"1.5"', 'true', '[]'],
                        is_int($value) => ['null', '"0"', '"1.5"', '1.5', 'true', '[]'],
                        $value === null => ['"0"', '1.5', 'true', '[]'],
                        is_array($value) => ['null', '{}', '[null]', '[0]', '[false]', '[{}]'],
                        default => ['null', '0', 'true', '[]'],
                    };
                    foreach ($bad as $invalid) {
                        $object = '{"' . $field . '":' . $invalid . '}';
                        $cases[] = $list ? '[' . $object . ']' : $object;
                    }
                }
                foreach ($cases as $index => $payload) {
                    yield "$route $module/$function $index" => [$route, $module, $function, $payload];
                }
            }
        }
    }

    #[DataProvider('operationRoutes')]
    public function testApiFailurePrecedesPayloadValidation(string $route, string $module, string $function): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            self::invoke($client->uapi('npr'), $module, $function);
            self::fail('UAPI status zero must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
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
    public function testOmittedBackupOptionsAndExplicitZeroRestoreOptions(string $route): void
    {
        [$client, $http] = self::client($route);
        $group = $client->uapi('npr')->websiteBackup();
        $http->addResponse(new Response(200, [], self::envelope($route, '{"operationId":"queued"}')));
        self::assertSame(['operationId' => 'queued'], $group->createBackup(domain: 'example.com'));
        self::assertBody($http, 'domain=example.com');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $group->restoreBackup(domain: 'example.com', id: 'backup-id');
        self::assertBody($http, 'domain=example.com&id=backup-id');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $group->restoreBackup(domain: 'example.com', id: 'backup-id', clean: 0, restoreDatabases: 0);
        self::assertBody($http, 'domain=example.com&id=backup-id&clean=0&restore_databases=0');
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testRepeatedFormListsPreserveOrderDuplicatesAndEmptyStrings(string $route): void
    {
        [$client, $http] = self::client($route);
        $group = $client->uapi('npr')->websiteBackup();
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $group->createBackup(domain: 'example.com', database: ['db +&=%/雪', '', 'db +&=%/雪'], email: '');
        self::assertBody($http, 'domain=example.com&database=db%20%2B%26%3D%25%2F%E9%9B%AA&database='
            . '&database=db%20%2B%26%3D%25%2F%E9%9B%AA&email=');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $group->deleteBackup(ids: ['', 'same', 'same']);
        self::assertBody($http, 'ids=&ids=same&ids=same');
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testEmptyRepeatedListsFailBeforeDispatchAndClearMetadata(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        foreach (['create', 'restore', 'delete'] as $operation) {
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->backupInfo()->progress();
            self::assertNotNull($account->responseMeta());
            $before = count($http->getRequests());
            try {
                match ($operation) {
                    'create' => $account->websiteBackup()->createBackup(domain: 'example.com', database: []),
                    'restore' => $account->websiteBackup()->restoreBackup(
                        domain: 'example.com',
                        id: 'backup-id', database: [],
                    ),
                    default => $account->websiteBackup()->deleteBackup(ids: []),
                };
                self::fail('Empty repeated input must be rejected before HTTP dispatch.');
            } catch (InvalidArgumentException) {
                self::assertNull($account->responseMeta());
                self::assertCount($before, $http->getRequests());
            }
        }
    }

    public function testSelectorsAreCachedAndAccountBoundWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($account->backupInfo(), $account->backupInfo());
        self::assertSame($account->extractInfo(), $account->extractInfo());
        self::assertSame($account->websiteBackup(), $account->websiteBackup());
        self::assertNotSame($account->backupInfo(), $other->backupInfo());
        self::assertNotSame($account->extractInfo(), $other->extractInfo());
        self::assertNotSame($account->websiteBackup(), $other->websiteBackup());
        self::assertSame([], $http->getRequests());
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
        ['BackupInfo', 'list'], ['BackupInfo', 'progress'], ['BackupInfo', 'reset'],
            ['ExtractInfo', 'finish'], ['ExtractInfo', 'progress'], ['ExtractInfo', 'start'],
            ['WebsiteBackup', 'create_backup'], ['WebsiteBackup', 'delete_backup'],
            ['WebsiteBackup', 'disk_quota_check'], ['WebsiteBackup', 'extract_backup'],
            ['WebsiteBackup', 'list_backups'], ['WebsiteBackup', 'list_operations'],
            ['WebsiteBackup', 'operation_status'], ['WebsiteBackup', 'restore_backup']];
    }

    private static function hasListPayload(string $module, string $function): bool
    {
        return $module . '/' . $function === 'BackupInfo/list'
            || in_array($function, ['delete_backup', 'list_backups', 'list_operations'], true);
    }

    private static function invoke(UapiClient $account, string $module, string $function): mixed
    {
        return match ($module . '/' . $function) {
            'BackupInfo/list' => $account->backupInfo()->listBackups(),
            'BackupInfo/progress' => $account->backupInfo()->progress(),
            'BackupInfo/reset' => $account->backupInfo()->reset(),
            'ExtractInfo/finish' => $account->extractInfo()->finish(),
            'ExtractInfo/progress' => $account->extractInfo()->progress(),
            'ExtractInfo/start' => $account->extractInfo()->start(
                archive: '/home/npr/a +&=%/雪.tar.gz',
                directory: './out',
            ),
            'WebsiteBackup/create_backup' => $account->websiteBackup()->createBackup(
                domain: 'example.com',
                database: ['db_one', 'db_two'], email: 'a+b@example.com',
            ),
            'WebsiteBackup/delete_backup' => $account->websiteBackup()->deleteBackup(ids: ['id_one', 'id_two']),
            'WebsiteBackup/disk_quota_check' => $account->websiteBackup()->diskQuotaCheck(domain: 'example.com'),
            'WebsiteBackup/extract_backup' => $account->websiteBackup()->extractBackup(
                domain: 'example.com',
                id: 'backup-id', path: '/restored/a +&=%/雪',
            ),
            'WebsiteBackup/list_backups' => $account->websiteBackup()->listBackups(domain: 'example.com'),
            'WebsiteBackup/list_operations' => $account->websiteBackup()->listOperations(domain: 'example.com'),
            'WebsiteBackup/operation_status' => $account->websiteBackup()->operationStatus(operationId: 'op +&=%/雪'),
            default => $account->websiteBackup()->restoreBackup(
                domain: 'example.com',
                id: 'backup-id',
                clean: 1,
                database: ['db_one', 'db_two'],
                restoreDatabases: 0,
            ),
        };
    }

    private static function assertRequest(MockClient $http, string $route, string $module, string $function): void
    {
        $body = match ($function) {
            'start' => 'archive=%2Fhome%2Fnpr%2Fa%20%2B%26%3D%25%2F%E9%9B%AA.tar.gz&directory=.%2Fout',
            'create_backup' => 'domain=example.com&database=db_one&database=db_two&email=a%2Bb%40example.com',
            'delete_backup' => 'ids=id_one&ids=id_two',
            'disk_quota_check', 'list_backups', 'list_operations' => 'domain=example.com',
            'extract_backup' => 'domain=example.com&id=backup-id&path=%2Frestored%2Fa%20%2B%26%3D%25%2F%E9%9B%AA',
            'operation_status' => 'operationId=op%20%2B%26%3D%25%2F%E9%9B%AA',
            'restore_backup' => 'domain=example.com&id=backup-id&clean=1'
                . '&database=db_one&database=db_two&restore_databases=0',
            default => '',
        };
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function;
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame($body, (string) $request->getBody());
    }

    private static function assertBody(MockClient $http, string $expected): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($expected, (string) $request->getBody());
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
