<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use Closure;
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestBodyFormat;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Execute one generated operation: encode, dispatch, validate, unwrap.
 *
 * The dispatch closure belongs to the owning handle: it routes the request
 * (WHM path, direct UAPI or the bridge), applies the spec's method and outcome
 * policy, and runs the shared request lifecycle, so metadata is cleared before
 * any parameter validation or I/O. This class adds no retries and no state.
 *
 * @internal
 */
final class OperationRunner
{
    /**
     * Run an operation and return its validated result.
     *
     * The whole spec is checked first (SpecChecker: operation keys, every
     * parameter spec and the complete return type grammar), so a defective spec
     * never sends a request. Arguments are then encoded from the spec's params
     * before the dispatch closure is called, so an argument the spec does not
     * declare fails without I/O. The returned value is whatever result() produces
     * for the response.
     *
     * @param array<mixed> $spec Generated operation spec.
     * @param array<string, mixed> $arguments Named arguments of the generated method.
     * @param Closure(array<mixed>, OrderedFields, JsonBody|MultipartInput|null=): ApiResponse $dispatch
     * @throws ApiException If transport, HTTP, the outcome, or the payload fails.
     * @throws PayloadMismatchException If the envelope and outcome were accepted but the payload differs from the
     *     spec; under the flag policy the operation took effect.
     * @throws LogicException If the spec is malformed or the arguments disagree with it.
     * @throws InvalidArgumentException If a float is non-finite or an explicit target policy fails (ArgumentEncoder),
     *     or if collection contents or expanded fields are invalid. The handles clear metadata before
     *     calling run(), so rejection before dispatch never leaves stale metadata.
     */
    public static function run(
        array $spec,
        #[SensitiveParameter]
        array $arguments,
        #[SensitiveParameter]
        Closure $dispatch,
    ): mixed {
        SpecChecker::check($spec);
        $params = $spec['params'] ?? null;
        if (!is_array($params)) {
            throw new LogicException('Operation spec is missing "params".');
        }

        $body = null;
        if (OperationSpec::body($spec) === RequestBodyFormat::Json) {
            $descriptor = OperationSpec::jsonBody($spec);
            $name = $descriptor['php'];
            $present = array_key_exists($name, $arguments);
            if (!$present && $descriptor['required']) {
                throw new InvalidArgumentException('Required JSON body is missing.');
            }
            $value = $arguments[$name] ?? null;
            unset($arguments[$name]);
            $body = !$descriptor['required'] && $value === null
                ? JsonBody::absent()
                : JsonBody::present(StructuredJsonEncoder::encode($value, $descriptor['schema']));
        }
        $uploads = null;
        if (isset($spec['multipart_uploads'])) {
            $descriptor = UploadList::descriptor($spec);
            $uploads = UploadList::parts($arguments[$descriptor['php']] ?? null, $descriptor['prefix']);
            unset($arguments[$descriptor['php']]);
        }
        $parameters = ArgumentEncoder::encode($params, $arguments);
        if ($uploads !== null) {
            $body = MultipartInput::generated($uploads, $parameters);
        }
        return self::result($spec, $dispatch($spec, $parameters, $body));
    }

    /**
     * Validate the payload against the spec, then apply void, optional or unwrap rules.
     *
     * A spec must declare "returns": an explicit null means a void operation,
     * which returns null and ignores any payload. A missing key is a generator
     * defect. When a payload is declared, an omitted data member is invalid; a
     * nullable spec accepts only an explicit null. An "optional" spec instead
     * returns null for an omitted or null data member without validating it. Typed
     * unchecked operations still require integer 0/1 status/result before these
     * result rules; an invalid status raises InvalidResponseException with the
     * captured metadata, outside payload mismatch classification. An
     * unwrapped field must be present in the validated object.
     *
     * An explicit "rows" policy preserves valid row-bearing status-0 batches, but
     * classifies explicit null or a fully validated object with an empty row list
     * as ApiErrorException. Missing data or rows and malformed known fields remain
     * payload mismatches. Status 1 retains the declared field optionality.
     *
     * The response reaching this point already passed the envelope checks and the
     * outcome policy, so other payload failures raise PayloadMismatchException:
     * outcomeConfirmed is true under the flag policy (the operation took effect)
     * and false when the outcome was not checked. Spec defects stay LogicException.
     * Responses can contain or echo secrets; hide them from trace arguments while
     * retaining the explicit unvalidated payload on PayloadMismatchException.
     *
     * @param array<mixed> $spec
     * @throws ApiErrorException If the opted-in status-0 result is null or has an empty validated row list.
     * @throws InvalidResponseException If a typed unchecked status/result is not integer 0 or 1.
     * @throws PayloadMismatchException If the payload does not satisfy the spec.
     * @throws LogicException If the spec is malformed.
     */
    public static function result(array $spec, #[SensitiveParameter] ApiResponse $response): mixed
    {
        $returns = self::returns($spec);
        if (OperationSpec::outcome($spec) === OutcomePolicy::Unchecked) {
            $uapi = isset($spec['module']);
            $status = $uapi ? $response->metadata->uapiStatus() : $response->metadata->whmResult();
            if ($status !== 0 && $status !== 1) {
                throw new InvalidResponseException(
                    $uapi ? 'Missing or invalid UAPI outcome.' : 'Missing or invalid WHM outcome.',
                    $response->metadata,
                );
            }
        }
        $rows = $spec['rows'] ?? null;
        $failedRows = is_string($rows) && $response->metadata->uapiStatus() === 0;
        if ($failedRows && $response->hasData && $response->data === null) {
            throw new ApiErrorException(
                'UAPI operation failed; inspect errors() or response metadata.',
                $response->metadata,
            );
        }
        if ($returns === null) {
            return null;
        }

        if (($spec['optional'] ?? false) === true && (!$response->hasData || $response->data === null)) {
            return null;
        }

        $confirmed = OperationSpec::outcome($spec) === OutcomePolicy::Flag;
        $read = new PayloadReader($response->metadata, OperationSpec::text($spec, 'function') . ' payload');
        try {
            $value = self::validated($spec, $returns, $response, $read);
            if ($failedRows) {
                if (!is_array($value) || !array_key_exists($rows, $value)) {
                    return $read->fail();
                }
                if ($value[$rows] === []) {
                    throw new ApiErrorException(
                        'UAPI operation failed; inspect errors() or response metadata.',
                        $response->metadata,
                    );
                }
            }
            return $value;
        } catch (InvalidResponseException $exception) {
            throw new PayloadMismatchException(
                $exception->getMessage(),
                $response->metadata,
                $confirmed,
                $response->hasData ? $read->json($response->data) : null,
            );
        }
    }

    /**
     * Validate a declared payload and apply the unwrap rule.
     *
     * @param array<mixed> $spec
     * @param array<mixed> $returns
     * @throws InvalidResponseException If the payload is omitted or does not satisfy the spec.
     * @throws LogicException If the spec is malformed.
     */
    private static function validated(array $spec, array $returns, ApiResponse $response, PayloadReader $read): mixed
    {
        if (!$response->hasData) {
            return $read->fail();
        }

        $value = ShapeValidator::value($response->data, $returns, $read);
        $unwrap = $spec['unwrap'] ?? null;
        if ($unwrap === null) {
            return $value;
        }

        if (!is_string($unwrap)) {
            throw new LogicException('Invalid unwrap spec.');
        }

        return is_array($value) && array_key_exists($unwrap, $value) ? $value[$unwrap] : $read->fail();
    }

    /**
     * Check the top-level spec keys that must be sound before any request is sent.
     *
     * @param array<mixed> $spec
     * @return array<mixed>|null The return type spec, or null for a void operation.
     * @throws LogicException If "function", "returns", "optional" or "unwrap" is defective.
     */
    private static function returns(array $spec): ?array
    {
        OperationSpec::text($spec, 'function');
        if (!array_key_exists('returns', $spec)) {
            throw new LogicException('Operation spec is missing "returns".');
        }

        $returns = $spec['returns'];
        if ($returns !== null && !is_array($returns)) {
            throw new LogicException('Invalid return spec.');
        }

        $optional = $spec['optional'] ?? false;
        if (!is_bool($optional) || ($optional && $returns === null)) {
            throw new LogicException('Invalid optional spec.');
        }

        $unwrap = $spec['unwrap'] ?? null;
        if ($unwrap === null) {
            return $returns;
        }

        $fields = is_array($returns) ? ($returns['fields'] ?? null) : null;
        if (
            !is_string($unwrap)
            || !is_array($returns)
            || ($returns['type'] ?? null) !== 'object'
            || !is_array($fields)
            || !array_key_exists($unwrap, $fields)
        ) {
            throw new LogicException('Invalid unwrap spec.');
        }

        return $returns;
    }
}
