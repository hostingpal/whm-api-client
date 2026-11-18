<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Admit the ordered DNS exercise for one disposable account and its two synthetic zones.
 *
 * arm() binds only the current SOA serial and the line index selected by the exercise's fresh
 * identity check. Methods, URIs, record bytes, domain order and destination addresses are fixed
 * here independently of the generated client. A failed attempt consumes the phase before I/O.
 * The lifecycle retains its own exact, single-use control writes so teardown can always proceed.
 */
final class DnsZoneGuard implements ClientInterface
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';
    private const DIRECT = 'https://test.oblak.host:2083/execute/';
    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=' . DnsZoneFixture::ACCOUNT
        . '&cpanel.module=DNS&cpanel.function=';

    private const PHASES = [
        'limits', 'park_alias',
        'direct-mass-before', 'direct-mass-add', 'direct-mass-added', 'direct-mass-edit', 'direct-mass-edited',
        'direct-mass-remove', 'direct-mass-removed',
        'bridged-mass-before', 'bridged-mass-add', 'bridged-mass-added', 'bridged-mass-edit', 'bridged-mass-edited',
        'bridged-mass-remove', 'bridged-mass-removed',
        'direct-seed-main-before', 'direct-seed-main', 'direct-seed-main-after',
        'direct-seed-alias-before', 'direct-seed-alias', 'direct-seed-alias-after',
        'bridged-swap-main-before', 'bridged-swap-alias-before', 'bridged-swap',
        'bridged-swap-main-after', 'bridged-swap-alias-after',
        'direct-swap-main-before', 'direct-swap-alias-before', 'direct-swap', 'direct-swap-main-after',
        'direct-swap-alias-after',
    ];

    private readonly ControlPostAdmission $controlPosts;

    /** @var array<string, true> */
    private readonly array $reads;

    private ?string $cpanelToken = null;
    private int $next = 0;

    /** @var array{string, string, string}|null Method, URI and form body for the current phase. */
    private ?array $expected = null;

    /** Bind only this fixture's writable disposable account. Construction performs no requests. */
    public function __construct(
        #[SensitiveParameter] private readonly ClientInterface $http,
        #[SensitiveParameter] private readonly string $whmToken,
        #[SensitiveParameter] private readonly DisposableAccount $account,
    ) {
        if (
            $account->user !== DnsZoneFixture::ACCOUNT || $account->domain !== DnsZoneFixture::DOMAIN
            || $account->tokenName !== DnsZoneFixture::TOKEN_NAME || $account->readOnlyToken
            || $whmToken === '' || preg_match('/[\x00-\x20\x7f]/', $whmToken) === 1
        ) {
            throw new LogicException('Invalid DNS fixture binding.');
        }
        $this->controlPosts = $account->controlPostAdmission();
        $this->reads = array_fill_keys([...$account->controlReads(), self::WHM . 'version?api.version=1'], true);
    }

    /** Bind the newly issued account token once without exposing it in errors or traces. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('Invalid DNS fixture token.');
        }
        $this->cpanelToken = $token;
    }

    /** Authorize one phase, with only that phase's dynamic serial and index from the verified zone read. */
    public function arm(string $phase, ?int $serial = null, ?int $editIndex = null, ?int $removeIndex = null): void
    {
        if ($this->expected !== null || (self::PHASES[$this->next] ?? null) !== $phase) {
            throw new LogicException('DNS fixture phase is out of order.');
        }
        $add = str_ends_with($phase, '-mass-add');
        $edit = str_ends_with($phase, '-mass-edit');
        $remove = str_ends_with($phase, '-mass-remove');
        $seed = $phase === 'direct-seed-main' || $phase === 'direct-seed-alias';
        if (
            ($add || $edit || $remove || $seed) !== ($serial !== null)
            || ($serial !== null && ($serial < 0 || $serial > 4294967295))
            || ($edit || $seed) !== ($editIndex !== null) || ($editIndex !== null && $editIndex < 0)
            || $remove !== ($removeIndex !== null) || ($removeIndex !== null && $removeIndex < 0)
        ) {
            throw new LogicException('Invalid DNS fixture phase parameters.');
        }

        $domain = DnsZoneFixture::DOMAIN;
        $alias = DnsZoneFixture::ALIAS;
        $direct = str_starts_with($phase, 'direct-');
        $dns = $direct ? self::DIRECT . 'DNS/' : self::BRIDGE;
        if ($seed && $serial !== null && $editIndex !== null) {
            $zone = $phase === 'direct-seed-alias' ? $alias : $domain;
            $body = 'serial=' . $serial . '&zone=' . $zone
                . '&add=' . self::address('source-one.' . $zone, '192.0.2.61')
                . '&add=' . self::address('source-two.' . $zone, '192.0.2.62')
                . '&edit=' . self::address($zone, $zone === $alias ? '192.0.2.62' : '192.0.2.61', $editIndex);
            $this->expected = ['POST', $dns . 'mass_edit_zone', $body];
        } elseif ($serial !== null) {
            $prefix = 'serial=' . $serial . '&zone=' . $domain;
            $body = match (true) {
                $add => $prefix . '&add=' . self::address('exercise.' . $domain, '192.0.2.51'),
                $edit => $prefix . '&edit=' . self::address('exercise.' . $domain, '192.0.2.52', $editIndex),
                default => $prefix . '&remove=' . $removeIndex,
            };
            $this->expected = ['POST', $dns . 'mass_edit_zone', $body];
        } else {
            $this->expected = match ($phase) {
                'limits' => ['POST', self::WHM . 'modifyacct?api.version=1',
                    'user=' . DnsZoneFixture::ACCOUNT . '&MAXPARK=1'],
                'park_alias' => ['POST', self::DIRECT . 'Park/park', 'domain=' . $alias],
                'bridged-swap', 'direct-swap' => ['POST', $dns . 'swap_ip_in_zones',
                    'domain=' . $domain . '&domain=' . $alias . '&dest_ip=192.0.2.' . ($direct ? '55' : '54')],
                default => ['GET', $dns . 'parse_zone' . ($direct ? '?' : '&') . 'zone='
                    . (str_contains($phase, '-alias-') ? $alias : $domain), ''],
            };
        }
    }

    /** The exact single-address A record bytes the recipe submits in an add or edit field. */
    private static function address(string $label, string $ip, ?int $lineIndex = null): string
    {
        return rawurlencode('{' . ($lineIndex === null ? '' : '"line_index":' . $lineIndex . ',')
            . '"dname":"' . $label . '.","ttl":300,"record_type":"A","data":["' . $ip . '"]}');
    }

    /** Check exact opt-ins, request target, credentials, headers, route and body before forwarding a request. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $read = $method === 'GET' && $body === '' && isset($this->reads[$uri]);
        $control = $method === 'POST' && $this->controlPosts->admit($uri, $body);
        $phase = !$read && !$control;
        $expected = $this->expected;
        if ($control && (str_contains($uri, '/removeacct?') || str_ends_with($uri, '&cpanel.function=revoke'))) {
            $this->expected = null;
            $this->next = count(self::PHASES);
        }
        if ($phase) {
            $this->expected = null;
            // Closing the exercise here spends the attempt even when opt-in, headers or the transport fails.
            $next = $this->next + 1;
            $this->next = count(self::PHASES);
        }
        $this->account->requireLiveOptIn();
        $direct = str_starts_with($uri, self::DIRECT);
        $headers = [
            'host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct ? 'cpanel ' . DnsZoneFixture::ACCOUNT . ':' . $this->cpanelToken
                : 'whm root:' . $this->whmToken],
            'accept' => ['application/json'],
        ];
        if ($method === 'POST') {
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        $target = $request->getUri()->getPath();
        if ($request->getUri()->getQuery() !== '') {
            $target .= '?' . $request->getUri()->getQuery();
        }
        if (
            ($phase && $expected !== [$method, $uri, $body]) || ($direct && $this->cpanelToken === null)
            || $request->getRequestTarget() !== $target || $headers !== $actual
        ) {
            throw new LogicException('Request outside the DNS fixture boundary.');
        }

        $response = $this->http->sendRequest($request);
        if (isset($next)) {
            $this->next = $next;
        }
        return $response;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** This guard holds credentials and a one-shot admission that cannot be copied or persisted. */
    private function __clone(): void
    {
    }

    public function __serialize(): never
    {
        throw new LogicException('DNS fixture guard cannot be serialized.');
    }
}
