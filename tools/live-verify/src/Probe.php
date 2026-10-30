<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Client;
use LogicException;

/** A reviewed generated method and its route; the catalog or lifecycle owns the exact request guard. */
final readonly class Probe
{
    /**
     * The spec uses the internal OperationSpec grammar; no endpoint schema is inferred here.
     * sourceVariant identifies the reviewed pinned variant independently of the actual request
     * method. Null retains the historical operation#GET catalog default; POST-source operations
     * must supply their explicit operation#POST identity, even if invocation fails before I/O.
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
        public ?string $sourceVariant = null,
    ) {
        if ($sourceVariant !== null && !in_array($sourceVariant, [$operation . '#GET', $operation . '#POST'], true)) {
            throw new LogicException('Probe source variant must identify its operation and a supported pinned method.');
        }
    }
}
