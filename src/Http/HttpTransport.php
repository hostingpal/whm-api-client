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

/**
 * Build PSR-7 requests and send them through a PSR-18 client.
 *
 * Each HTTP dependency can be injected or discovered independently. API-specific
 * authentication, request encoding, and response interpretation belong above this layer.
 */
final readonly class HttpTransport
{
    private ClientInterface $httpClient;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /**
     * Configure the HTTP client and message factories.
     *
     * Missing dependencies are resolved through HTTP discovery without sending
     * requests. Transport policy remains with the supplied PSR client.
     *
     * @throws \Http\Discovery\Exception\NotFoundException If a dependency cannot be discovered.
     */
    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Send an HTTP request and return the unmodified PSR response.
     *
     * Header lists are preserved and the optional body is already-encoded content.
     * HTTP error responses are returned for the API layer to interpret; PSR client
     * exceptions propagate without retries. This transport does not add credentials.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @throws ClientExceptionInterface
     */
    public function send(
        string $method,
        string|UriInterface $uri,
        array $headers = [],
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
