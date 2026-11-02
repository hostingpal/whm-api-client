<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Api\Uapi\Spec\UserTasksSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\VariablesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\VersionControlSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Observe a real user task queue entry through both routes on one fresh disposable account, delete it, read again.
 *
 * DisposableAccountLifecycle owns identity, ownership, the short-lived writable token, account cleanup and the run's
 * status, and states their rules. This fixture holds the producer, the queue reads and the one deletion.
 *
 * The producer is a VersionControl clone whose source is an empty repository this run creates first, inside the same
 * account, so the server's git clones a local path: no network is contacted, nothing is installed and no server
 * setting changes. Both queue routes must read empty before the producer runs, so every later row is this run's;
 * ownership still needs an exact subsystem, action and clone root. The task ID comes from the producer's own response
 * or, failing that, from a queue read, always from the original JSON. Exactly one owned queue element is deleted, once,
 * by that ID, even if the task already finished: a refusal then is recorded, never retried. Deletion proves neither
 * completion nor that a process stopped. No queued work is executed, polled, cancelled or otherwise touched, and other
 * rows are only counted. Repositories stay for account removal, which takes the whole home directory.
 *
 * Capture stops at the first unexpected state before the producer, so nothing is created on an account that does not
 * look as planned. After the producer, every read and the delete still run, as each says what remains; their failures
 * and unmet expectations are recorded and fail the run. In the report, capture says whether every planned observation
 * finished and passed, expectations whether the plan held, with its findings by fixed label.
 *
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Assessment from UserTasksEvidence
 * @phpstan-import-type CleanupOutcome from DisposableAccountLifecycle
 * @phpstan-import-type ResourceOutcome from DisposableAccountLifecycle
 * @phpstan-import-type TokenOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemControl from DisposableAccountLifecycle
 * @phpstan-type Observation array{
 *     phase: string, expectation: 'passed'|'failed'|'not_checked', uapi_status: int|null,
 *     operation: string, variant: string, argument_variant?: string, route: string, method: string|null,
 *     server_version: string|null, observed_at: string, revision: string, source_sha256: string,
 *     map_sha256: string, generator_tree: string, overlay_sha256: string, effective_contract_sha256: string,
 *     http_status: int|null, api_outcome: string, payload_validation: string, probe_outcome: string,
 *     failure_kind: string|null, response_paths: array<string, list<string>>,
 *     payload_paths: array<string, list<string>>, discrepancies: list<array{path: string, kind: string}>,
 *     payload_alternatives?: list<Alternative>
 * }
 * @phpstan-type QueueRead array{
 *     route: 'direct'|'bridged', observed: bool, list: bool, rows: int|null, owned_rows: int|null,
 *     foreign_rows: int|null
 * }
 * @phpstan-type Ownership array{
 *     initial_queue_empty: bool, producer_rows: int|null, producer_owned_rows: int|null,
 *     owned_task: 'not_attempted'|'proven'|'not_proven'|'ambiguous',
 *     id_source: 'producer_response'|'inventory'|null, id_is_queue_element: bool|null
 * }
 * @phpstan-type Deletion array{
 *     attempted: bool, outcome: string, data: string|null, owned_row_in_latest_read: bool
 * }
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started', capture: 'passed'|'failed'|'not_run',
 *     expectations: 'passed'|'failed'|'not_run', cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     expectation_findings: list<string>, stopped_at: string|null,
 *     resources: array{
 *         account: ResourceOutcome, token: TokenOutcome,
 *         repositories: array{
 *             creation: 'not_attempted'|'confirmed'|'unconfirmed',
 *             cleanup: 'not_required'|'absent_with_account'|'unconfirmed'
 *         },
 *         task: array{
 *             creation: 'not_attempted'|'confirmed'|'unconfirmed',
 *             cleanup: CleanupOutcome|'absent_with_account'
 *         }
 *     },
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>,
 *     filesystem_control: FilesystemControl
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'user-tasks-disposable-v1', target_role: 'disposable-test-account',
 *     producer: 'version-control-local-path-clone', probes: list<Observation>, queue: array<string, QueueRead>,
 *     ownership: Ownership, deletion: Deletion, discrepancy_review: Assessment, producer_review: Assessment,
 *     fixture: Report, limitations: list<string>
 * }
 */
final class UserTasksFixture
{
    private const HOST = 'test.oblak.host';

    /** Long enough for this run and short enough that a lost token dies quickly; cleanup still revokes it. */
    private const TOKEN_LIFETIME = 900;

    private readonly UserTasksRecipe $recipe;

    private readonly UserTasksGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    private readonly Client $root;

    private readonly DisposableAccountLifecycle $lifecycle;

    /** @var Report */
    private array $report;

    private ?Client $direct = null;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<string, QueueRead> */
    private array $queue = [];

    /** @var Ownership */
    private array $ownership = [
        'initial_queue_empty' => false, 'producer_rows' => null, 'producer_owned_rows' => null,
        'owned_task' => 'not_attempted', 'id_source' => null, 'id_is_queue_element' => null,
    ];

    /** @var Deletion */
    private array $deletion = [
        'attempted' => false, 'outcome' => 'not_attempted', 'data' => null, 'owned_row_in_latest_read' => false,
    ];

    /** @var list<string> */
    private array $findings = [];

    private ?string $stoppedAt = null;

    /**
     * Bind the fixed disposable account and a token expiration, without I/O.
     *
     * The client must support form POST, verify TLS and refuse redirects and retries. The guard re-checks both live
     * opt-ins and the exact account and domain on every request. Live callers must obtain committed context through
     * RepositoryContext first. Without a filesystem proof, nothing is claimed about the server's files.
     *
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] string $whmToken,
        private readonly array $context,
        ?FilesystemAbsenceProof $filesystem = null,
    ) {
        $this->recipe = new UserTasksRecipe(bin2hex(random_bytes(16)), DisposableAccount::fresh(
            UserTasksRecipe::ACCOUNT,
            UserTasksRecipe::DOMAIN,
            UserTasksRecipe::TOKEN_NAME,
            self::TOKEN_LIFETIME,
            readOnlyToken: false,
        ));
        $this->guard = new UserTasksGuard($http, $whmToken, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context, UserTasksRecipe::ACCOUNT);
        $factory = new Psr17Factory();
        $this->root = new Client([
            'host' => self::HOST, 'port' => 2087, 'authType' => 'whm', 'username' => 'root', 'token' => $whmToken,
        ], new HttpTransport($this->capture, $factory, $factory));
        $this->lifecycle = new DisposableAccountLifecycle(
            $this->recipe->account,
            $this->capture,
            $this->root,
            $filesystem,
        );
    }

    /**
     * Set up, capture, clean up, and return sanitized evidence including every failure.
     *
     * This instance is single-use. Exception messages, raw payloads, returned credentials, task IDs, arguments, names
     * and paths never enter the result; fixture success and the strict discrepancy assessments stay separate.
     *
     * @return Scenario
     */
    public function run(): array
    {
        $this->report = self::report();
        try {
            $outcome = $this->lifecycle->run(function (#[SensitiveParameter] string $token): void {
                $this->capture($token);
            });
        } catch (LogicException) {
            $this->report['failures'][] = 'configuration_failure';

            return $this->scenario();
        }
        $lifecycle = DisposableAccountLifecycle::reportFields($outcome);
        $this->report = [
            ...$this->report,
            ...$lifecycle,
            'resources' => [...$this->report['resources'], ...$lifecycle['resources']],
        ];
        $removed = $outcome['account']['cleanup'] === 'confirmed_absent';
        if ($this->report['resources']['repositories']['creation'] !== 'not_attempted') {
            $this->report['resources']['repositories']['cleanup'] = $removed ? 'absent_with_account' : 'unconfirmed';
        }
        $task = $this->report['resources']['task'];
        if ($task['creation'] !== 'not_attempted' && $task['cleanup'] !== 'confirmed_absent') {
            $this->report['resources']['task']['cleanup'] = $removed ? 'absent_with_account' : 'unconfirmed';
        }

        return $this->scenario();
    }

    /**
     * Bind the token and run every phase, then tell the lifecycle what failed.
     *
     * @throws ExerciseFailure After recording, for a failed capture or any unmet expectation.
     */
    private function capture(#[SensitiveParameter] string $token): void
    {
        $failures = [];
        $this->report['capture'] = 'failed';
        try {
            $this->guard->bindToken($token);
            $factory = new Psr17Factory();
            $this->direct = new Client([
                'host' => self::HOST, 'port' => 2083, 'authType' => 'cpanel',
                'username' => UserTasksRecipe::ACCOUNT, 'token' => $token,
            ], new HttpTransport($this->capture, $factory, $factory));
            $this->exercise();
            if (!$this->observationFailed()) {
                $this->report['capture'] = 'passed';
            }
        } catch (Throwable) {
            $last = array_key_last($this->records);
            $this->stoppedAt ??= $last === null ? null : $this->records[$last]['phase'];
        }
        $this->report['stopped_at'] = $this->stoppedAt;
        if ($this->observationFailed() || ($this->report['capture'] === 'failed' && $this->findings === [])) {
            $failures[] = 'capture_failure';
        }
        $this->report['expectation_findings'] = $this->findings;
        if ($this->findings !== []) {
            $this->report['expectations'] = 'failed';
            $failures[] = 'expectation_failure';
        } elseif ($this->report['capture'] === 'passed') {
            $this->report['expectations'] = 'passed';
        }
        if ($failures !== []) {
            throw new ExerciseFailure($failures);
        }
    }

    private function exercise(): void
    {
        $recipe = $this->recipe;
        $direct = $this->direct ?? throw new LogicException('User task client unavailable.');
        $this->observe('version', new Probe(
            'whm1:version',
            'whm',
            ServerSpec::VERSION,
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            static fn (#[SensitiveParameter] Client $client): mixed => $client->whm()->server()->version(),
        ), $this->root);
        $this->gate($this->passed());

        $home = $this->observe('home', new Probe(
            'uapi:Variables/get_user_information',
            'direct-uapi',
            VariablesSpec::GET_USER_INFORMATION,
            $recipe->homeUri(),
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->call(
                'Variables',
                'get_user_information',
                ['name' => 'home'],
                RequestMethod::Get,
            )->data,
            null,
            null,
            'home-field',
        ), $direct, false);
        $this->gate($this->passed());
        $this->gate(is_array($home) && ($home['home'] ?? null) === UserTasksRecipe::HOME, 'home_mismatch:home');

        foreach (['tasks_initial_direct' => false, 'tasks_initial_bridged' => true] as $phase => $bridged) {
            $owned = $this->readQueue($phase, $bridged);
            $this->gate($this->passed());
            $read = $this->queue[$phase];
            $this->gate($owned === [] && $read['list'] && $read['rows'] === 0, 'initial_queue_not_empty:' . $phase);
        }
        $this->ownership['initial_queue_empty'] = true;

        $this->report['resources']['repositories']['creation'] = 'unconfirmed';
        $this->create('source_create', 'source');
        $this->gate($this->passed());
        $source = $this->rawData();
        $this->gate(
            $source instanceof stdClass && ($source->repository_root ?? null) === $recipe->sourceRoot()
                && ($source->tasks ?? null) === [],
            'source_unexpected:source_create',
        );
        $this->report['resources']['repositories']['creation'] = 'confirmed';

        $this->report['resources']['task']['creation'] = 'unconfirmed';
        $this->create('clone_create', 'clone');
        $this->gate($this->passed());
        $this->report['resources']['task']['creation'] = 'confirmed';
        $clone = $this->rawData();
        $rows = $clone instanceof stdClass ? ($clone->tasks ?? null) : null;
        $producer = is_array($rows) ? $this->ownedIds($rows) : [];
        $this->ownership['producer_rows'] = is_array($rows) ? count($rows) : null;
        $this->ownership['producer_owned_rows'] = is_array($rows) ? count($producer) : null;
        if (!$clone instanceof stdClass || ($clone->repository_root ?? null) !== $recipe->cloneRoot()) {
            $this->finding('clone_unexpected');
        }

        // After the producer nothing stops capture: every read says what remains, and the delete is bound to an ID.
        $inventory = [];
        foreach (['tasks_queued_direct' => false, 'tasks_queued_bridged' => true] as $phase => $bridged) {
            array_push($inventory, ...$this->readQueue($phase, $bridged));
            $this->judgeRead($phase, false);
        }
        $latest = $this->queue['tasks_queued_bridged'];
        $this->deletion['owned_row_in_latest_read'] = ($latest['owned_rows'] ?? 0) > 0;
        $this->deleteOwned($producer, $inventory);
        $absent = true;
        foreach (['tasks_after_direct' => false, 'tasks_after_bridged' => true] as $phase => $bridged) {
            $this->readQueue($phase, $bridged);
            $this->judgeRead($phase, true);
            $absent = $absent && $this->queue[$phase]['observed'] && $this->queue[$phase]['owned_rows'] === 0;
        }
        $present = ($this->queue['tasks_after_direct']['owned_rows'] ?? 0) > 0
            || ($this->queue['tasks_after_bridged']['owned_rows'] ?? 0) > 0;
        $this->report['resources']['task']['cleanup'] = $present ? 'present' : ($absent ? 'confirmed_absent'
            : 'unconfirmed');
    }

    /**
     * Read one route's queue, record what it held by fixed counts, and return the owned IDs from the original JSON.
     *
     * Ownership never relies on the typed result, so a read that fails validation still says what is there.
     *
     * @return list<string>
     */
    private function readQueue(string $phase, bool $bridged): array
    {
        $this->observe($phase, new Probe(
            'uapi:UserTasks/retrieve',
            $bridged ? 'bridged-uapi' : 'direct-uapi',
            UserTasksSpec::RETRIEVE,
            $this->recipe->tasksUri($bridged),
            $bridged
                ? static fn (#[SensitiveParameter] Client $client): mixed =>
                    $client->uapi(UserTasksRecipe::ACCOUNT)->userTasks()->listTasks()
                : static fn (#[SensitiveParameter] Client $client): mixed =>
                    $client->uapi()->userTasks()->listTasks(),
        ), $bridged ? $this->root : ($this->direct ?? throw new LogicException('User task client unavailable.')));
        $rows = $this->rawData();
        $list = is_array($rows) && array_is_list($rows);
        $owned = $list ? $this->ownedIds($rows) : [];
        $count = $list ? count($rows) : null;
        $this->queue[$phase] = [
            'route' => $bridged ? 'bridged' : 'direct',
            'observed' => $this->last()['probe_outcome'] === 'passed',
            'list' => $list,
            'rows' => $count,
            'owned_rows' => $list ? count($owned) : null,
            'foreign_rows' => $count === null ? null : $count - count($owned),
        ];

        return $owned;
    }

    /** Judge one read after the producer without stopping: what it held is recorded either way. */
    private function judgeRead(string $phase, bool $afterDelete): void
    {
        $read = $this->queue[$phase];
        if (!$read['list']) {
            $this->finding('queue_unreadable:' . $phase);

            return;
        }
        if ($afterDelete ? $read['owned_rows'] !== 0 : $read['owned_rows'] === 0) {
            $this->finding(($afterDelete ? 'owned_row_present_after_delete:' : 'owned_row_absent:') . $phase);
        }
        if ($read['foreign_rows'] !== 0) {
            $this->finding('foreign_row_present:' . $phase);
        }
    }

    /**
     * Delete the one proven-owned queue element once, by its exact ID; nothing else is ever authorized.
     *
     * @param list<string> $producer IDs from the producer's own response.
     * @param list<string> $inventory IDs from the queue reads that followed it.
     */
    private function deleteOwned(array $producer, array $inventory): void
    {
        $ids = array_values(array_unique([...$producer, ...$inventory]));
        if ($ids === []) {
            $this->ownership['owned_task'] = 'not_proven';
            $this->finding('owned_task_not_proven');

            return;
        }
        $id = $ids[0];
        $this->ownership['id_is_queue_element'] = count($ids) === 1 && UserTasksRecipe::isTaskId($id);
        if (!$this->ownership['id_is_queue_element']) {
            $this->ownership['owned_task'] = 'ambiguous';
            $this->finding('owned_task_ambiguous');

            return;
        }
        $this->ownership['owned_task'] = 'proven';
        $this->ownership['id_source'] = $producer !== [] ? 'producer_response' : 'inventory';
        $this->deletion['attempted'] = true;
        try {
            $this->guard->authorizeDelete($id);
            $this->observe('delete_owned', new Probe(
                'uapi:UserTasks/delete',
                'direct-uapi',
                UserTasksSpec::DELETE,
                $this->recipe->deleteUri(),
                static fn (#[SensitiveParameter] Client $client): mixed =>
                    $client->uapi()->userTasks()->deleteTask(id: $id),
                null,
                null,
                'owned-queue-element',
            ), $this->direct ?? throw new LogicException('User task client unavailable.'));
        } finally {
            $this->guard->clearPending();
        }
        $record = $this->last();
        $this->deletion['outcome'] = $record['probe_outcome'] === 'passed' && $record['uapi_status'] === 1
            ? 'deleted' : ($record['failure_kind'] ?? 'unexpected_outcome');
        $this->deletion['data'] = $this->rawDataType();
        if ($this->deletion['outcome'] !== 'deleted') {
            $this->finding('owned_delete_not_confirmed');
        }
    }

    /** @param 'source'|'clone' $repository */
    private function create(string $phase, string $repository): void
    {
        $parameters = $this->recipe->createParameters($repository);
        $this->guard->authorizeCreate($repository);
        try {
            $this->observe($phase, new Probe(
                'uapi:VersionControl/create',
                'direct-uapi',
                VersionControlSpec::CREATE,
                $this->recipe->createUri(),
                static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->call(
                    'VersionControl',
                    'create',
                    $parameters,
                    RequestMethod::Post,
                )->data,
                null,
                null,
                $repository === 'source' ? 'local-empty-repository' : 'local-path-clone',
            ), $this->direct ?? throw new LogicException('User task client unavailable.'), false);
        } finally {
            $this->guard->clearPending();
        }
    }

    /**
     * Owned task IDs among raw rows, each matched exactly; a non-string ID proves nothing and is left out.
     *
     * @param array<mixed> $rows
     * @return list<string>
     */
    private function ownedIds(#[SensitiveParameter] array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($this->recipe->isOwnedRow($row) && $row instanceof stdClass && is_string($row->id ?? null)) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    /** The original response's UAPI data, decoded with objects kept distinct from lists; null when unreadable. */
    private function rawData(): mixed
    {
        $envelope = $this->rawEnvelope();

        return $envelope instanceof stdClass ? ($envelope->data ?? null) : null;
    }

    /** The JSON type of the original data member, by fixed label, or null when no envelope was read. */
    private function rawDataType(): ?string
    {
        $envelope = $this->rawEnvelope();
        if (!$envelope instanceof stdClass) {
            return null;
        }
        if (!property_exists($envelope, 'data')) {
            return 'missing';
        }

        return match (true) {
            $envelope->data === null => 'null',
            $envelope->data instanceof stdClass => 'object',
            is_array($envelope->data) => 'list',
            default => 'scalar',
        };
    }

    private function rawEnvelope(): ?stdClass
    {
        $raw = $this->capture->rawBody();
        if ($raw === null) {
            return null;
        }
        try {
            $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!$document instanceof stdClass) {
            return null;
        }
        if (($document->data ?? null) instanceof stdClass && ($document->data->uapi ?? null) instanceof stdClass) {
            return $document->data->uapi;
        }
        $envelope = property_exists($document, 'result') ? $document->result : $document;

        return $envelope instanceof stdClass ? $envelope : null;
    }

    /**
     * Capture one call through the shared runner, keeping its result in memory for expectations.
     *
     * The runner clears prior capture and never retries; the record carries only structural evidence. It reports
     * validation passed for any call that returned, but only a typed call has a validator, so a generic call's record
     * says not_checked. Structural discrepancies from the same response still reach the review.
     */
    private function observe(string $phase, Probe $probe, Client $client, bool $validated = true): mixed
    {
        $result = null;
        $invoke = $probe->invoke;
        $wrapped = new Probe(
            $probe->operation,
            $probe->route,
            $probe->spec,
            $probe->uri,
            static function (#[SensitiveParameter] Client $client) use ($invoke, &$result): mixed {
                return $result = $invoke($client);
            },
            null,
            $probe->sourceVariant,
            $probe->argumentVariant,
        );
        $record = $this->runner->capture($wrapped, $this->capture, $client);
        if (!$validated) {
            $record['payload_validation'] = 'not_checked';
        }
        $metadata = match ($probe->route) {
            'direct-uapi' => $client->uapi()->responseMeta(),
            'bridged-uapi' => $client->uapi(UserTasksRecipe::ACCOUNT)->responseMeta(),
            default => null,
        };
        $this->records[] = [...$record, 'phase' => $phase, 'uapi_status' => $metadata?->uapiStatus(),
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];

        return $result;
    }

    private function passed(): bool
    {
        return $this->last()['probe_outcome'] === 'passed';
    }

    /** Before the producer, stop at the first unexpected state; a label also records an unmet expectation. */
    private function gate(bool $condition, ?string $label = null): void
    {
        if ($condition) {
            return;
        }
        if ($label !== null) {
            $this->finding($label);
        }
        $this->stoppedAt = $this->last()['phase'];
        throw new RuntimeException('User task capture stopped.');
    }

    /** Record an unmet expectation by fixed label, failing the latest observation's expectation. */
    private function finding(string $label): void
    {
        $this->findings[] = $label;
        $index = array_key_last($this->records);
        if ($index !== null && $this->records[$index]['probe_outcome'] === 'passed') {
            $this->records[$index]['expectation'] = 'failed';
        }
    }

    /**
     * Whether an observation produced no usable outcome; an API refusal is a recorded outcome, judged as expected.
     */
    private function observationFailed(): bool
    {
        foreach ($this->records as $record) {
            if ($record['probe_outcome'] !== 'passed' && $record['failure_kind'] !== 'api_failure') {
                return true;
            }
        }

        return false;
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('User task observation unavailable.');
        }

        return $this->records[$index];
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

        return [
            'status' => 'failed', 'setup' => 'not_started', 'capture' => 'not_run',
            'expectations' => 'not_run', 'cleanup' => 'not_required', 'failures' => [],
            'expectation_findings' => [], 'stopped_at' => null,
            'resources' => ['account' => $resource, 'token' => $resource, 'repositories' => $resource,
                'task' => $resource],
            'domain_cleanup' => 'not_required', 'zone_cleanup' => 'not_required',
            'filesystem_cleanup' => 'not_claimed', 'filesystem_residue' => [],
            'filesystem_control' => ['status' => 'not_run', 'counts' => []],
        ];
    }

    /**
     * Assemble the sanitized result, attributing every observation to the run's one server version.
     *
     * @return Scenario
     */
    private function scenario(): array
    {
        $versions = array_column(array_filter($this->records, static fn (array $record): bool =>
            $record['phase'] === 'version'), 'server_version');
        $records = $this->records;
        foreach ($records as &$record) {
            $record['server_version'] ??= is_string($versions[0] ?? null) ? $versions[0] : null;
        }
        unset($record);
        $reviews = UserTasksEvidence::assess($records, $this->context);

        return [
            'scenario' => 'user-tasks-disposable-v1',
            'target_role' => 'disposable-test-account',
            'producer' => 'version-control-local-path-clone',
            'probes' => $records,
            'queue' => $this->queue,
            'ownership' => $this->ownership,
            'deletion' => $this->deletion,
            'discrepancy_review' => $reviews['discrepancy_review'],
            'producer_review' => $reviews['producer_review'],
            'fixture' => $this->report,
            'limitations' => [
                'one_version_control_clone_producer_on_one_server_version_only',
                'race_with_the_queue_runner_is_uncontrolled_and_unrepeated',
                'deletion_establishes_neither_task_completion_nor_process_termination',
                'clone_outcome_and_runner_behavior_unobserved',
                'wpx_and_other_producer_rows_unobserved',
                $this->report['filesystem_cleanup'] === 'confirmed_absent'
                    ? 'filesystem_proof_covers_fixed_locations_at_one_moment'
                    : 'api_absence_only_no_filesystem_or_home_directory_cleanup_claim',
            ],
        ];
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Fixture state contains credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('User task fixture state cannot be serialized.');
    }
}
