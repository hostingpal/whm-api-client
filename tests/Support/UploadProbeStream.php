<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** A real byte source with independently controllable PSR metadata and I/O counters. */
final class UploadProbeStream implements StreamInterface
{
    public int $reads = 0;
    public int $seeks = 0;
    public int $closes = 0;
    public int $detaches = 0;
    public int $metadataCalls = 0;
    public bool $readable = true;
    public bool $seekable = true;
    public bool $throwSize = false;
    public bool $throwTell = false;
    public bool $throwRead = false;
    public ?int $reportedSize;
    public ?int $reportedPosition = null;
    private StreamInterface $inner;

    public function __construct(string $bytes)
    {
        $this->inner = Stream::create($bytes);
        $this->reportedSize = strlen($bytes);
    }

    public function __toString(): string
    {
        ++$this->reads;
        return (string) $this->inner;
    }

    public function close(): void
    {
        ++$this->closes;
        $this->inner->close();
    }

    /** @return resource|null */
    public function detach()
    {
        ++$this->detaches;
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        ++$this->metadataCalls;
        if ($this->throwSize) {
            throw new RuntimeException('private-stream-size-secret');
        }
        return $this->reportedSize;
    }

    public function tell(): int
    {
        ++$this->metadataCalls;
        if ($this->throwTell) {
            throw new RuntimeException('private-stream-position-secret');
        }
        return $this->reportedPosition ?? $this->inner->tell();
    }

    public function eof(): bool
    {
        ++$this->metadataCalls;
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        ++$this->metadataCalls;
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        ++$this->seeks;
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        ++$this->seeks;
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Fixture is read-only.');
    }

    public function isReadable(): bool
    {
        ++$this->metadataCalls;
        return $this->readable && $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        ++$this->reads;
        if ($this->throwRead) {
            throw new RuntimeException('private-stream-content-secret');
        }
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        ++$this->reads;
        return $this->inner->getContents();
    }

    /** @return mixed */
    public function getMetadata(?string $key = null)
    {
        ++$this->metadataCalls;
        return $this->inner->getMetadata($key);
    }
}
