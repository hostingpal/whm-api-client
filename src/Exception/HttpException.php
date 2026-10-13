<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

use HostingPal\WhmApiClient\Response\ResponseMetadata;

/** Report a non-2xx HTTP response, with any usable API metadata retained. */
final class HttpException extends ApiException
{
    /**
     * Retain the HTTP status even when the body has no usable API envelope.
     *
     * Redirects are failures, not instructions to resend credentials elsewhere.
     */
    public function __construct(public readonly int $httpStatus, ?ResponseMetadata $metadata = null)
    {
        parent::__construct('API request returned HTTP ' . $httpStatus . '.', $metadata);
    }
}
