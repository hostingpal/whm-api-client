<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;

/**
 * Check a forward-only request body against its exact expected bytes while it is being sent.
 *
 * A multipart body can be read only once, so a guard cannot inspect it before dispatch. This decorator compares
 * each block on its way to the transport and throws at the first differing byte, which aborts the request before
 * the closing delimiter reaches the server. String conversion and buffering are refused, so it cannot pass with a
 * client that casts the body instead of streaming it. Only counts and booleans leave through stats().
 *
 * @phpstan-type Transfer array{
 *     declared_length: int, bytes_read: int, read_calls: int, largest_read: int,
 *     body_exact: bool, buffered: bool, sought: bool
 * }
 */
final class FilemanUploadBodyStream implements StreamInterface
{
    private int $position = 0;

    private int $reads = 0;

    private int $largest = 0;

    private bool $mismatch = false;

    private bool $buffered = false;

    private bool $sought = false;

    /** Wrap the original body without reading it; expected bytes stay in memory for this one attempt. */
    public function __construct(
        #[SensitiveParameter]
        private readonly StreamInterface $stream,
        #[SensitiveParameter]
        private readonly string $expected,
    ) {
    }

    /** @return Transfer */
    public function stats(): array
    {
        return [
            'declared_length' => strlen($this->expected),
            'bytes_read' => $this->position,
            'read_calls' => $this->reads,
            'largest_read' => $this->largest,
            'body_exact' => !$this->mismatch && $this->position === strlen($this->expected),
            'buffered' => $this->buffered,
            'sought' => $this->sought,
        ];
    }

    public function __toString(): string
    {
        $this->buffered = true;
        throw new LogicException('Fileman upload bodies are never converted to strings.');
    }

    public function close(): void
    {
        $this->stream->close();
    }

    /** @return resource|null Always null: the owned sources stay with the wrapped body. */
    public function detach()
    {
        $this->stream->close();

        return null;
    }

    public function getSize(): int
    {
        return strlen($this->expected);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position === strlen($this->expected);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    /** @param int $offset @param int $whence */
    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->sought = true;
        throw new LogicException('Fileman upload bodies are not seekable.');
    }

    public function rewind(): void
    {
        $this->sought = true;
        throw new LogicException('Fileman upload bodies are not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    /** @param string $string */
    public function write(#[SensitiveParameter] $string): int
    {
        throw new LogicException('Fileman upload bodies are read-only.');
    }

    public function isReadable(): bool
    {
        return !$this->mismatch && $this->stream->isReadable();
    }

    /** @param int $length */
    public function read($length): string
    {
        $bytes = $this->stream->read($length);
        ++$this->reads;
        $this->largest = max($this->largest, strlen($bytes));
        if (substr($this->expected, $this->position, strlen($bytes)) !== $bytes) {
            $this->mismatch = true;
            throw new LogicException('Fileman upload body differs from the reviewed recipe.');
        }
        $this->position += strlen($bytes);

        return $bytes;
    }

    public function getContents(): string
    {
        $this->buffered = true;
        throw new LogicException('Fileman upload bodies are never buffered.');
    }

    /** @param string|null $key @return mixed */
    public function getMetadata($key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
