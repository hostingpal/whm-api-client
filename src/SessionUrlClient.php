<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\Uapi\SessionUrlOperations;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Exception\TransportFailure;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\EnvelopeReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use SensitiveParameter;
use Throwable;

/**
 * Run the reviewed UAPI operation in one existing cPanel or Webmail session.
 *
 * This handle holds an authenticated URL and a single service cookie. Its metadata belongs
 * to that session. The caller supplies a PSR-18 transport with verified TLS, redirects and
 * retries disabled, bounded timeouts and no ambient credentials or cookie jar.
 */
final class SessionUrlClient
{
    use SessionUrlOperations;

    private const ENDPOINT_PATH = '~\A/cpsess[0-9]+/execute/Session/create_temp_user\z~';

    private readonly string $cookieName;
    private ?ResponseMetadata $metadata = null;

    /**
     * Bind a caller-owned authenticated endpoint to its independently trusted service origin.
     *
     * The origin is an HTTPS host (DNS, IPv4 or bracketed IPv6) with explicit service port 2083
     * or 2096. The complete URL must start with that exact origin and end with the session's
     * /cpsess<digits>/execute/Session/create_temp_user path. Pass only the service cookie value,
     * as sent on the wire, from the same authenticated session. The service selects the cookie
     * name. Construction performs no I/O and retains the opaque credentials for this handle only.
     *
     * @throws InvalidArgumentException If the URL, trusted origin or cookie is unusable.
     * @see https://api.docs.cpanel.net/specifications/cpanel.openapi/webmail-sessions/session-create_temp_user
     */
    public function __construct(
        #[SensitiveParameter] string $trustedOrigin,
        #[SensitiveParameter] private readonly string $endpointUrl,
        #[SensitiveParameter] private readonly string $cookieValue,
        #[SensitiveParameter] private readonly HttpTransport $transport,
    ) {
        if (
            preg_match('~\Ahttps://(\[[a-fA-F0-9:.]+\]|[A-Za-z0-9.-]+):(2083|2096)\z~', $trustedOrigin, $parts) !== 1
            || !str_starts_with($endpointUrl, $trustedOrigin . '/')
            || preg_match(self::ENDPOINT_PATH, substr($endpointUrl, strlen($trustedOrigin))) !== 1
            || preg_match('/\A[\x21\x23-\x2b\x2d-\x3a\x3c-\x5b\x5d-\x7e]+\z/', $cookieValue) !== 1
        ) {
            throw new InvalidArgumentException('Invalid authenticated session endpoint or cookie.');
        }
        $host = $parts[1];
        $valid = match (true) {
            str_starts_with($host, '[') => filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6),
            preg_match('/\A[0-9.]+\z/', $host) === 1 => filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
            default => filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME),
        };
        if ($valid === false) {
            throw new InvalidArgumentException('Invalid trusted session host.');
        }
        $this->cookieName = $parts[2] === '2083' ? 'cpsession' : 'webmailsession';
    }

    /**
     * Return the most recent usable UAPI envelope metadata for this session, or null before a response.
     *
     * A call to the generated operation can change this value between reads.
     */
    public function responseMeta(): ?ResponseMetadata
    {
        return $this->metadata;
    }

    /**
     * Clear prior metadata before the generated contract is checked and execute a single authenticated request.
     *
     * @param array<mixed> $spec
     * @param array<string, mixed> $arguments
     */
    private function run(array $spec, #[SensitiveParameter] array $arguments): mixed
    {
        $this->metadata = null;
        return OperationRunner::run($spec, $arguments, $this->dispatch(...));
    }

    private function dispatch(): ApiResponse
    {
        try {
            $response = $this->transport->send('POST', $this->endpointUrl, [
                'Cookie' => $this->cookieName . '=' . $this->cookieValue,
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], '');
        } catch (Throwable $cause) {
            // A factory or client may echo either the private request target or the complete Cookie header.
            throw new TransportException(
                match (true) {
                    $cause instanceof NetworkExceptionInterface => TransportFailure::Network,
                    $cause instanceof RequestExceptionInterface => TransportFailure::Request,
                    default => TransportFailure::Other,
                },
                $cause::class,
                'Authenticated session request failed.',
            );
        }

        try {
            $result = EnvelopeReader::read($response, ResponseRoute::DirectUapi, OutcomePolicy::Flag);
        } catch (ApiException $cause) {
            $this->metadata = $cause->metadata;
            throw $cause;
        }
        $this->metadata = $result->metadata;
        return $result;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Refuse persistence of the session URL and cookie. */
    public function __serialize(): never
    {
        throw new LogicException('Authenticated session state cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): never
    {
        throw new LogicException('Authenticated session state cannot be restored.');
    }
}
