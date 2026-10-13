<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

/**
 * Mark a query value for WHM's numbered multi-value encoding.
 *
 * One string encodes as name=value; a list encodes as name, name-1, name-2, ...
 * in order. Contents are validated by AbstractApiClient::executeGet() after it
 * clears response metadata, so groups never validate parameters before the
 * request lifecycle starts. Repeated-key lists remain plain list<string> values.
 *
 * @internal Built by operation groups for documented numbered parameters.
 */
final readonly class NumberedList
{
    /**
     * Wrap caller input without validating it yet.
     *
     * @param string|array<array-key, mixed> $values One value or a nonempty list of strings.
     */
    public function __construct(public string|array $values)
    {
    }
}
