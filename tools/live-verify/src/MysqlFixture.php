<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Temporarily populate the designated npr account for the existing generated MySQL inventory probes.
 *
 * Only two databases and two users belong to this run. Their initial absence must be confirmed;
 * attempted creation is reconciled before deletion, including failed requests. Cleanup always
 * proceeds across independent resources and verifies absence without retrying mutations. Raw
 * identities, passwords, inventory values and upstream messages remain in memory. This fixture
 * neither creates tables nor establishes typed coverage for its generic POST setup operations.
 *
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type Names from MysqlFixtureGuard
 * @phpstan-type Role 'linked_database'|'unlinked_database'|'linked_user'|'unlinked_user'
 * @phpstan-type ResourceOutcome array{
 *     creation: 'not_attempted'|'confirmed'|'unconfirmed',
 *     cleanup: 'not_required'|'confirmed_absent'|'present'|'unconfirmed'
 * }
 * @phpstan-type Report array{
 *     recipe: 'mysql-npr-linked-unlinked-v1', status: 'passed'|'failed',
 *     setup: 'passed'|'failed'|'not_started', capture: 'passed'|'failed'|'not_run',
 *     expectations: 'passed'|'failed'|'not_run', cleanup: 'passed'|'failed'|'not_required',
 *     failures: list<string>, resources: array{
 *         linked_database: ResourceOutcome, unlinked_database: ResourceOutcome,
 *         linked_user: ResourceOutcome, unlinked_user: ResourceOutcome
 *     }
 * }
 */
final class MysqlFixture
{
    /** @var Names */
    private readonly array $names;

    private readonly string $password;

    private readonly UapiClient $account;

    private readonly CaptureClient $capture;

    private bool $used = false;

    /**
     * Bind one six-character lowercase hexadecimal run suffix to the fixed npr test account.
     *
     * Construction sends nothing. The supplied transport must support guarded form POST requests;
     * run() and the request guard require both live and mutation opt-ins before network I/O.
     */
    public function __construct(
        #[SensitiveParameter]
        ClientInterface $http,
        #[SensitiveParameter]
        string $cpanelToken,
        #[SensitiveParameter]
        string $suffix,
    ) {
        $this->names = MysqlFixtureGuard::names($suffix);
        $this->password = 'Wv!9' . bin2hex(random_bytes(20));
        $this->capture = new CaptureClient(new MysqlFixtureGuard($http, $cpanelToken, $suffix, $this->password));
        $factory = new Psr17Factory();
        $client = new Client([
            'host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
            'username' => 'npr', 'token' => $cpanelToken,
        ], new HttpTransport($this->capture, $factory, $factory));
        $this->account = $client->uapi();
    }

    /**
     * Create fixtures, capture the unchanged catalog, then clean up before returning sanitized outcomes.
     *
     * The capture callback receives every supplied probe. Four MySQL callbacks additionally retain
     * their generated results in memory; expectations are checked after capture, so API and typed
     * payload outcomes and same-response evidence remain intact. No exception text enters the report.
     * Each instance is single-use. Failed setup skips capture but still reconciles attempted resources.
     *
     * @param list<Probe> $probes
     * @param Closure(list<Probe>): list<Record> $capture
     * @return array{probes: list<Record>, mysql_fixture: Report}
     */
    public function run(#[SensitiveParameter] array $probes, #[SensitiveParameter] Closure $capture): array
    {
        $report = self::report();
        $records = [];
        if ($this->used || getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1') {
            $report['failures'][] = 'configuration_failure';

            return ['probes' => [], 'mysql_fixture' => $report];
        }
        $this->used = true;
        /** @var array<string, mixed> $observed */
        $observed = [];
        $wrapped = [];
        $identities = [];
        foreach ($probes as $probe) {
            if ($probe->unavailable !== null) {
                $report['failures'][] = 'configuration_failure';

                return ['probes' => [], 'mysql_fixture' => $report];
            }
            if (!self::isMysql($probe->operation, $probe->route)) {
                $wrapped[] = $probe;
                continue;
            }
            $identity = $probe->operation . '|' . $probe->route;
            $identities[] = $identity;
            $wrapped[] = new Probe(
                $probe->operation,
                $probe->route,
                $probe->spec,
                $probe->uri,
                static function (#[SensitiveParameter] Client $client) use ($probe, $identity, &$observed): mixed {
                    $result = ($probe->invoke)($client);
                    $observed[$identity] = $result;

                    return $result;
                },
                $probe->unavailable,
            );
        }
        if (count(array_unique($identities)) !== 4 || count($identities) !== 4) {
            $report['failures'][] = 'configuration_failure';

            return ['probes' => [], 'mysql_fixture' => $report];
        }

        try {
            $report['setup'] = 'failed';
            $this->setup($report);
            $report['setup'] = 'passed';
            $report['capture'] = 'failed';
            $records = $capture($wrapped);
            $report['capture'] = 'passed';
            $this->expectations($records, $observed, $report);
        } catch (Throwable) {
            $report['failures'][] = $report['setup'] === 'passed' ? 'capture_failure' : 'setup_failure';
        } finally {
            $this->cleanup($report);
        }
        $report['failures'] = array_values(array_unique($report['failures']));
        if ($report['failures'] === [] && $report['expectations'] === 'passed' && $report['cleanup'] === 'passed') {
            $report['status'] = 'passed';
        }

        return ['probes' => $records, 'mysql_fixture' => $report];
    }

    /** @param Report $report */
    private function setup(array &$report): void
    {
        $databases = $this->inventory(true);
        $users = $this->inventory(false);
        foreach ($this->names as $role => $name) {
            if (in_array($name, str_ends_with($role, 'database') ? $databases : $users, true)) {
                throw new RuntimeException('Fixture identity is already present.');
            }
        }
        foreach ($this->names as $role => $name) {
            $database = str_ends_with($role, 'database');
            $parameters = ['name' => $name];
            if (!$database) {
                $parameters['password'] = $this->password;
            }
            $report['resources'][$role]['creation'] = 'unconfirmed';
            $this->account->call(
                'Mysql',
                $database ? 'create_database' : 'create_user',
                $parameters,
                RequestMethod::Post,
            );
            $report['resources'][$role]['creation'] = 'confirmed';
        }
        $this->account->call('Mysql', 'set_privileges_on_database', [
            'database' => $this->names['linked_database'],
            'user' => $this->names['linked_user'],
            'privileges' => 'SELECT',
        ], RequestMethod::Post);
    }

    /**
     * Read usable identities independently of optional typed fields, without trusting object-to-array conversion.
     *
     * A generic call first validates the original response's envelope and outcome. Its CaptureClient
     * supplies those same bytes; inspecting the original JSON preserves the distinction between []
     * and {}, which generic CallResult conversion alone cannot retain. Every row must identify itself
     * uniquely before this inventory can establish that any proposed name is absent.
     *
     * @return list<string>
     */
    private function inventory(bool $databases): array
    {
        $this->account->call('Mysql', $databases ? 'list_databases' : 'list_users', [], RequestMethod::Get);
        $raw = $this->capture->rawBody();
        if ($raw === null) {
            throw new RuntimeException('Fixture inventory is unavailable.');
        }
        $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $envelope = $document instanceof stdClass && property_exists($document, 'result')
            ? $document->result : $document;
        $rows = $envelope instanceof stdClass ? ($envelope->data ?? null) : null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Fixture inventory must be a list.');
        }
        $field = $databases ? 'database' : 'user';
        $identities = [];
        foreach ($rows as $row) {
            $identity = $row instanceof stdClass ? ($row->{$field} ?? null) : null;
            if (!is_string($identity) || $identity === '' || in_array($identity, $identities, true)) {
                throw new RuntimeException('Fixture inventory identities are unusable.');
            }
            $identities[] = $identity;
        }

        return $identities;
    }

    /** @param Report $report */
    private function cleanup(array &$report): void
    {
        foreach (array_reverse($this->names, true) as $role => $name) {
            if ($report['resources'][$role]['creation'] === 'not_attempted') {
                continue;
            }
            if ($report['cleanup'] === 'not_required') {
                $report['cleanup'] = 'passed';
            }
            $database = str_ends_with($role, 'database');
            $report['resources'][$role]['cleanup'] = 'unconfirmed';
            try {
                if (!in_array($name, $this->inventory($database), true)) {
                    $report['resources'][$role]['cleanup'] = 'confirmed_absent';
                    continue;
                }
                // Every attempt followed confirmed initial absence. A usable inventory now
                // confirms this exact run identity before even an uncertain creation is deleted.
                $report['resources'][$role]['creation'] = 'confirmed';
                try {
                    $this->account->call('Mysql', $database ? 'delete_database' : 'delete_user', [
                        'name' => $name,
                    ], RequestMethod::Post);
                } catch (Throwable) {
                    $report['cleanup'] = 'failed';
                    $report['failures'][] = 'cleanup_request_failed';
                }
                $absent = !in_array($name, $this->inventory($database), true);
                $report['resources'][$role]['cleanup'] = $absent ? 'confirmed_absent' : 'present';
                if (!$absent) {
                    $report['cleanup'] = 'failed';
                    $report['failures'][] = 'cleanup_incomplete';
                }
            } catch (Throwable) {
                $report['cleanup'] = 'failed';
                $report['failures'][] = 'cleanup_unconfirmed';
            }
        }
    }

    /**
     * @param list<Record> $records
     * @param array<string, mixed> $observed
     * @param Report $report
     */
    private function expectations(array &$records, #[SensitiveParameter] array $observed, array &$report): void
    {
        $report['expectations'] = 'passed';
        $seen = [];
        foreach ($records as &$record) {
            if (!self::isMysql($record['operation'], $record['route'])) {
                continue;
            }
            $identity = $record['operation'] . '|' . $record['route'];
            $seen[] = $identity;
            if (
                $record['probe_outcome'] !== 'passed'
                || !$this->expectedResult(
                    $observed[$identity] ?? null,
                    $record['operation'] === 'uapi:Mysql/list_databases',
                )
            ) {
                $report['expectations'] = 'failed';
                if ($record['probe_outcome'] === 'passed') {
                    $record['probe_outcome'] = 'failed';
                    $record['failure_kind'] = 'fixture_expectation_failed';
                }
            }
        }
        if (count($seen) !== 4 || count(array_unique($seen)) !== 4) {
            $report['expectations'] = 'failed';
        }
        if ($report['expectations'] === 'failed') {
            $report['failures'][] = 'fixture_expectation_failed';
        }
    }

    /** Check only fixture-owned records; unrelated inventory values remain outside fixture expectations. */
    private function expectedResult(#[SensitiveParameter] mixed $result, bool $databases): bool
    {
        if (!is_array($result) || !array_is_list($result)) {
            return false;
        }
        $identity = $databases ? 'database' : 'user';
        $nested = $databases ? 'users' : 'databases';
        $linked = $this->names[$databases ? 'linked_database' : 'linked_user'];
        $unlinked = $this->names[$databases ? 'unlinked_database' : 'unlinked_user'];
        $other = $this->names[$databases ? 'linked_user' : 'linked_database'];
        $found = [];
        foreach ($result as $row) {
            if (!is_array($row) || !in_array($row[$identity] ?? null, [$linked, $unlinked], true)) {
                continue;
            }
            $name = $row[$identity];
            if (isset($found[$name])) {
                return false;
            }
            $found[$name] = true;
            $values = $row[$nested] ?? null;
            if (!is_array($values) || !array_is_list($values)) {
                return false;
            }
            if (($name === $linked && !in_array($other, $values, true)) || ($name === $unlinked && $values !== [])) {
                return false;
            }
            if ($databases ? !is_int($row['disk_usage'] ?? null) : !is_string($row['shortuser'] ?? null)) {
                return false;
            }
        }

        return isset($found[$linked], $found[$unlinked]);
    }

    private static function isMysql(string $operation, string $route): bool
    {
        return in_array($operation, ['uapi:Mysql/list_databases', 'uapi:Mysql/list_users'], true)
            && in_array($route, ['direct-uapi', 'bridged-uapi'], true);
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

        return [
            'recipe' => 'mysql-npr-linked-unlinked-v1', 'status' => 'failed', 'setup' => 'not_started',
            'capture' => 'not_run', 'expectations' => 'not_run', 'cleanup' => 'not_required', 'failures' => [],
            'resources' => [
                'linked_database' => $resource, 'unlinked_database' => $resource,
                'linked_user' => $resource, 'unlinked_user' => $resource,
            ],
        ];
    }
}
