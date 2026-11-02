<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Confine the Webmail session fixture to one disposable account, one temporary token and the recipe's exact requests.
 *
 * Every request requires both opt-ins and the exact account and domain, then an exact method, URI, header set and
 * body. Reads may repeat. Each mutation is admitted at most once: the binding's ControlPostAdmission admits each
 * lifecycle mutation, and this guard admits each recipe mutation the first time its exact request arrives, so nothing
 * below it can retry one. A request that does not match exactly consumes nothing. Only the API ports and the recipe's
 * URIs exist here, so a returned Webmail credential, session or URL can never be used or fetched through this guard.
 * Failure text carries no request data.
 */
final class WebmailSessionGuard implements ClientInterface
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private ?string $cpanelToken = null;

    /** @var array<string, true> Consumed recipe mutation URIs. */
    private array $consumed = [];

    private readonly ControlPostAdmission $controlPosts;

    /** @var array<string, true> */
    private readonly array $readOnly;

    /** @var array<string, string> Each recipe mutation phase, by its exact URI. */
    private readonly array $mutations;

    /** Bind the root control credential and the run's recipe; construction sends nothing. */
    public function __construct(
        #[SensitiveParameter]
        private readonly ClientInterface $http,
        #[SensitiveParameter]
        private readonly string $whmToken,
        #[SensitiveParameter]
        private readonly WebmailSessionRecipe $recipe,
    ) {
        if ($whmToken === '') {
            throw new LogicException('Webmail session fixture configuration is invalid.');
        }
        $this->controlPosts = $recipe->account->controlPostAdmission();
        $reads = [];
        foreach ($recipe->account->controlReads() as $uri) {
            $reads[$uri] = true;
        }
        $mutations = [];
        foreach (WebmailSessionRecipe::PHASES as $phase) {
            $request = $recipe->request($phase);
            if ($request['method'] === 'GET') {
                $reads[$request['uri']] = true;
            } else {
                $mutations[$request['uri']] = $phase;
            }
        }
        $this->readOnly = $reads;
        $this->mutations = $mutations;
    }

    /** Bind the generated credential once, without admitting whitespace or header control characters. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('Webmail session fixture token is unusable.');
        }
        $this->cpanelToken = $token;
    }

    /** Permit only this fixture's exact requests, each mutation at most once. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        try {
            $this->recipe->account->requireLiveOptIn();
        } catch (LogicException) {
            throw self::refused();
        }
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $direct = str_starts_with($uri, 'https://test.oblak.host:2083/');
        if (!$direct && !str_starts_with($uri, self::WHM)) {
            throw self::refused();
        }
        $method = $request->getMethod();
        if ($method === 'GET') {
            $this->requireHeaders($request, $body === '' && isset($this->readOnly[$uri]), $direct, null);

            return $this->http->sendRequest($request);
        }
        if ($method !== 'POST') {
            throw self::refused();
        }
        $phase = $this->mutations[$uri] ?? null;
        $allowed = $phase === null
            ? !$direct && $this->controlPosts->admit($uri, $body)
            : $this->admitMutation($phase, $uri, $body, $direct);
        $this->requireHeaders($request, $allowed, $direct, 'application/x-www-form-urlencoded');

        return $this->http->sendRequest($request);
    }

    /** True the first time a recipe mutation's exact request arrives on its own route; false for any other. */
    private function admitMutation(string $phase, string $uri, #[SensitiveParameter] string $body, bool $direct): bool
    {
        if (
            isset($this->consumed[$uri]) || $direct !== (WebmailSessionRecipe::route($phase) === 'direct-uapi')
            || !hash_equals($this->recipe->request($phase)['body'], $body)
        ) {
            return false;
        }
        $this->consumed[$uri] = true;

        return true;
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
                ? 'cpanel ' . WebmailSessionRecipe::ACCOUNT . ':' . $this->cpanelToken
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
        return new LogicException('Request outside the Webmail session fixture boundary.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The guard holds credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Webmail session guard cannot be serialized.');
    }
}
