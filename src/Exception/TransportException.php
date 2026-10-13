<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

/**
 * Report PSR transport failure without retaining a potentially secret-bearing cause.
 *
 * The original exception is never chained because it can hold the authenticated
 * request. Its classification and class name are kept for diagnosis, and its
 * message is available separately with the configured token redacted. That
 * message can name the request URL and upstream host details, so it is excluded
 * from getMessage() and should be logged deliberately.
 */
final class TransportException extends ApiException
{
    /**
     * Class name of the PSR client exception; anonymous classes are shortened to
     * "Parent@anonymous" so no NUL byte or source path reaches messages.
     */
    public readonly string $causeClass;

    /**
     * Describe the failed attempt from its classified, already-redacted cause.
     *
     * @param string $causeClass Class name of the PSR client exception.
     * @param string $causeMessage Cause message with configured credentials redacted.
     */
    public function __construct(
        public readonly TransportFailure $failure,
        string $causeClass,
        public readonly string $causeMessage,
    ) {
        $anonymous = strpos($causeClass, '@anonymous');
        $this->causeClass = $anonymous === false ? $causeClass : substr($causeClass, 0, $anonymous + 10);
        parent::__construct('API transport failed (' . $failure->value . ' failure; ' . $this->causeClass . ').');
    }
}
