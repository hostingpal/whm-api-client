<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use JsonException;
use RuntimeException;

/** Bind overlay recipes to reviewed generated calls; recipes never execute arbitrary methods or arguments. */
final class ProbeCatalog
{
    /** @return list<Probe> */
    public static function load(string $root): array
    {
        $server = self::read($root . '/docs/api/overlay/whm/server-administration.json');
        $domains = self::read($root . '/docs/api/overlay/uapi/DomainInfo.json');

        return [
            self::probe(
                $server,
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->version(),
            ),
            self::probe(
                $server,
                'whm1:systemloadavg',
                'whm',
                ServerSpec::SYSTEMLOADAVG,
                'https://test.oblak.host:2087/json-api/systemloadavg?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->getLoadAverage(),
            ),
            self::probe(
                $domains,
                'uapi:DomainInfo/list_domains',
                'direct-uapi',
                DomainInfoSpec::LIST_DOMAINS,
                'https://test.oblak.host:2083/execute/DomainInfo/list_domains',
                static fn (Client $client): mixed => $client->uapi()->domainInfo()->listDomains(),
            ),
            self::probe(
                $domains,
                'uapi:DomainInfo/list_domains',
                'bridged-uapi',
                DomainInfoSpec::LIST_DOMAINS,
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=list_domains',
                static fn (Client $client): mixed => $client->uapi('npr')->domainInfo()->listDomains(),
            ),
        ];
    }

    /**
     * Absent recipes are skipped; unsupported recipes cause the entire run to fail closed before I/O.
     *
     * @param array<mixed> $overlay
     * @param 'whm'|'direct-uapi'|'bridged-uapi' $route
     * @param array<mixed> $spec
     * @param Closure(Client): mixed $invoke
     */
    private static function probe(
        array $overlay,
        string $operation,
        string $route,
        array $spec,
        string $uri,
        Closure $invoke,
    ): Probe {
        $operations = $overlay['operations'] ?? null;
        $entry = is_array($operations) ? ($operations[$operation] ?? null) : null;
        $recipe = is_array($entry) ? ($entry['probe'] ?? null) : null;
        $unavailable = null;
        if ($recipe === null) {
            $unavailable = 'skipped';
        } else {
            $expected = [
                'phase' => 'read-only',
                'arguments' => [],
                'routes' => $route === 'whm' ? ['whm'] : ['direct-uapi', 'bridged-uapi'],
                'expected' => 'success',
            ];
            if (is_array($recipe)) {
                ksort($recipe);
            }
            ksort($expected);
            if ($recipe !== $expected || ($spec['method'] ?? null) !== 'GET') {
                $unavailable = 'unsupported';
            }
        }

        return new Probe($operation, $route, $spec, $uri, $invoke, $unavailable);
    }

    /** @return array<mixed> */
    private static function read(string $path): array
    {
        $bytes = @file_get_contents($path);
        try {
            $value = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $value = null;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Cannot read reviewed probe recipes.');
        }

        return $value;
    }
}
