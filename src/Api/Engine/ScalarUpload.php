<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/** Validate the reviewed WHM required literal text and single file body before source I/O. @internal */
final class ScalarUpload
{
    /**
     * @param array<mixed> $spec
     * @return array{php: string, source: 'file', text: 'module'}
     */
    public static function descriptor(array $spec): array
    {
        $body = $spec['multipart_upload'] ?? null;
        if (
            ($spec['body'] ?? null) !== 'multipart' || ($spec['method'] ?? null) !== 'POST'
            || array_key_exists('module', $spec) || array_key_exists('multipart_uploads', $spec)
            || array_key_exists('json_body', $spec) || ($spec['params'] ?? null) !== []
            || !is_array($body) || count($body) !== 3 || !is_string($body['php'] ?? null)
            || preg_match('/\A[a-z][A-Za-z0-9]*\z/', $body['php']) !== 1
            || $body['php'] === 'module' || ($body['source'] ?? null) !== 'file'
            || ($body['text'] ?? null) !== 'module'
        ) {
            throw new LogicException('Invalid scalar multipart upload spec.');
        }
        return ['php' => $body['php'], 'source' => 'file', 'text' => 'module'];
    }

    /**
     * @param array{php: string, source: 'file', text: 'module'} $descriptor
     * @param array<string, mixed> $arguments
     * @return list<array{name: string, value: string|Upload}>
     */
    public static function parts(array $descriptor, #[SensitiveParameter] array &$arguments): array
    {
        $text = $arguments[$descriptor['text']] ?? null;
        $file = $arguments[$descriptor['php']] ?? null;
        if (!is_string($text) || !$file instanceof Upload) {
            throw new InvalidArgumentException('Required multipart text or upload is invalid.');
        }
        unset($arguments[$descriptor['text']], $arguments[$descriptor['php']]);
        return [['name' => $descriptor['text'], 'value' => $text], ['name' => $descriptor['source'], 'value' => $file]];
    }
}
