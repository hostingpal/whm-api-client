<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use WeakMap;

/**
 * The exact identity of one disposable account and its token, for one run on the test server.
 *
 * It holds the per-run contact-email marker that alone proves an account is this run's, so it stays in memory and is
 * never printed or persisted. The marker is no property: it lives in a private map keyed by the binding, so no JSON
 * encoding, var_export, array cast, print_r or var_dump of a binding shows it, and a binding cannot be cloned. Only
 * the requests that must carry it reveal it: createAccountParameters() for the lifecycle's one createacct call and
 * controlPosts() for the guard's comparison. Anything else that needs it asks isContactMarker().
 *
 * Start a recipe from fresh(), which draws the marker. A recipe's guard admits exactly
 * controlPosts(), each at most once through controlPostAdmission(), and controlReads(), plus its own requests. Those
 * strings are spelled here independently of the client's encoder, so the guard keeps comparing two representations of
 * each request.
 */
final class DisposableAccount
{
    /** A token outlives a lost run by this much at most; cleanup still revokes it. */
    public const MAXIMUM_TOKEN_LIFETIME = 3600;

    /** A cPanel account name, narrow enough to reach a remote shell as a plain word. */
    public const USER_PATTERN = '/\A[a-z][a-z0-9]{0,15}\z/';

    /** A lowercase host name of at least two labels, equally free of shell metacharacters. */
    public const DOMAIN_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+\z/';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    /** @var WeakMap<self, string>|null Each binding's marker, kept off the binding itself. */
    private static ?WeakMap $markers = null;

    /**
     * Bind exact identities; each is validated, so nothing unsafe can reach a request. Performs no I/O.
     *
     * A recipe should use fresh(), which also draws the marker. The token bound holds here, for every binding.
     */
    public function __construct(
        public readonly string $user,
        public readonly string $domain,
        public readonly string $tokenName,
        #[SensitiveParameter]
        string $contactEmail,
        public readonly int $tokenExpiresAt,
        public readonly bool $readOnlyToken,
    ) {
        $marker = '/\A[a-z]+-[0-9a-f]{32}@' . preg_quote($domain, '/') . '\z/';
        $now = time();
        if (
            preg_match(self::USER_PATTERN, $user) !== 1 || preg_match(self::DOMAIN_PATTERN, $domain) !== 1
            || preg_match('/\A[a-z0-9_-]{1,64}\z/', $tokenName) !== 1 || preg_match($marker, $contactEmail) !== 1
            || $tokenExpiresAt <= $now || $tokenExpiresAt > $now + self::MAXIMUM_TOKEN_LIFETIME
        ) {
            throw new InvalidArgumentException('Disposable account binding is invalid.');
        }
        self::$markers ??= new WeakMap();
        self::$markers[$this] = $contactEmail;
    }

    /**
     * Bind one run's identities with a new 128-bit marker and a token that expires after the given seconds.
     *
     * @throws InvalidArgumentException For an unsafe identity, or a lifetime outside 1 to MAXIMUM_TOKEN_LIFETIME.
     */
    public static function fresh(
        string $user,
        string $domain,
        string $tokenName,
        int $tokenLifetime,
        bool $readOnlyToken,
    ): self {
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

    /** Whether $email is exactly this run's marker; anything else, another run's marker included, is not. */
    public function isContactMarker(#[SensitiveParameter] mixed $email): bool
    {
        return is_string($email) && hash_equals($this->marker(), $email);
    }

    /**
     * The one createacct call's parameters. They carry the marker: send them, never keep or print them.
     *
     * @return array{
     *     username: string, domain: string, contactemail: string, quota: int, forcedns: int, hasshell: int, ip: string
     * }
     */
    public function createAccountParameters(): array
    {
        return [
            'username' => $this->user, 'domain' => $this->domain, 'contactemail' => $this->marker(),
            'quota' => 64, 'forcedns' => 0, 'hasshell' => 0, 'ip' => 'n',
        ];
    }

    /**
     * Every mutation the lifecycle may send, as exact form body by exact URI.
     *
     * The createacct body carries the marker, so a guard admits these through controlPostAdmission() and keeps no copy.
     *
     * @return array<string, string>
     */
    public function controlPosts(): array
    {
        return [
            self::WHM . 'createacct?api.version=1' => 'username=' . $this->user . '&domain=' . $this->domain
                . '&contactemail=' . rawurlencode($this->marker()) . '&quota=64&forcedns=0&hasshell=0&ip=n',
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

    /** Admit this binding's control mutations, each at most once, for one guard; every guard asks its own. */
    public function controlPostAdmission(): ControlPostAdmission
    {
        return new ControlPostAdmission($this);
    }

    private function marker(): string
    {
        return self::$markers[$this] ?? throw new LogicException('Disposable account binding has no marker.');
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

    /** A copy would carry no marker, so a binding is never cloned. */
    private function __clone(): void
    {
    }

    /** The binding holds the run's ownership marker and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Disposable account binding cannot be serialized.');
    }
}
