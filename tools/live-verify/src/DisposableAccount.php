<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * The exact identity of one disposable account and its token, for one run on the test server.
 *
 * It holds the per-run contact-email marker that alone proves an account is this run's, so it stays in memory and is
 * never printed or persisted. Start a recipe from fresh(), which draws the marker. A recipe's guard admits exactly
 * controlPosts() and controlReads(), plus its own requests. Those strings are spelled here independently of the
 * client's encoder, so the guard keeps comparing two representations of each request.
 */
final readonly class DisposableAccount
{
    /** The lifecycle's token outlives a run only by this much at most; cleanup still revokes it. */
    public const MAXIMUM_TOKEN_LIFETIME = 3600;

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    /** Bind exact identities; each is validated, so nothing unsafe can reach a request. Performs no I/O. */
    public function __construct(
        public string $user,
        public string $domain,
        public string $tokenName,
        #[SensitiveParameter]
        public string $contactEmail,
        public int $tokenExpiresAt,
        public bool $readOnlyToken,
    ) {
        $marker = '/\A[a-z]+-[0-9a-f]{32}@' . preg_quote($domain, '/') . '\z/';
        if (
            preg_match('/\A[a-z][a-z0-9]{0,15}\z/', $user) !== 1
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+\z/', $domain) !== 1
            || preg_match('/\A[a-z0-9_-]{1,64}\z/', $tokenName) !== 1
            || preg_match($marker, $contactEmail) !== 1 || $tokenExpiresAt < 1
        ) {
            throw new InvalidArgumentException('Disposable account binding is invalid.');
        }
    }

    /** Bind one run's identities with a new 128-bit marker and a token that expires after the given seconds. */
    public static function fresh(
        string $user,
        string $domain,
        string $tokenName,
        int $tokenLifetime,
        bool $readOnlyToken,
    ): self {
        if ($tokenLifetime < 1 || $tokenLifetime > self::MAXIMUM_TOKEN_LIFETIME) {
            throw new InvalidArgumentException('Disposable account tokens must be short-lived.');
        }

        return new self(
            $user,
            $domain,
            $tokenName,
            'fixture-' . bin2hex(random_bytes(16)) . '@' . $domain,
            time() + $tokenLifetime,
            $readOnlyToken,
        );
    }

    /** Require both live opt-ins and this exact target, without exposing supplied environment values. */
    public function requireLiveOptIn(): void
    {
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== $this->user || getenv('WHM_API_LIVE_DOMAIN') !== $this->domain
        ) {
            throw new LogicException('Disposable account requires both opt-ins and its designated account and domain.');
        }
    }

    /**
     * Every mutation the lifecycle may send, as exact form body by exact URI.
     *
     * @return array<string, string>
     */
    public function controlPosts(): array
    {
        return [
            self::WHM . 'createacct?api.version=1' => 'username=' . $this->user . '&domain=' . $this->domain
                . '&contactemail=' . rawurlencode($this->contactEmail) . '&quota=64&forcedns=0&hasshell=0&ip=n',
            self::WHM . 'removeacct?api.version=1' => 'username=' . $this->user . '&keepdns=0',
            $this->tokenFunction('create_full_access') => 'name=' . $this->tokenName
                . '&expires_at=' . $this->tokenExpiresAt . ($this->readOnlyToken ? '&readonly=1' : ''),
            $this->tokenFunction('revoke') => 'name=' . $this->tokenName,
        ];
    }

    /**
     * Every identity read the lifecycle may send, as exact URIs.
     *
     * @return list<string>
     */
    public function controlReads(): array
    {
        return [
            self::WHM . 'listaccts?api.version=1&want=user%2Cdomain%2Cemail',
            self::WHM . 'listzones?api.version=1',
            self::WHM . 'get_domain_info?api.version=1',
            $this->tokenFunction('list'),
        ];
    }

    private function tokenFunction(string $function): string
    {
        return self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=' . $this->user . '&cpanel.module=Tokens'
            . '&cpanel.function=' . $function;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The binding holds the run's ownership marker and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Disposable account binding cannot be serialized.');
    }
}
