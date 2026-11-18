<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use Error;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\Uapi\SessionUrlOperations;
use HostingPal\WhmApiClient\Config\HostName;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\EnvelopeReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;
use TypeError;
use ValueError;

/**
 * Run the reviewed UAPI operation in one existing cPanel or Webmail session.
 *
 * This handle holds an authenticated URL and a single service cookie. Its metadata belongs
 * to that session. Configure the transport to verify TLS, disable redirects and retries,
 * enforce timeouts and omit ambient credentials and cookies. cPanel 11.138.0.24 refused
 * this operation through its Webmail AppSafe allowlist after successful authentication.
 */
final class SessionUrlClient
{
    use SessionUrlOperations;

    private readonly string $operationPath;

    private readonly string $cookieName;
    /** @var SensitiveParameterValue<array{url: string, cookie: string, transport: HttpTransport}> */
    private readonly SensitiveParameterValue $connection;

    /** @var SensitiveParameterValue<ResponseMetadata>|null Upstream messages can include the session credentials. */
    private ?SensitiveParameterValue $metadata = null;

    /**
     * Bind a caller-owned authenticated endpoint to its independently trusted service origin.
     *
     * The origin is an HTTPS host (DNS, IPv4 or bracketed IPv6) with explicit service port 2083
     * or 2096. The endpoint must match this origin (scheme and host are case-insensitive) and
     * its path must be /cpsess<digits>/execute/Session/create_temp_user. Pass only the service cookie value,
     * as sent on the wire, from the same authenticated session. The service selects the cookie
     * name. Construction performs no I/O and retains the opaque credentials for this handle only.
     *
     * @throws InvalidArgumentException If the URL, trusted origin or cookie is unusable.
     * @see https://api.docs.cpanel.net/specifications/cpanel.openapi/webmail-sessions/session-create_temp_user
     */
    public function __construct(
        #[SensitiveParameter] string $trustedOrigin,
        #[SensitiveParameter] string $endpointUrl,
        #[SensitiveParameter] string $cookieValue,
        #[SensitiveParameter] HttpTransport $transport,
    ) {
        if (
            preg_match('~\Ahttps://(\[[a-f0-9:.]+\]|[a-z0-9.-]+):(2083|2096)\z~i', $trustedOrigin, $parts) !== 1
            || strncasecmp($endpointUrl, $trustedOrigin . '/', strlen($trustedOrigin) + 1) !== 0
            || preg_match('~\A/cpsess[0-9]+(/execute/[A-Za-z0-9_]+/[A-Za-z0-9_]+)\z~', substr(
                $endpointUrl,
                strlen($trustedOrigin),
            ), $path) !== 1
            || !in_array($path[1], self::SESSION_PATHS, true)
            || preg_match('/\A[\x21\x23-\x2b\x2d-\x3a\x3c-\x5b\x5d-\x7e]+\z/', $cookieValue) !== 1
        ) {
            throw new InvalidArgumentException('Invalid authenticated session endpoint or cookie.');
        }
        $host = HostName::normalize($parts[1]);
        // Some HTTP clients interpret shortened, octal, integer or hexadecimal IPv4 hostnames as addresses.
        if (
            str_ends_with($host, '.')
            || (preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/', $host) === 1
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
        ) {
            throw new InvalidArgumentException('Invalid trusted session host.');
        }
        // A transport may retain its last request. Keep it behind the same export boundary as the credentials.
        $this->connection = new SensitiveParameterValue([
            'url' => $endpointUrl,
            'cookie' => $cookieValue,
            'transport' => $transport,
        ]);
        $this->operationPath = $path[1];
        $this->cookieName = $parts[2] === '2083' ? 'cpsession' : 'webmailsession';
    }

    /**
     * Return the most recent usable UAPI envelope metadata for this session, or null before a response.
     *
     * A call to the generated operation can change this value between reads.
     */
    public function responseMeta(): ?ResponseMetadata
    {
        return $this->metadata?->getValue();
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

    /** @param array<mixed> $spec */
    private function dispatch(array $spec, #[SensitiveParameter] OrderedFields $fields): ApiResponse
    {
        $path = '/execute/' . OperationSpec::text($spec, 'module') . '/' . OperationSpec::text($spec, 'function');
        if ($path !== $this->operationPath) {
            throw new LogicException('Generated operation differs from the bound session endpoint.');
        }
        $fields->assertNoReserved([]);
        $method = OperationSpec::method($spec);
        $outcome = OperationSpec::outcome($spec);
        $connection = $this->connection->getValue();
        try {
            $response = $connection['transport']->send($method->value, $connection['url'], [
                'Cookie' => $this->cookieName . '=' . $connection['cookie'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], $fields->encode());
        } catch (ClientExceptionInterface $cause) {
            throw TransportException::fromClient($cause, self::safeDiagnostic($cause->getMessage()));
        } catch (Throwable $cause) {
            // Factory and client bugs may retain the request or quote its URL or cookie.
            $message = 'HTTP implementation failed while preparing or sending the session request.';
            throw match (true) {
                $cause instanceof TypeError => new TypeError($message),
                $cause instanceof ValueError => new ValueError($message),
                $cause instanceof Error => new Error($message),
                $cause instanceof LogicException => new LogicException($message),
                default => new RuntimeException($message),
            };
        }

        return EnvelopeReader::readAndRecord(
            $response,
            ResponseRoute::DirectUapi,
            $outcome,
            function (ResponseMetadata $metadata): void {
                $this->metadata = new SensitiveParameterValue($metadata);
            },
        );
    }

    /** Report recognized transport diagnoses without copying any request-derived bytes into the exception. */
    private static function safeDiagnostic(#[SensitiveParameter] string $message): string
    {
        return match (true) {
            preg_match('/\Acurl error (?:51|60):/i', $message) === 1 => 'TLS certificate verification failed.',
            preg_match('/\Acurl error 28:/i', $message) === 1 => 'Session request timed out.',
            preg_match('/\Acurl error 6:/i', $message) === 1 => 'Host resolution failed.',
            default => 'Authenticated session request failed.',
        };
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
