<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

/**
 * Report malformed JSON, an unusable envelope, or an invalid endpoint payload.
 *
 * Generated operations raise the PayloadMismatchException subclass when only the
 * payload differs from its contract after the envelope was accepted.
 */
class InvalidResponseException extends ApiException
{
}
