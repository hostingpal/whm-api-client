<?php

declare(strict_types=1);

/*
 * Refresh the Client column of docs/live-testing-operations.md from the current generated client.
 *
 * Usage: php tools/live-testing/refresh-operations-client.php [--check]
 *
 * Each operation keeps its reviewed group and order; only Client is recomputed: "typed" when a generated
 * method carries its API-map identity, "excluded" when docs/api/coverage.md lists it as excluded, otherwise
 * "untyped" (reachable through generic call()). --check exits 1 without writing when the table is stale.
 * Offline and read-only apart from rewriting that one file.
 */

$root = dirname(__DIR__, 2);
$check = in_array('--check', array_slice($argv, 1), true);
$tablePath = $root . '/docs/live-testing-operations.md';

$typed = [];
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src/Api', FilesystemIterator::SKIP_DOTS),
);
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/API-map identity: ((?:uapi|whm1):[^#\s]+)#/', $source, $matches) > 0) {
        foreach ($matches[1] as $id) {
            $typed[$id] = true;
        }
    }
}

$excluded = [];
$coverage = (string) file_get_contents($root . '/docs/api/coverage.md');
$section = strstr($coverage, '## Excluded operations');
if ($section === false) {
    fwrite(STDERR, "coverage.md has no excluded-operations section\n");
    exit(2);
}
if (preg_match_all('/^\| `((?:uapi|whm1):[^`]+)` \|/m', $section, $matches) > 0) {
    foreach ($matches[1] as $id) {
        $excluded[$id] = true;
    }
}

$table = (string) file_get_contents($tablePath);
$changed = 0;
$seen = 0;
$refreshed = preg_replace_callback(
    '/^\| `((?:uapi|whm1):[^`]+)` \| (typed|excluded|untyped) \|$/m',
    static function (array $row) use ($typed, $excluded, &$changed, &$seen): string {
        $seen++;
        $client = isset($typed[$row[1]]) ? 'typed' : (isset($excluded[$row[1]]) ? 'excluded' : 'untyped');
        if ($client !== $row[2]) {
            $changed++;
        }

        return '| `' . $row[1] . '` | ' . $client . ' |';
    },
    $table,
);
if (!is_string($refreshed) || $seen === 0) {
    fwrite(STDERR, "no operation rows found\n");
    exit(2);
}
$counts = [];
foreach (explode("\n", $refreshed) as $line) {
    if (preg_match('/^\| `(?:uapi|whm1):[^`]+` \| ([a-z]+) \|$/', $line, $cell) === 1) {
        $counts[$cell[1]] = ($counts[$cell[1]] ?? 0) + 1;
    }
}
ksort($counts);
echo "rows=$seen changed=$changed " . json_encode($counts) . "\n";
if ($check) {
    exit($changed === 0 ? 0 : 1);
}
if ($changed > 0) {
    file_put_contents($tablePath, $refreshed);
}
