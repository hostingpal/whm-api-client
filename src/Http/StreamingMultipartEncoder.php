<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Http;

use Closure;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Prepare one bounded multipart body without reading or prescanning sources.
 *
 * The separate string-only SSL encoder retains its complete-string preflight.
 * Callers above this internal boundary own route/query/reservation validation.
 *
 * @internal
 */
final class StreamingMultipartEncoder
{
    /**
     * Validate all metadata, then prepare all sources and the exact body length.
     *
     * The optional generator is an internal deterministic testing seam, never a
     * public API boundary override. Production boundaries contain 192 random bits.
     * Only known text fields are inspected when selecting a boundary. File data
     * is checked incrementally by the body, and a failure can follow partial send.
     *
     * @param array<array-key, mixed> $parts Ordered name/value records, with string|Upload values.
     * @param (Closure(): string)|null $boundaryGenerator
     */
    public static function encode(
        #[SensitiveParameter] array $parts,
        #[SensitiveParameter] ?Closure $boundaryGenerator = null,
    ): StreamingMultipartBody {
        if (!array_is_list($parts)) {
            throw new InvalidArgumentException('Multipart parts must be a sequential list.');
        }
        $uploads = [];
        $validated = [];
        foreach ($parts as $part) {
            if (
                !is_array($part) || count($part) !== 2 || !isset($part['name'])
                || !is_string($part['name']) || preg_match('/\A[A-Za-z0-9_.-]+\z/', $part['name']) !== 1
                || !isset($part['value']) || (!is_string($part['value']) && !$part['value'] instanceof Upload)
            ) {
                throw new InvalidArgumentException('Multipart parts require safe names and string or upload values.');
            }
            $validated[] = ['name' => $part['name'], 'value' => $part['value']];
            if ($part['value'] instanceof Upload) {
                $uploads[] = $part['value'];
            }
        }
        $boundaryGenerator ??= static fn (): string => 'whm-api-' . bin2hex(random_bytes(24));
        do {
            $boundary = $boundaryGenerator();
            if (preg_match('/\A[A-Za-z0-9_-]{1,70}\z/', $boundary) !== 1) {
                throw new InvalidArgumentException('Multipart boundary generator returned an invalid token.');
            }
            $collision = false;
            foreach ($validated as $part) {
                if (is_string($part['value']) && str_contains($part['value'], $boundary)) {
                    $collision = true;
                    break;
                }
            }
        } while ($collision);

        $sources = PreparedUploadSet::prepare($uploads);
        try {
            $prepared = $sources->sources();
            $segments = [];
            $size = 0;
            $sourceIndex = 0;
            foreach ($validated as $part) {
                $header = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $part['name'] . '"';
                if ($part['value'] instanceof Upload) {
                    $value = $prepared[$sourceIndex++];
                    $header .= '; filename="' . $value->filename() . "\"\r\nContent-Type: " . $value->contentType();
                    $length = $value->size();
                } else {
                    $value = $part['value'];
                    $length = strlen($value);
                }
                $header .= "\r\n\r\n";
                $segments[] = $header;
                $segments[] = $value;
                $segments[] = "\r\n";
                $size = self::add($size, strlen($header));
                $size = self::add($size, $length);
                $size = self::add($size, 2);
            }
            $closing = '--' . $boundary . "--\r\n";
            $segments[] = $closing;
            $size = self::add($size, strlen($closing));

            return StreamingMultipartBody::create($sources, $segments, $boundary, $size);
        } catch (Throwable) {
            $sources->release();
            throw new RuntimeException('Multipart body could not be prepared.');
        }
    }

    private static function add(int $total, int $length): int
    {
        if ($length < 0 || $total > PHP_INT_MAX - $length) {
            throw new RuntimeException('Multipart body length is invalid.');
        }
        return $total + $length;
    }
}
