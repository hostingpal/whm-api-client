<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Confine the user task fixture to one disposable account, one temporary token and the recipe's exact requests.
 *
 * Every request requires both opt-ins and the exact account and domain, then an exact method, URI, header set and
 * body. Reads are a fixed list. Each mutation is consumed once, even on rejection, so nothing below this guard can
 * retry one: the binding's ControlPostAdmission admits each control mutation, and this guard consumes its own two
 * repository creations and its one task deletion. Those also need a prior single-use authorization from the fixture.
 * A deletion names one queue element whose ownership the fixture proved, and only one deletion is ever authorized, so
 * no other queue entry can be touched. Failure text carries no request data.
 *
 * @phpstan-import-type Repository from UserTasksRecipe
 */
final class UserTasksGuard implements ClientInterface
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private ?string $cpanelToken = null;

    private ?string $pendingBody = null;

    private ?string $pendingUri = null;

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
        private readonly UserTasksRecipe $recipe,
    ) {
        if ($whmToken === '') {
            throw new LogicException('User task fixture configuration is invalid.');
        }
        $this->controlPosts = $recipe->account->controlPostAdmission();
        $reads = [
            self::WHM . 'version?api.version=1' => true,
            $recipe->homeUri() => true,
            $recipe->tasksUri(false) => true,
            $recipe->tasksUri(true) => true,
        ];
        foreach ($recipe->account->controlReads() as $uri) {
            $reads[$uri] = true;
        }
        $this->reads = $reads;
    }

    /** Bind the generated credential once, without admitting whitespace or header control characters. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('User task fixture token is unusable.');
        }
        $this->cpanelToken = $token;
    }

    /**
     * Authorize the next request as this repository's one creation, which can never be authorized again.
     *
     * @param Repository $repository
     */
    public function authorizeCreate(string $repository): void
    {
        $this->consume('create:' . $repository);
        $this->pendingUri = $this->recipe->createUri();
        $this->pendingBody = $this->recipe->createBody($repository);
    }

    /**
     * Authorize the next request as the run's one deletion, of exactly this proven-owned queue element.
     *
     * @throws InvalidArgumentException For anything that is not a queue element name, before consuming anything.
     */
    public function authorizeDelete(#[SensitiveParameter] string $id): void
    {
        $body = $this->recipe->deleteBody($id);
        $this->consume('delete');
        $this->pendingUri = $this->recipe->deleteUri();
        $this->pendingBody = $body;
    }

    /** Forget an authorization whose request never arrived; it stays consumed. */
    public function clearPending(): void
    {
        $this->pendingUri = null;
        $this->pendingBody = null;
    }

    /** Permit only this fixture's exact requests, each mutation at most once. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $this->pendingUri;
        $body = $this->pendingBody;
        $this->clearPending();
        $this->recipe->account->requireLiveOptIn();
        $actualUri = (string) $request->getUri();
        $actualBody = (string) $request->getBody();
        $direct = str_starts_with($actualUri, 'https://test.oblak.host:2083/');
        if ($request->getMethod() === 'GET') {
            $allowed = $uri === null && $actualBody === '' && isset($this->reads[$actualUri]);
            $this->requireHeaders($request, $allowed, $direct, null);

            return $this->http->sendRequest($request);
        }
        if ($request->getMethod() !== 'POST') {
            throw self::refused();
        }
        $allowed = $direct
            ? $uri !== null && $actualUri === $uri && $body !== null && hash_equals($body, $actualBody)
            : $uri === null && $this->controlPosts->admit($actualUri, $actualBody);
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
                ? 'cpanel ' . UserTasksRecipe::ACCOUNT . ':' . $this->cpanelToken
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

    private function consume(string $identity): void
    {
        if (isset($this->consumed[$identity])) {
            throw self::refused();
        }
        $this->consumed[$identity] = true;
    }

    private static function refused(): LogicException
    {
        return new LogicException('Request outside the user task fixture boundary.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The guard holds credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('User task guard cannot be serialized.');
    }
}
