<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

use HostingPal\WhmApiClient\Response\ResponseMetadata;

/**
 * Report a payload that differs from its operation contract after the envelope was accepted.
 *
 * The response envelope parsed and, when $outcomeConfirmed is true, the request's
 * outcome policy confirmed success: the operation took effect upstream. Do not retry
 * a mutating operation on this exception; inspect $payload or confirm the server
 * state instead. Envelope and JSON failures raise a plain InvalidResponseException.
 *
 * $payload is the unvalidated decoded data member as plain PHP values (objects become
 * associative arrays), detached from the response, or null when the data member was
 * omitted or null. It is not part of getMessage(). It can contain secrets such as a
 * newly created token or key, so do not log it indiscriminately.
 */
final class PayloadMismatchException extends InvalidResponseException
{
    /**
     * Describe the mismatch with this attempt's metadata, outcome and raw payload.
     *
     * @param bool $outcomeConfirmed True when the outcome policy confirmed success; false when it was not checked.
     * @param mixed $payload Unvalidated decoded data member, or null when it was omitted or null.
     */
    public function __construct(
        string $message,
        ResponseMetadata $metadata,
        public readonly bool $outcomeConfirmed,
        public readonly mixed $payload,
    ) {
        parent::__construct($message, $metadata);
    }
}
