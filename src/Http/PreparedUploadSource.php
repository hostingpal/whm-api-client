<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Http;

use HostingPal\WhmApiClient\Upload;
use LogicException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use WeakMap;

/**
 * One forward-only source cursor with a fixed, exact initial remaining length.
 *
 * No read exceeds that declared length. Early EOF is returned as empty bytes for
 * the multipart body to reject; no extra-byte probe verifies asserted lengths.
 * Source mutation during an attempt and distinct wrappers around one underlying
 * resource are caller responsibilities. No source is buffered or spooled.
 *
 * Sensitive state uses weak keys so exporting this internal handle is also safe.
 *
 * @internal
 * @phpstan-type State array{kind: 'path'|'string'|'stream', filename: string, contentType: string,
 *     source: resource|string|StreamInterface|null, size: int, consumed: int}
 */
final class PreparedUploadSource
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
     * Open one source without consuming bytes; sets must first check duplicates.
     *
     * A local stat precheck avoids blocking on ordinary FIFOs/devices; fstat then
     * verifies the opened handle itself. This does not defend against hostile
     * concurrent path substitution. Symlinks to regular files remain allowed.
     *
     * @throws RuntimeException For unusable sources or unknown/invalid remainders.
     */
    public static function prepare(#[SensitiveParameter] Upload $upload): self
    {
        $descriptor = $upload->descriptor();
        $source = $descriptor['source'];
        $handle = null;
        try {
            if ($descriptor['kind'] === 'path' && is_string($source)) {
                clearstatcache(true, $source);
                $stat = @stat($source);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                    throw new RuntimeException('Upload source is not a regular file.');
                }
                $handle = @fopen($source, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Upload source cannot be opened.');
                }
                $stat = fstat($handle);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 0) {
                    throw new RuntimeException('Upload source has invalid file metadata.');
                }
                $source = $handle;
                $size = $stat['size'];
            } elseif ($descriptor['kind'] === 'string' && is_string($source)) {
                $size = strlen($source);
            } elseif ($descriptor['kind'] === 'stream' && $source instanceof StreamInterface) {
                $size = self::borrowedSize($source, $descriptor['size']);
            } else {
                throw new RuntimeException('Invalid upload descriptor.');
            }
            $prepared = new self();
            self::registry()[$prepared] = [
                'kind' => $descriptor['kind'],
                'filename' => $descriptor['filename'],
                'contentType' => $descriptor['contentType'],
                'source' => $source,
                'size' => $size,
                'consumed' => 0,
            ];
        } catch (Throwable) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Upload source could not be prepared.');
        }

        return $prepared;
    }

    /** Return the original exact remaining length, stable after reads. */
    public function size(): int
    {
        return $this->state()['size'];
    }

    /** Return the validated filename for internal multipart framing only. */
    public function filename(): string
    {
        return $this->state()['filename'];
    }

    /** Return the explicit validated content type, without sniffing. */
    public function contentType(): string
    {
        return $this->state()['contentType'];
    }

    /**
     * Read at most length bytes and never beyond the declared remainder.
     *
     * The multipart body controls bounded chunk sizes and rejects early EOF.
     * Failures can follow partial source consumption and never trigger retries.
     *
     * @throws RuntimeException For released sources, invalid read lengths or source failures.
     */
    public function read(int $length): string
    {
        $state = $this->state();
        if ($length < 0 || $state['source'] === null) {
            throw new RuntimeException('Upload source is unavailable or read length is invalid.');
        }
        $length = min($length, $state['size'] - $state['consumed']);
        if ($length <= 0) {
            return '';
        }
        try {
            if (is_string($state['source'])) {
                $bytes = substr($state['source'], $state['consumed'], $length);
            } elseif ($state['source'] instanceof StreamInterface) {
                $bytes = $state['source']->read($length);
            } else {
                $bytes = fread($state['source'], $length);
                if ($bytes === false) {
                    throw new RuntimeException('Upload source read failed.');
                }
            }
            if (strlen($bytes) > $length) {
                throw new RuntimeException('Upload source read exceeded its requested length.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Upload source could not be read.');
        }
        $state['consumed'] += strlen($bytes);
        self::registry()[$this] = $state;

        return $bytes;
    }

    /** Close only the library-owned file handle; repeated calls are harmless. */
    public function release(): void
    {
        $state = self::registry()[$this] ?? null;
        if ($state === null || $state['source'] === null) {
            return;
        }
        if (is_resource($state['source'])) {
            fclose($state['source']);
        }
        $state['source'] = null;
        self::registry()[$this] = $state;
    }

    public function __destruct()
    {
        $this->release();
    }

    /** @return array{kind: string} */
    public function __debugInfo(): array
    {
        return ['kind' => self::registry()[$this]['kind'] ?? 'invalid'];
    }

    /**
     * @return array<string, mixed>
     * @throws LogicException Always.
     */
    public function __serialize(): array
    {
        throw new LogicException('Prepared upload sources cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     * @throws LogicException Always.
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Prepared upload sources cannot be unserialized.');
    }

    /** @return WeakMap<self, State> */
    private static function registry(): WeakMap
    {
        return self::$states ??= new WeakMap();
    }

    /** @return State */
    private function state(): array
    {
        return self::registry()[$this] ?? throw new LogicException('Invalid prepared upload source.');
    }

    private static function borrowedSize(#[SensitiveParameter] StreamInterface $stream, ?int $asserted): int
    {
        if (!$stream->isReadable()) {
            throw new RuntimeException('Upload stream must be readable.');
        }
        try {
            $total = $stream->getSize();
        } catch (Throwable) {
            $total = null;
        }
        try {
            $position = $stream->tell();
        } catch (Throwable) {
            $position = null;
        }
        if (
            ($total !== null && $total < 0) || ($position !== null && $position < 0)
            || ($total !== null && $position !== null && $position > $total)
        ) {
            throw new RuntimeException('Upload stream has invalid length metadata.');
        }
        $derived = $total !== null && $position !== null ? $total - $position : null;
        if (
            ($asserted !== null && $derived !== null && $asserted !== $derived)
            || ($asserted === null && $derived === null)
        ) {
            throw new RuntimeException('Upload stream requires an exact remaining length.');
        }

        return $asserted ?? $derived;
    }
}
