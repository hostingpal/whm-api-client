<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Psr\Http\Client\ClientInterface;

/**
 * A PSR-18 client for one incremental multipart body, reporting the framing it actually sent.
 *
 * @phpstan-type Framing array{
 *     http_1_1: bool, content_length_exact: bool, transfer_encoding_absent: bool,
 *     expect_absent: bool, bytes_sent_exact: bool
 * }
 */
interface StreamingTransport extends ClientInterface
{
    /**
     * Framing facts for the latest completed exchange, null when none completed.
     *
     * Holds booleans only, so no request header, credential or body byte can leave through it.
     *
     * @return Framing|null
     */
    public function lastFraming(): ?array;
}
