<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\JsonBody;
use HostingPal\WhmApiClient\Api\Engine\MultipartInput;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
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
 * call() provides GET and form-encoded POST access without a typed group through
 * the same routing, authentication, and envelope handling. callJson() provides
 * explicit raw JSON POST access; callMultipart() streams ordered text/file parts.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 * @phpstan-import-type MultipartQuery from AbstractApiClient
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
     * Execute a WHM API 1 function using GET or form-encoded POST without a typed group.
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
     * The caller must check the function's method, parameter representation and
     * envelope requirements. JSON and multipart request bodies are unsupported;
     * generic access does not establish operation coverage or live verification.
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
     * POST an already serialized JSON document to a WHM API 1 operation.
     *
     * The configured host and credentials own the request. Valid JSON roots retain
     * their exact bytes without endpoint schema validation. The case-sensitive api.
     * prefix is reserved in caller query fields and root object members; the library
     * adds its fixed api.version in the URL after caller validation.
     * Query uses the existing parameter representation and is encoded once. Keep
     * secrets in the body: servers may log the separate query. The untyped result,
     * outcome policy, envelope errors and metadata follow call(); no retries occur.
     * The caller must choose a JSON-compatible operation with a standard envelope.
     * Raw access establishes neither typed coverage nor wrapper compatibility.
     *
     * @param Parameters $query Explicit URL query data, never merged into the body.
     * @throws InvalidArgumentException If a name, JSON document or query is invalid; metadata is cleared first.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     * @see https://api.docs.cpanel.net/whm/introduction
     */
    public function callJson(
        string $operation,
        #[SensitiveParameter] string $json,
        #[SensitiveParameter] array $query = [],
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): CallResult {
        $this->beginRequest();
        if (preg_match('/\A[A-Za-z0-9_]+(?:\/[A-Za-z0-9_]+)*\z/', $operation) !== 1) {
            throw new InvalidArgumentException(
                'WHM function names contain only letters, digits, underscores, and "/" separators.',
            );
        }
        $this->validateJsonQuery($query, []);
        $body = JsonBody::present($json);
        $body->assertNoReservedRoot([]);

        return CallResult::fromApiResponse($this->executeJsonPost(
            '/json-api/' . $operation,
            self::RESERVED + $query,
            $body,
            ResponseRoute::Whm,
            $outcome,
        ));
    }

    /**
     * POST ordered text and file parts through this configured handle.
     *
     * Strings are literal text, including path-looking strings. Upload descriptors
     * select files; repeated names and text/file order are preserved. Empty lists
     * send the closing delimiter. Parts are never form-encoded or renumbered.
     * Query uses the existing parameter representation plus null omission and is
     * encoded once. Keep secrets in parts: servers may log the separate query.
     * The case-sensitive api. prefix is reserved in parts and raw/expanded query
     * fields, including raw nulls. Emitted query fields cannot share a part name.
     * Sources are prepared only after complete local validation. Borrowed streams
     * start at their current cursor and are never rewound, closed or detached;
     * exact remaining lengths are required. Owned files close on every exit.
     * A partial send or failure never retries or establishes remote ownership.
     * Standard envelope, metadata and CallResult semantics follow call(). Flag's
     * API error omits data/per-file reasons; explicit Unchecked retains raw data.
     * Choose a multipart-compatible operation with a standard envelope. Generic
     * access establishes neither typed coverage nor live endpoint compatibility.
     *
     * @param list<array{name: string, value: string|Upload}> $parts
     * @param MultipartQuery $query
     * @throws InvalidArgumentException If local input is invalid; metadata clears first and errors omit caller data.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     */
    public function callMultipart(
        string $operation,
        #[SensitiveParameter] array $parts,
        #[SensitiveParameter] array $query = [],
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): CallResult {
        $this->beginRequest();
        if (preg_match('/\A[A-Za-z0-9_]+(?:\/[A-Za-z0-9_]+)*\z/', $operation) !== 1) {
            throw new InvalidArgumentException(
                'WHM function names contain only letters, digits, underscores, and "/" separators.',
            );
        }
        $body = MultipartInput::present($parts, $query, []);

        return CallResult::fromApiResponse($this->executeMultipartPost(
            '/json-api/' . $operation,
            $body->query->withRouting(self::RESERVED),
            $body,
            ResponseRoute::Whm,
            $outcome,
        ));
    }

    /**
     * Run a generated operation spec through this handle.
     *
     * Generated groups receive this method as their run callback; selecting a
     * group performs no I/O. The attempt starts here: metadata is cleared before
     * the runner checks the spec and encodes the arguments, so an argument rejected
     * before dispatch (for example a non-finite float) never leaves an earlier
     * request's metadata in responseMeta().
     *
     * @param array<mixed> $spec
     * @param array<string, mixed> $arguments
     */
    private function run(array $spec, #[SensitiveParameter] array $arguments): mixed
    {
        $this->beginRequest();

        return OperationRunner::run($spec, $arguments, $this->dispatch(...));
    }

    /**
     * Route a generated operation to WHM API 1 with the spec's method and outcome policy.
     *
     * @param array<mixed> $spec
     */
    private function dispatch(
        array $spec,
        #[SensitiveParameter] OrderedFields $parameters,
        #[SensitiveParameter] JsonBody|MultipartInput|null $body = null,
    ): ApiResponse {
        if ($body instanceof MultipartInput) {
            if (!isset($spec['multipart_upload'])) {
                throw new LogicException('Generated WHM multipart requires a scalar upload spec.');
            }
            return $this->executeMultipartPost(
                '/json-api/' . OperationSpec::text($spec, 'function'),
                $body->query->withRouting(self::RESERVED),
                $body,
                ResponseRoute::Whm,
                OperationSpec::outcome($spec),
            );
        }
        if ($body !== null) {
            $this->validateJsonQuery($parameters, []);
            $body->assertNoReservedRoot([]);
            return $this->executeJsonPost(
                '/json-api/' . OperationSpec::text($spec, 'function'),
                $parameters->withRouting(self::RESERVED),
                $body,
                ResponseRoute::Whm,
                OperationSpec::outcome($spec),
            );
        }
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
     * Generated fields reject routing collisions before encoding. Generic caller
     * arrays retain reserved-field filtering. Metadata is already cleared before
     * argument validation; execution resets it again before I/O. Post keeps
     * api.version in the query string and form-encodes operation parameters.
     *
     * @param Parameters|OrderedFields $parameters
     */
    private function request(
        string $operation,
        #[SensitiveParameter]
        array|OrderedFields $parameters,
        RequestMethod $method,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        $path = '/json-api/' . $operation;
        if ($parameters instanceof OrderedFields) {
            $parameters->assertNoReserved(array_keys(self::RESERVED));
            $query = $method === RequestMethod::Get ? $parameters->withRouting(self::RESERVED) : self::RESERVED;
        } else {
            $parameters = array_diff_key($parameters, self::RESERVED);
            $query = self::RESERVED + $parameters;
        }

        return $method === RequestMethod::Post
            ? $this->executePost($path, self::RESERVED, $parameters, ResponseRoute::Whm, $outcome)
            : $this->executeGet($path, $query, ResponseRoute::Whm, $outcome);
    }
}
