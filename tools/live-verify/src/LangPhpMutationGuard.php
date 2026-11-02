<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Confine the LangPHP mutation fixture to one disposable account, one temporary token and the recipe's exact requests.
 *
 * Every request requires both opt-ins and the exact account and domain, then an exact method, URI, header set and body.
 * Reads come only from the recipe's fixed list. Each LangPHP mutation needs a prior single-use authorization naming its
 * phase, and the body it must carry is spelled by the recipe, not by the client's encoder; the phase is consumed even
 * when the request is refused or never arrives, so nothing below this guard can retry one. The binding's
 * ControlPostAdmission admits each control mutation once, and only while no LangPHP mutation is pending. Failure text
 * carries no request data.
 */
final class LangPhpMutationGuard implements ClientInterface
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const DIRECT = 'https://test.oblak.host:2083/';

    private ?string $cpanelToken = null;

    /** @var array{uri: string, body: string}|null */
    private ?array $pending = null;

    /** @var array<string, true> */
    private array $consumed = [];

    private readonly ControlPostAdmission $controlPosts;

    /** @var array<string, true> */
    private readonly array $reads;

    /** Bind the root control credential and the run's recipe; construction sends nothing. */
    public function __construct(
        #[SensitiveParameter]
        private readonly ClientInterface $http,
        #[SensitiveParameter]
        private readonly string $whmToken,
        #[SensitiveParameter]
        private readonly LangPhpMutationRecipe $recipe,
    ) {
        if ($whmToken === '') {
            throw new LogicException('LangPHP mutation fixture configuration is invalid.');
        }
        $this->controlPosts = $recipe->account->controlPostAdmission();
        $reads = [self::WHM . 'version?api.version=1' => true];
        foreach ([...$recipe->account->controlReads(), ...LangPhpMutationRecipe::readUris()] as $uri) {
            $reads[$uri] = true;
        }
        $this->reads = $reads;
    }

    /** Bind the generated credential once, without admitting whitespace or header control characters. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('LangPHP mutation fixture token is unusable.');
        }
        $this->cpanelToken = $token;
    }

    /**
     * Authorize the next request as this mutation phase, which can never be authorized again.
     *
     * A version write names the one version it may send; every other phase takes none.
     */
    public function authorize(string $phase, ?string $version = null): void
    {
        if ($this->pending !== null || isset($this->consumed[$phase])) {
            throw self::refused();
        }
        $this->consumed[$phase] = true;
        $this->pending = $this->recipe->mutation($phase, $version);
    }

    /** Forget an authorization whose request never arrived; its phase stays consumed. */
    public function clearPending(): void
    {
        $this->pending = null;
    }

    /** Permit only this fixture's exact requests, each mutation at most once. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $pending = $this->pending;
        $this->pending = null;
        $this->recipe->account->requireLiveOptIn();
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $direct = str_starts_with($uri, self::DIRECT);
        $method = $request->getMethod();
        if ($method === 'GET') {
            $allowed = $pending === null && $body === '' && isset($this->reads[$uri]);
            $this->requireHeaders($request, $allowed, $direct, null);

            return $this->http->sendRequest($request);
        }
        if ($method !== 'POST') {
            throw self::refused();
        }
        $allowed = $pending === null
            ? !$direct && $this->controlPosts->admit($uri, $body)
            : $uri === $pending['uri'] && $body === $pending['body'];
        $this->requireHeaders($request, $allowed, $direct, 'application/x-www-form-urlencoded');

        return $this->http->sendRequest($request);
    }

    /** Compare the complete header set exactly, so nothing unreviewed reaches the transport. */
    private function requireHeaders(
        #[SensitiveParameter] RequestInterface $request,
        bool $allowed,
        bool $direct,
        ?string $contentType,
    ): void {
        if ($direct && $this->cpanelToken === null) {
            throw self::refused();
        }
        $headers = [
            'host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct
                ? 'cpanel ' . LangPhpMutationRecipe::ACCOUNT . ':' . $this->cpanelToken
                : 'whm root:' . $this->whmToken],
            'accept' => ['application/json'],
        ];
        if ($contentType !== null) {
            $headers['content-type'] = [$contentType];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        if (!$allowed || $actual !== $headers) {
            throw self::refused();
        }
    }

    private static function refused(): LogicException
    {
        return new LogicException('Request outside the LangPHP mutation fixture boundary.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The guard holds credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('LangPHP mutation guard cannot be serialized.');
    }
}
