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
 * Independent account, domain, zone and token state double for the shared disposable-account lifecycle.
 *
 * It models a real server: other accounts, domains and zones always exist, an account's token needs the account, and
 * removal takes the token and, unless told otherwise, the zone and domain. Failures fire `_before` or `_after` a
 * request's real effect, so a lost response can follow a created account: `throw` loses the response, `api` is an
 * explicit refusal, `http` a 502 gateway error and `invalid` an unreadable 200 body.
 */
final class DisposableAccountServer implements ClientInterface
{
    public const USER = 'wdisposable';

    public const DOMAIN = 'wdisposable.example.com';

    public const TOKEN_NAME = 'wdisposable-fixture';

    public const TOKEN = 'PRIVATEDISPOSABLETOKEN';

    public const FOREIGN_MARKER = 'fixture-00000000000000000000000000000000@wdisposable.example.com';

    public bool $account = false;

    public bool $zone = false;

    public bool $manualDomain = false;

    public bool $retainZone = false;

    public bool $retainDomain = false;

    /** Marker another run's creation leaves behind, in place of the one this run sends. */
    public ?string $concurrentContactEmail = null;

    public string $contactEmail = '';

    /** Primary domain the account inventory shows in place of the real one, with the exact marker kept. */
    public ?string $listedDomain = null;

    /** @var list<string> */
    public array $tokens = [];

    /** @var array<string, mixed> Replacement token listing fields. */
    public array $tokenFields = [];

    public bool $tokenReadOnly = false;

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

    private int $expiresAt = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $function = basename($request->getUri()->getPath());
        $bridge = $function === 'uapi_cpanel';
        if ($bridge) {
            if (!is_string($query['cpanel_module'] ?? null) || !is_string($query['cpanel_function'] ?? null)) {
                throw new LogicException('Synthetic bridge request is malformed.');
            }
            if (($query['cpanel_user'] ?? null) !== self::USER) {
                throw new LogicException('Synthetic bridge request names another account.');
            }
            $function = $query['cpanel_module'] . '/' . $query['cpanel_function'];
        }
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;
        $step = $function . ':' . $this->counts[$function];
        $failure = $this->failures[$step] ?? '';
        if ($function === 'createacct' && $this->concurrentContactEmail !== null) {
            $this->account = true;
            $this->zone = true;
            $this->manualDomain = true;
            $this->contactEmail = $this->concurrentContactEmail;
        }
        if (str_ends_with($failure, '_before')) {
            return self::fail(substr($failure, 0, -7), $bridge);
        }
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
        }
        $parameters = [];
        parse_str((string) $request->getBody(), $parameters);
        $this->apply($function, $parameters);
        if ($this->afterEffect !== null) {
            ($this->afterEffect)($this, $step);
        }
        if (str_ends_with($failure, '_after')) {
            return self::fail(substr($failure, 0, -6), $bridge);
        }
        $payload = $this->overrides[$step] ?? json_encode($this->payload($function), JSON_THROW_ON_ERROR);

        return self::success($payload, $bridge);
    }

    /** @param array<mixed> $parameters */
    private function apply(string $function, array $parameters): void
    {
        switch ($function) {
            case 'createacct':
                if ($this->concurrentContactEmail !== null) {
                    return;
                }
                $email = $parameters['contactemail'] ?? null;
                if (!is_string($email)) {
                    throw new LogicException('Synthetic account ownership is malformed.');
                }
                $this->account = true;
                $this->zone = true;
                $this->manualDomain = true;
                $this->contactEmail = $email;
                break;
            case 'Tokens/create_full_access':
                if (!is_string($parameters['expires_at'] ?? null)) {
                    throw new LogicException('Synthetic token expiration is missing.');
                }
                $this->expiresAt = (int) $parameters['expires_at'];
                $this->tokenReadOnly = ($parameters['readonly'] ?? null) === '1';
                $this->tokens[] = self::TOKEN_NAME;
                break;
            case 'Tokens/revoke':
                $this->tokens = [];
                break;
            case 'removeacct':
                $this->account = false;
                $this->tokens = [];
                $this->zone = $this->retainZone;
                $this->manualDomain = $this->retainDomain;
                break;
        }
    }

    private function payload(string $function): mixed
    {
        return match ($function) {
            'listaccts' => ['acct' => array_merge([
                ['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com'],
                ['user' => self::USER . 'x', 'domain' => self::DOMAIN . '.other'],
            ], $this->account
                ? [['user' => self::USER, 'domain' => $this->listedDomain ?? self::DOMAIN,
                    'email' => $this->contactEmail]] : [])],
            'listzones' => ['zone' => array_merge(
                [['domain' => 'existing.example', 'zonefile' => 'private.db']],
                $this->zone ? [['domain' => self::DOMAIN, 'zonefile' => 'private.db']] : [],
            )],
            'get_domain_info' => ['domains' => array_merge(
                [['domain' => 'existing.example', 'user' => 'existing']],
                $this->manualDomain ? [['domain' => self::DOMAIN, 'user' => self::USER]] : [],
            )],
            'Tokens/list' => array_map(fn (string $name): array => array_replace(
                array_filter([
                    'name' => $name, 'expires_at' => (string) $this->expiresAt,
                    'readonly' => $this->tokenReadOnly ? 1 : null,
                ], static fn (mixed $field): bool => $field !== null),
                $this->tokenFields,
            ), $this->tokens),
            'Tokens/create_full_access' => ['token' => self::TOKEN, 'create_time' => 1790000000],
            'Tokens/revoke' => 1,
            default => null,
        };
    }

    private static function success(string $payload, bool $bridge): ResponseInterface
    {
        return new Response(200, [], $bridge
            ? '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":' . $payload . ',"errors":null}}}'
            : '{"metadata":{"result":1,"reason":"private upstream text"},"data":' . $payload . '}');
    }

    private static function fail(string $kind, bool $bridge): ResponseInterface
    {
        return match ($kind) {
            'throw' => throw new class ('private transport failure') extends RuntimeException implements
                ClientExceptionInterface
            {
            },
            'api' => new Response(200, [], $bridge
                ? '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":null,'
                    . '"errors":["private upstream text"]}}}'
                : '{"metadata":{"result":0,"reason":"private upstream text"},"data":null}'),
            'http' => new Response(502, [], 'private upstream gateway text'),
            'invalid' => new Response(200, [], 'private unreadable upstream text'),
            default => throw new LogicException('Unknown synthetic failure.'),
        };
    }
}
