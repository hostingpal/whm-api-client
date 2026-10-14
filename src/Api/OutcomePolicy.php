<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

/**
 * Declare, per request, how the API envelope's outcome field is interpreted.
 *
 * Flag applies the documented integer convention of the pinned WHM and UAPI
 * contracts: WHM metadata.result and UAPI status are 1 for success and 0 for
 * failure (ApiErrorException); any other value is an invalid response. Every
 * typed operation group declares Flag. Unchecked records the envelope metadata
 * without interpreting the outcome, for operations whose status field carries
 * payload semantics (UAPI Features/has_feature reports feature state there) or
 * when the caller inspects responseMeta() itself. HTTP failures are reported
 * under both policies, and both still require a usable envelope.
 */
enum OutcomePolicy
{
    case Flag;
    case Unchecked;
}
