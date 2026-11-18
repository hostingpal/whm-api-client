<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Config;

use InvalidArgumentException;
use SensitiveParameter;

/** @internal Shared validation for bare DNS names and IP literals in API service origins. */
final class HostName
{
    /**
     * Validates and normalizes a host without accepting URI components.
     *
     * Brackets are removed only from valid IPv6 addresses. Schemes, userinfo,
     * embedded ports, paths, queries and fragments are rejected.
     *
     * @throws InvalidArgumentException If the host is invalid.
     */
    public static function normalize(#[SensitiveParameter] string $host): string
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
