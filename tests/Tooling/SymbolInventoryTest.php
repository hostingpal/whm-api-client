<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tooling;

use Composer\Autoload\ClassLoader;
use HostingPal\WhmApiClient\Tools\SymbolInventory;
use PHPUnit\Framework\TestCase;

final class SymbolInventoryTest extends TestCase
{
    public function testRepositoryIncludesOnlyDeclaredPublicTransportAndTestMethods(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/tools/php-symbols/SymbolInventory.php');
        require_once dirname(__DIR__, 2) . '/tools/php-symbols/SymbolInventory.php';
        $inventory = (new SymbolInventory())->scan(dirname(__DIR__, 2));
        self::assertSame('1.0', $inventory['schema_version']);
        self::assertSame([], $inventory['diagnostics']);
        $transport = array_values(array_filter($inventory['symbols'], static fn (array $symbol): bool =>
            $symbol['declaring_class'] === 'HostingPal\\WhmApiClient\\Http\\HttpTransport'));
        self::assertSame(['constructor', 'method'], array_column($transport, 'kind'));
        self::assertSame(['__construct', 'send'], array_map(static fn (array $symbol): string =>
            substr($symbol['id'], (int) strrpos($symbol['id'], '::') + 2), $transport));
        $tests = array_values(array_filter($inventory['symbols'], static fn (array $symbol): bool =>
            $symbol['declaring_class'] === 'HostingPal\\WhmApiClient\\Tests\\Http\\HttpTransportTest'));
        self::assertCount(8, array_filter($tests, static fn (array $symbol): bool => $symbol['kind'] === 'test'));
        $providers = array_values(array_filter($tests, static fn (array $symbol): bool =>
            $symbol['kind'] === 'provider'));
        self::assertCount(2, $providers);
        self::assertSame([true, true], array_column($providers, 'static'));
        self::assertCount(10, $tests);
        self::assertSame($inventory, (new SymbolInventory())->scan(dirname(__DIR__, 2)));
    }

    public function testTokenDiscoveryAndReflectionNeverInvokeFixtureCode(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/tools/php-symbols/SymbolInventory.php');
        require_once dirname(__DIR__, 2) . '/tools/php-symbols/SymbolInventory.php';
        $root = sys_get_temp_dir() . '/whm-symbols-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/src', 0755, true));
        self::assertTrue(mkdir($root . '/tests', 0755, true));
        $fixture = dirname(__DIR__) . '/Fixtures/SymbolInventory';
        foreach (['src/Client', 'src/ExternalValues', 'src/Grouped', 'src/Missing', 'tests/SampleTest'] as $file) {
            self::assertTrue(copy($fixture . '/' . $file . '.php.fixture', $root . '/' . $file . '.php'));
        }
        $loader = new ClassLoader();
        $loader->addPsr4('FixtureInventory\\Tests\\', $root . '/tests');
        $loader->addPsr4('FixtureInventory\\', $root . '/src');
        $loader->addClassMap([
            'FixtureInventory\\DangerousAttribute' => $root . '/src/Client.php',
            'FixtureInventory\\Grouped\\First' => $root . '/src/Grouped.php',
            'FixtureInventory\\Grouped\\Second' => $root . '/src/Grouped.php',
            'FixtureInventory\\Other\\Third' => $root . '/src/Grouped.php',
        ]);
        $loader->register();
        try {
            $inventory = (new SymbolInventory())->scan($root);
            $byID = array_column($inventory['symbols'], null, 'id');
            self::assertArrayHasKey('FixtureInventory\\Grouped\\First::first', $byID);
            self::assertArrayHasKey('FixtureInventory\\Grouped\\Second::second', $byID);
            self::assertTrue($byID['FixtureInventory\\Other\\Third::third']['abstract']);
            self::assertSame('test', $byID['FixtureInventory\\Tests\\SampleTest::checksValues']['kind']);
            self::assertSame('provider', $byID['FixtureInventory\\Tests\\SampleTest::values']['kind']);
            self::assertSame(
                ['FixtureInventory\\Tests\\SampleTest::values'],
                $byID['FixtureInventory\\Tests\\SampleTest::checksValues']['providers'],
            );
            self::assertSame('provider', $byID['FixtureInventory\\ExternalValues::cases']['kind']);
            foreach (['testGroupedProvider', 'testAliasedGroupedProvider'] as $method) {
                self::assertSame(
                    ['FixtureInventory\\ExternalValues::cases'],
                    $byID['FixtureInventory\\Tests\\SampleTest::' . $method]['providers'],
                );
            }
            foreach (['testQualifiedGroupedProvider', 'testNamespaceRelativeProvider'] as $method) {
                self::assertSame(
                    ['FixtureInventory\\Tests\\SampleTest::values'],
                    $byID['FixtureInventory\\Tests\\SampleTest::' . $method]['providers'],
                );
            }
            foreach (
                ['FixtureInventory\\ExternalValues::cases',
                'FixtureInventory\\Client::__construct',
                'FixtureInventory\\DangerousAttribute::__construct',
                'FixtureInventory\\Tests\\SampleTest::values'] as $id
            ) {
                $class = $byID[$id]['declaring_class'];
                if (!class_exists($class)) {
                    self::fail('Expected fixture class was not autoloaded.');
                }
                self::assertSame(0, (new \ReflectionClass($class))->getStaticPropertyValue('calls'));
            }
            self::assertCount(1, $inventory['diagnostics']);
            self::assertSame('autoload_failed', $inventory['diagnostics'][0]['code']);
            self::assertSame('FixtureInventory\\CannotAutoload', $inventory['diagnostics'][0]['class']);
            self::assertStringNotContainsString($root, json_encode($inventory, JSON_THROW_ON_ERROR));
            foreach ($inventory['symbols'] as $symbol) {
                self::assertStringNotContainsString('anonymous', $symbol['id']);
                self::assertStringNotContainsString('hidden', $symbol['id']);
            }
        } finally {
            $loader->unregister();
            foreach (['src/Client', 'src/ExternalValues', 'src/Grouped', 'src/Missing', 'tests/SampleTest'] as $file) {
                unlink($root . '/' . $file . '.php');
            }
            rmdir($root . '/src');
            rmdir($root . '/tests');
            rmdir($root);
        }
    }
}
