<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ResponseMetadata;

/**
 * Share configuration and metadata lifecycle across API family implementations.
 *
 * Future executors use the protected transport and lifecycle hooks. Metadata is
 * instance-local and does not interpret HTTP or API outcome semantics itself.
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
}
