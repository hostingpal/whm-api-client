<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Validate the reviewed required binary upload list without opening sources.
 *
 * @internal
 */
final class UploadList
{
    /**
     * @param array<mixed> $spec
     * @return array{php: string, prefix: string, start: 0, routes: 'direct', filename_forbidden: '<>;'}
     */
    public static function descriptor(array $spec): array
    {
        $body = $spec['multipart_uploads'] ?? null;
        $params = $spec['params'] ?? null;
        if (
            ($spec['body'] ?? null) !== 'multipart' || ($spec['method'] ?? null) !== 'POST'
            || array_key_exists('multipart_upload_family', $spec)
            || !isset($spec['module']) || !is_array($body) || !is_array($params)
            || !is_string($body['php'] ?? null) || preg_match('/\A[a-z][A-Za-z0-9]*\z/', $body['php']) !== 1
            || !is_string($body['prefix'] ?? null)
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $body['prefix']) !== 1
            || ($body['start'] ?? null) !== 0 || ($body['routes'] ?? null) !== 'direct'
            || ($body['filename_forbidden'] ?? null) !== '<>;'
            || array_diff(array_keys($body), ['php', 'prefix', 'start', 'routes', 'filename_forbidden']) !== []
            || array_key_exists($body['php'], $params)
        ) {
            throw new LogicException('Invalid multipart upload list spec.');
        }
        foreach ($params as $parameter) {
            if (
                !is_array($parameter) || !is_string($parameter['name'] ?? null)
                || !in_array($parameter['encoding'] ?? null, ['scalar', 'bool'], true)
                || str_starts_with($parameter['name'], $body['prefix'])
                || str_starts_with($parameter['name'], 'api.')
                || in_array($parameter['name'], ['cpanel.user', 'cpanel.module', 'cpanel.function'], true)
            ) {
                throw new LogicException('Invalid multipart upload query spec.');
            }
        }
        return ['php' => $body['php'], 'prefix' => $body['prefix'], 'start' => 0,
            'routes' => 'direct', 'filename_forbidden' => '<>;'];
    }

    /**
     * Validate the entire original list before any source preparation or dispatch.
     *
     * @return non-empty-list<array{name: string, value: Upload}>
     */
    public static function parts(#[SensitiveParameter] mixed $files, string $prefix): array
    {
        if (!is_array($files) || $files === [] || !array_is_list($files)) {
            throw new InvalidArgumentException('Uploads require a nonempty sequential list of Upload values.');
        }
        $parts = [];
        foreach ($files as $index => $file) {
            if (!$file instanceof Upload || strpbrk($file->descriptor()['filename'], '<>;') !== false) {
                throw new InvalidArgumentException('Invalid upload value or filename.');
            }
            $parts[] = ['name' => $prefix . $index, 'value' => $file];
        }
        return $parts;
    }
}
