<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

/** @internal Select the documented response envelope for the implemented read-only slice. */
enum ResponseRoute
{
    case Whm;
    case DirectUapi;
    case BridgedUapi;
}
