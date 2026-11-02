<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Independent account, token, repository and user task queue double for the disposable user task fixture.
 *
 * The queue follows the installed cPanel 11.138.0.19 sources the recipe was derived from: Cpanel::UserTasks::add()
 * stores subsystem, action, args and exclusive (0 when omitted), and get() returns them as strings with sse_url added
 * when args.log_file is set; Directory::Queue::Normal names elements as eight then fourteen hexadecimal digits. A
 * VersionControl clone queues one task and its create response lists the queue rows for that root at response time.
 * A local create queues nothing. Deleting a missing element is an API failure, as its lock cannot be taken. The task
 * finishes, and leaves the queue, after a configurable number of queue reads, to model the race with the runner.
 * Failures fire `_before` or `_after` a request's effect: `throw` loses the response, `api` is an explicit refusal,
 * `http` a 502 gateway error and `invalid` an unreadable 200 body.
 */
final class UserTasksServer implements ClientInterface
{
    public const HOME = '/home/wltasks';

    public const TOKEN = 'PRIVATEFIXTURETOKEN';

    public const FOREIGN_ID = '00000000/0fedcba987654a';

    public bool $account = false;

    public bool $zone = false;

    /** @var list<string> */
    public array $tokens = [];

    /** The home directory the user information read reports. */
    public string $home = self::HOME;

    /** @var array<string, string> Repository root, then 'local' or 'clone'. */
    public array $repositories = [];

    /** @var array<string, array<string, mixed>> Queue element name, then the stored task. */
    public array $queue = [];

    /** Queue reads, the create response's own included, after which a clone task finishes; null never finishes. */
    public ?int $finishAfterReads = null;

    /** Whether a successful delete really removes the element; false models residue the reads must expose. */
    public bool $deleteRemoves = true;

    /** Whether create responses carry the stored exclusive key in their task rows, as get() returns it. */
    public bool $producerRowsCarryExclusive = true;

    /** @var array<string, mixed> Replacement fields for every queue row as returned. */
    public array $rowFields = [];

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $mutations = [];

    /** @var array<string, string> Step failures by function:count. */
    public array $failures = [];

    /** @var array<string, string> Raw replacement data JSON by function:count. */
    public array $overrides = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var (Closure(self, string): void)|null Runs after any request's effect, to model concurrent change. */
    public ?Closure $afterEffect = null;

    private string $contactEmail = '';

    private int $expiresAt = 0;

    private int $readsSinceQueued = 0;

    private int $sequence = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $path = $request->getUri()->getPath();
        $function = basename($path);
        $bridge = $function === 'uapi_cpanel';
        if ($bridge) {
            if (!is_string($query['cpanel_module'] ?? null) || !is_string($query['cpanel_function'] ?? null)) {
                throw new LogicException('Synthetic bridge request is malformed.');
            }
            if (($query['cpanel_user'] ?? null) !== 'wltasks') {
                throw new LogicException('Synthetic bridge request names another account.');
            }
            $function = $query['cpanel_module'] . '/' . $query['cpanel_function'];
        } elseif (str_starts_with($path, '/execute/')) {
            $function = 'direct:' . substr($path, strlen('/execute/'));
            $live = $request->getHeaderLine('Authorization') === 'cpanel wltasks:' . self::TOKEN;
            if ($this->tokens === [] || !$live) {
                throw new LogicException('Synthetic direct request without a live temporary token.');
            }
        }
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;
        $step = $function . ':' . $this->counts[$function];
        $failure = $this->failures[$step] ?? '';
        if (str_ends_with($failure, '_before')) {
            return self::fail(substr($failure, 0, -7), $function, $bridge);
        }
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
        }
        $parameters = [];
        parse_str((string) $request->getBody(), $parameters);
        $data = $this->apply($function, $parameters);
        if ($this->afterEffect !== null) {
            ($this->afterEffect)($this, $step);
        }
        if (str_ends_with($failure, '_after')) {
            return self::fail(substr($failure, 0, -6), $function, $bridge);
        }
        if ($data === false) {
            return self::fail('api', $function, $bridge);
        }
        $payload = $this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR);

        return self::success($payload, $function, $bridge);
    }

    /** Queue a task another producer made, which is never this run's. */
    public function queueForeign(string $repositoryRoot): void
    {
        $this->queue[self::FOREIGN_ID] = [
            'subsystem' => 'VersionControl', 'action' => 'create', 'exclusive' => '0',
            'args' => ['repository_root' => $repositoryRoot, 'log_file' => self::HOME . '/.cpanel/logs/private.log'],
        ];
    }

    /**
     * Apply one request and return its data, or false for an explicit API refusal.
     *
     * @param array<mixed> $parameters
     */
    private function apply(string $function, array $parameters): mixed
    {
        switch ($function) {
            case 'createacct':
                $email = $parameters['contactemail'] ?? null;
                if (!is_string($email)) {
                    throw new LogicException('Synthetic account ownership is malformed.');
                }
                $this->account = true;
                $this->zone = true;
                $this->contactEmail = $email;

                return null;
            case 'Tokens/create_full_access':
                if (!is_string($parameters['expires_at'] ?? null)) {
                    throw new LogicException('Synthetic token expiration is missing.');
                }
                $this->expiresAt = (int) $parameters['expires_at'];
                $this->tokens[] = 'wltasks-fixture';

                return ['token' => self::TOKEN, 'create_time' => 1790000000];
            case 'Tokens/revoke':
                $this->tokens = [];

                return 1;
            case 'removeacct':
                $this->account = false;
                $this->zone = false;
                $this->tokens = [];
                $this->repositories = [];
                $this->queue = [];

                return null;
            case 'direct:VersionControl/create':
                return $this->create($parameters);
            case 'direct:UserTasks/delete':
                $id = $parameters['id'] ?? null;
                if (!is_string($id) || !isset($this->queue[$id])) {
                    return false;
                }
                if ($this->deleteRemoves) {
                    unset($this->queue[$id]);
                }

                return null;
            case 'UserTasks/retrieve':
            case 'direct:UserTasks/retrieve':
                return $this->rows(null);
        }

        return $this->payload($function);
    }

    /** @param array<mixed> $parameters */
    private function create(array $parameters): mixed
    {
        $root = $parameters['repository_root'] ?? null;
        if (
            !is_string($root) || !str_starts_with($root, self::HOME . '/') || isset($this->repositories[$root])
            || ($parameters['type'] ?? null) !== 'git' || !is_string($parameters['name'] ?? null)
        ) {
            return false;
        }
        $data = [
            'available_branches' => [], 'branch' => null, 'clone_urls' => ['read_only' => [], 'read_write' => []],
            'last_update' => null, 'name' => $parameters['name'], 'repository_root' => $root, 'type' => 'git',
        ];
        $source = $parameters['source_repository'] ?? null;
        if ($source === null) {
            $this->repositories[$root] = 'local';
            $data['tasks'] = $this->rows($root);

            return $data;
        }
        $decoded = is_string($source) ? json_decode($source, true) : null;
        $url = is_array($decoded) ? ($decoded['url'] ?? null) : null;
        if (!is_string($url) || preg_match('/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=]/', $url) === 1) {
            return false;
        }
        $this->repositories[$root] = 'clone';
        $id = sprintf('%08x/%014x', 0, 0x5a9ec8dd4c3400 + ++$this->sequence);
        $this->queue[$id] = [
            'subsystem' => 'VersionControl', 'action' => 'create', 'exclusive' => '0',
            'args' => ['repository_root' => $root,
                'log_file' => self::HOME . '/.cpanel/logs/vc_private_git_create.log'],
        ];
        $this->readsSinceQueued = 0;
        $data['source_repository'] = ['remote_name' => 'origin', 'url' => $url];
        $data['tasks'] = $this->rows($root);

        return $data;
    }

    /**
     * Read the queue as get() returns it, letting a clone task finish first once its reads run out.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(?string $root): array
    {
        ++$this->readsSinceQueued;
        if ($this->finishAfterReads !== null && $this->readsSinceQueued > $this->finishAfterReads) {
            $this->queue = array_filter($this->queue, static fn (string $id): bool =>
                $id === self::FOREIGN_ID, ARRAY_FILTER_USE_KEY);
        }
        $rows = [];
        foreach ($this->queue as $id => $task) {
            $args = $task['args'];
            if (!is_array($args) || ($root !== null && ($args['repository_root'] ?? null) !== $root)) {
                continue;
            }
            $logFile = $args['log_file'] ?? null;
            $row = [...$task, 'id' => $id];
            if (is_string($logFile)) {
                $row['sse_url'] = '/sse/UserTasks/' . str_replace('/', '_', $id) . '/' . basename($logFile);
            }
            if ($root !== null && !$this->producerRowsCarryExclusive) {
                unset($row['exclusive']);
            }
            $rows[] = array_replace($row, $root === null ? $this->rowFields : []);
        }

        return $rows;
    }

    private function payload(string $function): mixed
    {
        return match ($function) {
            'version' => ['version' => '11.138.0.19'],
            'listaccts' => ['acct' => array_merge([
                ['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com'],
                ['user' => 'wltasksextra', 'domain' => 'wltasks.example.com.other'],
            ], $this->account
                ? [['user' => 'wltasks', 'domain' => 'wltasks.example.com', 'email' => $this->contactEmail]] : [])],
            'listzones' => ['zone' => array_merge(
                [['domain' => 'existing.example', 'zonefile' => 'private.db']],
                $this->zone ? [['domain' => 'wltasks.example.com', 'zonefile' => 'private.db']] : [],
            )],
            'get_domain_info' => ['domains' => array_merge(
                [['domain' => 'existing.example', 'user' => 'existing']],
                $this->account ? [['domain' => 'wltasks.example.com', 'user' => 'wltasks']] : [],
            )],
            'Tokens/list' => array_map(fn (string $name): array => [
                'name' => $name, 'expires_at' => (string) $this->expiresAt, 'has_full_access' => 1,
            ], $this->tokens),
            'direct:Variables/get_user_information' => ['home' => $this->home],
            default => null,
        };
    }

    private static function success(string $payload, string $function, bool $bridge): ResponseInterface
    {
        $metadata = str_ends_with($function, 'UserTasks/retrieve') ? '{"transformed":1}' : '{}';
        $uapi = '{"status":1,"data":' . $payload . ',"errors":null,"messages":null,"warnings":null,"metadata":'
            . $metadata . '}';
        if (str_starts_with($function, 'direct:')) {
            return new Response(200, [], $uapi);
        }

        return new Response(200, [], $bridge
            ? '{"metadata":{"result":1},"data":{"uapi":' . $uapi . '}}'
            : '{"metadata":{"result":1,"reason":"private upstream text"},"data":' . $payload . '}');
    }

    private static function fail(string $kind, string $function, bool $bridge): ResponseInterface
    {
        $uapi = '{"status":0,"data":null,"errors":["private upstream text"],"messages":null,"warnings":null,'
            . '"metadata":{}}';

        return match ($kind) {
            'throw' => throw new class ('private transport failure') extends RuntimeException implements
                ClientExceptionInterface
            {
            },
            'api' => new Response(
                200,
                [], match (true) {
                str_starts_with($function, 'direct:') => $uapi,
                $bridge => '{"metadata":{"result":1},"data":{"uapi":' . $uapi . '}}',
                default => '{"metadata":{"result":0,"reason":"private upstream text"},"data":null}',
                },
            ),
            'http' => new Response(502, [], 'private upstream gateway text'),
            'invalid' => new Response(200, [], 'private unreadable upstream text'),
            default => throw new LogicException('Unknown synthetic failure.'),
        };
    }
}
