<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;

/** Fixed directory inventory reads; no file content is read and no filesystem mutation is sent. */
final class FilemanProbes
{
    /** @return list<Probe> */
    public static function load(): array
    {
        $probes = [new Probe(
            'whm1:version',
            'whm',
            ServerSpec::VERSION,
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            static fn (Client $client): mixed => $client->whm()->server()->version(),
        )];
        foreach (
            [
                ['autocompletedir', FilemanSpec::AUTOCOMPLETEDIR,
                    'path=public&dirsonly=0&html=0&list_all=0',
                    static fn (UapiClient $account): array => $account->fileman()->autocompleteDirectory(
                        path: 'public',
                        dirsOnly: false,
                        html: false,
                        listAll: false,
                    )],
                ['get_file_information', FilemanSpec::GET_FILE_INFORMATION,
                    'path=public_html&check_for_leaf_directories=1&include_mime=1'
                        . '&include_permissions=1&show_hidden=0',
                    static fn (UapiClient $account): array => $account->fileman()->getFileInformation(
                        path: 'public_html',
                        checkForLeafDirectories: true,
                        includeMime: true,
                        includePermissions: true,
                        showHidden: false,
                    )],
                ['list_files', FilemanSpec::LIST_FILES,
                    'dir=public_html&check_for_leaf_directories=1&include_mime=1'
                        . '&include_permissions=1&show_hidden=0&types=dir%7Cfile',
                    static fn (UapiClient $account): array => $account->fileman()->listFiles(
                        dir: 'public_html',
                        checkForLeafDirectories: true,
                        includeMime: true,
                        includePermissions: true,
                        showHidden: false,
                        types: 'dir|file',
                    )],
            ] as [$function, $spec, $parameters, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $direct = $route === 'direct-uapi';
                $uri = $direct
                    ? 'https://test.oblak.host:2083/execute/Fileman/' . $function
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=Fileman&cpanel.function=' . $function;
                $uri .= ($direct ? '?' : '&') . $parameters;
                $probes[] = self::probe($function, $route, $spec, $uri, $invoke);
            }
        }

        return $probes;
    }

    /**
     * @param 'direct-uapi'|'bridged-uapi' $route
     * @param array<mixed> $spec
     * @param Closure(UapiClient): mixed $invoke
     */
    private static function probe(string $function, string $route, array $spec, string $uri, Closure $invoke): Probe
    {
        return new Probe(
            'uapi:Fileman/' . $function,
            $route,
            $spec,
            $uri,
            static fn (Client $client): mixed => $invoke($client->uapi($route === 'direct-uapi' ? null : 'npr')),
        );
    }
}
