<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Fileman;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/**
 * Seven read-only probes bound to one existing zero-byte file directly under the designated account's mail directory.
 *
 * The runtime basename stays private; only structural original-response fingerprints are persisted.
 * Metadata confirms the selected file without reading content or creating/changing any resource.
 * Expectation failures use the existing conservative probe_failure outcome: HTTP and raw structural
 * evidence remain available, while API/payload success is not asserted for a failed invoke callback.
 *
 * @phpstan-import-type ListFilesResult from Fileman
 */
final class FilemanReviewProbes
{
    /**
     * Accept one non-hidden direct-child basename; never accept absolute, nested or escaped paths.
     *
     * The parent independently selects the existing file through authorized metadata inspection.
     * No default filename, fallback search or filesystem mutation is performed by this catalog.
     *
     * @return list<Probe>
     */
    public static function load(#[SensitiveParameter] string $path): array
    {
        if (preg_match('/\Amail\/([A-Za-z0-9_-][A-Za-z0-9_.-]{0,254})\z/', $path, $matches) !== 1) {
            throw new LogicException('Fileman review requires one safe direct child of mail.');
        }
        $basename = $matches[1];
        $probes = array_values(array_filter(FilemanProbes::load(), static fn (Probe $probe): bool =>
            in_array($probe->operation, ['whm1:version', 'uapi:Fileman/autocompletedir'], true)));
        foreach (['get_file_information', 'list_files'] as $function) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $information = $function === 'get_file_information';
                $spec = $information ? FilemanSpec::GET_FILE_INFORMATION : FilemanSpec::LIST_FILES;
                $parameters = $information ? 'path=' . rawurlencode($path) : 'dir=mail';
                $parameters .= '&check_for_leaf_directories=1&include_mime=1&include_permissions=1&show_hidden=0';
                if (!$information) {
                    $parameters .= '&types=dir%7Cfile';
                }
                $direct = $route === 'direct-uapi';
                $uri = $direct ? 'https://test.oblak.host:2083/execute/Fileman/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=Fileman&cpanel.function=' . $function;
                $uri .= ($direct ? '?' : '&') . $parameters;
                $probes[] = new Probe(
                    'uapi:Fileman/' . $function,
                    $route,
                    $spec,
                    $uri,
                    static function (#[SensitiveParameter] Client $client) use (
                        $information,
                        $direct,
                        $path,
                        $basename,
                    ): array {
                        $group = $client->uapi($direct ? null : 'npr')->fileman();
                        if ($information) {
                            $result = $group->getFileInformation(
                                path: $path,
                                checkForLeafDirectories: true,
                                includeMime: true,
                                includePermissions: true,
                                showHidden: false,
                            );
                            self::requireZeroFile($result, $basename);

                            return $result;
                        }
                        $result = $group->listFiles(
                            dir: 'mail',
                            checkForLeafDirectories: true,
                            includeMime: true,
                            includePermissions: true,
                            showHidden: false,
                            types: 'dir|file',
                        );
                        self::requireListing($result, $basename);

                        return $result;
                    },
                );
            }
        }

        return $probes;
    }

    /**
     * Independently assert only the selected metadata fields shared by both generated listing forms.
     *
     * @param array<mixed> $row
     */
    private static function requireZeroFile(
        #[SensitiveParameter] array $row,
        #[SensitiveParameter] string $basename,
    ): void {
        if (
            ($row['file'] ?? null) !== $basename || ($row['fullpath'] ?? null) !== '/home/npr/mail/' . $basename
            || ($row['exists'] ?? null) !== 1 || ($row['type'] ?? null) !== 'file'
            || !in_array($row['size'] ?? null, [0, ''], true)
        ) {
            throw new RuntimeException('Expected zero-byte file metadata was not observed.');
        }
    }

    /** @param ListFilesResult $result */
    private static function requireListing(
        #[SensitiveParameter] array $result,
        #[SensitiveParameter] string $basename,
    ): void {
        $rows = array_is_list($result) ? $result : ($result['files'] ?? []);
        $matching = 0;
        foreach ($rows as $row) {
            if (($row['file'] ?? null) === $basename) {
                ++$matching;
                self::requireZeroFile($row, $basename);
            }
        }
        if ($matching !== 1) {
            throw new RuntimeException('Expected one selected zero-byte file in the directory inventory.');
        }
    }
}
