<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Authentication;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Holds validated token credentials for one authentication account.
 *
 * Credentials are immutable. Debug output redacts the token, while the
 * authorization header exposes it for the HTTP transport to send. Serialization
 * is refused so tokens cannot leak into caches, queues, or sessions; var_export()
 * cannot be intercepted and must not be used on credentials or configuration.
 */
final readonly class TokenCredentials
{
    /**
     * Creates credentials for the specified token type and owner.
     *
     * The username must be nonempty lowercase ASCII letters, digits or
     * underscores. The token must be nonempty and contain no ASCII whitespace
     * or control characters; invalid values are never included in errors.
     *
     * @throws InvalidArgumentException If the username or token is invalid.
     */
    public function __construct(
        public TokenType $type,
        public string $username,
        #[SensitiveParameter]
        private string $token,
    ) {
        self::validateUsername($username);

        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new InvalidArgumentException('Token must be nonempty and contain no ASCII whitespace or controls.');
        }
    }

    /**
     * Validates an authentication or target account username.
     *
     * Accounts use nonempty lowercase ASCII letters, digits or underscores;
     * values are neither trimmed nor normalized.
     *
     * @throws InvalidArgumentException If the username is invalid.
     */
    public static function validateUsername(string $username): void
    {
        if (preg_match('/\A[a-z0-9_]+\z/', $username) !== 1) {
            throw new InvalidArgumentException(
                'Username must contain only lowercase ASCII letters, digits or underscores.',
            );
        }
    }

    /**
     * Returns the token authorization header value.
     *
     * The configured type selects the whm or cpanel scheme, followed by the
     * credential owner's username and token separated by a colon.
     *
     * @see https://api.docs.cpanel.net/whm/tokens
     * @see https://api.docs.cpanel.net/cpanel/tokens
     */
    public function authorizationHeader(): string
    {
        return $this->type->value . ' ' . $this->username . ':' . $this->token;
    }

    /**
     * Replaces raw and common encoded occurrences of the token in diagnostic text.
     *
     * Covers RFC 3986 and form URL encoding (upper- or lowercase hex escapes)
     * and the JSON string form. Used before retaining third-party messages, such
     * as transport exception messages, that could echo the authenticated request.
     */
    public function redact(string $text): string
    {
        $encoded = rawurlencode($this->token);
        $forms = [
            $this->token,
            $encoded,
            urlencode($this->token),
            preg_replace_callback(
                '/%[0-9A-F]{2}/',
                static fn (array $hex): string => strtolower($hex[0]),
                $encoded,
            ) ?? $encoded,
        ];
        $json = json_encode($this->token);
        if (is_string($json)) {
            $forms[] = substr($json, 1, -1);
        }
        $forms = array_values(array_unique($forms));
        usort($forms, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return str_replace($forms, '[REDACTED]', $text);
    }

    /**
     * Returns safe credential details for debug output.
     *
     * The token field contains a redaction marker rather than the stored token.
     *
     * @return array{type: string, username: string, token: string}
     */
    public function __debugInfo(): array
    {
        return ['type' => $this->type->value, 'username' => $this->username, 'token' => '[REDACTED]'];
    }

    /**
     * Refuse serialization so the token never reaches caches, queues, or sessions.
     *
     * Configuration objects holding these credentials inherit the refusal.
     *
     * @return array<string, mixed>
     * @throws LogicException Always.
     */
    public function __serialize(): array
    {
        throw new LogicException('Token credentials cannot be serialized.');
    }

    /**
     * Refuse restoring credentials from serialized data.
     *
     * @param array<string, mixed> $data
     * @throws LogicException Always.
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Token credentials cannot be unserialized.');
    }
}
