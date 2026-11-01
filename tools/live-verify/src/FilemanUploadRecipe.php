<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Fixed identifiers, synthetic bytes and exact wire expectations for one disposable upload run.
 *
 * Everything is derived from one high-entropy per-run value, so each resource name is fresh. The file bytes are
 * deterministic, nonsecret and independent of any account data. Expected multipart bodies are assembled here by hand
 * rather than by the library's encoder, so the guard compares the client against a second implementation.
 *
 * @phpstan-type Label 'bytes'|'empty'|'cursor'|'path'|'partial'|'raw'
 * @phpstan-type UploadPhase 'upload_success'|'upload_partial'|'upload_all_failed'|'upload_raw'|'upload_empty'
 * @phpstan-type Part array{field: string, filename: string, bytes: string}
 */
final readonly class FilemanUploadRecipe
{
    public const ACCOUNT = 'wlupload';

    public const DOMAIN = 'wlupload.example.com';

    public const HOME = '/home/wlupload';

    public const TOKEN_NAME = 'wlupload-upload-fixture';

    public const PERMISSIONS = '0600';

    public const FROM_CHARSET = 'ISO-8859-1';

    public const TO_CHARSET = 'UTF-8';

    /** Sits before every borrowed cursor: a rewind would upload it, and the exact body check would refuse that. */
    public const STREAM_PREFIX = 'BORROWED-PREFIX-BEFORE-THE-CURSOR';

    /** Offered for an existing name with overwrite disabled, so readback can prove the original survived. */
    public const CONFLICT_BYTES = 'conflicting bytes that must never replace an owned file';

    /** Every label, in the order its file is first stored. */
    public const LABELS = ['bytes', 'cursor', 'path', 'partial', 'raw', 'empty'];

    private const DIRECT = 'https://test.oblak.host:2083/execute/Fileman/';

    public string $directory;

    public string $contactEmail;

    /** The exact account, domain, token and marker of this run, as the shared lifecycle binds them. */
    public DisposableAccount $account;

    /** @var array<Label, string> */
    public array $names;

    /** Bind one 128-bit lowercase hexadecimal run value and an exact token expiration; performs no I/O. */
    public function __construct(#[SensitiveParameter] string $entropy, public int $expiresAt)
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $entropy) !== 1 || $expiresAt < 1) {
            throw new InvalidArgumentException('Fileman upload recipe requires 128 bits of entropy and an expiration.');
        }
        $this->directory = 'wlupload-fixture-' . $entropy;
        $this->contactEmail = 'upload-' . $entropy . '@' . self::DOMAIN;
        $this->account = new DisposableAccount(
            self::ACCOUNT,
            self::DOMAIN,
            self::TOKEN_NAME,
            $this->contactEmail,
            $expiresAt,
            readOnlyToken: false,
        );
        $names = [];
        foreach (self::LABELS as $label) {
            $names[$label] = $label . '-' . substr($entropy, 0, 16) . '.bin';
        }
        $this->names = $names;
    }

    /**
     * Return one synthetic file: exact bytes, never derived from this run's identifiers.
     *
     * The first file carries all 256 byte values, extra NUL bytes and a CRLF. The path file exceeds one
     * 8192-byte read so its request has to stream in several blocks.
     *
     * @param Label $label
     */
    public static function contents(string $label): string
    {
        $all = '';
        for ($byte = 0; $byte < 256; ++$byte) {
            $all .= chr($byte);
        }

        return match ($label) {
            'bytes' => "\x00" . $all . "\x00\r\n\xff",
            'empty' => '',
            'cursor' => strrev($all),
            'path' => str_repeat("path\x00\xe9\r\n", 2500),
            'partial' => str_repeat("\x80\xffpartial\r\n", 40),
            'raw' => str_repeat("raw\x00\xc3\x28", 50),
        };
    }

    /**
     * Map bytes through ISO-8859-1 to UTF-8 by the code-point definition, with no conversion library.
     *
     * Each byte value N is exactly U+00NN, so distinct inputs have distinct outputs and any replacement character,
     * added encoding layer, truncation or dropped NUL in a reply differs from this result.
     */
    public static function latin1ToUtf8(string $bytes): string
    {
        $mapped = '';
        for ($offset = 0, $length = strlen($bytes); $offset < $length; ++$offset) {
            $code = ord($bytes[$offset]);
            $mapped .= $code < 0x80 ? $bytes[$offset] : chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }

        return $mapped;
    }

    /** @param Label $label */
    public function relativePath(string $label): string
    {
        return $this->directory . '/' . $this->names[$label];
    }

    public function absoluteDirectory(): string
    {
        return self::HOME . '/' . $this->directory;
    }

    /** @param Label $label */
    public function absolutePath(string $label): string
    {
        return $this->absoluteDirectory() . '/' . $this->names[$label];
    }

    /**
     * Ordered file parts of one upload; a refused part reuses an existing owned name with conflicting bytes.
     *
     * @param UploadPhase $phase
     * @return non-empty-list<Part>
     */
    public function parts(string $phase): array
    {
        $files = match ($phase) {
            'upload_success' => [
                [$this->names['bytes'], self::contents('bytes')],
                [$this->names['cursor'], self::contents('cursor')],
                [$this->names['path'], self::contents('path')],
            ],
            'upload_partial' => [
                [$this->names['bytes'], self::CONFLICT_BYTES],
                [$this->names['partial'], self::contents('partial')],
            ],
            'upload_all_failed' => [[$this->names['cursor'], self::CONFLICT_BYTES]],
            'upload_raw' => [[$this->names['raw'], self::contents('raw')]],
            'upload_empty' => [[$this->names['empty'], self::contents('empty')]],
        };
        $parts = [];
        foreach ($files as $index => [$filename, $bytes]) {
            $parts[] = ['field' => 'file-' . $index, 'filename' => $filename, 'bytes' => $bytes];
        }

        return $parts;
    }

    /**
     * Assemble the exact multipart bytes of one upload, around the boundary the client selected.
     *
     * @param UploadPhase $phase
     */
    public function expectedBody(string $phase, string $boundary): string
    {
        $body = '';
        foreach ($this->parts($phase) as $part) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $part['field'] . '"; filename="' . $part['filename']
                . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $part['bytes'] . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /**
     * Exact upload URI. The success call alone asks for disk usage; overwrite is always explicitly disabled.
     *
     * @param UploadPhase $phase
     */
    public function uploadUri(string $phase): string
    {
        return self::DIRECT . 'upload_files?dir=' . rawurlencode($this->directory)
            . ($phase === 'upload_success' ? '&get_disk_info=1' : '') . '&overwrite=0&permissions=0600';
    }

    /** Home listing: enough to read the fresh directory's absence, with no hidden entries. */
    public function homeListUri(): string
    {
        return self::DIRECT . 'list_files?dir=' . rawurlencode(self::HOME) . '&types=dir%7Cfile';
    }

    /**
     * Fixture directory listing with hidden entries and no type filter, which returns every file type.
     *
     * A type filter would hide a symbolic link or other special entry from the checks that guard the
     * upstream-recursive directory delete.
     */
    public function directoryListUri(): string
    {
        return self::DIRECT . 'list_files?dir=' . rawurlencode($this->directory)
            . '&include_permissions=1&show_hidden=1';
    }

    /**
     * Exact readback URI with both charsets explicit and HTML document rewriting disabled.
     *
     * @param Label $label
     */
    public function readUri(string $label): string
    {
        return self::DIRECT . 'get_file_content?dir=' . rawurlencode($this->absoluteDirectory())
            . '&file=' . rawurlencode($this->names[$label]) . '&from_charset=' . rawurlencode(self::FROM_CHARSET)
            . '&to_charset=' . rawurlencode(self::TO_CHARSET) . '&update_html_document_encoding=0';
    }

    public function deleteUri(): string
    {
        return self::DIRECT . 'delete_file';
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The recipe holds every run identifier and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Fileman upload recipe cannot be serialized.');
    }
}
