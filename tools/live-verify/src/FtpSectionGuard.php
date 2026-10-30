<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Exact, single-use phase boundary; a rejected request or lost response consumes authorization.
 *
 * Headers, encoding, target, body and transport method must match the fixed recipe exactly.
 * No anonymous setting, session termination, directory destruction or alternate target is permitted.
 *
 * @phpstan-import-type Step from FtpSectionRecipe
 */
final class FtpSectionGuard implements ClientInterface
{
    /** @var Step|null */
    private ?array $pending = null;

    /** @var array<string, true> */
    private array $used = [];

    /** @param array{whm: string, cpanel: string} $tokens */
    public function __construct(
        #[SensitiveParameter] private readonly ClientInterface $http,
        #[SensitiveParameter] private readonly array $tokens,
        #[SensitiveParameter] private readonly FtpSectionRecipe $recipe,
    ) {
        if ($tokens['whm'] === '' || $tokens['cpanel'] === '') {
            throw new LogicException('FTP section credentials are required.');
        }
    }

    /** @param 'whm'|'direct-uapi'|'bridged-uapi' $route */
    public function authorize(string $phase, string $route): void
    {
        $key = $phase . '|' . $route;
        if ($this->pending !== null || isset($this->used[$key])) {
            throw new LogicException('FTP section phase already authorized.');
        }
        $this->pending = $this->recipe->step($phase, $route);
        $this->used[$key] = true;
    }

    /** Discard unused authorization after a failure before dispatch without permitting a retry. */
    public function clearPending(): void
    {
        $this->pending = null;
    }

    /** Consume authorization before checking and delegating; opt-ins are checked on every request. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $step = $this->pending;
        $this->pending = null;
        if (!self::authorized() || $step === null) {
            throw new LogicException('FTP section requires exact opt-ins and an unused phase.');
        }
        $direct = $step['probe']->route === 'direct-uapi';
        $expected = ['host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct ? 'cpanel npr:' . $this->tokens['cpanel'] : 'whm root:' . $this->tokens['whm']],
            'accept' => ['application/json']];
        if ($step['method'] === 'POST') {
            $expected['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($expected);
        ksort($actual);
        if (
            $request->getMethod() !== $step['method'] || (string) $request->getUri() !== $step['probe']->uri
            || (string) $request->getBody() !== $step['body'] || $actual !== $expected
        ) {
            throw new LogicException('Request outside the FTP section boundary.');
        }

        return $this->http->sendRequest($request);
    }

    /** The exact existing account/domain designation is required even for preparatory reads. */
    public static function authorized(): bool
    {
        return getenv('WHM_API_LIVE') === '1' && getenv('WHM_API_LIVE_MUTATIONS') === '1'
            && getenv('WHM_API_LIVE_ACCOUNT') === 'npr' && getenv('WHM_API_LIVE_DOMAIN') === 'npr.rs';
    }
    /** Keep passwords, raw captures and resource identities out of ordinary diagnostic dumps.
     * @return array{}
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Fixture state contains credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('FTP section state cannot be serialized.');
    }
}
