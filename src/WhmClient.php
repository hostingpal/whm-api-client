<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Http\HttpTransport;
use LogicException;

/**
 * Hold WHM configuration and the metadata state for WHM operations.
 *
 * Only WHM credentials are accepted. Endpoint groups will build on this handle;
 * constructing it performs no authentication request or other network I/O.
 */
final class WhmClient extends AbstractApiClient
{
    /**
     * Bind a WHM configuration to its HTTP transport.
     *
     * Credentials are checked before accepting the context. The supplied transport
     * remains responsible for PSR HTTP behavior when an executor later uses it.
     *
     * @throws LogicException If the credentials are not a WHM token.
     */
    public function __construct(ClientConfig $config, HttpTransport $transport)
    {
        if ($config->credentials->type !== TokenType::Whm) {
            throw new LogicException('WHM access requires WHM credentials.');
        }

        parent::__construct($config, $transport);
    }
}
