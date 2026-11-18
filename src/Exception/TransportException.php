<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use SensitiveParameter;

/**
 * Report PSR transport failure without retaining a potentially secret-bearing cause.
 *
 * The original exception is never chained because it can hold the authenticated
 * request. Its classification and class name are kept for diagnosis. Token
 * handles retain the original message with their token redacted. A session URL
 * handle reports a recognized cURL DNS, timeout or certificate diagnostic, or a
 * generic message. Read causeMessage deliberately because token routes may include
 * the request URL and upstream host details.
 */
final class TransportException extends ApiException
{
    /**
     * Class name of the PSR client exception; anonymous classes are shortened to
     * "Parent@anonymous" so no NUL byte or source path reaches messages.
     */
    public readonly string $causeClass;

    /** Classify a PSR-18 failure without retaining its original request, stack or unsanitized message. */
    public static function fromClient(
        #[SensitiveParameter] ClientExceptionInterface $cause,
        #[SensitiveParameter] string $safeMessage,
    ): self {
        return new self(
            match (true) {
                $cause instanceof NetworkExceptionInterface => TransportFailure::Network,
                $cause instanceof RequestExceptionInterface => TransportFailure::Request,
                default => TransportFailure::Other,
            },
            $cause::class,
            $safeMessage,
        );
    }

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
