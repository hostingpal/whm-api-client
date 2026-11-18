<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Exception;

use HostingPal\WhmApiClient\Response\ResponseMetadata;
use SensitiveParameter;
use SensitiveParameterValue;

/** The zone rebuild reported failure after successful UAPI dispatch. */
final class ZoneRebuildException extends ApiErrorException
{
    private readonly SensitiveParameterValue $reason;

    /** Keep the dispatched call's metadata and conceal upstream statusmsg from ordinary exception output. */
    public function __construct(ResponseMetadata $metadata, #[SensitiveParameter] ?string $reason)
    {
        parent::__construct('The DNS zone rebuild failed.', $metadata);
        $this->reason = new SensitiveParameterValue($reason);
    }

    /**
     * Read the untrusted upstream statusmsg explicitly. It may contain account details or other private data.
     *
     * An empty string is preserved. A missing, null or non-string statusmsg gives null.
     */
    public function upstreamReason(): ?string
    {
        $reason = $this->reason->getValue();

        return is_string($reason) ? $reason : null;
    }
}
