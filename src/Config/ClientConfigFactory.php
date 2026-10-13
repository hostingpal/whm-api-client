<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Config;

use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Authentication\TokenType;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Builds immutable configuration from validated client options.
 *
 * ClientOptions describes accepted configuration. Runtime validation also
 * accepts an untrusted options bag so malformed values receive explicit errors.
 *
 * @phpstan-type ClientOptions array{
 *     host: string,
 *     token: string,
 *     authType: 'whm'|'cpanel',
 *     username?: string,
 *     port?: int,
 *     defaultCpanelUser?: string
 * }
 */
final readonly class ClientConfigFactory
{
    /**
     * Validates options and creates the client configuration.
     *
     * Host, token and authType are required. An omitted username defaults to
     * root only for WHM; cPanel requires an explicit account. An omitted port
     * selects the credential type's default. defaultCpanelUser is optional and
     * never inferred from the authentication username. Unknown keys, nulls,
     * incorrect types and invalid values are rejected without echoing input.
     *
     * @param array<string, mixed> $options Untrusted input validated against the ClientOptions shape.
     *
     * @throws InvalidArgumentException If any option is unknown, missing or invalid.
     */
    public static function fromArray(#[SensitiveParameter] array $options): ClientConfig
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, ['host', 'token', 'authType', 'username', 'port', 'defaultCpanelUser'], true)) {
                throw new InvalidArgumentException('Configuration contains an unknown option.');
            }
        }

        foreach (['host', 'token', 'authType'] as $key) {
            if (!array_key_exists($key, $options) || !is_string($options[$key])) {
                throw new InvalidArgumentException('Host, token and authType must be provided as strings.');
            }
        }

        if (array_key_exists('username', $options) && !is_string($options['username'])) {
            throw new InvalidArgumentException('Username must be a string when provided.');
        }

        if (array_key_exists('defaultCpanelUser', $options) && !is_string($options['defaultCpanelUser'])) {
            throw new InvalidArgumentException('The default cPanel account must be a string when provided.');
        }

        if (array_key_exists('port', $options) && !is_int($options['port'])) {
            throw new InvalidArgumentException('Port must be an integer when provided.');
        }

        $type = TokenType::tryFrom($options['authType']);
        if ($type === null) {
            throw new InvalidArgumentException('authType must be whm or cpanel.');
        }

        if ($type === TokenType::Cpanel && !array_key_exists('username', $options)) {
            throw new InvalidArgumentException('cPanel credentials require an explicit username.');
        }

        return new ClientConfig(
            $options['host'],
            new TokenCredentials($type, $options['username'] ?? 'root', $options['token']),
            $options['port'] ?? null,
            $options['defaultCpanelUser'] ?? null,
        );
    }
}
