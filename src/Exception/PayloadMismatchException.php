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
 * omitted or null. It is not part of getMessage() and var_dump()/print_r() output
 * elides it. It can contain secrets such as a newly created token or key, so do not
 * log it indiscriminately.
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

    /**
     * Describe the exception for var_dump() and print_r() without its payload.
     *
     * The payload can hold secrets; read $payload directly when it is needed.
     *
     * @return array{message: string, file: string, line: int, metadata: ?ResponseMetadata,
     *     outcomeConfirmed: bool, payload: string}
     */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'metadata' => $this->metadata,
            'outcomeConfirmed' => $this->outcomeConfirmed,
            'payload' => '[elided; read $payload]',
        ];
    }
}
