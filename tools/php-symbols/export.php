<?php

declare(strict_types=1);

use HostingPal\WhmApiClient\Tools\SymbolInventory;

require_once __DIR__ . '/SymbolInventory.php';

/** @param list<string> $arguments */
$exportSymbols = static function (array $arguments): int {
    $options = [];
    for ($i = 1, $count = count($arguments); $i < $count; $i += 2) {
        $name = $arguments[$i];
        $value = $arguments[$i + 1] ?? null;
        if (!in_array($name, ['--root', '--output'], true) || !is_string($value) || isset($options[$name])) {
            fwrite(STDERR, "Usage: php tools/php-symbols/export.php --root PATH --output PATH\n");
            return 2;
        }
        $options[$name] = $value;
    }
    if (!isset($options['--root'], $options['--output'])) {
        fwrite(STDERR, "Usage: php tools/php-symbols/export.php --root PATH --output PATH\n");
        return 2;
    }
    $temporary = false;
    try {
        $inventory = (new SymbolInventory())->scan($options['--root']);
        $data = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $directory = dirname($options['--output']);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create output directory.');
        }
        $temporary = tempnam($directory, '.symbols-');
        if (
            $temporary === false || file_put_contents($temporary, $data) !== strlen($data)
            || !rename($temporary, $options['--output'])
        ) {
            throw new RuntimeException('Cannot replace inventory output.');
        }
        return $inventory['diagnostics'] === [] ? 0 : 1;
    } catch (Throwable) {
        fwrite(STDERR, "Symbol export failed; check repository and output paths.\n");
        return 1;
    } finally {
        if ($temporary !== false && is_file($temporary)) {
            unlink($temporary);
        }
    }
};

exit($exportSymbols($argv));
