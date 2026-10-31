<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\JsonBody;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestBodyFormat;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Api\Uapi\UapiGroups;
use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\CallResult;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Hold UAPI context for one immutable cPanel target account.
 *
 * The credential type selects direct cPanel access or execution through WHM.
 * Both routes share this handle type and expose the same endpoint groups.
 * Typed groups are generated from the pinned API map and overlay (see docs/api/coverage.md);
 * call() provides GET and form-encoded POST access without a typed group through
 * the same routing, authentication, and envelope handling. Reviewed generated
 * operations can select multipart string fields; call() remains form-only.
 * callJson() provides explicit raw JSON POST access on direct cPanel handles only.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 */
final class UapiClient extends AbstractApiClient
{
    use UapiGroups;

    /**
     * Bind an explicit cPanel target to a configuration and transport.
     *
     * Direct-token targets must match the token owner. With WHM credentials the
     * target identifies the account to impersonate, subject to server permissions.
     *
     * @throws InvalidArgumentException If the target is invalid or mismatched.
     */
    public function __construct(
        ClientConfig $config,
        HttpTransport $transport,
        private readonly string $username,
    ) {
        TokenCredentials::validateUsername($username);

        if ($config->credentials->type === TokenType::Cpanel && $username !== $config->credentials->username) {
            throw new InvalidArgumentException('The UAPI target must match the cPanel credential account.');
        }

        parent::__construct($config, $transport);
    }

    /**
     * Return the selected cPanel account username.
     *
     * This is the target account, which can differ from the WHM authentication user.
     */
    public function username(): string
    {
        return $this->username;
    }

    /**
     * Report whether UAPI execution must use the WHM bridge.
     *
     * Routing follows the configured credential type and requires no server probe.
     */
    public function isBridged(): bool
    {
        return $this->config->credentials->type === TokenType::Whm;
    }

    /**
     * Execute a UAPI function for this account using GET or form-encoded POST.
     *
     * The module and function are the documented names (such as Email and
     * list_pops). Direct cPanel credentials request /execute/Module/function;
     * WHM credentials route through the uapi_cpanel bridge with this account as
     * the target, and the bridge's api.version and cpanel.* fields cannot be
     * overridden. Post, the default, form-encodes the parameters in the body (the
     * bridge's routing fields stay in the query string), so secrets never reach the
     * URL; POST was verified live on both routes for read-only functions. Pass Get
     * explicitly to send every parameter in the query string, never for a
     * secret-bearing parameter. The result's
     * data is the UAPI payload converted to arrays without schema validation, and
     * metadata, exceptions, and responseMeta() behave as for typed operations.
     * Flag applies the documented status 1/0 rule (and the WHM result rule on the
     * bridge); Unchecked leaves the UAPI status to the caller, for functions such as
     * Features/has_feature whose status reports feature state. The bridge's WHM
     * result is checked under both policies, so a failed bridge always raises.
     * The caller must check the function's method, parameter representation, route
     * and envelope requirements. JSON and multipart request bodies are unsupported
     * on either route; generic access does not establish operation coverage or
     * live verification.
     *
     * @param Parameters $parameters
     * @throws InvalidArgumentException If a name or a parameter value is invalid; responseMeta() is cleared
     *     first, so it never reports an earlier request.
     *     Messages may name a parameter but never include its value.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     * @see https://api.docs.cpanel.net/cpanel/introduction
     * @see https://api.docs.cpanel.net/whm/use-whm-api-to-call-cpanel-api-and-uapi
     */
    public function call(
        string $module,
        string $function,
        #[SensitiveParameter]
        array $parameters = [],
        RequestMethod $method = RequestMethod::Post,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): CallResult {
        $this->beginRequest();
        foreach ([$module, $function] as $segment) {
            if (preg_match('/\A[A-Za-z0-9_]+\z/', $segment) !== 1) {
                throw new InvalidArgumentException(
                    'UAPI module and function names contain only letters, digits, and underscores.',
                );
            }
        }

        return CallResult::fromApiResponse($this->request($module, $function, $parameters, $method, $outcome));
    }

    /**
     * POST an already serialized JSON document for this direct cPanel account.
     *
     * WHM-bridged handles reject this route locally after metadata reset. Direct
     * access uses only this handle's host, credentials and account. Valid JSON roots
     * retain exact bytes without endpoint schema validation. The case-sensitive api.
     * prefix and exact cpanel.user/module/function names are reserved in caller
     * query fields and root object members, including nulls. Nested names remain data.
     * Query uses the existing parameter representation and is encoded once. Keep
     * secrets in the body: servers may log the separate query. The untyped result,
     * outcome policy, envelope errors and metadata follow call(); no retries occur.
     * Choose a JSON-compatible operation with a standard envelope. This raw escape
     * hatch establishes neither typed coverage nor live endpoint compatibility.
     * Reviewer-inspected 11.138.0.19 UAPI source generally accepts flat JSON
     * arguments; nested structures require the function's requires_json declaration.
     * The corrected review identifies five functions across four modules:
     * ClamScanner (1), ContactInformation (1), Personalization (2), StatsManager (1).
     * This source attribution is neither live verification nor an endpoint allowlist.
     *
     * @param Parameters $query Explicit URL query data, never merged into the body.
     * @throws InvalidArgumentException If bridged, or a name, JSON document or query is invalid; metadata clears first.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     * @see https://api.docs.cpanel.net/cpanel/introduction
     * @see https://github.com/hostingpal/whm-api-client/pull/73#pullrequestreview-5722705880
     * @see https://github.com/hostingpal/whm-api-client/pull/73#issuecomment-6543354915
     */
    public function callJson(
        string $module,
        string $operation,
        #[SensitiveParameter] string $json,
        #[SensitiveParameter] array $query = [],
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): CallResult {
        $this->beginRequest();
        if ($this->isBridged()) {
            throw new InvalidArgumentException('JSON bodies require a direct cPanel UAPI handle.');
        }
        foreach ([$module, $operation] as $segment) {
            if (preg_match('/\A[A-Za-z0-9_]+\z/', $segment) !== 1) {
                throw new InvalidArgumentException(
                    'UAPI module and function names contain only letters, digits, and underscores.',
                );
            }
        }
        $reserved = ['cpanel.user', 'cpanel.module', 'cpanel.function'];
        $this->validateJsonQuery($query, $reserved);
        $body = JsonBody::present($json);
        $body->assertNoReservedRoot($reserved);

        return CallResult::fromApiResponse($this->executeJsonPost(
            '/execute/' . $module . '/' . $operation,
            $query,
            $body,
            ResponseRoute::DirectUapi,
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
     * Route a generated operation for this account through direct cPanel or WHM.
     *
     * @param array<mixed> $spec
     */
    private function dispatch(
        array $spec,
        #[SensitiveParameter] OrderedFields $parameters,
        #[SensitiveParameter] ?JsonBody $body = null,
    ): ApiResponse {
        if ($body !== null) {
            if ($this->isBridged()) {
                throw new InvalidArgumentException('JSON bodies require a direct cPanel UAPI handle.');
            }
            $reserved = ['cpanel.user', 'cpanel.module', 'cpanel.function'];
            $this->validateJsonQuery($parameters, $reserved);
            $body->assertNoReservedRoot($reserved);
            return $this->executeJsonPost(
                '/execute/' . OperationSpec::text($spec, 'module') . '/' . OperationSpec::text($spec, 'function'),
                $parameters,
                $body,
                ResponseRoute::DirectUapi,
                OperationSpec::outcome($spec),
            );
        }
        return $this->request(
            OperationSpec::text($spec, 'module'),
            OperationSpec::text($spec, 'function'),
            $parameters,
            OperationSpec::method($spec),
            OperationSpec::outcome($spec),
            OperationSpec::body($spec),
        );
    }

    /**
     * Route every UAPI request through this account-bound helper.
     *
     * Groups supply operation names and endpoint parameters. The bound module,
     * account, API version, and function are reserved on the bridge. Generated
     * fields reject collisions before filtering; generic arrays retain reserved
     * field removal. Execution clears metadata before
     * parameter validation or I/O, then retains usable envelope metadata before
     * API or group-level payload errors. Post keeps the routing fields in the
     * query string and encodes operation parameters in the selected form or
     * multipart body. Multipart routing support does not imply live coverage.
     *
     * @param Parameters|OrderedFields $parameters
     */
    private function request(
        string $module,
        string $operation,
        #[SensitiveParameter]
        array|OrderedFields $parameters,
        RequestMethod $method,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
        RequestBodyFormat $bodyFormat = RequestBodyFormat::Form,
    ): ApiResponse {
        if ($this->isBridged()) {
            $reserved = [
                'api.version' => 1,
                'cpanel.user' => $this->username,
                'cpanel.module' => $module,
                'cpanel.function' => $operation,
            ];
            if ($parameters instanceof OrderedFields) {
                $parameters->assertNoReserved(array_keys($reserved));
                $query = $method === RequestMethod::Get ? $parameters->withRouting($reserved) : $reserved;
            } else {
                $parameters = array_diff_key($parameters, $reserved);
                $query = $reserved + $parameters;
            }

            $route = ResponseRoute::BridgedUapi;

            return $method === RequestMethod::Post
                ? $this->executePost('/json-api/uapi_cpanel', $reserved, $parameters, $route, $outcome, $bodyFormat)
                : $this->executeGet('/json-api/uapi_cpanel', $query, $route, $outcome);
        }

        $path = '/execute/' . $module . '/' . $operation;

        return $method === RequestMethod::Post
            ? $this->executePost($path, [], $parameters, ResponseRoute::DirectUapi, $outcome, $bodyFormat)
            : $this->executeGet($path, $parameters, ResponseRoute::DirectUapi, $outcome);
    }
}
