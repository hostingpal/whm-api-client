<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A synchronous client boundary observing real requests without network I/O. */
final class UploadTestClient implements ClientInterface
{
    public int $calls = 0;
    public ?RequestInterface $request = null;

    /** @param Closure(RequestInterface): ResponseInterface $send */
    public function __construct(private Closure $send)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->request = $request;
        return ($this->send)($request);
    }
}
