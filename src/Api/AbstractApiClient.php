<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Exception\TransportFailure;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\EnvelopeReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

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
     * Configuration stays internal: account-scoped handles never hand out the
     * credentials they were built with, which may belong to a WHM root user.
     */
    protected function __construct(
        protected readonly ClientConfig $config,
        protected readonly HttpTransport $transport,
    ) {
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
     * executeGet resets state before query encoding, validation, and I/O.
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
     * List values encode repeated parameters in order, without bracketed names;
     * NumberedList values use WHM's name, name-1, ... convention. Empty or
     * malformed lists fail locally after clearing prior metadata, so operation
     * groups pass caller input through unvalidated instead of checking it first.
     *
     * @param array<string, string|int|list<string>|NumberedList> $query
     * @throws InvalidArgumentException If a multi-value parameter is invalid.
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
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException(
                match (true) {
                    $exception instanceof NetworkExceptionInterface => TransportFailure::Network,
                    $exception instanceof RequestExceptionInterface => TransportFailure::Request,
                    default => TransportFailure::Other,
                },
                $exception::class,
                $this->config->credentials->redact($exception->getMessage()),
            );
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
     * Encode scalar, repeated, or numbered query values without changing key names.
     *
     * A list<string> repeats its literal name; a NumberedList uses name, name-1,
     * name-2, ... in order. The mixed boundary validates PHP array contents
     * supplied to typed methods.
     *
     * @return non-empty-list<string>
     * @throws InvalidArgumentException If a list is empty, keyed, or contains non-strings.
     */
    private static function queryValues(string $name, mixed $value): array
    {
        $numbered = $value instanceof NumberedList;
        $value = $numbered ? $value->values : $value;
        if (is_string($value) || is_int($value)) {
            return [rawurlencode($name) . '=' . rawurlencode((string) $value)];
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('Multi-value query parameters require a nonempty list of strings.');
        }
        $parts = [];
        foreach ($value as $index => $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Multi-value query parameters require string values.');
            }
            $key = $numbered && $index > 0 ? $name . '-' . $index : $name;
            $parts[] = rawurlencode($key) . '=' . rawurlencode($entry);
        }

        return $parts;
    }
}
