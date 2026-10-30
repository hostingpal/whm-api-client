<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Encode reviewed multipart string fields without altering their bytes.
 *
 * Fields are inline values, not filesystem paths or uploaded-file handles. Names
 * are restricted to safe token characters so they cannot inject part headers.
 * A random boundary is regenerated if it occurs in any field value; this local
 * encoding step never retries an HTTP request. No values appear in diagnostics.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 * @internal
 */
final class MultipartEncoder
{
    /**
     * Return the Content-Type and CRLF-framed body for one request.
     *
     * Empty strings remain explicit fields; an empty field set still includes the
     * closing delimiter. Values are validated before boundary selection or I/O.
     *
     * @param Parameters $parameters
     * @return array{string, string}
     * @throws InvalidArgumentException If a field is not a string or its name is unsafe.
     */
    public static function encode(#[SensitiveParameter] array $parameters): array
    {
        foreach ($parameters as $name => $value) {
            if (preg_match('/\A[A-Za-z0-9_.-]+\z/', (string) $name) !== 1 || !is_string($value)) {
                throw new InvalidArgumentException('Multipart fields require safe names and string values.');
            }
        }
        do {
            $boundary = 'whm-api-' . bin2hex(random_bytes(24));
        } while (self::collides($parameters, $boundary));

        $body = '';
        foreach ($parameters as $name => $value) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n"
                . $value . "\r\n";
        }

        return ['multipart/form-data; boundary=' . $boundary, $body . '--' . $boundary . "--\r\n"];
    }

    /**
     * Check all string values for a candidate delimiter without logging them.
     *
     * @param array<string, string> $parameters
     */
    private static function collides(#[SensitiveParameter] array $parameters, string $boundary): bool
    {
        foreach ($parameters as $value) {
            if (str_contains($value, $boundary)) {
                return true;
            }
        }
        return false;
    }
}
