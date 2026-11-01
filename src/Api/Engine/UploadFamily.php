<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/** Validate the reviewed scalar or one-based Upload list without source I/O. @internal */
final class UploadFamily
{
    /**
     * @param array<mixed> $spec
     * @return array{php: string, source: 'file'|'backup', routes: 'direct'}
     */
    public static function descriptor(array $spec): array
    {
        $body = $spec['multipart_upload_family'] ?? null;
        $params = $spec['params'] ?? null;
        if (
            ($spec['body'] ?? null) !== 'multipart' || ($spec['method'] ?? null) !== 'POST'
            || !isset($spec['module']) || !is_array($body) || count($body) !== 3 || !is_array($params)
            || array_key_exists('multipart_upload', $spec) || array_key_exists('multipart_uploads', $spec)
            || array_key_exists('json_body', $spec) || !is_string($body['php'] ?? null)
            || preg_match('/\A[a-z][A-Za-z0-9]*\z/', $body['php']) !== 1
            || !in_array($body['source'] ?? null, ['file', 'backup'], true)
            || ($body['routes'] ?? null) !== 'direct' || array_key_exists($body['php'], $params)
        ) {
            throw new LogicException('Invalid multipart upload family spec.');
        }
        foreach ($params as $parameter) {
            $wire = is_array($parameter) ? ($parameter['name'] ?? null) : null;
            if (
                !is_string($wire) || !in_array($parameter['encoding'] ?? null, ['scalar', 'bool'], true)
                || $wire === 'backup' || str_starts_with($wire, 'backup-')
                || $wire === $body['source'] || str_starts_with($wire, $body['source'] . '-')
                || str_starts_with($wire, 'api.')
                || in_array($wire, ['cpanel.user', 'cpanel.module', 'cpanel.function'], true)
            ) {
                throw new LogicException('Invalid multipart upload family query spec.');
            }
        }
        return ['php' => $body['php'], 'source' => $body['source'], 'routes' => 'direct'];
    }

    /** @return non-empty-list<array{name: string, value: Upload}> */
    public static function parts(#[SensitiveParameter] mixed $uploads, string $source): array
    {
        if ($uploads instanceof Upload) {
            return [['name' => $source, 'value' => $uploads]];
        }
        if (!is_array($uploads) || $uploads === [] || !array_is_list($uploads)) {
            throw new InvalidArgumentException('Uploads require an Upload or nonempty sequential Upload list.');
        }
        $parts = [];
        foreach ($uploads as $index => $upload) {
            if (!$upload instanceof Upload) {
                throw new InvalidArgumentException('Invalid upload family item.');
            }
            $parts[] = ['name' => $source . '-' . ($index + 1), 'value' => $upload];
        }
        return $parts;
    }
}
