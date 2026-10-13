<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

/** Report PSR transport failure without retaining a potentially secret-bearing cause. */
final class TransportException extends ApiException
{
}
