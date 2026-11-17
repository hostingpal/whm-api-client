<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Confine the domain-rows recipe to one disposable account, one temporary token and the recipe's exact requests.
 *
 * Every request requires both opt-ins and the exact account and domain, then an exact method, URI, header set and
 * body. Reads come from the binding's control reads and the recipe's own exact URIs, and may repeat. Each mutation is
 * consumed once, even when this guard refuses it, so nothing below it can retry one: the binding's
 * ControlPostAdmission admits each control mutation, and this guard consumes the recipe's limits and its three domain
 * creations, which only the account's own token can send. Failure text carries no request data.
 */
final class DomainRowsGuard implements ClientInterface
{
    private const DIRECT = 'https://test.oblak.host:2083/';

    private ?string $cpanelToken = null;

    private readonly ControlPostAdmission $controlPosts;

    /** @var array<string, true> */
    private readonly array $reads;

    /** @var array<string, string> */
    private readonly array $mutations;

    /** @var array<string, true> */
    private array $consumed = [];

    /** Bind the root control credential and the run's recipe; construction sends nothing. */
    public function __construct(
        #[SensitiveParameter]
        private readonly ClientInterface $http,
        #[SensitiveParameter]
        private readonly string $whmToken,
        #[SensitiveParameter]
        private readonly DomainRowsRecipe $recipe,
    ) {
        if ($whmToken === '') {
            throw new LogicException('Domain rows fixture configuration is invalid.');
        }
        $this->controlPosts = $recipe->account->controlPostAdmission();
        $this->reads = array_fill_keys([...$recipe->account->controlReads(), ...DomainRowsRecipe::readUris()], true);
        $this->mutations = DomainRowsRecipe::mutations();
    }

    /** Bind the generated credential once, without admitting whitespace or header control characters. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('Domain rows fixture token is unusable.');
        }
        $this->cpanelToken = $token;
    }

    /** Permit only this recipe's exact requests, each mutation at most once. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->recipe->account->requireLiveOptIn();
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $direct = str_starts_with($uri, self::DIRECT);
        $post = $request->getMethod() === 'POST';
        if ($post) {
            $allowed = isset($this->mutations[$uri])
                ? $this->consume($uri) && $this->mutations[$uri] === $body
                : $this->controlPosts->admit($uri, $body);
        } else {
            $allowed = $request->getMethod() === 'GET' && $body === '' && isset($this->reads[$uri]);
        }
        $headers = [
            'host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct
                ? 'cpanel ' . DomainRowsRecipe::ACCOUNT . ':' . $this->cpanelToken : 'whm root:' . $this->whmToken],
            'accept' => ['application/json'],
        ];
        if ($post) {
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        if (!$allowed || ($direct && $this->cpanelToken === null) || $actual !== $headers) {
            throw new LogicException('Request outside the domain rows fixture boundary.');
        }

        return $this->http->sendRequest($request);
    }

    /** Spend one recipe mutation; true only the first time, whatever happens to the request afterwards. */
    private function consume(string $uri): bool
    {
        if (isset($this->consumed[$uri])) {
            return false;
        }
        $this->consumed[$uri] = true;

        return true;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The guard holds credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Domain rows guard cannot be serialized.');
    }
}
