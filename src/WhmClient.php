<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Api\Whm\WhmGroups;
use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\CallResult;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Hold WHM configuration and the metadata state for WHM operations.
 *
 * Only WHM credentials are accepted. Endpoint groups share this handle's metadata;
 * constructing it performs no authentication request or other network I/O.
 * Typed groups are generated from the pinned API map and overlay (see docs/api/coverage.md);
 * call() reaches every documented WHM API 1 function through the same routing,
 * authentication, and envelope handling.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 */
final class WhmClient extends AbstractApiClient
{
    use WhmGroups;

    /** The API version is fixed; callers cannot override it in either request method. */
    private const RESERVED = ['api.version' => 1];

    /**
     * Bind a WHM configuration to its HTTP transport.
     *
     * Credentials are checked before accepting the context. The supplied transport
     * remains responsible for PSR HTTP behavior, TLS verification, and disabling redirects.
     *
     * @throws LogicException If the credentials are not a WHM token.
     */
    public function __construct(ClientConfig $config, HttpTransport $transport)
    {
        if ($config->credentials->type !== TokenType::Whm) {
            throw new LogicException('WHM access requires WHM credentials.');
        }

        parent::__construct($config, $transport);
    }

    /**
     * Execute any documented WHM API 1 function without a typed group.
     *
     * The function name is the documented path segment after /json-api/ (such as
     * listaccts or 360Monitoring/webpros_auth_callback); api.version=1 is always
     * added and cannot be overridden. Post, the default, form-encodes the parameters
     * in the body and keeps api.version in the query string, so passwords and other
     * secrets never reach the URL that the server records in its access log; POST
     * was verified live for read-only functions. Pass Get explicitly to send every
     * parameter in the query string, never for a secret-bearing parameter. The
     * result's data is the envelope payload converted to arrays without schema
     * validation; metadata, exceptions, and responseMeta() behave as for typed
     * operations. Flag applies the documented metadata.result 1/0 rule; Unchecked
     * leaves the outcome to the caller. Typed groups remain the reviewed contracts.
     *
     * @param Parameters $parameters
     * @throws InvalidArgumentException If the function name or a parameter value is invalid; responseMeta() is
     *     cleared first, so it never reports an earlier request.
     *     Messages may name a parameter but never include its value.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     * @see https://api.docs.cpanel.net/whm/introduction
     */
    public function call(
        string $function,
        #[SensitiveParameter]
        array $parameters = [],
        RequestMethod $method = RequestMethod::Post,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): CallResult {
        $this->beginRequest();
        if (preg_match('/\A[A-Za-z0-9_]+(?:\/[A-Za-z0-9_]+)*\z/', $function) !== 1) {
            throw new InvalidArgumentException(
                'WHM function names contain only letters, digits, underscores, and "/" separators.',
            );
        }

        return CallResult::fromApiResponse($this->request($function, $parameters, $method, $outcome));
    }

    /**
     * Run a generated operation spec through this handle.
     *
     * Generated groups receive this method as their run callback; selecting a
     * group performs no I/O.
     *
     * @param array<mixed> $spec
     * @param array<string, mixed> $arguments
     */
    private function run(array $spec, #[SensitiveParameter] array $arguments): mixed
    {
        return OperationRunner::run($spec, $arguments, $this->dispatch(...));
    }

    /**
     * Route a generated operation to WHM API 1 with the spec's method and outcome policy.
     *
     * @param array<mixed> $spec
     * @param Parameters $parameters
     */
    private function dispatch(array $spec, #[SensitiveParameter] array $parameters): ApiResponse
    {
        return $this->request(
            OperationSpec::text($spec, 'function'),
            $parameters,
            OperationSpec::method($spec),
            OperationSpec::outcome($spec),
        );
    }

    /**
     * Execute an operation declared by an internal operation group or call().
     *
     * Groups supply endpoint parameters; the API version cannot be overridden and
     * is removed from caller parameters. Execution clears metadata before parameter
     * validation or I/O, so groups pass multi-value input through unvalidated
     * (see NumberedList). Post keeps api.version in the query string and
     * form-encodes the operation parameters.
     *
     * @param Parameters $parameters
     */
    private function request(
        string $operation,
        #[SensitiveParameter]
        array $parameters,
        RequestMethod $method = RequestMethod::Get,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        $path = '/json-api/' . $operation;
        $parameters = array_diff_key($parameters, self::RESERVED);

        return $method === RequestMethod::Post
            ? $this->executePost($path, self::RESERVED, $parameters, ResponseRoute::Whm, $outcome)
            : $this->executeGet($path, self::RESERVED + $parameters, ResponseRoute::Whm, $outcome);
    }
}
