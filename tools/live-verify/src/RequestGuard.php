<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** Enforce the recipe's exact GET URI, including target account and all parameters. */
final readonly class RequestGuard implements ClientInterface
{
    /** The approved URI comes from a reviewed recipe, never caller-provided live arguments. */
    public function __construct(
        #[SensitiveParameter]
        private ClientInterface $client,
        #[SensitiveParameter]
        private string $approvedUri,
    ) {
    }

    /** Reject method, body or URI differences before reaching the execution boundary. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        if (
            $request->getMethod() !== 'GET'
            || (string) $request->getUri() !== $this->approvedUri
            || (string) $request->getBody() !== ''
        ) {
            throw new LogicException('Request outside the reviewed live recipe.');
        }

        return $this->client->sendRequest($request);
    }
}
