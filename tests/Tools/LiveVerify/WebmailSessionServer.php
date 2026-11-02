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
 * Independent account, token, mailbox and Webmail session state double for the disposable session fixture.
 *
 * Creator behavior follows the pinned source and the PR102 review: each creator returns a hostname, session and token,
 * a mail-user creator needs an existing mailbox and the checking one its correct password. Like the reviewed server,
 * removing the account leaves the sessions behind. Failures fire `_before` or `_after` a request's real effect:
 * `throw` loses the response, `api` is an explicit refusal, `http` a 502 gateway error, `invalid` an unreadable body.
 */
final class WebmailSessionServer implements ClientInterface
{
    public const TOKEN = 'PRIVATEACCOUNTTOKEN';

    public const ABSENT = '__absent__';

    public bool $account = false;

    public bool $zone = false;

    /** @var list<string> */
    public array $tokens = [];

    /** @var array<string, string> Mailbox local part, then its password. */
    public array $mailboxes = [];

    /** @var list<array{creator: string, route: string}> Sessions created; account removal leaves them. */
    public array $sessions = [];

    /** Whether the session inventory lists created sessions at all. */
    public bool $listSessions = true;

    /** The hostname every creator returns; ABSENT omits the field. */
    public mixed $hostname = null;

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> Effects that actually reached this server, in order. */
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
            if (($query['cpanel_user'] ?? null) !== 'wlsession') {
                throw new LogicException('Synthetic bridge request names another account.');
            }
            $function = $query['cpanel_module'] . '/' . $query['cpanel_function'];
        } elseif (str_starts_with($path, '/execute/')) {
            $function = 'direct:' . substr($path, strlen('/execute/'));
            $this->requireLiveToken($request);
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
        $refused = !$this->apply($function, $parameters, $bridge);
        if ($this->afterEffect !== null) {
            ($this->afterEffect)($this, $step);
        }
        if (str_ends_with($failure, '_after')) {
            return self::fail(substr($failure, 0, -6), $function, $bridge);
        }
        if ($refused) {
            return self::fail('api', $function, $bridge);
        }
        $payload = $this->overrides[$step] ?? json_encode($this->payload($function), JSON_THROW_ON_ERROR);

        return match (true) {
            str_starts_with($function, 'direct:') => self::uapi($payload, false),
            $bridge => self::uapi($payload, true),
            default => new Response(200, [], '{"metadata":{"result":1,"reason":"private upstream text"},"data":'
                . $payload . '}'),
        };
    }

    /** A revoked or wrong credential authenticates nothing, so cleanup order is real here. */
    private function requireLiveToken(RequestInterface $request): void
    {
        if ($this->tokens === [] || $request->getHeaderLine('Authorization') !== 'cpanel wlsession:' . self::TOKEN) {
            throw new LogicException('Synthetic direct request without a live temporary token.');
        }
    }

    /**
     * Apply one request's effect; false is the server's own refusal.
     *
     * @param array<mixed> $parameters
     */
    private function apply(string $function, array $parameters, bool $bridge): bool
    {
        $creator = str_contains($function, 'Session/create_webmail_session_for_')
            ? substr($function, (int) strrpos($function, '_for_') + 5) : null;
        switch (true) {
            case $function === 'createacct':
                $email = $parameters['contactemail'] ?? null;
                if (!is_string($email)) {
                    throw new LogicException('Synthetic account ownership is malformed.');
                }
                $this->account = true;
                $this->zone = true;
                $this->contactEmail = $email;
                break;
            case $function === 'Tokens/create_full_access':
                if (!is_string($parameters['expires_at'] ?? null)) {
                    throw new LogicException('Synthetic token expiration is missing.');
                }
                $this->expiresAt = (int) $parameters['expires_at'];
                $this->tokens[] = 'wlsession-session-fixture';
                break;
            case $function === 'Tokens/revoke':
                $this->tokens = [];
                break;
            case $function === 'removeacct':
                $this->account = false;
                $this->zone = false;
                $this->tokens = [];
                $this->mailboxes = [];
                break;
            case $function === 'direct:Email/add_pop':
                $local = $parameters['email'] ?? null;
                $password = $parameters['password'] ?? null;
                if (
                    !is_string($local) || !is_string($password) || ($parameters['domain'] ?? null)
                        !== 'wlsession.example.com' || ($parameters['send_welcome_email'] ?? null) !== '0'
                ) {
                    throw new LogicException('Synthetic mailbox creation is malformed.');
                }
                $this->mailboxes[$local] = $password;
                break;
            case $creator !== null:
                if (($parameters['remote_address'] ?? null) !== '192.0.2.10') {
                    throw new LogicException('Synthetic creator has no documentation address.');
                }
                if ($creator !== 'self') {
                    $login = $parameters['login'] ?? null;
                    $known = is_string($login) && isset($this->mailboxes[$login])
                        && ($parameters['domain'] ?? null) === 'wlsession.example.com';
                    if (
                        !$known || ($creator === 'mail_user_check_password'
                        && ($parameters['password'] ?? null) !== $this->mailboxes[$login])
                    ) {
                        return false;
                    }
                }
                $this->sessions[] = ['creator' => $creator, 'route' => $bridge ? 'bridged' : 'direct'];
                break;
        }

        return true;
    }

    private function payload(string $function): mixed
    {
        $session = count($this->sessions);

        return match (true) {
            $function === 'version' => ['version' => '11.138.0.19'],
            $function === 'listaccts' => ['acct' => array_merge([
                ['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com'],
                ['user' => 'wlsessionextra', 'domain' => 'wlsession.example.com.other'],
            ], $this->account
                ? [['user' => 'wlsession', 'domain' => 'wlsession.example.com', 'email' => $this->contactEmail]]
                : [])],
            $function === 'listzones' => ['zone' => array_merge(
                [['domain' => 'existing.example', 'zonefile' => 'private.db']],
                $this->zone ? [['domain' => 'wlsession.example.com', 'zonefile' => 'private.db']] : [],
            )],
            $function === 'get_domain_info' => ['domains' => array_merge(
                [['domain' => 'existing.example', 'user' => 'existing']],
                $this->account ? [['domain' => 'wlsession.example.com', 'user' => 'wlsession']] : [],
            )],
            $function === 'Tokens/list' => array_map(fn (string $name): array => [
                'name' => $name, 'expires_at' => (string) $this->expiresAt, 'has_full_access' => 1,
            ], $this->tokens),
            $function === 'Tokens/create_full_access' => ['token' => self::TOKEN, 'create_time' => 1790000000],
            $function === 'Tokens/revoke' => 1,
            $function === 'direct:Email/add_pop' => array_key_last($this->mailboxes) . '+wlsession.example.com',
            str_contains($function, 'Session/create_webmail_session_for_') => array_filter([
                'hostname' => $this->hostname, 'session' => 'PRIVATEWEBMAILSESSION' . $session,
                'token' => '/cpsessPRIVATE' . $session,
            ], static fn (mixed $value): bool => $value !== self::ABSENT),
            str_contains($function, 'ActiveSessions/list_active_sessions') => $this->listSessions
                ? array_map(static fn (array $created): array => [
                    'ip_address' => '192.0.2.10', 'is_current' => 0, 'is_possessed' => 0,
                    'last_active' => 1790000000, 'login_theme' => 'private-theme', 'tfa_verified' => 0,
                ], array_reverse($this->sessions)) : [],
            default => null,
        };
    }

    private static function uapi(string $payload, bool $bridge): ResponseInterface
    {
        $envelope = '{"status":1,"data":' . $payload . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}';

        return new Response(200, [], $bridge ? '{"metadata":{"result":1},"data":{"uapi":' . $envelope . '}}'
            : $envelope);
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
