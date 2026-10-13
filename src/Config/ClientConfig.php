<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Config;

use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Authentication\TokenType;
use InvalidArgumentException;

/**
 * Holds validated, immutable client connection and account configuration.
 *
 * Host names are lowercase and IPv6 addresses are stored without brackets.
 * Credentials remain a single object; configuration performs no network I/O.
 */
final readonly class ClientConfig
{
    public string $host;
    public int $port;

    /**
     * Configures a secure API host and optional default cPanel target.
     *
     * The host must be a bare DNS name, IPv4 address or IPv6 address, with
     * optional brackets around IPv6. A null port selects 2087 for WHM or 2083
     * for cPanel; an explicit port must be between 1 and 65535. A null default
     * target leaves account selection explicit. A provided target is validated
     * and must match the credential owner for direct cPanel authentication.
     *
     * @throws InvalidArgumentException If the host, port or default account is invalid.
     */
    public function __construct(
        string $host,
        public TokenCredentials $credentials,
        ?int $port = null,
        public ?string $defaultCpanelUser = null,
    ) {
        $this->host = self::normalizeHost($host);
        $this->port = $port ?? ($credentials->type === TokenType::Whm ? 2087 : 2083);

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('Port must be between 1 and 65535.');
        }

        if ($defaultCpanelUser !== null) {
            TokenCredentials::validateUsername($defaultCpanelUser);
            if ($credentials->type === TokenType::Cpanel && $defaultCpanelUser !== $credentials->username) {
                throw new InvalidArgumentException(
                    'The default cPanel account must match the cPanel credential account.',
                );
            }
        }
    }

    /**
     * Returns the HTTPS origin for API requests.
     *
     * The resolved port is always included and IPv6 addresses are bracketed.
     * Operation paths and query parameters are added by the caller.
     */
    public function baseUri(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

        return 'https://' . $host . ':' . $this->port;
    }

    /**
     * Validates and normalizes a host without accepting URI components.
     *
     * Brackets are removed only from valid IPv6 addresses. Schemes, userinfo,
     * embedded ports, paths, queries and fragments are rejected.
     *
     * @throws InvalidArgumentException If the host is invalid.
     */
    private static function normalizeHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Host must be a bare DNS name or IP address.');
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if (
                preg_match('/\A(?:[0-9]+\.){3}[0-9]+\z/', $host) === 1
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            ) {
                throw new InvalidArgumentException('Host must be a bare DNS name or IP address.');
            }
        }

        return strtolower($host);
    }
}
