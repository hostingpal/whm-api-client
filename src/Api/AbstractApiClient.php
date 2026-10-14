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
 * Share configuration and the request lifecycle across API family implementations.
 *
 * Every attempt clears this handle's metadata first, encodes parameters, sends one
 * request without retries, and records a usable envelope before returning a payload
 * or raising an API error. Metadata is instance-local. GET places parameters in the
 * query string; POST keeps routing fields there and form-encodes the operation
 * parameters in the body. The outcome policy is declared per request.
 *
 * @phpstan-type Parameters array<string, string|int|list<string>|NumberedList|RepeatedList>
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
     * Executors call this before work that can fail, so transport or decoding
     * failures cannot expose a previous request's metadata as the current result.
     * Both executors reset state before parameter encoding, validation, and I/O.
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
     * Execute one authenticated GET with every parameter in the query string.
     *
     * Paths are supplied by family handles and parameters by operation groups or
     * generic callers. Literal dotted names are preserved; no retries or redirect
     * requests are issued, and the injected PSR client must verify TLS and disable
     * redirects itself. List values encode repeated parameters in order, without
     * bracketed names; NumberedList values use WHM's name, name-1, ... convention.
     * Empty or malformed lists fail locally after clearing prior metadata, so
     * operation groups pass caller input through unvalidated.
     *
     * @param Parameters $query
     * @throws InvalidArgumentException If a multi-value parameter is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    final protected function executeGet(
        string $path,
        array $query,
        ResponseRoute $route,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        return $this->execute(RequestMethod::Get, $path, $query, [], $route, $outcome);
    }

    /**
     * Execute one authenticated POST with form-encoded operation parameters.
     *
     * Routing fields stay in the query string while the operation parameters travel
     * as an application/x-www-form-urlencoded body, so they never reach the URL that
     * the pinned WHM documentation says the server records in its access log.
     * Secret-bearing operations must use this executor. Encoding rules, lifecycle,
     * and failure handling match executeGet; an empty body is sent as empty content.
     *
     * @param Parameters $query Routing fields that remain in the URL.
     * @param Parameters $body Operation parameters sent in the form-encoded body.
     * @throws InvalidArgumentException If a multi-value parameter is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    final protected function executePost(
        string $path,
        array $query,
        array $body,
        ResponseRoute $route,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        return $this->execute(RequestMethod::Post, $path, $query, $body, $route, $outcome);
    }

    /**
     * Run the shared lifecycle: reset, encode, send once, read the envelope, record.
     *
     * @param Parameters $query
     * @param Parameters $body
     * @throws InvalidArgumentException If a multi-value parameter is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    private function execute(
        RequestMethod $method,
        string $path,
        array $query,
        array $body,
        ResponseRoute $route,
        OutcomePolicy $outcome,
    ): ApiResponse {
        $this->beginRequest();
        $uri = $this->config->baseUri() . $path;
        $encodedQuery = self::encode($query);
        if ($encodedQuery !== '') {
            $uri .= '?' . $encodedQuery;
        }
        $headers = [
            'Authorization' => $this->config->credentials->authorizationHeader(),
            'Accept' => 'application/json',
        ];
        $content = null;
        if ($method === RequestMethod::Post) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $content = self::encode($body);
        }

        try {
            $response = $this->transport->send($method->value, $uri, $headers, $content);
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
            $result = EnvelopeReader::read($response, $route, $outcome);
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
     * Encode parameters as RFC 3986 key=value pairs joined by "&", in order.
     *
     * @param Parameters $parameters
     * @throws InvalidArgumentException If a multi-value parameter is invalid.
     */
    private static function encode(array $parameters): string
    {
        $parts = [];
        foreach ($parameters as $name => $value) {
            array_push($parts, ...self::queryValues((string) $name, $value));
        }

        return implode('&', $parts);
    }

    /**
     * Encode scalar, repeated, or numbered values without changing key names.
     *
     * List values and RepeatedList values repeat their literal name; NumberedList
     * values use WHM's name, name-1, name-2, ... convention. The mixed boundary
     * validates PHP array contents supplied to typed methods.
     *
     * @return non-empty-list<string>
     * @throws InvalidArgumentException If a list is empty, keyed, or contains non-strings.
     */
    private static function queryValues(string $name, mixed $value): array
    {
        $numbered = $value instanceof NumberedList;
        if ($numbered || $value instanceof RepeatedList) {
            $value = $value->values;
        }
        if (is_string($value) || is_int($value)) {
            return [rawurlencode($name) . '=' . rawurlencode((string) $value)];
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('Multi-value parameters require a nonempty list of strings.');
        }
        $parts = [];
        foreach ($value as $index => $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Multi-value parameters require string values.');
            }
            $key = $numbered && $index > 0 ? $name . '-' . $index : $name;
            $parts[] = rawurlencode($key) . '=' . rawurlencode($entry);
        }

        return $parts;
    }
}
