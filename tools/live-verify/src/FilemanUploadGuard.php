<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Http\StreamingMultipartBody;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Confine the upload fixture to one disposable account, one temporary token and the recipe's exact requests.
 *
 * Every request requires both opt-ins and the exact account and domain, then an exact method, URI, header set and
 * body. Each mutation is consumed once, even on rejection, so nothing below this guard can retry one. Uploads and
 * deletes also need a prior single-use authorization from the fixture; a delete names one exact path whose ownership
 * the fixture proved. Multipart bodies are compared with an independently assembled expectation as they stream, and
 * reach only the streaming transport. Every other request goes through the ordinary client. Failure text carries no
 * request data.
 *
 * @phpstan-import-type UploadPhase from FilemanUploadRecipe
 * @phpstan-import-type Transfer from FilemanUploadBodyStream
 * @phpstan-import-type Framing from StreamingTransport
 */
final class FilemanUploadGuard implements ClientInterface
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wlupload&cpanel.module=Tokens'
        . '&cpanel.function=';

    private ?string $cpanelToken = null;

    /** @var UploadPhase|null */
    private ?string $pendingUpload = null;

    private ?string $pendingDelete = null;

    /** @var array<string, true> */
    private array $consumed = [];

    /** @var array<string, array{transfer: Transfer, framing: Framing|null}> */
    private array $transfers = [];

    /** @var array<string, string> */
    private readonly array $controlPosts;

    /** @var array<string, true> */
    private readonly array $readOnly;

    /** Bind the root control credential, the run's recipe and both transports; construction sends nothing. */
    public function __construct(
        #[SensitiveParameter]
        private readonly ClientInterface $http,
        #[SensitiveParameter]
        private readonly StreamingTransport $streaming,
        #[SensitiveParameter]
        private readonly string $whmToken,
        #[SensitiveParameter]
        private readonly FilemanUploadRecipe $recipe,
    ) {
        if ($whmToken === '') {
            throw new LogicException('Fileman upload fixture configuration is invalid.');
        }
        $this->controlPosts = [
            self::WHM . 'createacct?api.version=1' => 'username=wlupload&domain=wlupload.example.com&contactemail='
                . rawurlencode($recipe->contactEmail) . '&quota=64&forcedns=0&hasshell=0&ip=n',
            self::WHM . 'removeacct?api.version=1' => 'username=wlupload&keepdns=0',
            self::BRIDGE . 'create_full_access' =>
                'name=' . FilemanUploadRecipe::TOKEN_NAME . '&expires_at=' . $recipe->expiresAt,
            self::BRIDGE . 'revoke' => 'name=' . FilemanUploadRecipe::TOKEN_NAME,
        ];
        $reads = [
            self::WHM . 'version?api.version=1' => true,
            self::WHM . 'listaccts?api.version=1&want=user%2Cdomain%2Cemail' => true,
            self::WHM . 'listzones?api.version=1' => true,
            self::WHM . 'get_domain_info?api.version=1' => true,
            self::BRIDGE . 'list' => true,
            $recipe->homeListUri() => true,
            $recipe->directoryListUri() => true,
        ];
        foreach (FilemanUploadRecipe::LABELS as $label) {
            $reads[$recipe->readUri($label)] = true;
        }
        $this->readOnly = $reads;
    }

    /** Bind the generated credential once, without admitting whitespace or header control characters. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('Fileman upload fixture token is unusable.');
        }
        $this->cpanelToken = $token;
    }

    /** Require the explicit designated target without exposing supplied environment values. */
    public static function requireEnvironment(): void
    {
        if (
            getenv('WHM_API_LIVE') !== '1'
            || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== FilemanUploadRecipe::ACCOUNT
            || getenv('WHM_API_LIVE_DOMAIN') !== FilemanUploadRecipe::DOMAIN
        ) {
            throw new LogicException('Fileman upload fixture requires both opt-ins and the designated account/domain.');
        }
    }

    /**
     * Authorize the next request as this upload phase, which can never be authorized again.
     *
     * @param UploadPhase $phase
     */
    public function authorizeUpload(string $phase): void
    {
        $this->consume('upload:' . $phase);
        $this->pendingUpload = $phase;
    }

    /** Authorize one deletion of a proven-owned relative path, which can never be authorized again. */
    public function authorizeDelete(#[SensitiveParameter] string $path): void
    {
        $owned = [$this->recipe->directory];
        foreach (FilemanUploadRecipe::LABELS as $label) {
            $owned[] = $this->recipe->relativePath($label);
        }
        if (!in_array($path, $owned, true)) {
            throw new LogicException('Fileman upload fixture deletion is outside its owned paths.');
        }
        $this->consume('delete:' . $path);
        $this->pendingDelete = $path;
    }

    /** Forget an authorization whose request never arrived; it stays consumed. */
    public function clearPending(): void
    {
        $this->pendingUpload = null;
        $this->pendingDelete = null;
    }

    /**
     * Sanitized counts and framing booleans recorded for one upload phase.
     *
     * @param UploadPhase $phase
     * @return array{transfer: Transfer, framing: Framing|null}|null
     */
    public function transfer(string $phase): ?array
    {
        return $this->transfers[$phase] ?? null;
    }

    /** Permit only this fixture's exact requests, each mutation at most once. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $upload = $this->pendingUpload;
        $delete = $this->pendingDelete;
        $this->clearPending();
        self::requireEnvironment();
        if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/')) {
            return $this->upload($request, $upload);
        }
        if ($upload !== null) {
            throw self::refused();
        }
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $direct = str_starts_with($uri, 'https://test.oblak.host:2083/');
        if ($request->getMethod() === 'GET') {
            $allowed = $delete === null && $body === '' && isset($this->readOnly[$uri]);
            $this->requireHeaders($request, $allowed, $direct, null);

            return $this->http->sendRequest($request);
        }
        if ($request->getMethod() !== 'POST') {
            throw self::refused();
        }
        if ($direct) {
            $allowed = $delete !== null && $uri === $this->recipe->deleteUri()
                && $body === 'path=' . rawurlencode($delete);
        } else {
            $allowed = $delete === null && ($this->controlPosts[$uri] ?? null) === $body;
            if ($allowed) {
                $this->consume('post:' . $uri);
            }
        }
        $this->requireHeaders($request, $allowed, $direct, 'application/x-www-form-urlencoded');

        return $this->http->sendRequest($request);
    }

    /** @param UploadPhase|null $phase */
    private function upload(#[SensitiveParameter] RequestInterface $request, ?string $phase): ResponseInterface
    {
        $type = $request->getHeaderLine('Content-Type');
        $body = $request->getBody();
        if (
            $phase === null || $request->getMethod() !== 'POST'
            || (string) $request->getUri() !== $this->recipe->uploadUri($phase)
            || !$body instanceof StreamingMultipartBody
            || preg_match('/\Amultipart\/form-data; boundary=([A-Za-z0-9_-]{20,70})\z/', $type, $match) !== 1
        ) {
            throw self::refused();
        }
        $expected = $this->recipe->expectedBody($phase, $match[1]);
        $this->requireHeaders($request, $body->getSize() === strlen($expected), true, $type, strlen($expected));
        $checked = new FilemanUploadBodyStream($body, $expected);
        try {
            return $this->streaming->sendRequest($request->withBody($checked));
        } finally {
            $this->transfers[$phase] = ['transfer' => $checked->stats(), 'framing' => $this->streaming->lastFraming()];
        }
    }

    /** Compare the complete header set exactly, so nothing unreviewed reaches a transport. */
    private function requireHeaders(
        #[SensitiveParameter] RequestInterface $request,
        bool $allowed,
        bool $direct,
        ?string $contentType,
        ?int $contentLength = null,
    ): void {
        if ($direct && $this->cpanelToken === null) {
            throw self::refused();
        }
        $headers = [
            'host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct
                ? 'cpanel ' . FilemanUploadRecipe::ACCOUNT . ':' . $this->cpanelToken
                : 'whm root:' . $this->whmToken],
            'accept' => ['application/json'],
        ];
        if ($contentType !== null) {
            $headers['content-type'] = [$contentType];
        }
        if ($contentLength !== null) {
            $headers['content-length'] = [(string) $contentLength];
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
        return new LogicException('Request outside the Fileman upload fixture boundary.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The guard holds credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Fileman upload guard cannot be serialized.');
    }
}
