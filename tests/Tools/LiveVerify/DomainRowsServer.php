<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsRecipe;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Independent account, token, domain and zone double for the domain-rows fixture.
 *
 * Other accounts, domains and zones always exist. Creating a domain needs the account's raised limits and its live
 * token; an addon makes its own subdomain and zone, an alias its zone; account removal takes all of them unless told
 * to retain one. DNS reads answer in request order unless `sortedDns` says otherwise. Failures fire `_before` or
 * `_after` a request's real effect: `throw` loses the response, `api` is an explicit refusal, `http` a 502 gateway
 * error and `invalid` an unreadable 200 body.
 */
final class DomainRowsServer implements ClientInterface
{
    public const TOKEN = 'PRIVATEDOMAINROWSTOKEN';

    private const M = DomainRowsRecipe::DOMAIN;

    private const A = DomainRowsRecipe::ADDON;

    public bool $account = false;

    public bool $limits = false;

    /** @var array<string, string> Extra domain name to owner. */
    public array $domains = [];

    /** @var list<string> Extra zones. */
    public array $zones = [];

    /** @var list<string> Extra names removal leaves behind. */
    public array $retain = [];

    /** Answer named DNS rows in sorted order instead of request order. */
    public bool $sortedDns = false;

    /** Return only one result to every DNS read. */
    public bool $oneDnsResult = false;

    /** Whether single_domain_data refuses an alias, as aliases have no user-data file. */
    public bool $refuseAlias = true;

    /** @var array<string, string> Replacement domain a single_domain_data read reports, by requested domain. */
    public array $singleDomain = [];

    /** @var list<string> */
    public array $tokens = [];

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

    public function __construct()
    {
        $this->refuseAlias = in_array('direct_single_alias', DomainRowsRecipe::expectedRefusals(), true);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $path = $request->getUri()->getPath();
        $function = basename($path);
        $bridge = $function === 'uapi_cpanel';
        $direct = str_starts_with($path, '/execute/');
        if ($bridge) {
            if (($query['cpanel_user'] ?? null) !== DomainRowsRecipe::ACCOUNT) {
                throw new LogicException('Synthetic bridge request names another account.');
            }
            $module = $query['cpanel_module'] ?? null;
            $operation = $query['cpanel_function'] ?? null;
            if (!is_string($module) || !is_string($operation)) {
                throw new LogicException('Synthetic bridge request is malformed.');
            }
            $function = $module . '/' . $operation;
        } elseif ($direct) {
            $function = 'direct:' . basename(dirname($path)) . '/' . $function;
            if (
                $this->tokens === []
                || $request->getHeaderLine('Authorization') !== 'cpanel wldomains:' . self::TOKEN
            ) {
                throw new LogicException('Synthetic direct request without a live temporary token.');
            }
        }
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;
        $step = $function . ':' . $this->counts[$function];
        $failure = $this->failures[$step] ?? '';
        if (str_ends_with($failure, '_before')) {
            return self::fail(substr($failure, 0, -7), $bridge, $direct);
        }
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
        }
        $parameters = [];
        parse_str((string) $request->getBody(), $parameters);
        $refused = $this->apply($function, $parameters);
        if ($this->afterEffect !== null) {
            ($this->afterEffect)($this, $step);
        }
        if (str_ends_with($failure, '_after')) {
            return self::fail(substr($failure, 0, -6), $bridge, $direct);
        }
        if ($refused) {
            return self::fail('api', $bridge, $direct);
        }
        $domains = [];
        foreach (explode('&', $request->getUri()->getQuery()) as $pair) {
            if (str_starts_with($pair, 'domain=')) {
                $domains[] = urldecode(substr($pair, 7));
            }
        }
        $data = $this->payload($function, $domains, $query);
        if ($data === false) {
            return self::fail('api', $bridge, $direct);
        }
        $payload = $this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR);

        return self::success($payload, $bridge, $direct);
    }

    /**
     * Apply one request's effect; true when the server refuses it.
     *
     * @param array<mixed> $parameters
     */
    private function apply(string $function, array $parameters): bool
    {
        switch ($function) {
            case 'createacct':
                $email = $parameters['contactemail'] ?? null;
                if (!is_string($email)) {
                    throw new LogicException('Synthetic account ownership is malformed.');
                }
                $this->account = true;
                $this->contactEmail = $email;
                break;
            case 'Tokens/create_full_access':
                $expires = $parameters['expires_at'] ?? null;
                if (!is_string($expires)) {
                    throw new LogicException('Synthetic token expiration is missing.');
                }
                $this->expiresAt = (int) $expires;
                $this->tokens[] = DomainRowsRecipe::TOKEN_NAME;
                break;
            case 'Tokens/revoke':
                $this->tokens = [];
                break;
            case 'removeacct':
                $this->account = false;
                $this->tokens = [];
                $this->limits = false;
                $this->domains = array_filter($this->domains, fn (string $owner, string $name): bool =>
                    in_array($name, $this->retain, true), ARRAY_FILTER_USE_BOTH);
                $this->zones = array_values(array_filter($this->zones, fn (string $zone): bool =>
                    in_array($zone, $this->retain, true)));
                break;
            case 'modifyacct':
                $this->limits = $this->account && ($parameters['user'] ?? null) === DomainRowsRecipe::ACCOUNT;
                break;
            case 'direct:AddonDomain/addaddondomain':
                if (!$this->limits) {
                    return true;
                }
                $this->domains[DomainRowsRecipe::ADDON] = DomainRowsRecipe::ACCOUNT;
                $this->domains[DomainRowsRecipe::ADDON_SUBDOMAIN] = DomainRowsRecipe::ACCOUNT;
                $this->zones[] = DomainRowsRecipe::ADDON;
                break;
            case 'direct:Park/park':
                if (!$this->limits) {
                    return true;
                }
                $this->domains[DomainRowsRecipe::ALIAS] = DomainRowsRecipe::ACCOUNT;
                $this->zones[] = DomainRowsRecipe::ALIAS;
                break;
            case 'direct:SubDomain/addsubdomain':
                if (!$this->limits) {
                    return true;
                }
                $this->domains[DomainRowsRecipe::SUBDOMAIN] = DomainRowsRecipe::ACCOUNT;
                break;
        }

        return false;
    }

    /**
     * @param list<string> $domains
     * @param array<mixed> $query
     */
    private function payload(string $function, array $domains, array $query): mixed
    {
        $named = $this->sortedDns ? self::sorted($domains) : $domains;
        if ($this->oneDnsResult) {
            $named = array_slice($named, 0, 1);
        }
        $authority = array_map(static fn (string $domain): array => [
            'domain' => $domain, 'error' => null, 'local_authority' => 1, 'nameservers' => ['ns1.example.net'],
            'zone' => $domain,
        ], $named);
        $module = str_starts_with($function, 'direct:') ? substr($function, 7) : $function;

        return match ($module) {
            'version' => ['version' => '11.138.0.19'],
            'listaccts' => ['acct' => array_merge(
                [['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com']],
                $this->account ? [['user' => DomainRowsRecipe::ACCOUNT, 'domain' => self::M,
                    'email' => $this->contactEmail]] : [],
            )],
            'listzones' => ['zone' => array_map(static fn (string $zone): array => ['domain' => $zone,
                'zonefile' => 'private.db'], ['existing.example', ...($this->account ? [self::M] : []),
                ...$this->zones])],
            'get_domain_info' => ['domains' => array_map(
                static fn (string $domain, string $user): array => ['domain' => $domain, 'user' => $user],
                ['existing.example', ...($this->account ? [self::M] : []), ...array_keys($this->domains)],
                ['existing', ...($this->account ? [DomainRowsRecipe::ACCOUNT] : []), ...array_values($this->domains)],
            )],
            'Tokens/list' => array_map(fn (string $name): array => ['name' => $name,
                'expires_at' => (string) $this->expiresAt], $this->tokens),
            'Tokens/create_full_access' => ['token' => self::TOKEN, 'create_time' => 1790000000],
            'Tokens/revoke' => 1,
            'modifyacct' => ['cpuser' => ['MAXADDON' => '1'], 'domain' => self::M, 'setshell' => 'no',
                'user' => DomainRowsRecipe::ACCOUNT],
            'AddonDomain/addaddondomain' => ['domain' => DomainRowsRecipe::ADDON],
            'Park/park', 'SubDomain/addsubdomain' => null,
            'has_local_authority' => ['records' => $authority],
            'DNS/has_local_authority' => $authority,
            'fetch_ds_records_for_domains' => ['domains' => array_map(static fn (string $domain): array =>
                ['domain' => $domain, 'ds_records' => []], $named)],
            'DNS/ensure_domains_reside_only_locally' => array_map(static fn (): ?string => null, $named),
            'DNS/ensure_domains_use_recognized_nameservers' => array_map(static fn (): array =>
                ['message' => 'private upstream text', 'type' => 'unrecognized_nameservers'], $named),
            'DomainInfo/list_domains' => ['main_domain' => self::M, 'addon_domains' => $this->owned([self::A]),
                'parked_domains' => $this->owned([DomainRowsRecipe::ALIAS]),
                'sub_domains' => $this->owned([DomainRowsRecipe::ADDON_SUBDOMAIN, DomainRowsRecipe::SUBDOMAIN])],
            'DomainInfo/domains_data' => ($query['format'] ?? null) === 'list'
                ? array_map(self::row(...), [self::M, ...$this->owned([self::A, DomainRowsRecipe::ALIAS,
                    DomainRowsRecipe::ADDON_SUBDOMAIN, DomainRowsRecipe::SUBDOMAIN])])
                : ['main_domain' => self::row(self::M),
                    'addon_domains' => array_map(self::row(...), $this->owned([self::A])),
                    'parked_domains' => $this->owned([DomainRowsRecipe::ALIAS]),
                    'sub_domains' => array_map(self::row(...), $this->owned([DomainRowsRecipe::ADDON_SUBDOMAIN,
                        DomainRowsRecipe::SUBDOMAIN]))],
            'DomainInfo/single_domain_data' => $this->single(
                is_string($query['domain'] ?? null) ? $query['domain'] : '',
            ),
            default => null,
        };
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function owned(array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => isset($this->domains[$name])));
    }

    /** @return array<string, mixed>|false */
    private function single(string $domain): array|false
    {
        if ($domain === DomainRowsRecipe::ALIAS && $this->refuseAlias) {
            return false;
        }
        if ($domain !== self::M && !isset($this->domains[$domain])) {
            return false;
        }

        return self::row($this->singleDomain[$domain] ?? $domain);
    }

    /** @return array<string, mixed> */
    private static function row(string $domain): array
    {
        return [
            'domain' => $domain, 'documentroot' => '/home/wldomains/private', 'homedir' => '/home/wldomains',
            'user' => DomainRowsRecipe::ACCOUNT, 'type' => 'private_kind', 'hascgi' => '1', 'port' => '80',
            'userdirprotect' => '',
        ];
    }

    /**
     * @param list<string> $domains
     * @return list<string>
     */
    private static function sorted(array $domains): array
    {
        sort($domains);

        return $domains;
    }

    private static function success(string $payload, bool $bridge, bool $direct): ResponseInterface
    {
        if ($direct) {
            return new Response(200, [], '{"status":1,"data":' . $payload
                . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}');
        }

        return new Response(200, [], $bridge
            ? '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":' . $payload . ',"errors":null}}}'
            : '{"metadata":{"result":1,"reason":"private upstream text"},"data":' . $payload . '}');
    }

    private static function fail(string $kind, bool $bridge, bool $direct): ResponseInterface
    {
        return match ($kind) {
            'throw' => throw new class ('private transport failure') extends RuntimeException implements
                ClientExceptionInterface
            {
            },
            'api' => new Response(
                200,
                [], match (true) {
                $direct => '{"status":0,"data":null,"errors":["private upstream text"],"messages":null,'
                    . '"warnings":null,"metadata":{}}',
                $bridge => '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":null,'
                    . '"errors":["private upstream text"]}}}',
                default => '{"metadata":{"result":0,"reason":"private upstream text"},"data":null}',
                },
            ),
            'http' => new Response(502, [], 'private upstream gateway text'),
            'invalid' => new Response(200, [], 'private unreadable upstream text'),
            default => throw new LogicException('Unknown synthetic failure.'),
        };
    }
}
