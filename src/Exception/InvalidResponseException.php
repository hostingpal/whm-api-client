<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

/** Report malformed JSON, an unusable envelope, or an invalid endpoint payload. */
final class InvalidResponseException extends ApiException
{
}
