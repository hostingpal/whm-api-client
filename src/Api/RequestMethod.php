<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

/**
 * Select how an operation's parameters reach the server.
 *
 * Get places every parameter in the URL query string. Post keeps the routing
 * fields (WHM api.version and the bridge's cpanel.* fields) in the query string
 * and sends the operation parameters as an application/x-www-form-urlencoded
 * body, matching the pinned WHM OpenAPI example "POST /json-api/<function>?api.version=1"
 * and the pinned UAPI tutorials' form-encoded POST calls. The pinned WHM
 * documentation notes that the server records the URL query string in its access
 * log, so secret-bearing parameters (passwords, keys) must use Post.
 */
enum RequestMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
}
