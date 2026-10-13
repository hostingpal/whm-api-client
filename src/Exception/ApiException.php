<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

use HostingPal\WhmApiClient\Response\ResponseMetadata;
use RuntimeException;

/**
 * Base failure for an attempted API operation.
 *
 * Messages omit upstream bodies and credentials. Inspect metadata explicitly for
 * upstream diagnostics; it may contain account information and should not be logged
 * indiscriminately. Transport exceptions are not chained because they can retain
 * authenticated requests. The snapshot is the same instance exposed by the client.
 */
class ApiException extends RuntimeException
{
    /**
     * Describe a failure with the metadata available for this attempt.
     *
     * Null means no usable API envelope was received. The message must be safe
     * for callers to display without exposing request headers or response bodies.
     */
    public function __construct(string $message, public readonly ?ResponseMetadata $metadata = null)
    {
        parent::__construct($message);
    }
}
