<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Confine the finite-quota fixture to one explicit disposable account and one temporary token.
 *
 * Every request requires both opt-ins and the exact account/domain environment values. Canonical
 * URI/body/header comparisons reject extra or duplicate parameters, alias target fields, control-token
 * revocation and other accounts before I/O. Direct Quota reads require the token returned by setup.
 * Each mutation is consumed once, even on rejection, so nothing below this guard can retry one.
 */
final class FiniteQuotaGuard implements ClientInterface
{
    public const ACCOUNT = 'wqfinite';

    public const DOMAIN = 'wqfinite.example.com';

    public const TOKEN_NAME = 'wqfinite-quota-readonly';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const DIRECT = 'https://test.oblak.host:2083/execute/Quota/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wqfinite&cpanel.module=';

    private ?string $cpanelToken = null;

    /** @var array<string, string> */
    private readonly array $posts;

    /** @var list<string> */
    private readonly array $reads;

    /** @var array<string, true> */
    private array $consumed = [];

    /** Bind the root control credential and this recipe's one account; construction sends nothing. */
    public function __construct(
        #[SensitiveParameter]
        private readonly ClientInterface $client,
        #[SensitiveParameter]
        private readonly string $whmToken,
        #[SensitiveParameter]
        private readonly DisposableAccount $account,
    ) {
        if (
            $whmToken === '' || $account->user !== self::ACCOUNT || $account->domain !== self::DOMAIN
            || $account->tokenName !== self::TOKEN_NAME || !$account->readOnlyToken
        ) {
            throw new LogicException('Finite quota fixture configuration is invalid.');
        }
        $this->posts = $account->controlPosts();
        $this->reads = [
            ...$account->controlReads(),
            self::WHM . 'version?api.version=1',
            self::BRIDGE . 'Quota&cpanel.function=get_quota_info',
            self::BRIDGE . 'Quota&cpanel.function=get_local_quota_info',
            self::DIRECT . 'get_quota_info',
            self::DIRECT . 'get_local_quota_info',
        ];
    }

    /** Bind the generated credential once, without admitting whitespace or header control characters. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('Finite quota fixture token is unusable.');
        }
        $this->cpanelToken = $token;
    }

    /** Permit only the exact fixture mutations, each once, identity reads and five finite-evidence requests. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->account->requireLiveOptIn();
        $uri = (string) $request->getUri();
        $post = $request->getMethod() === 'POST';
        $direct = str_starts_with($uri, self::DIRECT);
        $approved = $request->getMethod() === 'GET' && (string) $request->getBody() === ''
            && in_array($uri, $this->reads, true);
        if ($post) {
            $approved = isset($this->posts[$uri]) && (string) $request->getBody() === $this->posts[$uri];
            if ($approved && isset($this->consumed[$uri])) {
                throw new LogicException('Request outside the finite quota fixture boundary.');
            }
            if ($approved) {
                $this->consumed[$uri] = true;
            }
        }
        $headers = [
            'host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct
                ? 'cpanel wqfinite:' . ($this->cpanelToken ?? '') : 'whm root:' . $this->whmToken],
            'accept' => ['application/json'],
        ];
        if ($post) {
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        if (!$approved || ($direct && $this->cpanelToken === null) || $actual !== $headers) {
            throw new LogicException('Request outside the finite quota fixture boundary.');
        }

        return $this->client->sendRequest($request);
    }
}
