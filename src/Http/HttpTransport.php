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
use Psr\Http\Message\StreamInterface;
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
     * Supplied streams attach directly without copying. Ordinary caller streams
     * remain caller-owned. Internal multipart bodies supply exact framing headers
     * and release their owned sources after every synchronous exit, including a
     * response before complete consumption. A recorded multipart read failure is
     * checked even if the client swallowed it (including PSR string conversion).
     * A partial read alone is not an error or proof of complete delivery.
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
        string|StreamInterface|null $body = null,
    ): ResponseInterface {
        try {
            if ($body instanceof StreamingMultipartBody) {
                $body->assertHealthy();
            }
            $request = $this->requestFactory->createRequest($method, $uri);

            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            if ($body !== null) {
                $request = $request->withBody(
                    $body instanceof StreamInterface ? $body : $this->streamFactory->createStream($body),
                );
            }
            if ($body instanceof StreamingMultipartBody) {
                $request = $request->withHeader('Content-Type', $body->contentType())
                    ->withHeader('Content-Length', (string) $body->getSize())
                    ->withoutHeader('Transfer-Encoding');
            }

            $response = $this->httpClient->sendRequest($request);
            if ($body instanceof StreamingMultipartBody) {
                $body->assertHealthy();
            }
            return $response;
        } finally {
            if ($body instanceof StreamingMultipartBody) {
                $body->close();
            }
        }
    }
}
