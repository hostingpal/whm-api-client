<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Response;

/**
 * Carry a decoded payload to its operation-specific validator.
 *
 * This internal boundary is intentionally mixed: each operation owns its schema.
 * Public endpoint methods return validated concrete payloads instead.
 *
 * @internal
 */
final readonly class ApiResponse
{
    /**
     * Keep the decoded payload, its presence, and the envelope snapshot together.
     *
     * hasData distinguishes an omitted data member from a valid explicit null.
     */
    public function __construct(
        public mixed $data,
        public ResponseMetadata $metadata,
        public bool $hasData = true,
    ) {
    }
}
