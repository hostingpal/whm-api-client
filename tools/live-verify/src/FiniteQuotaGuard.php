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

    /** Bind the root control credential and one exact token expiration; construction sends nothing. */
    public function __construct(
        #[SensitiveParameter]
        private readonly ClientInterface $client,
        #[SensitiveParameter]
        private readonly string $whmToken,
        int $expiresAt,
        #[SensitiveParameter]
        string $contactEmail,
    ) {
        if (
            $whmToken === '' || $expiresAt < 1
            || preg_match('/\Afinite-[0-9a-f]{32}@wqfinite\.example\.com\z/', $contactEmail) !== 1
        ) {
            throw new LogicException('Finite quota fixture configuration is invalid.');
        }
        $account = new DisposableAccount(
            self::ACCOUNT,
            self::DOMAIN,
            self::TOKEN_NAME,
            $contactEmail,
            $expiresAt,
            readOnlyToken: true,
        );
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

    /** Require the explicit designated target without exposing supplied environment values. */
    public static function requireEnvironment(): void
    {
        if (
            getenv('WHM_API_LIVE') !== '1'
            || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== self::ACCOUNT
            || getenv('WHM_API_LIVE_DOMAIN') !== self::DOMAIN
        ) {
            throw new LogicException('Finite quota fixture requires both opt-ins and the designated account/domain.');
        }
    }

    /** Permit only the exact fixture mutations, identity reads and five finite-evidence requests. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        self::requireEnvironment();
        $uri = (string) $request->getUri();
        $post = $request->getMethod() === 'POST';
        $direct = str_starts_with($uri, self::DIRECT);
        $approved = $request->getMethod() === 'GET' && (string) $request->getBody() === ''
            && in_array($uri, $this->reads, true);
        if ($post) {
            $approved = isset($this->posts[$uri]) && (string) $request->getBody() === $this->posts[$uri];
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
