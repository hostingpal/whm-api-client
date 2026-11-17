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
 * Confine the Mime and directory fixture to one disposable account, one temporary token and the recipe's requests.
 *
 * Every request requires both opt-ins and the exact account and domain. A fixture request needs the prior single-use
 * authorization of its phase, then must equal that phase's independently spelled method, URI, complete header set and
 * body. A phase can be authorized only once, even when its request is refused or never sent, so nothing below this
 * guard can retry a mutation or repeat a read. Outside a phase, only the binding's control reads and control
 * mutations pass, each control mutation once through ControlPostAdmission. The one multipart upload is compared with
 * its independent expectation as it streams and reaches only the streaming transport. Failure text carries no request
 * data.
 *
 * @phpstan-import-type Transfer from FilemanUploadBodyStream
 * @phpstan-import-type Framing from StreamingTransport
 */
final class MimeDirectoryGuard implements ClientInterface
{
    private const UPLOAD = 'create_directory';

    private ?string $cpanelToken = null;

    private ?string $pending = null;

    /** @var array<string, true> */
    private array $consumed = [];

    /** @var array{transfer: Transfer, framing: Framing|null}|null */
    private ?array $transfer = null;

    private readonly ControlPostAdmission $controlPosts;

    /** Bind the root control credential, the run's recipe and both transports; construction sends nothing. */
    public function __construct(
        #[SensitiveParameter]
        private readonly ClientInterface $http,
        #[SensitiveParameter]
        private readonly StreamingTransport $streaming,
        #[SensitiveParameter]
        private readonly string $whmToken,
        #[SensitiveParameter]
        private readonly MimeDirectoryRecipe $recipe,
    ) {
        if ($whmToken === '') {
            throw new LogicException('Mime directory fixture configuration is invalid.');
        }
        $this->controlPosts = $recipe->account->controlPostAdmission();
    }

    /** Bind the generated credential once, without admitting whitespace or header control characters. */
    public function bindToken(#[SensitiveParameter] string $token): void
    {
        if ($this->cpanelToken !== null || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new LogicException('Mime directory fixture token is unusable.');
        }
        $this->cpanelToken = $token;
    }

    /** Authorize the next request as this phase, which can never be authorized again. */
    public function authorize(string $phase): void
    {
        if (!isset(MimeDirectoryRecipe::PHASES[$phase]) || isset($this->consumed[$phase])) {
            throw self::refused();
        }
        $this->consumed[$phase] = true;
        $this->pending = $phase;
    }

    /** Forget an authorization whose request never arrived; its phase stays consumed. */
    public function clearPending(): void
    {
        $this->pending = null;
    }

    /**
     * Sanitized counts and framing booleans of the directory upload, once it was attempted.
     *
     * @return array{transfer: Transfer, framing: Framing|null}|null
     */
    public function transfer(): ?array
    {
        return $this->transfer;
    }

    /** Permit only the authorized phase's exact request, or a control request outside every phase. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $phase = $this->pending;
        $this->clearPending();
        $this->recipe->account->requireLiveOptIn();
        if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/')) {
            return $this->upload($request, $phase);
        }
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $method = $request->getMethod();
        if ($phase !== null) {
            $wire = $this->recipe->wire($phase);
            $allowed = $phase !== self::UPLOAD && $method === $wire['method'] && $uri === $wire['uri']
                && $body === $wire['body'];
            $direct = $wire['route'] === 'direct-uapi';
        } else {
            $allowed = match ($method) {
                'GET' => $body === '' && in_array($uri, $this->recipe->account->controlReads(), true),
                'POST' => $this->controlPosts->admit($uri, $body),
                default => false,
            };
            $direct = false;
        }
        $this->requireHeaders($request, $allowed, $direct, $method === 'POST'
            ? 'application/x-www-form-urlencoded' : null);

        return $this->http->sendRequest($request);
    }

    private function upload(#[SensitiveParameter] RequestInterface $request, ?string $phase): ResponseInterface
    {
        $type = $request->getHeaderLine('Content-Type');
        $body = $request->getBody();
        if (
            $phase !== self::UPLOAD || $request->getMethod() !== 'POST'
            || (string) $request->getUri() !== $this->recipe->wire(self::UPLOAD)['uri']
            || !$body instanceof StreamingMultipartBody
            || preg_match('/\Amultipart\/form-data; boundary=([A-Za-z0-9_-]{20,70})\z/', $type, $match) !== 1
        ) {
            throw self::refused();
        }
        $expected = $this->recipe->expectedUploadBody($match[1]);
        $this->requireHeaders($request, $body->getSize() === strlen($expected), true, $type, strlen($expected));
        $checked = new FilemanUploadBodyStream($body, $expected);
        try {
            return $this->streaming->sendRequest($request->withBody($checked));
        } finally {
            $this->transfer = ['transfer' => $checked->stats(), 'framing' => $this->streaming->lastFraming()];
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
                ? 'cpanel ' . MimeDirectoryRecipe::ACCOUNT . ':' . $this->cpanelToken
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

    private static function refused(): LogicException
    {
        return new LogicException('Request outside the Mime directory fixture boundary.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The guard holds credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Mime directory guard cannot be serialized.');
    }
}
