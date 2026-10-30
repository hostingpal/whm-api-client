<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * One-shot phase authorization with exact method, URI, body and header matching before I/O.
 *
 * Phases cannot be replayed, even after rejection or a transport failure. Canonical matching rejects
 * duplicate fields, alternate encodings, extra headers, target changes and passwords in queries.
 *
 * @phpstan-import-type Step from PostgresqlSectionRecipe
 */
final class PostgresqlSectionGuard implements ClientInterface
{
    /** @var Step|null */
    private ?array $expected = null;

    /** @var array<string, true> */
    private array $used = [];

    /** @param array{whm: string, cpanel: string} $tokens */
    public function __construct(
        #[SensitiveParameter] private readonly ClientInterface $http,
        #[SensitiveParameter] private readonly array $tokens,
        #[SensitiveParameter] private readonly PostgresqlSectionRecipe $recipe,
    ) {
        if ($tokens['whm'] === '' || $tokens['cpanel'] === '') {
            throw new LogicException('Section credentials are required.');
        }
    }

    /** @param 'whm'|'direct-uapi'|'bridged-uapi' $route */
    public function authorize(string $phase, string $route): void
    {
        $key = $phase . '|' . $route;
        if (isset($this->used[$key]) || $this->expected !== null) {
            throw new LogicException('Section phase already authorized.');
        }
        $this->expected = $this->recipe->step($phase, $route);
        $this->used[$key] = true;
    }

    /** Discard unused authorization after a failure before dispatch; consumed phases remain unavailable. */
    public function clearPending(): void
    {
        $this->expected = null;
    }

    /** Check all opt-ins again on every request, then consume this phase before delegating once. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $step = $this->expected;
        $this->expected = null;
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'npr' || getenv('WHM_API_LIVE_DOMAIN') !== 'npr.rs'
            || $step === null
        ) {
            throw new LogicException('Section requests require exact opt-ins and an unused phase.');
        }
        $direct = $step['probe']->route === 'direct-uapi';
        $headers = [
            'host' => ['test.oblak.host:' . ($direct ? '2083' : '2087')],
            'authorization' => [$direct ? 'cpanel npr:' . $this->tokens['cpanel'] : 'whm root:' . $this->tokens['whm']],
            'accept' => ['application/json'],
        ];
        if ($step['method'] === 'POST') {
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        if (
            $request->getMethod() !== $step['method'] || (string) $request->getUri() !== $step['probe']->uri
            || (string) $request->getBody() !== $step['body'] || $actual !== $headers
        ) {
            throw new LogicException('Request outside the section fixture boundary.');
        }

        return $this->http->sendRequest($request);
    }
}
