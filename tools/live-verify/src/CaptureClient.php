<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;

/**
 * Development-only observation of one original HTTP response in memory.
 *
 * The payload reader triggers capture through its existing string conversion,
 * before decoding, normalization or unknown-field removal. Transport failures
 * propagate unchanged. No response is rerequested to recover evidence.
 */
final class CaptureClient implements ClientInterface
{
    private ?string $rawBody = null;

    private ?int $httpStatus = null;

    private ?string $method = null;

    private ?stdClass $attempt = null;

    public function __construct(#[SensitiveParameter] private readonly ClientInterface $client)
    {
    }

    /** Clear a previous lifecycle stage even when the next callback fails before sending a request. */
    public function clear(): void
    {
        $this->attempt = null;
        $this->rawBody = null;
        $this->httpStatus = null;
        $this->method = null;
    }

    /** Send once; reset prior capture even if the underlying request fails. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $attempt = $this->attempt = new stdClass();
        $this->rawBody = null;
        $this->httpStatus = null;
        $this->method = $request->getMethod();
        $response = $this->client->sendRequest($request);
        $this->httpStatus = $response->getStatusCode();

        return $response->withBody(new ObservedStream(
            $response->getBody(),
            function (#[SensitiveParameter] string $body) use ($attempt): void {
                if ($this->attempt === $attempt) {
                    $this->rawBody = $body;
                }
            },
        ));
    }

    /** Exact bytes delivered to the consumer, absent until successful string conversion. */
    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    /** HTTP status from the same response, absent after a transport failure. */
    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** Actual method of the latest attempted request, absent before the first attempt. */
    public function method(): ?string
    {
        return $this->method;
    }
}
