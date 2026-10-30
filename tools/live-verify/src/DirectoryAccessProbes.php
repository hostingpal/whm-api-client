<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DirectoryIndexesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DirectoryPrivacySpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DirectoryProtectionSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Reviewed reads of existing npr public_html; never changes indexing, access users or protection settings. */
final class DirectoryAccessProbes
{
    /** @return list<Probe> */
    public static function load(): array
    {
        return [
            new Probe(
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->version(),
            ),
            ...self::pair(
                'DirectoryIndexes',
                'get_indexing',
                DirectoryIndexesSpec::GET_INDEXING,
                static fn (UapiClient $account): mixed =>
                    $account->directoryIndexes()->getIndexing(dir: '/home/npr/public_html'),
            ),
            ...self::pair(
                'DirectoryIndexes',
                'list_directories',
                DirectoryIndexesSpec::LIST_DIRECTORIES,
                static fn (UapiClient $account): mixed =>
                    $account->directoryIndexes()->listDirectories(dir: '/home/npr/public_html'),
            ),
            ...self::pair(
                'DirectoryPrivacy',
                'is_directory_protected',
                DirectoryPrivacySpec::IS_DIRECTORY_PROTECTED,
                static fn (UapiClient $account): mixed =>
                    $account->directoryPrivacy()->isDirectoryProtected(dir: '/home/npr/public_html'),
            ),
            ...self::pair(
                'DirectoryPrivacy',
                'list_directories',
                DirectoryPrivacySpec::LIST_DIRECTORIES,
                static fn (UapiClient $account): mixed =>
                    $account->directoryPrivacy()->listDirectories(dir: '/home/npr/public_html'),
            ),
            ...self::pair(
                'DirectoryPrivacy',
                'list_users',
                DirectoryPrivacySpec::LIST_USERS,
                static fn (UapiClient $account): mixed =>
                    $account->directoryPrivacy()->listUsers(dir: '/home/npr/public_html'),
            ),
            ...self::pair(
                'DirectoryProtection',
                'list_directories',
                DirectoryProtectionSpec::LIST_DIRECTORIES,
                static fn (UapiClient $account): mixed =>
                    $account->directoryProtection()->listDirectories(dir: '/home/npr/public_html'),
            ),
        ];
    }

    /**
     * @param array<mixed> $spec
     * @param Closure(UapiClient): mixed $invoke
     * @return array{Probe, Probe}
     */
    private static function pair(string $module, string $function, array $spec, Closure $invoke): array
    {
        $operation = 'uapi:' . $module . '/' . $function;
        $query = 'dir=%2Fhome%2Fnpr%2Fpublic_html';
        return [
            new Probe(
                $operation,
                'direct-uapi',
                $spec,
                'https://test.oblak.host:2083/execute/' . $module . '/' . $function . '?' . $query,
                static fn (Client $client): mixed => $invoke($client->uapi()),
            ),
            new Probe(
                $operation,
                'bridged-uapi',
                $spec,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=' . $module . '&cpanel.function=' . $function . '&' . $query,
                static fn (Client $client): mixed => $invoke($client->uapi('npr')),
            ),
        ];
    }
}
