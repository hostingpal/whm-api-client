<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SensitiveParameter;
use SplFileInfo;
use Throwable;

/**
 * Bind evidence to clean runtime, generator, overlay and pinned-source inputs.
 *
 * Git inspection is read-only. Evidence and tests may change during a capture;
 * effective code and source inputs must match HEAD, including untracked files.
 * Only fixed hashes leave this boundary; failures never expose paths, command
 * output or nested exceptions.
 *
 * @internal Development evidence tooling, independent of live credentials.
 * @phpstan-type Context array{
 *     revision: string,
 *     source_hashes: array{whm1: string, uapi: string},
 *     map_sha256: string,
 *     generator_tree: string,
 *     overlay_sha256: string
 * }
 */
final class RepositoryContext
{
    private const INPUTS = [
        'src',
        'tools',
        'composer.json',
        'composer.lock',
        'docs/api/overlay',
        'docs/api/sources',
        'docs/api/map.sha256',
        'docs/api/annotations.json',
    ];

    private const SNAPSHOT = 'docs/api/sources/2026-10-13/';

    /**
     * Verify the checkout and return hashes of its effective generation inputs.
     *
     * Source hashes cover decompressed bytes and must match the pinned manifest.
     * The overlay digest covers every regular file, sorted by relative path,
     * using records of relative path, NUL, lowercase SHA256 and newline. Ignored
     * files in protected directories also invalidate attribution. Symlinks in
     * hashed inputs are rejected so external files cannot change attribution.
     *
     * @return Context
     * @throws RuntimeException If any repository input cannot be verified.
     */
    public static function load(#[SensitiveParameter] string $root): array
    {
        try {
            $canonical = realpath($root);
            if ($canonical === false || self::git($canonical, ['rev-parse', '--show-toplevel']) !== $canonical) {
                throw new RuntimeException('Invalid repository root.');
            }

            self::clean($canonical);
            $revision = self::gitHash($canonical, 'HEAD');
            $generator = self::gitHash($canonical, 'HEAD:tools/api-map');
            if (self::git($canonical, ['cat-file', '-t', 'HEAD:tools/api-map']) !== 'tree') {
                throw new RuntimeException('Invalid generator tree.');
            }

            $manifest = self::json(self::read($canonical . '/' . self::SNAPSHOT . 'manifest.json'));
            $context = [
                'revision' => $revision,
                'source_hashes' => [
                    'whm1' => self::source($canonical, $manifest, 'whm.openapi.json.gz'),
                    'uapi' => self::source($canonical, $manifest, 'cpanel.openapi.json.gz'),
                ],
                'map_sha256' => self::mapHash($canonical),
                'generator_tree' => $generator,
                'overlay_sha256' => self::overlayHash($canonical),
            ];
            self::clean($canonical);
            if (self::gitHash($canonical, 'HEAD') !== $revision) {
                throw new RuntimeException('Repository revision changed.');
            }

            return $context;
        } catch (Throwable) {
            throw new RuntimeException('Repository context could not be verified.');
        }
    }

    private static function clean(string $root): void
    {
        if (
            self::git($root, ['status', '--porcelain=v1', '--untracked-files=all', '--', ...self::INPUTS]) !== ''
            || self::git($root, ['ls-files', '--others', '--', ...self::INPUTS]) !== ''
        ) {
            throw new RuntimeException('Effective repository inputs are dirty.');
        }

        // Index flags can hide working-tree edits from status. Symlinks and
        // submodules can load code from outside the checked revision.
        foreach (explode("\0", self::git($root, ['ls-files', '-v', '-z', '--', ...self::INPUTS])) as $entry) {
            if ($entry !== '' && !str_starts_with($entry, 'H ')) {
                throw new RuntimeException('Unverifiable repository index flags.');
            }
        }

        foreach (explode("\0", self::git($root, ['ls-files', '--stage', '-z', '--', ...self::INPUTS])) as $entry) {
            if ($entry !== '' && preg_match('/\A100(?:644|755) [a-f0-9]{40} 0\t/', $entry) !== 1) {
                throw new RuntimeException('Unverifiable repository input mode.');
            }
        }
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments): string
    {
        $command = ['git', '--no-optional-locks', '-C', $root, ...$arguments];
        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>/dev/null', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Repository inspection failed.');
        }

        return implode("\n", $output);
    }

    private static function gitHash(string $root, string $object): string
    {
        $hash = self::git($root, ['rev-parse', '--verify', $object]);
        if (preg_match('/\A[a-f0-9]{40}\z/', $hash) !== 1) {
            throw new RuntimeException('Invalid repository object hash.');
        }

        return $hash;
    }

    private static function read(string $path): string
    {
        $value = is_file($path) && !is_link($path) ? @file_get_contents($path) : false;
        if ($value === false) {
            throw new RuntimeException('Repository input could not be read.');
        }

        return $value;
    }

    /**
     * @return array<mixed>
     * @throws JsonException If the manifest is not JSON.
     */
    private static function json(string $contents): array
    {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid source manifest.');
        }

        return $decoded;
    }

    /** @param array<mixed> $manifest */
    private static function source(string $root, array $manifest, string $filename): string
    {
        $sources = $manifest['sources'] ?? null;
        if (!is_array($sources)) {
            throw new RuntimeException('Invalid source manifest.');
        }

        $matches = array_values(array_filter($sources, static fn (mixed $source): bool =>
            is_array($source) && ($source['file'] ?? null) === $filename));
        $expected = count($matches) === 1 ? ($matches[0]['sha256'] ?? null) : null;
        if (!is_string($expected) || preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1) {
            throw new RuntimeException('Invalid source hash.');
        }

        $decoded = @gzdecode(self::read($root . '/' . self::SNAPSHOT . $filename));
        if (!is_string($decoded) || hash('sha256', $decoded) !== $expected) {
            throw new RuntimeException('Pinned source hash mismatch.');
        }

        return $expected;
    }

    private static function mapHash(string $root): string
    {
        $checksum = self::read($root . '/docs/api/map.sha256');
        if (preg_match('~\A([a-f0-9]{64})[ \t]+\*?\.cache/api-map/map\.json(?:\r?\n)?\z~', $checksum, $match) !== 1) {
            throw new RuntimeException('Invalid reviewed map checksum.');
        }

        return $match[1];
    }

    private static function overlayHash(string $root): string
    {
        $directory = $root . '/docs/api/overlay';
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Invalid overlay directory.');
        }

        $files = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->isLink()) {
                throw new RuntimeException('Invalid overlay entry.');
            }

            if ($entry->isFile()) {
                $files[substr($entry->getPathname(), strlen($directory) + 1)] = hash(
                    'sha256',
                    self::read($entry->getPathname()),
                );
            } elseif (!$entry->isDir()) {
                throw new RuntimeException('Invalid overlay entry.');
            }
        }

        ksort($files, SORT_STRING);
        $records = '';
        foreach ($files as $path => $hash) {
            $records .= $path . "\0" . $hash . "\n";
        }

        return hash('sha256', $records);
    }
}
