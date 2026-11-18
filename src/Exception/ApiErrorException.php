<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

/**
 * Report a documented failing WHM or UAPI outcome, including HTTP 200 failures.
 *
 * Catch this base class for API rejections and operation-specific failures such as ZoneRebuildException.
 */
class ApiErrorException extends ApiException
{
}
