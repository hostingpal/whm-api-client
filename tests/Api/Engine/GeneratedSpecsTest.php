<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Lint every generated operation spec through the runner's pre-dispatch check.
 *
 * The runner checks a spec only when its operation is called; this test checks every
 * constant of every generated Spec class under src/Api/{family}/Spec offline, so a
 * generator defect fails the suite before any operation reaches a server.
 */
final class GeneratedSpecsTest extends TestCase
{
    #[DataProvider('generatedSpecs')]
    public function testGeneratedSpecIsSound(mixed $spec): void
    {
        self::assertIsArray($spec);

        try {
            SpecChecker::check($spec);
        } catch (LogicException $exception) {
            self::fail($exception->getMessage());
        }
    }

    public function testEveryGeneratedSpecClassDeclaresOperations(): void
    {
        $files = self::specFiles();

        self::assertNotSame([], $files);
        foreach ($files as $class) {
            self::assertNotSame([], (new ReflectionClass($class))->getConstants(), $class);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function generatedSpecs(): iterable
    {
        foreach (self::specFiles() as $class) {
            foreach ((new ReflectionClass($class))->getConstants() as $name => $spec) {
                yield $class . '::' . $name => [$spec];
            }
        }
    }

    /** @return list<class-string> */
    private static function specFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Api/';
        $classes = [];
        foreach (glob($root . '*/Spec/*.php') ?: [] as $file) {
            $relative = substr($file, strlen($root), -strlen('.php'));
            $class = 'HostingPal\\WhmApiClient\\Api\\' . str_replace('/', '\\', $relative);
            self::assertTrue(class_exists($class), $class);
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
