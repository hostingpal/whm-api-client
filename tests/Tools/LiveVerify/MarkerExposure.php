<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use PHPUnit\Framework\Assert;
use SplObjectStorage;

/**
 * Look at an object graph the ways a careless log or dump would, and require that none of them shows a secret.
 *
 * Every reachable object is JSON-encoded and cast to an array, every array is walked, and every scalar found on the
 * way is rendered with var_export. Test doubles the test itself injected are skipped, because they record requests
 * by design: what is checked is what the tool's own objects keep.
 */
final class MarkerExposure
{
    /** Fail if any JSON encoding, array cast or var_export rendering reachable from $subject contains $secret. */
    public static function assertNeverExposed(string $secret, mixed $subject, object ...$doubles): void
    {
        $skip = new SplObjectStorage();
        foreach ($doubles as $double) {
            $skip->attach($double);
        }
        self::walk($secret, $subject, $skip, new SplObjectStorage(), '$subject');
        if ($doubles === []) {
            Assert::assertStringNotContainsString($secret, var_export($subject, true), 'var_export exposes it');
        }
    }

    /**
     * @param SplObjectStorage<object, mixed> $skip
     * @param SplObjectStorage<object, mixed> $seen
     */
    private static function walk(
        string $secret,
        mixed $value,
        SplObjectStorage $skip,
        SplObjectStorage $seen,
        string $path,
    ): void {
        if (is_object($value)) {
            if ($skip->contains($value) || $seen->contains($value)) {
                return;
            }
            $seen->attach($value);
            $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            Assert::assertStringNotContainsString($secret, (string) $json, 'json_encode exposes it at ' . $path);
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $name = str_replace("\0", '~', (string) $key);
                Assert::assertStringNotContainsString($secret, $name, 'A key exposes it at ' . $path);
                self::walk($secret, $item, $skip, $seen, $path . '[' . $name . ']');
            }

            return;
        }
        if (is_scalar($value)) {
            Assert::assertStringNotContainsString(
                $secret,
                var_export($value, true),
                'var_export exposes it at ' . $path,
            );
        }
    }
}
