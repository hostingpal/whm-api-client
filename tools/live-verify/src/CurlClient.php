<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use CurlHandle;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Development-only, single-attempt transport for the designated test server.
 *
 * Every send requires WHM_API_LIVE=1 and PHP cURL. TLS peer/host verification is
 * mandatory, redirects are disabled, and connect/total timeouts are 10/30 seconds.
 * GET is the default; explicitly enabled form or multipart POST also requires
 * WHM_API_LIVE_MUTATIONS=1. RequestGuard or a fixture-specific guard supplies the
 * narrower operation/account/argument restriction.
 * Response status, headers and body are retained; transport failures expose only
 * a fixed diagnostic, with no raw URL, headers, payload or chained cURL cause.
 */
final readonly class CurlClient implements ClientInterface
{
    /** Enable form or multipart POST only for fixture clients protected by their own exact request guard. */
    public function __construct(private bool $allowFormPost = false, private bool $allowMultipartPost = false)
    {
    }

    /** Execute one opted-in, bounded HTTPS GET or authorized form/multipart POST without retries. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        if (getenv('WHM_API_LIVE') !== '1') {
            throw new LogicException('Live verification requires WHM_API_LIVE=1.');
        }

        $uri = $request->getUri();
        $method = $request->getMethod();
        $requestBody = (string) $request->getBody();
        $formPost = $method === 'POST'
            && $this->allowFormPost
            && getenv('WHM_API_LIVE_MUTATIONS') === '1'
            && $request->getHeaderLine('Content-Type') === 'application/x-www-form-urlencoded';
        $multipartPost = $method === 'POST' && $this->allowMultipartPost
            && getenv('WHM_API_LIVE_MUTATIONS') === '1'
            && preg_match(
                '/\Amultipart\/form-data; boundary=[A-Za-z0-9_-]{20,70}\z/',
                $request->getHeaderLine('Content-Type'),
            ) === 1;
        $post = $formPost || $multipartPost;
        if (
            ($method !== 'GET' && !$post)
            || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'test.oblak.host'
            || !in_array($uri->getPort(), [2083, 2087], true)
            || $uri->getUserInfo() !== ''
            || $uri->getFragment() !== ''
            || ($method === 'GET' && $requestBody !== '')
        ) {
            throw new LogicException('Request outside the live transport boundary.');
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
        try {
            $requestHeaders = [
                'Authorization: ' . $request->getHeaderLine('Authorization'),
                'Accept: application/json',
            ];
            if ($post) {
                $requestHeaders[] = 'Content-Type: ' . $request->getHeaderLine('Content-Type');
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_HEADERFUNCTION => static function (
                    #[SensitiveParameter] CurlHandle $handle,
                    #[SensitiveParameter] string $line,
                ) use (
                    &$headers,
                    &$protocol,
                    &$reason
): int {
                    if (preg_match('/\AHTTP\/([0-9.]+) [0-9]{3}(?: ([^\r\n]*))?/', $line, $matches) === 1) {
                        // Discard CONNECT/interim response headers in favor of the final block.
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
            if ($post) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $requestBody;
            } else {
                $options[CURLOPT_HTTPGET] = true;
            }
            $configured = curl_setopt_array($curl, $options);
            if (!$configured) {
                throw self::failure();
            }
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (!is_string($body) || $status < 100 || $status > 599) {
                throw self::failure();
            }

            return new Response($status, $headers, $body, $protocol, $reason);
        } catch (Throwable) {
            throw self::failure();
        } finally {
            curl_close($curl);
        }
    }

    /** Fixed diagnostics keep transport details and arbitrary upstream text private. */
    private static function failure(): RuntimeException&ClientExceptionInterface
    {
        return new class ('Live HTTPS transport failed.') extends RuntimeException implements ClientExceptionInterface {
        };
    }
}
