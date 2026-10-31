<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use WeakMap;

/**
 * An immutable description of one multipart file source, without source I/O.
 *
 * Paths are anchored at factory time and opened only during preparation. Strings
 * retain arbitrary bytes. Streams are borrowed from their call-time cursor and
 * are never rewound, closed or detached by the library. Callers must not mutate
 * sources during a call. No MIME sniffing or filename rewriting occurs.
 *
 * Path syntax is POSIX-style: absolute slash-prefixed paths or relative paths
 * anchored to the factory working directory. URI schemes, double-slash network
 * paths, UNC paths and Windows drive paths are rejected. Native Windows path
 * support is deferred; Windows callers can supply bytes with fromString() or
 * borrow a PSR stream with fromStream() instead.
 *
 * Source data lives in a weak-key registry rather than instance properties:
 * var_dump(), print_r() and var_export() cannot reveal it. Serialization is
 * refused. Intentional internal access/reflection and caller-owned PSR requests
 * remain outside that diagnostic boundary.
 *
 * @phpstan-type Descriptor array{kind: 'path'|'string'|'stream', filename: string,
 *     contentType: string, source: string|StreamInterface, size: int|null}
 */
final class Upload
{
    /** @var WeakMap<self, Descriptor>|null */
    private static ?WeakMap $descriptors = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * Describe a local file without checking existence or opening it.
     *
     * Relative paths use the current working directory; symlinks to regular files
     * are allowed at preparation. Omitted filename uses the path's basename.
     *
     * @throws InvalidArgumentException For invalid local paths or metadata.
     */
    public static function fromPath(
        #[SensitiveParameter]
        string $path,
        #[SensitiveParameter]
        ?string $filename = null,
        #[SensitiveParameter]
        string $contentType = 'application/octet-stream',
    ): self {
        if (
            $path === '' || str_contains($path, "\0") || str_starts_with($path, '//')
            || str_starts_with($path, '\\\\')
            || preg_match('~\A[a-zA-Z][a-zA-Z0-9+.-]*:~', $path) === 1
        ) {
            throw new InvalidArgumentException('Upload path must be a local filesystem path.');
        }
        $filename ??= basename($path);
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new InvalidArgumentException('Upload working directory is unavailable.');
            }
            $path = $cwd . '/' . $path;
        }

        return self::create('path', $filename, $contentType, $path, null);
    }

    /**
     * Describe exact bytes, including empty, NUL and non-UTF-8 content.
     *
     * @throws InvalidArgumentException For invalid filename or content type.
     */
    public static function fromString(
        #[SensitiveParameter]
        string $filename,
        #[SensitiveParameter]
        string $contents,
        #[SensitiveParameter]
        string $contentType = 'application/octet-stream',
    ): self {
        return self::create('string', $filename, $contentType, $contents, null);
    }

    /**
     * Borrow a PSR stream without inspecting or changing it.
     *
     * Size asserts the entire remaining length at preparation, never a prefix.
     * Null requests call-time getSize()-tell() derivation. Unknown length requires
     * an explicit size; disagreement with a derivable length rejects locally.
     *
     * @throws InvalidArgumentException For invalid metadata or negative size.
     */
    public static function fromStream(
        #[SensitiveParameter]
        string $filename,
        #[SensitiveParameter]
        StreamInterface $stream,
        #[SensitiveParameter]
        string $contentType = 'application/octet-stream',
        ?int $size = null,
    ): self {
        if ($size !== null && $size < 0) {
            throw new InvalidArgumentException('Upload remaining size must be nonnegative.');
        }

        return self::create('stream', $filename, $contentType, $stream, $size);
    }

    /**
     * Return source data solely for internal preparation, never diagnostics.
     *
     * @internal
     * @return Descriptor
     */
    public function descriptor(): array
    {
        return self::$descriptors[$this] ?? throw new LogicException('Invalid upload descriptor.');
    }

    /** @return array{kind: string} */
    public function __debugInfo(): array
    {
        return ['kind' => self::$descriptors[$this]['kind'] ?? 'invalid'];
    }

    /**
     * @return array<string, mixed>
     * @throws LogicException Always; descriptors are reusable but not serializable.
     */
    public function __serialize(): array
    {
        throw new LogicException('Upload descriptors cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     * @throws LogicException Always.
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Upload descriptors cannot be unserialized.');
    }

    /** @param 'path'|'string'|'stream' $kind */
    private static function create(
        string $kind,
        #[SensitiveParameter]
        string $filename,
        #[SensitiveParameter]
        string $contentType,
        #[SensitiveParameter]
        string|StreamInterface $source,
        ?int $size,
    ): self {
        if (
            $filename === '' || $filename === '.' || $filename === '..'
            || preg_match('//u', $filename) !== 1
            || preg_match('~[\x00-\x1f\x7f/\\\\"]~', $filename) === 1
        ) {
            throw new InvalidArgumentException('Upload filename must be a safe UTF-8 basename.');
        }
        $token = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
        if (preg_match('@\A' . $token . '/' . $token . '\z@D', $contentType) !== 1) {
            throw new InvalidArgumentException('Upload content type must be an ASCII type/subtype token pair.');
        }
        $upload = new self();
        self::$descriptors ??= new WeakMap();
        self::$descriptors[$upload] = compact('kind', 'filename', 'contentType', 'source', 'size');

        return $upload;
    }
}
