<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OperationSpec;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
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
 * call() reaches every documented UAPI function through the same routing,
 * authentication, and envelope handling.
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
     * Execute any documented UAPI function for this account without a typed group.
     *
     * The module and function are the documented names (such as Email and
     * list_pops). Direct cPanel credentials request /execute/Module/function;
     * WHM credentials route through the uapi_cpanel bridge with this account as
     * the target, and the bridge's api.version and cpanel.* fields cannot be
     * overridden. Parameters are sent in the query string for Get or form-encoded
     * in the body for Post; use Post for secret-bearing parameters. The result's
     * data is the UAPI payload converted to arrays without schema validation, and
     * metadata, exceptions, and responseMeta() behave as for typed operations.
     * Flag applies the documented status 1/0 rule (and the WHM result rule on the
     * bridge); Unchecked leaves the outcome to the caller, for functions such as
     * Features/has_feature whose status reports feature state.
     *
     * @param Parameters $parameters
     * @throws InvalidArgumentException If a name or a multi-value parameter is invalid.
     * @throws ApiException If transport, HTTP, or the API envelope fails.
     * @see https://api.docs.cpanel.net/cpanel/introduction
     * @see https://api.docs.cpanel.net/whm/use-whm-api-to-call-cpanel-api-and-uapi
     */
    public function call(
        string $module,
        string $function,
        #[SensitiveParameter]
        array $parameters = [],
        RequestMethod $method = RequestMethod::Get,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): CallResult {
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
     * Route a generated operation for this account through direct cPanel or WHM.
     *
     * @param array<mixed> $spec
     * @param Parameters $parameters
     */
    private function dispatch(array $spec, #[SensitiveParameter] array $parameters): ApiResponse
    {
        return $this->request(
            OperationSpec::text($spec, 'module'),
            OperationSpec::text($spec, 'function'),
            $parameters,
            OperationSpec::method($spec),
            OperationSpec::outcome($spec),
        );
    }

    /**
     * Route every UAPI request through this account-bound helper.
     *
     * Groups supply operation names and endpoint parameters. The bound module,
     * account, API version, and function take precedence over bridge parameters
     * and are removed from caller parameters. Execution clears metadata before
     * parameter validation or I/O, then retains usable envelope metadata before
     * API or group-level payload errors. Post keeps the routing fields in the
     * query string and form-encodes the operation parameters.
     *
     * @param Parameters $parameters
     */
    private function request(
        string $module,
        string $operation,
        #[SensitiveParameter]
        array $parameters,
        RequestMethod $method = RequestMethod::Get,
        OutcomePolicy $outcome = OutcomePolicy::Flag,
    ): ApiResponse {
        if ($this->isBridged()) {
            $reserved = [
                'api.version' => 1,
                'cpanel.user' => $this->username,
                'cpanel.module' => $module,
                'cpanel.function' => $operation,
            ];
            $parameters = array_diff_key($parameters, $reserved);

            $route = ResponseRoute::BridgedUapi;

            return $method === RequestMethod::Post
                ? $this->executePost('/json-api/uapi_cpanel', $reserved, $parameters, $route, $outcome)
                : $this->executeGet('/json-api/uapi_cpanel', $reserved + $parameters, $route, $outcome);
        }

        $path = '/execute/' . $module . '/' . $operation;

        return $method === RequestMethod::Post
            ? $this->executePost($path, [], $parameters, ResponseRoute::DirectUapi, $outcome)
            : $this->executeGet($path, $parameters, ResponseRoute::DirectUapi, $outcome);
    }
}
