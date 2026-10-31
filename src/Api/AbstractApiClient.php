<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

use HostingPal\WhmApiClient\Api\Engine\JsonBody;
use HostingPal\WhmApiClient\Api\Engine\JsonRouting;
use HostingPal\WhmApiClient\Api\Engine\MultipartEncoder;
use HostingPal\WhmApiClient\Api\Engine\MultipartInput;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\Engine\ParameterExpander;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Exception\TransportFailure;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Http\StreamingMultipartBody;
use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\EnvelopeReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use SensitiveParameter;

/**
 * Share configuration and the request lifecycle across API family implementations.
 *
 * Every attempt clears this handle's metadata first, encodes parameters, sends one
 * request without retries, and records a usable envelope before returning a payload
 * or raising an API error. Metadata is instance-local. GET places parameters in the
 * query string; POST keeps routing fields there and form-encodes the operation
 * parameters in the body. Explicit JSON bodies retain validated bytes. The outcome
 * policy is declared per request.
 * Every library frame that receives request parameters marks them
 * #[SensitiveParameter], so the library's frames in exception traces carry no
 * passwords, tokens or other parameters even when PHP records call arguments
 * (zend.exception_ignore_args=0, the default without a php.ini). The PSR-18
 * client's own frames are outside the library's control: a client that throws from
 * sendRequest() records the request it was handed, with its Authorization header
 * and any GET query (see HttpTransport::send()).
 *
 * @phpstan-type Parameters array<string, string|int|list<string>|NumberedList|RepeatedList|SuffixNumberedList>
 * @phpstan-type MultipartQuery array<string, string|int|list<string>|NumberedList|RepeatedList|SuffixNumberedList|null>
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
     * SuffixNumberedList replaces a terminal wildcard with 1, 2, ... in order.
     * Generic markers are validated here; generated OrderedFields already carry
     * validated literal expansions. Both paths follow the handle's metadata reset.
     *
     * @param Parameters|OrderedFields $query
     * @throws InvalidArgumentException If a parameter value or a multi-value parameter is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    final protected function executeGet(
        string $path,
        #[SensitiveParameter]
        array|OrderedFields $query,
        ResponseRoute $route,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        return $this->execute(RequestMethod::Get, $path, $query, [], $route, $outcome);
    }

    /**
     * Execute one authenticated POST with the selected operation-body encoding.
     *
     * Routing fields stay in the query string while the operation parameters travel
     * as an application/x-www-form-urlencoded body, so they never reach the URL that
     * the pinned WHM documentation says the server records in its access log.
     * Reviewed multipart operations instead use inline string fields with CRLF
     * framing and a collision-free boundary. Generic calls retain form encoding.
     * Secret-bearing operations must use this executor. Encoding rules, lifecycle,
     * and failure handling match executeGet; an empty body is sent as empty content.
     *
     * @param Parameters|OrderedFields $query Routing fields that remain in the URL.
     * @param Parameters|OrderedFields $body Operation fields sent as form or inline-string multipart content.
     * @throws InvalidArgumentException If a parameter value or a multi-value parameter is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    final protected function executePost(
        string $path,
        #[SensitiveParameter]
        array|OrderedFields $query,
        #[SensitiveParameter]
        array|OrderedFields $body,
        ResponseRoute $route,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
        RequestBodyFormat $bodyFormat = RequestBodyFormat::Form,
    ): ApiResponse {
        return $this->execute(RequestMethod::Post, $path, $query, $body, $route, $outcome, $bodyFormat);
    }

    /**
     * Execute one JSON POST through the common one-attempt lifecycle.
     *
     * The owning handle clears metadata before validating caller input and controls
     * route capability. Present content retains exact bytes; absence sends no
     * content or Content-Type. Authentication and envelope handling stay shared.
     *
     * @param Parameters|OrderedFields $query
     */
    final protected function executeJsonPost(
        string $path,
        #[SensitiveParameter] array|OrderedFields $query,
        #[SensitiveParameter] JsonBody $body,
        ResponseRoute $route,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        $this->beginRequest();
        if ($route === ResponseRoute::BridgedUapi) {
            throw new InvalidArgumentException('JSON bodies require a direct cPanel UAPI handle.');
        }

        return $this->execute(RequestMethod::Post, $path, $query, $body, $route, $outcome);
    }

    /**
     * Send a validated multipart declaration through the shared execution path.
     *
     * The handle owns route validation. Query, URI and authentication encoding
     * finish before source preparation. The fresh streaming body is released on
     * every exit, including an API-layer failure before entering the transport.
     *
     * @param OrderedFields $query Already validated caller fields and fixed routing.
     */
    final protected function executeMultipartPost(
        string $path,
        #[SensitiveParameter] OrderedFields $query,
        #[SensitiveParameter] MultipartInput $body,
        ResponseRoute $route,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        return $this->execute(RequestMethod::Post, $path, $query, $body, $route, $outcome);
    }

    /**
     * Validate the new JSON entrypoints' explicit query before legacy filtering.
     *
     * Presence checks precede value expansion so even reserved nulls are rejected.
     * The case-sensitive api. prefix and route-specific exact names are reserved.
     * Expansion checks final wire names, using static, data-free diagnostics.
     * Generated dispatch checks OrderedFields here before withRouting(); the JSON
     * executor accepts the resulting already-routed query. Existing generic call()
     * filtering and collision semantics are unaffected.
     *
     * @param Parameters|OrderedFields $query
     * @param list<string> $reserved Additional exact names reserved by the route.
     */
    final protected function validateJsonQuery(
        #[SensitiveParameter] array|OrderedFields $query,
        array $reserved,
    ): void {
        if ($query instanceof OrderedFields) {
            foreach ($query->all() as $field) {
                JsonRouting::assertAllowed($field['name'], $reserved);
            }

            return;
        }
        foreach ($query as $name => $value) {
            JsonRouting::assertAllowed((string) $name, $reserved);
            foreach (ParameterExpander::expand((string) $name, $value) as $field) {
                JsonRouting::assertAllowed($field['name'], $reserved);
            }
        }
    }

    /**
     * Run the shared lifecycle: reset, encode, send once, read the envelope, record.
     *
     * @param Parameters|OrderedFields $query
     * @param Parameters|OrderedFields|JsonBody|MultipartInput $body
     * @throws InvalidArgumentException If a parameter value or a multi-value parameter is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    private function execute(
        RequestMethod $method,
        string $path,
        #[SensitiveParameter]
        array|OrderedFields $query,
        #[SensitiveParameter]
        array|OrderedFields|JsonBody|MultipartInput $body,
        ResponseRoute $route,
        OutcomePolicy $outcome,
        RequestBodyFormat $bodyFormat = RequestBodyFormat::Form,
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
            if ($body instanceof MultipartInput) {
                $content = StreamingMultipartEncoder::encode($body->parts);
            } elseif ($body instanceof JsonBody) {
                $content = $body->content;
                if ($content !== null) {
                    $headers['Content-Type'] = 'application/json';
                }
            } elseif ($bodyFormat === RequestBodyFormat::Multipart) {
                [$headers['Content-Type'], $content] = MultipartEncoder::encode($body);
            } else {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                $content = self::encode($body);
            }
        }

        try {
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
        } finally {
            if ($content instanceof StreamingMultipartBody) {
                $content->close();
            }
        }
    }

    /**
     * Encode parameters as RFC 3986 key=value pairs joined by "&", in order.
     *
     * @param Parameters|OrderedFields $parameters
     * @throws InvalidArgumentException If a parameter value or a multi-value parameter is invalid.
     */
    private static function encode(#[SensitiveParameter] array|OrderedFields $parameters): string
    {
        if ($parameters instanceof OrderedFields) {
            return $parameters->encode();
        }
        $parts = [];
        $seen = [];
        $suffixKeys = [];
        foreach ($parameters as $name => $value) {
            $suffix = $value instanceof SuffixNumberedList;
            foreach (ParameterExpander::expand((string) $name, $value, true) as $field) {
                $key = $field['name'];
                if (($suffix && isset($seen[$key])) || isset($suffixKeys[$key])) {
                    throw new InvalidArgumentException('Suffix-numbered parameters overlap another wire name.');
                }
                $seen[$key] = true;
                if ($suffix) {
                    $suffixKeys[$key] = true;
                }
                $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $field['value']);
            }
        }

        return implode('&', $parts);
    }
}
