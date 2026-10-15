<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
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
        $email = $root . '/docs/api/overlay/uapi/Email.json';
        $inventory = [
            $root . '/docs/api/overlay/whm/accounts.json',
            $root . '/docs/api/overlay/whm/web-server-configuration.json',
            $root . '/docs/api/overlay/uapi/LangPHP.json',
        ];
        file_put_contents($server, json_encode(['operations' => [
            'whm1:version' => $recipe === null ? [] : ['probe' => $recipe],
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($domains, '{"operations":{}}');
        file_put_contents($email, '{"operations":{}}');
        foreach ($inventory as $path) {
            file_put_contents($path, '{"operations":{}}');
        }
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
            unlink($email);
            foreach ($inventory as $path) {
                unlink($path);
            }
            rmdir(dirname($server));
            rmdir(dirname($domains));
            rmdir($root . '/docs/api/overlay');
            rmdir($root . '/docs/api');
            rmdir($root . '/docs');
            rmdir($root);
        }
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('changedInventoryArguments')]
    public function testChangedInventoryArgumentsBlockTheWholeRun(
        string $file,
        string $operation,
        array $arguments,
    ): void {
        $root = sys_get_temp_dir() . '/probe-inventory-' . bin2hex(random_bytes(6));
        $files = [
            'whm/server-administration.json', 'whm/accounts.json', 'whm/web-server-configuration.json',
            'uapi/DomainInfo.json', 'uapi/Email.json', 'uapi/LangPHP.json',
        ];
        mkdir($root . '/docs/api/overlay/whm', 0700, true);
        mkdir($root . '/docs/api/overlay/uapi', 0700, true);
        foreach ($files as $path) {
            $overlay = json_decode(
                (string) file_get_contents(__DIR__ . '/../../../docs/api/overlay/' . $path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($overlay);
            if ($path === $file) {
                $operations = $overlay['operations'];
                self::assertIsArray($operations);
                $entry = $operations[$operation] ?? [];
                self::assertIsArray($entry);
                $entry['probe'] = [
                    'phase' => 'read-only', 'arguments' => $arguments,
                    'routes' => str_starts_with($operation, 'whm1:') ? ['whm'] : ['direct-uapi', 'bridged-uapi'],
                    'expected' => 'success',
                ];
                $operations[$operation] = $entry;
                $overlay['operations'] = $operations;
            }
            file_put_contents($root . '/docs/api/overlay/' . $path, json_encode($overlay, JSON_THROW_ON_ERROR));
        }
        try {
            $probes = ProbeCatalog::load($root);
            $affected = array_values(array_filter($probes, static fn (Probe $probe): bool =>
                $probe->operation === $operation));
            self::assertNotEmpty($affected, 'The changed recipe must be bound to a reviewed generated method.');
            foreach ($affected as $probe) {
                self::assertSame('unsupported', $probe->unavailable);
            }
            $http = new Client();
            $records = (new EvidenceRunner($http, [
                'revision' => str_repeat('a', 40),
                'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
                'map_sha256' => str_repeat('d', 64),
                'generator_tree' => str_repeat('e', 40),
                'overlay_sha256' => str_repeat('f', 64),
            ]))->run($probes, '1', ['whm' => 'synthetic', 'cpanel' => 'synthetic']);
            self::assertCount(0, $http->getRequests());
            self::assertSame(
                ['unsupported_recipe'],
                array_values(array_unique(array_column($records, 'failure_kind'))),
            );
        } finally {
            foreach ($files as $path) {
                unlink($root . '/docs/api/overlay/' . $path);
            }
            rmdir($root . '/docs/api/overlay/whm');
            rmdir($root . '/docs/api/overlay/uapi');
            rmdir($root . '/docs/api/overlay');
            rmdir($root . '/docs/api');
            rmdir($root . '/docs');
            rmdir($root);
        }
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function changedInventoryArguments(): iterable
    {
        yield 'different account search' => ['whm/accounts.json', 'whm1:listaccts', [
            'search' => 'other', 'searchtype' => 'user', 'searchmethod' => 'exact',
        ]];
        yield 'regex account search' => ['whm/accounts.json', 'whm1:listaccts', [
            'search' => 'npr', 'searchtype' => 'user', 'searchmethod' => 'regex',
        ]];
        yield 'omitted search type' => ['whm/accounts.json', 'whm1:listaccts', [
            'search' => 'npr', 'searchmethod' => 'exact',
        ]];
        yield 'different account summary' => ['whm/accounts.json', 'whm1:accountsummary', ['user' => 'other']];
        yield 'missing summary account' => ['whm/accounts.json', 'whm1:accountsummary', []];
        yield 'different handler type' => ['uapi/LangPHP.json', 'uapi:LangPHP/php_get_domain_handler', [
            'type' => 'vhost',
        ]];
        yield 'additional handler argument' => ['uapi/LangPHP.json', 'uapi:LangPHP/php_get_domain_handler', [
            'type' => 'home', 'vhost' => 'other.test',
        ]];
        yield 'missing handler type' => ['uapi/LangPHP.json', 'uapi:LangPHP/php_get_domain_handler', []];
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
