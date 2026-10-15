<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Throwable;

/**
 * Observe the exact bytes returned by the consumer's normal string conversion.
 *
 * No eager reads, rewinds or replacement streams are introduced. All operations
 * delegate to the original stream, including its cursor and exception behavior.
 * The observer runs only after a successful conversion and cannot affect it.
 */
final readonly class ObservedStream implements StreamInterface
{
    /** @param Closure(string): void $observe In-memory observer; never persist raw bytes. */
    public function __construct(
        #[SensitiveParameter]
        private StreamInterface $stream,
        #[SensitiveParameter]
        private Closure $observe,
    ) {
    }

    public function __toString(): string
    {
        $body = (string) $this->stream;
        try {
            ($this->observe)($body);
        } catch (Throwable) {
            // Evidence capture must never change client-visible stream behavior.
        }

        return $body;
    }

    public function close(): void
    {
        $this->stream->close();
    }

    /** @return resource|null */
    public function detach()
    {
        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    /** @param int $offset @param int $whence */
    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->stream->rewind();
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    /** @param string $string */
    public function write(#[SensitiveParameter] $string): int
    {
        return $this->stream->write($string);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /** @param int $length */
    public function read($length): string
    {
        return $this->stream->read($length);
    }

    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    /** @param string|null $key @return mixed */
    public function getMetadata($key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }
}
