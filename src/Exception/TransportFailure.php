<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

/**
 * Classify a PSR-18 transport failure by the interface its cause implements.
 *
 * Network failures are those the client reports through NetworkExceptionInterface,
 * typically connection, DNS, and timeout problems; request failures are reported
 * through RequestExceptionInterface. Classification follows the client: Guzzle,
 * for example, can report certificate verification failures as request failures, so
 * inspect causeMessage before assuming a cause. Other failures are PSR client
 * exceptions implementing neither specialization.
 *
 * @see https://www.php-fig.org/psr/psr-18/#error-handling
 */
enum TransportFailure: string
{
    case Network = 'network';
    case Request = 'request';
    case Other = 'other';
}
