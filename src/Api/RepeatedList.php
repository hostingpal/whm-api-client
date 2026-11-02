<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

/**
 * Mark a query or body value for repeated-key multi-value encoding.
 *
 * One string encodes as name=value; a list encodes as name=a&name=b in order.
 * ParameterExpander validates contents before dispatch, in both generated and
 * generic calls, after the owning handle clears response metadata.
 *
 * @internal Built by the engine for documented repeated parameters.
 */
final readonly class RepeatedList
{
    /**
     * Wrap caller input without validating it yet.
     *
     * ParameterExpander checks the contents after the handle clears response
     * metadata, so invalid input never leaves stale metadata behind.
     *
     * @param string|array<array-key, mixed> $values One value or a nonempty list of strings.
     */
    public function __construct(public string|array $values)
    {
    }
}
