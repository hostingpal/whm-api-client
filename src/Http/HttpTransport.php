<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use SensitiveParameter;

/**
 * Build PSR-7 requests and send them through a PSR-18 client.
 *
 * The PSR-18 client is explicit because it owns the security and reliability
 * policy the API layer relies on: verified TLS, disabled redirects, and timeouts.
 * PSR-17 factories carry no such policy and may be discovered. discover() is the
 * explicit opt-in for a discovered client with whatever defaults it ships with.
 * API-specific authentication, request encoding, and response interpretation
 * belong above this layer.
 */
final readonly class HttpTransport
{
    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /**
     * Configure the HTTP client and message factories.
     *
     * The client must verify TLS, disable redirects, and set timeouts for
     * authenticated requests. Omitted factories are resolved through HTTP
     * discovery without sending requests.
     *
     * @throws \Http\Discovery\Exception\NotFoundException If a factory cannot be discovered.
     */
    public function __construct(
        private ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Opt in to a discovered PSR-18 client and PSR-17 factories.
     *
     * The discovered client keeps its own defaults, which this library cannot
     * verify: many clients have no timeout, and redirect or TLS policy depends on
     * the implementation. Prefer injecting a configured client for production use.
     * Discovery sends no requests.
     *
     * @throws \Http\Discovery\Exception\NotFoundException If a dependency cannot be discovered.
     */
    public static function discover(): self
    {
        return new self(Psr18ClientDiscovery::find());
    }

    /**
     * Send an HTTP request and return the unmodified PSR response.
     *
     * Header lists are preserved and the optional body is already-encoded content.
     * HTTP error responses are returned for the API layer to interpret; PSR client
     * exceptions propagate without retries. This transport does not add credentials.
     * The URI, headers and body are marked sensitive, so this frame's trace arguments
     * omit authorization values and any secret-bearing query or form parameters; the
     * PSR request handed to the client still carries them.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @throws ClientExceptionInterface
     */
    public function send(
        string $method,
        #[SensitiveParameter]
        string|UriInterface $uri,
        #[SensitiveParameter]
        array $headers = [],
        #[SensitiveParameter]
        ?string $body = null,
    ): ResponseInterface {
        $request = $this->requestFactory->createRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        return $this->httpClient->sendRequest($request);
    }
}
