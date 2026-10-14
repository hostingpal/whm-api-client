<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use Closure;
use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use LogicException;

/**
 * Execute one generated operation: encode, dispatch, validate, unwrap.
 *
 * The dispatch closure belongs to the owning handle: it routes the request
 * (WHM path, direct UAPI or the bridge), applies the spec's method and outcome
 * policy, and runs the shared request lifecycle, so metadata is cleared before
 * any parameter validation or I/O. This class adds no retries and no state.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 * @internal
 */
final class OperationRunner
{
    /**
     * Run an operation and return its validated result.
     *
     * Arguments are encoded from the spec's params before the dispatch closure
     * is called, so an argument the spec does not declare fails without I/O.
     * The returned value is whatever result() produces for the response.
     *
     * @param array<mixed> $spec Generated operation spec.
     * @param array<string, mixed> $arguments Named arguments of the generated method.
     * @param Closure(array<mixed>, Parameters): ApiResponse $dispatch
     * @throws ApiException If transport, HTTP, the outcome, or the payload fails.
     * @throws LogicException If the spec and arguments disagree.
     */
    public static function run(array $spec, array $arguments, Closure $dispatch): mixed
    {
        $params = $spec['params'] ?? null;
        if (!is_array($params)) {
            throw new LogicException('Operation spec is missing "params".');
        }

        return self::result($spec, $dispatch($spec, ArgumentEncoder::encode($params, $arguments)));
    }

    /**
     * Validate the payload against the spec, then apply void or unwrap rules.
     *
     * A spec must declare "returns": an explicit null means a void operation,
     * which returns null and ignores any payload. A missing key is a generator
     * defect. When a payload is declared, an omitted data member is invalid; a
     * nullable spec accepts only an explicit null. An unwrapped field must be
     * present in the validated object.
     *
     * @param array<mixed> $spec
     * @throws InvalidResponseException If the payload does not satisfy the spec.
     * @throws LogicException If the spec is malformed.
     */
    public static function result(array $spec, ApiResponse $response): mixed
    {
        if (!array_key_exists('returns', $spec)) {
            throw new LogicException('Operation spec is missing "returns".');
        }

        $returns = $spec['returns'];
        if ($returns === null) {
            return null;
        }

        if (!is_array($returns)) {
            throw new LogicException('Invalid return spec.');
        }

        $read = new PayloadReader($response->metadata, OperationSpec::text($spec, 'function') . ' payload');
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
}
