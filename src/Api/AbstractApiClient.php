<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\EnvelopeReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Share configuration and metadata lifecycle across API family implementations.
 *
 * GET execution uses the protected transport and lifecycle hooks. Metadata is
 * instance-local; the envelope reader interprets the implemented operations' outcomes.
 */
abstract class AbstractApiClient
{
    private ?ResponseMetadata $responseMetadata = null;

    /**
     * Prepare a family client with immutable options and an existing transport.
     *
     * The metadata state starts empty; constructing a handle performs no I/O.
     */
    protected function __construct(
        protected readonly ClientConfig $config,
        protected readonly HttpTransport $transport,
    ) {
    }

    /**
     * Return the immutable connection and authentication configuration.
     *
     * For bridged UAPI the credential owner remains the WHM user; the selected
     * cPanel target is exposed separately by UapiClient::username().
     */
    final public function config(): ClientConfig
    {
        return $this->config;
    }

    /**
     * Return the metadata snapshot for this client's latest request attempt.
     *
     * The value is null before a response is recorded or after a new attempt clears
     * prior state. Reading metadata performs no I/O and cannot mutate the snapshot.
     * Bound operation groups can change this state between accessor calls.
     */
    final public function responseMeta(): ?ResponseMetadata
    {
        return $this->responseMetadata;
    }

    /**
     * Clear response metadata before a request attempt.
     *
     * Executors must call this before work that can fail, so transport or decoding
     * failures cannot expose a previous request's metadata as the current result.
     * Groups with local parameter validation receive this hook and call it before
     * validation; executeGet also resets state before query encoding and I/O.
     */
    final protected function beginRequest(): void
    {
        $this->responseMetadata = null;
    }

    /**
     * Record an immutable snapshot from a validated response envelope.
     *
     * Executors record metadata before returning a payload or raising an API error.
     * Retained snapshots remain unchanged when a later request replaces this value.
     */
    final protected function recordResponseMeta(ResponseMetadata $metadata): void
    {
        $this->responseMetadata = $metadata;
    }

    /**
     * Execute one authenticated GET for a reviewed read-only operation.
     *
     * Paths and query fields are supplied by declared operation groups. Literal
     * dotted names are preserved; no retries or redirect requests are issued.
     * Injected PSR clients must verify TLS and disable redirects themselves.
     * Metadata is cleared even if request creation, transport, or decoding fails.
     *
     * List values encode repeated parameters in order, without bracketed names.
     * Empty or malformed lists fail locally after clearing prior metadata.
     *
     * @param array<string, string|int|list<string>> $query
     * @throws InvalidArgumentException If a repeated parameter list is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    final protected function executeGet(string $path, array $query, ResponseRoute $route): ApiResponse
    {
        $this->beginRequest();
        $uri = $this->config->baseUri() . $path;
        if ($query !== []) {
            $parts = [];
            foreach ($query as $name => $value) {
                array_push($parts, ...self::queryValues($name, $value));
            }
            $uri .= '?' . implode('&', $parts);
        }

        try {
            $response = $this->transport->send('GET', $uri, [
                'Authorization' => $this->config->credentials->authorizationHeader(),
                'Accept' => 'application/json',
            ]);
        } catch (ClientExceptionInterface) {
            throw new TransportException('API transport failed.');
        }

        try {
            $result = EnvelopeReader::read($response, $route);
        } catch (ApiException $exception) {
            if ($exception->metadata !== null) {
                $this->recordResponseMeta($exception->metadata);
            }
            throw $exception;
        }

        $this->recordResponseMeta($result->metadata);

        return $result;
    }

    /**
     * Encode scalar or repeated query values without changing literal key names.
     *
     * The mixed boundary validates PHP array contents supplied to typed methods.
     * @return non-empty-list<string>
     */
    private static function queryValues(string $name, mixed $value): array
    {
        $prefix = rawurlencode($name) . '=';
        if (is_string($value) || is_int($value)) {
            return [$prefix . rawurlencode((string) $value)];
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('Repeated query parameters require a nonempty list of strings.');
        }
        $parts = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Repeated query parameters require string values.');
            }
            $parts[] = $prefix . rawurlencode($entry);
        }

        return $parts;
    }
}
