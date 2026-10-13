<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Authentication;

/**
 * Identifies the API that issued a token.
 *
 * The configured type selects the authorization scheme and default HTTPS
 * port; it is explicit rather than inferred from the token's contents.
 */
enum TokenType: string
{
    case Whm = 'whm';
    case Cpanel = 'cpanel';
}
