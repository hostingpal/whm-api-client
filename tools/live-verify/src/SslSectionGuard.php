<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Exact, one-shot guard for fresh SSL storage operations on npr, never certificate installation.
 *
 * Multipart framing is independently checked against the expected in-memory fields.
 * Deletes require an ID registered after confirmed creation; neither request payloads
 * nor identities appear in failure text. Authorization is consumed even on rejection.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Step array{route: 'whm'|Route, function: string, uri: string,
 *     fields: array<string,string>, method:string}
 */
final class SslSectionGuard implements ClientInterface
{
    /** @var Step|null */
    private ?array $pending = null;
    /** @var array<string, true> */
    private array $used = [];
    /** @var array<string, string> */
    private array $owned = [];

    /** @param array{whm:string, cpanel:string} $tokens
     * @param array<Route, SslSectionMaterial> $materials
     */
    public function __construct(
        #[SensitiveParameter] private readonly ClientInterface $http,
        #[SensitiveParameter] private readonly array $tokens,
        #[SensitiveParameter] private readonly array $materials,
    ) {
        if ($tokens['whm'] === '' || $tokens['cpanel'] === '') {
            throw new LogicException('SSL fixture credentials required.');
        }
    }

    /** @param Route $route
     * @param 'key'|'cert' $kind
     */
    public function own(string $route, string $kind, #[SensitiveParameter] string $id): void
    {
        if ($id === '' || isset($this->owned[$route . ':' . $kind])) {
            throw new LogicException('SSL fixture ownership unavailable.');
        }
        $this->owned[$route . ':' . $kind] = $id;
    }

    /** @param 'whm'|Route $route
     * @param array<string,string> $fields
     */
    public function authorize(
        string $phase,
        string $route,
        string $function,
        #[SensitiveParameter] array $fields,
    ): string {
        $identity = $route . ':' . $phase;
        if ($this->pending !== null || isset($this->used[$identity])) {
            throw new LogicException('SSL fixture phase already consumed.');
        }
        $this->used[$identity] = true;
        $expected = [];
        $method = 'GET';
        if ($route === 'whm') {
            if ($function !== 'version') {
                throw new LogicException('SSL fixture WHM operation unavailable.');
            }
            $uri = 'https://test.oblak.host:2087/json-api/version?api.version=1';
        } else {
            $material = $this->materials[$route];
            $expected = match ($function) {
                'list_keys', 'list_certs' => [],
                'upload_key' => ['key' => $material->key, 'friendly_name' => self::name($material)],
                'upload_cert' => ['crt' => $material->certificate, 'friendly_name' => self::name($material)],
                'fetch_key_and_cabundle_for_certificate' => ['certificate' => $material->certificate],
                'delete_key' => ['id' => $this->owned[$route . ':key'] ?? ''],
                'delete_cert' => ['id' => $this->owned[$route . ':cert'] ?? ''],
                default => throw new LogicException('SSL fixture operation unavailable.'),
            };
            if (isset($expected['id']) && $expected['id'] === '') {
                throw new LogicException('SSL fixture deletion lacks ownership.');
            }
            $method = in_array($function, ['list_keys', 'list_certs'], true) ? 'GET' : 'POST';
            $uri = $route === 'direct-uapi'
                ? 'https://test.oblak.host:2083/execute/SSL/' . $function
                : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=SSL&cpanel.function=' . $function;
        }
        if ($fields !== $expected) {
            throw new LogicException('SSL fixture fields outside owned material boundary.');
        }
        $this->pending = ['route' => $route, 'function' => $function, 'uri' => $uri,
            'fields' => $fields, 'method' => $method];
        return $uri;
    }

    /** Forget a phase that failed before dispatch; it remains consumed. */
    public function clearPending(): void
    {
        $this->pending = null;
    }

    /** Independently enforce exact opt-ins, target, credentials, method and byte-for-byte request content. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $step = $this->pending;
        $this->pending = null;
        if (!self::optedIn() || $step === null) {
            throw new LogicException('SSL fixture request lacks authorization.');
        }
        $direct = $step['route'] === 'direct-uapi';
        $headers = [
            'host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct ? 'cpanel npr:' . $this->tokens['cpanel'] : 'whm root:' . $this->tokens['whm']],
            'accept' => ['application/json'],
        ];
        $body = '';
        if ($step['method'] === 'POST') {
            if (str_starts_with($step['function'], 'delete_')) {
                $headers['content-type'] = ['application/x-www-form-urlencoded'];
                $body = 'id=' . rawurlencode($step['fields']['id']);
            } else {
                $type = $request->getHeaderLine('Content-Type');
                if (preg_match('/\Amultipart\/form-data; boundary=([A-Za-z0-9_-]{20,70})\z/', $type, $match) !== 1) {
                    throw new LogicException('SSL fixture multipart header invalid.');
                }
                $headers['content-type'] = [$type];
                foreach ($step['fields'] as $name => $value) {
                    $body .= '--' . $match[1] . "\r\nContent-Disposition: form-data; name=\"" . $name
                        . "\"\r\n\r\n" . $value . "\r\n";
                }
                $body .= '--' . $match[1] . "--\r\n";
            }
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        if (
            $actual !== $headers || $request->getMethod() !== $step['method']
            || (string) $request->getUri() !== $step['uri'] || (string) $request->getBody() !== $body
        ) {
            throw new LogicException('Request outside SSL fixture boundary.');
        }
        return $this->http->sendRequest($request);
    }

    /** A unique friendly label, derived only from the new public key identity. */
    public static function name(#[SensitiveParameter] SslSectionMaterial $material): string
    {
        return 'whm-api-' . substr(hash('sha256', $material->modulus), 0, 20);
    }

    /** Check the standing exact account designation at every dispatch. */
    public static function optedIn(): bool
    {
        return getenv('WHM_API_LIVE') === '1' && getenv('WHM_API_LIVE_MUTATIONS') === '1'
            && getenv('WHM_API_LIVE_ACCOUNT') === 'npr' && getenv('WHM_API_LIVE_DOMAIN') === 'npr.rs';
    }
}
