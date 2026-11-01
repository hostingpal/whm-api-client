<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use CurlHandle;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Development-only incremental transport for one Fileman multipart upload on the designated server.
 *
 * CurlClient casts its body to a string, so it cannot demonstrate streaming; it stays unchanged. This client
 * never casts, rewinds, seeks or pre-scans the body: a read callback pulls at most 8192 bytes at a time.
 * CURLOPT_POST with a read callback would select chunked framing, which the reviewer report shows cPanel's
 * form parser rejects, and PHP does not export CURLOPT_POSTFIELDSIZE. CURLOPT_UPLOAD with an explicit
 * CURLOPT_INFILESIZE and a custom POST verb instead sends the exact Content-Length. No seek callback exists,
 * so libcurl cannot rewind, and a fresh forbidden-reuse connection leaves it nothing to replay. A short or
 * failing source aborts locally, possibly after partial delivery, and is never retried.
 *
 * Every send requires WHM_API_LIVE=1 and WHM_API_LIVE_MUTATIONS=1. TLS verification is mandatory, redirects
 * are disabled and connect/total timeouts are 10/30 seconds. Failures expose only a fixed diagnostic with no
 * URL, header, payload or chained cause. The sent request head is inspected in memory for lastFraming().
 *
 * @phpstan-import-type Framing from StreamingTransport
 */
final class StreamingCurlClient implements StreamingTransport
{
    private const READ_LIMIT = 8192;

    /** @var Framing|null */
    private ?array $framing = null;

    /** Execute one opted-in, bounded HTTPS multipart POST to the fixed upload route, without retries. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->framing = null;
        if (getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1') {
            throw new LogicException('Streaming transport requires both live opt-ins.');
        }
        $uri = $request->getUri();
        $body = $request->getBody();
        $size = $body->getSize();
        if (
            $request->getMethod() !== 'POST' || $uri->getScheme() !== 'https' || $uri->getHost() !== 'test.oblak.host'
            || $uri->getPort() !== 2083 || $uri->getPath() !== '/execute/Fileman/upload_files'
            || $uri->getUserInfo() !== '' || $uri->getFragment() !== ''
            || preg_match(
                '/\Amultipart\/form-data; boundary=[A-Za-z0-9_-]{20,70}\z/',
                $request->getHeaderLine('Content-Type'),
            ) !== 1
            || $size === null || $size < 1 || $request->getHeader('Content-Length') !== [(string) $size]
            || $request->hasHeader('Transfer-Encoding') || $request->hasHeader('Expect')
            || $body->isSeekable() || !$body->isReadable()
        ) {
            throw new LogicException('Request outside the streaming transport boundary.');
        }
        if (!extension_loaded('curl')) {
            throw new LogicException('Live verification requires the PHP cURL extension.');
        }

        $curl = curl_init((string) $uri);
        if ($curl === false) {
            throw self::failure();
        }
        /** @var array<string, list<string>> $headers */
        $headers = [];
        $protocol = '1.1';
        $reason = '';
        $sent = 0;
        try {
            $options = [
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_INFILESIZE => $size,
                CURLOPT_READFUNCTION => static function (
                    #[SensitiveParameter] CurlHandle $handle,
                    #[SensitiveParameter] mixed $stream,
                    int $length,
                ) use (
                    $body,
                    $size,
                    &$sent
): string {
                    if ($sent === $size) {
                        return '';
                    }
                    $bytes = $body->read(min($length, self::READ_LIMIT, $size - $sent));
                    if ($bytes === '') {
                        throw new RuntimeException('Streaming body ended before its declared length.');
                    }
                    $sent += strlen($bytes);

                    return $bytes;
                },
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $request->getHeaderLine('Authorization'),
                    'Accept: application/json',
                    'Content-Type: ' . $request->getHeaderLine('Content-Type'),
                    'Expect:',
                ],
                CURLOPT_HEADERFUNCTION => static function (
                    #[SensitiveParameter] CurlHandle $handle,
                    #[SensitiveParameter] string $line,
                ) use (
                    &$headers,
                    &$protocol,
                    &$reason
): int {
                    if (preg_match('/\AHTTP\/([0-9.]+) [0-9]{3}(?: ([^\r\n]*))?/', $line, $matches) === 1) {
                        // Discard interim response headers in favor of the final block.
                        $headers = [];
                        $protocol = $matches[1];
                        $reason = $matches[2] ?? '';
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[$name] ??= [];
                        $headers[$name][] = trim($value);
                    }

                    return strlen($line);
                },
            ];
            if (!curl_setopt_array($curl, $options)) {
                throw self::failure();
            }
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (!is_string($response) || $status < 100 || $status > 599) {
                throw self::failure();
            }
            $this->framing = self::framing(
                curl_getinfo($curl, CURLINFO_HEADER_OUT),
                $size,
                $sent,
                curl_getinfo($curl, CURLINFO_SIZE_UPLOAD_T),
            );

            return new Response($status, $headers, $response, $protocol, $reason);
        } catch (Throwable) {
            throw self::failure();
        } finally {
            curl_close($curl);
        }
    }

    public function lastFraming(): ?array
    {
        return $this->framing;
    }

    /**
     * Reduce the sent request head to booleans, so the Authorization line goes no further.
     *
     * @return Framing
     */
    private static function framing(
        #[SensitiveParameter] mixed $head,
        int $size,
        int $sent,
        mixed $uploaded,
    ): array {
        $lines = is_string($head) ? explode("\r\n", $head) : [];
        $named = static fn (string $name): array => array_values(array_filter(
            $lines,
            static fn (string $line): bool => stripos($line, $name . ':') === 0,
        ));
        $lengths = $named('content-length');

        return [
            'http_1_1' => str_ends_with($lines[0] ?? '', ' HTTP/1.1'),
            'content_length_exact' => count($lengths) === 1
                && preg_match('/\Acontent-length: *' . $size . ' *\z/i', $lengths[0]) === 1,
            'transfer_encoding_absent' => $named('transfer-encoding') === [],
            'expect_absent' => $named('expect') === [],
            'bytes_sent_exact' => $sent === $size && $uploaded === $size,
        ];
    }

    /** Fixed diagnostics keep transport details and arbitrary upstream text private. */
    private static function failure(): RuntimeException&ClientExceptionInterface
    {
        return new class ('Live HTTPS transport failed.') extends RuntimeException implements ClientExceptionInterface {
        };
    }
}
