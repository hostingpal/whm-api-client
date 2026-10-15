<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use Http\Mock\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProbeCatalogTest extends TestCase
{
    /** @param array<mixed>|null $recipe */
    #[DataProvider('recipes')]
    public function testUnreviewedRecipesNeverDispatch(?array $recipe, string $outcome): void
    {
        $root = sys_get_temp_dir() . '/probe-recipes-' . bin2hex(random_bytes(6));
        mkdir($root . '/docs/api/overlay/whm', 0700, true);
        mkdir($root . '/docs/api/overlay/uapi', 0700, true);
        $server = $root . '/docs/api/overlay/whm/server-administration.json';
        $domains = $root . '/docs/api/overlay/uapi/DomainInfo.json';
        file_put_contents($server, json_encode(['operations' => [
            'whm1:version' => $recipe === null ? [] : ['probe' => $recipe],
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($domains, '{"operations":{}}');
        try {
            $probes = ProbeCatalog::load($root);
            self::assertSame($outcome, $probes[0]->unavailable);
            $http = new Client();
            $records = (new EvidenceRunner($http, [
                'revision' => str_repeat('a', 40),
                'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
                'map_sha256' => str_repeat('d', 64),
                'generator_tree' => str_repeat('e', 40),
                'overlay_sha256' => str_repeat('f', 64),
            ]))->run($probes, '1', ['whm' => 'synthetic', 'cpanel' => 'synthetic']);
            self::assertSame($outcome, $records[0]['probe_outcome']);
            self::assertCount(0, $http->getRequests());
            self::assertSame('skipped', $records[3]['probe_outcome']);
        } finally {
            unlink($server);
            unlink($domains);
            rmdir(dirname($server));
            rmdir(dirname($domains));
            rmdir($root . '/docs/api/overlay');
            rmdir($root . '/docs/api');
            rmdir($root . '/docs');
            rmdir($root);
        }
    }

    /** @return iterable<string, array{array<mixed>|null, string}> */
    public static function recipes(): iterable
    {
        yield 'missing recipe' => [null, 'skipped'];
        yield 'mutation phase' => [[
            'phase' => 'account', 'arguments' => [], 'routes' => ['whm'], 'expected' => 'success',
        ], 'unsupported'];
        yield 'unexpected account argument' => [[
            'phase' => 'read-only', 'arguments' => ['account' => 'other'],
            'routes' => ['whm'], 'expected' => 'success',
        ], 'unsupported'];
        yield 'unreviewed route' => [[
            'phase' => 'read-only', 'arguments' => [], 'routes' => ['direct-uapi'], 'expected' => 'success',
        ], 'unsupported'];
        yield 'unreviewed extra option' => [[
            'phase' => 'read-only', 'arguments' => [], 'routes' => ['whm'],
            'expected' => 'success', 'command' => 'private-command',
        ], 'unsupported'];
        yield 'coerced success' => [[
            'phase' => 'read-only', 'arguments' => [], 'routes' => ['whm'], 'expected' => true,
        ], 'unsupported'];
    }
}
