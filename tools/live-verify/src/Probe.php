<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Client;

/** A reviewed generated method and its exact read-only request boundary. */
final readonly class Probe
{
    /**
     * The spec uses the internal OperationSpec grammar; no endpoint schema is inferred here.
     *
     * @param 'whm'|'direct-uapi'|'bridged-uapi' $route
     * @param array<mixed> $spec
     * @param Closure(Client): mixed $invoke
     * @param 'skipped'|'unsupported'|null $unavailable
     */
    public function __construct(
        public string $operation,
        public string $route,
        public array $spec,
        public string $uri,
        public Closure $invoke,
        public ?string $unavailable = null,
    ) {
    }
}
