<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Http;

use LogicException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use WeakMap;

/**
 * A read-only, forward-only multipart stream owning one prepared source set.
 *
 * Construction/size queries read no source bytes. Each read() returns at most
 * 8192 bytes regardless of its requested length. Source reads and framing buffers
 * are also bounded to 8192 bytes plus a boundary-sized look-behind.
 * Explicit getContents()/string conversion may allocate the requested output.
 * Collision/short-read errors can follow partial delivery and are never retried.
 *
 * Failure survives close: synchronous transport must call assertHealthy() after
 * sendRequest(), since __toString() and some clients swallow read exceptions.
 * No arbitrary lower exception is retained. Debug/export/metadata do not reveal
 * private state; explicit body reads and caller logging of requests are outside
 * that diagnostic promise. There is no single resource to transfer via detach.
 *
 * @internal
 * @phpstan-type State array{sources: PreparedUploadSet, segments: list<string|PreparedUploadSource>,
 *     boundary: string, size: int, position: int, index: int, offset: int, pending: string,
 *     tail: string, closed: bool, failed: bool}
 */
final class StreamingMultipartBody implements StreamInterface
{
    /** @var WeakMap<self, State>|null */
    private static ?WeakMap $states = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * Internal encoder handoff; the source set stays owned until close/finally.
     *
     * @param list<string|PreparedUploadSource> $segments
     */
    public static function create(
        #[SensitiveParameter] PreparedUploadSet $sources,
        #[SensitiveParameter] array $segments,
        #[SensitiveParameter] string $boundary,
        int $size,
    ): self {
        $body = new self();
        self::registry()[$body] = [
            'sources' => $sources, 'segments' => $segments, 'boundary' => $boundary,
            'size' => $size, 'position' => 0, 'index' => 0, 'offset' => 0,
            'pending' => '', 'tail' => '', 'closed' => false, 'failed' => false,
        ];
        return $body;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->state()['boundary'];
    }

    /** Surface an actual recorded read failure even after release or string conversion. */
    public function assertHealthy(): void
    {
        if ($this->state()['failed']) {
            throw new RuntimeException('Multipart body could not be read.');
        }
    }

    /** PSR requires no exception here; transport checks the independent failure record. */
    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $state = self::registry()[$this] ?? null;
        if ($state === null || $state['closed']) {
            return;
        }
        $state['sources']->release();
        $state['segments'] = [];
        $state['pending'] = '';
        $state['tail'] = '';
        $state['closed'] = true;
        self::registry()[$this] = $state;
    }

    /** @return resource|null Always null: owned handles are released, never leaked/transferred. */
    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): int
    {
        return $this->state()['size'];
    }

    public function tell(): int
    {
        $state = $this->state();
        if ($state['closed']) {
            throw new RuntimeException('Multipart body is closed.');
        }
        return $state['position'];
    }

    public function eof(): bool
    {
        $state = $this->state();
        return $state['closed'] || $state['position'] === $state['size'];
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Multipart body is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Multipart body is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(#[SensitiveParameter] string $string): int
    {
        throw new RuntimeException('Multipart body is read-only.');
    }

    public function isReadable(): bool
    {
        return !$this->state()['closed'];
    }

    /**
     * Read a bounded block, advancing body and underlying source cursors.
     *
     * @phpstan-impure
     */
    public function read(int $length): string
    {
        $state = $this->state();
        if ($length < 0 || $state['closed']) {
            throw new RuntimeException('Multipart body is closed or read length is invalid.');
        }
        $length = min($length, 8192);
        $bytes = '';
        try {
            while (strlen($bytes) < $length && $state['position'] + strlen($bytes) < $state['size']) {
                if ($state['pending'] === '') {
                    self::fill($state);
                }
                $count = min($length - strlen($bytes), strlen($state['pending']));
                if ($count === 0) {
                    throw new RuntimeException('Multipart body made no read progress.');
                }
                $bytes .= substr($state['pending'], 0, $count);
                $state['pending'] = substr($state['pending'], $count);
            }
            $state['position'] += strlen($bytes);
            self::registry()[$this] = $state;
        } catch (Throwable) {
            $state['failed'] = true;
            self::registry()[$this] = $state;
            $this->close();
            throw new RuntimeException('Multipart body could not be read.');
        }
        return $bytes;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Multipart body is closed.');
        }
        $bytes = '';
        while (!$this->eof()) {
            $bytes .= $this->read(8192);
        }
        return $bytes;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(?string $key = null): ?array
    {
        return $key === null ? [] : null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return array{readable: bool} */
    public function __debugInfo(): array
    {
        return ['readable' => $this->isReadable()];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Multipart bodies cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Multipart bodies cannot be unserialized.');
    }

    /** @param State $state */
    private static function fill(#[SensitiveParameter] array &$state): void
    {
        while ($state['pending'] === '' && isset($state['segments'][$state['index']])) {
            $segment = $state['segments'][$state['index']];
            if (is_string($segment)) {
                $state['pending'] = substr($segment, $state['offset'], 8192);
                $state['offset'] += strlen($state['pending']);
                if ($state['offset'] === strlen($segment)) {
                    ++$state['index'];
                    $state['offset'] = 0;
                }
                continue;
            }
            $remaining = $segment->size() - $state['offset'];
            if ($remaining === 0) {
                ++$state['index'];
                $state['offset'] = 0;
                continue;
            }
            $chunk = $segment->read(min(8192, $remaining));
            if ($chunk === '') {
                throw new RuntimeException('Multipart source ended before its declared length.');
            }
            $state['offset'] += strlen($chunk);
            $combined = $state['tail'] . $chunk;
            if (str_contains($combined, $state['boundary'])) {
                throw new RuntimeException('Multipart source contains a boundary collision.');
            }
            $safe = $state['offset'] === $segment->size()
                ? strlen($combined) : max(0, strlen($combined) - strlen($state['boundary']) + 1);
            $state['pending'] = substr($combined, 0, $safe);
            $state['tail'] = substr($combined, $safe);
        }
    }

    /** @return WeakMap<self, State> */
    private static function registry(): WeakMap
    {
        return self::$states ??= new WeakMap();
    }

    /** @return State */
    private function state(): array
    {
        return self::registry()[$this] ?? throw new LogicException('Invalid multipart body.');
    }
}
