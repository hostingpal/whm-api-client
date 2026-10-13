<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Http\HttpTransport;
use InvalidArgumentException;

/**
 * Hold UAPI context for one immutable cPanel target account.
 *
 * The credential type selects direct cPanel access or execution through WHM.
 * Both routes share this handle type and will expose the same endpoint groups.
 */
final class UapiClient extends AbstractApiClient
{
    /**
     * Bind an explicit cPanel target to a configuration and transport.
     *
     * Direct-token targets must match the token owner. With WHM credentials the
     * target identifies the account to impersonate, subject to server permissions.
     *
     * @throws InvalidArgumentException If the target is invalid or mismatched.
     */
    public function __construct(
        ClientConfig $config,
        HttpTransport $transport,
        private readonly string $username,
    ) {
        TokenCredentials::validateUsername($username);

        if ($config->credentials->type === TokenType::Cpanel && $username !== $config->credentials->username) {
            throw new InvalidArgumentException('The UAPI target must match the cPanel credential account.');
        }

        parent::__construct($config, $transport);
    }

    /**
     * Return the selected cPanel account username.
     *
     * This is the target account, which can differ from the WHM authentication user.
     */
    public function username(): string
    {
        return $this->username;
    }

    /**
     * Report whether UAPI execution must use the WHM bridge.
     *
     * Routing follows the configured credential type and requires no server probe.
     */
    public function isBridged(): bool
    {
        return $this->config->credentials->type === TokenType::Whm;
    }
}
