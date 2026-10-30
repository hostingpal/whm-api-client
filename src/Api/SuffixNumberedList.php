<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api;

use SensitiveParameter;

/**
 * Mark a terminal-wildcard parameter for one-based suffix encoding.
 *
 * A wire name such as filter* expands to filter1, filter2, ... in list order.
 * One scalar is the first value. AbstractApiClient validates names and contents
 * after clearing response metadata. String-only parameters reject integers;
 * reviewed scalar parameters may additionally accept integer values.
 *
 * @internal Built by the engine for reviewed suffix-numbered parameters.
 */
final readonly class SuffixNumberedList
{
    /**
     * Retain caller input for validation within the shared request lifecycle.
     *
     * @param mixed $values One value or a nonempty sequential list.
     * @param bool $allowIntegers Whether integer scalars and list entries are supported.
     */
    public function __construct(
        #[SensitiveParameter]
        public mixed $values,
        public bool $allowIntegers = false,
    ) {
    }
}
